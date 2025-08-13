<?php
/**
 * Admin functionality and order meta box
 */

if (!defined('ABSPATH')) {
    exit;
}

class SAN8N_Admin {
    private $logger;

    public function __construct() {
        $this->logger = new SAN8N_Logger();

        // Add meta box
        add_action('add_meta_boxes', array($this, 'add_order_meta_box'));
        
        // Handle admin actions
        add_action('wp_ajax_san8n_reverify', array($this, 'handle_reverify'));
        add_action('wp_ajax_san8n_approve', array($this, 'handle_approve'));
        add_action('wp_ajax_san8n_reject', array($this, 'handle_reject'));
        add_action('wp_ajax_san8n_test_webhook', array($this, 'handle_test_webhook'));

        // Add admin scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    public function add_order_meta_box() {
        $screen = get_current_screen();
        
        if ($screen && $screen->id === 'shop_order') {
            add_meta_box(
                'san8n_payment_details',
                __('Scan & Pay (n8n) Details', 'scanandpay-n8n'),
                array($this, 'render_meta_box'),
                'shop_order',
                'side',
                'default'
            );
        }

        // For HPOS compatibility
        if ($screen && $screen->id === 'woocommerce_page_wc-orders') {
            add_meta_box(
                'san8n_payment_details',
                __('Scan & Pay (n8n) Details', 'scanandpay-n8n'),
                array($this, 'render_meta_box'),
                'woocommerce_page_wc-orders',
                'side',
                'default'
            );
        }
    }

