<?php
/**
 * صفحه تنظیمات پلاگین کیف پول
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once WALLET_PLUGIN_PATH . 'admin/admin-helpers.php';

$silver_label = esc_html(wwp_label_balance_type('silver'));
// دریافت تنظیمات فعلی
$sms_enabled              = get_option('wallet_sms_enabled', 1);
$document_support_admin_ids = get_option('medyar_document_support_admin_ids', []);
if (!is_array($document_support_admin_ids)) {
    $document_support_admin_ids = [];
}
$document_support_admin_ids = array_map('absint', $document_support_admin_ids);
$ticketing_support_admin_ids = get_option('medyar_ticketing_support_admin_ids', []);
if (!is_array($ticketing_support_admin_ids)) {
    $ticketing_support_admin_ids = [];
}
$ticketing_support_admin_ids = array_map('absint', $ticketing_support_admin_ids);
$deposit_withdrawal_admin_ids = get_option('medyar_deposit_withdrawal_admin_ids', []);
if (!is_array($deposit_withdrawal_admin_ids)) {
    $deposit_withdrawal_admin_ids = [];
}
$deposit_withdrawal_admin_ids = array_map('absint', $deposit_withdrawal_admin_ids);

$settings_eligible_roles = ['administrator', 'author'];
$settings_users = get_users([
    'role__in' => $settings_eligible_roles,
    'orderby'  => 'display_name',
    'order'    => 'ASC',
    'number'   => 200,
    'fields'   => ['ID', 'display_name', 'user_login', 'user_email'],
]);

$transaction_fee_percent  = function_exists('medyar_trading_fee_percent')
    ? medyar_trading_fee_percent()
    : (float) get_option('medyar_transaction_fee_percent', 1);
$trades_fee_percent = function_exists('medyar_trades_fee_percent')
    ? medyar_trades_fee_percent()
    : $transaction_fee_percent;
$manual_trade_listing_fee = max(0, absint(get_option('medyar_manual_trade_listing_fee', 5000)));
$credit_collateral_rate   = (float) get_option('medyar_credit_collateral_rate', 10);
$credit_collateral_tolerance = (float) get_option('medyar_credit_collateral_tolerance', 0.5);
$credit_settle_deadline_days = function_exists('medyar_credit_settle_deadline_days_global')
    ? medyar_credit_settle_deadline_days_global()
    : max(1, (int) get_option('medyar_credit_settle_deadline_days', 1));
$credit_debt_notice_snooze_hours = max(1, (int) get_option('medyar_credit_debt_notice_snooze_hours', 3));
$wallet_max_deposit       = function_exists('medyar_wallet_get_max_deposit')
    ? medyar_wallet_get_max_deposit()
    : (defined('medyar_wallet_max_deposit_DEFAULT') ? medyar_wallet_max_deposit_DEFAULT : 500000000);
$wallet_max_deposit_phrase = function_exists('medyar_wallet_deposit_limit_phrase')
    ? medyar_wallet_deposit_limit_phrase($wallet_max_deposit)
    : '';
$wallet_max_withdraw       = function_exists('medyar_wallet_get_max_withdraw')
    ? medyar_wallet_get_max_withdraw()
    : (defined('medyar_wallet_max_withdraw_DEFAULT') ? medyar_wallet_max_withdraw_DEFAULT : 200000000);
$wallet_max_withdraw_phrase = function_exists('medyar_wallet_withdraw_limit_phrase')
    ? medyar_wallet_withdraw_limit_phrase($wallet_max_withdraw)
    : '';
$silver_instant_buy_min = function_exists('medyar_silver_instant_buy_min')
    ? medyar_silver_instant_buy_min()
    : 100000;
$silver_instant_buy_max = function_exists('medyar_silver_instant_buy_max')
    ? medyar_silver_instant_buy_max()
    : 0;
$trades_price_band_frontend = function_exists('medyar_trades_price_band_frontend_percent')
    ? medyar_trades_price_band_frontend_percent()
    : 20;
$trades_price_band_server = function_exists('medyar_trades_price_band_server_percent')
    ? medyar_trades_price_band_server_percent()
    : 25;

// Migrated from ayarfar-theme-settings
$silver_replacement_price   = (float) get_option('medyar_silver_replacement_price', 0);
$api_interruption_percent   = (float) get_option('medyar_api_interruption_percent', 0);
$silver_sell_percent        = (float) get_option('medyar_silver_sell_percent', 0);
$silver_buy_percent         = (float) get_option('medyar_silver_buy_percent', 0);
$invite_reward_amount       = (float) get_option('invite_reward_amount', 0);
$global_profit_percent      = (float) get_option('medyar_global_profit_percent', 0);
$silver_pellets_product_id  = absint(get_option('medyar_silver_pellets_product_id', 0));
$melted_silver_product_id   = absint(get_option('medyar_melted_silver_product_id', 0));
$price_update_product_ids   = get_option('medyar_price_update_product_ids', []);
if (!is_array($price_update_product_ids)) {
    $price_update_product_ids = [];
}
$price_update_product_ids = array_map('absint', $price_update_product_ids);
$chart_redis_enabled        = (bool) get_option('medyar_chart_redis_enabled', false);
$verification_enforcement   = (bool) get_option('medyar_verification_enforcement_enabled', true);
$verification_level1        = (bool) get_option('medyar_verification_level1_required', true);
$verification_level2        = (bool) get_option('medyar_verification_level2_required', true);
$chart_ext_cache_active     = function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();

/**
 * Render a multi-select admin picker (checkbox list).
 *
 * @param string               $name     Input name (with [] for arrays).
 * @param string               $id       Field id prefix.
 * @param array<int,int>       $selected Selected user IDs.
 * @param array<int,WP_User>   $users    Eligible users.
 */
