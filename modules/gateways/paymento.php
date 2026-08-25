<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

// Guarded: the callback file defines the same constants and then calls
// $gateway->load(), which includes this file in the same request.
if (!defined('PAYMENTO_API_BASE')) {
    define('PAYMENTO_API_BASE', 'https://api.paymento.io/v1/');
    define('PAYMENTO_HTTP_TIMEOUT', 20);
    define('PAYMENTO_CONNECT_TIMEOUT', 10);
}

function paymento_MetaData()
{
    return array(
        'DisplayName' => 'Paymento Cryptocurrency Non-custodial Gateway',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
        'Logo' => 'paymento-logo.png', // Make sure to upload this image
        'Description' => 'Pay with cryptocurrencies via Paymento. Supports Bitcoin, Ethereum, and more.',
    );
}

function paymento_config()
{
    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Pay Crypto by Paymento',
        ),
        'apiKey' => array(
            'FriendlyName' => 'API Key',
            'Type' => 'text',
            'Size' => '50',
            'Default' => '',
            'Description' => 'Enter your Paymento API Key here',
        ),
        'secretKey' => array(
            'FriendlyName' => 'Secret Key',
            'Type' => 'password',
            'Size' => '50',
            'Default' => '',
            'Description' => 'Enter your Paymento Secret Key here',
        ),
    );
}

function paymento_link($params)
{
    $apiKey = $params['apiKey'];
    $invoiceId = $params['invoiceid'];
    $amount = $params['amount'];
    $currencyCode = $params['currency'];
    $systemUrl = $params['systemurl'];
    $langPayNow = $params['langpaynow'];

    $returnUrl = paymento_callback_url();
    if ($returnUrl === '') {
        $returnUrl = rtrim($systemUrl, '/') . '/modules/gateways/callback/paymento.php';
    }

    $postfields = array(
        'fiatAmount' => $amount,
        'fiatCurrency' => $currencyCode,
        'returnUrl' => $returnUrl,
        'orderId' => $invoiceId,
        'riskSpeed' => 0,
    );

    $response = paymento_api_call('POST', 'payment/request', $postfields, $params);

    if ($response['success']) {
        $token = $response['body'];
        $paymentUrl = "https://app.paymento.io/gateway";

        $htmlOutput = '<form method="get" action="' . $paymentUrl . '">';
        $htmlOutput .= '<input type="hidden" name="token" value="' . htmlspecialchars((string) $token) . '">';
        $htmlOutput .= '<input type="submit" value="' . $langPayNow . '" />';
        $htmlOutput .= '</form>';

        return $htmlOutput;
    }

    // The customer must never see the internal reason, but the merchant has to.
    // Without this the admin saw only the generic string below and had nothing
    // to act on - every distinct failure looked identical.
    $diagnosis = paymento_diagnose($response);

    paymento_log_failure(
        array(
            'invoiceId' => $invoiceId,
            'request' => $postfields,
            'apiKey' => paymento_mask($apiKey),
            'httpCode' => $response['httpCode'],
            'error' => $response['error'],
            'diagnosis' => $diagnosis,
            'rawResponse' => substr($response['raw'], 0, 500),
        ),
        'Payment request failed - ' . $diagnosis
    );

    return "Error: Unable to initiate payment. Please try again or contact support.";
}

/**
 * Turn a failed API call into a sentence the merchant can act on.
 *
 * The API already returns precise messages ("Only HTTPS URLs are allowed.
 * Provided scheme: http"); this adds the WHMCS-side remedy, because knowing
 * the return URL was rejected does not by itself tell an admin that the fix
 * lives in Setup > General Settings.
 */
