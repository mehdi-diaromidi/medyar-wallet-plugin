<?php
/**
 * Admin Dashboard — Toman wallet
 */
if (!defined('ABSPATH')) {
    exit;
}
require_once WALLET_PLUGIN_PATH . 'admin/admin-helpers.php';
require_once WALLET_PLUGIN_PATH . 'admin/admin-helpers-tx-modal-data.php';
require_once WALLET_PLUGIN_PATH . 'admin/admin-list-query.php';

$tm = Wallet_Transaction_Manager::get_instance();
global $wpdb;

$users_with_balance = (int) $wpdb->get_var(
    "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta}
     WHERE meta_key='wallet_toman_balance' AND CAST(meta_value AS DECIMAL(20,3)) > 0"
);
$total_toman = (float) $wpdb->get_var(
    "SELECT SUM(CAST(meta_value AS DECIMAL(20,3))) FROM {$wpdb->usermeta} WHERE meta_key='wallet_toman_balance'"
);

$pending_order = (isset($_GET['pending_order']) && strtoupper((string) $_GET['pending_order']) === 'DESC')
    ? 'DESC'
    : 'ASC';
$processing_txs = $tm->get_all_transactions([
    'status' => 'processing',
    'limit'  => 100,
    'order'  => $pending_order,
]);

$recent_txs = $wpdb->get_results(
    "SELECT * FROM {$wpdb->prefix}wallet_transactions
     WHERE balance_type = 'toman'
     ORDER BY created_at DESC
     LIMIT 20"
);

if (isset($_GET['wwp_updated'])) {
    echo '<div class="notice notice-success is-dismissible"><p>تراکنش با موفقیت به‌روزرسانی شد.</p></div>';
}
if (isset($_GET['wwp_error'])) {
    echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(urldecode((string) $_GET['wwp_error'])) . '</p></div>';
}
if (class_exists('Wallet_SMS') && !Wallet_SMS::is_sms_enabled()) {
    echo '<div class="notice notice-warning"><p><strong>توجه:</strong> ارسال پیامک غیرفعال است. ';
    echo '<a href="' . esc_url(admin_url('admin.php?page=medyar-wallet-settings')) . '">تنظیمات</a></p></div>';
}

$tx_js_data = wwp_build_transaction_modal_js_data(array_merge((array) $processing_txs, (array) $recent_txs));
$wwp_modal_redirect_page = 'medyar-wallet';
$wwp_modal_redirect_user_id = 0;
?>
<div class="wrap wallet-admin-wrap">
    <h1>کیف پول مدیار — داشبورد</h1>

    <div class="wallet-stats-grid">
        <div class="wallet-stat-card">
            <h3>کاربران دارای موجودی</h3>
            <p><?php echo esc_html(number_format_i18n($users_with_balance)); ?></p>
        </div>
        <div class="wallet-stat-card">
            <h3>مجموع موجودی تومان</h3>
            <p><?php echo esc_html(number_format_i18n($total_toman)); ?></p>
        </div>
        <div class="wallet-stat-card">
            <h3>در انتظار بررسی</h3>
            <p><?php echo esc_html(number_format_i18n(is_array($processing_txs) ? count($processing_txs) : 0)); ?></p>
        </div>
    </div>

    <h2>تراکنش‌های در حال پردازش</h2>
    <div class="wwp-table-scroll">
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>کاربر</th>
                    <th>عملیات</th>
                    <th>مبلغ</th>
                    <th>وضعیت</th>
                    <th>تاریخ</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php
                if (empty($processing_txs)) {
                    echo '<tr><td colspan="7">موردی نیست.</td></tr>';
                } else {
                    echo wwp_admin_render_tx_rows_html($processing_txs, [
                        'show_view' => true,
                        'redirect_page' => 'medyar-wallet',
                    ]);
                }
                ?>
            </tbody>
        </table>
    </div>

    <h2 style="margin-top:24px;">تراکنش‌های اخیر تومان
        <a href="<?php echo esc_url(admin_url('admin.php?page=medyar-wallet-recent-transactions')); ?>" class="button" style="float:left;">مشاهده همه</a>
    </h2>
    <div class="wwp-table-scroll">
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>کاربر</th>
                    <th>عملیات</th>
                    <th>مبلغ</th>
                    <th>وضعیت</th>
                    <th>تاریخ</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php
                if (empty($recent_txs)) {
                    echo '<tr><td colspan="7">موردی نیست.</td></tr>';
                } else {
                    echo wwp_admin_render_tx_rows_html($recent_txs, [
                        'show_view' => true,
                        'redirect_page' => 'medyar-wallet',
                    ]);
                }
                ?>
            </tbody>
        </table>
    </div>
</div>
<?php
require WALLET_PLUGIN_PATH . 'admin/partials/wwp-transaction-modal.php';
