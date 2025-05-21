<?php

/**
 * WHMCS Zwitch Payment Gateway Callback File
 *
 * This file handles callbacks from the Zwitch payment gateway
 *
 * @copyright Copyright (c) WHMCS Limited 2023
 * @license http://www.whmcs.com/license/ WHMCS Eula
 */

// Require libraries needed for gateway module functions.
require_once __DIR__ . "/../../../init.php";
require_once __DIR__ . "/../../../includes/gatewayfunctions.php";
require_once __DIR__ . "/../../../includes/invoicefunctions.php";

// Detect module name from filename.
$gatewayModuleName = basename(__FILE__, ".php");

// Fetch gateway configuration parameters.
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Die if module is not active.
if (!$gatewayParams["type"]) {
    die("Module Not Activated");
}

// Get common variables
$accessKey = $gatewayParams["accessKey"];
$secretKey = $gatewayParams["secretKey"];
$environment = $gatewayParams["environment"];
$apiBaseUrl =
    $environment == "sandbox"
    ? "https://api.zwitch.io/v1/pg/sandbox"
    : "https://api.zwitch.io/v1/pg";

// Generate timestamp in IST for API requests
date_default_timezone_set('Asia/Kolkata');
$timestamp = date('Y-m-d\TH:i:s'); // IST timestamp

// Bearer token format
$bearerToken = "{$accessKey}:{$secretKey}";

// Determine the action
$action = isset($_GET["action"]) ? $_GET["action"] : "";

