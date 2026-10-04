<?php

/**
 * Atomic balance helpers
 *
 * تمام تغییرات موجودی (تومان و دارایی‌ها) باید از این توابع عبور کند تا
 * read-modify-write روی usermeta با قفل ردیف (SELECT ... FOR UPDATE) انجام شود.
 *
 * دو حالت مصرف:
 *   ۱) کدی که تراکنش دیتابیس ندارد  → wallet_atomic_adjust_balance()
 *      (خودش START TRANSACTION / COMMIT / ROLLBACK را مدیریت می‌کند)
 *
 *   ۲) کدی که همین حالا داخل یک START TRANSACTION است →
 *      wallet_locked_read_balance() + wallet_write_balance()
 *      (در تراکنش فراخوان شریک می‌شود؛ نباید تراکنش جدید باز کند چون MySQL
 *       تراکنش تودرتو ندارد و START TRANSACTION دوم، تراکنش بیرونی را commit می‌کند)
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * کلید usermeta موجودی برای هر balance_type.
 *
 * @param string $balance_type toman|silver|bullion|…
 */
function wallet_balance_meta_key(string $balance_type): string
{
    $balance_type = sanitize_key($balance_type);

    return 'wallet_' . $balance_type . '_balance';
}

/**
 * دقت ذخیره‌سازی موجودی.
 *
 * - toman: همیشه عدد صحیح
 * - silver: همیشه اعشاری (دادهٔ کسری موجود نباید truncate شود)
 * - سایر کلیدها: از medyar_STATIC_ASSETS / wwp_asset_is_decimal
 */
function wallet_balance_is_decimal(string $balance_type): bool
{
    $balance_type = sanitize_key($balance_type);
    if ($balance_type === 'toman') {
        return false;
    }
    if ($balance_type === 'silver') {
        return true;
    }
    if (function_exists('wwp_asset_is_decimal')) {
        return wwp_asset_is_decimal($balance_type);
    }
    if (defined('medyar_STATIC_ASSETS') && is_array(medyar_STATIC_ASSETS)
        && isset(medyar_STATIC_ASSETS[$balance_type]['is_decimal'])) {
        return (bool) medyar_STATIC_ASSETS[$balance_type]['is_decimal'];
    }
    // پیش‌فرض دارایی ناشناخته: اعشاری تا موجودی کسری قدیمی truncate نشود.
    return $balance_type !== 'bullion';
}

/**
 * فرمت ذخیره‌سازی مقدار موجودی بر اساس نوع دارایی.
 *
 * @return string|int
 */
function wallet_format_balance_value(string $balance_type, float $value)
{
    if (wallet_balance_is_decimal($balance_type)) {
        return number_format($value, 3, '.', '');
    }

    return (int) round($value);
}

/**
 * Ensure a usermeta balance row exists so SELECT … FOR UPDATE can lock it.
 * Idempotent: concurrent callers may race on INSERT; either wins.
 */
function wallet_ensure_balance_row(int $user_id, string $balance_type): void
{
    global $wpdb;

    $user_id  = absint($user_id);
    $meta_key = wallet_balance_meta_key($balance_type);

    if ($user_id <= 0 || $meta_key === '') {
        return;
    }

    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT umeta_id FROM {$wpdb->usermeta}
         WHERE user_id = %d AND meta_key = %s LIMIT 1",
        $user_id,
        $meta_key
    ));

    if ($exists) {
        return;
    }

    $zero = wallet_format_balance_value($balance_type, 0.0);
    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$wpdb->usermeta} (user_id, meta_key, meta_value)
         SELECT %d, %s, %s FROM DUAL
         WHERE NOT EXISTS (
             SELECT 1 FROM {$wpdb->usermeta}
             WHERE user_id = %d AND meta_key = %s
             LIMIT 1
         )",
        $user_id,
        $meta_key,
        (string) $zero,
        $user_id,
        $meta_key
    ));
}

/**
 * خواندن موجودی با قفل ردیف — فقط داخل یک تراکنش باز معنا دارد.
 *
 * @param int    $user_id
 * @param string $balance_type toman|silver|bullion|…
 * @return float موجودی فعلی (قفل‌شده تا پایان تراکنش)
 */
