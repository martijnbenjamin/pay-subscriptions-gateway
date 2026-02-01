=== PAY Subscriptions Gateway (Custom) ===
Contributors: 072DESIGN
Requires at least: 6.4
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A custom WooCommerce Subscriptions gateway integrating PAY via tokenisation/mandates + webhook handling.

== Description ==
This is a scaffold plugin. You must implement real PAY API calls in `includes/class-pay-client.php`.
Use WooCommerce Subscriptions for renewals and retry logic.

== Installation ==
1. Upload the `pay-subscriptions-gateway` folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. In WooCommerce > Settings > Payments, enable "PAY (Subscriptions)".
4. Fill API Key, Service ID, Webhook Secret. Copy the webhook URL from the settings page (see below).

== Webhook URL ==
Legacy WooCommerce endpoint:
`https://example.com/?wc-api=pay_subs_webhook`

Add the webhook secret in your PAY dashboard so HMAC validation works.

== Notes ==
- Token data is stored via WooCommerce Payment Tokens; only references are kept to avoid PCI exposure.
- SEPA prenotification emails are not included; add via custom email templates.
