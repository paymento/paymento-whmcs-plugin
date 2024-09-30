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

    $postfields = array(
        'fiatAmount' => $amount,
        'fiatCurrency' => $currencyCode,
        'returnUrl' => $systemUrl . 'modules/gateways/callback/paymento.php',
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

function paymento_set_callback_url($apiKey)
{
    $callbackUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/modules/gateways/callback/paymento.php';
    
    $ch = curl_init('https://api.paymento.io/v1/payment/settings');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
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
    $apiKey = $params['apiKey'];
    $secretKey = $params['secretKey'];
    
    if ($apiKey && $secretKey) {
        $result = paymento_set_callback_url($apiKey);
        if (!$result['success']) {
            return array(
                'error' => 'Failed to set callback URL: ' . $result['message']
            );
        }
    }
    
    return array();
}