// Handle token creation request (AJAX call from payment form)
if ($action === "create_token") {
    // Get request body
    $requestBody = file_get_contents("php://input");
    $requestData = json_decode($requestBody, true);

    if (
        !$requestData ||
        !isset($requestData["invoice_id"]) ||
        !isset($requestData["order_ref"])
    ) {
        header("Content-Type: application/json");
        echo json_encode([
            "success" => false,
            "message" => "Invalid request data",
        ]);
        exit();
    }

    // Get invoice details
    $invoiceId = $requestData["invoice_id"];
    $orderRef = $requestData["order_ref"];

    $invoiceData = localAPI("GetInvoice", ["invoiceid" => $invoiceId]);

    if ($invoiceData["result"] !== "success") {
        header("Content-Type: application/json");
        echo json_encode(["success" => false, "message" => "Invalid invoice"]);
        exit();
    }

    // Get client details
    $clientId = $invoiceData["userid"];
    $clientData = localAPI("GetClientsDetails", ["clientid" => $clientId]);

    // Format amount with 2 decimal places as required by Zwitch
    $amount = number_format($invoiceData["total"], 2, '.', '');

    // Generate a unique transaction reference with order_id
    $mtx = bin2hex(random_bytes(10)) . '_order_' . $invoiceId;

    // Create the payment token request data in the required format
    $paymentTokenData = [
        "amount" => $amount,
        "currency" => "INR", // Using INR as specified
        "mtx" => $mtx,
        "contact_number" => $clientData["phonenumber"],
        "email_id" => $clientData["email"],
        "callback_url" =>
        $systemurl .
            "modules/gateways/callback/" .
            $gatewayModuleName .
            ".php?action=callback"
    ];

    // Set up the request to create payment token
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiBaseUrl . "/payment_token");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($paymentTokenData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "X-O-Timestamp: {$timestamp}",
        "Authorization: Bearer " . $bearerToken,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        header("Content-Type: application/json");
        echo json_encode([
            "success" => false,
            "message" => "Connection error: " . curl_error($ch),
        ]);
        exit();
    }

    curl_close($ch);

    $responseData = json_decode($response, true);

    if ($httpCode == 200 && isset($responseData["id"])) {
        header("Content-Type: application/json");
        echo json_encode([
            "success" => true,
            "token" => $responseData["id"],
        ]);
        exit();
    } else {
        header("Content-Type: application/json");
        echo json_encode([
            "success" => false,
            "message" => "Error creating payment token",
            "response" => $responseData,
        ]);
        exit();
    }
} elseif ($action === "success" || $action === "failure" || $action === "callback") {
    // Handle success, failure or callback
    $paymentToken = isset($_GET["payment_token"]) ? $_GET["payment_token"] : "";
    $invoiceId = isset($_GET["invoice_id"]) ? $_GET["invoice_id"] : 0;
    $orderRef = isset($_GET["order_ref"]) ? $_GET["order_ref"] : "";

    // If no payment token in the URL, try to get it from the request body
    if (!$paymentToken && isset($_POST["payment_token"])) {
        $paymentToken = $_POST["payment_token"];
    }

    // If we have a request with mtx data, extract the order_id
    if (isset($_POST["mtx"]) && strpos($_POST["mtx"], '_order_') !== false) {
        $mtxParts = explode('_order_', $_POST["mtx"]);
        if (isset($mtxParts[1])) {
            $invoiceId = $mtxParts[1];
        }
    }

    // Verify the payment status
    if ($paymentToken) {
        // Set up the request to check payment status
        $statusEndpoint = $apiBaseUrl . "/payment_token/{$paymentToken}/payment";

        // Generate new timestamp for the request
        $timestamp = date('Y-m-d\TH:i:s');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $statusEndpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "X-O-Timestamp: {$timestamp}",
            "Authorization: Bearer " . $bearerToken,
        ]);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            logTransaction(
                $gatewayModuleName,
                [
                    "error" => curl_error($ch),
                    "payment_token" => $paymentToken,
                    "invoice_id" => $invoiceId,
                    "order_ref" => $orderRef,
                ],
                "Error"
            );

            curl_close($ch);
            if ($action !== "callback") {
                header(
                    "Location: " .
                        $gatewayParams["systemurl"] .
                        "viewinvoice.php?id=" .
                        $invoiceId .
                        "&paymentfailed=true"
                );
                exit();
            }
            header("HTTP/1.1 500 Internal Server Error");
            exit();
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $responseData = json_decode($response, true);

        // Log the transaction
        logTransaction(
            $gatewayModuleName,
            [
                "response" => $responseData,
                "payment_token" => $paymentToken,
                "invoice_id" => $invoiceId,
                "order_ref" => $orderRef,
            ],
            "Response"
        );

        // Check if the payment is successful
        if (
            $httpCode == 200 &&
            isset($responseData["status"]) &&
            $responseData["status"] === "captured"
        ) {
            // Extract payment details - amount is already in decimal format
            $paymentAmount = $responseData["amount"];
            // Use payment_token_id for sandbox mode, id for live mode
            $transactionId = $responseData["payment_token_id"];
            $paymentCurrency = $responseData["currency"];

            // Check invoice exists and is unpaid
            $invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams["name"]);

            // Check transaction hasn't been processed before
            checkCbTransID($transactionId);

            // Add invoice payment
            addInvoicePayment(
                $invoiceId,
                $transactionId,
                $paymentAmount,
                0, // No fee
                $gatewayModuleName
            );

            if ($action !== "callback") {
                // Redirect to invoice for success/failure actions
                header(
                    "Location: " .
                        $gatewayParams["systemurl"] .
                        "viewinvoice.php?id=" .
                        $invoiceId .
                        "&paymentsuccess=true"
                );
                exit();
            }
            // For callbacks, just return 200 OK
            header("HTTP/1.1 200 OK");
            exit();
        } else {
            if ($action !== "callback") {
                // Redirect to invoice for failure
                header(
                    "Location: " .
                        $gatewayParams["systemurl"] .
                        "viewinvoice.php?id=" .
                        $invoiceId .
                        "&paymentfailed=true"
                );
                exit();
            }
            // For callbacks on non-success, return 200 OK to acknowledge receipt
            header("HTTP/1.1 200 OK");
            exit();
        }
    } else {
        // No payment token provided
        logTransaction(
            $gatewayModuleName,
            [
                "error" => "No payment token provided",
                "invoice_id" => $invoiceId,
                "order_ref" => $orderRef,
            ],
            "Error"
        );

        if ($action !== "callback") {
            header(
                "Location: " .
                    $gatewayParams["systemurl"] .
                    "viewinvoice.php?id=" .
                    $invoiceId .
                    "&paymentfailed=true"
            );
            exit();
        }
        header("HTTP/1.1 400 Bad Request");
        exit();
    }
} else {
    // Invalid action
    header("HTTP/1.1 400 Bad Request");
    echo "Invalid request";
    exit();
}
