# Zwitch Payment Gateway for WHMCS

## Overview

The Zwitch Payment Gateway module for WHMCS allows you to seamlessly integrate Zwitch's payment processing capabilities into your WHMCS installation. This module supports both sandbox (testing) and live environments, enabling secure credit card transactions for your clients.

## Features

- **Seamless Checkout**: Offers a smooth, embedded payment experience using Layer.js
- **Secure Transactions**: Uses Zwitch's secure payment tokenization process
- **Sandbox Testing**: Test your integration in a sandbox environment before going live
- **Automatic Invoice Updates**: Automatically marks invoices as paid upon successful payment
- **Transaction Logging**: All transactions are logged in WHMCS for easy reference
- **Refund Support**: Process refunds directly from the WHMCS admin area

## Requirements

- WHMCS 7.0 or higher
- PHP 7.2 or higher
- CURL support enabled in PHP
- Zwitch merchant account with API credentials

## Installation

1. Download the Zwitch payment gateway module
2. Upload the module files to your WHMCS installation directory
3. The module consists of two key files:
   - `modules/gateways/zwitch.php`: The main gateway module
   - `modules/gateways/callback/zwitch.php`: The callback handler for payment processing

## Configuration

1. Log in to your WHMCS admin area
2. Navigate to **Setup** > **Payments** > **Payment Gateways**
3. In the **All Payment Gateways** tab, find and click on **Zwitch Payment Gateway**
4. Enter your Zwitch API credentials:
   - **Access Key**: Your Zwitch Access Key
   - **Secret Key**: Your Zwitch Secret Key
   - **Environment**: Select "Sandbox" for testing or "Live" for production
5. Click **Save Changes**

## Obtaining API Credentials

1. Log in to your Zwitch merchant dashboard
2. Navigate to the API Keys section
3. Generate or copy your existing Access Key and Secret Key
4. Use these credentials in your WHMCS Zwitch module configuration

## Testing

1. Configure the module with your Sandbox credentials
2. Create a test invoice in WHMCS
3. Use the Zwitch test card details to make a payment:
   - Card Number: 4111 1111 1111 1111
   - Expiry Date: Any future date
   - CVV: Any 3-digit number
4. Verify that the transaction is processed and the invoice is marked as paid

## Going Live

Once you have completed testing in the sandbox environment:

1. Log in to your WHMCS admin area
2. Navigate to **Setup** > **Payments** > **Payment Gateways**
3. Open the Zwitch Payment Gateway configuration
4. Replace your Sandbox credentials with your Live credentials
5. Change the Environment setting to "Live"
6. Click **Save Changes**

## Transaction Flow

1. Customer selects Zwitch as the payment method for their invoice
2. The module generates a payment token through Zwitch's API
3. The Layer.js script displays a secure payment form
4. Customer enters their payment details
5. Upon successful payment, Zwitch sends a notification to WHMCS
6. The callback file processes the notification and updates the invoice status
7. Customer is redirected to the invoice page with a success message

## Handling Refunds

To process a refund:

1. Navigate to the relevant transaction in WHMCS
2. Click on the "Refund" button
3. Enter the amount to refund
4. Submit the refund request
5. The module will process the refund through Zwitch's API

## Troubleshooting

### Payment Not Processing

- Verify that your API credentials are correct
- Check that you're using the correct environment (Sandbox/Live)
- Review WHMCS Gateway Logs for any error messages

### Callback Errors

- Ensure your server can make outbound connections to Zwitch's API
- Check that your server allows incoming connections from Zwitch's IPs
- Verify that the callback URL is accessible

### Transaction Logging

All transactions are logged in WHMCS. To view the logs:

1. Navigate to **Utilities** > **Logs** > **Gateway Log**
2. Filter the logs for "Zwitch" to see all related transactions

## Support

For support with this module, please contact your Zwitch account manager or submit a support ticket through your WHMCS admin area.

---

© 2023 WHMCS Limited. All rights reserved.