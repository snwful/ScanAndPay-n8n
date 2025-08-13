<?php
/**
 * Uninstall Script for Scan & Pay (n8n)
 *
 * This file is executed when the plugin is deleted via WordPress admin.
 * It cleans up all plugin data including options, transients, capabilities,
 * uploaded files, and scheduled cron jobs.
 *
 * @package ScanAndPay_n8n
 */

// Exit if not called by WordPress
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Define constants if not already defined
if (!defined('SAN8N_OPTIONS_KEY')) {
    define('SAN8N_OPTIONS_KEY', 'woocommerce_scanandpay_n8n_settings');
}

if (!defined('SAN8N_CAPABILITY')) {
    define('SAN8N_CAPABILITY', 'manage_san8n_payments');
}

/**
 * Remove plugin options
 */
delete_option(SAN8N_OPTIONS_KEY);
delete_option('san8n_db_version');
delete_option('san8n_activation_time');
delete_option('san8n_last_cleanup');

/**
 * Remove all transients
 */
global $wpdb;

// Delete transients
$wpdb->query(
    "DELETE FROM {$wpdb->options} 
     WHERE option_name LIKE '_transient_san8n_%' 
     OR option_name LIKE '_transient_timeout_san8n_%'"
);

// Delete site transients for multisite
$wpdb->query(
    "DELETE FROM {$wpdb->sitemeta} 
     WHERE meta_key LIKE '_site_transient_san8n_%' 
     OR meta_key LIKE '_site_transient_timeout_san8n_%'"
);

/**
 * Remove custom capability from all roles
 */
$roles = wp_roles();
if ($roles) {
    foreach ($roles->roles as $role_name => $role_info) {
        $role = get_role($role_name);
        if ($role && $role->has_cap(SAN8N_CAPABILITY)) {
            $role->remove_cap(SAN8N_CAPABILITY);
        }
    }
}

/**
 * Clear scheduled cron jobs
 */
$timestamp = wp_next_scheduled('san8n_daily_cleanup');
if ($timestamp) {
    wp_unschedule_event($timestamp, 'san8n_daily_cleanup');
}

// Clear all scheduled events for this plugin
wp_clear_scheduled_hook('san8n_daily_cleanup');
wp_clear_scheduled_hook('san8n_hourly_check');
wp_clear_scheduled_hook('san8n_rate_limit_reset');

/**
 * Remove uploaded slip attachments
 */
$args = array(
    'post_type'      => 'attachment',
    'posts_per_page' => -1,
    'post_status'    => 'any',
    'meta_query'     => array(
        array(
            'key'     => '_san8n_slip',
            'compare' => 'EXISTS'
        )
    )
);

$attachments = get_posts($args);
foreach ($attachments as $attachment) {
    wp_delete_attachment($attachment->ID, true);
}

/**
 * Clean up order meta data (optional - commented out by default)
 * Uncomment if you want to remove all order meta data on uninstall
 */
/*
$wpdb->query(
    "DELETE FROM {$wpdb->postmeta} 
     WHERE meta_key LIKE '_san8n_%'"
);
*/

/**
 * Clean up user meta data
 */
$wpdb->query(
    "DELETE FROM {$wpdb->usermeta} 
     WHERE meta_key LIKE 'san8n_%'"
);

/**
 * Remove custom database tables if any
 * (Currently not used, but included for future expansion)
 */
$table_name = $wpdb->prefix . 'san8n_transactions';
if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
    $wpdb->query("DROP TABLE IF EXISTS $table_name");
}

/**
 * Clear WooCommerce session data
 */
$wpdb->query(
    "DELETE FROM {$wpdb->prefix}woocommerce_sessions 
     WHERE session_key LIKE '%san8n_%'"
);

/**
 * Clear any cached data
 */
if (function_exists('wp_cache_flush')) {
    wp_cache_flush();
}

/**
 * Log uninstall completion (optional)
 */
if (defined('WP_DEBUG') && WP_DEBUG === true) {
    error_log('Scan & Pay (n8n) plugin uninstalled and cleaned up successfully.');
}
