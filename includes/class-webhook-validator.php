<?php
namespace PAY_Subscriptions;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Webhook Validator for secure webhook verification
 * Implements multiple validation layers
 */
class Webhook_Validator {
    
    private $secret;
    private $max_age = 300; // 5 minutes
    
    public function __construct($secret) {
        $this->secret = $secret;
    }
    
    /**
     * Validate incoming webhook request
     * 
     * @param string $payload Raw request body
     * @param array $headers Request headers
     * @return bool
     */
    public function validate($payload, $headers = []) {
        // Check if secret is configured
        if (empty($this->secret)) {
            log_error('Webhook secret not configured');
            return false;
        }
        
        // Get signature from headers
        $signature = $this->get_signature_from_headers($headers);
        if (empty($signature)) {
            log_error('No signature found in webhook headers');
            return false;
        }
        
        // Verify signature
        if (!$this->verify_signature($payload, $signature)) {
            log_error('Invalid webhook signature');
            return false;
        }
        
        // Verify timestamp (if present)
        if (!$this->verify_timestamp($headers)) {
            log_error('Webhook timestamp validation failed');
            return false;
        }
        
        // Additional IP whitelist check (optional)
        if (!$this->verify_ip_whitelist()) {
            log_warning('Webhook from non-whitelisted IP');
            // Don't fail, just log warning
        }
        
        return true;
    }
    
    /**
     * Get signature from headers
     */
    private function get_signature_from_headers($headers) {
        // Try multiple possible header names
        $signature_headers = [
            'HTTP_X_PAY_SIGNATURE',
            'HTTP_PAY_SIGNATURE',
            'HTTP_SIGNATURE',
            'X-Pay-Signature',
            'Pay-Signature',
            'Signature'
        ];
        
        foreach ($signature_headers as $header) {
            // Check $_SERVER
            if (isset($_SERVER[$header])) {
                return $_SERVER[$header];
            }
            
            // Check provided headers array
            if (isset($headers[$header])) {
                return $headers[$header];
            }
            
            // Check with different case
            $lower = strtolower($header);
            if (isset($headers[$lower])) {
                return $headers[$lower];
            }
        }
        
        return null;
    }
    
    /**
     * Verify HMAC signature
     */
    private function verify_signature($payload, $signature) {
        // PAY.nl uses base64 encoded HMAC-SHA256
        $calculated = base64_encode(hash_hmac('sha256', $payload, $this->secret, true));
        
        // Use hash_equals for timing-safe comparison
        return hash_equals($calculated, $signature);
    }
    
    /**
     * Verify timestamp to prevent replay attacks
     */
    private function verify_timestamp($headers) {
        // Look for timestamp header
        $timestamp_headers = [
            'HTTP_X_PAY_TIMESTAMP',
            'HTTP_PAY_TIMESTAMP',
            'X-Pay-Timestamp',
            'Pay-Timestamp'
        ];
        
        $timestamp = null;
        foreach ($timestamp_headers as $header) {
            if (isset($_SERVER[$header])) {
                $timestamp = $_SERVER[$header];
                break;
            }
            if (isset($headers[$header])) {
                $timestamp = $headers[$header];
                break;
            }
        }
        
        // If no timestamp, allow (not all webhooks have timestamps)
        if (empty($timestamp)) {
            return true;
        }
        
        // Check if timestamp is within acceptable range
        $current_time = time();
        $webhook_time = is_numeric($timestamp) ? (int)$timestamp : strtotime($timestamp);
        
        if ($webhook_time === false) {
            return false;
        }
        
        $age = abs($current_time - $webhook_time);
        
        if ($age > $this->max_age) {
            log_warning('Webhook timestamp too old', [
                'age' => $age,
                'max_age' => $this->max_age
            ]);
            return false;
        }
        
        return true;
    }
    
    /**
     * Verify IP whitelist (optional security layer)
     */
    private function verify_ip_whitelist() {
        // PAY.nl webhook IPs (these should be configured based on PAY.nl documentation)
        $whitelist = [
            // PAY.nl production IPs (example - verify with PAY.nl)
            '37.97.196.0/24',
            '185.49.104.0/24',
            // PAY.nl test environment IPs
            '37.97.197.0/24'
        ];
        
        $whitelist = apply_filters('pay_webhook_ip_whitelist', $whitelist);
        
        // If whitelist is empty, allow all
        if (empty($whitelist)) {
            return true;
        }
        
        $remote_ip = $_SERVER['REMOTE_ADDR'] ?? '';
        
        if (empty($remote_ip)) {
            return false;
        }
        
        // Check if IP is in whitelist
        foreach ($whitelist as $allowed) {
            if ($this->ip_in_range($remote_ip, $allowed)) {
                return true;
            }
        }
        
        log_warning('Webhook from non-whitelisted IP', [
            'ip' => $remote_ip
        ]);
        
        return false;
    }
    
    /**
     * Check if IP is in CIDR range
     */
    private function ip_in_range($ip, $range) {
        if (strpos($range, '/') === false) {
            // Single IP
            return $ip === $range;
        }
        
        // CIDR notation
        list($subnet, $bits) = explode('/', $range);
        
        if ($bits === null) {
            $bits = 32;
        }
        
        $ip_long = ip2long($ip);
        $subnet_long = ip2long($subnet);
        $mask = -1 << (32 - $bits);
        $subnet_long &= $mask;
        
        return ($ip_long & $mask) == $subnet_long;
    }
    
    /**
     * Generate signature for testing
     */
    public function generate_signature($payload) {
        return base64_encode(hash_hmac('sha256', $payload, $this->secret, true));
    }
    
    /**
     * Validate and parse JSON payload
     */
    public function parse_payload($payload) {
        if (empty($payload)) {
            throw new \Exception('Empty webhook payload');
        }
        
        $data = json_decode($payload, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON in webhook payload: ' . json_last_error_msg());
        }
        
        if (!is_array($data)) {
            throw new \Exception('Webhook payload is not an array');
        }
        
        return $data;
    }
}
