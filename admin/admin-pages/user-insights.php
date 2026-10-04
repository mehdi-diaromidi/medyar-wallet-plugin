<?php
/**
 * پنل خلاصه موجودی تومان و تراکنش‌های یک کاربر.
 */
if (!defined('ABSPATH')) {
    exit;
}

require_once WALLET_PLUGIN_PATH . 'admin/admin-helpers.php';
require_once WALLET_PLUGIN_PATH . 'admin/admin-helpers-tx-modal-data.php';
require_once WALLET_PLUGIN_PATH . 'admin/admin-list-query.php';

global $wpdb;

$panel_uid = isset($_GET['user_id']) ? max(0, intval($_GET['user_id'])) : 0;
$users_url = admin_url('users.php');

if ($panel_uid <= 0) {
    wp_safe_redirect($users_url);
    exit;
}

if (isset($_GET['wwp_updated'])) {
    echo '<div class="notice notice-success is-dismissible"><p>تراکنش با موفقیت به‌روزرسانی شد.</p></div>';
}
if (isset($_GET['wwp_error'])) {
    echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(urldecode((string) $_GET['wwp_error'])) . '</p></div>';
}

$panel_user = get_user_by('id', $panel_uid);
if (!$panel_user) {
    echo '<div class="wrap wallet-admin-wrap"><div class="notice notice-error"><p>کاربر یافت نشد.</p></div>';
    echo '<p><a class="button" href="' . esc_url($users_url) . '">بازگشت به کاربران</a></p></div>';
    return;
}

$wallet_mgr = Wallet_Manager::get_instance();
$toman_bal  = $wallet_mgr->get_balance($panel_uid);

$tx_per_page = 50;
$tx_page     = max(1, intval($_GET['tx_paged'] ?? 1));
$tx_offset   = ($tx_page - 1) * $tx_per_page;
$tx_where    = $wpdb->prepare('user_id = %d', $panel_uid);
$transactions = $wpdb->get_results(
    "SELECT * FROM {$wpdb->prefix}wallet_transactions
     WHERE {$tx_where}
     ORDER BY created_at DESC
     LIMIT {$tx_per_page} OFFSET {$tx_offset}"
);
$total_tx = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->prefix}wallet_transactions WHERE {$tx_where}"
);

$tx_js_data                 = wwp_build_transaction_modal_js_data($transactions);
$wwp_modal_redirect_page    = 'medyar-wallet-user-insights';
$wwp_modal_redirect_user_id = $panel_uid;

$cards = function_exists('medyar_get_user_bank_cards') ? medyar_get_user_bank_cards($panel_uid) : [];
?>
<div class="wrap wallet-admin-wrap">
    <h1>پنل کیف پول — <?php echo esc_html($panel_user->display_name); ?> (#<?php echo (int) $panel_uid; ?>)</h1>
    <p><a class="button" href="<?php echo esc_url($users_url); ?>">بازگشت به کاربران</a></p>

    <div class="wallet-stats-grid" style="margin:16px 0;">
        <div class="wallet-stat-card">
            <h3>موجودی تومان</h3>
            <p style="font-size:1.4em;font-weight:700;"><?php echo esc_html(number_format_i18n((float) $toman_bal)); ?></p>
        </div>
        <div class="wallet-stat-card">
            <h3>تعداد کارت بانکی</h3>
            <p style="font-size:1.4em;font-weight:700;"><?php echo (int) count($cards); ?></p>
        </div>
    </div>

    <details class="wwp-user-panel-details" open id="wwp-user-panel-txs">
        <summary>
            <span>تراکنش‌ها — <?php echo number_format_i18n($total_tx); ?> مورد</span>
        </summary>
        <div class="wwp-details-body">
            <div class="wwp-details-inner wwp-admin-page">
                <?php
                wwp_admin_filter_bar_open('panel_txs', 'medyar-wallet-user-insights', [
                    'status', 'operation_type', 'date_from', 'date_to',
                ], [
                    'title' => 'فیلتر تراکنش‌های این کاربر',
                    'per_page' => 50,
                    'storage_suffix' => 'panel-tx-' . $panel_uid,
                    'extra_fields_html' => '<input type="hidden" name="user_id" value="' . (int) $panel_uid . '">',
                ]);
                ?>
                <div class="wwp-table-scroll">
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>عملیات</th>
                                <th>مبلغ</th>
                                <th>وضعیت</th>
                                <th>تاریخ</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody data-wwp-list-body>
                            <?php
                            echo wwp_admin_render_tx_rows_html($transactions, [
                                'show_view' => true,
                                'hide_user' => true,
                                'redirect_page' => 'medyar-wallet-user-insights',
                            ]);
                            ?>
                        </tbody>
                    </table>
                </div>
                <div data-wwp-list-pager>
                    <?php echo wwp_admin_render_pagination_html($total_tx, $tx_page, $tx_per_page, 'panel_txs'); ?>
                </div>
            </div>
        </div>
    </details>
</div>
<?php
require WALLET_PLUGIN_PATH . 'admin/partials/wwp-transaction-modal.php';