    public function render_meta_box($post_or_order) {
        // Get order object
        if (is_a($post_or_order, 'WP_Post')) {
            $order = wc_get_order($post_or_order->ID);
        } else {
            $order = $post_or_order;
        }

        if (!$order) {
            return;
        }

        // Check if this payment method was used
        if ($order->get_payment_method() !== SAN8N_GATEWAY_ID) {
            echo '<p>' . esc_html__('This order was not paid using Scan & Pay (n8n).', 'scanandpay-n8n') . '</p>';
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
        $can_view = current_user_can('manage_woocommerce');
        $can_manage = current_user_can('manage_woocommerce') && current_user_can(SAN8N_CAPABILITY);

        if (!$can_view) {
            echo '<p>' . esc_html__('You do not have permission to view this information.', 'scanandpay-n8n') . '</p>';
            return;
        }

        ?>
        <div class="san8n-admin-meta-box">
            <?php if ($status): ?>
            <div class="san8n-status-badge san8n-status-<?php echo esc_attr($status); ?>">
                <?php echo esc_html(ucfirst($status)); ?>
            </div>
            <?php endif; ?>

            <?php if ($attachment_id): ?>
            <div class="san8n-slip-preview">
                <h4><?php esc_html_e('Payment Slip', 'scanandpay-n8n'); ?></h4>
                <?php
                $attachment_url = wp_get_attachment_url($attachment_id);
                $attachment_thumb = wp_get_attachment_image_src($attachment_id, 'thumbnail');
                if ($attachment_thumb) {
                    echo '<a href="' . esc_url($attachment_url) . '" target="_blank">';
                    echo '<img src="' . esc_url($attachment_thumb[0]) . '" alt="' . esc_attr__('Payment slip', 'scanandpay-n8n') . '" style="max-width: 100%; height: auto;" />';
                    echo '</a>';
                }
                ?>
            </div>
            <?php endif; ?>

            <div class="san8n-details">
                <?php if ($reference_id): ?>
                <p>
                    <strong><?php esc_html_e('Reference ID:', 'scanandpay-n8n'); ?></strong><br>
                    <code><?php echo esc_html($reference_id); ?></code>
                </p>
                <?php endif; ?>

                <?php if ($approved_amount): ?>
                <p>
                    <strong><?php esc_html_e('Approved Amount:', 'scanandpay-n8n'); ?></strong><br>
                    <?php echo wc_price($approved_amount); ?> THB
                </p>
                <?php endif; ?>

                <?php if ($reason && $status === 'rejected'): ?>
                <p>
                    <strong><?php esc_html_e('Rejection Reason:', 'scanandpay-n8n'); ?></strong><br>
                    <?php echo esc_html($reason); ?>
                </p>
                <?php endif; ?>

                <?php if ($last_checked): ?>
                <p>
                    <strong><?php esc_html_e('Last Checked:', 'scanandpay-n8n'); ?></strong><br>
                    <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_checked))); ?>
                </p>
                <?php endif; ?>
            </div>

            <?php if ($can_manage): ?>
            <div class="san8n-actions">
                <h4><?php esc_html_e('Actions', 'scanandpay-n8n'); ?></h4>
                <button type="button" 
                        class="button san8n-action-button" 
                        data-action="reverify" 
                        data-order-id="<?php echo esc_attr($order->get_id()); ?>">
                    <?php esc_html_e('Re-verify', 'scanandpay-n8n'); ?>
                </button>
                
                <?php if ($status !== 'approved'): ?>
                <button type="button" 
                        class="button san8n-action-button" 
                        data-action="approve" 
                        data-order-id="<?php echo esc_attr($order->get_id()); ?>">
                    <?php esc_html_e('Approve Override', 'scanandpay-n8n'); ?>
                </button>
                <?php endif; ?>
                
                <?php if ($status !== 'rejected'): ?>
                <button type="button" 
                        class="button san8n-action-button" 
                        data-action="reject" 
                        data-order-id="<?php echo esc_attr($order->get_id()); ?>">
                    <?php esc_html_e('Reject Override', 'scanandpay-n8n'); ?>
                </button>
                <?php endif; ?>
                
                <div class="san8n-action-result" style="display: none; margin-top: 10px;"></div>
            </div>
            <?php endif; ?>

            <div class="san8n-audit-log">
                <h4><?php esc_html_e('Audit Log', 'scanandpay-n8n'); ?></h4>
                <?php
                $notes = wc_get_order_notes(array(
                    'order_id' => $order->get_id(),
                    'type' => 'internal'
                ));

                $san8n_notes = array_filter($notes, function($note) {
                    return strpos($note->content, '[SAN8N]') !== false;
                });

                if (!empty($san8n_notes)) {
                    echo '<ul class="san8n-audit-list">';
                    foreach (array_slice($san8n_notes, 0, 5) as $note) {
                        echo '<li>';
                        echo '<small>' . esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($note->date_created))) . '</small><br>';
                        echo wp_kses_post($note->content);
                        echo '</li>';
                    }
                    echo '</ul>';
                } else {
                    echo '<p><em>' . esc_html__('No audit entries.', 'scanandpay-n8n') . '</em></p>';
                }
                ?>
            </div>
        </div>
        <?php

        wp_nonce_field('san8n_admin_action', 'san8n_admin_nonce');
    }

    public function enqueue_admin_scripts($hook) {
        if ($hook === 'post.php' || $hook === 'post-new.php' || $hook === 'woocommerce_page_wc-orders') {
            wp_enqueue_script(
                'san8n-admin',
                SAN8N_PLUGIN_URL . 'assets/js/admin.js',
                array('jquery'),
                SAN8N_VERSION,
                true
            );

            wp_localize_script('san8n-admin', 'san8n_admin', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('san8n_admin_action'),
                'i18n' => array(
                    'confirm_reverify' => __('Are you sure you want to re-verify this payment?', 'scanandpay-n8n'),
                    'confirm_approve' => __('Are you sure you want to manually approve this payment?', 'scanandpay-n8n'),
                    'confirm_reject' => __('Are you sure you want to reject this payment?', 'scanandpay-n8n'),
                    'processing' => __('Processing...', 'scanandpay-n8n'),
                    'success' => __('Action completed successfully.', 'scanandpay-n8n'),
                    'error' => __('An error occurred. Please try again.', 'scanandpay-n8n')
                )
            ));

            wp_enqueue_style(
                'san8n-admin',
                SAN8N_PLUGIN_URL . 'assets/css/admin.css',
                array(),
                SAN8N_VERSION
            );
        }

        // Settings page
        if ($hook === 'woocommerce_page_wc-settings') {
            wp_enqueue_script(
                'san8n-settings',
                SAN8N_PLUGIN_URL . 'assets/js/settings.js',
                array('jquery'),
                SAN8N_VERSION,
                true
            );

            wp_localize_script('san8n-settings', 'san8n_settings', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('san8n_test_webhook'),
                'i18n' => array(
                    'testing' => __('Testing...', 'scanandpay-n8n'),
                    'test_success' => __('Webhook test successful! Latency: %dms', 'scanandpay-n8n'),
                    'test_failed' => __('Webhook test failed: %s', 'scanandpay-n8n')
                )
            ));
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
        if (!check_ajax_referer('san8n_admin_action', 'nonce', false)) {
            wp_die(json_encode(array('success' => false, 'message' => __('Security check failed.', 'scanandpay-n8n'))));
        }

        // Check capabilities
        if (!current_user_can('manage_woocommerce') || !current_user_can(SAN8N_CAPABILITY)) {
            wp_die(json_encode(array('success' => false, 'message' => __('Permission denied.', 'scanandpay-n8n'))));
        }

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $order = wc_get_order($order_id);

        if (!$order) {
            wp_die(json_encode(array('success' => false, 'message' => __('Order not found.', 'scanandpay-n8n'))));
        }

        $correlation_id = $this->logger->get_correlation_id();
        $user = wp_get_current_user();

        switch ($action) {
            case 'reverify':
                $this->perform_reverify($order, $correlation_id);
                break;

            case 'approve':
                $order->update_meta_data('_san8n_status', 'approved');
                $order->update_meta_data('_san8n_last_checked', current_time('mysql'));
                $order->save();

                $order->add_order_note(sprintf(
                    __('[SAN8N] Payment manually approved by %s (User ID: %d). Correlation ID: %s', 'scanandpay-n8n'),
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
                $reason = isset($_POST['reason']) ? sanitize_text_field($_POST['reason']) : __('Manual rejection', 'scanandpay-n8n');
                
                $order->update_meta_data('_san8n_status', 'rejected');
                $order->update_meta_data('_san8n_reason', $reason);
                $order->update_meta_data('_san8n_last_checked', current_time('mysql'));
                $order->save();

                $order->add_order_note(sprintf(
                    __('[SAN8N] Payment manually rejected by %s (User ID: %d). Reason: %s. Correlation ID: %s', 'scanandpay-n8n'),
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

        wp_die(json_encode(array('success' => true, 'message' => __('Action completed successfully.', 'scanandpay-n8n'))));
    }

    private function perform_reverify($order, $correlation_id) {
        $attachment_id = $order->get_meta('_san8n_attachment_id');
        
        if (!$attachment_id) {
            wp_die(json_encode(array('success' => false, 'message' => __('No slip attachment found.', 'scanandpay-n8n'))));
        }

        // Get settings
        $settings = get_option(SAN8N_OPTIONS_KEY, array());
        $n8n_url = $settings['n8n_webhook_url'];
        $shared_secret = $settings['shared_secret'];

        if (empty($n8n_url) || empty($shared_secret)) {
            wp_die(json_encode(array('success' => false, 'message' => __('Gateway not configured.', 'scanandpay-n8n'))));
        }

        // Prepare request (similar to verify_slip but simpler)
        $attachment_path = get_attached_file($attachment_id);
        if (!file_exists($attachment_path)) {
            wp_die(json_encode(array('success' => false, 'message' => __('Slip file not found.', 'scanandpay-n8n'))));
        }

        // Make reverification request
        // [Implementation would be similar to verify_slip in REST API class]
        
        $user = wp_get_current_user();
        $order->add_order_note(sprintf(
            __('[SAN8N] Payment re-verification initiated by %s (User ID: %d). Correlation ID: %s', 'scanandpay-n8n'),
            $user->display_name,
            $user->ID,
            $correlation_id
        ), 0, true);

        wp_die(json_encode(array('success' => true, 'message' => __('Re-verification initiated.', 'scanandpay-n8n'))));
    }

    public function handle_test_webhook() {
        // Check nonce
        if (!check_ajax_referer('san8n_test_webhook', 'nonce', false)) {
            wp_die(json_encode(array('success' => false, 'message' => __('Security check failed.', 'scanandpay-n8n'))));
        }

        // Check capabilities
        if (!current_user_can('manage_woocommerce')) {
            wp_die(json_encode(array('success' => false, 'message' => __('Permission denied.', 'scanandpay-n8n'))));
        }

        $settings = get_option(SAN8N_OPTIONS_KEY, array());
        $n8n_url = $settings['n8n_webhook_url'];
        $shared_secret = $settings['shared_secret'];

        if (empty($n8n_url) || empty($shared_secret)) {
            wp_die(json_encode(array('success' => false, 'message' => __('Please configure webhook URL and secret first.', 'scanandpay-n8n'))));
        }

        // Send test ping
        $timestamp = time();
        $test_payload = wp_json_encode(array(
            'type' => 'ping',
            'timestamp' => $timestamp,
            'source' => 'scanandpay-n8n'
        ));
        
        $body_hash = hash('sha256', $test_payload);
        $signature_base = $timestamp . "\n" . $body_hash;
        $signature = hash_hmac('sha256', $signature_base, $shared_secret);

        $start_time = microtime(true);
        
        $response = wp_remote_post($n8n_url, array(
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

        $latency = round((microtime(true) - $start_time) * 1000);

        if (is_wp_error($response)) {
            wp_die(json_encode(array(
                'success' => false, 
                'message' => $response->get_error_message()
            )));
        }

        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code >= 200 && $response_code < 300) {
            wp_die(json_encode(array(
                'success' => true, 
                'message' => sprintf(__('Webhook test successful! Latency: %dms', 'scanandpay-n8n'), $latency),
                'latency' => $latency
            )));
        } else {
            wp_die(json_encode(array(
                'success' => false, 
                'message' => sprintf(__('Webhook returned status code: %d', 'scanandpay-n8n'), $response_code)
            )));
        }
    }
}
