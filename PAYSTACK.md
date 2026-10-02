Luxe Haven Paystack checkout
===========================

The store at /b/luxe-haven/ supports Paystack hosted checkout for card and
Ghana Mobile Money payments in GHS, alongside Cash on Delivery.

Sign in as the Luxe Haven owner and open Payment Settings. Enter the store's
matching Paystack public and secret keys, select Test Mode for test keys,
enable checkout, save, then use Test Connection. Keys are stored encrypted
per business. Other stores use their own keys.

The encryption key comes from PAYMENT_SETTINGS_KEY or the ignored file
php/payment-config.local.php. Keep that file/key in secure backups and
preserve it when deploying, so saved secrets remain readable.

Set the Paystack webhook to https://YOUR-DOMAIN/possystem/php/paystack-webhook.php
(adjust the project prefix for your hosting). Paystack cannot deliver webhooks
to localhost. The callback URL is generated for the tenant storefront.

Test successful, cancelled, declined, and pending payments with Paystack test
keys. Successful verification creates one paid order and deducts stock once.
Refresh a pending return page to retry verification; keep its reference if
support is needed. Verify repeated callbacks do not duplicate the order.

Implementation follows https://paystack.com/docs/payments/accept-payments/
and https://paystack.com/docs/payments/webhooks/.
