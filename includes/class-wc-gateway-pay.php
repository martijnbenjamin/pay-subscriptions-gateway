<?php
namespace PAY_Subscriptions;

if ( ! defined( 'ABSPATH' ) ) exit;

class WC_Gateway_PAY extends \WC_Payment_Gateway {

    private $token_code;     // AT-xxxx-xxxx
    private $api_token;      // API Token (password)
    private $service_id;     // SL-xxxx-xxxx
    private $testmode;
    private $webhook_secret;
    private $debug;
    
    public function __construct() {
        $this->id                 = 'pay_subscriptions';
        $this->icon               = PAY_SUBS_PLUGIN_URL . 'assets/euro.svg';
        $this->method_title       = 'PAY (Subscriptions)';
        $this->method_description = 'PAY integratie met WooCommerce Subscriptions voor automatische incasso\'s.';
        $this->has_fields         = true;
        $this->supports           = [
            'products',
            'subscriptions',
            'subscription_suspension',
            'subscription_cancellation',
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes',
            'subscription_payment_method_change',
            'subscription_payment_method_change_customer',
            'subscription_payment_method_change_admin',
            'multiple_subscriptions',
            'refunds'
        ];

        // Initialize settings
        $this->init_form_fields();
        $this->init_settings();

        // Load settings
        $this->title          = $this->get_option('title', 'PAY');
        $this->description    = $this->get_option('description', 'Betaal veilig via PAY. Bij abonnementen geef je een incassomachtiging.');
        $this->token_code     = $this->get_option('token_code', '');   // AT-xxxx-xxxx
        $this->api_token      = $this->get_option('api_token', '');    // API Token
        $this->service_id     = $this->get_option('service_id', '');   // SL-xxxx-xxxx
        $this->testmode       = 'yes' === $this->get_option('testmode', 'yes');
        $this->webhook_secret = $this->get_option('webhook_secret', '');
        $this->debug          = 'yes' === $this->get_option('debug', 'no');
        
        // Subscription-only mode setting
        $this->subscription_only = 'yes' === $this->get_option('subscription_only', 'yes');
        
        // Alternative title for subscription context
        $this->subscription_title = $this->get_option('subscription_title', 'Automatische incasso (via PAY.nl)');

        // Hooks
        add_action("woocommerce_update_options_payment_gateways_{$this->id}", [$this, 'process_admin_options']);
        add_action("woocommerce_scheduled_subscription_payment_{$this->id}", [$this, 'scheduled_subscription_payment'], 10, 2);
        add_action('woocommerce_subscription_failing_payment_method_updated_' . $this->id, [$this, 'update_failing_payment_method'], 10, 2);
        
        // Dynamically adjust title based on cart contents
        add_filter('woocommerce_gateway_title', [$this, 'adjust_gateway_title'], 10, 2);
        add_filter('woocommerce_gateway_description', [$this, 'adjust_gateway_description'], 10, 2);
        
        // Log initialization
        if ($this->debug) {
            log_info('PAY Gateway initialized', [
                'testmode' => $this->testmode,
                'service_id' => $this->service_id,
                'subscription_only' => $this->subscription_only
            ]);
        }
    }

