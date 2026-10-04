<?php

/**
 * Security helper functions
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Verify nonce and capability for wallet operations
 */
function wallet_verify_security($nonce_name, $action, $capability = 'manage_options')
{
    if (!isset($_POST[$nonce_name]) || !wp_verify_nonce($_POST[$nonce_name], $action)) {
        wp_die(__('بررسی امنیتی ناموفق بود. لطفاً دوباره تلاش کنید.', 'medyar-toman-wallet'));
    }

    if (!current_user_can($capability)) {
        wp_die(__('شما اجازه انجام این عملیات را ندارید.', 'medyar-toman-wallet'));
    }

    return true;
}

/**
 * Sanitize decimal amount
 */
function wallet_sanitize_amount($amount)
{
    $amount = preg_replace('/[^0-9.]/', '', $amount);
    $amount = floatval($amount);
    return abs($amount);
}

/**
 * Sanitize balance type
 */
function wallet_sanitize_balance_type($type)
{
    $allowed = array('toman');
    if (function_exists('wwp_static_asset_keys')) {
        $allowed = array_merge($allowed, wwp_static_asset_keys(false));
    }
    if (class_exists('Asset_Settings_Manager')) {
        $allowed = array_merge($allowed, Asset_Settings_Manager::get_instance()->get_asset_keys(false));
    }
    if (defined('medyar_STATIC_ASSETS') && is_array(medyar_STATIC_ASSETS)) {
        $allowed = array_merge($allowed, array_map('sanitize_key', array_keys(medyar_STATIC_ASSETS)));
    }
    if (count($allowed) === 1) {
        $allowed = array_merge($allowed, array('silver', 'bullion'));
    }
    $allowed = array_values(array_unique(array_filter($allowed)));
    $type = strtolower(sanitize_text_field($type));

    return in_array($type, $allowed, true) ? $type : 'toman';
}

/**
 * Allowed wallet operation_type values (includes buy_{asset}/sell_{asset} from catalog).
 *
 * @return string[]
 */
function wallet_allowed_operation_types(): array
{
    $allowed = array(
        'charge',
        'spend',
        'trade_buy',
        'trade_sell',
        'withdraw',
        'delivery',
        'admin_add',
        'admin_reduce',
        'returned',
        'refund_order',
        'credit_collateral_freeze',
        'credit_grant',
        'credit_collateral_release',
        'credit_repay',
        'credit_grant_cancel',
        'invite_commission',
        'invite_commission_reversal',
    );

    $asset_keys = array();
    if (function_exists('wwp_static_asset_keys')) {
        $asset_keys = wwp_static_asset_keys(false);
    } elseif (class_exists('Asset_Settings_Manager')) {
        $asset_keys = Asset_Settings_Manager::get_instance()->get_asset_keys(false);
    } elseif (defined('medyar_STATIC_ASSETS') && is_array(medyar_STATIC_ASSETS)) {
        $asset_keys = array_map('sanitize_key', array_keys(medyar_STATIC_ASSETS));
    } else {
        $asset_keys = array('silver', 'bullion');
    }

    foreach ($asset_keys as $key) {
        $key = sanitize_key((string) $key);
        if ($key === '' || $key === 'toman') {
            continue;
        }
        $allowed[] = 'buy_' . $key;
        $allowed[] = 'sell_' . $key;
    }

    return array_values(array_unique($allowed));
}

/**
 * Sanitize operation type
 */
function wallet_sanitize_operation_type($type)
{
    $allowed = wallet_allowed_operation_types();
    $type = strtolower(sanitize_text_field($type));

    return in_array($type, $allowed, true) ? $type : 'spend';
}

/**
 * Sanitize status
 */
function wallet_sanitize_status($status)
{
    $allowed = [
        'pending',
        'processing',
        'completed',
        'canceled',
        'rejected',
        'to_bank',
        'returned',
        'minor',
        'minor_canceled',
        'minor_completed',
    ];
    $status = strtolower(sanitize_text_field($status));

    return in_array($status, $allowed) ? $status : 'pending';
}

/**
 * Check if user can manage their own wallet
 */
function wallet_can_manage_user($user_id)
{
    $current_user_id = get_current_user_id();

    if (current_user_can('manage_options')) {
        return true;
    }

    if ($current_user_id === (int)$user_id) {
        return true;
    }

    return false;
}

/**
 * تولید کد ۸ رقمی یکتا برای تراکنش
 */
function wallet_generate_transaction_code(): string
{
    try {
        $salt   = time();
        $random = random_int(10000000, 99999999);
    } catch (Exception $e) {
        $salt   = time();
        $random = mt_rand(10000000, 99999999);
    }

    $code = (string)(($salt ^ $random) % 90000000 + 10000000);

    return $code;
}

/**
 * Log security event
 */
function wallet_log_security_event($event, $user_id = 0)
{
    if ($user_id === 0) {
        $user_id = get_current_user_id();
    }

    error_log(sprintf(
        '[Wallet Security] User %d: %s',
        $user_id,
        $event
    ));
}