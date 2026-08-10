<?php
/**
 * Paymento WHMCS gateway - payment callback.
 *
 * Two entry points share this file:
 *
 *   POST - the server-to-server IPN from Paymento, authenticated by an
 *          HMAC-SHA256 signature over the raw body.
 *
 *   GET  - the browser return URL the customer lands on after paying.
 *
 * Both may settle an invoice, but NEITHER trusts the caller's claim about
 * payment status. The "status" parameter on the return URL is ignored
 * entirely. Settlement always requires a server-side verify call to the
 * Paymento API, that the verified payment belongs to this invoice, a real
 * amount from the API, and a duplicate-transaction check.
 *
 * The return path exists so a delayed or misconfigured IPN does not leave a
 * genuinely paid invoice sitting unpaid. It is a confirmation, not a claim.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

use WHMCS\Database\Capsule;

const PAYMENTO_API_BASE = 'https://api.paymento.io/v1/';
const PAYMENTO_HTTP_TIMEOUT = 20;
const PAYMENTO_CONNECT_TIMEOUT = 10;

// Paymento OrderStatus enum (Paymento.DomainClasses/Enums/GeneralProperties.cs).
const PAYMENTO_STATUS_PARTIAL_PAID = 2;
const PAYMENTO_STATUS_WAITING_CONFIRM = 3;
const PAYMENTO_STATUS_PAID = 7;
const PAYMENTO_STATUS_APPROVE = 8;
const PAYMENTO_STATUS_REJECT = 9;

$gatewayModuleName = 'paymento';
$gateway = new WHMCS\Module\Gateway();
if (!$gateway->load($gatewayModuleName)) {
    paymento_respond(503, 'Module Not Activated');
}

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';

if ($requestMethod === 'POST' || $requestMethod === 'PUT') {
    paymento_handle_ipn($gateway);
} elseif ($requestMethod === 'GET') {
    paymento_handle_return($gateway);
} else {
    paymento_respond(405, 'Unsupported request method');
}

// ---------------------------------------------------------------------
// IPN path
// ---------------------------------------------------------------------

function paymento_handle_ipn($gateway)
{
    $moduleName = paymento_module_name($gateway);
    $payload = file_get_contents('php://input');

    if ($payload === false || $payload === '') {
        paymento_log($moduleName, ['error' => 'empty body'], 'IPN rejected - empty body');
        paymento_respond(400, 'Empty request body');
    }

    $secretKey = (string) $gateway->getParam('secretKey');
    if ($secretKey === '') {
        // Fail closed: with an empty key the HMAC below would be forgeable.
        paymento_log($moduleName, ['error' => 'secretKey not configured'], 'IPN rejected - no secret key');
        paymento_respond(500, 'Gateway secret key is not configured');
    }

    $receivedSignature = paymento_get_header('X-HMAC-SHA256-Signature');
    if ($receivedSignature === '') {
        paymento_log($moduleName, [
            'error' => 'signature header missing',
            'headersSeen' => paymento_header_names(),
        ], 'IPN rejected - missing signature');
        paymento_respond(400, 'Missing signature');
    }

    $calculatedSignature = strtoupper(hash_hmac('sha256', $payload, $secretKey));

    if (!hash_equals($calculatedSignature, strtoupper($receivedSignature))) {
        paymento_log($moduleName, [
            'error' => 'signature mismatch',
            'payloadLength' => strlen($payload),
        ], 'IPN rejected - invalid signature');
        paymento_respond(400, 'Invalid signature');
    }

    $data = json_decode($payload, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        paymento_log($moduleName, ['error' => 'invalid json', 'body' => substr($payload, 0, 500)], 'IPN rejected - bad JSON');
        paymento_respond(400, 'Invalid JSON data');
    }

    $token = (string) paymento_field($data, 'Token');
    $paymentId = (string) paymento_field($data, 'PaymentId');
    $orderId = (string) paymento_field($data, 'OrderId');
    $orderStatus = paymento_field($data, 'OrderStatus');

    if ($token === '' || $orderId === '') {
        paymento_log($moduleName, $data, 'IPN rejected - missing Token or OrderId');
        paymento_respond(400, 'Missing Token or OrderId');
    }

    $invoice = paymento_get_invoice($orderId);

    if ($invoice === false) {
        // Lookup failed, not "absent". Retryable - do not tell Paymento to stop.
        paymento_log($moduleName, [
            'error' => paymento_last_error(),
            'lookedUpOrderId' => $orderId,
            'ipn' => $data,
        ], 'IPN deferred - invoice lookup failed');
        paymento_respond(503, 'Invoice lookup failed');
    }

    if ($invoice === null) {
        paymento_log($moduleName, [
            'lookedUpOrderId' => $orderId,
            'ipn' => $data,
        ], 'IPN rejected - no invoice matches OrderId');
        paymento_respond(404, 'Invoice not found');
    }

    $invoiceId = $invoice['id'];

    if (!paymento_is_our_invoice($invoice, $moduleName)) {
        paymento_log($moduleName, [
            'invoiceId' => $invoiceId,
            'paymentmethod' => $invoice['paymentmethod'],
        ], 'IPN rejected - invoice belongs to another gateway');
        paymento_respond(400, 'Invoice is not a Paymento invoice');
    }

    $statusInt = is_numeric($orderStatus) ? (int) $orderStatus : -1;

    if ($statusInt === PAYMENTO_STATUS_WAITING_CONFIRM || $statusInt === PAYMENTO_STATUS_PARTIAL_PAID) {
        if ($invoice['status'] !== 'Paid') {
            paymento_update_invoice_status($invoiceId, 'Payment Pending');
        }
        paymento_log($moduleName, $data, 'IPN - payment pending');
        paymento_respond(200, 'OK');
    }

    if ($statusInt === PAYMENTO_STATUS_PAID || $statusInt === PAYMENTO_STATUS_APPROVE) {
        $result = paymento_settle($gateway, $invoiceId, $invoice, $token, $paymentId, 'IPN', $data);
        paymento_respond($result['code'], $result['message'] . (paymento_last_error() !== '' ? ' (' . paymento_last_error() . ')' : ''));
    }

    if ($statusInt === PAYMENTO_STATUS_REJECT) {
        // Never move a Paid invoice backwards - a late or replayed rejection
        // must not undo a settled payment.
        if ($invoice['status'] !== 'Paid') {
            paymento_update_invoice_status($invoiceId, 'Unpaid');
        }
        paymento_log($moduleName, $data, 'IPN - rejected by gateway');
        paymento_respond(200, 'OK');
    }

    paymento_log($moduleName, $data, 'IPN - ignored, status ' . $statusInt);
    paymento_respond(200, 'OK');
}

// ---------------------------------------------------------------------
// Browser return path
// ---------------------------------------------------------------------

/**
 * The customer's browser lands here after paying.
 *
 * The "status" query parameter is deliberately never read. The only thing
 * taken from the request is which invoice and which token to ask the API
 * about; the answer comes from the API.
 */
