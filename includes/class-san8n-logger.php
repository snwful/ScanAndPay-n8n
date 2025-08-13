<?php
/**
 * Logger class with PII masking and correlation ID
 */

if (!defined('ABSPATH')) {
    exit;
}

class SAN8N_Logger {
    private $source;
    private $logger;
    private $log_level;
    private $correlation_id;

    public function __construct($correlation_id = null) {
        $this->source = SAN8N_LOGGER_SOURCE;
        $this->logger = wc_get_logger();
        
        $settings = get_option(SAN8N_OPTIONS_KEY, array());
        $this->log_level = isset($settings['log_level']) ? $settings['log_level'] : 'info';
        
        $this->correlation_id = $correlation_id ?: $this->generate_correlation_id();
    }

    private function generate_correlation_id() {
        return 'san8n_' . wp_generate_uuid4();
    }

    public function get_correlation_id() {
        return $this->correlation_id;
    }

    private function should_log($level) {
        $levels = array(
            'emergency' => 0,
            'alert' => 1,
            'critical' => 2,
            'error' => 3,
            'warning' => 4,
            'notice' => 5,
            'info' => 6,
            'debug' => 7
        );

        $current_level = isset($levels[$this->log_level]) ? $levels[$this->log_level] : 6;
        $requested_level = isset($levels[$level]) ? $levels[$level] : 6;

        return $requested_level <= $current_level;
    }

    private function mask_pii($message) {
        // Mask email addresses
        $message = preg_replace(
            '/([a-zA-Z0-9._%+-]+)@([a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/',
            '$1@***',
            $message
        );

        // Mask phone numbers (Thai format)
        $message = preg_replace(
            '/(\+?66|0)[\s-]?[0-9]{1,2}[\s-]?[0-9]{3}[\s-]?[0-9]{4}/',
            '***-***-****',
            $message
        );

        // Mask credit card-like numbers
        $message = preg_replace(
            '/\b[0-9]{13,19}\b/',
            '****-****-****-****',
            $message
        );

        return $message;
    }

    private function format_message($message, $context = array()) {
        $formatted = sprintf('[%s] %s', $this->correlation_id, $message);
        
        if (!empty($context)) {
            $formatted .= ' | Context: ' . wp_json_encode($context);
        }

        return $this->mask_pii($formatted);
    }

    public function log($level, $message, $context = array()) {
        if (!$this->should_log($level)) {
            return;
        }

        $formatted_message = $this->format_message($message, $context);
        $this->logger->log($level, $formatted_message, array('source' => $this->source));
    }

    public function emergency($message, $context = array()) {
        $this->log('emergency', $message, $context);
    }

    public function alert($message, $context = array()) {
        $this->log('alert', $message, $context);
    }

    public function critical($message, $context = array()) {
        $this->log('critical', $message, $context);
    }

    public function error($message, $context = array()) {
        $this->log('error', $message, $context);
    }

    public function warning($message, $context = array()) {
        $this->log('warning', $message, $context);
    }

    public function notice($message, $context = array()) {
        $this->log('notice', $message, $context);
    }

    public function info($message, $context = array()) {
        $this->log('info', $message, $context);
    }

    public function debug($message, $context = array()) {
        $this->log('debug', $message, $context);
    }
}
