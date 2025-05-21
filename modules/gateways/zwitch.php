<?php
/**
 * WHMCS Zwitch Payment Gateway Module
 *
 * Payment Gateway modules allow you to integrate payment solutions with the
 * WHMCS platform.
 *
 * @see https://developers.whmcs.com/payment-gateways/
 *
 * @copyright Copyright (c) WHMCS Limited 2023
 * @license http://www.whmcs.com/license/ WHMCS Eula
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Define module related meta data.
 *
 * Values returned here are used to determine module related capabilities and
 * settings.
 *
 * @return array
 */
function zwitch_MetaData()
{
    return [
        "DisplayName" => "Zwitch Payment Gateway",
        "APIVersion" => "1.1", // Use API Version 1.1
    ];
}

/**
 * Define gateway configuration options.
 *
 * The fields you define here determine the configuration options that are
 * presented to administrator users when activating and configuring your
 * payment gateway module for use.
 *
 * @return array
 */
function zwitch_config()
{
    return [
        // the friendly display name for a payment gateway should be
        // defined here for backwards compatibility
        "FriendlyName" => [
            "Type" => "System",
            "Value" => "Zwitch Payment Gateway",
        ],
        "accessKey" => [
            "FriendlyName" => "Access Key",
            "Type" => "password",
            "Size" => "80",
            "Default" => "",
            "Description" => "Enter your Zwitch Access Key here",
        ],
        "secretKey" => [
            "FriendlyName" => "Secret Key",
            "Type" => "password",
            "Size" => "80",
            "Default" => "",
            "Description" => "Enter your Zwitch Secret Key here",
        ],
        "environment" => [
            "FriendlyName" => "Environment",
            "Type" => "dropdown",
            "Options" => [
                "sandbox" => "Sandbox",
                "live" => "Live",
            ],
            "Default" => "sandbox",
            "Description" =>
                "Select the environment to use for processing transactions",
        ],
    ];
}

/**
 * Payment link.
 *
 * Required by the gateway module interface.
 *
 * @param array $params Payment Gateway Module Parameters
 *
 * @return string
 */
