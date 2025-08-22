<?php
/**
 * Blocks Integration
 */

if (!defined('ABSPATH')) {
    exit;
}
// Define a runtime-resolved base class for extension to satisfy IDE/static analysis and runtime.
if (class_exists('Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType')) {
    if (!class_exists('SAN8N_AbstractPaymentMethodType_Runtime')) {
        class_alias('Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType', 'SAN8N_AbstractPaymentMethodType_Runtime');
    }
} else {
    if (!class_exists('SAN8N_AbstractPaymentMethodType_Runtime')) {
        abstract class SAN8N_AbstractPaymentMethodType_Runtime {
            protected $name = '';
            public function initialize() {}
            public function is_active() { return false; }
            public function get_payment_method_script_handles() { return array(); }
            public function get_payment_method_data() { return array(); }
        }
    }
}

final class SAN8N_Blocks_Integration extends SAN8N_AbstractPaymentMethodType_Runtime {
    protected $name = 'scanandpay_n8n';
    /**
     * Plugin settings cached from options.
     * @var array<string,mixed>
     */
    protected $settings = array();
    
    public function initialize() {
        $this->settings = is_callable('get_option') ? call_user_func('get_option', SAN8N_OPTIONS_KEY, array()) : array();
    }

    public function is_active() {
        $wc = is_callable('WC') ? call_user_func('WC') : null;
        if (!$wc) {
            return false;
        }
        $pg = $wc->payment_gateways;
        if (!$pg || !method_exists($pg, 'payment_gateways')) {
            return false;
        }
        $gateways = $pg->payment_gateways();
        if (!is_array($gateways) || !isset($gateways[$this->name])) {
            return false;
        }
        $gateway = $gateways[$this->name];
        return is_object($gateway) && method_exists($gateway, 'is_available') ? (bool) $gateway->is_available() : false;
    }

    public function get_payment_method_script_handles() {
        $script_path = '/assets/js/blocks-integration.js';
        $script_asset_path = SAN8N_PLUGIN_DIR . 'assets/js/blocks-integration.asset.php';
        $script_asset = file_exists($script_asset_path) 
            ? require($script_asset_path) 
            : array(
                'dependencies' => array(),
                'version' => SAN8N_VERSION
            );
        
        $script_url = SAN8N_PLUGIN_URL . 'assets/js/blocks-integration.js';

        if (is_callable('wp_register_script')) {
            call_user_func(
                'wp_register_script',
                'san8n-blocks-integration',
                $script_url,
                isset($script_asset['dependencies']) ? $script_asset['dependencies'] : array(),
                isset($script_asset['version']) ? $script_asset['version'] : SAN8N_VERSION,
                true
            );
        }

        if (is_callable('wp_set_script_translations')) {
            call_user_func('wp_set_script_translations', 'san8n-blocks-integration', 'scanandpay-n8n', SAN8N_PLUGIN_DIR . 'languages/');
        }

        // Enqueue styles
        if (is_callable('wp_enqueue_style')) {
            call_user_func(
                'wp_enqueue_style',
                'san8n-blocks-checkout',
                SAN8N_PLUGIN_URL . 'assets/css/blocks-checkout.css',
                array(),
                SAN8N_VERSION
            );
        }

        // No PromptPay assets are enqueued. QR is a static image configured in settings.

        return array('san8n-blocks-integration');
    }

    public function get_payment_method_data() {
        $gateway = null;
        $wc = is_callable('WC') ? call_user_func('WC') : null;
        if ($wc && $wc->payment_gateways && method_exists($wc->payment_gateways, 'payment_gateways')) {
            $gateways = $wc->payment_gateways->payment_gateways();
            if (is_array($gateways) && isset($gateways[$this->name])) {
                $gateway = $gateways[$this->name];
            }
        }

        $title = $gateway && method_exists($gateway, 'get_title') ? $gateway->get_title() : $this->tr('Scan & Pay (n8n)');
        $description = $gateway && method_exists($gateway, 'get_description') ? $gateway->get_description() : '';
        $supports = array();
        if ($gateway && isset($gateway->supports) && is_array($gateway->supports) && method_exists($gateway, 'supports')) {
            $supports = array_values(array_filter($gateway->supports, array($gateway, 'supports')));
        }

        $qr_custom = $this->get_setting('qr_image_url', '');
        $qr_url = !empty($qr_custom) ? $qr_custom : (SAN8N_PLUGIN_URL . 'assets/images/qr-placeholder.svg');

        return array(
            'title' => $title,
            'description' => $description,
            'supports' => $supports,
            'settings' => array(
                'blocks_mode' => $this->get_setting('blocks_mode', 'express'),
                'allow_blocks_autosubmit_experimental' => $this->get_setting('allow_blocks_autosubmit_experimental') === 'yes',
                'show_express_only_when_approved' => $this->get_setting('show_express_only_when_approved', 'yes') === 'yes',
                'prevent_double_submit_ms' => intval($this->get_setting('prevent_double_submit_ms', '1500')),
                'max_file_size' => intval($this->get_setting('max_file_size', '5')) * 1024 * 1024,
                'verify_timeout_ms' => intval($this->get_setting('verify_timeout_ms', '9000')),
                'qr_placeholder' => $qr_url
            ),
            'rest_url' => is_callable('rest_url') ? call_user_func('rest_url', SAN8N_REST_NAMESPACE) : '',
            'nonce' => is_callable('wp_create_nonce') ? call_user_func('wp_create_nonce', 'wp_rest') : '',
            'gateway_id' => $this->name,
            'i18n' => array(
                'scan_qr' => $this->tr('Step 1: Scan QR Code'),
                'upload_slip' => $this->tr('Step 2: Upload Payment Slip'),
                'verify_payment' => $this->tr('Verify Payment'),
                'pay_now' => $this->tr('Pay now'),
                'verifying' => $this->tr('Verifying payment...'),
                'approved' => $this->tr('Payment approved!'),
                'processing_order' => $this->tr('Processing order...'),
                'rejected' => $this->tr('Payment rejected. Please try again.'),
                'error' => $this->tr('Verification error. Please try again.'),
                'timeout' => $this->tr('Verification timed out. Please try again.'),
                'file_too_large' => $this->tr('File size exceeds limit.'),
                'invalid_file_type' => $this->tr('Invalid file type. Please upload JPG or PNG.'),
                'upload_required' => $this->tr('Please upload a payment slip.'),
                'verify_required' => $this->tr('Please verify your payment before placing the order.'),
                'amount_label' => $this->tr('Amount: %s THB'),
                'accepted_formats' => $this->tr('Accepted formats: JPG, PNG (max %dMB)'),
                'remove' => $this->tr('Remove')
            )
        );
    }
    
    private function get_setting($setting, $default = null) {
        $settings = is_array($this->settings) ? $this->settings : array();
        return array_key_exists($setting, $settings) ? $settings[$setting] : $default;
    }

    private function tr($text) {
        return is_callable('__') ? call_user_func('__', $text, 'scanandpay-n8n') : $text;
    }
}
