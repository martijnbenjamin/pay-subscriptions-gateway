<?php
namespace PAY_Subscriptions;

if ( ! defined( 'ABSPATH' ) ) { exit; }

function get_webhook_url() : string {
    // WooCommerce "wc-api" legacy endpoint
    return trailingslashit( home_url() ) . '?wc-api=pay_subs_webhook';
}

function cents( $amount_float ) : int {
    return (int) round( (float) $amount_float * 100 );
}

function log_debug( $message, $context = [] ) : void {
    Logger::instance()->debug( $message, $context );
}

function log_info( $message, $context = [] ) : void {
    Logger::instance()->info( $message, $context );
}

function log_error( $message, $context = [] ) : void {
    Logger::instance()->error( $message, $context );
}

function log_warning( $message, $context = [] ) : void {
    Logger::instance()->warning( $message, $context );
}