$wwp_render_admin_picker = static function ($name, $id, array $selected, array $users) {
    $selected = array_map('absint', $selected);
    ?>
    <div class="wwp-admin-picker" id="<?php echo esc_attr($id); ?>">
        <?php if (empty($users)) : ?>
            <p class="description" style="margin:0;"><?php echo esc_html('کاربری با نقش مدیرکل یا نویسنده یافت نشد.'); ?></p>
        <?php else : ?>
            <?php foreach ($users as $settings_user) :
                $uid = (int) $settings_user->ID;
                $display = $settings_user->display_name ?: $settings_user->user_login;
                $login   = $settings_user->user_login;
                $email   = $settings_user->user_email;
                $input_id = $id . '-' . $uid;
                ?>
                <label class="wwp-admin-picker__item" for="<?php echo esc_attr($input_id); ?>">
                    <input
                        type="checkbox"
                        name="<?php echo esc_attr($name); ?>"
                        id="<?php echo esc_attr($input_id); ?>"
                        value="<?php echo esc_attr((string) $uid); ?>"
                        <?php checked(in_array($uid, $selected, true)); ?>
                    >
                    <?php echo get_avatar($uid, 32, '', $display, ['class' => 'wwp-admin-picker__avatar']); ?>
                    <span class="wwp-admin-picker__meta">
                        <span class="wwp-admin-picker__name"><?php echo esc_html($display); ?></span>
                        <span class="wwp-admin-picker__sub">
                            @<?php echo esc_html($login); ?>
                            <?php if ($email !== '') : ?>
                                · <?php echo esc_html($email); ?>
                            <?php endif; ?>
                        </span>
                    </span>
                </label>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php
};

