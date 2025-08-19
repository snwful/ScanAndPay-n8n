<?php
/**
 * Helper functions
 */

if (!defined('ABSPATH')) {
    exit;
}

class SAN8N_Helper {
    
    /**
     * Format amount for display
     * @param float $amount
     * @return string
     */
    public static function format_amount($amount) {
        return number_format($amount, 2, '.', ',');
    }

    /**
     * Get order by reference ID
     * @param string $reference_id
     * @return WC_Order|false
     */
    public static function get_order_by_reference($reference_id) {
        $args = array(
            'limit' => 1,
            'meta_key' => '_san8n_reference_id',
            'meta_value' => $reference_id,
            'meta_compare' => '='
        );
        
        $orders = is_callable('wc_get_orders') ? call_user_func('wc_get_orders', $args) : array();
        return !empty($orders) ? $orders[0] : false;
    }

    /**
     * Sanitize file name
     * @param string $filename
     * @return string
     */
    public static function sanitize_filename($filename) {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $rand = is_callable('wp_generate_password') ? call_user_func('wp_generate_password', 16, false) : substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, 16);
        $name = 'slip_' . $rand;
        return $name . '.' . $ext;
    }

    /**
     * Get client IP address
     * @return string
     */
    public static function get_client_ip() {
        $ip_keys = array(
            'HTTP_CF_CONNECTING_IP',
            'HTTP_CLIENT_IP', 
            'HTTP_X_FORWARDED_FOR',
            'REMOTE_ADDR'
        );
        
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ips = explode(',', $_SERVER[$key]);
                $ip = trim($ips[0]);
                
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1';
    }

    /**
     * Get status badge HTML
     * @param string $status
     * @return string
     */
    public static function get_status_badge($status) {
        $tr = function($text) { return is_callable('__') ? call_user_func('__', $text, 'scanandpay-n8n') : $text; };
        $esc_attr = function($v) { return is_callable('esc_attr') ? call_user_func('esc_attr', $v) : htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
        $esc_html = function($v) { return is_callable('esc_html') ? call_user_func('esc_html', $v) : htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

        // Checkout-only: support only approved|rejected. Default any other value to rejected.
        $status_labels = array(
            'approved' => $tr('Approved'),
            'rejected' => $tr('Rejected'),
        );
        $normalized = in_array($status, array('approved', 'rejected'), true) ? $status : 'rejected';
        $label = $status_labels[$normalized];
        
        return sprintf(
            '<span class="san8n-status-badge san8n-status-%s">%s</span>',
            $esc_attr($normalized),
            $esc_html($label)
        );
    }

    /**
     * Check if running in test mode
     * @return bool
     */
    public static function is_test_mode() {
        $settings = is_callable('get_option') ? call_user_func('get_option', SAN8N_OPTIONS_KEY, array()) : array();
        return isset($settings['test_mode']) && $settings['test_mode'] === 'yes';
    }

    /**
     * Log if test mode
     * @param string $message
     * @param array $context
     */
    public static function test_log($message, $context = array()) {
        if (self::is_test_mode()) {
            $logger = new SAN8N_Logger();
            $logger->debug('[TEST] ' . $message, $context);
        }
    }
}
