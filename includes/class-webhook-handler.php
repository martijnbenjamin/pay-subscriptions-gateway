<?php
namespace PAY_Subscriptions;

if ( ! defined( 'ABSPATH' ) ) { exit; }

// EMERGENCY WEBHOOK LOGGING - Dit logt ALTIJD, zelfs als WordPress/plugin crashed
$emergency_log = '/home/dutchvitals/logs/webhook-emergency.log';
@file_put_contents(
    $emergency_log,
    "\n" . str_repeat('=', 80) . "\n" .
    date('Y-m-d H:i:s') . " - WEBHOOK FILE LOADED - VERSION 1.6.0\n" .
    str_repeat('=', 80) . "\n" .
    "REQUEST_METHOD: " . ($_SERVER['REQUEST_METHOD'] ?? 'unknown') . "\n" .
    "REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'unknown') . "\n" .
    "QUERY_STRING: " . ($_SERVER['QUERY_STRING'] ?? 'empty') . "\n" .
    "\nGET params:\n" . print_r($_GET, true) . "\n" .
    "\nPOST params:\n" . print_r($_POST, true) . "\n" .
    "\nRELEVANT SERVER vars:\n" .
    "REMOTE_ADDR: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n" .
    "HTTP_USER_AGENT: " . ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown') . "\n" .
    "\n",
    FILE_APPEND
);

/**
 * Webhook Handler for PAY.nl notifications
 * Handles payment status updates from PAY.nl
 */
class Webhook_Handler {
    
    /**
     * Handle incoming webhook
     */
    public static function handle() : void {
        // Emergency logging - stap 1
        @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log', 
            date('Y-m-d H:i:s') . " - handle() method called\n", FILE_APPEND);
        
        // EERSTE: Geef meteen 200 OK terug zodat Pay.nl weet dat we de webhook hebben ontvangen
        // Dan kunnen we in rust de webhook verwerken zonder timeout
        http_response_code(200);
        echo 'TRUE';
        
