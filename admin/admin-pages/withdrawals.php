<?php

/**
 * Withdraw Requests Page — pending/history tabs, AJAX, bulk, CSV
 */
if (!defined('ABSPATH')) {
    exit;
}
require_once WALLET_PLUGIN_PATH . 'admin/admin-helpers.php';
require_once WALLET_PLUGIN_PATH . 'admin/admin-list-query.php';

$args = wwp_admin_list_parse_args($_GET);
if (empty($args['list_mode'])) {
    $args['list_mode'] = 'pending';
}
$args['per_page'] = 50;
$result = wwp_admin_query_transactions($args, 'withdraw');
$rows = $result['rows'];
$total = $result['total'];
$page = (int) $args['paged'];
$pending_mode = ($args['list_mode'] === 'pending');

if (isset($_GET['wwp_updated'])) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(is_string($_GET['wwp_updated']) && $_GET['wwp_updated'] !== '1' ? urldecode((string) $_GET['wwp_updated']) : 'وضعیت برداشت با موفقیت به‌روزرسانی شد.') . '</p></div>';
if (isset($_GET['wwp_error']))   echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(urldecode($_GET['wwp_error'])) . '</p></div>';
?>
<div class="wrap wallet-admin-wrap">
    <h1>درخواست‌های برداشت</h1>

    <div class="wallet-stats-grid wallet-stats-grid--auto">
        <div class="wallet-stat-card stat-warning">
            <h3>نتیجه فیلتر فعلی</h3>
            <div class="stat-amount"><?php echo number_format_i18n($total); ?></div>
        </div>
    </div>

    <?php
    $toolbar = '<button type="button" class="button button-primary" data-wwp-bulk="withdraw-approve">تایید انتخاب‌شده‌ها</button>'
        . '<button type="button" class="button wwp-btn-danger" data-wwp-bulk="withdraw-cancel">لغو انتخاب‌شده‌ها</button>';
    wwp_admin_filter_bar_open('withdrawals', 'medyar-wallet-withdrawals', [
        'list_mode', 'user_q', 'status', 'date_from', 'date_to', 'amount_min', 'amount_max', 'card_iban', 'tx_q',
    ], [
        'title' => 'فیلتر برداشت‌ها',
        'export' => true,
        'per_page' => 50,
        'storage_suffix' => 'withdraw',
        'toolbar_html' => '<span class="wwp-bulk-toolbar">' . $toolbar . '</span>',
    ]);
    ?>

    <div class="wallet-card">
        <h2><?php echo $pending_mode ? 'برداشت‌های در انتظار' : 'تاریخچه برداشت‌ها'; ?> (<?php echo number_format_i18n($total); ?>)</h2>
        <p class="wwp-bulk-toolbar">
            <label><input type="checkbox" class="wwp-check-all"> انتخاب همه</label>
        </p>
        <div class="wwp-table-scroll">
            <table class="wallet-table wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th></th>
                        <?php
                        wwp_admin_sortable_th('شناسه', 'id');
                        echo '<th>کاربر</th>';
                        wwp_admin_sortable_th('میزان', 'amount');
                        echo '<th>شماره کارت</th><th>شماره شبا</th>';
                        wwp_admin_sortable_th('وضعیت', 'status');
                        echo '<th>کد مرجع</th>';
                        echo '<th class="col-description">توضیحات</th>';
                        wwp_admin_sortable_th('تاریخ', 'created_at');
                        echo '<th>عملیات</th>';
                        ?>
                    </tr>
                </thead>
                <tbody data-wwp-list-body>
                    <?php
                    echo wwp_admin_render_tx_rows_html($rows, [
                        'show_checkbox' => $pending_mode,
                        'show_approve_cancel' => $pending_mode,
                        'withdraw_mode' => true,
                        'redirect_page' => 'medyar-wallet-withdrawals',
                    ]);
                    ?>
                </tbody>
            </table>
        </div>
        <div data-wwp-list-pager>
            <?php echo wwp_admin_render_pagination_html($total, $page, (int) $args['per_page'], 'withdrawals'); ?>
        </div>
    </div>
</div>