function wallet_locked_read_balance(int $user_id, string $balance_type): float
{
    global $wpdb;

    $meta_key = wallet_balance_meta_key($balance_type);

    wallet_ensure_balance_row($user_id, $balance_type);

    $current = $wpdb->get_var($wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->usermeta}
         WHERE user_id = %d AND meta_key = %s LIMIT 1 FOR UPDATE",
        $user_id,
        $meta_key
    ));

    return (float) $current;
}

/**
 * پاک‌سازی کش usermeta پس از ROLLBACK.
 *
 * ROLLBACK دیتابیس مقدار موجودی را برمی‌گرداند، اما object cache (اینجا Redis)
 * همچنان مقدار نوشته‌شدهٔ قبل از rollback را نگه می‌دارد. بدون این invalidate،
 * خواندن بعدی مقدار غلط می‌دهد — و «جبران دستی» بر اساس آن مقدار، موجودی را
 * دو بار تغییر می‌دهد. هرگز پس از ROLLBACK موجودی را دستی جبران نکنید.
 */
function wallet_flush_balance_cache(int $user_id): void
{
    wp_cache_delete($user_id, 'user_meta');
}

/**
 * نوشتن موجودی با فرمت درست دارایی.
 *
 * @return int|float|string مقدار ذخیره‌شده
 */
function wallet_write_balance(int $user_id, string $balance_type, float $value)
{
    $formatted = wallet_format_balance_value($balance_type, $value);
    update_user_meta($user_id, wallet_balance_meta_key($balance_type), $formatted);

    return $formatted;
}

/**
 * هستهٔ اتمیک: قفل ردیف موجودی → محاسبهٔ مقدار جدید → نوشتن → commit.
 *
 * تابع $compute مقدار فعلی (float) را می‌گیرد و مقدار جدید (float) برمی‌گرداند.
 * برای لغو عملیات باید Exception بیندازد (ROLLBACK می‌شود).
 *
 * ⚠ این تابع تراکنش خودش را باز می‌کند؛ اگر فراخوان از قبل داخل تراکنش است
 *   به‌جای این تابع از wallet_locked_read_balance()/wallet_write_balance() استفاده کنید.
 *
 * @throws Exception هر خطایی که $compute بیندازد، پس از ROLLBACK دوباره throw می‌شود
 * @return float مقدار جدید موجودی
 */
function wallet_atomic_update_balance(int $user_id, string $balance_type, callable $compute): float
{
    global $wpdb;

    $wpdb->query('START TRANSACTION');

    try {
        $current   = wallet_locked_read_balance($user_id, $balance_type);
        $new_value = (float) call_user_func($compute, $current);

        wallet_write_balance($user_id, $balance_type, $new_value);

        $wpdb->query('COMMIT');

        return $new_value;
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        throw $e;
    }
}

/**
 * افزایش/کاهش اتمیک موجودی به اندازهٔ $delta.
 *
 * @param int    $user_id
 * @param string $balance_type   toman|silver|bullion|…
 * @param float  $delta          مقدار مثبت (افزایش) یا منفی (کاهش)
 * @param bool   $allow_negative اگر false باشد و نتیجه منفی شود، خطا برمی‌گرداند
 * @return float|WP_Error موجودی جدید یا خطا
 */
function wallet_atomic_adjust_balance(int $user_id, string $balance_type, float $delta, bool $allow_negative = false)
{
    $user_id = absint($user_id);

    if ($user_id <= 0) {
        return new WP_Error('invalid_user', __('شناسه کاربر نامعتبر است.', 'medyar-toman-wallet'));
    }

    try {
        return wallet_atomic_update_balance(
            $user_id,
            $balance_type,
            function ($current) use ($delta, $allow_negative, $balance_type) {
                $new_value = (float) $current + $delta;

                if (!$allow_negative && $new_value < 0) {
                    throw new Exception(sprintf(
                        __('موجودی %s کافی نیست.', 'medyar-toman-wallet'),
                        $balance_type
                    ));
                }

                return $new_value;
            }
        );
    } catch (Throwable $e) {
        return new WP_Error('balance_update_failed', $e->getMessage());
    }
}