        // Emergency logging - stap 2
        @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log', 
            date('Y-m-d H:i:s') . " - 200 OK sent\n", FILE_APPEND);
        
        // Flush output zodat Pay.nl meteen response krijgt
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        } else {
            // Forceer output buffer flush
            @ob_end_flush();
            @flush();
        }

        // Emergency logging - stap 3
        @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log', 
            date('Y-m-d H:i:s') . " - Output flushed\n", FILE_APPEND);

        try {
            // Pay.nl stuurt webhooks via GET/POST parameters, NIET via php://input!
            $data = array_merge($_GET, $_POST);
            
            // Emergency logging - stap 4
            @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log', 
                date('Y-m-d H:i:s') . " - Data merged: " . json_encode($data) . "\n", FILE_APPEND);
            
            // Log webhook voor debugging (kan crashen als log_info niet bestaat)
            if (function_exists('PAY_Subscriptions\\log_info')) {
                log_info('Webhook received', [
                    'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
                    'get_params' => array_keys($_GET),
                    'post_params' => array_keys($_POST),
                    'action' => $data['action'] ?? null,
                    'order_id' => $data['order_id'] ?? null
                ]);
            }
            
            // Emergency logging - stap 5
            @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log', 
                date('Y-m-d H:i:s') . " - About to check data empty\n", FILE_APPEND);

            if (empty($data)) {
                if (function_exists('PAY_Subscriptions\\log_error')) {
                    log_error('No webhook data received');
                }
                return;
            }

            // Emergency logging - stap 6
            @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log', 
                date('Y-m-d H:i:s') . " - About to get settings\n", FILE_APPEND);

            // Get settings
            $settings = get_option('woocommerce_pay_subscriptions_settings', []);

            // Emergency logging - stap 7
            @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log', 
                date('Y-m-d H:i:s') . " - Settings loaded, creating client\n", FILE_APPEND);

            // Create client for API calls
            $client = new Pay_Client(
                $settings['token_code'] ?? '',
                $settings['api_token'] ?? '',
                $settings['service_id'] ?? '',
                ('yes' === ($settings['testmode'] ?? 'yes'))
            );

            // Emergency logging - stap 8
            @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log', 
                date('Y-m-d H:i:s') . " - Client created, calling process_webhook\n", FILE_APPEND);

            // Process de webhook
            self::process_webhook($data, $client);
            
            // Emergency logging - stap 9
            @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log', 
                date('Y-m-d H:i:s') . " - process_webhook completed successfully\n", FILE_APPEND);
            
        } catch (\Exception $e) {
            // Emergency logging - ERROR
            @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log', 
                date('Y-m-d H:i:s') . " - EXCEPTION: " . $e->getMessage() . "\n" .
                "Trace: " . $e->getTraceAsString() . "\n", FILE_APPEND);
                
            if (function_exists('PAY_Subscriptions\\log_error')) {
                log_error('Webhook processing error', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }
    }

    /**
     * Process webhook data
     * Pay.nl stuurt webhooks met action parameter: pending, new_ppt, paid, cancelled, etc.
     * OF via het nieuwe v1 Order API formaat met object[status][action]
     */
    private static function process_webhook(array $data, Pay_Client $client) : void {
        // Emergency logging - process_webhook start met ALLE data
        @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
            date('Y-m-d H:i:s') . " - process_webhook START\n" .
            "Data keys: " . implode(', ', array_keys($data)) . "\n" .
            "Has 'object' key: " . (isset($data['object']) ? 'YES' : 'NO') . "\n" .
            "Has 'id' key: " . (isset($data['id']) ? 'YES (' . $data['id'] . ')' : 'NO') . "\n", FILE_APPEND);

        // Pay.nl v1 Order API stuurt data in object[] array
        // Check voor dit nieuwe formaat EERST
        if (isset($data['object']) && is_array($data['object'])) {
            $action = strtoupper($data['object']['status']['action'] ?? '');
            $order_id = $data['object']['id'] ?? $data['id'] ?? '';
            $reference = $data['object']['reference'] ?? '';

            @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
                date('Y-m-d H:i:s') . " - v1 Order API format detected (nested array)\n" .
                "Action: {$action}, Order ID: {$order_id}, Reference: {$reference}\n", FILE_APPEND);

            // Zoek WooCommerce order via reference (= WC order ID)
            if (!empty($reference)) {
                $wc_order_id = absint($reference);
                $order = wc_get_order($wc_order_id);

                if ($order) {
                    @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
                        date('Y-m-d H:i:s') . " - Found WC order #{$wc_order_id} via reference\n", FILE_APPEND);

                    // Update transaction ID if not set
                    if (empty($order->get_transaction_id())) {
                        $order->set_transaction_id($order_id);
                        $order->save();
                    }

                    // Process based on action
                    switch ($action) {
                        case 'PAID':
                        case 'AUTHORIZE':
                        case 'APPROVED':
                            if (!$order->is_paid()) {
                                @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
                                    date('Y-m-d H:i:s') . " - Marking order as PAID\n", FILE_APPEND);
                                $order->payment_complete($order_id);

                                // Activate subscriptions
                                if (function_exists('\wcs_order_contains_subscription') && \wcs_order_contains_subscription($order)) {
                                    $subscriptions = \wcs_get_subscriptions_for_order($order);
                                    foreach ($subscriptions as $subscription) {
                                        if ($subscription->get_status() === 'pending') {
                                            $subscription->update_status('active');
                                            @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
                                                date('Y-m-d H:i:s') . " - Subscription #{$subscription->get_id()} activated\n", FILE_APPEND);
                                        }
                                    }
                                }

                                // Check for customerId for recurring payments
                                $customer_id = $data['object']['payments'][0]['customerId'] ?? null;
                                if (!empty($customer_id)) {
                                    @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
                                        date('Y-m-d H:i:s') . " - Found customerId for recurring: " . substr($customer_id, 0, 10) . "...\n", FILE_APPEND);
                                    self::save_recurring_token($order, $customer_id, $data['object']);
                                }
                            } else {
                                @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
                                    date('Y-m-d H:i:s') . " - Order already paid, skipping\n", FILE_APPEND);
                            }
                            break;

                        case 'PENDING':
                            $order->add_order_note(__('PAY betaling is in afwachting', 'pay-subs'));
                            break;

                        case 'CANCEL':
                        case 'CANCELLED':
                            if (!$order->is_paid()) {
                                $order->update_status('cancelled', __('Betaling geannuleerd via PAY', 'pay-subs'));
                            }
                            break;

                        case 'EXPIRED':
                            if (!$order->is_paid()) {
                                $order->update_status('cancelled', __('PAY betaling verlopen', 'pay-subs'));
                            }
                            break;

                        case 'DENIED':
                        case 'FAILED':
                            if (!$order->is_paid()) {
                                $order->update_status('failed', __('PAY betaling mislukt', 'pay-subs'));
                            }
                            break;
                    }

                    @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
                        date('Y-m-d H:i:s') . " - v1 Order API webhook processed successfully\n", FILE_APPEND);
                    return;
                } else {
                    @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
                        date('Y-m-d H:i:s') . " - WC order not found for reference: {$reference}\n", FILE_APPEND);
                }
            }
            return;
        }

        // Check voor v1 Order API met 'id' direct in root (als 'object' niet als array geparsed is)
        // Dit kan gebeuren als Pay.nl de data direct stuurt zonder object wrapper
        if (isset($data['id']) && !isset($data['action'])) {
            // Dit is waarschijnlijk v1 Order API formaat maar zonder object wrapper
            // Zoek naar status/action in de data
            $action = '';
            $order_id = $data['id'] ?? '';
            $reference = $data['reference'] ?? '';

            // Check voor status.action of status direct
            if (isset($data['status']) && is_array($data['status'])) {
                $action = strtoupper($data['status']['action'] ?? '');
            } elseif (isset($data['status']) && is_string($data['status'])) {
                $action = strtoupper($data['status']);
            }

            @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
                date('Y-m-d H:i:s') . " - v1 Order API format detected (flat structure)\n" .
                "Action: {$action}, Order ID: {$order_id}, Reference: {$reference}\n", FILE_APPEND);

            if (!empty($action) && !empty($reference)) {
                $wc_order_id = absint($reference);
                $order = wc_get_order($wc_order_id);

                if ($order) {
                    @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
                        date('Y-m-d H:i:s') . " - Found WC order #{$wc_order_id} via reference (flat)\n", FILE_APPEND);

                    // Update transaction ID if not set
                    if (empty($order->get_transaction_id())) {
                        $order->set_transaction_id($order_id);
                        $order->save();
                    }

                    // Process based on action
                    switch ($action) {
                        case 'PAID':
                        case 'AUTHORIZE':
                        case 'APPROVED':
                            if (!$order->is_paid()) {
                                @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
                                    date('Y-m-d H:i:s') . " - Marking order as PAID (flat)\n", FILE_APPEND);
                                $order->payment_complete($order_id);

                                // Activate subscriptions
                                if (function_exists('\wcs_order_contains_subscription') && \wcs_order_contains_subscription($order)) {
                                    $subscriptions = \wcs_get_subscriptions_for_order($order);
                                    foreach ($subscriptions as $subscription) {
                                        if ($subscription->get_status() === 'pending') {
                                            $subscription->update_status('active');
                                            @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
                                                date('Y-m-d H:i:s') . " - Subscription #{$subscription->get_id()} activated (flat)\n", FILE_APPEND);
                                        }
                                    }
                                }
                            }
                            break;

                        case 'PENDING':
                            $order->add_order_note(__('PAY betaling is in afwachting', 'pay-subs'));
                            break;

                        case 'CANCEL':
                        case 'CANCELLED':
                            if (!$order->is_paid()) {
                                $order->update_status('cancelled', __('Betaling geannuleerd via PAY', 'pay-subs'));
                            }
                            break;

                        case 'EXPIRED':
                            if (!$order->is_paid()) {
                                $order->update_status('cancelled', __('PAY betaling verlopen', 'pay-subs'));
                            }
                            break;

                        case 'DENIED':
                        case 'FAILED':
                            if (!$order->is_paid()) {
                                $order->update_status('failed', __('PAY betaling mislukt', 'pay-subs'));
                            }
                            break;
                    }

                    @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
                        date('Y-m-d H:i:s') . " - v1 Order API webhook processed successfully (flat)\n", FILE_APPEND);
                    return;
                }
            }
        }

        // Fallback: oude formaat met directe action parameter
        $action = $data['action'] ?? '';
        $order_id = $data['order_id'] ?? '';

        // Emergency logging - action detected
        @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
            date('Y-m-d H:i:s') . " - Legacy format - Action: {$action}, Order ID: {$order_id}\n", FILE_APPEND);
        
        if (function_exists('PAY_Subscriptions\\log_info')) {
            log_info('Processing webhook', [
                'action' => $action,
                'order_id' => $order_id,
                'all_params' => array_keys($data)
            ]);
        }

        // Handle verschillende Pay.nl webhook actions
        switch ($action) {
            case 'new_ppt':
                // Emergency logging
                @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log', 
                    date('Y-m-d H:i:s') . " - Calling handle_tokenization_webhook\n", FILE_APPEND);
                // NEW PAYMENT PROFILE TOKEN - dit is wat we nodig hebben!
                self::handle_tokenization_webhook($data);
                break;
                
            case 'paid':
            case 'authorize':
                // Emergency logging
                @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log', 
                    date('Y-m-d H:i:s') . " - Calling handle_paid_webhook\n", FILE_APPEND);
                self::handle_paid_webhook($data, $client);
                break;
                
            case 'pending':
                // Emergency logging
                @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log', 
                    date('Y-m-d H:i:s') . " - Calling handle_pending_webhook\n", FILE_APPEND);
                self::handle_pending_webhook($data);
                break;
                
            case 'cancel':
            case 'cancelled':
                // Emergency logging
                @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log', 
                    date('Y-m-d H:i:s') . " - Calling handle_cancelled_webhook\n", FILE_APPEND);
                self::handle_cancelled_webhook($data);
                break;
                
            case 'expired':
                // Emergency logging
                @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log', 
                    date('Y-m-d H:i:s') . " - Calling handle_expired_webhook\n", FILE_APPEND);
                self::handle_expired_webhook($data);
                break;
                
            case 'denied':
            case 'failed':
                // Emergency logging
                @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log', 
                    date('Y-m-d H:i:s') . " - Calling handle_failed_webhook\n", FILE_APPEND);
                self::handle_failed_webhook($data);
                break;
                
            default:
                // Emergency logging
                @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log', 
                    date('Y-m-d H:i:s') . " - Unknown action: {$action}\n", FILE_APPEND);
                if (function_exists('PAY_Subscriptions\\log_warning')) {
                    log_warning('Unknown webhook action', [
                        'action' => $action,
                        'all_params' => $data
                    ]);
                }
                break;
        }
    }

    /**
     * Handle tokenization webhook (new_ppt)
     * Dit is de webhook die we krijgen als Pay.nl een payment token heeft aangemaakt
     */
    private static function handle_tokenization_webhook(array $data) : void {
        // Emergency logging - tokenization handler start
        @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
            date('Y-m-d H:i:s') . " - handle_tokenization_webhook START\n" .
            "Data: " . json_encode($data) . "\n", FILE_APPEND);

        $pay_order_id = $data['order_id'] ?? '';

        if (function_exists('PAY_Subscriptions\\log_info')) {
            log_info('TOKENIZATION WEBHOOK RECEIVED!', [
                'order_id' => $pay_order_id,
                'all_data' => $data
            ]);
        }

        if (empty($pay_order_id)) {
            @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
                date('Y-m-d H:i:s') . " - ERROR: No order_id in tokenization webhook\n", FILE_APPEND);
            if (function_exists('PAY_Subscriptions\\log_error')) {
                log_error('No order_id in tokenization webhook');
            }
            return;
        }

        // Find WooCommerce order - try multiple methods
        $order = null;

        // Method 1: Find by transaction_id (Pay.nl order ID)
        $orders = wc_get_orders([
            'transaction_id' => $pay_order_id,
            'limit' => 1
        ]);

        if (!empty($orders)) {
            $order = reset($orders);
            @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
                date('Y-m-d H:i:s') . " - Tokenization: Found order via transaction_id: WC#{$order->get_id()}\n", FILE_APPEND);
        }

        // Method 2: Check if extra1 contains WC order ID
        if (!$order && !empty($data['extra1'])) {
            $wc_order_id = absint($data['extra1']);
            if ($wc_order_id > 0) {
                $order = wc_get_order($wc_order_id);
                if ($order) {
                    @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
                        date('Y-m-d H:i:s') . " - Tokenization: Found order via extra1: WC#{$wc_order_id}\n", FILE_APPEND);
                }
            }
        }

        if (!$order) {
            @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
                date('Y-m-d H:i:s') . " - ERROR: WC Order not found for tokenization\n", FILE_APPEND);
            log_error('WC Order not found for tokenization', ['order_id' => $pay_order_id]);
            return;
        }

        // Sla ALLE webhook data op in order meta voor debugging
        $order->update_meta_data('_pay_tokenization_webhook', $data);
        $order->save();

        $order->add_order_note(
            sprintf(
                __('PAY tokenization webhook ontvangen (action: %s)', 'pay-subs'),
                $data['action']
            )
        );

        @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
            date('Y-m-d H:i:s') . " - Tokenization webhook data saved to WC#{$order->get_id()}\n", FILE_APPEND);

        log_info('Tokenization webhook data saved to order', [
            'order_id' => $order->get_id(),
            'pay_order_id' => $pay_order_id
        ]);
    }

    /**
     * Handle paid webhook
     */
    private static function handle_paid_webhook(array $data, Pay_Client $client) : void {
        $pay_order_id = $data['order_id'] ?? '';

        // Emergency logging
        @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
            date('Y-m-d H:i:s') . " - handle_paid_webhook START\n" .
            "Pay order_id: {$pay_order_id}\n" .
            "Full data: " . json_encode($data) . "\n", FILE_APPEND);

        if (empty($pay_order_id)) {
            @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
                date('Y-m-d H:i:s') . " - ERROR: No order_id in paid webhook\n", FILE_APPEND);
            log_error('No order_id in paid webhook');
            return;
        }

        // Find WooCommerce order - try multiple methods
        $order = null;

        // Method 1: Find by transaction_id (Pay.nl order ID)
        $orders = wc_get_orders([
            'transaction_id' => $pay_order_id,
            'limit' => 1
        ]);

        if (!empty($orders)) {
            $order = reset($orders);
            @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
                date('Y-m-d H:i:s') . " - Found order via transaction_id: WC#{$order->get_id()}\n", FILE_APPEND);
        }

        // Method 2: Check if extra1 contains WC order ID (used as reference)
        if (!$order && !empty($data['extra1'])) {
            $wc_order_id = absint($data['extra1']);
            if ($wc_order_id > 0) {
                $order = wc_get_order($wc_order_id);
                if ($order) {
                    @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
                        date('Y-m-d H:i:s') . " - Found order via extra1: WC#{$wc_order_id}\n", FILE_APPEND);
                    // Update transaction ID for future reference
                    $order->set_transaction_id($pay_order_id);
                    $order->save();
                }
            }
        }

        // Method 3: Query Pay.nl API to get the reference (WC order ID)
        if (!$order) {
            @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
                date('Y-m-d H:i:s') . " - Trying to fetch order details from Pay.nl API\n", FILE_APPEND);
            try {
                $transaction_details = $client->get_transaction_status($pay_order_id);
                @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
                    date('Y-m-d H:i:s') . " - API response: " . json_encode($transaction_details) . "\n", FILE_APPEND);

                // The reference should be the WC order ID (we set this in process_payment)
                $reference = $transaction_details['transaction']['reference'] ??
                            $transaction_details['reference'] ?? null;

                if ($reference) {
                    // Reference is the WC order ID
                    $wc_order_id = absint($reference);
                    if ($wc_order_id > 0) {
                        $order = wc_get_order($wc_order_id);
                        if ($order) {
                            @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
                                date('Y-m-d H:i:s') . " - Found order via API reference: WC#{$wc_order_id}\n", FILE_APPEND);
                            // Update transaction ID for future reference
                            $order->set_transaction_id($pay_order_id);
                            $order->save();
                        }
                    }
                }

                // Also try to extract recurring_id/customerId for subscriptions
                if ($order) {
                    $recurring_id = $transaction_details['recurring_id']
                        ?? $transaction_details['customer_id']
                        ?? $transaction_details['customerId']
                        ?? $transaction_details['payments'][0]['customerId'] ?? null;

                    if ($recurring_id) {
                        @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
                            date('Y-m-d H:i:s') . " - Found recurring_id: " . substr($recurring_id, 0, 15) . "...\n", FILE_APPEND);
                        self::save_recurring_token($order, $recurring_id, $transaction_details);
                    }
                }

            } catch (\Exception $e) {
                @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
                    date('Y-m-d H:i:s') . " - API call failed: " . $e->getMessage() . "\n", FILE_APPEND);
            }
        }

        if (!$order) {
            @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
                date('Y-m-d H:i:s') . " - ERROR: WC Order not found for paid webhook\n", FILE_APPEND);
            log_error('WC Order not found for paid webhook', ['order_id' => $pay_order_id]);
            return;
        }

        // Check if already processed
        if ($order->is_paid()) {
            @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
                date('Y-m-d H:i:s') . " - Order already paid, skipping\n", FILE_APPEND);
            log_info('Order already paid', ['order_id' => $order->get_id()]);
            return;
        }

        // Complete payment
        @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
            date('Y-m-d H:i:s') . " - Calling payment_complete for WC#{$order->get_id()}\n", FILE_APPEND);
        $order->payment_complete($pay_order_id);

        @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
            date('Y-m-d H:i:s') . " - Order marked as paid: WC#{$order->get_id()}\n", FILE_APPEND);

        log_info('Order marked as paid', [
            'wc_order_id' => $order->get_id(),
            'pay_order_id' => $pay_order_id
        ]);

        // Handle subscription activation
        if (function_exists('\wcs_order_contains_subscription') && \wcs_order_contains_subscription($order)) {
            @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
                date('Y-m-d H:i:s') . " - Order contains subscription, activating...\n", FILE_APPEND);
            $subscriptions = \wcs_get_subscriptions_for_order($order);
            foreach ($subscriptions as $subscription) {
                if ($subscription->get_status() === 'pending') {
                    $subscription->update_status('active');
                    @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
                        date('Y-m-d H:i:s') . " - Subscription #{$subscription->get_id()} activated\n", FILE_APPEND);
                    log_info('Subscription activated', ['subscription_id' => $subscription->get_id()]);
                }
            }
        }

        @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
            date('Y-m-d H:i:s') . " - handle_paid_webhook COMPLETED\n", FILE_APPEND);
    }

    /**
     * Handle pending webhook
     */
    private static function handle_pending_webhook(array $data) : void {
        $pay_order_id = $data['order_id'] ?? '';

        @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
            date('Y-m-d H:i:s') . " - handle_pending_webhook: Pay order_id={$pay_order_id}\n", FILE_APPEND);

        if (empty($pay_order_id)) {
            return;
        }

        $order = self::find_wc_order_by_pay_id($pay_order_id, $data);

        if ($order) {
            $order->add_order_note(__('PAY betaling is in afwachting', 'pay-subs'));
            @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
                date('Y-m-d H:i:s') . " - Pending note added to WC#{$order->get_id()}\n", FILE_APPEND);
            log_info('Order pending webhook received', ['order_id' => $order->get_id()]);
        } else {
            @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
                date('Y-m-d H:i:s') . " - Pending: WC Order not found\n", FILE_APPEND);
        }
    }

    /**
     * Helper: Find WooCommerce order by Pay.nl order ID
     * Tries multiple methods to find the order
     */
    private static function find_wc_order_by_pay_id(string $pay_order_id, array $data = []) : ?\WC_Order {
        // Method 1: Find by transaction_id
        $orders = wc_get_orders([
            'transaction_id' => $pay_order_id,
            'limit' => 1
        ]);

        if (!empty($orders)) {
            return reset($orders);
        }

        // Method 2: Check if extra1 contains WC order ID
        if (!empty($data['extra1'])) {
            $wc_order_id = absint($data['extra1']);
            if ($wc_order_id > 0) {
                $order = wc_get_order($wc_order_id);
                if ($order) {
                    // Update transaction ID for future reference
                    $order->set_transaction_id($pay_order_id);
                    $order->save();
                    return $order;
                }
            }
        }

        return null;
    }

    /**
     * Handle cancelled webhook
     */
    private static function handle_cancelled_webhook(array $data) : void {
        $pay_order_id = $data['order_id'] ?? '';

        @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
            date('Y-m-d H:i:s') . " - handle_cancelled_webhook: Pay order_id={$pay_order_id}\n", FILE_APPEND);

        if (empty($pay_order_id)) {
            return;
        }

        $order = self::find_wc_order_by_pay_id($pay_order_id, $data);

        if ($order) {
            $order->update_status('cancelled', __('Betaling geannuleerd via PAY', 'pay-subs'));
            @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
                date('Y-m-d H:i:s') . " - Order WC#{$order->get_id()} cancelled\n", FILE_APPEND);
            log_info('Order cancelled via webhook', ['order_id' => $order->get_id()]);
        } else {
            @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
                date('Y-m-d H:i:s') . " - Cancelled: WC Order not found\n", FILE_APPEND);
        }
    }

    /**
     * Handle expired webhook
     */
    private static function handle_expired_webhook(array $data) : void {
        $pay_order_id = $data['order_id'] ?? '';

        @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
            date('Y-m-d H:i:s') . " - handle_expired_webhook: Pay order_id={$pay_order_id}\n", FILE_APPEND);

        if (empty($pay_order_id)) {
            return;
        }

        $order = self::find_wc_order_by_pay_id($pay_order_id, $data);

        if ($order) {
            $order->update_status('cancelled', __('PAY betaling verlopen', 'pay-subs'));
            @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
                date('Y-m-d H:i:s') . " - Order WC#{$order->get_id()} expired\n", FILE_APPEND);
            log_info('Order expired via webhook', ['order_id' => $order->get_id()]);
        } else {
            @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
                date('Y-m-d H:i:s') . " - Expired: WC Order not found\n", FILE_APPEND);
        }
    }

    /**
     * Handle failed webhook
     */
    private static function handle_failed_webhook(array $data) : void {
        $pay_order_id = $data['order_id'] ?? '';

        @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
            date('Y-m-d H:i:s') . " - handle_failed_webhook: Pay order_id={$pay_order_id}\n", FILE_APPEND);

        if (empty($pay_order_id)) {
            return;
        }

        $order = self::find_wc_order_by_pay_id($pay_order_id, $data);

        if ($order) {
            $order->update_status('failed', __('PAY betaling mislukt', 'pay-subs'));
            @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
                date('Y-m-d H:i:s') . " - Order WC#{$order->get_id()} failed\n", FILE_APPEND);
            log_info('Order failed via webhook', ['order_id' => $order->get_id()]);
        } else {
            @file_put_contents('/home/dutchvitals/logs/webhook-emergency.log',
                date('Y-m-d H:i:s') . " - Failed: WC Order not found\n", FILE_APPEND);
        }
    }

    /**
     * Handle new v1 Order API exchange format
     */
    private static function handle_order_exchange(array $data, Pay_Client $client) : void {
        $order_id = $data['id'] ?? null;
        $status = $data['status']['action'] ?? $data['status'] ?? null;
        $reference = $data['reference'] ?? '';

        log_info('Processing order exchange', [
            'order_id' => $order_id,
            'status' => $status,
            'reference' => $reference
        ]);

        if (empty($reference)) {
            log_error('No reference in order exchange');
            self::send_response(200, 'OK (no reference)');
            return;
        }

        // Reference is nu de WooCommerce order ID (numeriek)
        // Probeer eerst direct als order ID, daarna als order key (voor backwards compatibility)
        $wc_order_id = absint($reference);
        $order = wc_get_order($wc_order_id);

        // Fallback: probeer als order key (voor oudere transacties)
        if (!$order) {
            $wc_order_id = wc_get_order_id_by_order_key($reference);
            if ($wc_order_id) {
                $order = wc_get_order($wc_order_id);
            }
        }

        if (!$order) {
            log_warning('WC Order not found for reference', ['reference' => $reference]);
            self::send_response(200, 'OK (order not found)');
            return;
        }

        // Process based on status
        switch (strtoupper($status)) {
            case 'PAID':
            case 'AUTHORIZE':
            case 'APPROVED':
                // Haal volledige transactie details op via API om customerId/recurring_id te krijgen
                // De webhook data bevat niet altijd alle details
                try {
                    $full_details = $client->get_transaction_status($order_id);
                    log_debug('Full transaction details retrieved', [
                        'pay_order_id' => $order_id,
                        'recurring_id' => $full_details['recurring_id'] ?? null
                    ]);
                    // Merge API response met webhook data
                    $transaction_data = array_merge($data, $full_details, $full_details['transaction'] ?? []);
                } catch (\Exception $e) {
                    log_warning('Could not fetch full transaction details', [
                        'error' => $e->getMessage()
                    ]);
                    $transaction_data = $data;
                }
                self::handle_successful_payment($order, $order_id, $transaction_data);
                break;

            case 'CANCEL':
            case 'CANCELLED':
                $order->update_status('cancelled',
                    __('Betaling geannuleerd via PAY', 'pay-subs')
                );
                break;

            case 'EXPIRED':
                $order->update_status('cancelled',
                    __('PAY betaling verlopen', 'pay-subs')
                );
                break;

            case 'PENDING':
                $order->add_order_note(
                    __('PAY betaling is in afwachting', 'pay-subs')
                );
                break;

            case 'DENIED':
            case 'FAILED':
                $order->update_status('failed',
                    __('PAY betaling mislukt', 'pay-subs')
                );
                break;

            default:
                log_warning('Unknown order status', ['status' => $status]);
                break;
        }

        self::send_response(200, 'OK');
    }

    /**
     * Handle token exchange for recurring payments
     * Dit is de exchange die we ontvangen na een succesvolle betaling met tokenization
     */
    private static function handle_token_exchange(array $data) : void {
        $recurring_id = $data['recurring_id'] ?? null;
        $order_id = $data['orderId'] ?? $data['order_id'] ?? $data['id'] ?? null;
        $reference = $data['reference'] ?? '';

        log_info('Processing token exchange', [
            'recurring_id' => $recurring_id ? substr($recurring_id, 0, 10) . '...' : null,
            'order_id' => $order_id,
            'reference' => $reference
        ]);

        if (empty($recurring_id)) {
            log_error('No recurring_id in token exchange');
            self::send_response(200, 'OK (no recurring_id)');
            return;
        }

        // Find WooCommerce order
        // Reference is nu de WooCommerce order ID (numeriek)
        $wc_order_id = null;
        if (!empty($reference)) {
            // Probeer eerst als order ID (nieuw)
            $wc_order_id = absint($reference);
            $test_order = wc_get_order($wc_order_id);
            if (!$test_order) {
                // Fallback: probeer als order key (backwards compatibility)
                $wc_order_id = wc_get_order_id_by_order_key($reference);
            }
        }

        if (!$wc_order_id && !empty($order_id)) {
            $orders = wc_get_orders([
                'transaction_id' => $order_id,
                'limit' => 1
            ]);
            if (!empty($orders)) {
                $wc_order_id = reset($orders)->get_id();
            }
        }

        if (!$wc_order_id) {
            log_warning('WC Order not found for token exchange');
            self::send_response(200, 'OK (order not found)');
            return;
        }

        $order = wc_get_order($wc_order_id);
        if (!$order) {
            self::send_response(200, 'OK (invalid order)');
            return;
        }

        // Save recurring_id as token
        self::save_recurring_token($order, $recurring_id, $data);

        self::send_response(200, 'OK');
    }

    /**
     * Save recurring_id as WooCommerce payment token
     */
    private static function save_recurring_token($order, $recurring_id, $data) : void {
        $customer_id = $order->get_customer_id();

        if (!$customer_id) {
            log_warning('Cannot save token for guest order');
            return;
        }

        // Check if token already exists
        $existing_tokens = \WC_Payment_Tokens::get_customer_tokens($customer_id, 'pay_subscriptions');
        foreach ($existing_tokens as $token) {
            if ($token->get_token() === $recurring_id) {
                log_info('Token already saved', ['recurring_id' => substr($recurring_id, 0, 10) . '...']);
                return;
            }
        }

        // Create new token
        $token = new WC_Payment_Token_PAY();
        $token->set_user_id($customer_id);
        $token->set_token($recurring_id);
        $token->set_gateway_id('pay_subscriptions');

        // Add metadata
        $token->add_meta_data('recurring_id', $recurring_id);

        // Add customer ID (masked card) if available
        if (!empty($data['customer_id'])) {
            $token->add_meta_data('customer_id', $data['customer_id']);
            $token->add_meta_data('last4', substr($data['customer_id'], -4));
        }

        $token->save();

        // Link to order and subscriptions
        $order->add_payment_token($token);

        if (function_exists('\wcs_order_contains_subscription') && \wcs_order_contains_subscription($order)) {
            $subscriptions = \wcs_get_subscriptions_for_order($order);
            foreach ($subscriptions as $subscription) {
                $subscription->add_payment_token($token);
            }
        }

        log_info('Recurring token saved', [
            'recurring_id' => substr($recurring_id, 0, 10) . '...',
            'token_id' => $token->get_id()
        ]);
    }

    /**
     * Handle transaction status update
     */
    private static function handle_transaction_webhook(array $data, Pay_Client $client) : void {
        $transaction_id = $data['transaction']['id'] ?? null;
        
        if (empty($transaction_id)) {
            log_error('No transaction ID in webhook');
            self::send_response(400, 'No transaction ID');
            return;
        }

        // Get transaction details from API for security
        try {
            $transaction_details = $client->get_transaction_status($transaction_id);
        } catch (\Exception $e) {
            log_error('Failed to get transaction details', [
                'transaction_id' => $transaction_id,
                'error' => $e->getMessage()
            ]);
            self::send_response(500, 'Failed to verify transaction');
            return;
        }

        // Get order from reference
        $reference = $transaction_details['transaction']['reference'] ?? 
                    $data['transaction']['reference'] ?? '';
        
        if (empty($reference)) {
            log_error('No order reference in transaction');
            self::send_response(200, 'OK (no reference)');
            return;
        }

        // Find order by reference (order ID is now used as reference)
        // Probeer eerst als order ID (nieuw), daarna als order key (backwards compatibility)
        $order_id = absint($reference);
        $test_order = wc_get_order($order_id);
        if (!$test_order) {
            $order_id = wc_get_order_id_by_order_key($reference);
        }

        if (!$order_id) {
            // Try to find by transaction ID
            $orders = wc_get_orders([
                'transaction_id' => $transaction_id,
                'limit' => 1
            ]);
            
            if (!empty($orders)) {
                $order = reset($orders);
                $order_id = $order->get_id();
            }
        }

        if (!$order_id) {
            log_warning('Order not found for webhook', [
                'reference' => $reference,
                'transaction_id' => $transaction_id
            ]);
            self::send_response(200, 'OK (order not found)');
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            self::send_response(200, 'OK (invalid order)');
            return;
        }

        // Get transaction status
        $status = $transaction_details['transaction']['status'] ?? 
                 $data['transaction']['status'] ?? '';
        
        $status_code = $transaction_details['transaction']['statusCode'] ?? 
                      $data['transaction']['statusCode'] ?? 0;

        log_info('Processing transaction status', [
            'order_id' => $order_id,
            'transaction_id' => $transaction_id,
            'status' => $status,
            'status_code' => $status_code
        ]);

        // Process based on status
        switch (strtoupper($status)) {
            case 'PAID':
            case 'AUTHORIZE':
                self::handle_successful_payment($order, $transaction_id, $transaction_details);
                break;
                
            case 'CANCEL':
            case 'CANCELLED':
                $order->update_status('cancelled', 
                    __('Betaling geannuleerd door klant via PAY', 'pay-subs')
                );
                break;
                
            case 'EXPIRED':
                $order->update_status('cancelled', 
                    __('PAY betaling verlopen', 'pay-subs')
                );
                break;
                
            case 'PENDING':
                // Payment is still pending, don't change status
                $order->add_order_note(
                    __('PAY betaling is in afwachting', 'pay-subs')
                );
                break;
                
            case 'VERIFY':
                // Payment needs verification
                $order->update_status('on-hold', 
                    __('PAY betaling vereist verificatie', 'pay-subs')
                );
                break;
                
            case 'DENIED':
            case 'FAILED':
                $order->update_status('failed', 
                    __('PAY betaling mislukt', 'pay-subs')
                );
                break;
                
            case 'REFUND':
            case 'REFUNDED':
            case 'PARTIAL_REFUND':
                $order->add_order_note(
                    sprintf(__('PAY terugbetaling ontvangen (status: %s)', 'pay-subs'), $status)
                );
                break;
                
            case 'CHARGEBACK':
                $order->update_status('on-hold', 
                    __('PAY chargeback ontvangen - handmatige actie vereist', 'pay-subs')
                );
                break;
                
            default:
                log_warning('Unknown transaction status', [
                    'status' => $status,
                    'order_id' => $order_id
                ]);
                break;
        }

        self::send_response(200, 'OK');
    }

    /**
     * Handle successful payment
     */
    private static function handle_successful_payment($order, $transaction_id, $transaction_details) : void {
        // Check if already processed
        if ($order->is_paid()) {
            log_info('Order already paid', ['order_id' => $order->get_id()]);
            return;
        }

        // Complete payment
        $order->payment_complete($transaction_id);

        // Check for recurring_id/token in response (nieuwe v1 Order API)
        // Pay.nl slaat de customerId op die we kunnen gebruiken voor recurring betalingen
        $recurring_id = $transaction_details['recurring_id']
            ?? $transaction_details['customer_id']
            ?? $transaction_details['paymentDetails']['recurring_id']
            ?? $transaction_details['transaction']['recurring']['mandateId']
            ?? $transaction_details['transaction']['payments'][0]['customerId']
            ?? $transaction_details['payments'][0]['customerId']
            ?? $transaction_details['customerId']
            ?? null;

        log_debug('Checking for recurring_id in transaction', [
            'found_recurring_id' => $recurring_id ? substr($recurring_id, 0, 15) . '...' : null,
            'transaction_keys' => is_array($transaction_details) ? array_keys($transaction_details) : 'not array'
        ]);

        if ($recurring_id) {
            self::save_recurring_token($order, $recurring_id, $transaction_details);
        } else {
            log_warning('No recurring_id found in transaction details - subscription renewal may not work', [
                'order_id' => $order->get_id()
            ]);
        }

        // Handle subscription activation
        if (function_exists('\wcs_order_contains_subscription') && \wcs_order_contains_subscription($order)) {
            $subscriptions = \wcs_get_subscriptions_for_order($order);
            foreach ($subscriptions as $subscription) {
                if ($subscription->get_status() === 'pending') {
                    $subscription->update_status('active');
                }
            }
        }
    }

    /**
     * Save mandate as payment token
     */
    private static function save_mandate_as_token($order, $mandate_id, $transaction_details) : void {
        $customer_id = $order->get_customer_id();
        
        if (!$customer_id) {
            log_warning('Cannot save token for guest order');
            return;
        }

        // Check if token already exists
        $existing_tokens = \WC_Payment_Tokens::get_customer_tokens($customer_id, 'pay_subscriptions');
        foreach ($existing_tokens as $token) {
            if ($token->get_meta('mandate_id') === $mandate_id) {
                log_info('Mandate already saved as token', ['mandate_id' => $mandate_id]);
                return;
            }
        }

        // Create new token
        $token = new WC_Payment_Token_PAY();
        $token->set_user_id($customer_id);
        $token->set_token($mandate_id);
        $token->set_gateway_id('pay_subscriptions');
        
        // Add metadata
        $token->add_meta_data('mandate_id', $mandate_id);
        
        // Add payment method info if available
        $payment_profile = $transaction_details['transaction']['paymentProfile'] ?? [];
        if (!empty($payment_profile['number'])) {
            $token->add_meta_data('last4', substr($payment_profile['number'], -4));
        }
        if (!empty($payment_profile['name'])) {
            $token->add_meta_data('account_name', $payment_profile['name']);
        }
        
        $token->save();

        // Link to order and subscriptions
        $order->add_payment_token($token);
        
        if (function_exists('\wcs_order_contains_subscription') && \wcs_order_contains_subscription($order)) {
            $subscriptions = \wcs_get_subscriptions_for_order($order);
            foreach ($subscriptions as $subscription) {
                $subscription->add_payment_token($token);
            }
        }

        log_info('Mandate saved as token', [
            'mandate_id' => $mandate_id,
            'token_id' => $token->get_id()
        ]);
    }

    /**
     * Handle mandate webhook
     */
    private static function handle_mandate_webhook(array $data) : void {
        $mandate_id = $data['mandate']['id'] ?? null;
        $status = $data['mandate']['status'] ?? '';
        
        log_info('Mandate webhook received', [
            'mandate_id' => $mandate_id,
            'status' => $status
        ]);

        // Find token by mandate ID
        if ($mandate_id) {
            $tokens = \WC_Payment_Tokens::get_tokens([
                'gateway_id' => 'pay_subscriptions',
            ]);

            foreach ($tokens as $token) {
                if ($token->get_meta('mandate_id') === $mandate_id) {
                    if (strtoupper($status) === 'CANCELLED' || strtoupper($status) === 'EXPIRED') {
                        $token->delete();
                        log_info('Mandate token deleted', ['mandate_id' => $mandate_id]);
                    }
                    break;
                }
            }
        }

        self::send_response(200, 'OK');
    }

    /**
     * Handle refund webhook
     */
    private static function handle_refund_webhook(array $data) : void {
        $refund_id = $data['refund']['id'] ?? null;
        $transaction_id = $data['refund']['transactionId'] ?? null;
        $amount = $data['refund']['amount']['value'] ?? 0;
        $status = $data['refund']['status'] ?? '';
        
        log_info('Refund webhook received', [
            'refund_id' => $refund_id,
            'transaction_id' => $transaction_id,
            'amount' => $amount,
            'status' => $status
        ]);

        if ($transaction_id) {
            // Find order by transaction ID
            $orders = wc_get_orders([
                'transaction_id' => $transaction_id,
                'limit' => 1
            ]);
            
            if (!empty($orders)) {
                $order = reset($orders);
                $order->add_order_note(sprintf(
                    __('PAY webhook: Terugbetaling %s van €%s (status: %s)', 'pay-subs'),
                    $refund_id,
                    number_format($amount / 100, 2, ',', ''),
                    $status
                ));
            }
        }

        self::send_response(200, 'OK');
    }

    /**
     * Send HTTP response and exit
     */
    private static function send_response(int $status_code, string $message) : void {
        status_header($status_code);
        echo $message;
        exit;
    }
}
