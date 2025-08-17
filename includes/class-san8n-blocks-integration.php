<?php
/**
 * Blocks Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class SAN8N_Blocks_Integration extends AbstractPaymentMethodType {
    protected $name = 'scanandpay_n8n';
    
    public function initialize() {
        $this->settings = get_option(SAN8N_OPTIONS_KEY, array());
    }

    public function is_active() {
        $gateway = WC()->payment_gateways->payment_gateways()[$this->name];
        return $gateway->is_available();
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

        wp_register_script(
            'san8n-blocks-integration',
            $script_url,
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations('san8n-blocks-integration', 'scanandpay-n8n', SAN8N_PLUGIN_DIR . 'languages/');
        }

        // Enqueue styles
        wp_enqueue_style(
            'san8n-blocks-checkout',
            SAN8N_PLUGIN_URL . 'assets/css/blocks-checkout.css',
            array(),
            SAN8N_VERSION
        );

        return array('san8n-blocks-integration');
    }

    public function get_payment_method_data() {
        $gateway = WC()->payment_gateways->payment_gateways()[$this->name];
        
        return array(
            'title' => $gateway->get_title(),
            'description' => $gateway->get_description(),
            'supports' => array_filter($gateway->supports, array($gateway, 'supports')),
            'settings' => array(
                'blocks_mode' => $this->get_setting('blocks_mode', 'express'),
                'allow_blocks_autosubmit_experimental' => $this->get_setting('allow_blocks_autosubmit_experimental') === 'yes',
                'show_express_only_when_approved' => $this->get_setting('show_express_only_when_approved', 'yes') === 'yes',
                'prevent_double_submit_ms' => intval($this->get_setting('prevent_double_submit_ms', '1500')),
                'max_file_size' => intval($this->get_setting('max_file_size', '5')) * 1024 * 1024,
                'qr_placeholder' => SAN8N_PLUGIN_URL . 'assets/images/qr-placeholder.svg'
            ),
            'rest_url' => rest_url(SAN8N_REST_NAMESPACE),
            'nonce' => wp_create_nonce('san8n-verify'),
            'gateway_id' => $this->name,
            'i18n' => array(
                'scan_qr' => __('Step 1: Scan PromptPay QR Code', 'scanandpay-n8n'),
                'upload_slip' => __('Step 2: Upload Payment Slip', 'scanandpay-n8n'),
                'verify_payment' => __('Verify Payment', 'scanandpay-n8n'),
                'pay_now' => __('Pay now', 'scanandpay-n8n'),
                'verifying' => __('Verifying payment...', 'scanandpay-n8n'),
                'approved' => __('Payment approved!', 'scanandpay-n8n'),
                'processing_order' => __('Processing order...', 'scanandpay-n8n'),
                'rejected' => __('Payment rejected. Please try again.', 'scanandpay-n8n'),
                'error' => __('Verification error. Please try again.', 'scanandpay-n8n'),
                'file_too_large' => __('File size exceeds limit.', 'scanandpay-n8n'),
                'invalid_file_type' => __('Invalid file type. Please upload JPG or PNG.', 'scanandpay-n8n'),
                'upload_required' => __('Please upload a payment slip.', 'scanandpay-n8n'),
                'amount_label' => __('Amount: %s THB', 'scanandpay-n8n'),
                'accepted_formats' => __('Accepted formats: JPG, PNG (max %dMB)', 'scanandpay-n8n'),
                'remove' => __('Remove', 'scanandpay-n8n')
            )
        );
    }
    
    private function get_setting($setting, $default = null) {
        return isset($this->settings[$setting]) ? $this->settings[$setting] : $default;
    }
}
