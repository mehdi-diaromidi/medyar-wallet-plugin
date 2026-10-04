<?php

/**
 * Payment Gateway Abstract Class
 * 
 * This abstract class defines the interface that all payment gateways must implement
 * Allows easy replacement with real gateways (Zarinpal, etc.) in the future
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

if (class_exists('Wallet_Payment_Gateway')) { return; }

abstract class Wallet_Payment_Gateway
{

    protected $gateway_id;
    protected $gateway_name;
    protected $enabled = true;

    /**
     * Initialize payment with gateway
     * 
     * @param float $amount Payment amount
     * @param int $user_id User ID
     * @param array $additional_data Additional payment data
     * @return array Payment result with keys: success, transaction_id, redirect_url, error
     */
    abstract public function initiate_payment($amount, $user_id, $additional_data = array());

    /**
     * Verify payment after callback
     * 
     * @param array $callback_data Data received from gateway callback
     * @return array Verification result with keys: success, amount, transaction_id, transaction_code, error
     */
    abstract public function verify_payment($callback_data);

    /**
     * Get gateway ID
     * 
     * @return string Gateway ID
     */
    public function get_gateway_id()
    {
        return $this->gateway_id;
    }

    /**
     * Get gateway name
     * 
     * @return string Gateway name
     */
    public function get_gateway_name()
    {
        return $this->gateway_name;
    }

    /**
     * Check if gateway is enabled
     * 
     * @return bool True if enabled
     */
    public function is_enabled()
    {
        return $this->enabled;
    }

    /**
     * Enable gateway
     */
    public function enable()
    {
        $this->enabled = true;
    }

    /**
     * Disable gateway
     */
    public function disable()
    {
        $this->enabled = false;
    }

    /**
     * Sanitize amount for gateway
     * 
     * @param float $amount Amount to sanitize
     * @return float Sanitized amount
     */
    protected function sanitize_amount($amount)
    {
        return abs(floatval($amount));
    }

    /**
     * Log gateway activity
     * 
     * @param string $message Log message
     * @param string $level Log level (info, error, warning)
     */
    protected function log($message, $level = 'info')
    {
        error_log(sprintf(
            '[Wallet Gateway %s] [%s] %s',
            $this->gateway_id,
            strtoupper($level),
            $message
        ));
    }

    /**
     * Generate callback URL
     * 
     * @param array $params URL parameters
     * @return string Callback URL
     */
    protected function get_callback_url($params = array())
    {
        $url = home_url('/wallet-payment-callback/');

        if (!empty($params)) {
            $url = add_query_arg($params, $url);
        }

        return $url;
    }
}

/**
 * Payment Gateway Manager
 * 
 * Manages available payment gateways
 */
class Wallet_Payment_Gateway_Manager
{

    private static $instance = null;
    private $gateways = array();

    /**
     * Get singleton instance
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        $this->load_gateways();
    }

    /**
     * Load available gateways
     */
    private function load_gateways()
    {
        // Hook for registering payment gateways (e.g. ZarinPal via its own loader)
        do_action('wallet_register_payment_gateways', $this);
    }

    /**
     * Register a payment gateway
     * 
     * @param Wallet_Payment_Gateway $gateway Gateway instance
     */
    public function register_gateway($gateway)
    {
        if ($gateway instanceof Wallet_Payment_Gateway) {
            $this->gateways[$gateway->get_gateway_id()] = $gateway;
        }
    }

    /**
     * Get gateway by ID
     * 
     * @param string $gateway_id Gateway ID
     * @return Wallet_Payment_Gateway|null Gateway instance or null
     */
    public function get_gateway($gateway_id)
    {
        return isset($this->gateways[$gateway_id]) ? $this->gateways[$gateway_id] : null;
    }

    /**
     * Get all registered gateways
     * 
     * @return array Array of gateway instances
     */
    public function get_all_gateways()
    {
        return $this->gateways;
    }

    /**
     * Get enabled gateways
     * 
     * @return array Array of enabled gateway instances
     */
    public function get_enabled_gateways()
    {
        return array_filter($this->gateways, function ($gateway) {
            return $gateway->is_enabled();
        });
    }
}
