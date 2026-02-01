<?php
/**
 * Plugin Name: PAY Subscriptions Gateway – 072DESIGN
 * Plugin URI: https://072design.nl/
 * Description: WooCommerce-gateway voor PAY met automatische incasso en webhook-integratie voor abonnementen. Ontwikkeld door 072DESIGN.
 * Version: 1.6.0
 * Author: 072DESIGN
 * Author URI: https://072design.nl/
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: pay-subscriptions
 * Domain Path: /languages
 */


if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'PAY_SUBS_VERSION', '1.6.0' );
define( 'PAY_SUBS_PLUGIN_FILE', __FILE__ );
define( 'PAY_SUBS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PAY_SUBS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// --- Dependency checks ---
add_action('plugins_loaded', function () {
    if ( ! class_exists('WooCommerce') || ! class_exists('WC_Subscriptions') ) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>PAY Subscriptions Gateway</strong> vereist WooCommerce en WooCommerce Subscriptions.</p></div>';
        });
        return;
    }

    // Includes
    require_once PAY_SUBS_PLUGIN_DIR . 'includes/helpers.php';
    require_once PAY_SUBS_PLUGIN_DIR . 'includes/class-logger.php';
    require_once PAY_SUBS_PLUGIN_DIR . 'includes/class-pay-client.php';
    require_once PAY_SUBS_PLUGIN_DIR . 'includes/class-wc-payment-token-pay.php';
    require_once PAY_SUBS_PLUGIN_DIR . 'includes/class-webhook-validator.php';
    require_once PAY_SUBS_PLUGIN_DIR . 'includes/class-webhook-handler.php';
    require_once PAY_SUBS_PLUGIN_DIR . 'includes/class-wc-gateway-pay.php';

    // Register gateway
    add_filter('woocommerce_payment_gateways', function ($methods) {
        $methods[] = 'PAY_Subscriptions\WC_Gateway_PAY';
        return $methods;
    });

    // Webhook endpoint via legacy wc-api
    add_action('woocommerce_api_pay_subs_webhook', ['PAY_Subscriptions\Webhook_Handler','handle']);
});

// On activation, generate a default webhook secret if empty
register_activation_hook(__FILE__, function(){
    $opt = get_option('wc_gateway_pay_settings', []);
    if ( empty($opt['webhook_secret']) ) {
        $opt['webhook_secret'] = wp_generate_password(32, false, false);
        update_option('wc_gateway_pay_settings', $opt);
    }
});

