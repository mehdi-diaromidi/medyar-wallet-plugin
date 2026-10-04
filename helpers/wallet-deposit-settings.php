<?php
/**
 * Wallet deposit limits (admin: medyar-wallet-settings).
 *
 * @package Wallet_Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Default max deposit in Toman (۵۰۰ میلیون). */
if (!defined('MEDYAR_WALLET_MAX_DEPOSIT_DEFAULT')) {
    define('MEDYAR_WALLET_MAX_DEPOSIT_DEFAULT', 500000000);
}

/**
 * Global maximum wallet deposit amount (Toman).
 */
function medyar_wallet_get_max_deposit(): int
{
    $stored = get_option('medyar_wallet_max_deposit', MEDYAR_WALLET_MAX_DEPOSIT_DEFAULT);
    $value  = absint($stored);

    if ($value < 100000) {
        return (int) MEDYAR_WALLET_MAX_DEPOSIT_DEFAULT;
    }

    return $value;
}

/**
 * Sanitize admin input for max deposit (Toman).
 */
function medyar_wallet_sanitize_max_deposit($raw): int
{
    $value = absint($raw);

    if ($value < 100000) {
        return (int) MEDYAR_WALLET_MAX_DEPOSIT_DEFAULT;
    }

    if ($value > 2000000000) {
        return 2000000000;
    }

    return $value;
}

/**
 * UI phrase: «واریز تا سقف X میلیون تومان» or full toman when not round millions.
 */
function medyar_wallet_deposit_limit_phrase(int $toman): string
{
    $toman = max(0, $toman);

    if ($toman >= 1000000 && $toman % 1000000 === 0) {
        return sprintf(
            'واریز تا سقف %s میلیون تومان',
            number_format_i18n($toman / 1000000)
        );
    }

    return sprintf('واریز تا سقف %s تومان', number_format_i18n($toman));
}

/**
 * Error message when deposit exceeds limit.
 */
function medyar_wallet_max_deposit_error_message(?int $max = null): string
{
    $max = $max ?? medyar_wallet_get_max_deposit();

    return sprintf('حداکثر مبلغ واریز %s تومان است.', number_format_i18n($max));
}
