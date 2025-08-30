<?php
/**
 * Verifier adapters for n8n and Laravel backends
 */

if (!defined('ABSPATH')) {
    exit;
}

interface SAN8N_Verifier_Interface {
    /**
     * Verify a payment slip
     *
     * @return array Response data with keys:
     *               - status: 'approved'|'rejected'
     *               - reference_id?: string
     *               - approved_amount?: float
     *               - reason?: string
     *               - message?: string
     */
    public function verify($attachment_path, $order_id, $order_total, $session_token, $correlation_id);
}

class SAN8N_Verifier_Factory {
    public static function make($settings) {
        $backend = isset($settings['verifier_backend']) ? (string) $settings['verifier_backend'] : 'n8n';
        if ($backend === 'laravel') {
            return new SAN8N_Verifier_Laravel($settings);
        }
        return new SAN8N_Verifier_N8n($settings);
    }
}

abstract class SAN8N_Verifier_Abstract implements SAN8N_Verifier_Interface {
    protected $url;
    protected $secret;
    protected $backend; // 'n8n' | 'laravel'

    protected function build_multipart_body($boundary, $fields) {
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

    protected function do_request($headers, $body, $logger = null, $session_token = '') {
        $timeout = function_exists('apply_filters') ? apply_filters('san8n_verifier_timeout', 8, $this->backend) : 8;
        // Default to 3 retries (total 4 attempts) to realize 1s,2s,4s backoff
        $default_retries = (defined('SAN8N_CALLBACK_ASYNC') && SAN8N_CALLBACK_ASYNC) ? 3 : 0;
        $retries = function_exists('apply_filters') ? (int) apply_filters('san8n_verifier_retries', $default_retries, $this->backend) : $default_retries;
        if (!(defined('SAN8N_CALLBACK_ASYNC') && SAN8N_CALLBACK_ASYNC)) {
            $retries = 0;
        }
        $attempt = 0;
        $last = null;
        $delay = 1; // seconds
        do {
            $attempt++;
            if ($logger) {
                $logger->info('outbound_call', array(
                    'url' => $this->url,
                    'attempt' => $attempt,
                    'session_token' => $session_token,
                    'correlation_id' => isset($headers['X-Correlation-ID']) ? $headers['X-Correlation-ID'] : '',
                ));
            }
            if (is_callable('wp_remote_post')) {
                $last = call_user_func('wp_remote_post', $this->url, array(
                    'timeout' => $timeout,
                    'sslverify' => true,
                    'headers' => $headers,
                    'body' => $body,
                ));
            }
            $is_err = is_callable('is_wp_error') ? call_user_func('is_wp_error', $last) : false;
            $status = $is_err ? 0 : (is_callable('wp_remote_retrieve_response_code') ? call_user_func('wp_remote_retrieve_response_code', $last) : 0);
            if ($logger) {
                $context = array(
                    'attempt' => $attempt,
                    'status_code' => $status,
                    'session_token' => $session_token,
                    'correlation_id' => isset($headers['X-Correlation-ID']) ? $headers['X-Correlation-ID'] : '',
                );
                if ($attempt > 1) { $context['retry_attempt'] = $attempt - 1; }
                $logger->info('outbound_response', $context);
            }
            if (!$is_err && $status >= 200 && $status < 500) {
                if ($attempt > 1 && $logger) {
                    $logger->info('retry_success', array(
                        'retry_success' => $attempt - 1,
                        'session_token' => $session_token,
                        'correlation_id' => isset($headers['X-Correlation-ID']) ? $headers['X-Correlation-ID'] : '',
                    ));
                }
                break;
            }
            if ($attempt > $retries) {
                if ($logger) {
                    $logger->error('retry_fail', array(
                        'retry_fail' => 1,
                        'session_token' => $session_token,
                        'correlation_id' => isset($headers['X-Correlation-ID']) ? $headers['X-Correlation-ID'] : '',
                    ));
                }
                break;
            }
            if (defined('SAN8N_CALLBACK_ASYNC') && SAN8N_CALLBACK_ASYNC) {
                $jitter = function_exists('mt_rand') ? mt_rand(0, 500) / 1000 : 0; // 0–0.5s
                if ($logger) {
                    $logger->info('retry_backoff', array(
                        'sleep_seconds' => $delay,
                        'jitter_seconds' => $jitter,
                        'next_attempt' => $attempt + 1,
                        'session_token' => $session_token,
                        'correlation_id' => isset($headers['X-Correlation-ID']) ? $headers['X-Correlation-ID'] : '',
                    ));
                }
                sleep($delay);
                if ($jitter > 0) { usleep((int) ($jitter * 1000000)); }
                $delay = min($delay * 2, 8); // cap growth to avoid runaway
            }
        } while (true);
        return $last;
    }
}

class SAN8N_Verifier_N8n extends SAN8N_Verifier_Abstract {
    public function __construct($settings) {
        $this->backend = 'n8n';
        $this->url = isset($settings['n8n_webhook_url']) ? (string) $settings['n8n_webhook_url'] : '';
        $this->secret = isset($settings['shared_secret']) ? (string) $settings['shared_secret'] : '';
    }

