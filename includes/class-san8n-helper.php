<?php
/**
 * Helper functions
 */

if (!defined('ABSPATH')) {
    exit;
}

class SAN8N_Helper {
    /**
     * Validate PromptPay ID format
     * @param string $id
     * @return bool
     */
    public static function validate_promptpay_id($id) {
        // Remove spaces and dashes
        $id = preg_replace('/[\s-]/', '', $id);
        
        // Check if it's a phone number (10 digits starting with 0)
        if (preg_match('/^0[0-9]{9}$/', $id)) {
            return true;
        }
        
        // Check if it's a national ID (13 digits)
        if (preg_match('/^[0-9]{13}$/', $id)) {
            return true;
        }
        
        // Check if it's a tax ID (13 digits)
        if (preg_match('/^[0-9]{13}$/', $id)) {
            return true;
        }
        
        // Check if it's an e-wallet ID
        if (preg_match('/^[0-9]{15}$/', $id)) {
            return true;
        }
        
        return false;
    }

    /**
     * Format amount for display
     * @param float $amount
     * @return string
     */
    public static function format_amount($amount) {
        return number_format($amount, 2, '.', ',');
    }

    /**
     * Check if cart has changed
     * @param string $stored_hash
     * @return bool
     */
    public static function has_cart_changed($stored_hash) {
        if (!WC()->cart) {
            return true;
        }
        
        $current_hash = WC()->cart->get_cart_hash();
        return $stored_hash !== $current_hash;
    }

    /**
     * Clear session data
     */
    public static function clear_session_data() {
        if (WC()->session) {
            WC()->session->set(SAN8N_SESSION_FLAG, false);
            WC()->session->set('san8n_attachment_id', null);
            WC()->session->set('san8n_approved_amount', null);
            WC()->session->set('san8n_cart_hash', null);
            WC()->session->set('san8n_reference_id', null);
        }
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
        
        $orders = wc_get_orders($args);
        return !empty($orders) ? $orders[0] : false;
    }

    /**
     * Check if payment is within time window
     * @param string $timestamp
     * @param int $window_minutes
     * @return bool
     */
    public static function is_within_time_window($timestamp, $window_minutes = 15) {
        $payment_time = is_numeric($timestamp) ? $timestamp : strtotime($timestamp);
        $current_time = current_time('timestamp');
        $diff_minutes = abs($current_time - $payment_time) / 60;
        
        return $diff_minutes <= $window_minutes;
    }

    /**
     * Sanitize file name
     * @param string $filename
     * @return string
     */
    public static function sanitize_filename($filename) {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $name = 'slip_' . wp_generate_password(16, false);
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
        $status_labels = array(
            'approved' => __('Approved', 'scanandpay-n8n'),
            'rejected' => __('Rejected', 'scanandpay-n8n'),
            'pending' => __('Pending', 'scanandpay-n8n'),
            'expired' => __('Expired', 'scanandpay-n8n')
        );
        
        $label = isset($status_labels[$status]) ? $status_labels[$status] : ucfirst($status);
        
        return sprintf(
            '<span class="san8n-status-badge san8n-status-%s">%s</span>',
            esc_attr($status),
            esc_html($label)
        );
    }

    /**
     * Check if running in test mode
     * @return bool
     */
    public static function is_test_mode() {
        $settings = get_option(SAN8N_OPTIONS_KEY, array());
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
