<?php

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

$gatewayModuleName = 'paymento';
$gateway = new WHMCS\Module\Gateway();
if (!$gateway->load($gatewayModuleName)) {
    die("Module Not Activated");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handlePostCallback($gateway);
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    handleGetCallback($gateway);
} else {
    die("Unsupported request method");
}

function handlePostCallback($gateway) {
    $payload = file_get_contents('php://input');
    $headers = getallheaders();

    $receivedSignature = $headers['X-Hmac-Sha256-Signature'] ?? $headers['x-hmac-sha256-signature'] ?? '';

    $secretKey = $gateway->getParam('secretKey');
    $calculatedSignature = strtoupper(hash_hmac('sha256', $payload, $secretKey));

    if (!hash_equals($calculatedSignature, $receivedSignature)) {
        header("HTTP/1.0 400 Bad Request");
        die("Invalid signature");
    }

    $data = json_decode($payload, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        die("Invalid JSON data");
    }

    processPaymentData($data, $gateway);
}

function handleGetCallback($gateway) {
    global $whmcs;
    
    $orderId = $_GET['orderId'] ?? '';
    $token = $_GET['token'] ?? '';
    $status = $_GET['status'] ?? '';

    if (empty($orderId) || empty($token)) {
        die("Missing required parameters");
    }

    $invoiceId = checkCbInvoiceID($orderId, $gateway->getLoadedModule());
    $systemUrl = $whmcs->get_config('SystemURL');

    $command = 'GetInvoice';
    $postData = array('invoiceid' => $invoiceId);
    $results = localAPI($command, $postData);

    if ($results['result'] == 'success' && $results['status'] == 'Paid') {
        header("Location: " . $systemUrl . "/viewinvoice.php?id=" . $invoiceId . "&paymentsuccess=true");
        exit;
    }

    if ($status == '3') {
        updateInvoice($invoiceId, 'Payment Pending');
        header("Location: " . $systemUrl . "/viewinvoice.php?id=" . $invoiceId);
    } else {
        $verificationResult = paymento_verify_payment($token, $gateway);
        if ($verificationResult['success']) {
            if ($status == '7') {
                addInvoicePayment(
                    $invoiceId,
                    $token,
                    $verificationResult['amount'],
                    0,
                    $gateway->getLoadedModule()
                );
                header("Location: " . $systemUrl . "/viewinvoice.php?id=" . $invoiceId . "&paymentsuccess=true");
            } else {
                updateInvoice($invoiceId, 'Unpaid');
                header("Location: " . $systemUrl . "/viewinvoice.php?id=" . $invoiceId);
            }
        } else {
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

    $invoiceId = checkCbInvoiceID($orderId, $gateway->getLoadedModule());

    if ($orderStatus == 3) {
        updateInvoice($invoiceId, 'Payment Pending');
    } elseif ($orderStatus == 7) {
        $verificationResult = paymento_verify_payment($token, $gateway);
        if ($verificationResult['success']) {
            addInvoicePayment(
                $invoiceId,
                $paymentId,
                $verificationResult['amount'],
                0,
                $gateway->getLoadedModule()
            );
        }
    } elseif ($orderStatus == 9) {
        updateInvoice($invoiceId, 'Unpaid');
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
        curl_close($ch);
        return array('success' => false, 'error' => curl_error($ch));
    }

    curl_close($ch);

    $result = json_decode($response, true);

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

    localAPI($command, $postData);
}