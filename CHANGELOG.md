# Changelog - PAY Subscriptions Gateway

## [1.7.0] - 2026-03-05

### Fixed
- **Subscription renewal payments** now work with iDEAL initial payments
- Renewal handler tries multiple methods: payment tokens, SEPA mandate, order reference

### Added
- IBAN capture from initial iDEAL payment webhook (`customerMethod.data.iban`)
- API fallback: fetches full order details when webhook data is incomplete
- SEPA Direct Debit mandate creation for recurring payments (`POST /v1/directdebits/mandates`)
- Direct debit charge against existing mandates (`POST /v1/directdebits`)
- Subscription meta storage for IBAN, mandate ID, and initial Pay.nl order ID
- Multi-method renewal: tries token → SEPA mandate → order reference fallback

### Technical
- Webhook handler now calls `get_transaction_status()` API when `customerId` is empty
- IBAN data stored in both order and subscription meta for redundancy
- `scheduled_subscription_payment()` rewritten with 3 fallback methods
- `charge_with_token()` routes to `create_direct_debit()` for mandate IDs (IO- prefix)

## [1.6.0] - 2026-02-01

### Fixed
- **CRITICAL FIX**: Webhook handler now correctly processes Pay.nl v1 Order API format
- Fixed order status not updating after successful payment
- Orders now correctly marked as "paid" when webhook is received
- Subscriptions now automatically activated after payment

### Added
- Detection for Pay.nl v1 Order API webhook format (`object[status][action]`)
- Fallback detection for flat structure webhooks
- Version identifier in emergency logs for debugging
- Comprehensive logging of webhook data structure

### Technical
- Webhook handler checks for `$data['object']['status']['action']` (v1 format) before legacy format
- Reference field (WooCommerce order ID) extracted from `$data['object']['reference']`
- Added logging of data keys to help debug webhook format issues

## [1.5.0] - 2026-02-01

### Added
- Additional debug logging at start of process_webhook
- Flat structure detection for v1 Order API

## [1.4.0] - 2026-02-01

### Added
- Initial v1 Order API format detection (partial)

## [1.3.0] - 2026-02-01

### Added
- Robust order lookup via multiple methods (transaction_id, extra1, API reference)
- Helper function `find_wc_order_by_pay_id()`
- `extra1` parameter in Pay.nl order for reliable webhook lookup

## [1.1.8] - 2025-12-30

### Fixed
- **CRITICAL FIX**: Corrected emergency log path from `/home/dutchbal/` to `/home/dutchvitals/`
- Emergency logging now writes to correct server directory
- Webhooks can now be properly debugged

### Technical
- All emergency log paths updated to match actual cPanel directory structure

## [1.1.7] - 2025-12-30

### Debugging
- Added extensive emergency logging to webhook handler to diagnose webhook delivery issues
- Logs written to `/home/dutchvitals/logs/webhook-emergency.log`
- Tracks every step of webhook processing from file load to completion
- Helps identify where webhook processing fails

### Technical
- Emergency logging bypasses WordPress/plugin logging to ensure visibility even if WordPress crashed
- Logs include: request method, parameters, action detection, handler routing, and any exceptions

## [1.1.6] - 2025-12-30

### Fixed
- Changed webhook handler to accept GET/POST parameters instead of php://input
- Returns immediate HTTP 200 OK to prevent Pay.nl timeout
- Added fastcgi_finish_request() for proper response flushing
- Removed webhook validation that was blocking legitimate webhooks

### Added
- Action-based webhook routing for Pay.nl webhook format
- Dedicated handler for tokenization webhooks (new_ppt action)
- Comprehensive webhook logging for all webhook types

### Changed
- Webhook handler now accepts data via `$_GET` and `$_POST` arrays
- Removed strict payload validation

## [1.1.5-test] - 2025-12-30

### Fixed
- Removed `optimize` and `recurring` parameters from checkout request
- Fixed API error: "reference: Deze waarde is niet geldig"
- Checkout now works with tokenization enabled

### Changed
- Simplified checkout request to only include required parameters
- Token creation via automatic webhook after payment completion

## [1.1.4] - 2025-12-30

### Fixed
- Fixed `reference` parameter format in API request
- Changed from numeric order ID to prefixed format: `wcorder{unique_id}`

## [1.1.3] - 2025-12-30

### Added
- Initial tokenization support via Pay.nl API
- `create_token: true` in checkout request
- Webhook handler for tokenization confirmation

## [1.1.0-1.1.2] - 2025-12-30

### Development
- Various attempts at implementing recurring payments
- Testing different API approaches
- Webhook handler development

## [1.0.0] - 2025-12-30

### Initial Release
- Basic PAY.nl integration for WooCommerce Subscriptions
- Support for initial subscription payments
- Basic order processing
- Manual subscription renewal support
