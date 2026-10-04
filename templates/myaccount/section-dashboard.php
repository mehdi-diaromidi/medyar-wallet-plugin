<?php
if (!defined('ABSPATH')) {
    exit;
}

$prefix     = MTW_MyAccount_UI::PREFIX;
$user_id    = get_current_user_id();
$balance    = (int) Wallet_Manager::get_instance()->get_balance($user_id);
$tx_manager = Wallet_Transaction_Manager::get_instance();
$transactions = $tx_manager->get_user_transactions($user_id, [
    'balance_type' => 'toman',
    'limit'        => 5,
    'offset'       => 0,
]);
$op_labels = MTW_MyAccount_UI::operation_labels();
?>
<div class="<?php echo esc_attr($prefix); ?>dashboard <?php echo esc_attr($prefix); ?>section mtw-dash">
    <div class="<?php echo esc_attr($prefix); ?>dashboard-info mtw-dash__hero-row">
        <div class="<?php echo esc_attr($prefix); ?>dashboard-info-inner mtw-dash__balance">
            <div class="mtw-dash__balance-copy">
                <span class="<?php echo esc_attr($prefix); ?>dashboard-balance-label">موجودی کیف پول شما</span>
                <span class="<?php echo esc_attr($prefix); ?>dashboard-balance-value"><?php echo esc_html(MTW_MyAccount_UI::format_price($balance)); ?></span>
            </div>
            <i class="sheyda-wallet-icon-coin mtw-dash__balance-icon" aria-hidden="true"></i>
        </div>
        <div class="<?php echo esc_attr($prefix); ?>dashboard-quick-access-wrap mtw-dash__actions">
            <a href="<?php echo esc_url(MTW_MyAccount_UI::section_url('topup')); ?>" class="<?php echo esc_attr($prefix); ?>dashboard-quick-access-btn mtw-dash__action mtw-dash__action--topup">
                <i class="sheyda-wallet-icon-wallet-add"></i>
                <span class="mtw-dash__action-label">
                    <strong>افزایش موجودی</strong>
                    <em>شارژ سریع کیف پول</em>
                </span>
            </a>
            <a href="<?php echo esc_url(MTW_MyAccount_UI::section_url('withdrawal')); ?>" class="<?php echo esc_attr($prefix); ?>dashboard-quick-access-btn mtw-dash__action mtw-dash__action--withdraw">
                <i class="sheyda-wallet-icon-wallet-minus"></i>
                <span class="mtw-dash__action-label">
                    <strong>درخواست برداشت</strong>
                    <em>انتقال به کارت بانکی</em>
                </span>
            </a>
        </div>
    </div>

    <div class="<?php echo esc_attr($prefix); ?>dashboard-transactions-list mtw-dash__recent">
        <div class="<?php echo esc_attr($prefix); ?>dashboard-transactions-head">
            <span class="<?php echo esc_attr($prefix); ?>dashboard-transactions-title">آخرین تراکنش‌ها</span>
            <a href="<?php echo esc_url(MTW_MyAccount_UI::section_url('transactions')); ?>" class="<?php echo esc_attr($prefix); ?>dashboard-all-transactions-link">
                <span>مشاهده همه</span>
                <i class="sheyda-wallet-icon-<?php echo is_rtl() ? 'left' : 'right'; ?>"></i>
            </a>
        </div>

        <?php if (!empty($transactions)) : ?>
            <div class="<?php echo esc_attr($prefix); ?>transactions-items mtw-dash__tx-list">
                <?php foreach ($transactions as $transaction) : ?>
                    <?php
                    $op   = (string) ($transaction->operation_type ?? '');
                    $ts   = !empty($transaction->created_at) ? strtotime($transaction->created_at) : 0;
                    $desc = (string) ($transaction->description ?? '');
                    $amt  = (float) ($transaction->amount ?? 0);
                    $is_out = $amt < 0 || in_array($op, ['withdraw', 'purchase', 'payment'], true);
                    ?>
                    <div class="<?php echo esc_attr($prefix); ?>transaction mtw-dash__tx <?php echo $is_out ? 'is-out' : 'is-in'; ?>">
                        <span class="mtw-dash__tx-kind" aria-hidden="true"><?php echo $is_out ? '−' : '+'; ?></span>
                        <span class="<?php echo esc_attr($prefix); ?>transaction-type <?php echo esc_attr($prefix); ?>transaction-item-cell mtw-dash__tx-main">
                            <span class="<?php echo esc_attr($prefix); ?>transaction-item-title">نوع</span>
                            <span class="<?php echo esc_attr($prefix); ?>transaction-item-value"><?php echo esc_html($op_labels[$op] ?? $op); ?></span>
                            <?php if ($desc !== '') : ?>
                                <span class="<?php echo esc_attr($prefix); ?>transaction-description mtw-dash__tx-desc"><?php echo esc_html(wp_strip_all_tags($desc)); ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="<?php echo esc_attr($prefix); ?>transaction-amount <?php echo esc_attr($prefix); ?>transaction-item-cell mtw-dash__tx-amount">
                            <span class="<?php echo esc_attr($prefix); ?>transaction-item-title">مبلغ</span>
                            <span class="<?php echo esc_attr($prefix); ?>transaction-item-value"><?php echo esc_html(MTW_MyAccount_UI::format_price(abs($amt))); ?></span>
                        </span>
                        <span class="<?php echo esc_attr($prefix); ?>transaction-created-date <?php echo esc_attr($prefix); ?>transaction-item-cell mtw-dash__tx-date">
                            <span class="<?php echo esc_attr($prefix); ?>transaction-item-title">تاریخ ایجاد</span>
                            <span class="<?php echo esc_attr($prefix); ?>transaction-item-value">
                                <?php if ($ts) : ?>
                                    <span><?php echo esc_html(date_i18n('j F Y', $ts)); ?></span>
                                    <small><?php echo esc_html(date_i18n('H:i', $ts)); ?></small>
                                <?php endif; ?>
                            </span>
                        </span>
                        <span class="<?php echo esc_attr($prefix); ?>transaction-id <?php echo esc_attr($prefix); ?>transaction-item-cell mtw-dash__tx-id">
                            <span class="<?php echo esc_attr($prefix); ?>transaction-item-title">شناسه</span>
                            <span class="<?php echo esc_attr($prefix); ?>transaction-item-value">#<?php echo esc_html((string) $transaction->id); ?></span>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <div class="<?php echo esc_attr($prefix); ?>empty-section mtw-dash__empty">
                <span class="<?php echo esc_attr($prefix); ?>empty-section-text">هنوز تراکنشی ثبت نشده است.</span>
            </div>
        <?php endif; ?>
    </div>
</div>
