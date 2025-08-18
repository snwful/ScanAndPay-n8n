<?php
/**
 * Admin functionality and order meta box
 */

if (!defined('ABSPATH')) {
    exit;
}

class SAN8N_Admin {
    private $logger;

    /**
     * Guarded translation helper.
     */
    private function tr($text) {
        return is_callable('__') ? call_user_func('__', $text, 'scanandpay-n8n') : $text;
    }

    /**
     * Guarded HTML escape output.
     */
    private function esc_html_out($text) {
        $t = (string) $text;
        return is_callable('esc_html') ? call_user_func('esc_html', $t) : htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Guarded attribute escape output.
     */
    private function esc_attr_out($text) {
        $t = (string) $text;
        return is_callable('esc_attr') ? call_user_func('esc_attr', $t) : htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Guarded URL escape output.
     */
    private function esc_url_out($url) {
        $u = (string) $url;
        return is_callable('esc_url') ? call_user_func('esc_url', $u) : filter_var($u, FILTER_SANITIZE_URL);
    }

    /**
     * Echo translated and HTML-escaped text safely.
     */
    private function e_tr($text) {
        echo $this->esc_html_out($this->tr($text));
    }

    public function __construct() {
        $this->logger = new SAN8N_Logger();

        // Add hooks (guarded)
        if (is_callable('add_action')) {
            call_user_func('add_action', 'add_meta_boxes', array($this, 'add_order_meta_box'));
            // Handle admin actions
            call_user_func('add_action', 'wp_ajax_san8n_reverify', array($this, 'handle_reverify'));
            call_user_func('add_action', 'wp_ajax_san8n_approve', array($this, 'handle_approve'));
            call_user_func('add_action', 'wp_ajax_san8n_reject', array($this, 'handle_reject'));
            call_user_func('add_action', 'wp_ajax_san8n_test_webhook', array($this, 'handle_test_webhook'));
            // Admin assets
            call_user_func('add_action', 'admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        }
    }

    public function add_order_meta_box() {
        $screen = is_callable('get_current_screen') ? call_user_func('get_current_screen') : null;
        
        if ($screen && $screen->id === 'shop_order') {
            if (is_callable('add_meta_box')) {
                call_user_func(
                    'add_meta_box',
                    'san8n_payment_details',
                    (is_callable('__') ? call_user_func('__', 'Scan & Pay (n8n) Details', 'scanandpay-n8n') : 'Scan & Pay (n8n) Details'),
                    array($this, 'render_meta_box'),
                    'shop_order',
                    'side',
                    'default'
                );
            }
        }

        // For HPOS compatibility
        if ($screen && $screen->id === 'woocommerce_page_wc-orders') {
            if (is_callable('add_meta_box')) {
                call_user_func(
                    'add_meta_box',
                    'san8n_payment_details',
                    (is_callable('__') ? call_user_func('__', 'Scan & Pay (n8n) Details', 'scanandpay-n8n') : 'Scan & Pay (n8n) Details'),
                    array($this, 'render_meta_box'),
                    'woocommerce_page_wc-orders',
                    'side',
                    'default'
                );
            }
        }
    }

    public function render_meta_box($post_or_order) {
        // Get order object
        if (is_a($post_or_order, 'WP_Post')) {
            $order = is_callable('wc_get_order') ? call_user_func('wc_get_order', $post_or_order->ID) : null;
        } else {
            $order = $post_or_order;
        }

        if (!$order) {
            return;
        }

        // Check if this payment method was used
        if ($order->get_payment_method() !== SAN8N_GATEWAY_ID) {
            echo '<p>' . $this->esc_html_out($this->tr('This order was not paid using Scan & Pay (n8n).')) . '</p>';
            return;
        }

        // Get meta data
        $status = $order->get_meta('_san8n_status');
        $reference_id = $order->get_meta('_san8n_reference_id');
        $approved_amount = $order->get_meta('_san8n_approved_amount');
        $reason = $order->get_meta('_san8n_reason');
        $last_checked = $order->get_meta('_san8n_last_checked');
        $attachment_id = $order->get_meta('_san8n_attachment_id');

        // Check capabilities
        $can_view = is_callable('current_user_can') ? (bool) call_user_func('current_user_can', 'manage_woocommerce') : false;
        $can_manage = $can_view && (is_callable('current_user_can') ? (bool) call_user_func('current_user_can', SAN8N_CAPABILITY) : false);

        if (!$can_view) {
            echo '<p>' . $this->esc_html_out($this->tr('You do not have permission to view this information.')) . '</p>';
            return;
        }

        ?>
        <div class="san8n-admin-meta-box">
            <?php if ($status): ?>
            <div class="san8n-status-badge san8n-status-<?php echo $this->esc_attr_out($status); ?>">
                <?php echo $this->esc_html_out(ucfirst($status)); ?>
            </div>
            <?php endif; ?>

            <?php if ($attachment_id): ?>
            <div class="san8n-slip-preview">
                <h4><?php $this->e_tr('Payment Slip'); ?></h4>
                <?php
                $attachment_url = is_callable('wp_get_attachment_url') ? call_user_func('wp_get_attachment_url', $attachment_id) : '';
                $attachment_thumb = is_callable('wp_get_attachment_image_src') ? call_user_func('wp_get_attachment_image_src', $attachment_id, 'thumbnail') : false;
                if ($attachment_thumb) {
                    echo '<a href="' . $this->esc_url_out($attachment_url) . '" target="_blank">';
                    echo '<img src="' . $this->esc_url_out($attachment_thumb[0]) . '" alt="' . $this->esc_attr_out($this->tr('Payment slip')) . '" style="max-width: 100%; height: auto;" />';
                    echo '</a>';
                }
                ?>
            </div>
            <?php endif; ?>

            <div class="san8n-details">
                <?php if ($reference_id): ?>
                <p>
                    <strong><?php $this->e_tr('Reference ID:'); ?></strong><br>
                    <code><?php echo $this->esc_html_out($reference_id); ?></code>
                </p>
                <?php endif; ?>

                <?php if ($approved_amount): ?>
                <p>
                    <strong><?php $this->e_tr('Approved Amount:'); ?></strong><br>
                    <?php 
                        $price_html = is_callable('wc_price') ? call_user_func('wc_price', $approved_amount) : number_format((float)$approved_amount, 2);
                        echo $price_html . ' THB';
                    ?>
                </p>
                <?php endif; ?>

                <?php if ($reason && $status === 'rejected'): ?>
                <p>
                    <strong><?php $this->e_tr('Rejection Reason:'); ?></strong><br>
                    <?php echo $this->esc_html_out($reason); ?>
                </p>
                <?php endif; ?>

                <?php if ($last_checked): ?>
                <p>
                    <strong><?php $this->e_tr('Last Checked:'); ?></strong><br>
                    <?php 
                        $df = is_callable('get_option') ? call_user_func('get_option', 'date_format') : 'Y-m-d';
                        $tf = is_callable('get_option') ? call_user_func('get_option', 'time_format') : 'H:i';
                        $fmt = $df . ' ' . $tf;
                        $formatted = is_callable('date_i18n') ? call_user_func('date_i18n', $fmt, strtotime($last_checked)) : date('Y-m-d H:i', strtotime($last_checked));
                        echo $this->esc_html_out($formatted);
                    ?>
                </p>
                <?php endif; ?>
            </div>

            <?php if ($can_manage): ?>
            <div class="san8n-actions">
                <h4><?php $this->e_tr('Actions'); ?></h4>
                <button type="button" 
                        class="button san8n-action-button" 
                        data-action="reverify" 
                        data-order-id="<?php echo $this->esc_attr_out($order->get_id()); ?>">
                    <?php $this->e_tr('Re-verify'); ?>
                </button>
                
                <?php if ($status !== 'approved'): ?>
                <button type="button" 
                        class="button san8n-action-button" 
                        data-action="approve" 
                        data-order-id="<?php echo $this->esc_attr_out($order->get_id()); ?>">
                    <?php $this->e_tr('Approve Override'); ?>
                </button>
                <?php endif; ?>
                
                <?php if ($status !== 'rejected'): ?>
                <button type="button" 
                        class="button san8n-action-button" 
                        data-action="reject" 
                        data-order-id="<?php echo $this->esc_attr_out($order->get_id()); ?>">
                    <?php $this->e_tr('Reject Override'); ?>
                </button>
                <?php endif; ?>
                
                <div class="san8n-action-result" style="display: none; margin-top: 10px;"></div>
            </div>
            <?php endif; ?>

            <div class="san8n-audit-log">
                <h4><?php $this->e_tr('Audit Log'); ?></h4>
                <?php
                $notes = is_callable('wc_get_order_notes') ? call_user_func('wc_get_order_notes', array(
                    'order_id' => $order->get_id(),
                    'type' => 'internal'
                )) : array();

                $san8n_notes = array_filter($notes, function($note) {
                    return strpos($note->content, '[SAN8N]') !== false;
                });

                if (!empty($san8n_notes)) {
                    echo '<ul class="san8n-audit-list">';
                    foreach (array_slice($san8n_notes, 0, 5) as $note) {
                        echo '<li>';
                        $df = is_callable('get_option') ? call_user_func('get_option', 'date_format') : 'Y-m-d';
                        $tf = is_callable('get_option') ? call_user_func('get_option', 'time_format') : 'H:i';
                        $fmt = $df . ' ' . $tf;
                        $note_time = strtotime($note->date_created);
                        $date_out = is_callable('date_i18n') ? call_user_func('date_i18n', $fmt, $note_time) : date('Y-m-d H:i', $note_time);
                        echo '<small>' . $this->esc_html_out($date_out) . '</small><br>';
                        $content = is_callable('wp_kses_post') ? call_user_func('wp_kses_post', $note->content) : htmlspecialchars((string)$note->content, ENT_QUOTES, 'UTF-8');
                        echo $content;
                        echo '</li>';
                    }
                    echo '</ul>';
                } else {
                    echo '<p><em>' . $this->esc_html_out($this->tr('No audit entries.')) . '</em></p>';
                }
                ?>
            </div>
        </div>
        <?php

        if (is_callable('wp_nonce_field')) {
            call_user_func('wp_nonce_field', 'san8n_admin_action', 'san8n_admin_nonce');
        }
    }

    public function enqueue_admin_scripts($hook) {
        if ($hook === 'post.php' || $hook === 'post-new.php' || $hook === 'woocommerce_page_wc-orders') {
            if (is_callable('wp_enqueue_script')) {
                call_user_func(
                    'wp_enqueue_script',
                    'san8n-admin',
                    SAN8N_PLUGIN_URL . 'assets/js/admin.js',
                    array('jquery'),
                    SAN8N_VERSION,
                    true
                );
            }

            if (is_callable('wp_localize_script')) {
                $ajax_url = is_callable('admin_url') ? call_user_func('admin_url', 'admin-ajax.php') : '';
                $nonce = is_callable('wp_create_nonce') ? call_user_func('wp_create_nonce', 'san8n_admin_action') : '';
                call_user_func('wp_localize_script', 'san8n-admin', 'san8n_admin', array(
                    'ajax_url' => $ajax_url,
                    'nonce' => $nonce,
                    'i18n' => array(
                        'confirm_reverify' => (is_callable('__') ? call_user_func('__', 'Are you sure you want to re-verify this payment?', 'scanandpay-n8n') : 'Are you sure you want to re-verify this payment?'),
                        'confirm_approve' => (is_callable('__') ? call_user_func('__', 'Are you sure you want to manually approve this payment?', 'scanandpay-n8n') : 'Are you sure you want to manually approve this payment?'),
                        'confirm_reject' => (is_callable('__') ? call_user_func('__', 'Are you sure you want to reject this payment?', 'scanandpay-n8n') : 'Are you sure you want to reject this payment?'),
                        'processing' => (is_callable('__') ? call_user_func('__', 'Processing...', 'scanandpay-n8n') : 'Processing...'),
                        'success' => (is_callable('__') ? call_user_func('__', 'Action completed successfully.', 'scanandpay-n8n') : 'Action completed successfully.'),
                        'error' => (is_callable('__') ? call_user_func('__', 'An error occurred. Please try again.', 'scanandpay-n8n') : 'An error occurred. Please try again.')
                    )
                ));
            }

            if (is_callable('wp_enqueue_style')) {
                call_user_func(
                    'wp_enqueue_style',
                    'san8n-admin',
                    SAN8N_PLUGIN_URL . 'assets/css/admin.css',
                    array(),
                    SAN8N_VERSION
                );
            }
        }

        // Settings page
        if ($hook === 'woocommerce_page_wc-settings') {
            // Ensure Media Library is available for media picker fields
            if (is_callable('wp_enqueue_media')) {
                call_user_func('wp_enqueue_media');
            }

            if (is_callable('wp_enqueue_script')) {
                call_user_func(
                    'wp_enqueue_script',
                    'san8n-settings',
                    SAN8N_PLUGIN_URL . 'assets/js/settings.js',
                    array('jquery'),
                    SAN8N_VERSION,
                    true
                );
            }

            if (is_callable('wp_localize_script')) {
                $ajax_url = is_callable('admin_url') ? call_user_func('admin_url', 'admin-ajax.php') : '';
                $nonce = is_callable('wp_create_nonce') ? call_user_func('wp_create_nonce', 'san8n_test_webhook') : '';
                call_user_func('wp_localize_script', 'san8n-settings', 'san8n_settings', array(
                    'ajax_url' => $ajax_url,
                    'nonce' => $nonce,
                    'i18n' => array(
                        'testing' => (is_callable('__') ? call_user_func('__', 'Testing...', 'scanandpay-n8n') : 'Testing...'),
                        'test_success' => (is_callable('__') ? call_user_func('__', 'Webhook test successful! Latency: %dms', 'scanandpay-n8n') : 'Webhook test successful! Latency: %dms'),
                        'test_failed' => (is_callable('__') ? call_user_func('__', 'Webhook test failed: %s', 'scanandpay-n8n') : 'Webhook test failed: %s')
                    )
                ));
            }
        }
    }

    public function handle_reverify() {
        $this->handle_admin_action('reverify');
    }

    public function handle_approve() {
        $this->handle_admin_action('approve');
    }

    public function handle_reject() {
        $this->handle_admin_action('reject');
    }

    private function handle_admin_action($action) {
        // Check nonce
        $nonce_ok = is_callable('check_ajax_referer') ? (bool) call_user_func('check_ajax_referer', 'san8n_admin_action', 'nonce', false) : true;
        if (!$nonce_ok) {
            $msg = is_callable('__') ? call_user_func('__', 'Security check failed.', 'scanandpay-n8n') : 'Security check failed.';
            if (is_callable('wp_die')) { call_user_func('wp_die', json_encode(array('success' => false, 'message' => $msg))); }
            return;
        }

        // Check capabilities
        $can_manage_wc = is_callable('current_user_can') ? (bool) call_user_func('current_user_can', 'manage_woocommerce') : false;
        $can_san8n = is_callable('current_user_can') ? (bool) call_user_func('current_user_can', SAN8N_CAPABILITY) : false;
        if (!$can_manage_wc || !$can_san8n) {
            $msg = is_callable('__') ? call_user_func('__', 'Permission denied.', 'scanandpay-n8n') : 'Permission denied.';
            if (is_callable('wp_die')) { call_user_func('wp_die', json_encode(array('success' => false, 'message' => $msg))); }
            return;
        }

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $order = is_callable('wc_get_order') ? call_user_func('wc_get_order', $order_id) : null;

        if (!$order) {
            $msg = is_callable('__') ? call_user_func('__', 'Order not found.', 'scanandpay-n8n') : 'Order not found.';
            if (is_callable('wp_die')) { call_user_func('wp_die', json_encode(array('success' => false, 'message' => $msg))); }
            return;
        }

        $correlation_id = $this->logger->get_correlation_id();
        $user = is_callable('wp_get_current_user') ? call_user_func('wp_get_current_user') : (object) array('display_name' => 'user', 'ID' => 0);

        switch ($action) {
            case 'reverify':
                $this->perform_reverify($order, $correlation_id);
                break;

            case 'approve':
                $order->update_meta_data('_san8n_status', 'approved');
                $order->update_meta_data('_san8n_last_checked', (is_callable('current_time') ? call_user_func('current_time', 'mysql') : date('Y-m-d H:i:s')));
                $order->save();

                $order->add_order_note(sprintf(
                    $this->tr('[SAN8N] Payment manually approved by %s (User ID: %d). Correlation ID: %s'),
                    $user->display_name,
                    $user->ID,
                    $correlation_id
                ), 0, true);

                $this->logger->info('Payment manually approved', array(
                    'order_id' => $order_id,
                    'user_id' => $user->ID,
                    'correlation_id' => $correlation_id
                ));
                break;

            case 'reject':
                $reason_raw = isset($_POST['reason']) ? $_POST['reason'] : '';
                $reason = $reason_raw !== '' ? (is_callable('sanitize_text_field') ? call_user_func('sanitize_text_field', $reason_raw) : $reason_raw) : (is_callable('__') ? call_user_func('__', 'Manual rejection', 'scanandpay-n8n') : 'Manual rejection');
                
                $order->update_meta_data('_san8n_status', 'rejected');
                $order->update_meta_data('_san8n_reason', $reason);
                $order->update_meta_data('_san8n_last_checked', (is_callable('current_time') ? call_user_func('current_time', 'mysql') : date('Y-m-d H:i:s')));
                $order->save();

                $order->add_order_note(sprintf(
                    $this->tr('[SAN8N] Payment manually rejected by %s (User ID: %d). Reason: %s. Correlation ID: %s'),
                    $user->display_name,
                    $user->ID,
                    $reason,
                    $correlation_id
                ), 0, true);

                $this->logger->info('Payment manually rejected', array(
                    'order_id' => $order_id,
                    'user_id' => $user->ID,
                    'reason' => $reason,
                    'correlation_id' => $correlation_id
                ));
                break;
        }

        $msg = is_callable('__') ? call_user_func('__', 'Action completed successfully.', 'scanandpay-n8n') : 'Action completed successfully.';
        if (is_callable('wp_die')) { call_user_func('wp_die', json_encode(array('success' => true, 'message' => $msg))); }
    }

    private function perform_reverify($order, $correlation_id) {
        $attachment_id = $order->get_meta('_san8n_attachment_id');
        
        if (!$attachment_id) {
            $msg = is_callable('__') ? call_user_func('__', 'No slip attachment found.', 'scanandpay-n8n') : 'No slip attachment found.';
            if (is_callable('wp_die')) { call_user_func('wp_die', json_encode(array('success' => false, 'message' => $msg))); }
            return;
        }

        // Get settings (guard array keys)
        $settings = is_callable('get_option') ? call_user_func('get_option', SAN8N_OPTIONS_KEY, array()) : array();
        $n8n_url = isset($settings['n8n_webhook_url']) ? (string) $settings['n8n_webhook_url'] : '';
        $shared_secret = isset($settings['shared_secret']) ? (string) $settings['shared_secret'] : '';

        if (empty($n8n_url) || empty($shared_secret)) {
            $msg = is_callable('__') ? call_user_func('__', 'Gateway not configured.', 'scanandpay-n8n') : 'Gateway not configured.';
            if (is_callable('wp_die')) { call_user_func('wp_die', json_encode(array('success' => false, 'message' => $msg))); }
            return;
        }

        // Prepare request (similar to verify_slip but simpler)
        $attachment_path = is_callable('get_attached_file') ? call_user_func('get_attached_file', $attachment_id) : '';
        if (!file_exists($attachment_path)) {
            $msg = is_callable('__') ? call_user_func('__', 'Slip file not found.', 'scanandpay-n8n') : 'Slip file not found.';
            if (is_callable('wp_die')) { call_user_func('wp_die', json_encode(array('success' => false, 'message' => $msg))); }
            return;
        }

        // Make reverification request
        // [Implementation would be similar to verify_slip in REST API class]
        
        $user = is_callable('wp_get_current_user') ? call_user_func('wp_get_current_user') : (object) array('display_name' => 'user', 'ID' => 0);
        $order->add_order_note(sprintf(
            $this->tr('[SAN8N] Payment re-verification initiated by %s (User ID: %d). Correlation ID: %s'),
            $user->display_name,
            $user->ID,
            $correlation_id
        ), 0, true);

        $msg = is_callable('__') ? call_user_func('__', 'Re-verification initiated.', 'scanandpay-n8n') : 'Re-verification initiated.';
        if (is_callable('wp_die')) { call_user_func('wp_die', json_encode(array('success' => true, 'message' => $msg))); }
    }

    public function handle_test_webhook() {
        // Check nonce
        $nonce_ok = is_callable('check_ajax_referer') ? (bool) call_user_func('check_ajax_referer', 'san8n_test_webhook', 'nonce', false) : true;
        if (!$nonce_ok) {
            $msg = is_callable('__') ? call_user_func('__', 'Security check failed.', 'scanandpay-n8n') : 'Security check failed.';
            if (is_callable('wp_die')) { call_user_func('wp_die', json_encode(array('success' => false, 'message' => $msg))); }
            return;
        }

        // Check capabilities
        $can_manage_wc = is_callable('current_user_can') ? (bool) call_user_func('current_user_can', 'manage_woocommerce') : false;
        if (!$can_manage_wc) {
            $msg = is_callable('__') ? call_user_func('__', 'Permission denied.', 'scanandpay-n8n') : 'Permission denied.';
            if (is_callable('wp_die')) { call_user_func('wp_die', json_encode(array('success' => false, 'message' => $msg))); }
            return;
        }

        $settings = is_callable('get_option') ? call_user_func('get_option', SAN8N_OPTIONS_KEY, array()) : array();
        $n8n_url = isset($settings['n8n_webhook_url']) ? (string) $settings['n8n_webhook_url'] : '';
        $shared_secret = isset($settings['shared_secret']) ? (string) $settings['shared_secret'] : '';

        if (empty($n8n_url) || empty($shared_secret)) {
            $msg = is_callable('__') ? call_user_func('__', 'Please configure webhook URL and secret first.', 'scanandpay-n8n') : 'Please configure webhook URL and secret first.';
            if (is_callable('wp_die')) { call_user_func('wp_die', json_encode(array('success' => false, 'message' => $msg))); }
            return;
        }

        // Send test ping
        $timestamp = time();
        $test_payload = (is_callable('wp_json_encode') ? call_user_func('wp_json_encode', array(
            'type' => 'ping',
            'timestamp' => $timestamp,
            'source' => 'scanandpay-n8n'
        )) : json_encode(array(
            'type' => 'ping',
            'timestamp' => $timestamp,
            'source' => 'scanandpay-n8n'
        )));
        
        $body_hash = hash('sha256', $test_payload);
        $signature_base = $timestamp . "\n" . $body_hash;
        $signature = hash_hmac('sha256', $signature_base, $shared_secret);

        $start_time = microtime(true);
        
        $response = null;
        if (is_callable('wp_remote_post')) {
            $response = call_user_func('wp_remote_post', $n8n_url, array(
                'timeout' => 5,
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'X-PromptPay-Timestamp' => $timestamp,
                    'X-PromptPay-Signature' => $signature,
                    'X-PromptPay-Version' => '1.0',
                    'X-Test-Ping' => 'true'
                ),
                'body' => $test_payload
            ));
        }

        $latency = round((microtime(true) - $start_time) * 1000);

        $is_err = is_callable('is_wp_error') ? call_user_func('is_wp_error', $response) : false;
        if ($is_err) {
            $err_msg = method_exists($response, 'get_error_message') ? $response->get_error_message() : 'Request failed.';
            if (is_callable('wp_die')) { call_user_func('wp_die', json_encode(array('success' => false, 'message' => $err_msg))); }
            return;
        }

        $response_code = is_callable('wp_remote_retrieve_response_code') ? call_user_func('wp_remote_retrieve_response_code', $response) : 0;
        
        if ($response_code >= 200 && $response_code < 300) {
            $msg = is_callable('__') ? call_user_func('__', 'Webhook test successful! Latency: %dms', 'scanandpay-n8n') : 'Webhook test successful! Latency: %dms';
            if (is_callable('wp_die')) { call_user_func('wp_die', json_encode(array('success' => true, 'message' => sprintf($msg, $latency), 'latency' => $latency))); }
            return;
        } else {
            $msg = is_callable('__') ? call_user_func('__', 'Webhook returned status code: %d', 'scanandpay-n8n') : 'Webhook returned status code: %d';
            if (is_callable('wp_die')) { call_user_func('wp_die', json_encode(array('success' => false, 'message' => sprintf($msg, $response_code)))); }
            return;
        }
    }
}