function paymento_handle_return($gateway)
{
    $moduleName = paymento_module_name($gateway);
    $systemUrl = rtrim(paymento_system_url(), '/');

    $orderId = isset($_GET['orderId']) ? (string) $_GET['orderId'] : '';
    $token = isset($_GET['token']) ? (string) $_GET['token'] : '';

    if ($orderId === '' || !ctype_digit($orderId)) {
        paymento_redirect($systemUrl . '/clientarea.php');
    }

    $invoice = paymento_get_invoice($orderId);

    if (!is_array($invoice)) {
        paymento_redirect($systemUrl . '/clientarea.php');
    }

    $invoiceId = $invoice['id'];

    // Try to settle only when it could plausibly do anything. This keeps the
    // endpoint from being usable as a free outbound-request generator.
    if ($invoice['status'] !== 'Paid'
        && paymento_is_our_invoice($invoice, $moduleName)
        && paymento_looks_like_token($token)
    ) {
        paymento_settle($gateway, $invoiceId, $invoice, $token, '', 'Return URL', [
            'token' => $token,
            'OrderId' => $orderId,
        ]);
        $invoice = paymento_get_invoice($invoiceId) ?: $invoice;
    }

    $target = $systemUrl . '/viewinvoice.php?id=' . $invoiceId;
    $target .= ($invoice['status'] === 'Paid') ? '&paymentsuccess=true' : '&pendingpayment=true';

    paymento_redirect($target);
}

// ---------------------------------------------------------------------
// Settlement - shared by both paths
// ---------------------------------------------------------------------

/**
 * Confirm a payment against the Paymento API and credit the invoice.
 *
 * Returns ['ok' => bool, 'code' => int, 'message' => string] rather than
 * exiting, so the caller decides whether to emit an HTTP status (IPN) or a
 * redirect (return URL).
 */