function paymento_diagnose(array $response)
{
    $error = (string) $response['error'];
    $httpCode = (int) $response['httpCode'];
    $raw = (string) $response['raw'];

    if ($response['curlError'] !== '') {
        return 'Could not reach ' . PAYMENTO_API_BASE . ' from this server (' . $error . '). '
            . 'Check outbound HTTPS access, any egress firewall or proxy, and the server CA bundle.';
    }

    if (stripos($error, 'Only HTTPS URLs are allowed') !== false) {
        return 'Paymento rejected the return URL because it is not HTTPS. '
            . 'Set an https:// System URL under Setup > General Settings > General.';
    }

    if (stripos($error, 'Invalid fiat currency') !== false) {
        return 'Paymento does not support this invoice currency. '
            . 'Check the invoice currency under Setup > Payments > Currencies.';
    }

    if (stripos($error, 'insufficient') !== false) {
        return 'The Paymento account cannot process payments right now (balance / top-up deadline). '
            . 'Top up at app.paymento.io before retrying.';
    }

    if (stripos($error, 'Invalid fiat amount') !== false) {
        return 'Paymento could not parse the invoice amount "' . $response['sentAmount'] . '".';
    }

    if ($httpCode === 401) {
        return 'Paymento rejected the API Key. Re-copy it from app.paymento.io into '
            . 'Setup > Payment Gateways, making sure no whitespace is included.';
    }

    if ($httpCode === 400 && stripos($raw, 'API_KEY_MISSING') !== false) {
        return 'The Api-Key header did not arrive at Paymento - a proxy, WAF or mod_security '
            . 'rule on this server is stripping custom request headers.';
    }

    if ($httpCode === 429) {
        return 'Rate limited by Paymento. A payment request is made on every invoice page '
            . 'render, so heavy invoice traffic can trip the limit; retry shortly.';
    }

    if ($httpCode >= 500) {
        return 'Paymento returned a server error (HTTP ' . $httpCode . '). Retry, then contact support.';
    }

    if ($error === 'Unknown error' && $httpCode !== 200) {
        // Non-JSON body on a non-200 is almost always an intercepting proxy or
        // a host error page rather than us. rawResponse in the log shows which.
        return 'Unexpected non-JSON response (HTTP ' . $httpCode . ') - see rawResponse in this log entry.';
    }

    return $error !== '' ? $error : 'Unknown error';
}

function paymento_api_call($method, $endpoint, $data, $params)
{
    $result = paymento_http_post($endpoint, $data, $params['apiKey']);
    $result['sentAmount'] = isset($data['fiatAmount']) ? (string) $data['fiatAmount'] : '';

    return $result;
}

/**
 * Single place every outbound call goes through, so failures carry the same
 * detail regardless of caller: HTTP status, cURL error and the raw body.
 */
function paymento_http_post($endpoint, array $data, $apiKey)
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
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Api-Key: " . $apiKey,
        "Content-Type: application/json",
        "Accept: text/plain"
    ));

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_errno($ch) ? curl_error($ch) : '';
    curl_close($ch);

    if ($curlError !== '') {
        return array(
            'success' => false,
            'error' => 'cURL: ' . $curlError,
            'curlError' => $curlError,
            'httpCode' => $httpCode,
            'body' => null,
            'raw' => '',
            'sentAmount' => '',
        );
    }

    $raw = (string) $response;
    $result = json_decode($raw, true);

    if ($httpCode === 200 && is_array($result) && !empty($result['success'])) {
        return array(
            'success' => true,
            'error' => '',
            'curlError' => '',
            'httpCode' => $httpCode,
            'body' => isset($result['body']) ? $result['body'] : null,
            'raw' => $raw,
            'sentAmount' => '',
        );
    }

    $message = '';
    if (is_array($result) && !empty($result['message'])) {
        $message = (string) $result['message'];
    }

    return array(
        'success' => false,
        'error' => $message !== '' ? $message : 'Unknown error',
        'curlError' => '',
        'httpCode' => $httpCode,
        'body' => null,
        'raw' => $raw,
        'sentAmount' => '',
    );
}

/**
 * Write to Billing > Gateway Log.
 *
 * Falls back rather than giving up: silence here is worse than a log in the
 * wrong place, because "nothing in the Gateway Log" would read as "no failure
 * happened" and send diagnosis down the wrong path entirely. gatewayfunctions
 * .php is normally already loaded when a gateway link renders, but if it is
 * not, load it, then degrade to the Activity Log and finally the PHP error log.
 */