    /**
     * Initialize settings form fields
     */
    public function init_form_fields() {
        $this->form_fields = [
            'enabled' => [
                'title'   => __('Inschakelen/Uitschakelen', 'pay-subs'),
                'type'    => 'checkbox',
                'label'   => __('Schakel PAY gateway in', 'pay-subs'),
                'default' => 'no'
            ],
            'title' => [
                'title'       => __('Titel (normale betalingen)', 'pay-subs'),
                'type'        => 'text',
                'description' => __('De titel voor normale betalingen (wordt alleen getoond als subscription_only uit staat).', 'pay-subs'),
                'default'     => 'PAY',
                'desc_tip'    => true,
            ],
            'subscription_title' => [
                'title'       => __('Titel (abonnementen)', 'pay-subs'),
                'type'        => 'text',
                'description' => __('De titel wanneer de klant een abonnement afrekent.', 'pay-subs'),
                'default'     => 'Automatische incasso (via PAY.nl)',
                'desc_tip'    => true,
            ],
            'description' => [
                'title'       => __('Omschrijving', 'pay-subs'),
                'type'        => 'textarea',
                'description' => __('De omschrijving die de gebruiker ziet tijdens checkout.', 'pay-subs'),
                'default'     => 'Betaal veilig via PAY. Bij abonnementen geef je een incassomachtiging.',
                'desc_tip'    => true,
            ],
            'subscription_only' => [
                'title'       => __('Alleen voor abonnementen', 'pay-subs'),
                'type'        => 'checkbox',
                'label'       => __('Toon deze gateway alleen wanneer er een abonnement in de winkelwagen zit', 'pay-subs'),
                'description' => __('Als dit is ingeschakeld, wordt automatische incasso alleen getoond voor abonnementen.', 'pay-subs'),
                'default'     => 'yes',
                'desc_tip'    => true,
            ],
            'token_code' => [
                'title'       => __('PAY Token Code', 'pay-subs'),
                'type'        => 'text',
                'description' => __('Je PAY Token Code (AT-xxxx-xxxx). Te vinden in My.pay.nl onder Company > API Tokens.', 'pay-subs'),
                'placeholder' => 'AT-xxxx-xxxx',
                'desc_tip'    => true,
            ],
            'api_token' => [
                'title'       => __('PAY API Token', 'pay-subs'),
                'type'        => 'password',
                'description' => __('Je PAY API Token (wachtwoord). Te vinden in My.pay.nl onder Company > API Tokens.', 'pay-subs'),
                'desc_tip'    => true,
            ],
            'service_id' => [
                'title'       => __('PAY Service ID', 'pay-subs'),
                'type'        => 'text',
                'description' => __('Je PAY Sales Location / Service ID (SL-xxxx-xxxx). Te vinden in My.pay.nl onder Sales Locations.', 'pay-subs'),
                'placeholder' => 'SL-xxxx-xxxx',
                'desc_tip'    => true,
            ],
            'testmode' => [
                'title'       => __('Testmodus', 'pay-subs'),
                'type'        => 'checkbox',
                'label'       => __('Schakel testmodus in', 'pay-subs'),
                'description' => __('Gebruik de PAY sandbox omgeving voor testen.', 'pay-subs'),
                'default'     => 'yes',
                'desc_tip'    => true,
            ],
            'webhook_secret' => [
                'title'       => __('Webhook Secret', 'pay-subs'),
                'type'        => 'password',
                'description' => sprintf(
                    __('Webhook URL: %s', 'pay-subs'),
                    get_webhook_url()
                ),
                'desc_tip'    => false,
                'default'     => wp_generate_password(32, false, false)
            ],
            'debug' => [
                'title'       => __('Debug Logging', 'pay-subs'),
                'type'        => 'checkbox',
                'label'       => __('Schakel debug logging in', 'pay-subs'),
                'description' => __('Log PAY events naar WooCommerce logs.', 'pay-subs'),
                'default'     => 'no',
                'desc_tip'    => true,
            ],
        ];
    }

    /**
     * Adjust gateway title based on cart contents
     */
    public function adjust_gateway_title($title, $id) {
        if ($id !== $this->id) {
            return $title;
        }

        // Check if cart contains subscription
        if ($this->cart_contains_subscription()) {
            return $this->subscription_title;
        }

        return $this->title;
    }

    /**
     * Adjust gateway description based on cart contents
     */
    public function adjust_gateway_description($description, $id) {
        if ($id !== $this->id) {
            return $description;
        }

        // Special description for subscriptions
        if ($this->cart_contains_subscription()) {
            return __('Voor abonnementen wordt automatische incasso gebruikt. Je geeft toestemming voor terugkerende betalingen.', 'pay-subs');
        }

        return $description;
    }

