<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function paymento_MetaData()
{
    return array(
        'DisplayName' => 'Paymento Cryptocurrency Gateway',
        'APIVersion' => '1.1',
    );
}

function paymento_config()
{
    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Paymento Cryptocurrency Gateway',
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
        // ... other configuration options ...
    );
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

function paymento_link($params)
{
    // Retrieve configuration parameters
    $apiKey = $params['apiKey'];
    $secretKey = $params['secretKey'];
    $paymentApproach = $params['paymentApproach'];
    
    // Invoice Parameters
    $invoiceId = $params['invoiceid'];
    $amount = $params['amount'];
    $currencyCode = $params['currency'];

    // System Parameters
    $systemUrl = $params['systemurl'];
    $returnUrl = $params['returnurl'];
    $langPayNow = $params['langpaynow'];

    // Set the appropriate speed based on the payment approach
    $speed = ($paymentApproach == 'accept_mempool' || $paymentApproach == 'redirect_hold') ? 0 : 1;

    // Prepare data for API request
    $postfields = array(
        'fiatAmount' => $amount,
        'fiatCurrency' => $currencyCode,
        'returnUrl' => $systemUrl . 'modules/gateways/callback/paymento.php',
        'orderId' => $invoiceId,
        'speed' => $speed,
    );

    // Log the request data
    logTransaction('paymento', $postfields, 'Request Data');

    // Call Paymento API to create payment request
    $response = paymento_api_call('POST', 'payment/request', $postfields, $params);

    // Log the full response
    logTransaction('paymento', $response, 'API Response');

    if ($response['success']) {
        $token = $response['body'];
        $paymentUrl = "https://app.paymento.io/gateway";

        $htmlOutput = '<form method="get" action="' . $paymentUrl . '">';
        $htmlOutput .= '<input type="hidden" name="token" value="' . htmlspecialchars($token) . '">';
        $htmlOutput .= '<input type="submit" value="' . $langPayNow . '" />';
        $htmlOutput .= '</form>';

        return $htmlOutput;
    } else {
        // Log the error details
        logTransaction('paymento', $response, 'Payment Initiation Error');
        return "Error: Unable to initiate payment. Please try again or contact support. Error details: " . ($response['error'] ?? 'Unknown error');
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
        paymento_log($params['paymentmethod'], 'API Call Error', $data, $error, $params);
        return array('success' => false, 'error' => $error);
    }

    curl_close($ch);

    $result = json_decode($response, true);

    // Log the full API response
    paymento_log($params['paymentmethod'], 'Full API Response', $data, $response, $params);

    if ($httpCode == 200 && isset($result['success']) && $result['success']) {
        paymento_log($params['paymentmethod'], 'API Call Success', $data, $response, $params);
        return array('success' => true, 'body' => $result['body']);
    } else {
        paymento_log($params['paymentmethod'], 'API Call Failed', $data, $response, $params);
        return array('success' => false, 'error' => $result['message'] ?? 'Unknown error', 'full_response' => $response);
    }
}

function paymento_log($module, $action, $request, $response, $params)
{
    if ($params['enableLogging']) {
        logTransaction($module, array('request' => $request, 'response' => $response), $action);
    }
}

// Function to set IPN URL when API key is saved
function paymento_set_ipn_url($params)
{
    $apiKey = $params['apiKey'];
    $systemUrl = $params['systemurl'];
    
    $ipnUrl = $systemUrl . 'modules/gateways/callback/paymento.php';
    
    $postfields = array(
        'IPN_Url' => $ipnUrl,
        'IPN_Method' => 1 // HTTP POST
    );
    
    $response = paymento_api_call('POST', 'payment/settings', $postfields, $params);
    
    if (!$response['success']) {
        return "Failed to set IPN URL: " . ($response['error'] ?? 'Unknown error');
    }
    
    return "IPN URL set successfully";
}

// Hook function to set IPN URL when API key is saved
function paymento_config_options_save($vars)
{
    $gatewayParams = getGatewayVariables('paymento');
    
    if ($vars['gateway'] == 'paymento' && $vars['apiKey'] != $gatewayParams['apiKey']) {
        $result = paymento_set_ipn_url($vars);
        
        if (strpos($result, 'Failed') !== false) {
            return array(
                'status' => 'error',
                'description' => $result,
            );
        }
    }
}

add_hook('AdminAreaFooterOutput', 1, function($vars) {
    return '<script type="text/javascript">
        $(document).ready(function() {
            $("#gatewaysfrm").on("submit", function(e) {
                if ($("#inputGatewaypaymento").prop("checked")) {
                    e.preventDefault();
                    var formData = $(this).serialize();
                    $.ajax({
                        url: "' . $vars['systemurl'] . 'modules/gateways/callback/paymento.php?action=setIpnUrl",
                        type: "POST",
                        data: formData,
                        dataType: "json",
                        success: function(response) {
                            if (response.success) {
                                $("#gatewaysfrm").unbind("submit").submit();
                            } else {
                                alert("Failed to set IPN URL: " + response.message);
                            }
                        },
                        error: function() {
                            alert("An error occurred while setting the IPN URL");
                        }
                    });
                }
            });
        });
    </script>';
});