    public function verify($attachment_path, $order_id, $order_total, $session_token, $correlation_id) {
        if (empty($this->url)) {
            return array('status' => 'rejected', 'reason' => 'verifier_unreachable', 'message' => 'verifier_unreachable');
        }
        $boundary = function_exists('wp_generate_password') ? wp_generate_password(24, false) : substr(hash('sha256', uniqid('', true)), 0, 24);
        $order_payload = function_exists('wp_json_encode') ? wp_json_encode(array(
            'id' => $order_id,
            'total' => $order_total,
            'currency' => (function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'THB'),
        )) : json_encode(array('id' => $order_id, 'total' => $order_total, 'currency' => 'THB'));
        $body = $this->build_multipart_body($boundary, array(
            'slip_image' => array(
                'filename' => basename($attachment_path),
                'content' => @file_get_contents($attachment_path),
                'type' => function_exists('mime_content_type') ? mime_content_type($attachment_path) : 'image/jpeg',
            ),
            'order' => $order_payload,
            'session_token' => (string) $session_token,
        ));

        $timestamp = time();
        $body_hash = hash('sha256', $body);
        $signature = hash_hmac('sha256', $timestamp . "\n" . $body_hash, (string) $this->secret);

        $headers = array(
            'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            'X-PromptPay-Timestamp' => $timestamp,
            'X-PromptPay-Signature' => $signature,
            'X-PromptPay-Version' => '1.0',
            'X-Correlation-ID' => (string) $correlation_id,
            'X-Idempotency-Key' => hash('sha256', (string) $session_token . '|' . (string) $order_id),
        );

        $logger = new SAN8N_Logger($correlation_id);
        $response = $this->do_request($headers, $body, $logger, (string) $session_token);
        $is_err = is_callable('is_wp_error') ? call_user_func('is_wp_error', $response) : false;
        if ($is_err) {
            return array('status' => 'rejected', 'reason' => 'verifier_unreachable', 'message' => 'verifier_unreachable');
        }
        $resp_body = is_callable('wp_remote_retrieve_body') ? call_user_func('wp_remote_retrieve_body', $response) : '';
        $tmp = json_decode($resp_body, true);
        if (is_array($tmp)) {
            return $tmp;
        }
        return array('status' => 'rejected', 'reason' => 'bad_response', 'message' => 'bad_response');
    }
}

class SAN8N_Verifier_Laravel extends SAN8N_Verifier_Abstract {
    public function __construct($settings) {
        $this->backend = 'laravel';
        $this->url = isset($settings['laravel_verify_url']) ? (string) $settings['laravel_verify_url'] : '';
        $this->secret = isset($settings['laravel_secret']) ? (string) $settings['laravel_secret'] : '';
    }

    public function verify($attachment_path, $order_id, $order_total, $session_token, $correlation_id) {
        if (empty($this->url)) {
            return array('status' => 'rejected', 'reason' => 'verifier_unreachable', 'message' => 'verifier_unreachable');
        }
        // Use the same multipart contract and HMAC headers as n8n for parity
        $boundary = function_exists('wp_generate_password') ? wp_generate_password(24, false) : substr(hash('sha256', uniqid('', true)), 0, 24);
        $order_payload = function_exists('wp_json_encode') ? wp_json_encode(array(
            'id' => $order_id,
            'total' => $order_total,
            'currency' => (function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'THB'),
        )) : json_encode(array('id' => $order_id, 'total' => $order_total, 'currency' => 'THB'));
        $body = $this->build_multipart_body($boundary, array(
            'slip_image' => array(
                'filename' => basename($attachment_path),
                'content' => @file_get_contents($attachment_path),
                'type' => function_exists('mime_content_type') ? mime_content_type($attachment_path) : 'image/jpeg',
            ),
            'order' => $order_payload,
            'session_token' => (string) $session_token,
        ));

        $timestamp = time();
        $body_hash = hash('sha256', $body);
        $signature = hash_hmac('sha256', $timestamp . "\n" . $body_hash, (string) $this->secret);

        $headers = array(
            'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            'X-PromptPay-Timestamp' => $timestamp,
            'X-PromptPay-Signature' => $signature,
            'X-PromptPay-Version' => '1.0',
            'X-Correlation-ID' => (string) $correlation_id,
            'X-Idempotency-Key' => hash('sha256', (string) $session_token . '|' . (string) $order_id),
        );

        $logger = new SAN8N_Logger($correlation_id);
        $response = $this->do_request($headers, $body, $logger, (string) $session_token);
        $is_err = is_callable('is_wp_error') ? call_user_func('is_wp_error', $response) : false;
        if ($is_err) {
            return array('status' => 'rejected', 'reason' => 'verifier_unreachable', 'message' => 'verifier_unreachable');
        }
        $resp_body = is_callable('wp_remote_retrieve_body') ? call_user_func('wp_remote_retrieve_body', $response) : '';
        $tmp = json_decode($resp_body, true);
        if (is_array($tmp)) {
            return $tmp;
        }
        return array('status' => 'rejected', 'reason' => 'bad_response', 'message' => 'bad_response');
    }
}
