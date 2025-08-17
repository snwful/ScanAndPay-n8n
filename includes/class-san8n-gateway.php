<?php
/**
 * Main Gateway Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class SAN8N_Gateway extends WC_Payment_Gateway {
    private $logger;
    private $n8n_webhook_url;
    private $shared_secret;
    private $auto_place_order_classic;
    private $blocks_mode;
    private $allow_blocks_autosubmit_experimental;
    private $show_express_only_when_approved;
    private $prevent_double_submit_ms;
    private $max_file_size;
    private $allowed_file_types;
    private $retention_days;
    private $log_level;

    public function __construct() {
        $this->id = SAN8N_GATEWAY_ID;
        $this->icon = '';
        $this->has_fields = true;
        $this->method_title = __('Scan & Pay (n8n)', 'scanandpay-n8n');
        $this->method_description = __('PromptPay payment gateway with inline slip verification via n8n', 'scanandpay-n8n');
        $this->supports = array('products', 'refunds');

        // Load settings
        $this->init_form_fields();
        $this->init_settings();

        // Get settings
        $this->title = $this->get_option('title', __('Scan & Pay (n8n) — PromptPay', 'scanandpay-n8n'));
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->n8n_webhook_url = $this->get_option('n8n_webhook_url');
        $this->shared_secret = $this->get_option('shared_secret');
        $this->auto_place_order_classic = $this->get_option('auto_place_order_classic', 'yes') === 'yes';
        $this->blocks_mode = $this->get_option('blocks_mode', 'express');
        $this->allow_blocks_autosubmit_experimental = $this->get_option('allow_blocks_autosubmit_experimental', 'no') === 'yes';
        $this->show_express_only_when_approved = $this->get_option('show_express_only_when_approved', 'yes') === 'yes';
        $this->prevent_double_submit_ms = intval($this->get_option('prevent_double_submit_ms', '1500'));
        $this->max_file_size = intval($this->get_option('max_file_size', '5')) * 1024 * 1024; // Convert MB to bytes
        $this->allowed_file_types = array('jpg', 'jpeg', 'png');
        $this->retention_days = intval($this->get_option('retention_days', '30'));
        $this->log_level = $this->get_option('log_level', 'info');

        // Initialize logger
        $this->logger = new SAN8N_Logger();

        // Hooks
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_admin_order_meta'), 10, 1);
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'scanandpay-n8n'),
                'type' => 'checkbox',
                'label' => __('Enable Scan & Pay (n8n)', 'scanandpay-n8n'),
                'default' => 'no'
            ),
            'title' => array(
                'title' => __('Title', 'scanandpay-n8n'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'scanandpay-n8n'),
                'default' => __('Scan & Pay (n8n) — PromptPay', 'scanandpay-n8n'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', 'scanandpay-n8n'),
                'type' => 'textarea',
                'description' => __('Payment method description that the customer will see on your checkout.', 'scanandpay-n8n'),
                'default' => __('Scan PromptPay QR code and upload payment slip for instant verification.', 'scanandpay-n8n'),
                'desc_tip' => true,
            ),
            'n8n_webhook_url' => array(
                'title' => __('n8n Webhook URL', 'scanandpay-n8n'),
                'type' => 'text',
                'description' => __('The HTTPS webhook URL for n8n verification service.', 'scanandpay-n8n'),
                'desc_tip' => true,
                'placeholder' => 'https://your-n8n-instance.com/webhook/verify-slip',
                'custom_attributes' => array('required' => 'required')
            ),
            'shared_secret' => array(
                'title' => __('Shared Secret', 'scanandpay-n8n'),
                'type' => 'password',
                'description' => __('Shared secret for HMAC signature verification.', 'scanandpay-n8n'),
                'desc_tip' => true,
                'custom_attributes' => array('required' => 'required')
            ),
            'classic_settings' => array(
                'title' => __('Classic Checkout Settings', 'scanandpay-n8n'),
                'type' => 'title',
                'description' => '',
            ),
            'auto_place_order_classic' => array(
                'title' => __('Auto-Submit Order (Classic)', 'scanandpay-n8n'),
                'type' => 'checkbox',
                'label' => __('Automatically place order after approval (recommended)', 'scanandpay-n8n'),
                'description' => __('When payment is approved, automatically submit the order without requiring additional click.', 'scanandpay-n8n'),
                'default' => 'yes',
                'desc_tip' => true,
            ),
            'prevent_double_submit_ms' => array(
                'title' => __('Double-Submit Prevention (ms)', 'scanandpay-n8n'),
                'type' => 'number',
                'description' => __('Milliseconds to prevent double submission after auto-submit.', 'scanandpay-n8n'),
                'default' => '1500',
                'desc_tip' => true,
                'custom_attributes' => array('min' => '500', 'max' => '5000')
            ),
            'blocks_settings' => array(
                'title' => __('Blocks Checkout Settings', 'scanandpay-n8n'),
                'type' => 'title',
                'description' => '',
            ),
            'blocks_mode' => array(
                'title' => __('Blocks Mode', 'scanandpay-n8n'),
                'type' => 'select',
                'description' => __('Choose how the payment gateway behaves in Blocks checkout.', 'scanandpay-n8n'),
                'default' => 'express',
                'desc_tip' => true,
                'options' => array(
                    'express' => __('Express Button (recommended)', 'scanandpay-n8n'),
                    'autosubmit_experimental' => __('Auto-Submit (experimental)', 'scanandpay-n8n'),
                    'none' => __('Standard behavior', 'scanandpay-n8n')
                )
            ),
            'allow_blocks_autosubmit_experimental' => array(
                'title' => __('Enable Experimental Auto-Submit', 'scanandpay-n8n'),
                'type' => 'checkbox',
                'label' => __('Allow experimental auto-submit in Blocks (may break in future)', 'scanandpay-n8n'),
                'description' => __('⚠️ WARNING: This feature is experimental and may break with WooCommerce updates.', 'scanandpay-n8n'),
                'default' => 'no',
                'desc_tip' => false,
            ),
            'show_express_only_when_approved' => array(
                'title' => __('Show Express Button Only When Approved', 'scanandpay-n8n'),
                'type' => 'checkbox',
                'label' => __('Hide express button until payment is approved', 'scanandpay-n8n'),
                'default' => 'yes',
                'desc_tip' => true,
            ),
            'file_settings' => array(
                'title' => __('File Upload Settings', 'scanandpay-n8n'),
                'type' => 'title',
                'description' => '',
            ),
            'max_file_size' => array(
                'title' => __('Max File Size (MB)', 'scanandpay-n8n'),
                'type' => 'number',
                'description' => __('Maximum allowed file size for slip upload.', 'scanandpay-n8n'),
                'default' => '5',
                'desc_tip' => true,
                'custom_attributes' => array('min' => '1', 'max' => '10')
            ),
            'retention_days' => array(
                'title' => __('Retention Days', 'scanandpay-n8n'),
                'type' => 'number',
                'description' => __('Number of days to keep uploaded slips before automatic deletion.', 'scanandpay-n8n'),
                'default' => '30',
                'desc_tip' => true,
                'custom_attributes' => array('min' => '7', 'max' => '365')
            ),
            'advanced_settings' => array(
                'title' => __('Advanced Settings', 'scanandpay-n8n'),
                'type' => 'title',
                'description' => '',
            ),
            'log_level' => array(
                'title' => __('Log Level', 'scanandpay-n8n'),
                'type' => 'select',
                'description' => __('Set the logging level for debugging.', 'scanandpay-n8n'),
                'default' => 'info',
                'desc_tip' => true,
                'options' => array(
                    'emergency' => __('Emergency', 'scanandpay-n8n'),
                    'alert' => __('Alert', 'scanandpay-n8n'),
                    'critical' => __('Critical', 'scanandpay-n8n'),
                    'error' => __('Error', 'scanandpay-n8n'),
                    'warning' => __('Warning', 'scanandpay-n8n'),
                    'notice' => __('Notice', 'scanandpay-n8n'),
                    'info' => __('Info', 'scanandpay-n8n'),
                    'debug' => __('Debug', 'scanandpay-n8n')
                )
            ),
            'test_webhook' => array(
                'title' => __('Test Webhook', 'scanandpay-n8n'),
                'type' => 'button',
                'description' => __('Send a test ping to the n8n webhook to verify connectivity.', 'scanandpay-n8n'),
                'desc_tip' => true,
                'custom_attributes' => array(
                    'onclick' => 'san8n_test_webhook(); return false;'
                )
            )
        );
    }

    public function payment_fields() {
        if ($this->description) {
            echo wpautop(wp_kses_post($this->description));
        }

        $order_total = WC()->cart->get_total('edit');
        $session_token = $this->generate_session_token();
        
        ?>
        <fieldset id="san8n-payment-fields" class="san8n-payment-container wc-payment-form" style="background:transparent;">
            <div class="san8n-qr-section form-row form-row-wide">
                <h4><?php esc_html_e('Step 1: Scan PromptPay QR Code', 'scanandpay-n8n'); ?></h4>
                <div class="san8n-qr-container">
                    <div class="san8n-qr-placeholder">
                        <?php
                        $amount = (float) $order_total;
                        if (shortcode_exists('promptpayqr')) {
                            echo do_shortcode(sprintf('[promptpayqr amount="%s"]', esc_attr($amount)));
                        } else {
                            echo '<p>' . esc_html__('PromptPay plugin not active. Using placeholder QR.', 'scanandpay-n8n') . '</p>';
                            echo '<img src="' . esc_url(SAN8N_PLUGIN_URL . 'assets/images/qr-placeholder.svg') . '" alt="' . esc_attr__('PromptPay QR placeholder', 'scanandpay-n8n') . '" />';
                        }
                        ?>
                    </div>
                    <div class="san8n-amount-display">
                        <?php
                        echo sprintf(
                            /* translators: %s: order amount */
                            __('Amount: %s THB', 'scanandpay-n8n'),
                            wc_format_localized_price($order_total)
                        ); 
                        ?>
                    </div>
                </div>
            </div>

            <div class="san8n-upload-section form-row form-row-wide">
                <h4><?php esc_html_e('Step 2: Upload Payment Slip', 'scanandpay-n8n'); ?></h4>
                <div class="san8n-upload-container">
                    <input type="file" 
                           id="san8n-slip-upload" 
                           name="san8n_slip_upload"
                           accept="image/jpeg,image/jpg,image/png"
                           aria-label="<?php esc_attr_e('Upload payment slip', 'scanandpay-n8n'); ?>"
                           data-max-size="<?php echo esc_attr($this->max_file_size); ?>" />
                    <div class="san8n-upload-preview" style="display:none;">
                        <img id="san8n-preview-image" src="" alt="<?php esc_attr_e('Slip preview', 'scanandpay-n8n'); ?>" />
                        <button type="button" class="san8n-remove-slip">
                            <?php esc_html_e('Remove', 'scanandpay-n8n'); ?>
                        </button>
                    </div>
                    <div class="san8n-upload-info">
                        <?php 
                        echo sprintf(
                            /* translators: %d: max file size in MB */
                            __('Accepted formats: JPG, PNG (max %dMB)', 'scanandpay-n8n'),
                            $this->max_file_size / (1024 * 1024)
                        ); 
                        ?>
                    </div>
                </div>
            </div>

            <div class="san8n-verify-section form-row form-row-wide">
                <button type="button"
                        id="san8n-verify-button"
                        class="san8n-verify-button button alt"
                        disabled>
                    <?php esc_html_e('Verify Payment', 'scanandpay-n8n'); ?>
                </button>
                <div class="san8n-status-container" 
                     aria-live="polite" 
                     aria-atomic="true"
                     role="status">
                    <div class="san8n-status-message" style="display:none;"></div>
                    <div class="san8n-spinner" style="display:none;">
                        <span class="spinner is-active"></span>
                    </div>
                </div>
            </div>

            <input type="hidden" id="san8n-session-token" name="san8n_session_token" value="<?php echo esc_attr($session_token); ?>" />
            <input type="hidden" id="san8n-approval-status" name="san8n_approval_status" value="" />
            <input type="hidden" id="san8n-reference-id" name="san8n_reference_id" value="" />
            
            <?php if ($this->auto_place_order_classic): ?>
            <input type="hidden" id="san8n-auto-submit" value="1" data-delay="<?php echo esc_attr($this->prevent_double_submit_ms); ?>" />
            <?php endif; ?>
        </fieldset>
        <?php
    }

    public function payment_scripts() {
        if (!is_cart() && !is_checkout() && !isset($_GET['pay_for_order'])) {
            return;
        }

        if ('no' === $this->enabled) {
            return;
        }

        // Register and enqueue styles
        wp_register_style(
            'san8n-checkout',
            SAN8N_PLUGIN_URL . 'assets/css/checkout.css',
            array(),
            SAN8N_VERSION
        );
        wp_enqueue_style('san8n-checkout');

        // Register and enqueue scripts
        wp_register_script(
            'san8n-checkout-inline',
            SAN8N_PLUGIN_URL . 'assets/js/checkout-inline.js',
            array('jquery', 'wc-checkout'),
            SAN8N_VERSION,
            true
        );

        wp_localize_script('san8n-checkout-inline', 'san8n_params', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'rest_url' => rest_url(SAN8N_REST_NAMESPACE),
            'nonce' => wp_create_nonce('wp_rest'),
            'gateway_id' => $this->id,
            'auto_submit' => $this->auto_place_order_classic,
            'prevent_double_submit_ms' => $this->prevent_double_submit_ms,
            'i18n' => array(
                'verifying' => __('Verifying payment...', 'scanandpay-n8n'),
                'approved' => __('Payment approved! Processing order...', 'scanandpay-n8n'),
                'rejected' => __('Payment rejected. Please try again.', 'scanandpay-n8n'),
                'error' => __('Verification error. Please try again.', 'scanandpay-n8n'),
                'file_too_large' => __('File size exceeds limit.', 'scanandpay-n8n'),
                'invalid_file_type' => __('Invalid file type. Please upload JPG or PNG.', 'scanandpay-n8n'),
                'upload_required' => __('Please upload a payment slip.', 'scanandpay-n8n'),
                'processing_order' => __('Processing order...', 'scanandpay-n8n'),
                'verify_payment' => __('Verify Payment', 'scanandpay-n8n')
            )
        ));

        wp_enqueue_script('san8n-checkout-inline');
    }

    public function validate_fields() {
        $approval_status = isset($_POST['san8n_approval_status']) ? sanitize_text_field($_POST['san8n_approval_status']) : '';
        
        if ($approval_status !== 'approved') {
            wc_add_notice(__('Payment verification is required before placing the order.', 'scanandpay-n8n'), 'error');
            return false;
        }

        // Validate session flag
        if (!WC()->session->get(SAN8N_SESSION_FLAG)) {
            wc_add_notice(__('Payment session expired. Please verify payment again.', 'scanandpay-n8n'), 'error');
            return false;
        }

        return true;
    }

    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return array(
                'result' => 'fail',
                'messages' => __('Order not found.', 'scanandpay-n8n')
            );
        }

        // Check if payment was approved
        if (!WC()->session->get(SAN8N_SESSION_FLAG)) {
            return array(
                'result' => 'fail',
                'messages' => __('Payment not verified.', 'scanandpay-n8n')
            );
        }

        // Get verification data
        $reference_id = isset($_POST['san8n_reference_id']) ? sanitize_text_field($_POST['san8n_reference_id']) : '';
        $attachment_id = WC()->session->get('san8n_attachment_id');
        $approved_amount = WC()->session->get('san8n_approved_amount');

        // Save order meta
        $order->update_meta_data('_san8n_status', 'approved');
        $order->update_meta_data('_san8n_reference_id', $reference_id);
        $order->update_meta_data('_san8n_approved_amount', $approved_amount);
        $order->update_meta_data('_san8n_attachment_id', $attachment_id);
        $order->update_meta_data('_san8n_last_checked', current_time('mysql'));

        // Mark as paid
        $order->payment_complete($reference_id);
        
        // Add order note
        $order->add_order_note(
            sprintf(
                __('Payment approved via Scan & Pay (n8n). Reference: %s, Amount: %s THB', 'scanandpay-n8n'),
                $reference_id,
                wc_format_localized_price($approved_amount)
            )
        );

        // Clear session
        WC()->session->set(SAN8N_SESSION_FLAG, false);
        WC()->session->set('san8n_attachment_id', null);
        WC()->session->set('san8n_approved_amount', null);

        // Log success
        $this->logger->info('Payment processed successfully', array(
            'order_id' => $order_id,
            'reference_id' => $reference_id
        ));

        // Return success
        return array(
            'result' => 'success',
            'redirect' => $this->get_return_url($order)
        );
    }

    private function generate_session_token() {
        return wp_generate_password(32, false);
    }

    public function display_admin_order_meta($order) {
        // This will be handled by the admin class
    }
}