function zwitch_link($params)
{
    // Gateway Configuration Parameters
    $accessKey = $params["accessKey"];
    $secretKey = $params["secretKey"];
    $environment = $params["environment"];

    // System Parameters
    $systemUrl = $params["systemurl"];
    $returnUrl = $params["returnurl"];
    $cancelUrl = $systemUrl . "clientarea.php?action=invoices";
    $langPayNow = $params["langpaynow"];
    $moduleName = $params["paymentmethod"];

    // Invoice Parameters
    $invoiceId = $params["invoiceid"];
    $description = $params["description"];
    $amount = $params["amount"];
    $currencyCode = $params["currency"];

    // Client Parameters
    $email = $params["clientdetails"]["email"];
    $phone = $params["clientdetails"]["phonenumber"];

    // Base API URLs
    $apiBaseUrl =
        $environment == "sandbox"
            ? "https://api.zwitch.io/v1/pg/sandbox"
            : "https://api.zwitch.io/v1/pg";

    // Create a unique order reference
    $orderReference = $invoiceId . "_order_" . time();

    // Store the order reference in a WHMCS transaction
    logTransaction(
        $moduleName,
        [
            "order_reference" => $orderReference,
            "invoice_id" => $invoiceId,
        ],
        "Pending"
    );

    // Return URL for callback from Zwitch
    $callbackUrl =
        $systemUrl . "modules/gateways/callback/" . $moduleName . ".php";

    // Add required viewport meta tag for responsiveness
    $htmlOutput =
        '<meta name="viewport" content="width=device-width, initial-scale=1" />';

    // Add the Layer.js script based on environment
    $htmlOutput .=
        $environment == "sandbox"
            ? '<script id="context" type="text/javascript" src="https://sandbox-payments.open.money/layer"></script>'
            : '<script id="context" type="text/javascript" src="https://payments.open.money/layer"></script>';

    // Initialize payment button
    $htmlOutput .=
        '<button id="zwitch-pay-button" type="button">' .
        $langPayNow .
        "</button>";

    // Add Layer.js integration script
    $htmlOutput .=
        '
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            function createPaymentToken() {
                // Show loading state
                const payButton = document.getElementById("zwitch-pay-button");
                payButton.disabled = true;
                payButton.textContent = "Processing...";

                // AJAX call to create token
                const xhr = new XMLHttpRequest();
                xhr.open("POST", "' .
        $callbackUrl .
        '?action=create_token", true);
                xhr.setRequestHeader("Content-Type", "application/json");
                xhr.onreadystatechange = function() {
                    if (xhr.readyState === 4) {
                        if (xhr.status === 200) {
                            try {
                                const response = JSON.parse(xhr.responseText);
                                if (response.success && response.token) {
                                    initializeLayer(response.token);
                                } else {
                                    handleError("Payment initialization failed: " + (response.message || "Unknown error"));
                                }
                            } catch (e) {
                                handleError("Invalid response from server");
                            }
                        } else {
                            handleError("Error connecting to payment server");
                        }
                    }
                };

                xhr.send(JSON.stringify({
                    invoice_id: "' .
        $invoiceId .
        '",
                    order_ref: "' .
        $orderReference .
        '"
                    // The server side will now handle the required format with INR currency and mtx format
                }));
            }

            function initializeLayer(paymentToken) {
                Layer.checkout({
                    token: paymentToken,
                    accesskey: "' .
        $accessKey .
        '",
                    theme: {
                        color: "#3d9080",
                        error_color: "#ff2b2b"
                    }
                },
                function(response) {
                    if (response.status == "captured") {
                        // Use appropriate payment token field based on environment
                        const tokenId = "' . $environment . '" === "sandbox" ? response.payment_token_id : response.payment_token;
                        window.location.href = "' .
        $callbackUrl .
        "?action=success&invoice_id=" .
        $invoiceId .
        "&order_ref=" .
        $orderReference .
        '&payment_token=" + tokenId;
                    } else if (response.status == "failed") {
                        // Use appropriate payment token field based on environment
                        const tokenId = "' . $environment . '" === "sandbox" ?
                            (response.payment_token_id || "") : (response.id || "");
                        window.location.href = "' .
        $callbackUrl .
        "?action=failure&invoice_id=" .
        $invoiceId .
        "&order_ref=" .
        $orderReference .
        '&payment_token=" + tokenId;
                    } else if (response.status == "cancelled") {
                        window.location.href = "' .
        $systemUrl .
        "viewinvoice.php?id=" .
        $invoiceId .
        '&paymentfailed=true";
                    }
                },
                function(err) {
                    handleError("Payment initialization error: " + err.message);
                });
            }

            function handleError(message) {
                alert(message);
                const payButton = document.getElementById("zwitch-pay-button");
                payButton.textContent = "' .
        $langPayNow .
        '";
                payButton.disabled = false;
            }

            // Set up click handler
            document.getElementById("zwitch-pay-button").addEventListener("click", createPaymentToken);
        });
    </script>';

    return $htmlOutput;
}

/**
 * Refund transaction.
 *
 * Called when a refund is requested for a previously successful transaction.
 *
 * @param array $params Payment Gateway Module Parameters
 *
 * @return array Transaction response status
 */
function zwitch_refund($params)
{
    // Gateway Configuration Parameters
    $accessKey = $params["accessKey"];
    $secretKey = $params["secretKey"];
    $environment = $params["environment"];
    $apiBaseUrl =
        $environment == "sandbox"
            ? "https://api.zwitch.io/v1/pg/sandbox"
            : "https://api.zwitch.io/v1/pg";

    // Transaction Parameters
    $transactionIdToRefund = $params["transid"];
    $refundAmount = $params["amount"];

    // Format amount with 2 decimal places as required by Zwitch
    $refundAmount = number_format($refundAmount, 2, '.', '');

    // Generate timestamp in IST
    date_default_timezone_set('Asia/Kolkata');
    $timestamp = date('Y-m-d\TH:i:s'); // IST timestamp

    // Bearer token format
    $bearerToken = "{$accessKey}:{$secretKey}";

    // Refund endpoint
    $refundEndpoint = $apiBaseUrl . "/payment_token/{$transactionIdToRefund}/refund";

    // Set up the request
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $refundEndpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt(
        $ch,
        CURLOPT_POSTFIELDS,
        json_encode([
            "amount" => $refundAmount,
        ])
    );
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "X-O-Timestamp: {$timestamp}",
        "Authorization: Bearer " . $bearerToken,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        return [
            "status" => "error",
            "rawdata" => curl_error($ch),
        ];
    }

    curl_close($ch);

    $responseData = json_decode($response, true);

    if (
        ($httpCode == 200 || $httpCode == 201) &&
        isset($responseData["status"]) &&
        ($responseData["status"] == "success" || $responseData["status"] == "processed")
    ) {
        return [
            "status" => "success",
            "transid" =>
                $responseData["id"] ?? $transactionIdToRefund,
            "rawdata" => $response,
        ];
    }

    return [
        "status" => "error",
        "rawdata" => $response,
    ];
}
