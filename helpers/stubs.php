<?php
if (!defined('ABSPATH')) { exit; }

if (!function_exists('medyar_user_can_request_withdrawal')) {
    function medyar_user_can_request_withdrawal($user_id) {
        return (bool) apply_filters('medyar_user_can_request_withdrawal', true, (int) $user_id);
    }
}
if (!function_exists('medyar_get_trades_spot_prices')) {
    function medyar_get_trades_spot_prices() { return []; }
}
if (!function_exists('medyar_trades_price_band_server_percent')) {
    function medyar_trades_price_band_server_percent() { return 25.0; }
}
if (!function_exists('medyar_trades_price_band_frontend_percent')) {
    function medyar_trades_price_band_frontend_percent() { return 20.0; }
}
if (!function_exists('medyar_trades_fee_percent')) {
    function medyar_trades_fee_percent() { return 0.0; }
}
if (!function_exists('wwp_display_user_info')) {
    function wwp_display_user_info($user_id) {
        $u = get_userdata((int) $user_id);
        return $u ? esc_html($u->display_name . ' (#' . (int) $user_id . ')') : '#' . (int) $user_id;
    }
}
if (!function_exists('wwp_format_price')) {
    function wwp_format_price($amount) {
        return esc_html(number_format_i18n((float) $amount)) . ' تومان';
    }
}
if (!function_exists('wwp_trade_action_buttons')) {
    function wwp_trade_action_buttons(...$args) { return ''; }
}
if (!function_exists('wwp_label_trade_operation')) {
    function wwp_label_trade_operation($op) { return esc_html((string) $op); }
}
if (!function_exists('wwp_trade_asset_label')) {
    function wwp_trade_asset_label($t) { return esc_html((string) $t); }
}
if (!function_exists('wwp_format_trade_amount')) {
    function wwp_format_trade_amount($a, $t = '') { return esc_html((string) $a); }
}
if (!function_exists('wwp_calculate_price_deviation')) {
    function wwp_calculate_price_deviation(...$args) { return ''; }
}
if (!function_exists('wwp_trade_status_badge')) {
    function wwp_trade_status_badge($s) { return esc_html((string) $s); }
}