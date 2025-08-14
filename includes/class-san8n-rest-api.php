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
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes() {
        register_rest_route(SAN8N_REST_NAMESPACE, '/verify-slip', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'verify_slip'),
            'permission_callback' => array($this, 'verify_permission'),
            'args' => array(
                'slip_image' => array(
                    'required' => false,
                    'validate_callback' => array($this, 'validate_image')
                ),
                'session_token' => array(
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'order_id' => array(
                    'required' => true,
                    'validate_callback' => 'is_numeric'
                ),
                'order_total' => array(
                    'required' => true,
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param >= 0;
                    }
                ),
                'customer_email' => array(
                    'required' => false,
                    'sanitize_callback' => 'sanitize_email'
                )
            )
        ));

        register_rest_route(SAN8N_REST_NAMESPACE, '/status/(?P<token>[a-zA-Z0-9_-]+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'get_status'),
            'permission_callback' => '__return_true',
            'args' => array(
                'token' => array(
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field'
                )
            )
        ));
    }

    public function verify_permission($request) {
        // Check nonce
        $nonce = $request->get_header('X-WP-Nonce');
        if (!wp_verify_nonce($nonce, 'wp_rest')) {
            return false;
        }

        // Check rate limit
        if (!$this->check_rate_limit()) {
            return new WP_Error('rate_limited', __('Too many requests. Please try again later.', 'scanandpay-n8n'), array('status' => 429));
        }

        return true;
    }

    private function check_rate_limit() {
        $ip = $this->get_client_ip();
        $transient_key = 'san8n_rl_' . md5($ip);
        $attempts = get_transient($transient_key);

        if (false === $attempts) {
            set_transient($transient_key, 1, $this->rate_limit_window);
            return true;
        }

        if ($attempts >= $this->rate_limit_attempts) {
            $this->logger->warning('Rate limit exceeded', array('ip' => $ip));
            return false;
        }

        set_transient($transient_key, $attempts + 1, $this->rate_limit_window);
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
            return new WP_Error('upload_missing', __('No file uploaded.', 'scanandpay-n8n'));
        }

        $file = $_FILES['slip_image'];
        $settings = get_option(SAN8N_OPTIONS_KEY, array());
        $max_size = isset($settings['max_file_size']) ? intval($settings['max_file_size']) * 1024 * 1024 : 5 * 1024 * 1024;

        // Check file size
        if ($file['size'] > $max_size) {
            return new WP_Error('upload_size', __('File size exceeds limit.', 'scanandpay-n8n'));
        }

        // Check file type
        $allowed_types = array('image/jpeg', 'image/jpg', 'image/png');
        $file_type = wp_check_filetype($file['name']);
        
        if (!in_array($file['type'], $allowed_types) || !in_array('image/' . $file_type['ext'], $allowed_types)) {
            return new WP_Error('upload_type', __('Invalid file type.', 'scanandpay-n8n'));
        }

        return true;
    }

    public function verify_slip($request) {
        $correlation_id = $this->logger->get_correlation_id();

        $this->logger->info('Starting slip verification', array(
            'correlation_id' => $correlation_id
        ));

        try {
            // Process file upload
            $attachment_id = $this->process_file_upload();
            if (is_wp_error($attachment_id)) {
                throw new Exception($attachment_id->get_error_message());
            }

            // Get parameters
            $order_id = intval($request->get_param('order_id'));
            $order_total = floatval($request->get_param('order_total'));
            $session_token = $request->get_param('session_token');
            $customer_email = $request->get_param('customer_email');

            // Prepare data for n8n
            $settings = get_option(SAN8N_OPTIONS_KEY, array());
            $n8n_url = isset($settings['n8n_webhook_url']) ? $settings['n8n_webhook_url'] : '';
            $shared_secret = isset($settings['shared_secret']) ? $settings['shared_secret'] : '';
            $store_id = get_bloginfo('name');

            if (empty($n8n_url) || empty($shared_secret)) {
                throw new Exception('Gateway not configured properly');
            }

            $attachment_path = get_attached_file($attachment_id);

            // Strip EXIF data
            $this->strip_exif_data($attachment_path);

            // Prepare multipart request
            $boundary = wp_generate_password(24, false);
            $body = $this->build_multipart_body($boundary, array(
                'slip_image' => array(
                    'filename' => basename($attachment_path),
                    'content' => file_get_contents($attachment_path),
                    'type' => mime_content_type($attachment_path)
                ),
                'order_id' => $order_id,
                'order_total' => $order_total,
                'session_token' => $session_token,
                'customer_email' => $customer_email,
                'store_id' => $store_id
            ));

            // Generate HMAC signature
            $timestamp = time();
            $body_hash = hash('sha256', $body);
            $signature = hash_hmac('sha256', $timestamp . "\n" . $body_hash, $shared_secret);

            // Make request to n8n
            $response = wp_remote_post($n8n_url, array(
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

            if (is_wp_error($response)) {
                $this->logger->error('n8n request failed', array(
                    'error' => $response->get_error_message()
                ));
                throw new Exception('verifier_unreachable');
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            $response_data = json_decode($response_body, true);

            if ($response_code === 200 && isset($response_data['status'])) {
                $status = $response_data['status'];
                $approved_amount = isset($response_data['approved_amount']) ? floatval($response_data['approved_amount']) : $order_total;
                $reference_id = isset($response_data['reference_id']) ? sanitize_text_field($response_data['reference_id']) : '';
                $reason = isset($response_data['reason']) ? sanitize_text_field($response_data['reason']) : '';

                if (WC()->session) {
                    WC()->session->set(SAN8N_SESSION_FLAG, $status === 'approved');
                    WC()->session->set('san8n_attachment_id', $attachment_id);
                    WC()->session->set('san8n_approved_amount', $approved_amount);
                }

                if ($order_id > 0) {
                    $order = wc_get_order($order_id);
                    if ($order) {
                        $order->update_meta_data('_san8n_status', $status);
                        $order->update_meta_data('_san8n_reference_id', $reference_id);
                        $order->update_meta_data('_san8n_approved_amount', $approved_amount);
                        $order->update_meta_data('_san8n_attachment_id', $attachment_id);
                        $order->update_meta_data('_san8n_last_checked', current_time('mysql'));
                        if ($status === 'approved') {
                            $order->payment_complete($reference_id);
                            $order->add_order_note(sprintf(
                                __('Payment approved via Scan & Pay (n8n). Reference: %s, Amount: %s THB', 'scanandpay-n8n'),
                                $reference_id,
                                wc_format_localized_price($approved_amount)
                            ));
                        } elseif ($status === 'rejected') {
                            $order->update_meta_data('_san8n_reason', $reason);
                            $order->add_order_note(sprintf(
                                __('Payment rejected via Scan & Pay (n8n). Reason: %s', 'scanandpay-n8n'),
                                $reason
                            ));
                        }
                        $order->save();
                    }
                }

                if ($status === 'approved') {
                    $this->logger->info('Payment approved', array(
                        'reference_id' => $reference_id,
                        'amount' => $approved_amount
                    ));

                    return new WP_REST_Response(array(
                        'status' => 'approved',
                        'reference_id' => $reference_id,
                        'approved_amount' => $approved_amount,
                        'correlation_id' => $correlation_id
                    ), 200);
                }

                if ($status === 'rejected') {
                    $this->logger->info('Payment rejected', array(
                        'reason' => $reason
                    ));

                    return new WP_REST_Response(array(
                        'status' => 'rejected',
                        'reason' => $reason,
                        'correlation_id' => $correlation_id
                    ), 200);
                }

                return new WP_REST_Response(array(
                    'status' => 'pending',
                    'correlation_id' => $correlation_id
                ), 200);
            }

            if (isset($response_data['error'])) {
                $error_code = $response_data['error'];
                $status_code = $this->get_error_status_code($error_code);

                return new WP_Error($error_code, $this->get_error_message($error_code), array('status' => $status_code));
            }

            throw new Exception('bad_request');

        } catch (Exception $e) {
            $this->logger->error('Verification failed', array(
                'error' => $e->getMessage()
            ));

            $error_code = $e->getMessage();
            $status_code = $this->get_error_status_code($error_code);

            return new WP_Error($error_code, $this->get_error_message($error_code), array('status' => $status_code));
        }
    }

    private function process_file_upload() {
        if (!isset($_FILES['slip_image'])) {
            return new WP_Error('upload_missing', __('No file uploaded.', 'scanandpay-n8n'));
        }

        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        // Generate random filename
        $file = $_FILES['slip_image'];
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $random_name = 'slip_' . wp_generate_password(16, false) . '.' . $ext;
        $_FILES['slip_image']['name'] = $random_name;

        // Handle upload
        $attachment_id = media_handle_upload('slip_image', 0);

        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        // Mark as slip attachment
        update_post_meta($attachment_id, '_san8n_slip', '1');
        update_post_meta($attachment_id, '_san8n_upload_time', current_time('mysql'));

        return $attachment_id;
    }

    private function strip_exif_data($file_path) {
        if (!file_exists($file_path)) {
            return;
        }

        $image_type = exif_imagetype($file_path);
        
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
        return new WP_REST_Response(array(
            'status' => 'pending',
            'message' => __('Status check endpoint reserved for future async implementation.', 'scanandpay-n8n')
        ), 200);
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
            'bad_signature' => __('Invalid signature.', 'scanandpay-n8n'),
            'old_timestamp' => __('Request timestamp too old.', 'scanandpay-n8n'),
            'rate_limited' => __('Too many requests. Please try again later.', 'scanandpay-n8n'),
            'upload_type' => __('Invalid file type.', 'scanandpay-n8n'),
            'upload_size' => __('File size exceeds limit.', 'scanandpay-n8n'),
            'verifier_unreachable' => __('Verification service unavailable. Please try again.', 'scanandpay-n8n'),
            'bad_request' => __('Invalid request.', 'scanandpay-n8n')
        );

        return isset($messages[$error_code]) ? $messages[$error_code] : __('An error occurred.', 'scanandpay-n8n');
    }
}
