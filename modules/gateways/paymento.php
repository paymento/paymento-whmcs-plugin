<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
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
        'speed' => 0,
    );

    $response = paymento_api_call('POST', 'payment/request', $postfields, $params);

    if ($response['success']) {
        $token = $response['body'];
        $paymentUrl = "https://app.paymento.io/gateway";

        $htmlOutput = '<form method="get" action="' . $paymentUrl . '">';
        $htmlOutput .= '<input type="hidden" name="token" value="' . htmlspecialchars($token) . '">';
        $htmlOutput .= '<input type="submit" value="' . $langPayNow . '" />';
        $htmlOutput .= '</form>';

        return $htmlOutput;
    } else {
        return "Error: Unable to initiate payment. Please try again or contact support.";
    }
}

function paymento_api_call($method, $endpoint, $data, $params)
{
    $apiKey = $params['apiKey'];
    $apiUrl = "https://api.paymento.io/v1/" . $endpoint;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Api-Key: " . $apiKey,
        "Content-Type: application/json",
        "Accept: text/plain"
    ));

    if ($method == 'POST') {
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        return array('success' => false, 'error' => $error);
    }

    curl_close($ch);

    $result = json_decode($response, true);

    if ($httpCode == 200 && isset($result['success']) && $result['success']) {
        return array('success' => true, 'body' => $result['body']);
    } else {
        return array('success' => false, 'error' => $result['message'] ?? 'Unknown error');
    }
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

    $ch = curl_init('https://api.paymento.io/v1/payment/settings');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'IPN_Url' => $callbackUrl,
        'IPN_Method' => 1 // HTTP POST
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Api-Key: ' . $apiKey,
        'Content-Type: application/json',
        'Accept: text/plain'
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return array('success' => false, 'message' => 'cURL Error: ' . $error);
    }
    
    $result = json_decode($response, true);
    
    if (isset($result['success']) && $result['success']) {
        return array('success' => true);
    } else {
        return array('success' => false, 'message' => $result['message'] ?? 'Unknown error');
    }
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

    $result = paymento_set_callback_url($apiKey);
    if (!$result['success']) {
        return array(
            'error' => 'Failed to set callback URL: ' . $result['message']
        );
    }

    return array();
}