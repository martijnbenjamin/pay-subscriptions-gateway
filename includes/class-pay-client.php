<?php
namespace PAY_Subscriptions;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * PAY.nl API Client
 * Implementeert de PAY.nl REST API v1 (connect.payments.nl)
 */
class Pay_Client {
    private $token_code;  // AT-xxxx-xxxx
    private $api_token;   // API Token/Password
    private $service_id;  // SL-xxxx-xxxx
    private $testmode;
    private $endpoint;

    public function __construct( $token_code, $api_token, $service_id, $testmode = true ) {
        $this->token_code = $token_code;
        $this->api_token  = $api_token;
        $this->service_id = $service_id;
        $this->testmode   = (bool) $testmode;

        // PAY.nl API endpoint - v1 is the current production API
        // BELANGRIJK: Het is payments.nl, NIET pay.nl!
        $this->endpoint = 'https://connect.payments.nl/';
    }

    /**
     * Make API request to PAY.nl using Basic Authentication
     */
    private function request( $endpoint, $method = 'POST', $data = [] ) {
        $url = $this->endpoint . ltrim($endpoint, '/');

        // PAY.nl uses Basic Authentication: base64(tokenCode:apiToken)
        $authorization = base64_encode($this->token_code . ':' . $this->api_token);

        // Log de URL voor debugging
        log_debug('PAY API Request', [
            'url' => $url,
            'method' => $method,
            'endpoint' => $endpoint,
            'data' => $data
        ]);

        $args = [
            'method'  => $method,
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Basic ' . $authorization,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ]
        ];

        if (!empty($data) && $method !== 'GET') {
            $args['body'] = wp_json_encode($data);
        } elseif (!empty($data) && $method === 'GET') {
            $url = add_query_arg($data, $url);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            log_error('PAY API WP Error', [
                'message' => $response->get_error_message()
            ]);
            throw new \Exception(sprintf(
                'PAY API Error: %s',
                $response->get_error_message()
            ));
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        $decoded = json_decode($body, true);

        // Log response voor debugging
        log_debug('PAY API Response', [
            'status_code' => $status_code,
            'body' => $decoded
        ]);

        if ($status_code >= 400) {
            $error_message = $decoded['message'] ?? $decoded['error'] ?? 'Unknown API error';

            // Check for validation errors (violations array)
            if (!empty($decoded['violations'])) {
                $violations = [];
                foreach ($decoded['violations'] as $v) {
                    $violations[] = ($v['propertyPath'] ?? '') . ': ' . ($v['message'] ?? '');
                }
                $error_message = implode('; ', $violations);
            }

            // Extra error details loggen
            log_error('PAY API Error Response', [
                'status_code' => $status_code,
                'error' => $error_message,
                'response' => $decoded
            ]);

            throw new \Exception(sprintf(
                'PAY API Error (%d): %s',
                $status_code,
                $error_message
            ));
        }

        return $decoded;
    }

    /**
     * Create initial order and request token for subscriptions
     * Uses Pay.nl v1 API at connect.payments.nl/v1/orders
     *
     * @param array $args Order arguments
     * @return array Response with redirect URL and order details
     */
    public function create_initial_charge_and_token( array $args ) : array {
        try {
            // Build transaction data according to Pay.nl v1 API spec
            // BELANGRIJK: reference mag alleen alfanumeriek zijn, max 64 tekens
            // WooCommerce order keys hebben underscores (wc_order_xxx) die niet toegestaan zijn
            $clean_reference = substr(preg_replace('/[^a-zA-Z0-9]/', '', $args['reference']), 0, 64);

            $order_data = [
                'serviceId' => $this->service_id,
                'amount' => [
                    'value' => (int) $args['amount'],  // Amount in cents
                    'currency' => 'EUR'
                ],
                'description' => substr($args['description'] ?? 'Order', 0, 128),
                'reference' => $clean_reference,
                'returnUrl' => $args['return_url'],
                'exchangeUrl' => $args['webhook_url'],
                'integration' => [
                    'test' => $this->testmode,
                ],
                'stats' => [
                    'object' => 'woocommerce-pay-subscriptions ' . PAY_SUBS_VERSION,
                    // Store WC order ID in extra1 for reliable webhook lookup
                    'extra1' => $clean_reference,
                ],
            ];

            // Payment method configuration
            // 10 = iDEAL (supports mandate creation)
            $order_data['paymentMethod'] = [
                'id' => 10  // iDEAL for initial payment
            ];

            // Voeg klantgegevens toe
            if (!empty($args['customer_email']) || !empty($args['customer_name'])) {
                $name_parts = explode(' ', $args['customer_name'] ?? '', 2);
                $order_data['customer'] = [
                    'email' => $args['customer_email'] ?? '',
                    'firstName' => $name_parts[0] ?? '',
                    'lastName' => $name_parts[1] ?? '',
                ];
            }

            // Add mandate/recurring request for subscriptions
            // This is the KEY part that enables token generation!
            if (!empty($args['create_mandate'])) {
                $order_data['order'] = [
                    'recurring' => 1,  // Enable recurring/mandate creation
                ];

                // Add transfer data for mandate description (shown to customer)
                $order_data['transfer'] = [
                    'description' => $args['mandate_description'] ?? 'Incassomachtiging',
                ];

                log_debug('Mandate creation enabled for transaction', [
                    'mandate_description' => $args['mandate_description'] ?? 'Incassomachtiging'
                ]);
            }

            // v1 Order API endpoint
            $endpoint = 'v1/orders';

            log_info('PAY order request', [
                'endpoint' => $endpoint,
                'service_id' => $this->service_id,
                'amount' => $args['amount'],
                'create_mandate' => !empty($args['create_mandate'])
            ]);

            $response = $this->request($endpoint, 'POST', $order_data);

            // Parse response - Pay.nl v1 API structure
            if (empty($response['orderId']) && empty($response['id'])) {
                throw new \Exception('No orderId in response: ' . wp_json_encode($response));
            }

            $result = [
                'requires_redirect' => true,
                'redirect_url' => $response['links']['redirect'] ?? $response['paymentUrl'] ?? '',
                'transaction_id' => $response['orderId'] ?? $response['id'] ?? null,
                'order_id' => $response['orderId'] ?? $response['id'] ?? null,
                'paid' => false,
            ];

            // The mandate ID is typically returned after successful payment via webhook
            // But if available immediately, store it
            if (!empty($response['recurring']['mandateId'])) {
                $result['mandate_id'] = $response['recurring']['mandateId'];
                $result['token'] = $response['recurring']['mandateId'];
            }

            log_info('PAY order created', [
                'order_id' => $result['order_id'],
                'redirect_url' => $result['redirect_url'],
                'mandate_requested' => !empty($args['create_mandate'])
            ]);

            return $result;

        } catch (\Exception $e) {
            log_error('PAY create_initial_charge_and_token failed', [
                'error' => $e->getMessage(),
                'args' => array_merge($args, ['api_token' => '[REDACTED]'])
            ]);
            throw $e;
        }
    }

    /**
     * Charge using previously stored token (recurring_id)
     * Used for recurring subscription payments (MIT - Merchant Initiated Transaction)
     *
     * @param array $args Charge arguments including recurring_id/token
     * @return array Response with payment status
     */
    public function charge_with_token( array $args ) : array {
        try {
            // Haal de recurring_id (token) op
            $recurring_id = $args['mandate_id'] ?? $args['token'] ?? $args['recurring_id'] ?? null;

            if (empty($recurring_id)) {
                throw new \Exception('No recurring_id/token provided for recurring payment');
            }

            // v1 Order API voor recurring/token payments
            $order_data = [
                'serviceId' => $this->service_id,
                'amount' => [
                    'value' => (int) $args['amount'],
                    'currency' => 'EUR'
                ],
                'description' => substr($args['description'] ?? 'Subscription renewal', 0, 128),
                'reference' => substr(preg_replace('/[^a-zA-Z0-9]/', '', $args['reference']), 0, 64),
                // MIT = Merchant Initiated Transaction (geen klant interactie nodig)
                'payment' => [
                    'method' => 'token',
                    'token' => [
                        'id' => $recurring_id
                    ]
                ],
                'transaction' => [
                    'type' => 'mit' // Merchant Initiated Transaction
                ]
            ];

            // Exchange URL voor status updates
            if (!empty($args['webhook_url'])) {
                $order_data['exchangeUrl'] = $args['webhook_url'];
            }

            $endpoint = 'v1/orders';

            log_info('PAY recurring payment request', [
                'endpoint' => $endpoint,
                'recurring_id' => substr($recurring_id, 0, 10) . '...',
                'amount' => $args['amount']
            ]);

            $response = $this->request($endpoint, 'POST', $order_data);

            // Check status van de recurring payment
            $status = $response['status']['action'] ?? $response['status'] ?? null;
            $paid = in_array(strtoupper($status), ['PAID', 'AUTHORIZE', 'PENDING', 'APPROVED']);

            $result = [
                'paid' => $paid,
                'transaction_id' => $response['id'] ?? $response['orderId'] ?? null,
                'status' => $status
            ];

            log_info('PAY recurring payment result', $result);

            return $result;

        } catch (\Exception $e) {
            log_error('PAY charge_with_token failed', [
                'error' => $e->getMessage(),
                'recurring_id' => isset($recurring_id) ? substr($recurring_id, 0, 10) . '...' : null
            ]);

            return [
                'paid' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get order/transaction status
     *
     * @param string $order_id The PAY.nl order ID
     * @return array Order details including status
     */
    public function get_transaction_status( string $order_id ) : array {
        try {
            // v1 Order status endpoint - haal volledige details op
            $endpoint = 'v1/orders/' . $order_id;
            $response = $this->request($endpoint, 'GET');

            $status = $response['status']['action'] ?? $response['status'] ?? null;

            // Extract recurring/mandate ID from response
            // Pay.nl kan dit op verschillende plekken terugsturen
            $recurring_id = $response['payments'][0]['customerId'] ??
                           $response['customerId'] ??
                           $response['recurring']['mandateId'] ??
                           $response['paymentDetails']['recurring_id'] ??
                           null;

            log_debug('PAY get_transaction_status response', [
                'order_id' => $order_id,
                'status' => $status,
                'recurring_id' => $recurring_id ? substr($recurring_id, 0, 10) . '...' : null,
                'has_payments' => !empty($response['payments']),
                'response_keys' => array_keys($response)
            ]);

            return [
                'status' => $status,
                'paid' => in_array(
                    strtoupper($status),
                    ['PAID', 'AUTHORIZE', 'APPROVED']
                ),
                'recurring_id' => $recurring_id,
                'customer_id' => $recurring_id,  // Alias for compatibility
                'transaction' => $response
            ];

        } catch (\Exception $e) {
            log_error('PAY get_transaction_status failed', [
                'error' => $e->getMessage(),
                'order_id' => $order_id
            ]);
            throw $e;
        }
    }

    /**
     * Process refund
     *
     * @param array $args Refund arguments
     * @return array Refund response
     */
    public function refund( array $args ) : array {
        try {
            if (empty($args['transaction_id'])) {
                throw new \Exception('Order ID is required for refund');
            }

            $refund_data = [
                'amount' => [
                    'value' => (int) $args['amount'],
                    'currency' => 'EUR'
                ],
                'description' => $args['description'] ?? 'Refund'
            ];

            // v1 Order refund endpoint
            $endpoint = 'v1/orders/' . $args['transaction_id'] . '/refund';
            $response = $this->request($endpoint, 'POST', $refund_data);

            $result = [
                'refunded' => !empty($response['id']),
                'refund_id' => $response['id'] ?? null,
                'status' => $response['status'] ?? null
            ];

            log_info('PAY refund processed', [
                'order_id' => $args['transaction_id'],
                'refund_id' => $result['refund_id']
            ]);

            return $result;

        } catch (\Exception $e) {
            log_error('PAY refund failed', [
                'error' => $e->getMessage(),
                'order_id' => $args['transaction_id'] ?? null
            ]);

            return [
                'refunded' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Verify webhook signature
     * PAY.nl gebruikt een specifieke webhook verificatie
     * 
     * @param string $payload Raw request body
     * @param string $signature Signature from header
     * @param string $secret Webhook secret
     * @return bool
     */
    public function verify_signature( string $payload, string $signature, string $secret ) : bool {
        if (empty($secret)) {
            log_error('Webhook secret is not configured');
            return false;
        }

        // PAY.nl kan verschillende signature methodes gebruiken
        // Check documentatie voor de exacte methode
        
        // Methode 1: HMAC-SHA256 (meest voorkomend)
        $calculated = hash_hmac('sha256', $payload, $secret);
        if (hash_equals($calculated, $signature)) {
            return true;
        }
        
        // Methode 2: Base64 encoded HMAC-SHA256
        $calculated_base64 = base64_encode(hash_hmac('sha256', $payload, $secret, true));
        if (hash_equals($calculated_base64, $signature)) {
            return true;
        }
        
        return false;
    }

    /**
     * Get available payment methods via Service config
     *
     * @return array List of payment methods
     */
    public function get_payment_methods() : array {
        try {
            // v1 Service config endpoint
            $endpoint = 'v1/services/config?serviceId=' . $this->service_id;
            $response = $this->request($endpoint, 'GET');

            return $response['paymentMethods'] ?? $response['paymentProfiles'] ?? [];

        } catch (\Exception $e) {
            log_error('PAY get_payment_methods failed', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Test API connection
     *
     * @return bool True if connection successful
     */
    public function test_connection() : bool {
        try {
            $endpoint = 'v1/services/config?serviceId=' . $this->service_id;
            $response = $this->request($endpoint, 'GET');
            return !empty($response);
        } catch (\Exception $e) {
            log_error('PAY test_connection failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