function paymento_settle($gateway, $invoiceId, array $invoice, $token, $paymentId, $source, array $context)
{
    $moduleName = paymento_module_name($gateway);

    if ($invoice['status'] === 'Paid') {
        paymento_log($moduleName, $context, $source . ' - ignored, invoice already paid');
        return ['ok' => true, 'code' => 200, 'message' => 'Already paid'];
    }

    $verification = paymento_verify_payment($token, $gateway);

    if (!$verification['success']) {
        paymento_log($moduleName, [
            'invoiceId' => $invoiceId,
            'error' => $verification['error'],
            'response' => substr($verification['raw'], 0, 800),
        ], $source . ' - verification failed');
        // Non-2xx so Paymento retries: a transient API outage must not lose a payment.
        return ['ok' => false, 'code' => 502, 'message' => 'Payment verification failed'];
    }

    // Token/invoice binding: this is what stops a valid token for invoice A
    // being used to settle invoice B.
    //
    // Accept a match against either the internal invoice id or the OrderId as
    // it arrived, because an install using custom invoice numbering resolves
    // via tblinvoices.invoicenum and the two legitimately differ.
    $verifiedOrderId = (string) $verification['orderId'];
    $acceptable = [(string) $invoiceId, (string) $invoice['id']];
    $contextOrderId = (string) paymento_field($context, 'OrderId', '');
    if ($contextOrderId !== '') {
        $acceptable[] = $contextOrderId;
    }

    if ($verifiedOrderId === '' || !paymento_matches_any_invoice($verifiedOrderId, $acceptable)) {
        paymento_log($moduleName, [
            'invoiceId' => $invoiceId,
            'verifiedOrderId' => $verifiedOrderId,
            'acceptable' => array_values(array_unique($acceptable)),
        ], $source . ' - rejected, token does not belong to this invoice');
        return ['ok' => false, 'code' => 400, 'message' => 'Token does not belong to this invoice'];
    }

    // Never pass a blank amount to addInvoicePayment(): WHMCS treats blank as
    // "the full invoice balance", which would credit the invoice in full
    // regardless of what was actually paid.
    $amount = $verification['amount'];
    if ($amount === null || !is_numeric($amount) || (float) $amount <= 0) {
        paymento_log($moduleName, [
            'invoiceId' => $invoiceId,
            'error' => 'no usable amount in verification response',
            'response' => substr($verification['raw'], 0, 800),
        ], $source . ' - rejected, no amount');
        return ['ok' => false, 'code' => 502, 'message' => 'Verification response carried no amount'];
    }

    $amount = (float) $amount;

    if ($amount + 0.00001 < (float) $invoice['total']) {
        // Credit what was genuinely paid and let the invoice stay unpaid, but
        // make it visible: it means the total changed after the payment link
        // was created.
        paymento_log($moduleName, [
            'invoiceId' => $invoiceId,
            'verifiedAmount' => $amount,
            'invoiceTotal' => $invoice['total'],
        ], $source . ' - warning, verified amount below invoice total');
    }

    // Idempotency. Equivalent to checkCbTransID(), but returns instead of
    // die()ing so the return path can still redirect the customer.
    $transactionId = $paymentId !== '' ? $paymentId : $token;
    if (paymento_transaction_exists($transactionId)) {
        $checkError = paymento_last_error();
        if (strpos($checkError, 'duplicate check:') === 0) {
            // The check itself failed; we withheld credit rather than risk a
            // double payment. Retryable, and must not read as "already paid".
            paymento_log($moduleName, [
                'invoiceId' => $invoiceId,
                'transactionId' => $transactionId,
                'error' => $checkError,
            ], $source . ' - deferred, duplicate check failed');
            return ['ok' => false, 'code' => 503, 'message' => 'Duplicate check failed'];
        }

        paymento_log($moduleName, [
            'invoiceId' => $invoiceId,
            'transactionId' => $transactionId,
        ], $source . ' - ignored, duplicate transaction');
        return ['ok' => true, 'code' => 200, 'message' => 'Duplicate transaction'];
    }

    addInvoicePayment($invoiceId, $transactionId, $amount, 0, $moduleName);

    paymento_log($moduleName, [
        'invoiceId' => $invoiceId,
        'transactionId' => $transactionId,
        'amount' => $amount,
        'context' => $context,
    ], $source . ' - Successful');

    return ['ok' => true, 'code' => 200, 'message' => 'OK'];
}

// ---------------------------------------------------------------------
// Paymento API
// ---------------------------------------------------------------------

/**
 * Re-confirm a payment against the Paymento API.
 *
 * Note the verify response carries no top-level "amount"; the fiat amount is
 * at body.settlement.requestedFiatAmount.
 */
