<?php

/**
 * Wallet SMS Notifications
 *
 * ارسال پیامک از طریق پلاگین «پیامک حرفه‌ای ووکامرس» (PWooSMS / PWSMS)
 *
 * ─── نحوه کار پلاگین ───────────────────────────────────────────────────
 *  تابع PWooSMS() یک instance از PW\PWSMS\Helper برمی‌گرداند.
 *  متد اصلی: send_sms($data)
 *    $data['mobile']  → شماره موبایل (string یا array)
 *    $data['message'] → متن پیام
 *    $data['post_id'] → اختیاری
 *    $data['type']    → نوع پیام (0=normal)
 *
 *  متد SendSMS() deprecated است اما send_sms() را فراخوانی می‌کند.
 *
 * ─── تشخیص شماره موبایل ───────────────────────────────────────────────
 *  اولویت:
 *   1. billing_phone (WooCommerce meta)
 *   2. user_login (اگر فرمت موبایل ایرانی داشته باشد)
 *   3. user_phone (meta سفارشی)
 *
 * ─── اعتبارسنجی موبایل ────────────────────────────────────────────────
 *  پلاگین PWSMS از modify_mobile() استفاده می‌کند که:
 *   - اعداد فارسی/عربی را به انگلیسی تبدیل می‌کند
 *   - فرمت‌های مختلف (09xxx، 9xxx، +989xxx) را normalize می‌کند
 *  ما قبل از ارسال با validate_mobile() پلاگین validate می‌کنیم.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (class_exists('Wallet_SMS')) { return; }

class Wallet_SMS
{
    /**
     * بررسی اینکه آیا ارسال پیامک فعال است
     * 
     * @return bool
     */
    public static function is_sms_enabled(): bool
    {
        return (bool) get_option('wallet_sms_enabled', 1);
    }

    /**
     * لاگ تشخیصی Match/Notify — هم error_log و هم فایل:
     * wp-content/trade-match-notify.log
     */
    public static function debug_log(string $message): void
    {
        $line = '[TradeMatchNotify] ' . $message;
        error_log($line);

        $dir  = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : dirname(__DIR__, 3);
        $file = rtrim(str_replace('\\', '/', (string) $dir), '/') . '/trade-match-notify.log';
        $stamp = gmdate('Y-m-d H:i:s') . ' UTC';
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        @file_put_contents($file, $stamp . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
    }

    private static function asset_title(string $asset_key): string
    {
        if (function_exists('wwp_label_balance_type')) {
            return wwp_label_balance_type($asset_key);
        }
        if (defined('medyar_STATIC_ASSETS') && is_array(medyar_STATIC_ASSETS)
            && !empty(medyar_STATIC_ASSETS[$asset_key]['title_fa'])) {
            return (string) medyar_STATIC_ASSETS[$asset_key]['title_fa'];
        }
        if (class_exists('Asset_Settings_Manager')) {
            $asset = Asset_Settings_Manager::get_instance()->get_asset($asset_key);
            if ($asset) {
                return (string) ($asset['title_fa'] ?: $asset['title_en'] ?: $asset_key);
            }
        }
        return $asset_key;
    }

    private static function asset_unit(string $asset_key): string
    {
        if (function_exists('wwp_asset_unit_label')) {
            return wwp_asset_unit_label($asset_key);
        }
        if (defined('medyar_STATIC_ASSETS') && is_array(medyar_STATIC_ASSETS)
            && !empty(medyar_STATIC_ASSETS[$asset_key]['unit_label'])) {
            return (string) medyar_STATIC_ASSETS[$asset_key]['unit_label'];
        }
        if (class_exists('Asset_Settings_Manager')) {
            $asset = Asset_Settings_Manager::get_instance()->get_asset($asset_key);
            if ($asset) {
                return (string) ($asset['unit_label'] ?? '');
            }
        }
        return '';
    }

    private static function asset_is_decimal(string $asset_key): bool
    {
        if (function_exists('wwp_asset_is_decimal')) {
            return wwp_asset_is_decimal($asset_key);
        }
        if (defined('medyar_STATIC_ASSETS') && is_array(medyar_STATIC_ASSETS)
            && isset(medyar_STATIC_ASSETS[$asset_key]['is_decimal'])) {
            return (bool) medyar_STATIC_ASSETS[$asset_key]['is_decimal'];
        }
        if (class_exists('Asset_Settings_Manager')) {
            $asset = Asset_Settings_Manager::get_instance()->get_asset($asset_key);
            if ($asset) {
                return !empty($asset['is_decimal']);
            }
        }
        return $asset_key !== 'bullion';
    }

    private static function format_asset_amount(float $amount, string $asset_key): string
    {
        $num = self::format_asset_number($amount, $asset_key);
        $unit = self::asset_unit($asset_key);
        return trim($num . ' ' . $unit);
    }

    /** عدد دارایی بدون واحد (اعشار طبق تنظیمات دارایی). */
    private static function format_asset_number(float $amount, string $asset_key): string
    {
        return self::asset_is_decimal($asset_key)
            ? number_format($amount, 3)
            : number_format($amount, 0);
    }

    /**
     * تراکنش‌های ساخته‌شده از صفحه تست عملیات (wallet-test-actions).
     */
    private static function is_admin_test_action_tx(object $tx): bool
    {
        $op = sanitize_key((string) ($tx->operation_type ?? ''));
        if ($op === 'admin_add' || $op === 'admin_reduce') {
            return true;
        }
        $desc = (string) ($tx->description ?? '');
        return $desc !== '' && strpos($desc, 'انجام‌شده توسط ادمین:') !== false;
    }
    // ═══════════════════════════════════════════════════════════════════
    // Bootstrap
    // ═══════════════════════════════════════════════════════════════════

    public static function init(): void
    {
        add_action('wallet_transaction_created',        [__CLASS__, 'on_created'],        10, 5);
        add_action('wallet_transaction_status_changed', [__CLASS__, 'on_status_changed'], 10, 5);
        
        // Trade events
        add_action('wallet_trade_created',              [__CLASS__, 'on_trade_created'],  10, 2);
        add_action('wallet_trade_matched',              [__CLASS__, 'on_trade_matched'],  10, 3);
        add_action('wallet_trade_fully_matched',        [__CLASS__, 'on_trade_fully_matched'], 10, 2);
        add_action('wallet_trade_canceled',             [__CLASS__, 'on_trade_canceled'], 10, 2);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Hooks
    // ═══════════════════════════════════════════════════════════════════

    /**
     * هنگام ثبت تراکنش جدید
     * برداشت مستقیماً با processing شروع می‌کند → اینجا پیامک می‌زنیم
     * خرید نقره با کیف پول مستقیماً با processing شروع می‌شود
     */
    public static function on_created(
        int    $tx_id,
        string $tx_code,
        string $status,
        string $prev_status,
        array  $row
    ): void {
        $op = $row['operation_type'] ?? '';

        error_log(sprintf(
            '[WalletSMS] on_created: tx=%d op=%s status=%s',
            $tx_id,
            $op,
            $status
        ));

        if ($op === 'withdraw' && $status === 'processing') {
            $tx     = (object) $row;
            $tx->id = $tx_id;
            self::sms_withdraw_registered($tx);
        }

        // خرید نقره با کیف پول
        if ($op === 'buy_silver' && $status === 'processing') {
            $tx     = (object) $row;
            $tx->id = $tx_id;
            self::sms_buy_silver_registered($tx);
        }

        if ($op === 'sell_silver' && $status === 'processing') {
            $tx     = (object) $row;
            $tx->id = $tx_id;
            self::sms_sell_silver_registered($tx);
        }

        // شارژ / افزودن موجودی که مستقیماً با status=completed ثبت می‌شود
        // (تست عملیات ادمین، واریز دستی، add_balance با completed) — بدون transition pending→completed
        if (($op === 'charge' || $op === 'admin_add') && $status === 'completed'
            && floatval($row['amount'] ?? 0) > 0) {
            $tx     = (object) $row;
            $tx->id = $tx_id;
            self::sms_charge_completed($tx);
        }
    }

    /**
     * هنگام تغییر وضعیت هر تراکنش
     */
    public static function on_status_changed(
        int    $tx_id,
        string $tx_code,
        string $new_status,
        string $prev_status,
        object $tx
    ): void {
        $op  = $tx->operation_type ?? '';
        $bal = $tx->balance_type   ?? '';

        error_log(sprintf(
            '[WalletSMS] on_status_changed: tx=%d op=%s bal=%s %s→%s',
            $tx_id,
            $op,
            $bal,
            $prev_status,
            $new_status
        ));

        // ── خرید نقره (balance_type = silver) ────────────────────────
        if ($op === 'buy_silver' && $bal === 'silver') {
            if ($prev_status === 'pending'    && $new_status === 'processing') {
                self::sms_buy_silver_registered($tx);
            }
            if ($prev_status === 'processing' && $new_status === 'completed') {
                self::sms_buy_silver_completed($tx);
            }
            if ($prev_status === 'processing' && $new_status === 'canceled') {
                self::sms_buy_silver_canceled($tx, $prev_status);
            }
        }

        // ── فروش نقره (balance_type = toman) ─────────────────────────
        if ($op === 'sell_silver' && $bal === 'toman') {
            if ($prev_status === 'pending'    && $new_status === 'processing') {
                self::sms_sell_silver_registered($tx);
            }
            if ($prev_status === 'processing' && $new_status === 'completed') {
                self::sms_sell_silver_completed($tx);
            }
            if ($prev_status === 'processing' && $new_status === 'canceled') {
                self::sms_sell_silver_canceled($tx);
            }
        }

        // ── واریز / شارژ (تومان و سایر دارایی‌ها) ───────────────────────
        if (($op === 'charge' || $op === 'admin_add') && $prev_status === 'pending' && $new_status === 'completed'
            && floatval($tx->amount ?? 0) > 0) {
            self::sms_charge_completed($tx);
        }
        if ($op === 'charge' && $bal === 'toman' && $prev_status === 'completed' && $new_status === 'canceled') {
            self::sms_charge_canceled($tx);
        }

        // ── برداشت ───────────────────────────────────────────────────
        if ($op === 'withdraw' && $bal === 'toman') {
            if ($prev_status === 'processing' && $new_status === 'completed') {
                self::sms_withdraw_completed($tx);
            }
            if ($prev_status === 'processing' && $new_status === 'canceled') {
                self::sms_withdraw_canceled($tx);
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // پیام‌های هر حالت
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Dual notify when theme helper exists; otherwise SMS-only fallback.
     *
     * @return bool true وقتی پیامک واقعاً ارسال شد
     */
    private static function dual_notify(int $user_id, string $type, string $title, string $inapp, string $sms, int $ref_id = 0): bool
    {
        if (function_exists('medyar_notify_user')) {
            $result = medyar_notify_user($user_id, $type, $title, $inapp, $sms, $ref_id);
            return !empty($result['sms']);
        }
        return self::send_user_message_only($user_id, $sms, $ref_id);
    }

    /**
     * خط لاگ یکسان برای توضیحات تراکنش / معامله پس از ارسال موفق پیامک.
     */
    private static function format_sms_success_log(string $message, string $mobile = ''): string
    {
        $msg = trim(preg_replace("/\s+/u", ' ', $message));
        if ($msg !== '') {
            if (function_exists('mb_strlen') && mb_strlen($msg) > 280) {
                $msg = mb_substr($msg, 0, 277) . '…';
            } elseif (strlen($msg) > 280) {
                $msg = substr($msg, 0, 277) . '…';
            }
            return sprintf(
                "\n[%s] پیامک «%s» با موفقیت به کاربر ارسال شد.",
                current_time('Y/m/d H:i'),
                $msg
            );
        }
        if ($mobile !== '') {
            return sprintf(
                "\n[%s] پیامک با موفقیت به شماره %s ارسال شد.",
                current_time('Y/m/d H:i'),
                $mobile
            );
        }
        return sprintf(
            "\n[%s] پیامک با موفقیت به کاربر ارسال شد.",
            current_time('Y/m/d H:i')
        );
    }

    private static function account_url(string $endpoint, string $query = ''): string
    {
        if (function_exists('medyar_notify_account_url')) {
            return medyar_notify_account_url($endpoint, $query);
        }
        $url = home_url('/my-account/' . trailingslashit($endpoint));
        return $query !== '' ? $url . '?' . ltrim($query, '?') : $url;
    }

    /** خرید نقره: pending → processing */
    private static function sms_buy_silver_registered(object $tx): void
    {
        $uid = intval($tx->user_id);
        $name = function_exists('medyar_notify_user_display_name') ? medyar_notify_user_display_name($uid) : (self::get_user_data($uid)['name'] ?? 'کاربر');
        $asset = self::asset_title(!empty($tx->balance_type) ? (string) $tx->balance_type : 'silver');
        $link = self::account_url('trading', 'scroll=tx');
        $title = 'خرید از عیارفر';
        $inapp = "{$name} عزیز، خرید {$asset} شما با موفقیت انجام شد و در صف بررسی قرار گرفت.\nمشاهده لیست خرید و فروش‌ها: {$link}";
        $sms = "{$name} عزیز، خرید {$asset} شما با موفقیت انجام شد و در صف بررسی قرار گرفت.";
        self::dual_notify($uid, 'trade', $title, $inapp, $sms, intval($tx->id ?? 0));
    }

    /** خرید نقره: processing → completed */
    private static function sms_buy_silver_completed(object $tx): void
    {
        $uid = intval($tx->user_id);
        $name = function_exists('medyar_notify_user_display_name') ? medyar_notify_user_display_name($uid) : (self::get_user_data($uid)['name'] ?? 'کاربر');
        $asset_key = !empty($tx->balance_type) ? (string) $tx->balance_type : 'silver';
        $asset = self::asset_title($asset_key);
        $amount = self::format_asset_amount(abs(floatval($tx->amount)), $asset_key);
        $link = self::account_url('trading', 'scroll=tx');
        $title = 'خرید از عیارفر';
        $inapp = "{$name} عزیز، خرید {$asset} شما با موفقیت تکمیل و {$amount} به کیف پول دارایی شما اضافه شد.\nمشاهده لیست خرید و فروش‌ها: {$link}";
        $sms = "{$name} عزیز، خرید {$asset} شما با موفقیت تکمیل و {$amount} به کیف پول دارایی شما اضافه شد.";
        self::dual_notify($uid, 'trade', $title, $inapp, $sms, intval($tx->id ?? 0));
    }

    /** خرید نقره: canceled */
    private static function sms_buy_silver_canceled(object $tx, string $from_status): void
    {
        unset($from_status);
        $uid = intval($tx->user_id);
        $name = function_exists('medyar_notify_user_display_name') ? medyar_notify_user_display_name($uid) : (self::get_user_data($uid)['name'] ?? 'کاربر');
        $asset = self::asset_title(!empty($tx->balance_type) ? (string) $tx->balance_type : 'silver');
        $link = self::account_url('trading', 'scroll=tx');
        $title = 'خرید از عیارفر';
        $inapp = "{$name} عزیز، خرید {$asset} شما لغو گردید.\nمشاهده لیست خرید و فروش‌ها: {$link}";
        $sms = "{$name} عزیز، خرید {$asset} شما لغو گردید.";
        self::dual_notify($uid, 'trade', $title, $inapp, $sms, intval($tx->id ?? 0));
    }

    private static function sms_sell_silver_registered(object $tx): void
    {
        $uid = intval($tx->user_id);
        $name = function_exists('medyar_notify_user_display_name') ? medyar_notify_user_display_name($uid) : (self::get_user_data($uid)['name'] ?? 'کاربر');
        $asset = self::asset_title('silver');
        $link = self::account_url('trading', 'scroll=tx');
        $title = 'فروش از عیارفر';
        $inapp = "{$name} عزیز، فروش {$asset} شما با موفقیت انجام شد و در صف بررسی قرار گرفت.\nمشاهده لیست خرید و فروش‌ها: {$link}";
        $sms = "{$name} عزیز، فروش {$asset} شما با موفقیت انجام شد و در صف بررسی قرار گرفت.";
        self::dual_notify($uid, 'trade', $title, $inapp, $sms, intval($tx->id ?? 0));
    }

    /** فروش نقره: processing → completed */
    private static function sms_sell_silver_completed(object $tx): void
    {
        $uid = intval($tx->user_id);
        $name = function_exists('medyar_notify_user_display_name') ? medyar_notify_user_display_name($uid) : (self::get_user_data($uid)['name'] ?? 'کاربر');
        $asset = self::asset_title('silver');
        $toman = number_format(abs(floatval($tx->amount)));
        $link = self::account_url('trading', 'scroll=tx');
        $title = 'فروش از عیارفر';
        $inapp = "{$name} عزیز، فروش {$asset} شما با موفقیت تکمیل و {$toman} تومان به کیف پول دارایی شما اضافه شد.\nمشاهده لیست خرید و فروش‌ها: {$link}";
        $sms = "{$name} عزیز، فروش {$asset} شما با موفقیت تکمیل و {$toman} تومان به کیف پول دارایی شما اضافه شد.";
        self::dual_notify($uid, 'trade', $title, $inapp, $sms, intval($tx->id ?? 0));
    }

    /** فروش نقره: canceled */
    private static function sms_sell_silver_canceled(object $tx): void
    {
        $uid = intval($tx->user_id);
        $name = function_exists('medyar_notify_user_display_name') ? medyar_notify_user_display_name($uid) : (self::get_user_data($uid)['name'] ?? 'کاربر');
        $asset = self::asset_title('silver');
        $link = self::account_url('trading', 'scroll=tx');
        $title = 'فروش از عیارفر';
        $inapp = "{$name} عزیز، فروش {$asset} شما لغو گردید.\nمشاهده لیست خرید و فروش‌ها: {$link}";
        $sms = "{$name} عزیز، فروش {$asset} شما لغو گردید.";
        self::dual_notify($uid, 'trade', $title, $inapp, $sms, intval($tx->id ?? 0));
    }

    /** واریز/شارژ: completed (از status change یا create مستقیم) */
    private static function sms_charge_completed(object $tx): void
    {
        $uid = intval($tx->user_id);
        $name = function_exists('medyar_notify_user_display_name') ? medyar_notify_user_display_name($uid) : (self::get_user_data($uid)['name'] ?? 'کاربر');
        $bal  = sanitize_key((string) ($tx->balance_type ?? 'toman'));
        $amount = abs(floatval($tx->amount ?? 0));
        $title = 'شارژ دارایی';

        if ($bal === '' || $bal === 'toman') {
            $toman = number_format($amount);
            $bank  = '';
            if (!empty($tx->description) && preg_match('/بانک\s+([^\s،\-]+)/u', (string) $tx->description, $m)) {
                $bank = $m[1];
            }
            if ($bank !== '') {
                $body = "{$name} عزیز، کیف پول شما از مبدا بانک {$bank} به مبلغ {$toman} تومان شارژ شد.";
            } else {
                $body = "{$name} عزیز، کیف پول شما به مبلغ {$toman} تومان شارژ شد.";
            }
        } else {
            // دارایی نقره‌محور: «به میزان {عدد} {واحد} {نام کامل}»
            $asset = self::asset_title($bal);
            $num   = self::format_asset_number($amount, $bal);
            $unit  = self::asset_unit($bal);
            $qty   = trim($num . ($unit !== '' ? ' ' . $unit : ''));
            $body  = "{$name} عزیز، کیف پول شما به میزان {$qty} {$asset} شارژ شد.";
        }

        // صفحه تست عملیات: بدون لینک در نوتیف درون‌برنامه
        if (self::is_admin_test_action_tx($tx)) {
            $inapp = $body;
        } else {
            $link  = self::account_url('deposit', 'scroll=tx');
            $inapp = $body . "\nمشاهده درخواست‌های واریز: {$link}";
        }
        self::dual_notify($uid, 'deposit', $title, $inapp, $body, intval($tx->id ?? 0));
    }

    /** واریز: completed → canceled */
    private static function sms_charge_canceled(object $tx): void
    {
        $uid = intval($tx->user_id);
        $name = function_exists('medyar_notify_user_display_name') ? medyar_notify_user_display_name($uid) : (self::get_user_data($uid)['name'] ?? 'کاربر');
        $body = "{$name} عزیز، درخواست واریز شما لغو شد. در صورت کسر وجه، تا ۷۲ ساعت به کارت بانکی برگشت می‌خورد.";
        $link = self::account_url('deposit', 'scroll=tx');
        self::dual_notify($uid, 'deposit', 'شارژ دارایی', $body . "\nمشاهده درخواست‌های واریز: {$link}", $body, intval($tx->id ?? 0));
    }

    /** برداشت: ثبت (processing) */
    private static function sms_withdraw_registered(object $tx): void
    {
        $uid = intval($tx->user_id);
        $name = function_exists('medyar_notify_user_display_name') ? medyar_notify_user_display_name($uid) : (self::get_user_data($uid)['name'] ?? 'کاربر');
        $amount = number_format(abs(floatval($tx->amount)));
        $link = self::account_url('withdrawal', 'scroll=tx');
        $title = 'برداشت از کیف پول';
        $body = "{$name} عزیز، درخواست برداشت شما به مبلغ {$amount} تومان ثبت شد و در صف بررسی قرار گرفت.";
        self::dual_notify($uid, 'withdraw', $title, $body . "\nمشاهده درخواست‌های برداشت: {$link}", $body, intval($tx->id ?? 0));

        $admin_ids = get_option('medyar_deposit_withdrawal_admin_ids', []);
        if (!is_array($admin_ids)) {
            $admin_ids = [];
        }
        if (function_exists('medyar_notify_admins') && !empty($admin_ids)) {
            $admin_name = $name;
            medyar_notify_admins(
                $admin_ids,
                "ادمین {$admin_name}، یک درخواست برداشت در انتظار بررسی شما است.\nمشاهده: https://ayarfar.ir/wp-admin/admin.php?page=medyar-wallet-withdrawals",
                intval($tx->id ?? 0)
            );
        }
    }

    /** برداشت: processing → completed (= ارسال به بانک) */
    private static function sms_withdraw_completed(object $tx): void
    {
        $uid = intval($tx->user_id);
        $name = function_exists('medyar_notify_user_display_name') ? medyar_notify_user_display_name($uid) : (self::get_user_data($uid)['name'] ?? 'کاربر');
        $amount = number_format(abs(floatval($tx->amount)));
        $link = self::account_url('withdrawal', 'scroll=tx');
        $title = 'برداشت از کیف پول';
        $body = "{$name} عزیز، درخواست برداشت شما به مبلغ {$amount} تومان با موفقیت تایید شد و به بانک ارسال گردید.";
        self::dual_notify($uid, 'withdraw', $title, $body . "\nمشاهده درخواست‌های برداشت: {$link}", $body, intval($tx->id ?? 0));
    }

    /** برداشت: canceled */
    private static function sms_withdraw_canceled(object $tx): void
    {
        $uid = intval($tx->user_id);
        $name = function_exists('medyar_notify_user_display_name') ? medyar_notify_user_display_name($uid) : (self::get_user_data($uid)['name'] ?? 'کاربر');
        $amount = number_format(abs(floatval($tx->amount)));
        $link = self::account_url('withdrawal', 'scroll=tx');
        $title = 'برداشت از کیف پول';
        $body = "{$name} عزیز، درخواست برداشت شما به مبلغ {$amount} تومان لغو گردید و مبلغ به کیف پول شما عودت داده شد.";
        self::dual_notify($uid, 'withdraw', $title, $body . "\nمشاهده درخواست‌های برداشت: {$link}", $body, intval($tx->id ?? 0));
    }

    // ═══════════════════════════════════════════════════════════════════
    // اطلاعات کاربر
    // ═══════════════════════════════════════════════════════════════════

    /**
     * اولویت تشخیص موبایل:
     *  1. billing_phone (WooCommerce)
     *  2. user_login (اگر فرمت موبایل ایرانی داشته باشد)
     *  3. user_phone (meta سفارشی)
     *
     * @return array{name:string,mobile:string}|null
     */
    private static function get_user_data(int $user_id): ?array
    {
        $user = get_user_by('id', $user_id);
        if (!$user) {
            error_log("[WalletSMS] user NOT FOUND: user_id=$user_id");
            return null;
        }

        $name = trim($user->first_name . ' ' . $user->last_name);
        if (empty($name)) {
            $name = $user->display_name ?: 'کاربر';
        }

        // ① billing_phone
        $mobile = (string) get_user_meta($user_id, 'billing_phone', true);

        // ② user_login
        if (!self::looks_like_mobile($mobile)) {
            if (self::looks_like_mobile($user->user_login)) {
                $mobile = $user->user_login;
            }
        }

        // ③ user_phone meta
        if (!self::looks_like_mobile($mobile)) {
            $phone_meta = (string) get_user_meta($user_id, 'user_phone', true);
            if (self::looks_like_mobile($phone_meta)) {
                $mobile = $phone_meta;
            }
        }

        if (empty($mobile)) {
            error_log("[WalletSMS] mobile NOT FOUND for user_id=$user_id login={$user->user_login}");
            return null;
        }

        $mobile = str_replace(['*', '★', '•', '✱'], '', $mobile);

        error_log("[WalletSMS] user_id=$user_id name=$name mobile=$mobile");
        return ['name' => $name, 'mobile' => $mobile];
    }

    /**
     * شماره موبایل کاربر (همان منطق get_user_data) — برای معاملات و سایر مسیرها
     */
    private static function get_user_mobile(int $user_id): string
    {
        $data = self::get_user_data($user_id);
        if (!$data || empty($data['mobile'])) {
            return '';
        }
        return (string) $data['mobile'];
    }

    /**
     * شماره موبایل کامل کاربر برای نمایش در پنل ادمین (بدون کاراکترهای جایگزین مثل *)
     */
    public static function get_resolved_mobile(int $user_id): string
    {
        return self::get_user_mobile($user_id);
    }

    /** Public wrapper for admin notify helpers. */
    public static function get_user_mobile_public(int $user_id): string
    {
        return self::get_user_mobile($user_id);
    }

    /**
     * ارسال پیامک متنی به کاربر (و در صورت موفقیت، نوتیفیکیشن داخل‌پنل).
     */
    public static function send_user_message(int $user_id, string $message, string $notif_type = 'document', int $ref_id = 0): bool
    {
        $user_id = absint($user_id);
        $message = trim($message);
        if ($user_id <= 0 || $message === '') {
            return false;
        }

        $mobile = self::get_user_mobile($user_id);
        if ($mobile === '') {
            return false;
        }

        return self::send($mobile, $message, $ref_id, false, $user_id, sanitize_key($notif_type));
    }

    /**
     * SMS only — does not emit medyar_wallet_sms_sent (in-app is separate via medyar_notify_user).
     */
    public static function send_user_message_only(int $user_id, string $message, int $ref_id = 0): bool
    {
        $user_id = absint($user_id);
        $message = trim($message);
        if ($user_id <= 0 || $message === '') {
            return false;
        }
        $mobile = self::get_user_mobile($user_id);
        if ($mobile === '') {
            return false;
        }
        // If ref_id is a wallet transaction id, append success note to its description.
        return self::send($mobile, $message, $ref_id, $ref_id > 0, 0, '');
    }

    /**
     * ارسال پیامک به یک شماره (بدون نوتیفیکیشن داخل‌پنل مگر user_id داده شود).
     */
    public static function send_to_mobile(string $mobile, string $message, int $ref_id = 0, int $user_id = 0, string $notif_type = ''): bool
    {
        $mobile  = trim($mobile);
        $message = trim($message);
        if ($mobile === '' || $message === '') {
            return false;
        }

        return self::send($mobile, $message, $ref_id, false, absint($user_id), sanitize_key($notif_type));
    }

    /** SMS-only to a raw mobile (no in-app bridge). */
    public static function send_to_mobile_only(string $mobile, string $message, int $ref_id = 0): bool
    {
        return self::send_to_mobile($mobile, $message, $ref_id, 0, '');
    }

    /**
     * بررسی ساده اینکه آیا رشته شبیه شماره موبایل ایرانی است
     * (قبل از اینکه PWSMS normalize کند)
     */
    private static function looks_like_mobile(string $value): bool
    {
        if (empty($value)) return false;
        // اعداد فارسی/عربی را تبدیل می‌کنیم
        $v = str_replace(
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            $value
        );
        $digits = preg_replace('/\D/', '', $v);
        // 09xxxxxxxxx (11 رقم) یا 9xxxxxxxxx (10 رقم) یا +989xxxxxxxxx
        return (bool) preg_match('/^(0?9\d{9}|989\d{9}|\+989\d{9})$/', $digits);
    }

    // ═══════════════════════════════════════════════════════════════════
    // مبالغ
    // ═══════════════════════════════════════════════════════════════════

    private static function resolve_buy_refund_amount(object $tx): int
    {
        $tm = Wallet_Transaction_Manager::get_instance();

        if (!empty($tx->p_info)) {
            $p_info = json_decode($tx->p_info, true);

            if (!empty($p_info['type']) && $p_info['type'] === 'Pay_gateway' && !empty($p_info['id'])) {
                $pay = $tm->get_payment_record(intval($p_info['id']));
                if ($pay) return intval($pay->amount);
            }

            if (!empty($p_info['type']) && $p_info['type'] === 'Wallet_gateway' && !empty($p_info['id'])) {
                $linked = $tm->get_transaction(intval($p_info['id']));
                if ($linked) return abs(intval($linked->amount));
            }
        }

        if (!empty($tx->linked_tx_id)) {
            $linked = $tm->get_transaction(intval($tx->linked_tx_id));
            if ($linked && $linked->balance_type === 'toman') {
                return abs(intval($linked->amount));
            }
        }

        return 0;
    }

    private static function resolve_sell_silver_grams(object $tx): float
    {
        $tm = Wallet_Transaction_Manager::get_instance();

        if (!empty($tx->p_info)) {
            $p_info = json_decode($tx->p_info, true);
            if (!empty($p_info['type']) && $p_info['type'] === 'Wallet_gateway' && !empty($p_info['id'])) {
                $silver_tx = $tm->get_transaction(intval($p_info['id']));
                if ($silver_tx && $silver_tx->balance_type === 'silver') {
                    return abs(floatval($silver_tx->amount));
                }
            }
        }

        if (!empty($tx->linked_tx_id)) {
            $linked = $tm->get_transaction(intval($tx->linked_tx_id));
            if ($linked && $linked->balance_type === 'silver') {
                return abs(floatval($linked->amount));
            }
        }

        return 0.0;
    }

    // ═══════════════════════════════════════════════════════════════════
    // ارسال پیامک
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ارسال پیامک از طریق PWSMS (پیامک حرفه‌ای ووکامرس)
     *
     * API:
     *  PWSMS()->send_sms($data)
     *   - mobile  : شماره یا آرایه شماره‌ها
     *   - message : متن (پلاگین esc_textarea اعمال می‌کند)
     *   - post_id : اختیاری (برای آرشیو)
     *   - type    : اختیاری (0=normal)
     *
     * پلاگین خودش:
     *  - اعداد فارسی/عربی را normalize می‌کند
     *  - شماره را به فرمت استاندارد تبدیل می‌کند
     *  - نتیجه را در آرشیو ثبت می‌کند
     *
     * @param string $mobile   شماره موبایل
     * @param string $message  متن پیامک
     * @param int    $tx_id    شناسه تراکنش (برای لاگ)
     */
    /**
     * @param int  $post_id_or_tx_id شناسه تراکنش کیف پول (برای لاگ) یا post_id برای PWSMS؛ برای معامله trade_id
     * @param bool $append_wallet_tx_log اگر false باشد، فقط ارسال می‌شود و در wp_wallet_transactions لاگ نمی‌شود (معاملات با log_sms_in_trade)
     */
    private static function send(string $mobile, string $message, int $post_id_or_tx_id = 0, bool $append_wallet_tx_log = true, int $user_id = 0, string $notif_type = ''): bool
    {
        $ref = $post_id_or_tx_id;
        error_log("[WalletSMS] send attempt: ref=$ref mobile=$mobile msg_len=" . mb_strlen($message));
        if ($notif_type === 'trade' || strpos($message, 'Match شد') !== false || strpos($message, 'با موفقیت ثبت شد') !== false) {
            $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6);
            $frames = array();
            foreach ($bt as $i => $f) {
                if ($i === 0) {
                    continue;
                }
                $frames[] = ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '') . '@' . basename($f['file'] ?? '') . ':' . ($f['line'] ?? 0);
            }
            self::debug_log(sprintf(
                'SEND_GATE type=%s user=%d ref=%d mobile=%s msg=%s stack=%s',
                $notif_type !== '' ? $notif_type : '(empty)',
                $user_id,
                $ref,
                $mobile,
                str_replace(array("\r", "\n"), array('', ' | '), $message),
                implode(' <- ', $frames)
            ));
        }

        // بررسی تنظیمات: آیا ارسال پیامک فعال است؟
        $sms_enabled = get_option('wallet_sms_enabled', 1);
        if (!$sms_enabled) {
            error_log("[WalletSMS] DISABLED: SMS sending is disabled in settings. ref=$ref");
            return false;
        }

        if (empty($mobile) || empty($message)) {
            error_log("[WalletSMS] SKIPPED: empty mobile or message. ref=$ref");
            return false;
        }

        // بررسی دسترسی به PWSMS
        if (!function_exists('PWSMS')) {
            error_log("[WalletSMS] FAILED: PWSMS() function not found. Is the plugin active? ref=$ref");
            return false;
        }

        $sms = PWSMS();

        if (!is_object($sms)) {
            error_log("[WalletSMS] FAILED: PWSMS() returned " . gettype($sms) . " instead of object. ref=$ref");
            return false;
        }

        if (!method_exists($sms, 'send_sms')) {
            error_log("[WalletSMS] FAILED: send_sms() method not found on " . get_class($sms) . ". ref=$ref");
            return false;
        }

        // بررسی gateway تنظیم شده
        if (method_exists($sms, 'get_sms_gateway')) {
            $gateway = $sms->get_sms_gateway();
            error_log("[WalletSMS] gateway: " . (is_object($gateway) ? get_class($gateway) : gettype($gateway)) . " ref=$ref");
        }

        // بررسی اینکه آیا شماره valid است (با استفاده از متد خود پلاگین)
        if (method_exists($sms, 'validate_mobile') && !$sms->validate_mobile($mobile)) {
            error_log("[WalletSMS] SKIPPED: mobile='$mobile' failed PWSMS validate_mobile(). ref=$ref");
            return false;
        }

        $data = [
            'post_id' => $post_id_or_tx_id,
            'type'    => 0,
            'mobile'  => $mobile,
            'message' => $message,
        ];

        error_log("[WalletSMS] calling send_sms: ref=$ref mobile=$mobile");

        try {
            $result = $sms->send_sms($data);
            if ($result === true) {
                error_log("[WalletSMS] SUCCESS ref=$ref mobile=$mobile");

                if ($append_wallet_tx_log && $post_id_or_tx_id > 0) {
                    self::log_sms_in_transaction($post_id_or_tx_id, $mobile, $message);
                }

                if ($user_id > 0) {
                    do_action('medyar_wallet_sms_sent', $user_id, $message, $notif_type, $post_id_or_tx_id);
                }

                return true;
            }
            error_log("[WalletSMS] RESULT (not true): ref=$ref result=" . print_r($result, true));
        } catch (Throwable $e) {
            error_log("[WalletSMS] EXCEPTION: ref=$ref error=" . $e->getMessage());
        }
        return false;
    }

    /**
     * پس از ارسال موفق پیامک معامله، همان خط در توضیحات تراکنش‌های کیف پول مرتبط با معامله ثبت می‌شود.
     */
    private static function log_sms_in_trade_wallet_transactions(object $trade, string $mobile, string $message = ''): void
    {
        global $wpdb;
        $table   = $wpdb->prefix . 'wallet_transactions';
        $sms_log = self::format_sms_success_log($message, $mobile);
        $ids = array_unique(array_filter([
            !empty($trade->wallet_toman_tx_id) ? (int) $trade->wallet_toman_tx_id : 0,
            !empty($trade->wallet_asset_tx_id) ? (int) $trade->wallet_asset_tx_id : 0,
        ]));
        foreach ($ids as $tx_id) {
            if ($tx_id <= 0) {
                continue;
            }
            $current = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT description FROM {$table} WHERE id = %d",
                $tx_id
            ));
            $wpdb->update(
                $table,
                ['description' => $current . $sms_log],
                ['id' => $tx_id],
                ['%s'],
                ['%d']
            );
        }
    }

    /**
     * ثبت لاگ ارسال پیامک در توضیحات تراکنش
     */
    private static function log_sms_in_transaction(int $tx_id, string $mobile, string $message = ''): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wallet_transactions';

        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE id = %d", $tx_id));
        // Not a wallet transaction row (e.g. trade_id passed as ref) — skip silently.
        if ($exists === null) {
            return;
        }

        $current_desc = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT description FROM {$table} WHERE id = %d",
            $tx_id
        ));

        $new_desc = $current_desc . self::format_sms_success_log($message, $mobile);

        $wpdb->update(
            $table,
            ['description' => $new_desc],
            ['id' => $tx_id],
            ['%s'],
            ['%d']
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Trade Event Handlers
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ارسال پیامک هنگام ثبت معامله جدید
     * 
     * @param int $trade_id شناسه معامله
     * @param object $trade اطلاعات کامل معامله
     */
    public static function on_trade_created(int $trade_id, object $trade): void
    {
        // P2P (trades): no SMS/in-app notify until the order is fully matched.
        self::debug_log(sprintf(
            'CREATED_SMS_SKIP trade_id=%d user_id=%d op=%s type=%s reason=wait_full_match',
            $trade_id,
            $trade->user_id,
            $trade->operation_type,
            $trade->balance_type
        ));
    }

    /** @var array<int, true> جلوگیری از SMS تکراری Match در همان درخواست PHP */
    private static $match_success_sms_sent = array();

    /**
     * آیا این ردیف تکهٔ خرده‌شده / باقیمانده از یک معامله مادر است؟
     * پیام موفقیت فقط برای مادر کامل‌شده ارسال می‌شود.
     */
    private static function is_trade_match_piece(object $trade): bool
    {
        $status = isset($trade->status) ? (string) $trade->status : '';
        // مادر نهایی‌شده تکه نیست
        if ($status === 'minor_completed') {
            return false;
        }
        if ($status === 'minor' || $status === 'minor_canceled') {
            return true;
        }
        $self_id = isset($trade->id) ? (int) $trade->id : 0;
        $prepaid = !empty($trade->prepaid_from_trade_id) ? (int) $trade->prepaid_from_trade_id : 0;
        if ($prepaid > 0 && $prepaid !== $self_id) {
            return true;
        }
        $split = !empty($trade->split_from_trade_id) ? (int) $trade->split_from_trade_id : 0;
        if ($split > 0 && $split !== $self_id) {
            return true;
        }
        // حتی اگر prepaid/split خالی شده باشد، origin ≠ خود ردیف یعنی تکه است
        $origin = isset($trade->origin_trade_id) ? (int) $trade->origin_trade_id : 0;
        if ($origin > 0 && $self_id > 0 && $origin !== $self_id) {
            return true;
        }
        return false;
    }

    /**
     * شناسه معامله‌ای که باید در پیام‌رسانی استفاده شود (همیشه مادر/ریشه).
     */
    private static function resolve_match_notify_trade_id(int $trade_id, object $trade): int
    {
        $status = isset($trade->status) ? (string) $trade->status : '';
        if ($status === 'minor_completed') {
            return $trade_id;
        }
        if (self::is_trade_match_piece($trade)) {
            $parent = 0;
            if (!empty($trade->prepaid_from_trade_id)) {
                $parent = (int) $trade->prepaid_from_trade_id;
            } elseif (!empty($trade->split_from_trade_id)) {
                $parent = (int) $trade->split_from_trade_id;
            } elseif (!empty($trade->origin_trade_id)) {
                $parent = (int) $trade->origin_trade_id;
            }
            return $parent > 0 ? $parent : 0;
        }
        return $trade_id;
    }

    /**
     * خلاصهٔ فیلدهای lineage برای لاگ تشخیصی.
     */
    private static function trade_debug_snapshot(int $trade_id, $trade): string
    {
        if (!$trade || !is_object($trade)) {
            return sprintf('id=%d MISSING', $trade_id);
        }
        return sprintf(
            'id=%d user=%d op=%s status=%s amt=%s orig=%s total=%s fee=%s split=%s prepaid=%s origin=%s code=%s piece=%d',
            $trade_id,
            (int) ($trade->user_id ?? 0),
            (string) ($trade->operation_type ?? ''),
            (string) ($trade->status ?? ''),
            (string) ($trade->amount ?? ''),
            (string) ($trade->original_amount ?? ''),
            (string) ($trade->total_price ?? ''),
            (string) ($trade->fee_amount ?? ''),
            (string) ($trade->split_from_trade_id ?? '0'),
            (string) ($trade->prepaid_from_trade_id ?? '0'),
            (string) ($trade->origin_trade_id ?? '0'),
            (string) ($trade->transaction_code ?? ''),
            self::is_trade_match_piece($trade) ? 1 : 0
        );
    }

    /**
     * مقدار قابل‌نمایش برای پیام موفقیت (همیشه روی معامله مادر).
     */
    private static function trade_message_amount(object $trade): float
    {
        if (!empty($trade->original_amount) && (float) $trade->original_amount > 0) {
            return (float) $trade->original_amount;
        }
        return (float) $trade->amount;
    }

    /**
     * ارسال یک پیامک موفقیت Match برای معاملهٔ کامل (ریشه / مادر).
     * تکه‌ها هرگز از اینجا پیام نمی‌گیرند؛ فقط completed ریشه یا minor_completed.
     */
    private static function send_trade_fully_matched_sms(int $trade_id, object $trade, string $source = ''): void
    {
        $source = $source !== '' ? $source : 'unknown';

        // اطمینان از وجود id روی آبجکت برای تشخیص origin ≠ self
        if (!isset($trade->id) || !(int) $trade->id) {
            $trade->id = $trade_id;
        }

        self::debug_log(sprintf(
            'SMS_TRY source=%s %s',
            $source,
            self::trade_debug_snapshot($trade_id, $trade)
        ));

        if ($trade_id <= 0 || empty($trade->user_id)) {
            self::debug_log(sprintf(
            'SMS_SKIP source=%s reason=bad_id_or_user trade_id=%d', $source, $trade_id));
            return;
        }

        $status = isset($trade->status) ? (string) $trade->status : '';
        if (!in_array($status, array('completed', 'minor_completed'), true)) {
            self::debug_log(sprintf(
            'SMS_SKIP source=%s reason=bad_status status=%s trade_id=%d',
                $source,
                $status,
                $trade_id
            ));
            return;
        }

        // تکه/باقیمانده: صبر تا wallet_trade_fully_matched روی مادر
        if (self::is_trade_match_piece($trade)) {
            self::debug_log(sprintf(
            'SMS_SKIP source=%s reason=match_piece trade_id=%d parent_hint=%d status=%s split=%s prepaid=%s origin=%s',
                $source,
                $trade_id,
                self::resolve_match_notify_trade_id($trade_id, $trade),
                $status,
                (string) ($trade->split_from_trade_id ?? '0'),
                (string) ($trade->prepaid_from_trade_id ?? '0'),
                (string) ($trade->origin_trade_id ?? '0')
            ));
            return;
        }

        if (!empty(self::$match_success_sms_sent[$trade_id])) {
            self::debug_log(sprintf(
            'SMS_SKIP source=%s reason=dedupe trade_id=%d',
                $source,
                $trade_id
            ));
            return;
        }

        $asset_name = self::asset_title((string) $trade->balance_type);
        $is_buy = ((string) $trade->operation_type === 'buy');
        $op = $is_buy ? 'خرید' : 'فروش';
        $uid = (int) $trade->user_id;
        $name = function_exists('medyar_notify_user_display_name') ? medyar_notify_user_display_name($uid) : (self::get_user_data($uid)['name'] ?? 'کاربر');
        $link = self::account_url('trades');
        $title = "معامله {$op} {$asset_name}";
        $body = "{$name} عزیز، معامله {$op} {$asset_name} شما با موفقیت تکمیل شد.";
        $inapp = $body . "\nمشاهده جدول معاملات: {$link}";

        self::debug_log(sprintf(
            'SMS_SEND source=%s trade_id=%d user=%d op=%s msg=%s',
            $source,
            $trade_id,
            $uid,
            (string) $trade->operation_type,
            str_replace(array("\r", "\n"), array('', ' | '), $body)
        ));

        self::$match_success_sms_sent[$trade_id] = true;

        $sms_ok = self::dual_notify($uid, 'trade', $title, $inapp, $body, $trade_id);
        if ($sms_ok) {
            $mobile = self::get_user_mobile($uid);
            if ($mobile) {
                self::log_sms_in_trade($trade_id, $mobile, $body);
                self::log_sms_in_trade_wallet_transactions($trade, $mobile, $body);
            }
        }
        self::debug_log(sprintf(
            'SMS_SENT_OK source=%s trade_id=%d user=%d',
            $source,
            $trade_id,
            $uid
        ));
    }

    /**
     * Match تکی: فقط ریشهٔ completed (بدون lineage تکه) پیام می‌گیرد.
     *
     * @param int   $buy_trade_id
     * @param int   $sell_trade_id
     * @param array $data
     */
    public static function on_trade_matched(int $buy_trade_id, int $sell_trade_id, array $data): void
    {
        $buy_trade  = $data['buy_trade'] ?? null;
        $sell_trade = $data['sell_trade'] ?? null;
        $batch_id   = isset($data['match_batch_id']) ? (string) $data['match_batch_id'] : '';
        $match_type = isset($data['match_type']) ? (string) $data['match_type'] : '';

        if ($buy_trade && is_object($buy_trade) && empty($buy_trade->id)) {
            $buy_trade->id = $buy_trade_id;
        }
        if ($sell_trade && is_object($sell_trade) && empty($sell_trade->id)) {
            $sell_trade->id = $sell_trade_id;
        }

        self::debug_log(sprintf(
            'HOOK_matched batch=%s type=%s buy=%s | sell=%s',
            $batch_id,
            $match_type,
            self::trade_debug_snapshot($buy_trade_id, $buy_trade),
            self::trade_debug_snapshot($sell_trade_id, $sell_trade)
        ));

        if (!$buy_trade || !$sell_trade) {
            self::debug_log('HOOK_matched SKIP missing trade objects');
            return;
        }

        $buy_ok = ((string) ($buy_trade->status ?? '') === 'completed')
            && !self::is_trade_match_piece($buy_trade);
        $sell_ok = ((string) ($sell_trade->status ?? '') === 'completed')
            && !self::is_trade_match_piece($sell_trade);

        self::debug_log(sprintf(
            'HOOK_matched DECISION buy_send=%d sell_send=%d buy_piece=%d sell_piece=%d',
            $buy_ok ? 1 : 0,
            $sell_ok ? 1 : 0,
            self::is_trade_match_piece($buy_trade) ? 1 : 0,
            self::is_trade_match_piece($sell_trade) ? 1 : 0
        ));

        if ($buy_ok) {
            self::send_trade_fully_matched_sms($buy_trade_id, $buy_trade, 'wallet_trade_matched:buy');
        }

        if ($sell_ok) {
            self::send_trade_fully_matched_sms($sell_trade_id, $sell_trade, 'wallet_trade_matched:sell');
        }
    }

    /**
     * وقتی مادر minor به‌طور کامل match شد (minor_completed).
     *
     * @param int    $trade_id
     * @param object $trade
     */
    public static function on_trade_fully_matched(int $trade_id, object $trade): void
    {
        if ($trade && is_object($trade) && empty($trade->id)) {
            $trade->id = $trade_id;
        }
        self::debug_log(sprintf(
            'HOOK_fully_matched %s',
            self::trade_debug_snapshot($trade_id, $trade)
        ));
        self::send_trade_fully_matched_sms($trade_id, $trade, 'wallet_trade_fully_matched');
    }

    /**
     * ارسال پیامک هنگام لغو معامله
     * 
     * @param int $trade_id شناسه معامله
     * @param object $trade اطلاعات کامل معامله
     */
    public static function on_trade_canceled(int $trade_id, object $trade): void
    {
        $uid = (int) $trade->user_id;
        $asset_name = self::asset_title((string) $trade->balance_type);
        $operation_label = ($trade->operation_type === 'buy') ? 'خرید' : 'فروش';
        $name = function_exists('medyar_notify_user_display_name') ? medyar_notify_user_display_name($uid) : (self::get_user_data($uid)['name'] ?? 'کاربر');

        if ($trade->operation_type === 'buy') {
            $refund_message = sprintf(
                "مبلغ %s تومان به کیف پول شما بازگشت داده شد.",
                number_format($trade->total_price + $trade->fee_amount)
            );
        } else {
            $amt_ref = self::format_asset_amount((float) $trade->amount, (string) $trade->balance_type) . ' ' . $asset_name;
            $refund_message = sprintf(
                "%s به کیف پول شما بازگشت داده شد.",
                $amt_ref
            );
        }

        $title = "معامله {$operation_label} {$asset_name}";
        $body = "{$name} عزیز، معامله {$operation_label} {$asset_name} شما لغو شد.\n{$refund_message}";
        $link = self::account_url('trades');
        $sms_ok = self::dual_notify($uid, 'trade', $title, $body . "\nمشاهده جدول معاملات: {$link}", $body, $trade_id);

        if ($sms_ok) {
            $mobile = self::get_user_mobile($uid);
            if ($mobile) {
                self::log_sms_in_trade($trade_id, $mobile, $body);
                self::log_sms_in_trade_wallet_transactions($trade, $mobile, $body);
            }
        }
    }

    /**
     * ثبت لاگ ارسال پیامک در توضیحات معامله
     */
    private static function log_sms_in_trade(int $trade_id, string $mobile, string $message = ''): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'medyar_trades';

        $current_desc = $wpdb->get_var($wpdb->prepare(
            "SELECT description FROM {$table} WHERE id = %d",
            $trade_id
        ));
        if ($current_desc === null && $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE id = %d", $trade_id)) === null) {
            return;
        }

        $new_desc = (string) $current_desc . self::format_sms_success_log($message, $mobile);

        $wpdb->update(
            $table,
            ['description' => $new_desc],
            ['id' => $trade_id],
            ['%s'],
            ['%d']
        );
    }
}

add_action('plugins_loaded', ['Wallet_SMS', 'init'], 30);
