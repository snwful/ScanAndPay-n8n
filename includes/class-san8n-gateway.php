<?php
/**
 * Main Gateway Class
 */

if (!defined('ABSPATH')) {
    exit;
}

// Bail out early if WooCommerce core gateway base is not available (prevents fatals in non-WP/IDE contexts)
if (!class_exists('WC_Payment_Gateway')) {
    return;
}

class SAN8N_Gateway extends WC_Payment_Gateway {
    // Public properties declared to satisfy IDEs when WooCommerce stubs are unavailable.
    public $id;
    public $icon;
    public $has_fields;
    public $method_title;
    public $method_description;
    public $supports = array();
    public $title;
    public $description;
    public $enabled;
    public $form_fields = array();
    private $logger;
    private $n8n_webhook_url;
    private $shared_secret;
    private $verifier_backend;
    private $laravel_verify_url;
    private $laravel_secret;
    private $auto_place_order_classic;
    private $blocks_mode;
    private $allow_blocks_autosubmit_experimental;
    private $show_express_only_when_approved;
    private $prevent_double_submit_ms;
    private $max_file_size;
    private $allowed_file_types;
    private $retention_days;
    private $log_level;
    private $qr_image_url;

    public function __construct() {
        $this->id = SAN8N_GATEWAY_ID;
        $this->icon = '';
        $this->has_fields = true;
        $this->method_title = $this->tr('Scan & Pay (n8n)');
        $this->method_description = $this->tr('PromptPay payment gateway with inline slip verification via n8n');
        $this->supports = array('products', 'refunds');

        // Load settings
        $this->init_form_fields();
        $this->init_settings();

        // Get settings
        $this->title = $this->get_option('title', $this->tr('Scan & Pay (n8n) — PromptPay'));
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->n8n_webhook_url = $this->get_option('n8n_webhook_url');
        $this->shared_secret = $this->get_option('shared_secret');
        $this->verifier_backend = $this->get_option('verifier_backend', 'n8n');
        $this->laravel_verify_url = $this->get_option('laravel_verify_url', '');
        $this->laravel_secret = $this->get_option('laravel_secret', '');
        $this->auto_place_order_classic = $this->get_option('auto_place_order_classic', 'yes') === 'yes';
        $this->blocks_mode = $this->get_option('blocks_mode', 'express');
        $this->allow_blocks_autosubmit_experimental = $this->get_option('allow_blocks_autosubmit_experimental', 'no') === 'yes';
        $this->show_express_only_when_approved = $this->get_option('show_express_only_when_approved', 'yes') === 'yes';
        $this->prevent_double_submit_ms = intval($this->get_option('prevent_double_submit_ms', '1500'));

        $this->max_file_size = intval($this->get_option('max_file_size', '5')) * 1024 * 1024; // Convert MB to bytes
        $this->allowed_file_types = array('jpg', 'jpeg', 'png');
        $this->retention_days = intval($this->get_option('retention_days', '30'));
        $this->log_level = $this->get_option('log_level', 'info');
        $this->qr_image_url = $this->get_option('qr_image_url', '');

        // Initialize logger
        $this->logger = new SAN8N_Logger();

        // Hooks
        if (is_callable('add_action')) {
            call_user_func('add_action', 'woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            call_user_func('add_action', 'wp_enqueue_scripts', array($this, 'payment_scripts'));
            call_user_func('add_action', 'woocommerce_admin_order_data_after_billing_address', array($this, 'display_admin_order_meta'), 10, 1);
        }
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => $this->tr('Enable/Disable'),
                'type' => 'checkbox',
                'label' => $this->tr('Enable Scan & Pay (n8n)'),
                'default' => 'no'
            ),
            'title' => array(
                'title' => $this->tr('Title'),
                'type' => 'text',
                'description' => $this->tr('This controls the title which the user sees during checkout.'),
                'default' => $this->tr('Scan & Pay (n8n) — PromptPay'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => $this->tr('Description'),
                'type' => 'textarea',
                'description' => $this->tr('Payment method description that the customer will see on your checkout.'),
                'default' => $this->tr('Scan PromptPay QR code and upload payment slip for instant verification.'),
                'desc_tip' => true,
            ),
            'verifier_settings' => array(
                'title' => $this->tr('Verification Backend'),
                'type' => 'title',
                'description' => $this->tr('Choose which backend to use for slip verification and configure its credentials.'),
            ),
            'verifier_backend' => array(
                'title' => $this->tr('Backend'),
                'type' => 'select',
                'description' => $this->tr('Select the verification backend.'),
                'default' => 'n8n',
                'desc_tip' => true,
                'options' => array(
                    'n8n' => $this->tr('n8n'),
                    'laravel' => $this->tr('Laravel')
                )
            ),
            'n8n_webhook_url' => array(
                'title' => $this->tr('n8n Webhook URL'),
                'type' => 'text',
                'description' => $this->tr('The HTTPS webhook URL for n8n verification service.'),
                'desc_tip' => true,
                'placeholder' => 'https://your-n8n-instance.com/webhook/verify-slip',
                'custom_attributes' => array('required' => 'required')
            ),
            'shared_secret' => array(
                'title' => $this->tr('Shared Secret'),
                'type' => 'password',
                'description' => $this->tr('Shared secret for HMAC signature verification.'),
                'desc_tip' => true,
                'custom_attributes' => array('required' => 'required')
            ),
            'laravel_verify_url' => array(
                'title' => $this->tr('Laravel Verify URL'),
                'type' => 'text',
                'description' => $this->tr('The HTTPS verify endpoint URL of your Laravel backend.'),
                'desc_tip' => true,
                'placeholder' => 'https://your-laravel-app.com/api/verify-slip'
            ),
            'laravel_secret' => array(
                'title' => $this->tr('Laravel Secret'),
                'type' => 'password',
                'description' => $this->tr('Shared secret for your Laravel backend HMAC signature verification.'),
                'desc_tip' => true,
            ),
            'qr_image_url' => array(
                'title' => $this->tr('QR Image'),
                'type' => 'text',
                'description' => $this->tr('Select a static image (via Media Library) to display instead of a PromptPay QR code. Leave empty to use the default placeholder.'),
                'default' => '',
                'desc_tip' => true,
            ),
            'classic_settings' => array(
                'title' => $this->tr('Classic Checkout Settings'),
                'type' => 'title',
                'description' => '',
            ),
            'auto_place_order_classic' => array(
                'title' => $this->tr('Auto-Submit Order (Classic)'),
                'type' => 'checkbox',
                'label' => $this->tr('Automatically place order after approval (recommended)'),
                'description' => $this->tr('When payment is approved, automatically submit the order without requiring additional click.'),
                'default' => 'yes',
                'desc_tip' => true,
            ),
            'prevent_double_submit_ms' => array(
                'title' => $this->tr('Double-Submit Prevention (ms)'),
                'type' => 'number',
                'description' => $this->tr('Milliseconds to prevent double submission after auto-submit.'),
                'default' => '1500',
                'desc_tip' => true,
                'custom_attributes' => array('min' => '500', 'max' => '5000')
            ),
            'blocks_settings' => array(
                'title' => $this->tr('Blocks Checkout Settings'),
                'type' => 'title',
                'description' => '',
            ),
            'blocks_mode' => array(
                'title' => $this->tr('Blocks Mode'),
                'type' => 'select',
                'description' => $this->tr('Choose how the payment gateway behaves in Blocks checkout.'),
                'default' => 'express',
                'desc_tip' => true,
                'options' => array(
                    'express' => $this->tr('Express Button (recommended)'),
                    'autosubmit_experimental' => $this->tr('Auto-Submit (experimental)'),
                    'none' => $this->tr('Standard behavior')
                )
            ),
            'allow_blocks_autosubmit_experimental' => array(
                'title' => $this->tr('Enable Experimental Auto-Submit'),
                'type' => 'checkbox',
                'label' => $this->tr('Allow experimental auto-submit in Blocks (may break in future)'),
                'description' => $this->tr('⚠️ WARNING: This feature is experimental and may break with WooCommerce updates.'),
                'default' => 'no',
                'desc_tip' => false,
            ),
            'show_express_only_when_approved' => array(
                'title' => $this->tr('Show Express Button Only When Approved'),
                'type' => 'checkbox',
                'label' => $this->tr('Hide express button until payment is approved'),
                'default' => 'yes',
                'desc_tip' => true,
            ),
            'file_settings' => array(
                'title' => $this->tr('File Upload Settings'),
                'type' => 'title',
                'description' => '',
            ),
            'max_file_size' => array(
                'title' => $this->tr('Max File Size (MB)'),
                'type' => 'number',
                'description' => $this->tr('Maximum allowed file size for slip upload.'),
                'default' => '5',
                'desc_tip' => true,
                'custom_attributes' => array('min' => '1', 'max' => '10')
            ),
            'retention_days' => array(
                'title' => $this->tr('Retention Days'),
                'type' => 'number',
                'description' => $this->tr('Number of days to keep uploaded slips before automatic deletion.'),
                'default' => '30',
                'desc_tip' => true,
                'custom_attributes' => array('min' => '7', 'max' => '365')
            ),
            'advanced_settings' => array(
                'title' => $this->tr('Advanced Settings'),
                'type' => 'title',
                'description' => '',
            ),
            'log_level' => array(
                'title' => $this->tr('Log Level'),
                'type' => 'select',
                'description' => $this->tr('Set the logging level for debugging.'),
                'default' => 'info',
                'desc_tip' => true,
                'options' => array(
                    'emergency' => $this->tr('Emergency'),
                    'alert' => $this->tr('Alert'),
                    'critical' => $this->tr('Critical'),
                    'error' => $this->tr('Error'),
                    'warning' => $this->tr('Warning'),
                    'notice' => $this->tr('Notice'),
                    'info' => $this->tr('Info'),
                    'debug' => $this->tr('Debug')
                )
            ),
            'test_webhook' => array(
                'title' => $this->tr('Test Backend'),
                'type' => 'button',
                'description' => $this->tr('Send a test ping to the selected backend to verify connectivity.'),
                'desc_tip' => true,
                'custom_attributes' => array(
                    'onclick' => 'san8n_test_webhook(); return false;'
                )
            )
        );
    }

