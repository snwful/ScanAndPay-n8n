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
    // Match main plugin constant defined in scanandpay-n8n.php
    define('SAN8N_CAPABILITY', 'san8n_manage');
}

/**
 * Remove plugin options
 */
if (is_callable('delete_option')) {
    call_user_func('delete_option', SAN8N_OPTIONS_KEY);
    call_user_func('delete_option', 'san8n_db_version');
    call_user_func('delete_option', 'san8n_activation_time');
    call_user_func('delete_option', 'san8n_last_cleanup');
}

/**
 * Remove all transients
 */
global $wpdb;

// Delete transients (guarded)
if (isset($wpdb) && isset($wpdb->options) && method_exists($wpdb, 'query')) {
    $wpdb->query(
        "DELETE FROM {$wpdb->options} 
         WHERE option_name LIKE '_transient_san8n_%' 
         OR option_name LIKE '_transient_timeout_san8n_%'"
    );
}

// Delete site transients for multisite (guarded)
if ((is_callable('is_multisite') ? (bool) call_user_func('is_multisite') : false) && isset($wpdb) && isset($wpdb->sitemeta) && method_exists($wpdb, 'query')) {
    $wpdb->query(
        "DELETE FROM {$wpdb->sitemeta} 
         WHERE meta_key LIKE '_site_transient_san8n_%' 
         OR meta_key LIKE '_site_transient_timeout_san8n_%'"
    );
}

/**
 * Remove custom capability from all roles
 */
$roles = is_callable('wp_roles') ? call_user_func('wp_roles') : null;
if ($roles && isset($roles->roles) && is_array($roles->roles)) {
    foreach ($roles->roles as $role_name => $role_info) {
        $role = is_callable('get_role') ? call_user_func('get_role', $role_name) : null;
        if ($role && method_exists($role, 'has_cap') && $role->has_cap(SAN8N_CAPABILITY) && method_exists($role, 'remove_cap')) {
            $role->remove_cap(SAN8N_CAPABILITY);
        }
    }
}

/**
 * Clear scheduled cron jobs
 */
$timestamp = is_callable('wp_next_scheduled') ? call_user_func('wp_next_scheduled', 'san8n_retention_cleanup') : false;
if ($timestamp && is_callable('wp_unschedule_event')) {
    call_user_func('wp_unschedule_event', $timestamp, 'san8n_retention_cleanup');
}

// Clear all scheduled events for this plugin (guarded)
if (is_callable('wp_clear_scheduled_hook')) {
    call_user_func('wp_clear_scheduled_hook', 'san8n_retention_cleanup');
    call_user_func('wp_clear_scheduled_hook', 'san8n_hourly_check');
    call_user_func('wp_clear_scheduled_hook', 'san8n_rate_limit_reset');
}

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

$attachments = is_callable('get_posts') ? call_user_func('get_posts', $args) : array();
if (is_array($attachments)) {
    foreach ($attachments as $attachment) {
        if (is_object($attachment) && isset($attachment->ID) && is_callable('wp_delete_attachment')) {
            call_user_func('wp_delete_attachment', $attachment->ID, true);
        }
    }
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
if (isset($wpdb) && isset($wpdb->usermeta) && method_exists($wpdb, 'query')) {
    $wpdb->query(
        "DELETE FROM {$wpdb->usermeta} 
         WHERE meta_key LIKE 'san8n_%'"
    );
}

/**
 * Remove custom database tables if any
 * (Currently not used, but included for future expansion)
 */
if (isset($wpdb) && isset($wpdb->prefix) && method_exists($wpdb, 'get_var') && method_exists($wpdb, 'query')) {
    $table_name = $wpdb->prefix . 'san8n_transactions';
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
    if ($exists === $table_name) {
        $wpdb->query("DROP TABLE IF EXISTS `$table_name`");
    }
}

/**
 * Clear WooCommerce session data
 */
if (isset($wpdb) && isset($wpdb->prefix) && method_exists($wpdb, 'get_var') && method_exists($wpdb, 'query')) {
    $wc_sessions = $wpdb->prefix . 'woocommerce_sessions';
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wc_sessions));
    if ($exists === $wc_sessions) {
        $wpdb->query(
            "DELETE FROM `$wc_sessions` 
             WHERE session_key LIKE '%san8n_%'"
        );
    }
}

/**
 * Clear any cached data
 */
if (is_callable('wp_cache_flush')) {
    call_user_func('wp_cache_flush');
}

/**
 * Log uninstall completion (optional)
 */
if (defined('WP_DEBUG') && (bool) constant('WP_DEBUG') === true) {
    error_log('Scan & Pay (n8n) plugin uninstalled and cleaned up successfully.');
}
