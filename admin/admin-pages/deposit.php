<?php
/**
 * Manual deposit by card / IBAN lookup
 */
if (!defined('ABSPATH')) {
    exit;
}

require_once WALLET_PLUGIN_PATH . 'admin/admin-helpers.php';
require_once WALLET_PLUGIN_PATH . 'admin/admin-list-query.php';
require_once WALLET_PLUGIN_PATH . 'admin/admin-helpers-tx-modal-data.php';

$search_card = isset($_GET['card']) ? preg_replace('/\D/', '', (string) wp_unslash($_GET['card'])) : '';
$search_iban = isset($_GET['iban'])
    ? strtoupper(preg_replace('/\s+/', '', sanitize_text_field(wp_unslash($_GET['iban']))))
    : '';
$search_user_q = isset($_GET['user_q']) ? sanitize_text_field(wp_unslash($_GET['user_q'])) : '';

$matches  = [];
$searched = ($search_card !== '' || $search_iban !== '' || $search_user_q !== '');
if ($searched) {
    $raw = wwp_deposit_collect_matches($search_card, $search_iban, $search_user_q);
    foreach ($raw as $row) {
        $matches[] = wwp_deposit_enrich_match_for_admin($row);
    }
}

$audit_args = wwp_admin_list_parse_args(['per_page' => 20, 'paged' => 1, 'order' => 'DESC']);
$audit = wwp_admin_query_transactions($audit_args, 'manual_deposit');

$silver_price = 0.0;
$silver_price = 0;
$tx_js_data                 = wwp_build_transaction_modal_js_data($audit['rows'] ?? []);
$wwp_modal_redirect_page    = 'medyar-wallet-deposit';
$wwp_modal_redirect_user_id = 0;

