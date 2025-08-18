<?php
/**
 * Lightweight stubs for IDE/static analysis only.
 * These are safe at runtime because we guard with function_exists/class_exists.
 */

// Common WP constants sometimes referenced in code
if (!defined('ABSPATH')) {
    define('ABSPATH', '/');
}

// i18n helpers
if (!function_exists('__')) {
    function __($text, $domain = null) { return (string) $text; }
}
if (!function_exists('_e')) {
    function _e($text, $domain = null) { echo (string) $text; }
}
if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = null) { return (string) $text; }
}
if (!function_exists('esc_attr__')) {
    function esc_attr__($text, $domain = null) { return (string) $text; }
}
if (!function_exists('esc_html_e')) {
    function esc_html_e($text, $domain = null) { echo (string) $text; }
}

// Escaping helpers
if (!function_exists('esc_html')) {
    function esc_html($text) { return (string) $text; }
}
if (!function_exists('esc_attr')) {
    function esc_attr($text) { return (string) $text; }
}
if (!function_exists('esc_url')) {
    function esc_url($url) { return (string) $url; }
}

// Sanitization helpers
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        $str = (string) $str;
        $str = strip_tags($str);
        $str = preg_replace('/[\r\n\t]+/', ' ', $str);
        return trim($str);
    }
}
if (!function_exists('absint')) {
    function absint($maybeint) { return abs((int) $maybeint); }
}

// Basic error and REST types
if (!class_exists('WP_Error')) {
    class WP_Error {
        protected $code;
        protected $message;
        public function __construct($code = '', $message = '') { $this->code = $code; $this->message = $message; }
        public function get_error_message() { return $this->message ?: 'Error'; }
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) { return $thing instanceof WP_Error; }
}

if (!class_exists('WP_REST_Server')) {
    class WP_REST_Server {
        public const READABLE = 'GET';
        public const CREATABLE = 'POST';
        public const EDITABLE = 'PUT';
        public const DELETABLE = 'DELETE';
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        protected $data;
        protected $status = 200;
        public function __construct($data = null, $status = 200) { $this->data = $data; $this->status = (int) $status; }
        public function set_status($status) { $this->status = (int) $status; return $this; }
        public function get_data() { return $this->data; }
        public function get_status() { return $this->status; }
    }
}

// --------------------------
// WooCommerce core light stubs
// --------------------------
if (!class_exists('WC_Payment_Gateway')) {
    class WC_Payment_Gateway {
        public $id;
        public $title;
        public $description;
        public $enabled;
        public $supports = array();
        public function __construct() {}
        public function init_form_fields() {}
        public function init_settings() {}
        public function get_option($key, $default = '') { return $default; }
        public function get_return_url($order = null) { return ''; }
        public function payment_fields() {}
    }
}

if (!class_exists('WC_Order')) {
    class WC_Order {
        public function update_meta_data($key, $value) {}
        public function payment_complete($transaction_id = null) {}
        public function add_order_note($note) {}
    }
}

if (!function_exists('WC')) {
    function WC() { return new stdClass(); }
}

if (!function_exists('wc_get_order')) {
    function wc_get_order($order_id) { return new WC_Order(); }
}

if (!function_exists('wc_add_notice')) {
    function wc_add_notice($message, $type = 'notice') {}
}

if (!function_exists('wc_format_localized_price')) {
    function wc_format_localized_price($value) { return number_format((float) $value, 2); }
}

if (!function_exists('wc_get_orders')) {
    function wc_get_orders($args = array()) { return array(); }
}
// --------------------------
// WooCommerce Blocks light stub (via class_alias to avoid namespaces)
// --------------------------
if (!class_exists('Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType')) {
    if (!class_exists('SAN8N_AbstractPaymentMethodType_Stub')) {
        abstract class SAN8N_AbstractPaymentMethodType_Stub {
            protected $name = '';
            public function initialize() {}
            public function is_active() { return false; }
            public function get_payment_method_script_handles() { return array(); }
            public function get_payment_method_data() { return array(); }
        }
    }
    class_alias('SAN8N_AbstractPaymentMethodType_Stub', 'Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType');
}

// --------------------------
// Commonly used WP helpers (no-ops)
// --------------------------
if (!function_exists('add_action')) { function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {} }
if (!function_exists('add_filter')) { function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {} }
if (!function_exists('wp_register_style')) { function wp_register_style($handle, $src = '', $deps = array(), $ver = false, $media = 'all') {} }
if (!function_exists('wp_enqueue_style')) { function wp_enqueue_style($handle, $src = '', $deps = array(), $ver = false, $media = 'all') {} }
if (!function_exists('wp_style_is')) { function wp_style_is($handle, $list = 'enqueued') { return false; } }
if (!function_exists('wp_register_script')) { function wp_register_script($handle, $src = '', $deps = array(), $ver = false, $in_footer = false) {} }
if (!function_exists('wp_localize_script')) { function wp_localize_script($handle, $object_name, $l10n) {} }
if (!function_exists('wp_enqueue_script')) { function wp_enqueue_script($handle, $src = '', $deps = array(), $ver = false, $in_footer = false) {} }
if (!function_exists('wp_script_is')) { function wp_script_is($handle, $list = 'enqueued') { return false; } }
if (!function_exists('wp_set_script_translations')) { function wp_set_script_translations($handle, $domain, $path = '') {} }
if (!function_exists('admin_url')) { function admin_url($path = '') { return $path; } }
if (!function_exists('rest_url')) { function rest_url($path = '') { return $path; } }
if (!function_exists('wp_create_nonce')) { function wp_create_nonce($action = -1) { return 'nonce'; } }
if (!function_exists('wpautop')) { function wpautop($pee, $br = true) { return (string) $pee; } }
if (!function_exists('wp_kses_post')) { function wp_kses_post($data) { return $data; } }
if (!function_exists('do_shortcode')) { function do_shortcode($content) { return $content; } }
if (!function_exists('shortcode_exists')) { function shortcode_exists($tag) { return false; } }
if (!function_exists('is_cart')) { function is_cart() { return false; } }
if (!function_exists('is_checkout')) { function is_checkout() { return false; } }
