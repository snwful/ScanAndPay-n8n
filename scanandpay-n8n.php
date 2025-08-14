<?php
/**
 * Plugin Name: Scan & Pay (n8n)
 * Plugin URI: https://github.com/your-org/scanandpay-n8n
 * Description: PromptPay payment gateway with inline slip verification via n8n
 * Version: 1.1.1
 * Author: Your Company
 * Author URI: https://yourcompany.com
 * Text Domain: scanandpay-n8n
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('SAN8N_VERSION', '1.1.1');
define('SAN8N_PLUGIN_FILE', __FILE__);
define('SAN8N_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SAN8N_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SAN8N_GATEWAY_ID', 'scanandpay_n8n');
define('SAN8N_TEXTDOMAIN', 'scanandpay-n8n');
define('SAN8N_REST_NAMESPACE', 'wc-scanandpay/v1');
define('SAN8N_OPTIONS_KEY', 'woocommerce_scanandpay_n8n_settings');
define('SAN8N_SESSION_FLAG', 'san8n_approved');
define('SAN8N_LOGGER_SOURCE', 'scanandpay-n8n');
define('SAN8N_CAPABILITY', 'san8n_manage');

// Bootstrap plugin
add_action('plugins_loaded', 'san8n_init_gateway', 11);

function san8n_init_gateway() {
    // Check if WooCommerce is active
    if (!class_exists('WC_Payment_Gateway')) {
        add_action('admin_notices', 'san8n_woocommerce_missing_notice');
        return;
    }

    // Load text domain
    load_plugin_textdomain(SAN8N_TEXTDOMAIN, false, dirname(plugin_basename(__FILE__)) . '/languages');

    // Include required files
    require_once SAN8N_PLUGIN_DIR . 'includes/class-san8n-logger.php';
    require_once SAN8N_PLUGIN_DIR . 'includes/class-san8n-gateway.php';
    require_once SAN8N_PLUGIN_DIR . 'includes/class-san8n-rest-api.php';
    require_once SAN8N_PLUGIN_DIR . 'includes/class-san8n-admin.php';
    // Note: class-san8n-blocks-integration.php is loaded conditionally in san8n_blocks_support()

    // Initialize REST API
    new SAN8N_REST_API();

    // Initialize Admin
    if (is_admin()) {
        new SAN8N_Admin();
    }

    // Register the gateway
    add_filter('woocommerce_payment_gateways', 'san8n_add_gateway');

    // Initialize Blocks support
    add_action('woocommerce_blocks_loaded', 'san8n_blocks_support');

    // Schedule cron events
    san8n_schedule_cron_events();
}

function san8n_add_gateway($gateways) {
    $gateways[] = 'SAN8N_Gateway';
    return $gateways;
}

function san8n_blocks_support() {
    if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        require_once SAN8N_PLUGIN_DIR . 'includes/class-san8n-blocks-integration.php';
        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function($payment_method_registry) {
                $payment_method_registry->register(new SAN8N_Blocks_Integration());
            }
        );
    }
}

function san8n_woocommerce_missing_notice() {
    ?>
    <div class="notice notice-error">
        <p><?php esc_html_e('Scan & Pay (n8n) requires WooCommerce to be installed and active.', 'scanandpay-n8n'); ?></p>
    </div>
    <?php
}

function san8n_schedule_cron_events() {
    if (!wp_next_scheduled('san8n_retention_cleanup')) {
        wp_schedule_event(time(), 'daily', 'san8n_retention_cleanup');
    }
}

// Handle cron events
add_action('san8n_retention_cleanup', 'san8n_cleanup_old_attachments');

function san8n_cleanup_old_attachments() {
    $settings = get_option(SAN8N_OPTIONS_KEY, array());
    $retention_days = isset($settings['retention_days']) ? intval($settings['retention_days']) : 30;
    
    if ($retention_days <= 0) {
        return;
    }

    $args = array(
        'post_type' => 'attachment',
        'post_status' => 'any',
        'meta_query' => array(
            array(
                'key' => '_san8n_slip',
                'value' => '1',
                'compare' => '='
            )
        ),
        'date_query' => array(
            array(
                'before' => $retention_days . ' days ago'
            )
        ),
        'posts_per_page' => 100,
        'fields' => 'ids'
    );

    $attachments = get_posts($args);
    
    foreach ($attachments as $attachment_id) {
        wp_delete_attachment($attachment_id, true);
    }
}

// Activation hook
register_activation_hook(__FILE__, 'san8n_activate');

function san8n_activate() {
    // Add custom capability to admin role
    $role = get_role('administrator');
    if ($role) {
        $role->add_cap(SAN8N_CAPABILITY);
    }

    // Schedule cron
    if (!wp_next_scheduled('san8n_retention_cleanup')) {
        wp_schedule_event(time(), 'daily', 'san8n_retention_cleanup');
    }

    // Flush rewrite rules for REST endpoints
    flush_rewrite_rules();
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'san8n_deactivate');

function san8n_deactivate() {
    // Clear scheduled cron
    wp_clear_scheduled_hook('san8n_retention_cleanup');

    // Flush rewrite rules
    flush_rewrite_rules();
}

// Uninstall hook
register_uninstall_hook(__FILE__, 'san8n_uninstall');

function san8n_uninstall() {
    // Remove custom capability
    $role = get_role('administrator');
    if ($role) {
        $role->remove_cap(SAN8N_CAPABILITY);
    }

    // Delete options
    delete_option(SAN8N_OPTIONS_KEY);

    // Delete transients with prefix
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_san8n_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_san8n_%'");

    // Delete all slip attachments
    $args = array(
        'post_type' => 'attachment',
        'post_status' => 'any',
        'meta_query' => array(
            array(
                'key' => '_san8n_slip',
                'value' => '1',
                'compare' => '='
            )
        ),
        'posts_per_page' => -1,
        'fields' => 'ids'
    );

    $attachments = get_posts($args);
    
    foreach ($attachments as $attachment_id) {
        wp_delete_attachment($attachment_id, true);
    }
}