function paymento_verify_payment($token, $gateway)
{
    $apiKey = (string) $gateway->getParam('apiKey');

    $result = paymento_api_post('payment/verify', ['token' => $token], $apiKey);

    if (!$result['success']) {
        return [
            'success' => false,
            'error' => $result['error'],
            'orderId' => '',
            'amount' => null,
            'raw' => $result['raw'],
        ];
    }

    $body = is_array($result['body']) ? $result['body'] : [];
    $settlement = paymento_field($body, 'Settlement');
    $amount = null;

    if (is_array($settlement)) {
        $amount = paymento_field($settlement, 'RequestedFiatAmount', null);
    }

    return [
        'success' => true,
        'error' => '',
        'orderId' => (string) paymento_field($body, 'OrderId'),
        'status' => paymento_field($body, 'OrderStatus'),
        'amount' => $amount,
        'raw' => $result['raw'],
    ];
}

function paymento_api_post($endpoint, array $data, $apiKey)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, PAYMENTO_API_BASE . $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, PAYMENTO_HTTP_TIMEOUT);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, PAYMENTO_CONNECT_TIMEOUT);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Api-Key: ' . $apiKey,
        'Content-Type: application/json',
        'Accept: text/plain',
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_errno($ch) ? curl_error($ch) : '';
    curl_close($ch);

    if ($curlError !== '') {
        return ['success' => false, 'error' => 'cURL: ' . $curlError, 'body' => null, 'raw' => ''];
    }

    $decoded = json_decode((string) $response, true);

    if ($httpCode === 200 && is_array($decoded) && !empty($decoded['success'])) {
        return ['success' => true, 'error' => '', 'body' => $decoded['body'] ?? null, 'raw' => (string) $response];
    }

    $message = 'HTTP ' . $httpCode;
    if (is_array($decoded) && !empty($decoded['message'])) {
        $message .= ': ' . $decoded['message'];
    }

    return ['success' => false, 'error' => $message, 'body' => null, 'raw' => (string) $response];
}

// ---------------------------------------------------------------------
// WHMCS helpers
// ---------------------------------------------------------------------

function paymento_module_name($gateway)
{
    $name = (string) $gateway->getLoadedModule();
    return $name !== '' ? $name : 'paymento';
}

/**
 * Gateway binding: refuse to touch invoices not placed through this gateway,
 * so a Paymento token cannot be aimed at a bank-transfer invoice.
 */
function paymento_is_our_invoice(array $invoice, $moduleName)
{
    $method = strtolower(trim($invoice['paymentmethod']));
    return $method === strtolower(trim($moduleName)) || $method === 'paymento';
}

/**
 * Resolve an OrderId to an invoice.
 *
 * Returns the invoice array, null when it genuinely does not exist, or false
 * when the lookup itself failed. Those are three different situations: a
 * database error must NOT be reported as "invoice not found", because that
 * tells Paymento to stop retrying a payment we simply failed to look up.
 *
 * Falls back to tblinvoices.invoicenum so installs using custom invoice
 * numbering still resolve - this is the tolerance checkCbInvoiceID() provides.
 */
function paymento_get_invoice($orderId)
{
    // Select whole rows rather than naming columns: the exact column set of
    // tblinvoices varies between WHMCS versions, and naming one that does not
    // exist turns every lookup into a query exception.
    try {
        $row = null;
        $needle = trim((string) $orderId);

        if ($needle === '') {
            return null;
        }

        if (ctype_digit($needle)) {
            $row = Capsule::table('tblinvoices')->where('id', (int) $needle)->first();
        }

        if (!$row) {
            $row = Capsule::table('tblinvoices')->where('invoicenum', $needle)->first();
        }
    } catch (Throwable $e) {
        paymento_set_error('invoice lookup: ' . $e->getMessage());
        return false;
    }

    if (!$row) {
        return null;
    }

    return [
        'id' => (int) $row->id,
        'status' => (string) $row->status,
        'total' => (float) $row->total,
        'paymentmethod' => (string) $row->paymentmethod,
    ];
}

/**
 * Last internal error, so a swallowed exception still reaches the gateway log
 * instead of surfacing as an unexplained "lookup failed".
 */
function paymento_set_error($message)
{
    $GLOBALS['paymento_last_error'] = (string) $message;
}

function paymento_last_error()
{
    return isset($GLOBALS['paymento_last_error']) ? (string) $GLOBALS['paymento_last_error'] : '';
}

/**
 * Has this gateway transaction already been recorded? Same check
 * checkCbTransID() performs, without the die().
 */
function paymento_transaction_exists($transactionId)
{
    if ((string) $transactionId === '') {
        return false;
    }

    try {
        return Capsule::table('tblaccounts')
            ->where('transid', (string) $transactionId)
            ->where('gateway', 'paymento')
            ->exists();
    } catch (Throwable $e) {
        // Fail safe: if the check cannot run, do not credit.
        paymento_set_error('duplicate check: ' . $e->getMessage());
        return true;
    }
}

