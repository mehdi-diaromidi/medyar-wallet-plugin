<?php
/**
 * Wallet withdrawal limits (admin: medyar-wallet-settings).
 *
 * @package Wallet_Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Default max withdrawal in Toman (۲۰۰ میلیون) — matches legacy frontend hardcode. */
if (!defined('MEDYAR_WALLET_MAX_WITHDRAW_DEFAULT')) {
    define('MEDYAR_WALLET_MAX_WITHDRAW_DEFAULT', 200000000);
}

/**
 * Global maximum wallet withdrawal amount (Toman).
 */
function medyar_wallet_get_max_withdraw(): int
{
    $stored = get_option('medyar_wallet_max_withdraw', MEDYAR_WALLET_MAX_WITHDRAW_DEFAULT);
    $value  = absint($stored);

    if ($value < 100000) {
        return (int) MEDYAR_WALLET_MAX_WITHDRAW_DEFAULT;
    }

    return $value;
}

/**
 * Sanitize admin input for max withdraw (Toman).
 */
function medyar_wallet_sanitize_max_withdraw($raw): int
{
    $value = absint($raw);

    if ($value < 100000) {
        return (int) MEDYAR_WALLET_MAX_WITHDRAW_DEFAULT;
    }

    if ($value > 2000000000) {
        return 2000000000;
    }

    return $value;
}

/**
 * UI phrase: «برداشت تا سقف X میلیون تومان» or full toman when not round millions.
 */
function medyar_wallet_withdraw_limit_phrase(int $toman): string
{
    $toman = max(0, $toman);

    if ($toman >= 1000000 && $toman % 1000000 === 0) {
        return sprintf(
            'برداشت تا سقف %s میلیون تومان',
            number_format_i18n($toman / 1000000)
        );
    }

    return sprintf('برداشت تا سقف %s تومان', number_format_i18n($toman));
}

/**
 * Error message when withdraw exceeds limit.
 */
function medyar_wallet_max_withdraw_error_message(?int $max = null): string
{
    $max = $max ?? medyar_wallet_get_max_withdraw();

    return sprintf('حداکثر مبلغ برداشت %s تومان است.', number_format_i18n($max));
}
