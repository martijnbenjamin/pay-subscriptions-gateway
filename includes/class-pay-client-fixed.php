<?php
namespace PAY_Subscriptions;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * PAY.nl API Client
 * Implementeert de PAY.nl REST API
 */
class Pay_Client {
    private $api_key;
    private $service_id;
    private $testmode;
    private $endpoint;

    public function __construct( $api_key, $service_id, $testmode = true ) {
        $this->api_key   = $api_key;
        $this->service_id = $service_id;
        $this->testmode  = (bool) $testmode;
        
        // PAY.nl API endpoints - Gebruik de correcte endpoints
        // De REST API gebruikt een andere URL structuur
        $this->endpoint = 'https://rest-api.pay.nl/';
    }

    /**
     * Make API request to PAY.nl
     */
    private function request( $endpoint, $method = 'POST', $data = [] ) {
        // Voor PAY.nl moeten we de token authenticatie gebruiken
        // De API key is in het format AT-xxxx-xxxx
        $url = $this->endpoint . ltrim($endpoint, '/');
        
        // Log de URL voor debugging
        if ($this->testmode) {
            log_info('PAY API Request', [
                'url' => $url,
                'method' => $method,
                'endpoint' => $endpoint
            ]);
        }
        
        $args = [
            'method'  => $method,
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->api_key . ':'),
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
        
        // Log response voor debugging
        if ($this->testmode) {
            log_info('PAY API Response', [
                'status_code' => $status_code,
                'body' => substr($body, 0, 500) // Eerste 500 karakters voor log
            ]);
        }
        
        $decoded = json_decode($body, true);

        if ($status_code >= 400) {
            $error_message = isset($decoded['message']) 
                ? $decoded['message'] 
                : (isset($decoded['error']) ? $decoded['error'] : 'Unknown API error');
            
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
     * Create initial transaction and request mandate/token for subscriptions
     * Voor PAY.nl gebruiken we de transaction/start endpoint
     * 
     * @param array $args Transaction arguments
     * @return array Response with redirect URL and transaction details
     */
    public function create_initial_charge_and_token( array $args ) : array {
        try {
            // PAY.nl gebruikt een specifiek format voor transacties
            // We moeten de serviceId, amount en andere parameters correct formatteren
            $transaction_data = [
                'serviceId' => $this->service_id,
                'amount' => [
                    'value' => $args['amount'], // Amount in cents
                    'currency' => 'EUR'
                ],
                'description' => substr($args['description'] ?? 'Order', 0, 32), // Max 32 chars
                'reference' => substr($args['reference'], 0, 64), // Max 64 chars
                'returnUrl' => $args['return_url'],
                'exchangeUrl' => $args['webhook_url']
            ];

            // Voeg klant gegevens toe indien beschikbaar
            if (!empty($args['customer_email'])) {
                $transaction_data['customer'] = [
                    'email' => $args['customer_email']
                ];
                
                if (!empty($args['customer_name'])) {
                    $names = explode(' ', $args['customer_name'], 2);
                    $transaction_data['customer']['firstName'] = $names[0] ?? '';
                    $transaction_data['customer']['lastName'] = $names[1] ?? '';
                }
            }

            // Voor subscriptions/mandates gebruiken we een andere aanpak
            // PAY.nl ondersteunt recurring payments via hun API
            if (!empty($args['create_mandate'])) {
                $transaction_data['order'] = [
                    'recurring' => 1
                ];
            }

            // Voor testmode gebruiken we een andere endpoint
            $endpoint = 'v2/transactions/start';
            
            log_info('PAY transaction request', [
                'endpoint' => $endpoint,
                'service_id' => $this->service_id,
                'amount' => $args['amount'],
                'create_mandate' => !empty($args['create_mandate'])
            ]);

            $response = $this->request($endpoint, 'POST', $transaction_data);

            // Check for transaction response
            if (!isset($response['transaction'])) {
                // Mogelijk oude API response format
                if (isset($response['transactionId'])) {
                    $response['transaction'] = [
                        'id' => $response['transactionId'],
                        'paymentUrl' => $response['paymentUrl'] ?? '',
                        'statusUrl' => $response['statusUrl'] ?? ''
                    ];
                } else {
                    throw new \Exception('No transaction in API response');
                }
            }

            $result = [
                'requires_redirect' => true,
                'redirect_url' => $response['transaction']['paymentUrl'] ?? '',
                'transaction_id' => $response['transaction']['id'] ?? null,
                'order_id' => $response['transaction']['orderId'] ?? null,
            ];

            // Store mandate/token information if available
            if (!empty($response['transaction']['recurring'])) {
                $result['mandate_id'] = $response['transaction']['recurring']['mandateId'] ?? null;
            }

            log_info('PAY transaction created', [
                'transaction_id' => $result['transaction_id'],
                'has_redirect' => !empty($result['redirect_url']),
                'mandate_requested' => !empty($args['create_mandate'])
            ]);

            return $result;

        } catch (\Exception $e) {
            log_error('PAY create_initial_charge_and_token failed', [
                'error' => $e->getMessage(),
                'service_id' => $this->service_id
            ]);
            throw $e;
        }
    }

    /**
     * Charge using previously stored mandate
     * Used for recurring subscription payments
     * 
     * @param array $args Charge arguments
     * @return array Response with payment status
     */
    public function charge_with_token( array $args ) : array {
        try {
            // Voor recurring payments met een mandate
            $transaction_data = [
                'serviceId' => $this->service_id,
                'amount' => [
                    'value' => $args['amount'],
                    'currency' => 'EUR'
                ],
                'description' => substr($args['description'] ?? 'Subscription renewal', 0, 32),
                'reference' => substr($args['reference'], 0, 64)
            ];

            // Als we een mandate hebben, gebruik deze voor recurring
            if (!empty($args['mandate_id'])) {
                $transaction_data['order'] = [
                    'recurring' => 1,
                    'mandateId' => $args['mandate_id']
                ];
            }

            $endpoint = 'v2/transactions/start';
            $response = $this->request($endpoint, 'POST', $transaction_data);

            $status = $response['transaction']['status'] ?? null;
            
            // Check of de betaling direct is goedgekeurd (bij recurring vaak het geval)
            $paid = in_array($status, ['PAID', 'AUTHORIZE', 'PENDING']);

            $result = [
                'paid' => $paid,
                'transaction_id' => $response['transaction']['id'] ?? null,
                'status' => $status
            ];

            log_info('PAY charge_with_token result', $result);

            return $result;

        } catch (\Exception $e) {
            log_error('PAY charge_with_token failed', [
                'error' => $e->getMessage(),
                'mandate_id' => $args['mandate_id'] ?? null
            ]);
            
            return [
                'paid' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get transaction status
     * 
     * @param string $transaction_id
     * @return array Transaction details
     */
    public function get_transaction_status( string $transaction_id ) : array {
        try {
            $endpoint = 'v2/transactions/' . $transaction_id . '/status';
            $response = $this->request($endpoint, 'GET');
            
            return [
                'status' => $response['transaction']['status'] ?? null,
                'paid' => in_array(
                    $response['transaction']['status'] ?? '', 
                    ['PAID', 'AUTHORIZE']
                ),
                'transaction' => $response['transaction'] ?? []
            ];
            
        } catch (\Exception $e) {
            log_error('PAY get_transaction_status failed', [
                'error' => $e->getMessage(),
                'transaction_id' => $transaction_id
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
                throw new \Exception('Transaction ID is required for refund');
            }

            $refund_data = [
                'amount' => [
                    'value' => $args['amount'],
                    'currency' => 'EUR'
                ],
                'description' => $args['description'] ?? 'Refund'
            ];

            $endpoint = 'v2/transactions/' . $args['transaction_id'] . '/refund';
            $response = $this->request($endpoint, 'POST', $refund_data);

            $result = [
                'refunded' => !empty($response['refund']['id']),
                'refund_id' => $response['refund']['id'] ?? null,
                'status' => $response['refund']['status'] ?? null
            ];

            log_info('PAY refund processed', [
                'transaction_id' => $args['transaction_id'],
                'refund_id' => $result['refund_id']
            ]);

            return $result;

        } catch (\Exception $e) {
            log_error('PAY refund failed', [
                'error' => $e->getMessage(),
                'transaction_id' => $args['transaction_id'] ?? null
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
     * Get available payment methods
     * 
     * @return array List of payment methods
     */
    public function get_payment_methods() : array {
        try {
            // Voor PAY.nl is dit een andere endpoint
            $endpoint = 'v1/services/' . $this->service_id . '/paymentmethods';
            $response = $this->request($endpoint, 'GET');
            
            return $response['paymentMethods'] ?? [];
            
        } catch (\Exception $e) {
            log_error('PAY get_payment_methods failed', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
}
