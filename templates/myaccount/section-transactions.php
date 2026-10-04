<?php
if (!defined('ABSPATH')) {
    exit;
}

$prefix     = MTW_MyAccount_UI::PREFIX;
$user_id    = get_current_user_id();
$page       = isset($_GET['t-page']) ? max(1, absint($_GET['t-page'])) : 1;
$ppp        = 10;
$tx_manager = Wallet_Transaction_Manager::get_instance();
$filters    = ['balance_type' => 'toman'];
$total      = $tx_manager->count_user_transactions($user_id, $filters);
$transactions = $tx_manager->get_user_transactions($user_id, array_merge($filters, [
    'limit'  => $ppp,
    'offset' => ($page - 1) * $ppp,
]));
$op_labels = MTW_MyAccount_UI::operation_labels();
?>
<div class="<?php echo esc_attr($prefix); ?>transactions <?php echo esc_attr($prefix); ?>section">
    <?php if (!empty($transactions)) : ?>
        <div class="<?php echo esc_attr($prefix); ?>transactions-list">
            <div class="<?php echo esc_attr($prefix); ?>transactions-head">
                <span class="<?php echo esc_attr($prefix); ?>transactions-head-item">شناسه</span>
                <span class="<?php echo esc_attr($prefix); ?>transactions-head-item">نوع</span>
                <span class="<?php echo esc_attr($prefix); ?>transactions-head-item">مبلغ</span>
                <span class="<?php echo esc_attr($prefix); ?>transactions-head-item">تاریخ</span>
                <span class="<?php echo esc_attr($prefix); ?>transactions-head-item">توضیحات</span>
            </div>
            <div class="<?php echo esc_attr($prefix); ?>transactions-items">
                <?php foreach ($transactions as $transaction) :
                    $op   = (string) ($transaction->operation_type ?? '');
                    $ts   = !empty($transaction->created_at) ? strtotime($transaction->created_at) : 0;
                    $desc = (string) ($transaction->description ?? '');
                    ?>
                    <div class="<?php echo esc_attr($prefix); ?>transaction">
                        <span class="<?php echo esc_attr($prefix); ?>transaction-id <?php echo esc_attr($prefix); ?>transaction-item-cell">
                            <span class="<?php echo esc_attr($prefix); ?>transaction-item-title">شناسه</span>
                            <span class="<?php echo esc_attr($prefix); ?>transaction-item-value">#<?php echo esc_html((string) $transaction->id); ?></span>
                        </span>
                        <span class="<?php echo esc_attr($prefix); ?>transaction-type <?php echo esc_attr($prefix); ?>transaction-item-cell">
                            <span class="<?php echo esc_attr($prefix); ?>transaction-item-title">نوع</span>
                            <span class="<?php echo esc_attr($prefix); ?>transaction-item-value"><?php echo esc_html($op_labels[$op] ?? $op); ?></span>
                        </span>
                        <span class="<?php echo esc_attr($prefix); ?>transaction-amount <?php echo esc_attr($prefix); ?>transaction-item-cell">
                            <span class="<?php echo esc_attr($prefix); ?>transaction-item-title">مبلغ</span>
                            <span class="<?php echo esc_attr($prefix); ?>transaction-item-value"><?php echo esc_html(MTW_MyAccount_UI::format_price(abs((float) $transaction->amount))); ?></span>
                        </span>
                        <span class="<?php echo esc_attr($prefix); ?>transaction-created-date <?php echo esc_attr($prefix); ?>transaction-item-cell">
                            <span class="<?php echo esc_attr($prefix); ?>transaction-item-title">تاریخ ایجاد</span>
                            <span class="<?php echo esc_attr($prefix); ?>transaction-item-value">
                                <?php if ($ts) : ?>
                                    <span><?php echo esc_html(date_i18n('j F Y', $ts)); ?></span>
                                    <small><?php echo esc_html(date_i18n('H:i', $ts)); ?></small>
                                <?php endif; ?>
                            </span>
                        </span>
                        <?php if ($desc !== '') : ?>
                            <span class="<?php echo esc_attr($prefix); ?>transaction-description"><?php echo wp_kses_post(wpautop($desc)); ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        require_once MTW_PATH . 'templates/myaccount/pagination.php';
        mtw_myaccount_pagination([
            'query_arg_name' => 't-page',
            'max_num_pages'  => (int) ceil($total / $ppp),
            'paged'          => $page,
        ]);
        ?>
    <?php else : ?>
        <div class="<?php echo esc_attr($prefix); ?>empty-section">
            <i class="sheyda-wallet-icon-document-text <?php echo esc_attr($prefix); ?>empty-section-icon"></i>
            <span class="<?php echo esc_attr($prefix); ?>empty-section-text">هیچ تراکنشی یافت نشد.</span>
        </div>
    <?php endif; ?>
</div>
