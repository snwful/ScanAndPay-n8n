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

            call_user_func('register_rest_route', SAN8N_REST_NAMESPACE, '/status/(?P<token>[a-zA-Z0-9_-]+)', array(
                'methods' => $readable,
                'callback' => array($this, 'get_status'),
                'permission_callback' => $permission_true,
                'args' => array(
                    'token' => array(
                        'required' => true,
                        'sanitize_callback' => $sanitize_text
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

            $settings = is_callable('get_option') ? call_user_func('get_option', SAN8N_OPTIONS_KEY, array()) : array();
            $n8n_url_raw = isset($settings['n8n_webhook_url']) ? $settings['n8n_webhook_url'] : '';
            $n8n_url = is_callable('esc_url_raw') ? call_user_func('esc_url_raw', $n8n_url_raw) : $n8n_url_raw;
            $shared_secret = isset($settings['shared_secret']) ? $settings['shared_secret'] : '';

            $attachment_path = is_callable('get_attached_file') ? call_user_func('get_attached_file', $attachment_id) : '';
            $this->strip_exif_data($attachment_path);

            $boundary = is_callable('wp_generate_password') ? call_user_func('wp_generate_password', 24, false) : substr(hash('sha256', uniqid('', true)), 0, 24);
            $body = $this->build_multipart_body($boundary, array(
                'slip_image' => array(
                    'filename' => basename($attachment_path),
                    'content' => @file_get_contents($attachment_path),
                    'type' => function_exists('mime_content_type') ? mime_content_type($attachment_path) : 'image/jpeg'
                ),
                'order' => (is_callable('wp_json_encode') ? call_user_func('wp_json_encode', array(
                    'id' => $order_id,
                    'total' => $order_total,
                    'currency' => (is_callable('get_woocommerce_currency') ? call_user_func('get_woocommerce_currency') : 'THB')
                )) : json_encode(array(
                    'id' => $order_id,
                    'total' => $order_total,
                    'currency' => 'THB'
                ))),
                'session_token' => $session_token
            ));

            $timestamp = time();
            $body_hash = hash('sha256', $body);
            $signature = hash_hmac('sha256', $timestamp . "\n" . $body_hash, $shared_secret);

            $response_data = array('status' => 'pending', 'reason' => 'verifier_unreachable', 'approved_amount' => 0.0);
            if ($n8n_url && is_callable('wp_remote_post')) {
                $response = call_user_func('wp_remote_post', $n8n_url, array(
                    'timeout' => 8,
                    'headers' => array(
                        'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
                        'X-PromptPay-Timestamp' => $timestamp,
                        'X-PromptPay-Signature' => $signature,
                        'X-PromptPay-Version' => '1.0',
                        'X-Correlation-ID' => $correlation_id
                    ),
                    'body' => $body
                ));

                $is_error = is_callable('is_wp_error') ? call_user_func('is_wp_error', $response) : false;
                if ($is_error) {
                    // Keep pending status to allow client to retry/poll
                    $response_data['reason'] = 'verifier_unreachable';
                } else {
                    $resp_body = is_callable('wp_remote_retrieve_body') ? call_user_func('wp_remote_retrieve_body', $response) : '';
                    $tmp = json_decode($resp_body, true);
                    if (is_array($tmp)) {
                        $response_data = (is_callable('wp_parse_args') ? call_user_func('wp_parse_args', $tmp, $response_data) : array_merge($response_data, $tmp));
                    }
                }
            }

            $order = is_callable('wc_get_order') ? call_user_func('wc_get_order', $order_id) : null;

            if ($response_data['status'] === 'approved') {
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
            return class_exists('WP_REST_Response') ? new WP_REST_Response($resp_payload, 200) : $resp_payload;
            }

            $reason = isset($response_data['reason']) ? (is_callable('sanitize_text_field') ? call_user_func('sanitize_text_field', $response_data['reason']) : (is_string($response_data['reason']) ? $response_data['reason'] : '')) : '';

            $resp_payload = array(
                'status' => 'rejected',
                'reason' => $reason,
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

    public function get_status($request) {
        $token = $request->get_param('token');
        
        // Stub implementation for v1 - always return pending
        $payload = array(
            'status' => 'pending',
            'message' => (is_callable('__') ? call_user_func('__', 'Status check endpoint reserved for future async implementation.', 'scanandpay-n8n') : 'Status check endpoint reserved for future async implementation.')
        );
        return class_exists('WP_REST_Response') ? new WP_REST_Response($payload, 200) : $payload;
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
