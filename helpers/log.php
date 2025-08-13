<?php
function san8n_log($msg, $ctx = array()) {
    if (!defined('WP_DEBUG') || !WP_DEBUG) {
        return;
    }
    $prefix = '[SAN8N] ';
    if (!empty($ctx)) {
        $msg .= ' ' . wp_json_encode($ctx);
    }
    error_log($prefix . $msg);
}
