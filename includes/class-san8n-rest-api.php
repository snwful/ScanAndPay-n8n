<?php
/**
 * REST API Handler
 */

if (!defined('ABSPATH')) {
    exit;
}

class SAN8N_REST_API {
    private $logger;
    private $rate_limit_attempts = 5;
    private $rate_limit_window = 60; // seconds

    public function __construct() {
        $this->logger = new SAN8N_Logger();
        if (is_callable('add_action')) {
            call_user_func('add_action', 'rest_api_init', array($this, 'register_routes'));
        }
    }

    /**
     * Proxy QR generation to n8n with HMAC headers
     */
    public function qr_proxy($request) {
        $correlation_id = $this->logger->get_correlation_id();
        $session_token_raw = $request->get_param('session_token');
        $this->logger->info('qr_proxy_request', array(
            'correlation_id' => $correlation_id,
            'session_token' => is_string($session_token_raw) ? $session_token_raw : '',
        ));
        try {
            // Normalize and sanitize inputs similar to verify_slip
            $order_id_raw = $request->get_param('order_id');
            $order_id = is_callable('absint') ? call_user_func('absint', $order_id_raw) : abs((int) $order_id_raw);

            $order_total_raw = $request->get_param('order_total');
            $order_total_str = (string) $order_total_raw;
            if (strpos($order_total_str, ',') !== false && strpos($order_total_str, '.') === false) {
                $order_total_str = str_replace(',', '.', $order_total_str);
            } else {
                $order_total_str = str_replace(',', '', $order_total_str);
            }
            $order_total = is_numeric($order_total_str) ? (float) $order_total_str : 0.0;

            $session_token_raw = $request->get_param('session_token');
            $session_token = is_callable('sanitize_text_field') ? call_user_func('sanitize_text_field', $session_token_raw) : (is_string($session_token_raw) ? $session_token_raw : '');

            $settings = is_callable('get_option') ? call_user_func('get_option', SAN8N_OPTIONS_KEY, array()) : array();
            $qr_url = $this->derive_qr_generate_url($settings);
            // Do not early-return here; we may still build a fallback after computing currency/ref_code.

            // Generate or reuse a numeric reference code stored in WC session
            $ref_code = null;
            $wc = is_callable('WC') ? call_user_func('WC') : null;
            if ($wc && isset($wc->session) && $wc->session) {
                $ttl = (defined('MINUTE_IN_SECONDS') ? constant('MINUTE_IN_SECONDS') : 60) * 10; // 10 minutes
                $now = time();
                $existing = method_exists($wc->session, 'get') ? $wc->session->get('san8n_ref_code') : null;
                $gen_ts = method_exists($wc->session, 'get') ? intval($wc->session->get('san8n_ref_code_time')) : 0;
                if (!empty($existing) && ($now - $gen_ts) < $ttl) {
                    $ref_code = (string) $existing;
                } else {
                    try {
                        // 7-8 digit numeric string
                        $num = random_int(1000000, 99999999);
                        $ref_code = (string) $num;
                    } catch (Exception $e) {
                        $ref_code = (string) mt_rand(1000000, 99999999);
                    }
                    if (method_exists($wc->session, 'set')) {
                        $wc->session->set('san8n_ref_code', $ref_code);
                        $wc->session->set('san8n_ref_code_time', $now);
                    }
                }
            }

            // Fallback when WC session is unavailable in REST context: cache by session_token
            if (empty($ref_code)) {
                $ttl = (defined('MINUTE_IN_SECONDS') ? constant('MINUTE_IN_SECONDS') : 60) * 10; // 10 minutes
                $tok_key = 'san8n_ref_tok_' . hash('sha256', (string) $session_token);
                $existing_tok = is_callable('get_transient') ? call_user_func('get_transient', $tok_key) : false;
                if (!empty($existing_tok)) {
                    $ref_code = (string) $existing_tok;
                } else {
                    try {
                        $num = random_int(1000000, 99999999);
                        $ref_code = (string) $num;
                    } catch (Exception $e) {
                        $ref_code = (string) mt_rand(1000000, 99999999);
                    }
                    if (is_callable('set_transient')) {
                        call_user_func('set_transient', $tok_key, $ref_code, $ttl);
                    }
                }
                // If WC session becomes available later, persist there too for consistency
                if (empty($existing) && $wc && isset($wc->session) && $wc->session && method_exists($wc->session, 'set')) {
                    $wc->session->set('san8n_ref_code', $ref_code);
                    $wc->session->set('san8n_ref_code_time', time());
                }
            }

            $currency = function_exists('get_woocommerce_currency') ? call_user_func('get_woocommerce_currency') : 'THB';

            // Server-side idempotency cache: reuse recent QR for the same session + amount + currency
            $cache_key = null;
            $cached = null;
            if (is_callable('get_transient')) {
                $amt_key = number_format((float) $order_total, 2, '.', '');
                $cache_key = 'san8n_qr_' . hash('sha256', (string) $session_token . '|' . $amt_key . '|' . (string) $currency);
                $maybe = call_user_func('get_transient', $cache_key);
                if (is_array($maybe)) {
                    // If backend provided expiry, ensure it's not already expired (leave a small safety window)
                    $now = time();
                    $exp_ok = !isset($maybe['expires_epoch']) || (intval($maybe['expires_epoch']) - $now) > 2;
                    if ($exp_ok) {
                        $resp_payload = array(
                            'order_id' => isset($maybe['order_id']) ? $maybe['order_id'] : $order_id,
                            'amount' => isset($maybe['amount']) ? $maybe['amount'] : $order_total,
                            'currency' => isset($maybe['currency']) ? $maybe['currency'] : $currency,
                            'session_token' => isset($maybe['session_token']) ? $maybe['session_token'] : $session_token,
                            'emv' => isset($maybe['emv']) ? $maybe['emv'] : null,
                            'qr_url' => isset($maybe['qr_url']) ? $maybe['qr_url'] : null,
                            'expires_epoch' => isset($maybe['expires_epoch']) ? $maybe['expires_epoch'] : null,
                            'ref_code' => isset($maybe['ref_code']) ? $maybe['ref_code'] : $ref_code,
                            'correlation_id' => $correlation_id,
                        );
                        return class_exists('WP_REST_Response') ? new WP_REST_Response($resp_payload, 200) : $resp_payload;
                    }
                }
            }

            // If no dynamic QR backend is configured, return a fallback payload carrying ref_code and expiry
            if (empty($qr_url)) {
                $now = time();
                $settings2 = is_callable('get_option') ? call_user_func('get_option', SAN8N_OPTIONS_KEY, array()) : array();
                $default_qr_expiry = isset($settings2['qr_expiry_seconds']) ? max(15, intval($settings2['qr_expiry_seconds'])) : 60;

                $resp_payload = array(
                    'order_id' => $order_id,
                    'amount' => $order_total,
                    'currency' => $currency,
                    'session_token' => (string) $session_token,
                    'emv' => null,
                    'qr_url' => null, // static image remains
                    'expires_epoch' => $now + $default_qr_expiry,
                    'ref_code' => (string) $ref_code,
                    'correlation_id' => $correlation_id,
                );

                // Cache this fallback payload keyed by session+amount+currency for idempotency
                if (is_callable('set_transient')) {
                    $amt_key = number_format((float) $order_total, 2, '.', '');
                    // String concatenation uses '.' in PHP
                    $cache_key = 'san8n_qr_' . hash('sha256', (string) $session_token . '|' . $amt_key . '|' . (string) $currency);
                    call_user_func('set_transient', $cache_key, array(
                        'order_id' => $resp_payload['order_id'],
                        'amount' => $resp_payload['amount'],
                        'currency' => $resp_payload['currency'],
                        'session_token' => $resp_payload['session_token'],
                        'emv' => $resp_payload['emv'],
                        'qr_url' => $resp_payload['qr_url'],
                        'expires_epoch' => $resp_payload['expires_epoch'],
                        'ref_code' => $resp_payload['ref_code'],
                        'cached_at' => $now,
                    ), max(5, $default_qr_expiry - 2));
                }

                return class_exists('WP_REST_Response') ? new WP_REST_Response($resp_payload, 200) : $resp_payload;
            }

            $payload_arr = array(
                'order_id' => $order_id,
                'amount' => $order_total,
                'currency' => $currency,
                'session_token' => (string) $session_token,
                'ref_code' => (string) $ref_code,
            );
            $payload = function_exists('wp_json_encode') ? call_user_func('wp_json_encode', $payload_arr) : json_encode($payload_arr);

            $timestamp = time();
            $body_hash = hash('sha256', (string) $payload);
            $secret = isset($settings['shared_secret']) ? (string) $settings['shared_secret'] : '';
            $signature = hash_hmac('sha256', $timestamp . "\n" . $body_hash, (string) $secret);

            $headers = array(
                'Content-Type' => 'application/json',
                'X-PromptPay-Timestamp' => $timestamp,
                'X-PromptPay-Signature' => $signature,
                'X-PromptPay-Version' => '1.0',
                'X-Correlation-ID' => (string) $correlation_id,
                'X-Idempotency-Key' => hash('sha256', (string) $session_token . '|' . (string) $order_id),
            );

            $timeout = is_callable('apply_filters') ? call_user_func('apply_filters', 'san8n_qr_proxy_timeout', 8) : 8;
            $this->logger->info('qr_proxy_outbound', array(
                'url' => $qr_url,
                'correlation_id' => $correlation_id,
                'session_token' => $session_token,
            ));
            $response = is_callable('wp_remote_post') ? call_user_func('wp_remote_post', $qr_url, array(
                'timeout' => $timeout,
                'sslverify' => true,
                'headers' => $headers,
                'body' => (string) $payload,
            )) : null;

            $is_err = is_callable('is_wp_error') ? call_user_func('is_wp_error', $response) : ($response === null);
            if ($is_err) {
                $msg = $this->get_error_message('verifier_unreachable');
                return new WP_Error('verifier_unreachable', $msg, array('status' => 502));
            }

            $resp_code = is_callable('wp_remote_retrieve_response_code') ? call_user_func('wp_remote_retrieve_response_code', $response) : 200;
            $resp_body = is_callable('wp_remote_retrieve_body') ? call_user_func('wp_remote_retrieve_body', $response) : '';
            $this->logger->info('qr_proxy_response', array(
                'status_code' => $resp_code,
                'correlation_id' => $correlation_id,
                'session_token' => $session_token,
            ));
            $data = json_decode($resp_body, true);
            if (!is_array($data)) {
                $data = array('error' => 'bad_response');
            }

            // Normalize and include correlation id
            $resp_payload = array(
                'order_id' => isset($data['order_id']) ? $data['order_id'] : $order_id,
                'amount' => isset($data['amount']) ? $data['amount'] : $order_total,
                'currency' => isset($data['currency']) ? $data['currency'] : $currency,
                'session_token' => isset($data['session_token']) ? $data['session_token'] : $session_token,
                'emv' => isset($data['emv']) ? $data['emv'] : null,
                'qr_url' => isset($data['qr_url']) ? $data['qr_url'] : null,
                'expires_epoch' => isset($data['expires_epoch']) ? $data['expires_epoch'] : null,
                'ref_code' => isset($data['ref_code']) ? $data['ref_code'] : $ref_code,
                'correlation_id' => $correlation_id,
            );

            // Cache successful responses to avoid duplicate upstream calls
            if ($resp_code >= 200 && $resp_code < 300 && is_callable('set_transient') && isset($cache_key)) {
                $now = time();
                $expires_epoch_val = isset($resp_payload['expires_epoch']) ? intval($resp_payload['expires_epoch']) : 0;
                // Derive TTL: prefer backend expiry; otherwise use plugin default (qr_expiry_seconds, default 60)
                $settings2 = is_callable('get_option') ? call_user_func('get_option', SAN8N_OPTIONS_KEY, array()) : array();
                $default_qr_expiry = isset($settings2['qr_expiry_seconds']) ? max(15, intval($settings2['qr_expiry_seconds'])) : 60;
                $ttl = $default_qr_expiry;
                if ($expires_epoch_val > $now) {
                    $ttl = max(5, $expires_epoch_val - $now - 2); // leave a 2s safety window
                }
                $cache_payload = array(
                    'order_id' => $resp_payload['order_id'],
                    'amount' => $resp_payload['amount'],
                    'currency' => $resp_payload['currency'],
                    'session_token' => $resp_payload['session_token'],
                    'emv' => $resp_payload['emv'],
                    'qr_url' => $resp_payload['qr_url'],
                    'expires_epoch' => $resp_payload['expires_epoch'],
                    'ref_code' => $resp_payload['ref_code'],
                    'cached_at' => $now,
                );
                call_user_func('set_transient', $cache_key, $cache_payload, $ttl);
            }

            $status = ($resp_code >= 200 && $resp_code < 300) ? 200 : 502;
            return class_exists('WP_REST_Response') ? new WP_REST_Response($resp_payload, $status) : $resp_payload;

        } catch (Exception $e) {
            $this->logger->error('QR proxy failed', array('error' => $e->getMessage()));
            $msg = is_callable('__') ? call_user_func('__', 'QR generation failed.', 'scanandpay-n8n') : 'QR generation failed.';
            return new WP_Error('qr_generation_failed', $msg, array('status' => 500));
        }
    }