function paymento_update_invoice_status($invoiceId, $status)
{
    $adminUser = paymento_admin_username();

    $postData = [
        'invoiceid' => (int) $invoiceId,
        'status' => $status,
    ];

    if ($adminUser !== '') {
        localAPI('UpdateInvoice', $postData, $adminUser);
    } else {
        localAPI('UpdateInvoice', $postData);
    }
}

/**
 * localAPI needs an admin identity on modern WHMCS.
 */
function paymento_admin_username()
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    try {
        $admin = Capsule::table('tbladmins')
            ->where('disabled', 0)
            ->orderBy('id')
            ->first(['username']);
        $cached = $admin ? (string) $admin->username : '';
    } catch (Throwable $e) {
        paymento_set_error('admin lookup: ' . $e->getMessage());
        $cached = '';
    }

    return $cached;
}

function paymento_system_url()
{
    try {
        $url = \WHMCS\Config\Setting::getValue('SystemURL');
        if (!empty($url)) {
            return (string) $url;
        }
    } catch (Throwable $e) {
        // Older WHMCS builds may not expose this class; fall through.
    }

    global $whmcs;
    if (isset($whmcs) && is_object($whmcs) && method_exists($whmcs, 'get_config')) {
        return (string) $whmcs->get_config('SystemURL');
    }

    return '';
}

/**
 * Every outcome is logged to Billing > Gateway Log. Nothing fails silently -
 * a blank Gateway Log means the request never arrived at all.
 */
function paymento_log($moduleName, $data, $status)
{
    try {
        logTransaction($moduleName, $data, $status);
    } catch (Throwable $e) {
        // Logging must never break settlement.
    }
}

// ---------------------------------------------------------------------
// Request helpers
// ---------------------------------------------------------------------

/**
 * Case-insensitive header lookup.
 *
 * Paymento sends "X-HMAC-SHA256-Signature". getallheaders() normalises header
 * case differently per SAPI - Apache mod_php preserves the case as sent,
 * CGI/FPM rebuilds it from $_SERVER - so matching a hardcoded spelling is not
 * reliable. Compare case-insensitively and fall back to $_SERVER.
 */
function paymento_get_header($name)
{
    $wanted = strtolower($name);

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $key => $value) {
                if (strtolower($key) === $wanted) {
                    return trim((string) $value);
                }
            }
        }
    }

    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$serverKey])) {
        return trim((string) $_SERVER[$serverKey]);
    }

    return '';
}

/**
 * Header names only - never values, so nothing sensitive reaches the log.
 * Used to diagnose a missing signature header.
 */
function paymento_header_names()
{
    $names = [];

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            $names = array_keys($headers);
        }
    }

    if (!$names) {
        foreach (array_keys($_SERVER) as $key) {
            if (strpos($key, 'HTTP_') === 0) {
                $names[] = $key;
            }
        }
    }

    return $names;
}

/**
 * Case-insensitive array field lookup. The IPN body is serialised PascalCase
 * while REST responses are camelCase; tolerate both.
 */
function paymento_field(array $data, $key, $default = '')
{
    if (array_key_exists($key, $data)) {
        return $data[$key];
    }

    $wanted = strtolower($key);
    foreach ($data as $k => $v) {
        if (strtolower((string) $k) === $wanted) {
            return $v;
        }
    }

    return $default;
}

function paymento_same_invoice($verifiedOrderId, $invoiceId)
{
    $a = trim((string) $verifiedOrderId);
    $b = trim((string) $invoiceId);

    if ($a === '' || $b === '') {
        return false;
    }

    if (ctype_digit($a) && ctype_digit($b)) {
        return (int) $a === (int) $b;
    }

    return $a === $b;
}

function paymento_matches_any_invoice($verifiedOrderId, array $candidates)
{
    foreach ($candidates as $candidate) {
        if (paymento_same_invoice($verifiedOrderId, $candidate)) {
            return true;
        }
    }

    return false;
}

/**
 * Cheap shape check before spending an outbound API call on a return-URL hit.
 * Order tokens are GUID "N" format, but stay permissive.
 */
function paymento_looks_like_token($token)
{
    $token = (string) $token;
    return $token !== '' && strlen($token) >= 16 && strlen($token) <= 128 && ctype_alnum(str_replace('-', '', $token));
}

function paymento_redirect($url)
{
    header('Location: ' . $url);
    exit;
}

function paymento_respond($code, $message)
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}