$batch_log = null;
$batch_log_token = isset($_GET['batch_log']) ? sanitize_key((string) wp_unslash($_GET['batch_log'])) : '';
if ($batch_log_token !== '') {
    $batch_log_key = 'wwp_deposit_batch_log_' . get_current_user_id() . '_' . $batch_log_token;
    $stored        = get_transient($batch_log_key);
    if (is_array($stored)) {
        $batch_log = $stored;
        delete_transient($batch_log_key);
    }
}
$batch_ok   = is_array($batch_log) ? (int) ($batch_log['ok_count'] ?? 0) : (isset($_GET['batch_ok']) ? absint($_GET['batch_ok']) : 0);
$batch_fail = is_array($batch_log) ? (int) ($batch_log['fail_count'] ?? 0) : (isset($_GET['batch_fail']) ? absint($_GET['batch_fail']) : 0);
?>
<div class="wrap wallet-admin-wrap wwp-deposit-page" id="wwp-deposit-page"
     data-initial-searched="<?php echo $searched ? '1' : '0'; ?>">
    <h1>واریز</h1>
    <p class="description wallet-page-desc">جستجوی کاربر با شماره کارت، شبا، موبایل یا شناسه و واریز دستی تومان به کیف پول. برای چند واریز همزمان از «صف واریز» استفاده کنید.</p>

    <?php if (isset($_GET['success'])) :
        $ok_uid = absint($_GET['uid'] ?? 0);
        $ok_amt = wallet_sanitize_amount($_GET['amount'] ?? 0);
        ?>
        <div class="notice notice-success is-dismissible">
            <p>
                واریز با موفقیت انجام شد
                <?php if ($ok_uid > 0 && $ok_amt > 0) : ?>
                    — کاربر #<?php echo (int) $ok_uid; ?> —
                    <?php echo esc_html(number_format_i18n($ok_amt)); ?> تومان
                <?php endif; ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if ($batch_ok > 0 || $batch_fail > 0) :
        $ok_rows   = is_array($batch_log) ? (array) ($batch_log['ok'] ?? []) : [];
        $fail_rows = is_array($batch_log) ? (array) ($batch_log['failed'] ?? []) : [];
        $ok_total  = is_array($batch_log) ? (float) ($batch_log['ok_total'] ?? 0) : 0;
        $notice_cls = $batch_fail > 0 ? ($batch_ok > 0 ? 'notice-warning' : 'notice-error') : 'notice-success';
        ?>
        <div class="notice <?php echo esc_attr($notice_cls); ?> is-dismissible wwp-deposit-batch-notice">
            <p>
                <strong>گزارش واریز گروهی</strong>
                —
                <?php echo (int) $batch_ok; ?> موفق
                <?php if ($ok_total > 0) : ?>
                    (جمع <?php echo esc_html(number_format_i18n($ok_total)); ?> تومان)
                <?php endif; ?>
                <?php if ($batch_fail > 0) : ?>
                    — <?php echo (int) $batch_fail; ?> ناموفق
                <?php endif; ?>
            </p>
            <?php if (!empty($ok_rows)) : ?>
                <p style="margin-bottom:4px;"><strong>موفق:</strong></p>
                <ul class="wwp-deposit-batch-log">
                    <?php foreach ($ok_rows as $row) :
                        $uid   = (int) ($row['user_id'] ?? 0);
                        $name  = (string) ($row['display_name'] ?? '');
                        $card  = (string) ($row['card_number'] ?? '');
                        $amt   = (float) ($row['amount'] ?? 0);
                        $line  = sprintf(
                            '#%d%s — کارت %s — %s تومان',
                            $uid,
                            $name !== '' ? (' (' . $name . ')') : '',
                            $card !== '' ? $card : '—',
                            number_format_i18n($amt)
                        );
                        ?>
                        <li><?php echo esc_html($line); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if (!empty($fail_rows)) : ?>
                <p style="margin-bottom:4px;"><strong>ناموفق:</strong></p>
                <ul class="wwp-deposit-batch-log wwp-deposit-batch-log--fail">
                    <?php foreach ($fail_rows as $row) :
                        $uid   = (int) ($row['user_id'] ?? 0);
                        $name  = (string) ($row['display_name'] ?? '');
                        $card  = (string) ($row['card_number'] ?? '');
                        $amt   = (float) ($row['amount'] ?? 0);
                        $msg   = (string) ($row['message'] ?? 'خطای نامشخص');
                        $line  = sprintf(
                            '#%d%s — کارت %s — %s تومان — %s',
                            $uid,
                            $name !== '' ? (' (' . $name . ')') : '',
                            $card !== '' ? $card : '—',
                            number_format_i18n($amt),
                            $msg
                        );
                        ?>
                        <li><?php echo esc_html($line); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($_GET['error'])) : ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo esc_html(rawurldecode((string) $_GET['error'])); ?></p>
        </div>
    <?php endif; ?>

    <div id="wwp-deposit-ajax-notice" class="notice is-dismissible" hidden></div>

    <div class="wallet-card wallet-filter-card wwp-deposit-search-card">
        <h2>جستجوی کاربر</h2>
        <form id="wwp-deposit-search-form" method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" autocomplete="off">
            <input type="hidden" name="page" value="medyar-wallet-deposit">
            <div class="wwp-filter-grid wwp-deposit-search-grid">
                <label for="deposit_user_q">کاربر / موبایل / ایمیل
                    <input type="search" name="user_q" id="deposit_user_q" class="regular-text"
                           value="<?php echo esc_attr($search_user_q); ?>"
                           placeholder="شناسه، موبایل یا ایمیل" autocomplete="off">
                </label>
                <label for="deposit_search_card">شماره کارت
                    <input type="text" name="card" id="deposit_search_card" class="regular-text" inputmode="numeric"
                           maxlength="19" value="<?php echo esc_attr($search_card); ?>"
                           placeholder="۱۶ رقم" dir="ltr" autocomplete="off">
                </label>
                <label for="deposit_search_iban">شماره شبا
                    <input type="text" name="iban" id="deposit_search_iban" class="regular-text"
                           maxlength="26" value="<?php echo esc_attr($search_iban); ?>"
                           placeholder="IR..." dir="ltr" autocomplete="off">
                </label>
            </div>
            <p class="description wwp-deposit-search-hint">حداقل یکی از فیلدها را پر کنید. جستجوی کارت/شبا بر جستجوی کاربر اولویت دارد. نتایج بدون رفرش صفحه بارگذاری می‌شوند.</p>
            <p class="wallet-filter-actions">
                <button type="submit" class="button button-primary" id="wwp-deposit-search-btn">جستجو</button>
                <button type="button" class="button" id="wwp-deposit-search-reset">بازنشانی</button>
                <span class="spinner" id="wwp-deposit-search-spinner" style="float:none;margin:0;"></span>
            </p>
        </form>
    </div>

    <div class="wallet-card wwp-deposit-results-card" id="wwp-deposit-results-card" <?php echo $searched ? '' : 'hidden'; ?>>
        <div class="wallet-card-toolbar">
            <h2>نتایج <span id="wwp-deposit-results-count">(<?php echo (int) count($matches); ?>)</span></h2>
        </div>
        <div id="wwp-deposit-results-empty" class="wwp-deposit-empty" <?php echo ($searched && empty($matches)) ? '' : 'hidden'; ?>>
            <p>کاربری با این مشخصات یافت نشد.</p>
        </div>
        <div class="wwp-table-scroll" id="wwp-deposit-results-wrap" <?php echo empty($matches) ? 'hidden' : ''; ?>>
            <table class="wallet-table wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th>کاربر</th>
                        <th>موجودی تومان</th>
                        <th>بانک</th>
                        <th>شماره کارت</th>
                        <th>شبا</th>
                        <th>صاحب حساب</th>
                        <th>مبلغ واریز</th>
                    </tr>
                </thead>
                <tbody id="wwp-deposit-results-body">
                    <?php
                    if (!empty($matches)) {
                        foreach ($matches as $row) {
                            wwp_deposit_render_result_row($row, $search_card, $search_iban, $search_user_q);
                        }
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="wallet-card wwp-deposit-queue-card" id="wwp-deposit-queue-card">
        <div class="wallet-card-toolbar">
            <h2>
                <span class="dashicons dashicons-list-view" aria-hidden="true"></span>
                صف واریز
                <span class="wwp-deposit-queue-badge" id="wwp-deposit-queue-count">0</span>
            </h2>
            <div class="wwp-deposit-queue-toolbar-actions">
                <button type="button" class="button" id="wwp-deposit-queue-clear" disabled>خالی کردن صف</button>
                <button type="button" class="button button-primary" id="wwp-deposit-queue-run" disabled>اجرای همه واریزها</button>
            </div>
        </div>
        <p class="description">مبالغ مختلف برای چند کارت را جمع کنید، سپس یک‌جا واریز کنید. از نتایج جستجو «افزودن به صف» را بزنید یا کارت را اینجا وارد کنید.</p>

        <div class="wwp-deposit-quick-add">
            <label for="wwp-deposit-quick-card">افزودن سریع با کارت
                <input type="text" id="wwp-deposit-quick-card" class="regular-text" inputmode="numeric"
                       maxlength="19" placeholder="شماره کارت ۱۶ رقمی" dir="ltr" autocomplete="off">
            </label>
            <label for="wwp-deposit-quick-amount">مبلغ (تومان)
                <input type="number" id="wwp-deposit-quick-amount" class="medyar-wallet-deposit-amount" min="1" step="1"
                       placeholder="مبلغ" dir="ltr">
            </label>
            <button type="button" class="button" id="wwp-deposit-quick-add-btn">جستجو و افزودن</button>
            <span class="spinner" id="wwp-deposit-quick-spinner" style="float:none;margin:0;"></span>
        </div>

        <div id="wwp-deposit-queue-empty" class="wwp-deposit-empty">
            <p>صف خالی است. پس از جستجو مبلغ را وارد کرده و «افزودن به صف» را بزنید.</p>
        </div>

        <div class="wwp-table-scroll" id="wwp-deposit-queue-wrap" hidden>
            <table class="wallet-table wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>کاربر</th>
                        <th>بانک / کارت</th>
                        <th>شبا</th>
                        <th>مبلغ (تومان)</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="wwp-deposit-queue-body"></tbody>
                <tfoot>
                    <tr>
                        <td colspan="4" class="wwp-deposit-queue-total-label">جمع کل</td>
                        <td colspan="2" id="wwp-deposit-queue-total" dir="ltr">۰ تومان</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div id="wwp-deposit-batch-status" class="wwp-deposit-batch-status" hidden></div>
    </div>

    <div class="wallet-card">
        <h2>آخرین واریزهای دستی ادمین</h2>
        <p class="description">فقط‌خواندنی — برای حسابرسی روزمره عملیات واریز دستی.</p>
        <div class="wwp-table-scroll">
            <table class="wallet-table wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th>شناسه</th>
                        <th>کاربر</th>
                        <th>مبلغ</th>
                        <th>وضعیت</th>
                        <th>کد</th>
                        <th>تاریخ</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($audit['rows'])) : ?>
                        <tr class="wwp-empty-row"><td colspan="7">رکوردی یافت نشد.</td></tr>
                    <?php else :
                        foreach ($audit['rows'] as $tx) :
                            $u = get_userdata((int) $tx->user_id);
                            ?>
                            <tr>
                                <td>#<?php echo (int) $tx->id; ?></td>
                                <td>
                                    <?php echo esc_html($u ? $u->display_name : ('#' . (int) $tx->user_id)); ?>
                                    <br><a href="<?php echo esc_url(admin_url('admin.php?page=medyar-wallet-user-insights&user_id=' . (int) $tx->user_id)); ?>">پنل کاربر</a>
                                </td>
                                <td><?php echo wwp_format_amount((float) $tx->amount, 'toman'); ?></td>
                                <td><span class="wallet-badge <?php echo esc_attr((string) $tx->status); ?>"><?php echo wwp_label_status((string) $tx->status); ?></span></td>
                                <td><?php echo esc_html((string) ($tx->transaction_code ?: '-')); ?></td>
                                <td><?php echo esc_html(date_i18n('Y/m/d H:i', strtotime((string) $tx->created_at))); ?></td>
                                <td>
                                    <button type="button" class="button button-small wwp-btn-view" data-tx-id="<?php echo (int) $tx->id; ?>">تراکنش</button>
                                </td>
                            </tr>
                        <?php endforeach;
                    endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
require WALLET_PLUGIN_PATH . 'admin/partials/wwp-transaction-modal.php';
