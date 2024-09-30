<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Create a log file specifically for this callback
function customLog($message) {
    file_put_contents(__DIR__ . '/paymento_callback.log', date('Y-m-d H:i:s') . " $message\n", FILE_APPEND);
}

customLog("Callback script started");

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

$gatewayModuleName = 'paymento';
$gateway = new WHMCS\Module\Gateway();
if (!$gateway->load($gatewayModuleName)) {
    die("Module Not Activated");
}

customLog("Request Method: " . $_SERVER['REQUEST_METHOD']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handlePostCallback($gateway);
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    handleGetCallback($gateway);
} else {
    customLog("Unsupported request method");
    die("Unsupported request method");
}

function handlePostCallback($gateway) {
    $payload = file_get_contents('php://input');
    $headers = getallheaders();

    customLog("POST Payload: " . $payload);
    customLog("Headers: " . print_r($headers, true));

    $receivedSignature = '';
    if (isset($headers['X-Hmac-Sha256-Signature'])) {
        $receivedSignature = $headers['X-Hmac-Sha256-Signature'];
    } elseif (isset($headers['x-hmac-sha256-signature'])) {
        $receivedSignature = $headers['x-hmac-sha256-signature'];
    }

    customLog("Received Signature: " . $receivedSignature);

    $secretKey = $gateway->getParam('secretKey');
    $calculatedSignature = strtoupper(hash_hmac('sha256', $payload, $secretKey));

    customLog("Calculated Signature: " . $calculatedSignature);

    if (!hash_equals($calculatedSignature, $receivedSignature)) {
        customLog("Signature mismatch");
        header("HTTP/1.0 400 Bad Request");
        die("Invalid signature");
    }

    $data = json_decode($payload, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        customLog("Invalid JSON: " . json_last_error_msg());
        die("Invalid JSON data");
    }

    processPaymentData($data, $gateway);
}

function handleGetCallback($gateway) {
    global $whmcs;
    
    customLog("GET Parameters: " . print_r($_GET, true));

    $orderId = $_GET['orderId'] ?? '';
    $token = $_GET['token'] ?? '';
    $status = $_GET['status'] ?? '';

    if (empty($orderId) || empty($token)) {
        customLog("Missing required parameters");
        die("Missing required parameters");
    }

    $invoiceId = checkCbInvoiceID($orderId, $gateway->getLoadedModule());

    // Get the system URL
    $systemUrl = $whmcs->get_config('SystemURL');

    // Check the current invoice status
    $command = 'GetInvoice';
    $postData = array(
        'invoiceid' => $invoiceId,
    );
    $results = localAPI($command, $postData);

    if ($results['result'] == 'success') {
        $currentStatus = $results['status'];
        customLog("Current invoice status: $currentStatus");

        if ($currentStatus == 'Paid') {
            customLog("Invoice already paid, redirecting to success page");
            header("Location: " . $systemUrl . "/viewinvoice.php?id=" . $invoiceId . "&paymentsuccess=true");
            exit;
        }
    }

    if ($status == '3') { // Waiting to confirm
        updateInvoice($invoiceId, 'Payment Pending'); // Use 'Unpaid' instead of 'Pending'
        customLog("Payment waiting to confirm for invoice ID: $invoiceId");
        header("Location: " . $systemUrl . "/viewinvoice.php?id=" . $invoiceId);
    } else {
        $verificationResult = paymento_verify_payment($token, $gateway);
        if ($verificationResult['success']) {
            if ($status == '7') { // Payment completed
                addInvoicePayment(
                    $invoiceId,
                    $token,
                    $verificationResult['amount'],
                    0,
                    $gateway->getLoadedModule()
                );
                customLog("Payment successful for invoice ID: $invoiceId");
                header("Location: " . $systemUrl . "/viewinvoice.php?id=" . $invoiceId . "&paymentsuccess=true");
            } else {
                updateInvoice($invoiceId, 'Unpaid');
                customLog("Payment failed for invoice ID: $invoiceId");
                header("Location: " . $systemUrl . "/viewinvoice.php?id=" . $invoiceId);
            }
        } else {
            customLog("Payment verification failed for invoice ID: $invoiceId");
            header("Location: " . $systemUrl . "/viewinvoice.php?id=" . $invoiceId);
        }
    }
    exit;
}


function processPaymentData($data, $gateway) {
    $token = $data['Token'] ?? '';
    $paymentId = $data['PaymentId'] ?? '';
    $orderId = $data['OrderId'] ?? '';
    $orderStatus = $data['OrderStatus'] ?? '';

    customLog("Processing payment data: " . print_r($data, true));

    $invoiceId = checkCbInvoiceID($orderId, $gateway->getLoadedModule());

    if ($orderStatus == 3) { // Waiting to confirm
        updateInvoice($invoiceId, 'Payment Pending'); // Use 'Unpaid' instead of 'Pending'
        customLog("Payment waiting to confirm for invoice ID: $invoiceId");
    } elseif ($orderStatus == 7) { // Payment completed
        $verificationResult = paymento_verify_payment($token, $gateway);
        if ($verificationResult['success']) {
            addInvoicePayment(
                $invoiceId,
                $paymentId,
                $verificationResult['amount'],
                0,
                $gateway->getLoadedModule()
            );
            customLog("Payment successful for invoice ID: $invoiceId");
        } else {
            customLog("Payment verification failed for invoice ID: $invoiceId");
        }
    } elseif ($orderStatus == 9) { // Payment failed
        updateInvoice($invoiceId, 'Unpaid');
        customLog("Payment failed for invoice ID: $invoiceId");
    }
}

function paymento_verify_payment($token, $gateway) {
    $apiKey = $gateway->getParam('apiKey');
    $apiUrl = "https://api.paymento.io/v1/payment/verify";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array('token' => $token)));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Api-Key: " . $apiKey,
        "Content-Type: application/json",
        "Accept: text/plain"
    ));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        customLog("Payment verification error: $error");
        return array('success' => false, 'error' => $error);
    }

    curl_close($ch);

    $result = json_decode($response, true);

    customLog("Payment verification API response: " . print_r($result, true));

    if ($httpCode == 200 && isset($result['success']) && $result['success']) {
        return array('success' => true, 'amount' => $result['body']['amount']);
    } else {
        return array('success' => false, 'error' => $result['message'] ?? 'Unknown error');
    }
}

function updateInvoice($invoiceId, $status) {
    $command = 'UpdateInvoice';
    $postData = array(
        'invoiceid' => $invoiceId,
        'status' => $status,
    );

    $results = localAPI($command, $postData);

    if ($results['result'] == 'success') {
        customLog("Invoice $invoiceId updated to status: $status");
    } else {
        customLog("Failed to update invoice $invoiceId. Error: " . $results['message']);
    }
}