    /**
     * Check if cart contains subscription product
     */
    private function cart_contains_subscription() {
        if (!function_exists('WC') || !WC()->cart) {
            return false;
        }

        // Check if any cart item is a subscription or will create one
        foreach (WC()->cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            
            // Check if product is subscription
            if (class_exists('WC_Subscriptions_Product')) {
                if (\WC_Subscriptions_Product::is_subscription($product)) {
                    return true;
                }
            }
        }

        // Also check for subscription renewals or switches
        if (function_exists('\wcs_cart_contains_renewal')) {
            if (\wcs_cart_contains_renewal()) {
                return true;
            }
        }
        
        // Check for subscription switches separately
        if (function_exists('\wcs_cart_contains_switch')) {
            if (\wcs_cart_contains_switch()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if gateway is available
     * Override to hide gateway for non-subscription products when in subscription-only mode
     */
    public function is_available() {
        // First check parent availability
        if (!parent::is_available()) {
            return false;
        }

        // Check basic requirements
        if ('yes' !== $this->enabled) {
            return false;
        }
        
        if (empty($this->token_code) || empty($this->api_token) || empty($this->service_id)) {
            return false;
        }

        // If subscription-only mode is enabled
        if ($this->subscription_only) {
            // Hide for admin orders
            if (is_admin() && !defined('DOING_AJAX')) {
                return true; // Always available in admin
            }

            // Check if this is checkout or pay for order page
            if (is_checkout() || is_checkout_pay_page()) {
                // For pay page, check the order
                if (is_checkout_pay_page()) {
                    global $wp;
                    if (isset($wp->query_vars['order-pay'])) {
                        $order_id = absint($wp->query_vars['order-pay']);
                        $order = wc_get_order($order_id);
                        
                        if ($order && function_exists('\wcs_order_contains_subscription')) {
                            return \wcs_order_contains_subscription($order) || \wcs_is_subscription($order);
                        }
                    }
                }
                
                // For regular checkout, check cart
                return $this->cart_contains_subscription();
            }

            // For subscription management pages
            if (function_exists('\wcs_is_subscription') && isset($_GET['change_payment_method'])) {
                return true;
            }
        }
        
        return true;
    }

    /**
     * Payment form on checkout page
     */
    public function payment_fields() {
        // Show different description based on cart contents
        $description = $this->description;
        
        if ($this->cart_contains_subscription()) {
            $description = __('Voor abonnementen wordt automatische incasso gebruikt. Bij de eerste betaling geef je een machtiging voor toekomstige automatische afschrijvingen.', 'pay-subs');
        }
        
        echo wpautop(wp_kses_post($description));
        
        // Don't show saved payment methods interface for this gateway
        // The gateway will always use automatic incasso for subscriptions
        // No need to show "use new payment method" option
    }

    /**
     * Process the payment
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wc_add_notice(__('Order niet gevonden.', 'pay-subs'), 'error');
            return ['result' => 'fail'];
        }

        try {
            // Check if this is a subscription
            $is_subscription = function_exists('\wcs_order_contains_subscription') && 
                             (\wcs_order_contains_subscription($order) || \wcs_is_subscription($order));
            
            // If subscription-only mode and no subscription, reject
            if ($this->subscription_only && !$is_subscription) {
                throw new \Exception(__('Deze betaalmethode is alleen beschikbaar voor abonnementen.', 'pay-subs'));
            }
            
            // Check if using saved payment method
            $token_id = isset($_POST['wc-pay_subscriptions-payment-token']) ? 
                       wc_clean($_POST['wc-pay_subscriptions-payment-token']) : null;
            
            if ($token_id && $token_id !== 'new') {
                return $this->process_payment_with_token($order, $token_id);
            }
            
            // New payment method
            return $this->process_new_payment($order, $is_subscription);
            
        } catch (\Exception $e) {
            log_error('Payment processing failed', [
                'order_id' => $order_id,
                'error' => $e->getMessage()
            ]);
            
            wc_add_notice(
                sprintf(__('Betaling mislukt: %s', 'pay-subs'), $e->getMessage()),
                'error'
            );
            
            return ['result' => 'fail'];
        }
    }

    /**
     * Process payment with saved token
     */
    private function process_payment_with_token($order, $token_id) {
        $token = \WC_Payment_Tokens::get($token_id);
        
        if (!$token || $token->get_user_id() !== get_current_user_id()) {
            throw new \Exception(__('Ongeldige betaalmethode.', 'pay-subs'));
        }

        $client = new Pay_Client($this->token_code, $this->api_token, $this->service_id, $this->testmode);
        
        $result = $client->charge_with_token([
            'amount' => cents($order->get_total()),
            'description' => sprintf('Order %s', $order->get_order_number()),
            'reference' => (string) $order->get_id(),  // Gebruik order ID (numeriek)
            'token' => $token->get_token(),
            'mandate_id' => $token->get_meta('mandate_id')
        ]);

        if ($result['paid']) {
            $order->payment_complete($result['transaction_id']);
            
            // Save token to subscription
            if (function_exists('\wcs_order_contains_subscription') && \wcs_order_contains_subscription($order)) {
                $subscriptions = \wcs_get_subscriptions_for_order($order);
                foreach ($subscriptions as $subscription) {
                    $subscription->add_payment_token($token);
                }
            }
            
            return [
                'result' => 'success',
                'redirect' => $this->get_return_url($order)
            ];
        }

        throw new \Exception(__('Betaling mislukt.', 'pay-subs'));
    }

    /**
     * Process new payment
     */
    private function process_new_payment($order, $is_subscription) {
        $client = new Pay_Client($this->token_code, $this->api_token, $this->service_id, $this->testmode);
        
        // BELANGRIJK: reference moet alfanumeriek zijn (max 64 tekens)
        // Gebruik WooCommerce order ID (numeriek) als reference voor betrouwbare terugkoppeling
        $args = [
            'amount' => cents($order->get_total()),
            'description' => sprintf('Order %s', $order->get_order_number()),
            'reference' => (string) $order->get_id(),  // Gebruik order ID (numeriek, altijd geldig)
            'return_url' => $this->get_return_url($order),
            'webhook_url' => get_webhook_url(),
            'customer_email' => $order->get_billing_email(),
            'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
        ];

        // For subscriptions, always create mandate
        if ($is_subscription) {
            $args['create_mandate'] = true;
            $args['mandate_description'] = sprintf(
                __('Machtiging voor abonnement bij %s', 'pay-subs'),
                get_bloginfo('name')
            );
            $args['payment_method'] = 'ideal'; // Use iDEAL for initial mandate creation
        } else {
            // For regular products when subscription_only is disabled
            // Use regular one-time payment without mandate
            $args['payment_method'] = 'ideal';
            $args['create_mandate'] = false;
        }

        $result = $client->create_initial_charge_and_token($args);

        if ($this->debug) {
            log_info('PAY payment initiated', [
                'order_id' => $order->get_id(),
                'transaction_id' => $result['transaction_id'] ?? null,
                'is_subscription' => $is_subscription
            ]);
        }

        // Store transaction ID
        if (!empty($result['transaction_id'])) {
            $order->set_transaction_id($result['transaction_id']);
            $order->save();
        }

        // Mark order as pending
        $order->update_status('pending', __('Wacht op PAY betaling', 'pay-subs'));

        if ($result['requires_redirect']) {
            return [
                'result' => 'success',
                'redirect' => $result['redirect_url']
            ];
        }

        // Immediate payment success (shouldn't happen normally)
        if (!empty($result['paid']) && $result['paid']) {
            $order->payment_complete($result['transaction_id']);
            
            // Only save payment token for subscriptions
            if ($is_subscription) {
                $this->save_payment_token($order, $result);
            }
            
            return [
                'result' => 'success',
                'redirect' => $this->get_return_url($order)
            ];
        }

        throw new \Exception(__('Onverwacht antwoord van PAY API.', 'pay-subs'));
    }

    /**
     * Save payment token for future use
     */
    private function save_payment_token($order, $payment_result) {
        if (empty($payment_result['token']) && empty($payment_result['mandate_id'])) {
            return;
        }

        $token = new WC_Payment_Token_PAY();
        $token->set_user_id($order->get_customer_id());
        $token->set_token($payment_result['token'] ?? $payment_result['mandate_id']);
        $token->set_gateway_id($this->id);
        
        if (!empty($payment_result['mandate_id'])) {
            $token->add_meta_data('mandate_id', $payment_result['mandate_id']);
        }
        
        if (!empty($payment_result['last4'])) {
            $token->add_meta_data('last4', $payment_result['last4']);
        }
        
        $token->save();

        // Attach to order and subscriptions
        WC()->payment_gateways()->get_available_payment_gateways()[$this->id]->add_payment_token($token->get_id(), $order);
        
        if (function_exists('\wcs_order_contains_subscription') && \wcs_order_contains_subscription($order)) {
            $subscriptions = \wcs_get_subscriptions_for_order($order);
            foreach ($subscriptions as $subscription) {
                $subscription->add_payment_token($token);
            }
        }
    }

    /**
     * Handle scheduled subscription payment
     * Supports multiple recurring methods:
     * 1. Payment tokens (customerId/mandateId from Pay.nl)
     * 2. SEPA Direct Debit mandate via stored IBAN
     * 3. Pay.nl order reference for repeat charges
     */
    public function scheduled_subscription_payment($amount_to_charge, $renewal_order) {
        try {
            log_info('Processing scheduled subscription payment', [
                'order_id' => $renewal_order->get_id(),
                'amount' => $amount_to_charge
            ]);

            // Find the subscription
            $subscription = null;
            $subscription_id = $renewal_order->get_meta('_subscription_renewal');
            if ($subscription_id) {
                $subscription = \wcs_get_subscription($subscription_id);
            }

            if (!$subscription) {
                // Try alternative method to find subscription
                $subscriptions = \wcs_get_subscriptions_for_renewal_order($renewal_order);
                if (!empty($subscriptions)) {
                    $subscription = reset($subscriptions);
                }
            }

            if (!$subscription) {
                throw new \Exception(__('Abonnement niet gevonden.', 'pay-subs'));
            }

            log_info('Found subscription for renewal', [
                'subscription_id' => $subscription->get_id(),
                'renewal_order_id' => $renewal_order->get_id()
            ]);

            $client = new Pay_Client($this->token_code, $this->api_token, $this->service_id, $this->testmode);

            // Method 1: Try payment tokens (customerId/mandateId)
            $tokens = $subscription->get_payment_tokens();
            if (!empty($tokens)) {
                $token_id = reset($tokens);
                $token = \WC_Payment_Tokens::get($token_id);

                if ($token) {
                    $recurring_id = $token->get_meta('mandate_id') ?: $token->get_token();

                    log_info('Attempting renewal with payment token', [
                        'token_id' => $token_id,
                        'recurring_id' => substr($recurring_id, 0, 15) . '...',
                    ]);

                    $result = $client->charge_with_token([
                        'amount' => cents($amount_to_charge),
                        'description' => sprintf('Abonnement verlenging order %s', $renewal_order->get_order_number()),
                        'reference' => (string) $renewal_order->get_id(),
                        'mandate_id' => $recurring_id,
                        'token' => $recurring_id,
                        'webhook_url' => get_webhook_url(),
                    ]);

                    if ($result['paid']) {
                        $renewal_order->payment_complete($result['transaction_id'] ?? '');
                        log_info('Subscription payment successful via token', [
                            'order_id' => $renewal_order->get_id(),
                            'transaction_id' => $result['transaction_id'] ?? null
                        ]);
                        return;
                    }

                    log_warning('Token-based renewal failed, trying alternative methods', [
                        'error' => $result['error'] ?? 'unknown'
                    ]);
                }
            }

            // Method 2: Try SEPA Direct Debit with stored IBAN
            $iban = $subscription->get_meta('_pay_customer_iban');
            if (!empty($iban)) {
                $iban_name = $subscription->get_meta('_pay_customer_iban_name') ?: $renewal_order->get_billing_first_name() . ' ' . $renewal_order->get_billing_last_name();
                $iban_bic = $subscription->get_meta('_pay_customer_iban_bic') ?: '';

                log_info('Attempting renewal via SEPA mandate with IBAN', [
                    'iban' => substr($iban, 0, 8) . '...',
                    'name' => $iban_name,
                ]);

                // First check if we already have a mandate
                $mandate_id = $subscription->get_meta('_pay_mandate_id');

                if (empty($mandate_id)) {
                    // Create a new mandate
                    log_info('Creating new SEPA mandate for subscription');

                    try {
                        $mandate_result = $client->create_mandate([
                            'amount' => cents($amount_to_charge),
                            'description' => sprintf('Machtiging abonnement #%s - %s', $subscription->get_id(), get_bloginfo('name')),
                            'account_holder' => $iban_name,
                            'iban' => $iban,
                            'bic' => $iban_bic,
                            'email' => $renewal_order->get_billing_email() ?: $subscription->get_billing_email(),
                            'webhook_url' => get_webhook_url(),
                            'interval_value' => 1,
                            'interval_period' => 'month',
                        ]);

                        $mandate_id = $mandate_result['mandate_id'] ?? null;

                        if (!empty($mandate_id)) {
                            $subscription->update_meta_data('_pay_mandate_id', $mandate_id);
                            $subscription->save();

                            log_info('SEPA mandate created', [
                                'mandate_id' => $mandate_id,
                                'subscription_id' => $subscription->get_id()
                            ]);
                        }
                    } catch (\Exception $e) {
                        log_error('Mandate creation failed', ['error' => $e->getMessage()]);
                    }
                }

                if (!empty($mandate_id)) {
                    // Create direct debit charge against the mandate
                    $result = $client->create_direct_debit([
                        'mandate_id' => $mandate_id,
                        'amount' => cents($amount_to_charge),
                        'description' => sprintf('Abonnement verlenging order %s', $renewal_order->get_order_number()),
                        'webhook_url' => get_webhook_url(),
                    ]);

                    if ($result['paid']) {
                        $renewal_order->payment_complete($result['transaction_id'] ?? '');
                        log_info('Subscription payment successful via SEPA mandate', [
                            'order_id' => $renewal_order->get_id(),
                            'mandate_id' => $mandate_id,
                            'transaction_id' => $result['transaction_id'] ?? null
                        ]);
                        return;
                    }

                    log_warning('SEPA mandate charge failed', ['error' => $result['error'] ?? 'unknown']);
                }
            }

            // Method 3: Try creating a new order via Pay.nl API (merchant-initiated)
            $initial_pay_order = $subscription->get_meta('_pay_initial_order_id');
            if (!empty($initial_pay_order)) {
                log_info('Attempting renewal via new Pay.nl order (merchant-initiated)', [
                    'initial_order' => $initial_pay_order
                ]);

                try {
                    $result = $client->charge_with_token([
                        'amount' => cents($amount_to_charge),
                        'description' => sprintf('Abonnement verlenging order %s', $renewal_order->get_order_number()),
                        'reference' => (string) $renewal_order->get_id(),
                        'mandate_id' => $initial_pay_order,
                        'token' => $initial_pay_order,
                        'webhook_url' => get_webhook_url(),
                    ]);

                    if ($result['paid']) {
                        $renewal_order->payment_complete($result['transaction_id'] ?? '');
                        log_info('Subscription payment successful via order reference', [
                            'order_id' => $renewal_order->get_id(),
                            'transaction_id' => $result['transaction_id'] ?? null
                        ]);
                        return;
                    }
                } catch (\Exception $e) {
                    log_error('Order reference renewal failed', ['error' => $e->getMessage()]);
                }
            }

            // No method worked
            throw new \Exception(__('Geen werkende betaalmethode gevonden voor abonnement. Controleer of automatische incasso is ingeschakeld bij Pay.nl.', 'pay-subs'));

        } catch (\Exception $e) {
            log_error('Subscription payment failed', [
                'order_id' => $renewal_order->get_id(),
                'error' => $e->getMessage()
            ]);

            $renewal_order->update_status('failed', sprintf(
                __('Automatische betaling mislukt: %s', 'pay-subs'),
                $e->getMessage()
            ));
        }
    }

    /**
     * Process refund
     */
    public function process_refund($order_id, $amount = null, $reason = '') {
        try {
            $order = wc_get_order($order_id);
            
            if (!$order) {
                return new \WP_Error('invalid_order', __('Order niet gevonden.', 'pay-subs'));
            }

            $transaction_id = $order->get_transaction_id();
            
            if (empty($transaction_id)) {
                return new \WP_Error('no_transaction', __('Geen transactie ID gevonden.', 'pay-subs'));
            }

            $client = new Pay_Client($this->token_code, $this->api_token, $this->service_id, $this->testmode);
            
            $result = $client->refund([
                'transaction_id' => $transaction_id,
                'amount' => cents($amount),
                'description' => $reason
            ]);

            if ($result['refunded']) {
                $order->add_order_note(sprintf(
                    __('Terugbetaling van €%s verwerkt via PAY.', 'pay-subs'),
                    $amount
                ));
                
                return true;
            }

            return new \WP_Error('refund_failed', __('Terugbetaling mislukt.', 'pay-subs'));
            
        } catch (\Exception $e) {
            log_error('Refund failed', [
                'order_id' => $order_id,
                'error' => $e->getMessage()
            ]);
            
            return new \WP_Error('refund_error', $e->getMessage());
        }
    }

    /**
     * Update failing payment method
     */
    public function update_failing_payment_method($subscription, $renewal_order) {
        if ($this->debug) {
            log_info('Updating failing payment method', [
                'subscription_id' => $subscription->get_id(),
                'order_id' => $renewal_order->get_id()
            ]);
        }
        
        // This is handled by the normal payment flow
    }

    /**
     * Admin notices
     */
    public function admin_options() {
        if (empty($this->token_code) || empty($this->api_token) || empty($this->service_id)) {
            echo '<div class="notice notice-warning"><p>';
            echo __('PAY gateway heeft een Token Code (AT-xxxx-xxxx), API Token en Service ID (SL-xxxx-xxxx) nodig om te functioneren.', 'pay-subs');
            echo '</p></div>';
        }
        
        if ($this->subscription_only) {
            echo '<div class="notice notice-info"><p>';
            echo __('Let op: De gateway is ingesteld om alleen te verschijnen bij abonnementen.', 'pay-subs');
            echo '</p></div>';
        }
        
        parent::admin_options();
    }
}