?>
<div class="wrap wallet-admin-wrap">
    <h1>
        <span class="dashicons dashicons-admin-settings" aria-hidden="true"></span>
        تنظیمات کیف پول
    </h1>

    <?php if (isset($_GET['updated']) && $_GET['updated'] === 'true'): ?>
        <div class="notice notice-success is-dismissible">
            <p><strong>تنظیمات با موفقیت ذخیره شد.</strong></p>
        </div>
    <?php endif; ?>

    <nav class="wwp-panel-jumpnav">
        <a href="#wwp-settings-sms">تنظیمات پیامک</a>
        <a href="#wwp-settings-pricing">قیمت‌گذاری و API</a>
        <a href="#wwp-settings-trading">خرید و فروش</a>
        <a href="#wwp-settings-trades">معاملات روی تابلو</a>
        <a href="#wwp-settings-manual-trades">معاملات حجمی بلوکی</a>
        <a href="#wwp-settings-credit">تنظیمات سیستم اعتبار</a>
        <a href="#wwp-settings-deposit">تنظیمات واریز و برداشت</a>
        <a href="#wwp-settings-verification">اعتبارسنجی کاربران</a>
        <a href="#wwp-settings-admin-tools">ابزارهای ادمین</a>
    </nav>

    <details class="wwp-user-panel-details wwp-settings-section" open id="wwp-settings-admin-tools">
        <summary>
            <span>ابزارهای ادمین (مرورگر)</span>
            <button type="button" class="wwp-details-close" onclick="this.closest('details').removeAttribute('open'); return false;">بستن</button>
        </summary>
        <div class="wwp-details-body">
            <div class="wwp-details-inner">
                <p class="description">حافظهٔ فیلتر/سورت صفحات لیست کیف پول فقط در همین مرورگر (localStorage) ذخیره می‌شود و روی سرور یا فرانت کاربر اثری ندارد.</p>
                <p>
                    <button type="button" class="button" id="wwp-clear-admin-list-memory">پاک کردن حافظهٔ فیلترهای ادمین در این مرورگر</button>
                </p>
            </div>
        </div>
    </details>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="medyar_save_settings">
        <?php wp_nonce_field('medyar_save_settings', 'settings_nonce'); ?>

        <details class="wwp-user-panel-details wwp-settings-section" open id="wwp-settings-sms">
            <summary>
                <span><span class="dashicons dashicons-email" aria-hidden="true"></span> تنظیمات پیامک</span>
                <button type="button" class="wwp-details-close" onclick="this.closest('details').removeAttribute('open'); return false;">بستن</button>
            </summary>
            <div class="wwp-details-body">
            <div class="wwp-details-inner">

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="wallet_sms_enabled">ارسال پیامک به کاربران</label>
                        </th>
                        <td>
                            <fieldset>
                                <label for="wallet_sms_enabled">
                                    <input
                                        type="checkbox"
                                        name="wallet_sms_enabled"
                                        id="wallet_sms_enabled"
                                        value="1"
                                        <?php checked($sms_enabled, 1); ?>
                                    >
                                    <strong>فعال</strong> — ارسال خودکار پیامک برای رویدادهای کیف پول و معاملات
                                </label>
                                <p class="description">
                                    در صورت غیرفعال بودن، هیچ پیامکی به کاربران ارسال نخواهد شد. این شامل:
                                </p>
                                <ul style="list-style: disc; margin-right: 20px; margin-top: 8px;">
                                    <li>پیامک‌های تأیید تراکنش‌ها</li>
                                    <li>پیامک‌های Match شدن معاملات</li>
                                    <li>پیامک‌های لغو معاملات</li>
                                    <li>پیامک‌های شارژ و برداشت کیف پول</li>
                                    <li>پیامک‌های خرید و فروش دارایی</li>
                                </ul>

                                <?php if (!$sms_enabled): ?>
                                    <div style="background: #fff3cd; border-right: 4px solid #856404; padding: 12px; margin-top: 15px; border-radius: 3px;">
                                        <strong style="color: #856404;">⚠️ هشدار:</strong>
                                        <span style="color: #856404;">
                                            ارسال پیامک در حال حاضر غیرفعال است. کاربران از تغییرات حساب خود مطلع نخواهند شد.
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <div style="background: #d1ecf1; border-right: 4px solid #0c5460; padding: 12px; margin-top: 15px; border-radius: 3px;">
                                        <strong style="color: #0c5460;">✓ فعال:</strong>
                                        <span style="color: #0c5460;">
                                            پیامک‌ها به طور خودکار برای کاربران ارسال می‌شوند.
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">ادمین‌های پشتیبان بررسی مدارک</th>
                        <td>
                            <?php $wwp_render_admin_picker(
                                'medyar_document_support_admin_ids[]',
                                'medyar_document_support_admin_ids',
                                $document_support_admin_ids,
                                $settings_users
                            ); ?>
                            <p class="description">
                                فقط کاربران با نقش مدیرکل یا نویسنده. وقتی مدرکی وارد صف بررسی می‌شود، پیامک به شماره تماس این کاربران ارسال می‌شود.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">ادمین‌های پشتیبان تیکتینگ</th>
                        <td>
                            <?php $wwp_render_admin_picker(
                                'medyar_ticketing_support_admin_ids[]',
                                'medyar_ticketing_support_admin_ids',
                                $ticketing_support_admin_ids,
                                $settings_users
                            ); ?>
                            <p class="description">
                                فقط کاربران با نقش مدیرکل یا نویسنده. ادمین‌هایی که در قبال تیکت‌های کاربران مسئولیت دارند.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">ادمین‌های واریز و برداشت</th>
                        <td>
                            <?php $wwp_render_admin_picker(
                                'medyar_deposit_withdrawal_admin_ids[]',
                                'medyar_deposit_withdrawal_admin_ids',
                                $deposit_withdrawal_admin_ids,
                                $settings_users
                            ); ?>
                            <p class="description">
                                فقط کاربران با نقش مدیرکل یا نویسنده. ادمین‌هایی که در قبال درخواست‌های واریز و برداشت کاربران مسئولیت دارند.
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>

            </div>
            </div>
        </details>

        <details class="wwp-user-panel-details wwp-settings-section" open id="wwp-settings-pricing">
            <summary>
                <span><span class="dashicons dashicons-chart-area" aria-hidden="true"></span> قیمت‌گذاری و API</span>
                <button type="button" class="wwp-details-close" onclick="this.closest('details').removeAttribute('open'); return false;">بستن</button>
            </summary>
            <div class="wwp-details-body">
            <div class="wwp-details-inner">

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="medyar_silver_replacement_price">قیمت جایگزین نقره در بدترین حالت</label>
                        </th>
                        <td>
                            <input type="number" name="medyar_silver_replacement_price" id="medyar_silver_replacement_price" value="<?php echo esc_attr($silver_replacement_price); ?>" min="0" step="1" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="medyar_api_interruption_percent">درصد افزایش آخرین قیمت هنگام اختلال در API</label>
                        </th>
                        <td>
                            <input type="number" name="medyar_api_interruption_percent" id="medyar_api_interruption_percent" value="<?php echo esc_attr($api_interruption_percent); ?>" step="1" class="regular-text">
                            <p class="description">درصد مورد نظر را وارد کنید (مثلاً ۲ برای ۲ درصد).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="medyar_silver_sell_percent">درصد برای قیمت فروش هر گرم نقره</label>
                        </th>
                        <td>
                            <input type="number" name="medyar_silver_sell_percent" id="medyar_silver_sell_percent" value="<?php echo esc_attr($silver_sell_percent); ?>" step="0.01" class="regular-text">
                            <p class="description">این درصد ضرب در قیمت لحظه هر گرم نقره می‌شود و کاربر با آن قیمت نقره را از عیارفر خریداری می‌کند.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="medyar_silver_buy_percent">درصد برای قیمت خرید هر گرم نقره</label>
                        </th>
                        <td>
                            <input type="number" name="medyar_silver_buy_percent" id="medyar_silver_buy_percent" value="<?php echo esc_attr($silver_buy_percent); ?>" step="0.01" class="regular-text">
                            <p class="description">این درصد ضرب در قیمت لحظه هر گرم نقره می‌شود و کاربر با آن قیمت نقره را به عیارفر می‌فروشد.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="medyar_silver_pellets_product_id">محصول ساچمه نقره</label>
                        </th>
                        <td>
                            <?php wwp_admin_render_product_select('medyar_silver_pellets_product_id', 'medyar_silver_pellets_product_id', $silver_pellets_product_id); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="medyar_melted_silver_product_id">محصول نقره آب‌شده (تحویل ساچمه)</label>
                        </th>
                        <td>
                            <?php wwp_admin_render_product_select('medyar_melted_silver_product_id', 'medyar_melted_silver_product_id', $melted_silver_product_id); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="invite_reward_amount">مقدار گرم پاداش به ازای هر دعوت</label>
                        </th>
                        <td>
                            <input type="number" name="invite_reward_amount" id="invite_reward_amount" value="<?php echo esc_attr($invite_reward_amount); ?>" step="0.01" min="0" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="medyar_global_profit_percent">درصد سود ثابت برای همه محصولات</label>
                        </th>
                        <td>
                            <input type="number" name="medyar_global_profit_percent" id="medyar_global_profit_percent" value="<?php echo esc_attr($global_profit_percent); ?>" step="0.01" class="regular-text">
                            <p class="description">این درصد در فرمول قیمت‌گذاری همه محصولات اعمال می‌شود.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="medyar_price_update_product_ids">محصولاتی که قیمت‌شان آپدیت می‌شود</label>
                        </th>
                        <td>
                            <?php wwp_admin_render_product_multiselect('medyar_price_update_product_ids[]', 'medyar_price_update_product_ids', $price_update_product_ids); ?>
                            <p class="description">برای انتخاب چند محصول، Ctrl (یا Cmd در مک) را نگه دارید.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">کش Redis برای نمودار قیمت</th>
                        <td>
                            <label for="medyar_chart_redis_enabled">
                                <input type="checkbox" name="medyar_chart_redis_enabled" id="medyar_chart_redis_enabled" value="1" <?php checked($chart_redis_enabled); ?>>
                                استفاده از Redis برای کش داده‌های نمودار قیمت
                            </label>
                            <p class="description">
                                وضعیت External Object Cache:
                                <?php if ($chart_ext_cache_active) : ?>
                                    <span style="color:#2e7d32;font-weight:600;">فعال (Redis Object Cache شناسایی شد)</span>
                                <?php else : ?>
                                    <span style="color:#b71c1c;">غیرفعال (External Object Cache شناسایی نشد)</span>
                                <?php endif; ?>
                                <br>
                                هنگام فعال بودن، توابع <code>wp_cache_get/set/delete</code> با گروه <code>silver</code> استفاده می‌شوند؛ در غیر این صورت Transient.
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>

            </div>
            </div>
        </details>

        <details class="wwp-user-panel-details wwp-settings-section" open id="wwp-settings-trading">
            <summary>
                <span><span class="dashicons dashicons-cart" aria-hidden="true"></span> خرید و فروش</span>
                <button type="button" class="wwp-details-close" onclick="this.closest('details').removeAttribute('open'); return false;">بستن</button>
            </summary>
            <div class="wwp-details-body">
            <div class="wwp-details-inner">

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="medyar_transaction_fee_percent">درصد کارمزد (%) خرید و فروش</label>
                        </th>
                        <td>
                            <input
                                type="number"
                                name="medyar_transaction_fee_percent"
                                id="medyar_transaction_fee_percent"
                                value="<?php echo esc_attr($transaction_fee_percent); ?>"
                                min="0" max="100" step="0.01"
                                style="width:100px;"
                            >
                            <p class="description">
                                کارمزد اندپوینت <strong>خرید و فروش</strong> (trading) روی <strong>کل مبلغ</strong> اعمال می‌شود
                                (نه روی قیمت هر واحد): در خرید این درصد به مبلغ پرداختی کاربر <strong>اضافه</strong> و در فروش از مبلغ
                                دریافتی کاربر <strong>کسر</strong> می‌شود. پیش‌فرض: <strong>۱%</strong>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="medyar_silver_instant_buy_min">حداقل خرید <?php echo $silver_label; ?> (تومان)</label>
                        </th>
                        <td>
                            <input
                                type="number"
                                name="medyar_silver_instant_buy_min"
                                id="medyar_silver_instant_buy_min"
                                value="<?php echo esc_attr($silver_instant_buy_min); ?>"
                                min="0"
                                step="1"
                                style="width:140px;"
                            >
                            <p class="description">حداقل مبلغ خرید. پیش‌فرض: <strong>۱۰۰٬۰۰۰ تومان</strong></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="medyar_silver_instant_buy_max">حداکثر خرید <?php echo $silver_label; ?> (تومان)</label>
                        </th>
                        <td>
                            <input
                                type="number"
                                name="medyar_silver_instant_buy_max"
                                id="medyar_silver_instant_buy_max"
                                value="<?php echo esc_attr($silver_instant_buy_max); ?>"
                                min="0"
                                step="1"
                                style="width:140px;"
                            >
                            <p class="description">۰ = بدون محدودیت</p>
                        </td>
                    </tr>
                </tbody>
            </table>

            </div>
            </div>
        </details>

        <details class="wwp-user-panel-details wwp-settings-section" open id="wwp-settings-trades">
            <summary>
                <span><span class="dashicons dashicons-chart-line" aria-hidden="true"></span> معاملات روی تابلو</span>
                <button type="button" class="wwp-details-close" onclick="this.closest('details').removeAttribute('open'); return false;">بستن</button>
            </summary>
            <div class="wwp-details-body">
            <div class="wwp-details-inner">

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="medyar_trades_fee_percent">درصد کارمزد (%) معامله روی تابلو</label>
                        </th>
                        <td>
                            <input
                                type="number"
                                name="medyar_trades_fee_percent"
                                id="medyar_trades_fee_percent"
                                value="<?php echo esc_attr($trades_fee_percent); ?>"
                                min="0" max="100" step="0.01"
                                style="width:100px;"
                            >
                            <p class="description">
                                کارمزد معاملات P2P اندپوینت <strong>trades</strong> روی کل مبلغ سفارش.
                                پیش‌فرض: همان مقدار کارمزد خرید و فروش (در صورت خالی بودن قبلاً).
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="medyar_trades_price_band_frontend">باند قیمت معاملات P2P — فرانت (%)</label>
                        </th>
                        <td>
                            <input
                                type="number"
                                name="medyar_trades_price_band_frontend"
                                id="medyar_trades_price_band_frontend"
                                value="<?php echo esc_attr($trades_price_band_frontend); ?>"
                                min="1" max="100" step="1"
                                style="width:100px;"
                            >
                            <p class="description">
                                حداکثر انحراف مجاز قیمت واحد در فرم معاملات (تابلو) نسبت به قیمت لحظه‌ای spot.
                                پیش‌فرض: <strong>۲۰٪</strong>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="medyar_trades_price_band_server">باند قیمت معاملات P2P — سرور (%)</label>
                        </th>
                        <td>
                            <input
                                type="number"
                                name="medyar_trades_price_band_server"
                                id="medyar_trades_price_band_server"
                                value="<?php echo esc_attr($trades_price_band_server); ?>"
                                min="1" max="100" step="1"
                                style="width:100px;"
                            >
                            <p class="description">
                                حداکثر انحراف مجاز در اعتبارسنجی سرور هنگام ثبت سفارش (معمولاً کمی بازتر از فرانت
                                تا با نوسان قیمت بین باز شدن پاپ‌آپ و ثبت نهایی، سفارش معتبر رد نشود).
                                پیش‌فرض: <strong>۲۵٪</strong>
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>

            </div>
            </div>
        </details>

        <details class="wwp-user-panel-details wwp-settings-section" open id="wwp-settings-manual-trades">
            <summary>
                <span><span class="dashicons dashicons-groups" aria-hidden="true"></span> معاملات حجمی بلوکی</span>
                <button type="button" class="wwp-details-close" onclick="this.closest('details').removeAttribute('open'); return false;">بستن</button>
            </summary>
            <div class="wwp-details-body">
            <div class="wwp-details-inner">

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="medyar_manual_trade_listing_fee">درصد کارمزد (%) ثبت معامله حجمی بلوکی</label>
                        </th>
                        <td>
                            <input
                                type="number"
                                name="medyar_manual_trade_listing_fee"
                                id="medyar_manual_trade_listing_fee"
                                value="<?php echo esc_attr($manual_trade_listing_fee); ?>"
                                min="0" step="1"
                                style="width:120px;"
                            >
                            <p class="description">
                                مبلغ ثابت (تومان) که هنگام ثبت هر معامله در اتاق «معاملات حجمی بلوکی» از موجودی تومانی کیف پول کاربر کسر می‌شود.
                                پیش‌فرض: <strong>۵٬۰۰۰ تومان</strong>
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>

            </div>
            </div>
        </details>

        <details class="wwp-user-panel-details wwp-settings-section" open id="wwp-settings-credit">
            <summary>
                <span><span class="dashicons dashicons-bank" aria-hidden="true"></span> تنظیمات سیستم اعتبار</span>
                <button type="button" class="wwp-details-close" onclick="this.closest('details').removeAttribute('open'); return false;">بستن</button>
            </summary>
            <div class="wwp-details-body">
            <div class="wwp-details-inner">

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="medyar_credit_collateral_rate">نرخ وثیقه (%)</label>
                        </th>
                        <td>
                            <input
                                type="number"
                                name="medyar_credit_collateral_rate"
                                id="medyar_credit_collateral_rate"
                                value="<?php echo esc_attr($credit_collateral_rate); ?>"
                                min="0" max="100" step="0.1"
                                style="width:100px;"
                            >
                            <p class="description">
                                درصدی از ارزش ریالی اعتبار درخواستی که کاربر باید به عنوان وثیقه فریز کند. پیش‌فرض: <strong>۱۰%</strong>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="medyar_credit_collateral_tolerance">تلرانس وثیقه (%)</label>
                        </th>
                        <td>
                            <input
                                type="number"
                                name="medyar_credit_collateral_tolerance"
                                id="medyar_credit_collateral_tolerance"
                                value="<?php echo esc_attr($credit_collateral_tolerance); ?>"
                                min="0" max="10" step="0.1"
                                style="width:100px;"
                            >
                            <p class="description">
                                اگر وثیقه ارائه‌شده حداقل <code>(1 − تلرانس%) × وثیقه</code> باشد قابل قبول است. پیش‌فرض: <strong>۰.۵%</strong>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="medyar_credit_settle_deadline_days">مهلت تسویه اعتبار (روز)</label>
                        </th>
                        <td>
                            <input
                                type="number"
                                name="medyar_credit_settle_deadline_days"
                                id="medyar_credit_settle_deadline_days"
                                value="<?php echo esc_attr($credit_settle_deadline_days); ?>"
                                min="1" max="365" step="1"
                                style="width:100px;"
                            >
                            <p class="description">
                                تعداد روز پس از دریافت اعتبار تا مهلت تسویه؛ همیشه تا ساعت ۱۳ تهران.
                                مثال: دریافت ۱۹ام با ۳ روز → مهلت ۲۲ام ساعت ۱۳. پیش‌فرض: <strong>۱</strong> روز
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="medyar_credit_debt_notice_snooze_hours">مهلت عدم نمایش هشدار بدهی (ساعت)</label>
                        </th>
                        <td>
                            <input
                                type="number"
                                name="medyar_credit_debt_notice_snooze_hours"
                                id="medyar_credit_debt_notice_snooze_hours"
                                value="<?php echo esc_attr($credit_debt_notice_snooze_hours); ?>"
                                min="1" max="720" step="1"
                                style="width:100px;"
                            >
                            <p class="description">
                                پس از دریافت اعتبار، و همچنین پس از بستن مودال هشدار بدهی (با تأیید مطالعه)،
                                مودال تا این مدت دوباره نمایش داده نمی‌شود. پیش‌فرض: <strong>۳</strong> ساعت
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>

            </div>
            </div>
        </details>

        <details class="wwp-user-panel-details wwp-settings-section" open id="wwp-settings-deposit">
            <summary>
                <span><span class="dashicons dashicons-money-alt" aria-hidden="true"></span> تنظیمات واریز و برداشت</span>
                <button type="button" class="wwp-details-close" onclick="this.closest('details').removeAttribute('open'); return false;">بستن</button>
            </summary>
            <div class="wwp-details-body">
            <div class="wwp-details-inner">

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="medyar_wallet_max_deposit">حداکثر مبلغ واریز (تومان)</label>
                        </th>
                        <td>
                            <input
                                type="number"
                                name="medyar_wallet_max_deposit"
                                id="medyar_wallet_max_deposit"
                                value="<?php echo esc_attr($wallet_max_deposit); ?>"
                                min="100000"
                                max="2000000000"
                                step="1"
                                style="width:200px;"
                            >
                            <p class="description">
                                سقف کلی مبلغ واریز در پنل کاربری (دکمه «حداکثر واریز»، اعتبارسنجی سرور و روش‌های پایا/پل).
                                <?php if ($wallet_max_deposit_phrase !== '') : ?>
                                    نمایش فعلی در سایت: <strong><?php echo esc_html($wallet_max_deposit_phrase); ?></strong>
                                <?php endif; ?>
                            </p>
                            <p class="description">پیش‌فرض: <strong>۵۰۰٬۰۰۰٬۰۰۰</strong> تومان (۵۰۰ میلیون). حداقل: ۱۰۰٬۰۰۰ تومان.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="medyar_wallet_max_withdraw">حداکثر مبلغ برداشت (تومان)</label>
                        </th>
                        <td>
                            <input
                                type="number"
                                name="medyar_wallet_max_withdraw"
                                id="medyar_wallet_max_withdraw"
                                value="<?php echo esc_attr($wallet_max_withdraw); ?>"
                                min="100000"
                                max="2000000000"
                                step="1"
                                style="width:200px;"
                            >
                            <p class="description">
                                سقف کلی مبلغ هر درخواست برداشت در پنل کاربری (دکمه «حداکثر برداشت»، اعتبارسنجی سرور).
                                سقف نهایی برای کاربر برابر <code>min(موجودی، این مقدار)</code> است.
                                <?php if ($wallet_max_withdraw_phrase !== '') : ?>
                                    نمایش فعلی در سایت: <strong><?php echo esc_html($wallet_max_withdraw_phrase); ?></strong>
                                <?php endif; ?>
                            </p>
                            <p class="description">پیش‌فرض: <strong>۲۰۰٬۰۰۰٬۰۰۰</strong> تومان (۲۰۰ میلیون). حداقل: ۱۰۰٬۰۰۰ تومان.</p>
                        </td>
                    </tr>
                </tbody>
            </table>

            </div>
            </div>
        </details>

        <details class="wwp-user-panel-details wwp-settings-section" open id="wwp-settings-verification">
            <summary>
                <span><span class="dashicons dashicons-id" aria-hidden="true"></span> اعتبارسنجی کاربران (سطح‌بندی)</span>
                <button type="button" class="wwp-details-close" onclick="this.closest('details').removeAttribute('open'); return false;">بستن</button>
            </summary>
            <div class="wwp-details-body">
            <div class="wwp-details-inner">
                <p class="description">با غیرفعال کردن موقت، ریدایرکت و محدودیت‌های اعتبارسنجی برای کاربران اعمال نمی‌شود. برای تعمیرات یا تست مفید است.</p>

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row">فعال‌سازی سیستم سطح‌بندی</th>
                        <td>
                            <label for="medyar_verification_enforcement_enabled">
                                <input type="checkbox" name="medyar_verification_enforcement_enabled" id="medyar_verification_enforcement_enabled" value="1" <?php checked($verification_enforcement); ?>>
                                اعمال سطح‌بندی اعتبارسنجی برای کاربران
                            </label>
                            <p class="description">اگر غیرفعال باشد، هیچ ریدایرکتی به صفحه validation یا profile برای تکمیل هویت انجام نمی‌شود.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">الزام سطح ۱ (کد ملی / شاهکار)</th>
                        <td>
                            <label for="medyar_verification_level1_required">
                                <input type="checkbox" name="medyar_verification_level1_required" id="medyar_verification_level1_required" value="1" <?php checked($verification_level1); ?>>
                                کاربران باید سطح ۱ (تایید کد ملی) را تکمیل کنند
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">الزام سطح ۲ (کارت ملی / OCR)</th>
                        <td>
                            <label for="medyar_verification_level2_required">
                                <input type="checkbox" name="medyar_verification_level2_required" id="medyar_verification_level2_required" value="1" <?php checked($verification_level2); ?>>
                                کاربران باید سطح ۲ (آپلود کارت ملی) را برای دسترسی کامل تکمیل کنند
                            </label>
                            <p class="description">در صورت غیرفعال بودن، پس از سطح ۱ محدودیت checkout و موارد حساس برداشته می‌شود.</p>
                        </td>
                    </tr>
                </tbody>
            </table>

            </div>
            </div>
        </details>

        <div class="wwp-settings-sticky-save">
            <?php submit_button('ذخیره تنظیمات', 'primary', 'submit', false); ?>
        </div>
    </form>

    <!-- راهنمای سریع -->
    <div style="background: #f0f0f1; padding: 15px; margin-top: 20px; border-radius: 5px;">
        <h3 style="margin-top: 0;">
            <span class="dashicons dashicons-info" aria-hidden="true"></span>
            راهنمای سریع
        </h3>
        <ul style="list-style: disc; margin-right: 20px;">
            <li><strong>ارسال پیامک:</strong> با غیرفعال کردن این گزینه، می‌توانید از ارسال پیامک‌های اضافی جلوگیری کنید (مفید برای تست یا کاهش هزینه‌ها)</li>
            <li><strong>لاگ پیامک‌ها:</strong> تمام پیامک‌های ارسال شده در لاگ سرور (error_log) ثبت می‌شوند</li>
            <li><strong>تأثیر بر عملکرد:</strong> غیرفعال کردن پیامک تنها جلوی ارسال را می‌گیرد، سایر عملیات سیستم بدون تغییر ادامه می‌یابد</li>
            <li><strong>حداکثر واریز / برداشت:</strong> پس از ذخیره، سقف‌ها در پنل کاربری و اعتبارسنجی AJAX به‌روز می‌شوند</li>
        </ul>
    </div>
</div>