    /**
     * Derive the n8n QR generate URL from settings with a filter for overrides.
     * @param array $settings
     * @return string
     */
    private function derive_qr_generate_url($settings) {
        // 1) Explicit override takes precedence
        $override = isset($settings['qr_generate_webhook_url']) ? (string) $settings['qr_generate_webhook_url'] : '';
        if (!empty($override)) {
            // Enforce HTTPS only
            $p = @parse_url($override);
            if (is_array($p) && isset($p['scheme']) && strtolower((string) $p['scheme']) === 'https') {
                if (is_callable('apply_filters')) {
                    $override = call_user_func('apply_filters', 'san8n_qr_generate_url', $override, $override, $settings);
                }
                return (string) $override;
            }
        }

        // 2) Derive from n8n_webhook_url
        $base = isset($settings['n8n_webhook_url']) ? (string) $settings['n8n_webhook_url'] : '';
        $derived = '';
        if (!empty($base)) {
            $trim = rtrim($base, '/');
            // Common case: replace last segment with qr-generate
            $replaced = preg_replace('~(/)([^/]+)$~', '$1qr-generate', $trim);
            if (is_string($replaced) && $replaced !== $trim) {
                $derived = $replaced;
            } else {
                // If webhook segment exists, append qr-generate next to it
                if (strpos($trim, '/webhook/') !== false) {
                    $derived = rtrim(dirname($trim), '/') . '/qr-generate';
                } else {
                    // Fallback to domain + /webhook/qr-generate
                    $parts = parse_url($trim);
                    if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
                        $derived = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . '/webhook/qr-generate';
                    }
                }
            }
        }
        if (is_callable('apply_filters')) {
            $derived = call_user_func('apply_filters', 'san8n_qr_generate_url', $derived, $base, $settings);
        }
        // Ensure HTTPS only
        $p2 = @parse_url($derived);
        if (!is_array($p2) || !isset($p2['scheme']) || strtolower((string) $p2['scheme']) !== 'https') {
            return '';
        }
        return (string) $derived;
    }

    public function register_routes() {
        $creatable = class_exists('WP_REST_Server') ? WP_REST_Server::CREATABLE : 'POST';
        $readable = class_exists('WP_REST_Server') ? WP_REST_Server::READABLE : 'GET';
        $permission_true = is_callable('__return_true') ? '__return_true' : function () { return true; };
        $sanitize_text = is_callable('sanitize_text_field') ? 'sanitize_text_field' : function ($v) { return is_string($v) ? $v : ''; };

        if (is_callable('register_rest_route')) {
            call_user_func('register_rest_route', SAN8N_REST_NAMESPACE, '/verify-slip', array(
                'methods' => $creatable,
                'callback' => array($this, 'verify_slip'),
                'permission_callback' => array($this, 'verify_permission'),
                'args' => array(
                    'slip_image' => array(
                        'required' => false,
                        'validate_callback' => array($this, 'validate_image')
                    ),
                    'session_token' => array(
                        'required' => true,
                        'sanitize_callback' => $sanitize_text
                    ),
                    'order_id' => array(
                        'required' => true,
                        'validate_callback' => function($param) {
                            return is_numeric($param);
                        }
                    ),
                    'order_total' => array(
                        'required' => true,
                        'validate_callback' => function($param) {
                            return is_numeric($param);
                        }
                    )
                )
            ));

            // QR Proxy endpoint to generate PromptPay QR via n8n
            call_user_func('register_rest_route', SAN8N_REST_NAMESPACE, '/qr-proxy', array(
                'methods' => $creatable,
                'callback' => array($this, 'qr_proxy'),
                'permission_callback' => array($this, 'verify_permission'),
                'args' => array(
                    'session_token' => array(
                        'required' => true,
                        'sanitize_callback' => $sanitize_text
                    ),
                    'order_id' => array(
                        'required' => true,
                        'validate_callback' => function($param) { return is_numeric($param); }
                    ),
                    'order_total' => array(
                        'required' => true,
                        'validate_callback' => function($param) { return is_numeric($param); }
                    )
                )
            ));
        }
    }

    public function verify_permission($request) {
        // Check nonce
        $nonce = method_exists($request, 'get_header') ? $request->get_header('X-WP-Nonce') : '';
        if (is_callable('wp_verify_nonce')) {
            if (!call_user_func('wp_verify_nonce', $nonce, 'wp_rest')) {
                return false;
            }
        }

        // Check rate limit
        if (!$this->check_rate_limit()) {
            $msg = is_callable('__') ? call_user_func('__', 'Too many requests. Please try again later.', 'scanandpay-n8n') : 'Too many requests. Please try again later.';
            return new WP_Error('rate_limited', $msg, array('status' => 429));
        }

        return true;
    }

    private function check_rate_limit() {
        $ip = $this->get_client_ip();
        $transient_key = 'san8n_rl_' . md5($ip);

        if (!is_callable('get_transient') || !is_callable('set_transient')) {
            return true; // Cannot rate limit without WP transients
        }

        $attempts = call_user_func('get_transient', $transient_key);

        if (false === $attempts) {
            call_user_func('set_transient', $transient_key, 1, $this->rate_limit_window);
            return true;
        }

        if ($attempts >= $this->rate_limit_attempts) {
            $this->logger->warning('Rate limit exceeded', array('ip' => $ip));
            return false;
        }

        call_user_func('set_transient', $transient_key, $attempts + 1, $this->rate_limit_window);
        return true;
    }

    private function get_client_ip() {
        $ip_keys = array('HTTP_CF_CONNECTING_IP', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = filter_var($_SERVER[$key], FILTER_VALIDATE_IP);
                if ($ip !== false) {
                    return $ip;
                }
            }
        }
        return '127.0.0.1';
    }

    public function validate_image($file_data) {
        if (!isset($_FILES['slip_image'])) {
            $msg = is_callable('__') ? call_user_func('__', 'No file uploaded.', 'scanandpay-n8n') : 'No file uploaded.';
            return new WP_Error('upload_missing', $msg);
        }

        $file = $_FILES['slip_image'];
        $settings = is_callable('get_option') ? call_user_func('get_option', SAN8N_OPTIONS_KEY, array()) : array();
        $max_size = isset($settings['max_file_size']) ? intval($settings['max_file_size']) * 1024 * 1024 : 5 * 1024 * 1024;

        // Check file size
        if (!empty($file['size']) && $file['size'] > $max_size) {
            $msg = is_callable('__') ? call_user_func('__', 'File size exceeds limit.', 'scanandpay-n8n') : 'File size exceeds limit.';
            return new WP_Error('upload_size', $msg);
        }

        // Check file type
        $allowed_types = array('image/jpeg', 'image/jpg', 'image/png');
        $file_type = is_callable('wp_check_filetype') ? call_user_func('wp_check_filetype', $file['name']) : array('ext' => pathinfo($file['name'], PATHINFO_EXTENSION));
        $mime_guess = 'image/' . strtolower($file_type['ext']);
        $current_type = isset($file['type']) ? $file['type'] : $mime_guess;
        
        if (!in_array($current_type, $allowed_types, true) || !in_array($mime_guess, $allowed_types, true)) {
            $msg = is_callable('__') ? call_user_func('__', 'Invalid file type.', 'scanandpay-n8n') : 'Invalid file type.';
            return new WP_Error('upload_type', $msg);
        }

        return true;
    }

    public function verify_slip($request) {
        $correlation_id = $this->logger->get_correlation_id();

        try {
            $attachment_id = $this->process_file_upload();
            $is_error = is_callable('is_wp_error') ? call_user_func('is_wp_error', $attachment_id) : ($attachment_id instanceof WP_Error);
            if ($is_error) {
                return $attachment_id;
            }

            $order_id_raw = $request->get_param('order_id');
            $order_id = is_callable('absint') ? call_user_func('absint', $order_id_raw) : abs((int) $order_id_raw);
            $order_total_raw = $request->get_param('order_total');
            $order_total_str = (string) $order_total_raw;
            if (strpos($order_total_str, ',') !== false && strpos($order_total_str, '.') === false) {
                // Likely using comma as decimal separator
                $order_total_str = str_replace(',', '.', $order_total_str);
            } else {
                // Remove thousands separators
                $order_total_str = str_replace(',', '', $order_total_str);
            }
            $order_total = is_numeric($order_total_str) ? (float) $order_total_str : 0.0;
            $session_token_raw = $request->get_param('session_token');
            $session_token = is_callable('sanitize_text_field') ? call_user_func('sanitize_text_field', $session_token_raw) : (is_string($session_token_raw) ? $session_token_raw : '');
            $this->logger->info('verify_slip_request', array(
                'correlation_id' => $correlation_id,
                'session_token' => $session_token,
                'order_id' => $order_id,
            ));

            $settings = is_callable('get_option') ? call_user_func('get_option', SAN8N_OPTIONS_KEY, array()) : array();

            $attachment_path = is_callable('get_attached_file') ? call_user_func('get_attached_file', $attachment_id) : '';
            $this->strip_exif_data($attachment_path);

            // Use verifier factory to support dynamic backend selection (n8n or Laravel)
            $verifier = class_exists('SAN8N_Verifier_Factory') ? SAN8N_Verifier_Factory::make($settings) : null;
            $response_data = array('status' => 'rejected', 'reason' => 'verifier_unreachable', 'message' => 'verifier_unreachable', 'approved_amount' => 0.0);
            if ($verifier) {
                $tmp = $verifier->verify($attachment_path, $order_id, $order_total, $session_token, $correlation_id);
                if (is_array($tmp)) {
                    $response_data = (is_callable('wp_parse_args') ? call_user_func('wp_parse_args', $tmp, $response_data) : array_merge($response_data, $tmp));
                }
            }

            // Map adapter 'message' to 'reason' if reason is missing or invalid
            if (isset($response_data['message']) && (!isset($response_data['reason']) || !is_string($response_data['reason']) || $response_data['reason'] === '')) {
                $response_data['reason'] = $response_data['message'];
            }

            $order = is_callable('wc_get_order') ? call_user_func('wc_get_order', $order_id) : null;

            if ($response_data['status'] === 'approved') {
                $this->logger->info('verify_slip_result', array(
                    'status' => 'approved',
                    'correlation_id' => $correlation_id,
                    'session_token' => $session_token,
                ));
                $reference_id = isset($response_data['reference_id']) ? (is_callable('sanitize_text_field') ? call_user_func('sanitize_text_field', $response_data['reference_id']) : (is_string($response_data['reference_id']) ? $response_data['reference_id'] : '')) : '';
                $approved_amount = isset($response_data['approved_amount']) ? floatval($response_data['approved_amount']) : 0;

                if ($order) {
                    $order->update_meta_data('_san8n_status', 'approved');
                    $order->update_meta_data('_san8n_reference_id', $reference_id);
                    $order->update_meta_data('_san8n_approved_amount', $approved_amount);
                    $order->update_meta_data('_san8n_attachment_id', $attachment_id);
                    $order->update_meta_data('_san8n_last_checked', (is_callable('current_time') ? call_user_func('current_time', 'mysql') : date('Y-m-d H:i:s')));
                    $order->payment_complete($reference_id);
                } else {
                    $wc = is_callable('WC') ? call_user_func('WC') : null;
                    if ($wc && isset($wc->session) && $wc->session) {
                        $wc->session->set(SAN8N_SESSION_FLAG, true);
                        $wc->session->set('san8n_attachment_id', $attachment_id);
                        $wc->session->set('san8n_approved_amount', $approved_amount);
                    }
                }

                $resp_payload = array(
                    'status' => 'approved',
                    'reference_id' => $reference_id,
                    'approved_amount' => $approved_amount,
                    'correlation_id' => $correlation_id
                );
                // Persist approval tied to the session token as a fallback when WC session isn't available
                $ttl = (defined('MINUTE_IN_SECONDS') ? constant('MINUTE_IN_SECONDS') : 60) * 10; // 10 minutes
                $transient_key = 'san8n_tok_' . hash('sha256', (string) $session_token);
                if (is_callable('set_transient')) {
                    call_user_func('set_transient', $transient_key, array(
                        'approved' => true,
                        'attachment_id' => $attachment_id,
                        'approved_amount' => $approved_amount,
                        'reference_id' => $reference_id,
                    ), $ttl);
                }

                return class_exists('WP_REST_Response') ? new WP_REST_Response($resp_payload, 200) : $resp_payload;
            }

            $this->logger->info('verify_slip_result', array(
                'status' => 'rejected',
                'reason' => isset($response_data['reason']) ? $response_data['reason'] : '',
                'correlation_id' => $correlation_id,
                'session_token' => $session_token,
            ));
            $reason = isset($response_data['reason']) ? (is_callable('sanitize_text_field') ? call_user_func('sanitize_text_field', $response_data['reason']) : (is_string($response_data['reason']) ? $response_data['reason'] : '')) : '';
            $message = isset($response_data['message']) ? (is_callable('sanitize_text_field') ? call_user_func('sanitize_text_field', $response_data['message']) : (is_string($response_data['message']) ? $response_data['message'] : '')) : $reason;

            $resp_payload = array(
                'status' => 'rejected',
                'reason' => $reason,
                'message' => $message,
                'correlation_id' => $correlation_id
            );
            return class_exists('WP_REST_Response') ? new WP_REST_Response($resp_payload, 200) : $resp_payload;

        } catch (Exception $e) {
            $this->logger->error('Verification failed', array('error' => $e->getMessage()));
            $msg = is_callable('__') ? call_user_func('__', 'Verification failed.', 'scanandpay-n8n') : 'Verification failed.';
            return new WP_Error('verification_failed', $msg, array('status' => 500));
        }
    }

    private function process_file_upload() {
        if (!isset($_FILES['slip_image'])) {
            $msg = is_callable('__') ? call_user_func('__', 'No file uploaded.', 'scanandpay-n8n') : 'No file uploaded.';
            return new WP_Error('upload_missing', $msg);
        }

        if (!defined('ABSPATH')) {
            return new WP_Error('upload_failed', 'ABSPATH not defined.');
        }
        $img_inc = ABSPATH . 'wp-admin/includes/image.php';
        $file_inc = ABSPATH . 'wp-admin/includes/file.php';
        $media_inc = ABSPATH . 'wp-admin/includes/media.php';
        if (file_exists($img_inc)) require_once($img_inc);
        if (file_exists($file_inc)) require_once($file_inc);
        if (file_exists($media_inc)) require_once($media_inc);

        // Generate random filename
        $file = $_FILES['slip_image'];
        if (!empty($file['error'])) {
            $msg = is_callable('__') ? call_user_func('__', 'Upload failed.', 'scanandpay-n8n') : 'Upload failed.';
            return new WP_Error('upload_failed', $msg);
        }
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $random_name = 'slip_' . (is_callable('wp_generate_password') ? call_user_func('wp_generate_password', 16, false) : substr(hash('sha256', uniqid('', true)), 0, 16)) . '.' . $ext;
        $_FILES['slip_image']['name'] = $random_name;

        // Handle upload
        if (!is_callable('media_handle_upload')) {
            return new WP_Error('upload_failed', 'Media upload not available.');
        }
        $attachment_id = call_user_func('media_handle_upload', 'slip_image', 0);

        $is_error = is_callable('is_wp_error') ? call_user_func('is_wp_error', $attachment_id) : ($attachment_id instanceof WP_Error);
        if ($is_error) { return $attachment_id; }

        // Mark as slip attachment
        if (is_callable('update_post_meta')) { call_user_func('update_post_meta', $attachment_id, '_san8n_slip', '1'); }
        if (is_callable('update_post_meta')) { call_user_func('update_post_meta', $attachment_id, '_san8n_upload_time', (is_callable('current_time') ? call_user_func('current_time', 'mysql') : date('Y-m-d H:i:s'))); }

        return $attachment_id;
    }

    private function strip_exif_data($file_path) {
        if (!file_exists($file_path)) {
            return;
        }

        if (!function_exists('exif_imagetype')) { return; }
        $image_type = @exif_imagetype($file_path);
        
        if ($image_type === IMAGETYPE_JPEG) {
            if (function_exists('imagecreatefromjpeg')) {
                $image = imagecreatefromjpeg($file_path);
                if ($image) {
                    imagejpeg($image, $file_path, 90);
                    imagedestroy($image);
                }
            }
        } elseif ($image_type === IMAGETYPE_PNG) {
            if (function_exists('imagecreatefrompng')) {
                $image = imagecreatefrompng($file_path);
                if ($image) {
                    imagepng($image, $file_path, 9);
                    imagedestroy($image);
                }
            }
        }
    }

    private function build_multipart_body($boundary, $fields) {
        $body = '';

        foreach ($fields as $name => $value) {
            $body .= '--' . $boundary . "\r\n";
            
            if ($name === 'slip_image') {
                $body .= 'Content-Disposition: form-data; name="' . $name . '"; filename="' . $value['filename'] . '"' . "\r\n";
                $body .= 'Content-Type: ' . $value['type'] . "\r\n\r\n";
                $body .= $value['content'] . "\r\n";
            } else {
                $body .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n";
                $body .= $value . "\r\n";
            }
        }

        $body .= '--' . $boundary . '--' . "\r\n";

        return $body;
    }

    private function get_error_status_code($error_code) {
        $status_codes = array(
            'bad_signature' => 401,
            'old_timestamp' => 401,
            'rate_limited' => 429,
            'upload_type' => 400,
            'upload_size' => 400,
            'verifier_unreachable' => 502,
            'bad_request' => 400
        );

        return isset($status_codes[$error_code]) ? $status_codes[$error_code] : 400;
    }

    private function get_error_message($error_code) {
        $messages = array(
            'bad_signature' => (is_callable('__') ? call_user_func('__', 'Invalid signature.', 'scanandpay-n8n') : 'Invalid signature.'),
            'old_timestamp' => (is_callable('__') ? call_user_func('__', 'Request timestamp too old.', 'scanandpay-n8n') : 'Request timestamp too old.'),
            'rate_limited' => (is_callable('__') ? call_user_func('__', 'Too many requests. Please try again later.', 'scanandpay-n8n') : 'Too many requests. Please try again later.'),
            'upload_type' => (is_callable('__') ? call_user_func('__', 'Invalid file type.', 'scanandpay-n8n') : 'Invalid file type.'),
            'upload_size' => (is_callable('__') ? call_user_func('__', 'File size exceeds limit.', 'scanandpay-n8n') : 'File size exceeds limit.'),
            'verifier_unreachable' => (is_callable('__') ? call_user_func('__', 'Verification service unavailable. Please try again.', 'scanandpay-n8n') : 'Verification service unavailable. Please try again.'),
            'bad_request' => (is_callable('__') ? call_user_func('__', 'Invalid request.', 'scanandpay-n8n') : 'Invalid request.')
        );

        return isset($messages[$error_code]) ? $messages[$error_code] : (is_callable('__') ? call_user_func('__', 'An error occurred.', 'scanandpay-n8n') : 'An error occurred.');
    }
}