    public function payment_fields() {
        if ($this->description) {
            $desc = $this->description;
            if (is_callable('wp_kses_post')) {
                $desc = call_user_func('wp_kses_post', $desc);
            }
            if (is_callable('wpautop')) {
                echo call_user_func('wpautop', $desc);
            } else {
                echo '<p>' . $desc . '</p>';
            }
        }

        $order_total = 0.0;
        $wc = is_callable('WC') ? call_user_func('WC') : null;
        if ($wc && isset($wc->cart)) {
            $cart = $wc->cart;
            if (is_object($cart) && method_exists($cart, 'get_total')) {
                $raw_total = $cart->get_total('edit');
                // Normalize numeric value from potential formatted string
                $raw_str = (string) $raw_total;
                if (strpos($raw_str, ',') !== false && strpos($raw_str, '.') === false) {
                    // Likely using comma as decimal separator
                    $raw_str = str_replace(',', '.', $raw_str);
                } else {
                    // Remove thousands separators
                    $raw_str = str_replace(',', '', $raw_str);
                }
                $order_total = is_numeric($raw_str) ? (float) $raw_str : 0.0;
            }
        }
        $session_token = $this->generate_session_token();
        
        ?>
        <fieldset id="san8n-payment-fields" class="san8n-payment-container wc-payment-form" style="background:transparent;">
            <div class="san8n-qr-section form-row form-row-wide">
                <h4><?php echo (is_callable('esc_html') ? call_user_func('esc_html', (is_callable('__') ? call_user_func('__', 'Step 1: Scan QR Code', 'scanandpay-n8n') : 'Step 1: Scan QR Code')) : 'Step 1: Scan QR Code'); ?></h4>
                <div class="san8n-qr-container">
                    <div class="san8n-qr-placeholder">
                        <?php
                        $custom_img = (string) $this->qr_image_url;
                        $img_src = !empty($custom_img) ? $custom_img : (SAN8N_PLUGIN_URL . 'assets/images/qr-placeholder.svg');
                        $img_src_attr = is_callable('esc_url') ? call_user_func('esc_url', $img_src) : $img_src;
                        $alt_src = is_callable('__') ? call_user_func('__', 'QR code image', 'scanandpay-n8n') : 'QR code image';
                        $alt_attr = is_callable('esc_attr') ? call_user_func('esc_attr', $alt_src) : htmlspecialchars($alt_src, ENT_QUOTES, 'UTF-8');
                        echo '<img src="' . $img_src_attr . '" alt="' . $alt_attr . '" class="san8n-qr-placeholder" />';
                        ?>
                    </div>
                    <div class="san8n-amount-display">
                        <?php
                        $amount_label = is_callable('__') ? call_user_func('__', 'Amount: %s THB', 'scanandpay-n8n') : 'Amount: %s THB';
                        echo sprintf(
                            /* translators: %s: order amount */
                            $amount_label,
                            (is_callable('wc_format_localized_price') ? call_user_func('wc_format_localized_price', $order_total) : number_format((float) $order_total, 2))
                        );
                        ?>
                    </div>
                </div>
            </div>

            <div class="san8n-upload-section form-row form-row-wide">
                <h4><?php echo (is_callable('esc_html') ? call_user_func('esc_html', (is_callable('__') ? call_user_func('__', 'Step 2: Upload Payment Slip', 'scanandpay-n8n') : 'Step 2: Upload Payment Slip')) : 'Step 2: Upload Payment Slip'); ?></h4>
                <div class="san8n-upload-container">
                    <input type="file" 
                           id="san8n-slip-upload" 
                           name="san8n_slip_upload"
                           accept="image/jpeg,image/jpg,image/png"
                           aria-label="<?php echo (is_callable('esc_attr') ? call_user_func('esc_attr', (is_callable('__') ? call_user_func('__', 'Upload payment slip', 'scanandpay-n8n') : 'Upload payment slip')) : 'Upload payment slip'); ?>"
                           data-max-size="<?php echo (is_callable('esc_attr') ? call_user_func('esc_attr', $this->max_file_size) : htmlspecialchars((string) $this->max_file_size, ENT_QUOTES, 'UTF-8')); ?>" />
                    <div class="san8n-upload-preview" style="display:none;">
                        <img id="san8n-preview-image" src="" alt="<?php echo (is_callable('esc_attr') ? call_user_func('esc_attr', (is_callable('__') ? call_user_func('__', 'Slip preview', 'scanandpay-n8n') : 'Slip preview')) : 'Slip preview'); ?>" />
                        <button type="button" class="san8n-remove-slip">
                            <?php echo (is_callable('esc_html') ? call_user_func('esc_html', (is_callable('__') ? call_user_func('__', 'Remove', 'scanandpay-n8n') : 'Remove')) : 'Remove'); ?>
                        </button>
                    </div>
                    <div class="san8n-upload-info">
                        <?php 
                        $formats_label = is_callable('__') ? call_user_func('__', 'Accepted formats: JPG, PNG (max %dMB)', 'scanandpay-n8n') : 'Accepted formats: JPG, PNG (max %dMB)';
                        echo sprintf(
                            /* translators: %d: max file size in MB */
                            $formats_label,
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
                    <?php echo (is_callable('esc_html') ? call_user_func('esc_html', (is_callable('__') ? call_user_func('__', 'Verify Payment', 'scanandpay-n8n') : 'Verify Payment')) : 'Verify Payment'); ?>
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

            <input type="hidden" id="san8n-session-token" name="san8n_session_token" value="<?php echo (is_callable('esc_attr') ? call_user_func('esc_attr', $session_token) : htmlspecialchars((string) $session_token, ENT_QUOTES, 'UTF-8')); ?>" />
            <input type="hidden" id="san8n-approval-status" name="san8n_approval_status" value="" />
            <input type="hidden" id="san8n-reference-id" name="san8n_reference_id" value="" />
            
            <?php if ($this->auto_place_order_classic): ?>
            <input type="hidden" id="san8n-auto-submit" value="1" data-delay="<?php echo (is_callable('esc_attr') ? call_user_func('esc_attr', $this->prevent_double_submit_ms) : htmlspecialchars((string) $this->prevent_double_submit_ms, ENT_QUOTES, 'UTF-8')); ?>" />
            <?php endif; ?>
        </fieldset>
        <?php
    }

    public function payment_scripts() {
        $in_cart_or_checkout = ((is_callable('is_cart') ? call_user_func('is_cart') : false) || (is_callable('is_checkout') ? call_user_func('is_checkout') : false) || isset($_GET['pay_for_order']));
        if (!$in_cart_or_checkout) {
            return;
        }

        if ('no' === $this->enabled) {
            return;
        }

        // Register and enqueue styles
        if (is_callable('wp_register_style')) {
            call_user_func(
                'wp_register_style',
                'san8n-checkout',
                SAN8N_PLUGIN_URL . 'assets/css/checkout.css',
                array(),
                SAN8N_VERSION
            );
        }
        if (is_callable('wp_enqueue_style')) {
            call_user_func('wp_enqueue_style', 'san8n-checkout');
        }

        // No PromptPay assets are enqueued; QR is a static image selected in settings.

        // Register and enqueue scripts
        if (is_callable('wp_register_script')) {
            call_user_func(
                'wp_register_script',
                'san8n-checkout-inline',
                SAN8N_PLUGIN_URL . 'assets/js/checkout-inline.js',
                array('jquery', 'wc-checkout'),
                SAN8N_VERSION,
                true
            );
        }

        if (is_callable('wp_localize_script')) {
            call_user_func('wp_localize_script', 'san8n-checkout-inline', 'san8n_params', array(
                'ajax_url' => (is_callable('admin_url') ? call_user_func('admin_url', 'admin-ajax.php') : ''),
                'rest_url' => (is_callable('rest_url') ? call_user_func('rest_url', SAN8N_REST_NAMESPACE) : ''),
                'nonce' => (is_callable('wp_create_nonce') ? call_user_func('wp_create_nonce', 'wp_rest') : ''),
                'gateway_id' => $this->id,
                'auto_submit' => $this->auto_place_order_classic,
                'prevent_double_submit_ms' => $this->prevent_double_submit_ms,
                'i18n' => array(
                    'verifying' => (is_callable('__') ? call_user_func('__', 'Verifying payment...', 'scanandpay-n8n') : 'Verifying payment...'),
                    'approved' => (is_callable('__') ? call_user_func('__', 'Payment approved! Processing order...', 'scanandpay-n8n') : 'Payment approved! Processing order...'),
                    'rejected' => (is_callable('__') ? call_user_func('__', 'Payment rejected. Please try again.', 'scanandpay-n8n') : 'Payment rejected. Please try again.'),
                    'error' => (is_callable('__') ? call_user_func('__', 'Verification error. Please try again.', 'scanandpay-n8n') : 'Verification error. Please try again.'),
                    'service_unavailable' => (is_callable('__') ? call_user_func('__', 'Verification service unavailable. Please try again.', 'scanandpay-n8n') : 'Verification service unavailable. Please try again.'),
                    'file_too_large' => (is_callable('__') ? call_user_func('__', 'File size exceeds limit.', 'scanandpay-n8n') : 'File size exceeds limit.'),
                    'invalid_file_type' => (is_callable('__') ? call_user_func('__', 'Invalid file type. Please upload JPG or PNG.', 'scanandpay-n8n') : 'Invalid file type. Please upload JPG or PNG.'),
                    'upload_required' => (is_callable('__') ? call_user_func('__', 'Please upload a payment slip.', 'scanandpay-n8n') : 'Please upload a payment slip.'),
                    'verify_required' => (is_callable('__') ? call_user_func('__', 'Please verify your payment before placing the order.', 'scanandpay-n8n') : 'Please verify your payment before placing the order.'),
                    'processing_order' => (is_callable('__') ? call_user_func('__', 'Processing order...', 'scanandpay-n8n') : 'Processing order...'),
                    'verify_payment' => (is_callable('__') ? call_user_func('__', 'Verify Payment', 'scanandpay-n8n') : 'Verify Payment')
                )
            ));
        }

        if (is_callable('wp_enqueue_script')) {
            call_user_func('wp_enqueue_script', 'san8n-checkout-inline');
        }
    }

    public function validate_fields() {
        $raw_status = isset($_POST['san8n_approval_status']) ? $_POST['san8n_approval_status'] : '';
        $approval_status = is_callable('sanitize_text_field') ? call_user_func('sanitize_text_field', $raw_status) : (string) $raw_status;
        
        if ($approval_status !== 'approved') {
            if (is_callable('wc_add_notice')) {
                call_user_func('wc_add_notice', (is_callable('__') ? call_user_func('__', 'Payment verification is required before placing the order.', 'scanandpay-n8n') : 'Payment verification is required before placing the order.'), 'error');
            }
            return false;
        }

        // Validate session flag when WC session is available
        $wc = is_callable('WC') ? call_user_func('WC') : null;
        if ($wc && isset($wc->session) && method_exists($wc->session, 'get')) {
            if (!$wc->session->get(SAN8N_SESSION_FLAG)) {
                // Fallback: try transient by session token
                $raw_tok = isset($_POST['san8n_session_token']) ? $_POST['san8n_session_token'] : '';
                $session_token = is_callable('sanitize_text_field') ? call_user_func('sanitize_text_field', $raw_tok) : (string) $raw_tok;
                $transient_key = 'san8n_tok_' . hash('sha256', (string) $session_token);
                $t = is_callable('get_transient') ? call_user_func('get_transient', $transient_key) : false;
                if (is_array($t) && !empty($t['approved'])) {
                    // Restore session state from transient
                    if (method_exists($wc->session, 'set')) {
                        $wc->session->set(SAN8N_SESSION_FLAG, true);
                        $wc->session->set('san8n_attachment_id', isset($t['attachment_id']) ? $t['attachment_id'] : null);
                        $wc->session->set('san8n_approved_amount', isset($t['approved_amount']) ? $t['approved_amount'] : null);
                    }
                } else {
                    if (is_callable('wc_add_notice')) {
                        call_user_func('wc_add_notice', (is_callable('__') ? call_user_func('__', 'Payment session expired. Please verify payment again.', 'scanandpay-n8n') : 'Payment session expired. Please verify payment again.'), 'error');
                    }
                    return false;
                }
            }
        }

        return true;
    }

    public function process_payment($order_id) {
        $order = is_callable('wc_get_order') ? call_user_func('wc_get_order', $order_id) : null;
        
        if (!$order) {
            return array(
                'result' => 'fail',
                'messages' => $this->tr('Order not found.')
            );
        }

        // Check if payment was approved (only when WC session is available)
        $wc = is_callable('WC') ? call_user_func('WC') : null;
        if ($wc && isset($wc->session) && method_exists($wc->session, 'get')) {
            if (!$wc->session->get(SAN8N_SESSION_FLAG)) {
                // Fallback: try transient by session token
                $raw_tok = isset($_POST['san8n_session_token']) ? $_POST['san8n_session_token'] : '';
                $session_token = is_callable('sanitize_text_field') ? call_user_func('sanitize_text_field', $raw_tok) : (string) $raw_tok;
                $transient_key = 'san8n_tok_' . hash('sha256', (string) $session_token);
                $t = is_callable('get_transient') ? call_user_func('get_transient', $transient_key) : false;
                if (is_array($t) && !empty($t['approved'])) {
                    if (method_exists($wc->session, 'set')) {
                        $wc->session->set(SAN8N_SESSION_FLAG, true);
                        $wc->session->set('san8n_attachment_id', isset($t['attachment_id']) ? $t['attachment_id'] : null);
                        $wc->session->set('san8n_approved_amount', isset($t['approved_amount']) ? $t['approved_amount'] : null);
                    }
                } else {
                    return array(
                        'result' => 'fail',
                        'messages' => $this->tr('Payment not verified.')
                    );
                }
            }
        }

        // Get verification data
        $raw_ref = isset($_POST['san8n_reference_id']) ? $_POST['san8n_reference_id'] : '';
        $reference_id = is_callable('sanitize_text_field') ? call_user_func('sanitize_text_field', $raw_ref) : (string) $raw_ref;
        $attachment_id = null;
        $approved_amount = null;
        $wc = is_callable('WC') ? call_user_func('WC') : null;
        if ($wc && isset($wc->session) && method_exists($wc->session, 'get')) {
            $attachment_id = $wc->session->get('san8n_attachment_id');
            $approved_amount = $wc->session->get('san8n_approved_amount');
        }
        // If reference_id is missing, attempt to pull from transient as a fallback
        if (empty($reference_id)) {
            $raw_tok = isset($_POST['san8n_session_token']) ? $_POST['san8n_session_token'] : '';
            $session_token = is_callable('sanitize_text_field') ? call_user_func('sanitize_text_field', $raw_tok) : (string) $raw_tok;
            $transient_key = 'san8n_tok_' . hash('sha256', (string) $session_token);
            $t = is_callable('get_transient') ? call_user_func('get_transient', $transient_key) : false;
            if (is_array($t) && isset($t['reference_id'])) {
                $reference_id = (string) $t['reference_id'];
            }
        }

        // Save order meta
        if (method_exists($order, 'update_meta_data')) {
            $order->update_meta_data('_san8n_status', 'approved');
            $order->update_meta_data('_san8n_reference_id', $reference_id);
            $order->update_meta_data('_san8n_approved_amount', $approved_amount);
            $order->update_meta_data('_san8n_attachment_id', $attachment_id);
            if (is_callable('current_time')) {
                $order->update_meta_data('_san8n_last_checked', call_user_func('current_time', 'mysql'));
            }
        }

        // Mark as paid
        if (method_exists($order, 'payment_complete')) {
            $order->payment_complete($reference_id);
        }
        
        // Add order note
        if (method_exists($order, 'add_order_note')) {
            $formatted_amount = is_callable('wc_format_localized_price') ? call_user_func('wc_format_localized_price', $approved_amount) : number_format((float) $approved_amount, 2);
            $order->add_order_note(
                sprintf(
                    $this->tr('Payment approved via Scan & Pay (n8n). Reference: %s, Amount: %s THB'),
                    $reference_id,
                    $formatted_amount
                )
            );
        }

        // Clear session
        $wc = is_callable('WC') ? call_user_func('WC') : null;
        if ($wc && isset($wc->session) && method_exists($wc->session, 'set')) {
            $wc->session->set(SAN8N_SESSION_FLAG, false);
            $wc->session->set('san8n_attachment_id', null);
            $wc->session->set('san8n_approved_amount', null);
        }

        // Clear approval transient tied to session token
        $raw_tok = isset($_POST['san8n_session_token']) ? $_POST['san8n_session_token'] : '';
        $session_token = is_callable('sanitize_text_field') ? call_user_func('sanitize_text_field', $raw_tok) : (string) $raw_tok;
        if (!empty($session_token)) {
            $transient_key = 'san8n_tok_' . hash('sha256', (string) $session_token);
            if (is_callable('delete_transient')) {
                call_user_func('delete_transient', $transient_key);
            }
        }

        // Log success
        if ($this->logger && method_exists($this->logger, 'info')) {
            $this->logger->info('Payment processed successfully', array(
                'order_id' => $order_id,
                'reference_id' => $reference_id
            ));
        }

        // Return success
        return array(
            'result' => 'success',
            'redirect' => $this->get_return_url($order)
        );
    }

    private function generate_session_token() {
        if (is_callable('wp_generate_password')) {
            return call_user_func('wp_generate_password', 32, false);
        }
        try {
            return bin2hex(random_bytes(16));
        } catch (Exception $e) {
            return substr(md5(uniqid((string) mt_rand(), true)), 0, 32);
        }
    }

    private function tr($text) {
        return is_callable('__') ? call_user_func('__', $text, 'scanandpay-n8n') : $text;
    }

    public function display_admin_order_meta($order) {
        // This will be handled by the admin class
    }
}