function paymento_log_failure($data, $status)
{
    if (!function_exists('logTransaction')) {
        $gatewayFunctions = __DIR__ . '/../../includes/gatewayfunctions.php';
        if (is_readable($gatewayFunctions)) {
            require_once $gatewayFunctions;
        }
    }

    try {
        if (function_exists('logTransaction')) {
            logTransaction('paymento', $data, $status);
            return;
        }

        $line = 'Paymento: ' . $status . ' ' . json_encode($data);

        if (function_exists('logActivity')) {
            logActivity($line);
            return;
        }

        error_log($line);
    } catch (Throwable $e) {
        // Logging must never break checkout.
        error_log('Paymento: could not log gateway failure - ' . $e->getMessage());
    }
}

/**
 * Never write a credential to the gateway log; the last 4 characters are
 * enough to tell two keys apart.
 */
function paymento_mask($value)
{
    $value = (string) $value;

    if ($value === '') {
        return '(empty)';
    }

    if (strlen($value) <= 4) {
        return str_repeat('*', strlen($value));
    }

    return str_repeat('*', 8) . substr($value, -4);
}

/**
 * Absolute URL of this module's callback file, derived from the configured
 * SystemURL. Never from $_SERVER['HTTP_HOST'] - that is attacker-controlled,
 * and it also assumes https:// at the document root, which breaks every
 * WHMCS install that lives in a subdirectory.
 */
function paymento_callback_url()
{
    $systemUrl = '';

    try {
        $systemUrl = (string) \WHMCS\Config\Setting::getValue('SystemURL');
    } catch (Throwable $e) {
        $systemUrl = '';
    }

    if ($systemUrl === '') {
        global $whmcs;
        if (isset($whmcs) && is_object($whmcs) && method_exists($whmcs, 'get_config')) {
            $systemUrl = (string) $whmcs->get_config('SystemURL');
        }
    }

    if ($systemUrl === '') {
        return '';
    }

    return rtrim($systemUrl, '/') . '/modules/gateways/callback/paymento.php';
}

function paymento_set_callback_url($apiKey)
{
    $callbackUrl = paymento_callback_url();

    if ($callbackUrl === '') {
        return array('success' => false, 'message' => 'Could not determine SystemURL. Set it under Setup > General Settings before saving.');
    }

    $result = paymento_http_post('payment/settings', array(
        'IPN_Url' => $callbackUrl,
        'IPN_Method' => 1 // HTTP POST
    ), $apiKey);

    if ($result['success']) {
        return array('success' => true, 'message' => '');
    }

    $diagnosis = paymento_diagnose($result);

    paymento_log_failure(
        array(
            'callbackUrl' => $callbackUrl,
            'apiKey' => paymento_mask($apiKey),
            'httpCode' => $result['httpCode'],
            'error' => $result['error'],
            'diagnosis' => $diagnosis,
            'rawResponse' => substr($result['raw'], 0, 500),
        ),
        'Callback URL registration failed - ' . $diagnosis
    );

    return array('success' => false, 'message' => $diagnosis);
}

function paymento_config_validate($params)
{
    $apiKey = trim($params['apiKey']);
    $secretKey = trim($params['secretKey']);

    // Both are mandatory. The IPN handler refuses to process anything without
    // a secret key, so saving the gateway with one missing would leave a
    // configuration that silently accepts no payments at all.
    if ($apiKey === '' || $secretKey === '') {
        return array(
            'error' => 'Both the API Key and the Secret Key are required. The Secret Key is what authenticates payment notifications from Paymento.'
        );
    }

    // A non-HTTPS return URL registers here without complaint but is rejected on
    // every payment request. Catching it at save time is the difference between
    // "the gateway saved fine and no invoice can be paid" and a one-line fix.
    $callbackUrl = paymento_callback_url();
    if ($callbackUrl !== '' && stripos($callbackUrl, 'https://') !== 0) {
        return array(
            'error' => 'Your WHMCS System URL is not HTTPS (' . $callbackUrl . '). Paymento only accepts '
                . 'HTTPS return URLs, so payments would fail on every invoice. Set an https:// System URL '
                . 'under Setup > General Settings > General, then save this gateway again.'
        );
    }

    $result = paymento_set_callback_url($apiKey);
    if (!$result['success']) {
        return array(
            'error' => 'Failed to set callback URL: ' . $result['message']
        );
    }

    return array();
}
