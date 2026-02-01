<?php
namespace PAY_Subscriptions;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class WC_Payment_Token_PAY extends \WC_Payment_Token {
    protected $type = 'pay_token';

    public function get_display_name( $deprecated = '' ) {
        $mandate_id = $this->get_meta('mandate_id');
        $last4      = $this->get_meta('last4');
        if ( $mandate_id ) {
            return sprintf( __('PAY Mandate %s', 'pay-subs'), $mandate_id );
        }
        if ( $last4 ) {
            return sprintf( __('PAY •••• %s', 'pay-subs'), $last4 );
        }
        return __('PAY betaalmethode', 'pay-subs');
    }
}
