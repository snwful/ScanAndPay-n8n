<?php
/**
 * Plugin Name: Scan & Pay (n8n)
 * Plugin URI: https://github.com/your-org/scanandpay-n8n
 * Description: PromptPay payment gateway with inline slip verification via n8n
 * Version: 1.1.3
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
    // Load stubs for static analysis/CLI contexts, then exit to avoid executing plugin outside WP
    $stub = __DIR__ . '/includes/wp-stubs.php';
    if (file_exists($stub)) { require_once $stub; }
    exit;
}

// Define plugin constants
define('SAN8N_VERSION', '1.1.3');
define('SAN8N_PLUGIN_FILE', __FILE__);
define('SAN8N_PLUGIN_DIR', is_callable('plugin_dir_path') ? call_user_func('plugin_dir_path', __FILE__) : (dirname(__FILE__) . '/'));
define('SAN8N_PLUGIN_URL', is_callable('plugin_dir_url') ? call_user_func('plugin_dir_url', __FILE__) : '');
define('SAN8N_GATEWAY_ID', 'scanandpay_n8n');
define('SAN8N_TEXTDOMAIN', 'scanandpay-n8n');
define('SAN8N_REST_NAMESPACE', 'wc-scanandpay/v1');
define('SAN8N_OPTIONS_KEY', 'woocommerce_scanandpay_n8n_settings');
define('SAN8N_SESSION_FLAG', 'san8n_approved');
define('SAN8N_LOGGER_SOURCE', 'scanandpay-n8n');
define('SAN8N_CAPABILITY', 'san8n_manage');
// Toggle async callback retries
if (!defined('SAN8N_CALLBACK_ASYNC')) {
    define('SAN8N_CALLBACK_ASYNC', true);
}

// Initialize plugin (guarded for IDEs)
if (is_callable('add_action')) {
    call_user_func('add_action', 'plugins_loaded', 'san8n_init_gateway', 11);
}

function san8n_init_gateway() {
    // Check if WooCommerce is active
    if (!class_exists('WC_Payment_Gateway')) {
        if (is_callable('add_action')) {
            call_user_func('add_action', 'admin_notices', 'san8n_woocommerce_missing_notice');
        }
        return;
    }

    // Load text domain
    if (is_callable('load_plugin_textdomain')) {
        $domain_path = '/languages';
        $plugin_base = is_callable('plugin_basename') ? call_user_func('plugin_basename', __FILE__) : '';
        $rel_path = $plugin_base ? dirname($plugin_base) . $domain_path : 'languages';
        call_user_func('load_plugin_textdomain', SAN8N_TEXTDOMAIN, false, $rel_path);
    }

    // Include required files
    require_once SAN8N_PLUGIN_DIR . 'includes/class-san8n-logger.php';
    require_once SAN8N_PLUGIN_DIR . 'includes/class-san8n-verifier.php';
    require_once SAN8N_PLUGIN_DIR . 'includes/class-san8n-gateway.php';
    require_once SAN8N_PLUGIN_DIR . 'includes/class-san8n-rest-api.php';
    require_once SAN8N_PLUGIN_DIR . 'includes/class-san8n-admin.php';
    // Note: class-san8n-blocks-integration.php is loaded conditionally in san8n_blocks_support()
    require_once SAN8N_PLUGIN_DIR . 'includes/class-san8n-helper.php';

    // Initialize REST API
    new SAN8N_REST_API();

    // Initialize Admin
    $is_admin = is_callable('is_admin') ? (bool) call_user_func('is_admin') : false;
    if ($is_admin) {
        new SAN8N_Admin();
    }

    // Register the gateway
    if (is_callable('add_filter')) {
        call_user_func('add_filter', 'woocommerce_payment_gateways', 'san8n_add_gateway');
    }

    // Initialize Blocks support
    if (is_callable('add_action')) {
        call_user_func('add_action', 'woocommerce_blocks_loaded', 'san8n_blocks_support');
    }

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
        if (is_callable('add_action')) {
            call_user_func(
                'add_action',
                'woocommerce_blocks_payment_method_type_registration',
                function($payment_method_registry) {
                    $payment_method_registry->register(new SAN8N_Blocks_Integration());
                }
            );
        }
    }
}

function san8n_woocommerce_missing_notice() {
    ?>
    <div class="notice notice-error">
        <p><?php echo (is_callable('esc_html') ? call_user_func('esc_html', (is_callable('__') ? call_user_func('__', 'Scan & Pay (n8n) requires WooCommerce to be installed and active.', 'scanandpay-n8n') : 'Scan & Pay (n8n) requires WooCommerce to be installed and active.')) : 'Scan & Pay (n8n) requires WooCommerce to be installed and active.'); ?></p>
    </div>
    <?php
}

function san8n_schedule_cron_events() {
    if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_event')) {
        if (!call_user_func('wp_next_scheduled', 'san8n_retention_cleanup')) {
            call_user_func('wp_schedule_event', time(), 'daily', 'san8n_retention_cleanup');
        }
    }
}

// Handle cron events
if (is_callable('add_action')) {
    call_user_func('add_action', 'san8n_retention_cleanup', 'san8n_cleanup_old_attachments');
}

function san8n_cleanup_old_attachments() {
    $settings = is_callable('get_option') ? call_user_func('get_option', SAN8N_OPTIONS_KEY, array()) : array();
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

    $attachments = is_callable('get_posts') ? call_user_func('get_posts', $args) : array();
    
    if (is_array($attachments)) {
        foreach ($attachments as $attachment_id) {
            if (is_callable('wp_delete_attachment')) {
                call_user_func('wp_delete_attachment', $attachment_id, true);
            }
        }
    }
}

// Activation hook
if (is_callable('register_activation_hook')) {
    call_user_func('register_activation_hook', __FILE__, 'san8n_activate');
}

function san8n_activate() {
    // Add custom capability to admin role
    $role = is_callable('get_role') ? call_user_func('get_role', 'administrator') : null;
    if ($role && method_exists($role, 'add_cap')) {
        $role->add_cap(SAN8N_CAPABILITY);
    }

    // Schedule cron
    if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_event')) {
        if (!call_user_func('wp_next_scheduled', 'san8n_retention_cleanup')) {
            call_user_func('wp_schedule_event', time(), 'daily', 'san8n_retention_cleanup');
        }
    }

    // Flush rewrite rules for REST endpoints
    if (is_callable('flush_rewrite_rules')) {
        call_user_func('flush_rewrite_rules');
    }
}

// Deactivation hook
if (is_callable('register_deactivation_hook')) {
    call_user_func('register_deactivation_hook', __FILE__, 'san8n_deactivate');
}

function san8n_deactivate() {
    // Clear scheduled cron
    if (is_callable('wp_clear_scheduled_hook')) {
        call_user_func('wp_clear_scheduled_hook', 'san8n_retention_cleanup');
    }

    // Flush rewrite rules
    if (is_callable('flush_rewrite_rules')) {
        call_user_func('flush_rewrite_rules');
    }
}

// Uninstall hook
if (is_callable('register_uninstall_hook')) {
    call_user_func('register_uninstall_hook', __FILE__, 'san8n_uninstall');
}

function san8n_uninstall() {
    // Remove custom capability
    $role = is_callable('get_role') ? call_user_func('get_role', 'administrator') : null;
    if ($role && method_exists($role, 'remove_cap')) {
        $role->remove_cap(SAN8N_CAPABILITY);
    }

    // Delete options
    if (is_callable('delete_option')) {
        call_user_func('delete_option', SAN8N_OPTIONS_KEY);
    }

    // Delete transients with prefix
    global $wpdb;
    if (isset($wpdb) && isset($wpdb->options) && method_exists($wpdb, 'query')) {
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_san8n_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_san8n_%'");
    }

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

    $attachments = is_callable('get_posts') ? call_user_func('get_posts', $args) : array();
    
    if (is_array($attachments)) {
        foreach ($attachments as $attachment_id) {
            if (is_callable('wp_delete_attachment')) {
                call_user_func('wp_delete_attachment', $attachment_id, true);
            }
        }
    }
}
