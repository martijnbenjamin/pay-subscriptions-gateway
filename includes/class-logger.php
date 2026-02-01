<?php
namespace PAY_Subscriptions;

use WC_Logger;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Logger {
    private static $instance;
    private $logger;
    private $source = 'pay_subscriptions';

    private function __construct() { $this->logger = new WC_Logger(); }

    public static function instance() : self {
        if ( ! self::$instance ) { self::$instance = new self(); }
        return self::$instance;
    }

    public function debug( $msg, $context = [] ) { $this->logger->debug( $this->format($msg,$context), [ 'source' => $this->source ] ); }
    public function info ( $msg, $context = [] ) { $this->logger->info ( $this->format($msg,$context), [ 'source' => $this->source ] ); }
    public function warning( $msg, $context = [] ) { $this->logger->warning( $this->format($msg,$context), [ 'source' => $this->source ] ); }
    public function error( $msg, $context = [] ) { $this->logger->error( $this->format($msg,$context), [ 'source' => $this->source ] ); }

    private function format( $msg, $context ) : string {
        if ( ! empty( $context ) ) {
            $msg .= ' | ' . wp_json_encode( $context );
        }
        return $msg;
    }
}
