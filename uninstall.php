<?php
// Security check
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

// Option cleanup (keep tokens & orders for audit!)
delete_option('wc_gateway_pay_settings');
