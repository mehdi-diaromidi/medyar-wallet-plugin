<?php
if (!defined('ABSPATH')) {
    exit;
}

$prefix     = MTW_MyAccount_UI::PREFIX;
$user_id    = get_current_user_id();
$balance    = (int) Wallet_Manager::get_instance()->get_balance($user_id);
$min        = MTW_MyAccount_UI::min_withdrawal();
$max        = function_exists('medyar_wallet_get_max_withdraw') ? medyar_wallet_get_max_withdraw() : $balance;
$cards      = medyar_get_user_bank_cards($user_id);
$can_access = $balance >= $min;
$loading    = MTW_PATH . 'assets/frontend/images/loading.svg';
$status_labels = MTW_MyAccount_UI::status_labels();
$tx_manager = Wallet_Transaction_Manager::get_instance();

$single_id = isset($_GET['withdrawal-id']) ? absint($_GET['withdrawal-id']) : 0;

if ($single_id > 0) {
    $withdrawal = $tx_manager->get_transaction($single_id);
    $valid = $withdrawal
        && (int) $withdrawal->user_id === $user_id
        && (string) $withdrawal->operation_type === 'withdraw';
    ?>
    <div class="<?php echo esc_attr($prefix); ?>withdrawal-section <?php echo esc_attr($prefix); ?>section">
        <?php if (!$valid) : ?>
            <div class="<?php echo esc_attr($prefix); ?>empty-section">
                <i class="sheyda-wallet-icon-close-circle <?php echo esc_attr($prefix); ?>empty-section-icon"></i>
                <span class="<?php echo esc_attr($prefix); ?>empty-section-text">خطا در گرفتن اطلاعات درخواست برداشت</span>
            </div>
        <?php else :
            $p_info = [];
            if (!empty($withdrawal->p_info)) {
                $decoded = json_decode((string) $withdrawal->p_info, true);
                if (is_array($decoded)) {
                    $p_info = $decoded;
                }
            }
            $ts_created = !empty($withdrawal->created_at) ? strtotime($withdrawal->created_at) : 0;
            $status     = (string) ($withdrawal->status ?? '');
            ?>
            <div class="<?php echo esc_attr($prefix); ?>withdrawal-single">
                <span class="<?php echo esc_attr($prefix); ?>withdrawal-title <?php echo esc_attr($prefix); ?>section-title">جزئیات برداشت</span>
                <div class="<?php echo esc_attr($prefix); ?>withdrawal-single-details">
                    <div class="<?php echo esc_attr($prefix); ?>withdrawal-single-details-section details-section-1">
                        <div class="<?php echo esc_attr($prefix); ?>withdrawal-single-detail-item">
                            <span class="<?php echo esc_attr($prefix); ?>withdrawal-single-detail-item-title">شناسه</span>
                            <span class="<?php echo esc_attr($prefix); ?>withdrawal-single-detail-item-value"><?php echo esc_html((string) $withdrawal->id); ?></span>
                        </div>
                        <div class="<?php echo esc_attr($prefix); ?>withdrawal-single-detail-item">
                            <span class="<?php echo esc_attr($prefix); ?>withdrawal-single-detail-item-title">وضعیت</span>
                            <span class="<?php echo esc_attr($prefix); ?>withdrawal-single-detail-item-value"><?php echo esc_html($status_labels[$status] ?? $status); ?></span>
                        </div>
                        <div class="<?php echo esc_attr($prefix); ?>withdrawal-single-detail-item">
                            <span class="<?php echo esc_attr($prefix); ?>withdrawal-single-detail-item-title">مبلغ</span>
                            <span class="<?php echo esc_attr($prefix); ?>withdrawal-single-detail-item-value"><?php echo esc_html(MTW_MyAccount_UI::format_price(abs((float) $withdrawal->amount))); ?></span>
                        </div>
                        <div class="<?php echo esc_attr($prefix); ?>withdrawal-single-detail-item">
                            <span class="<?php echo esc_attr($prefix); ?>withdrawal-single-detail-item-title">تاریخ درخواست</span>
                            <span class="<?php echo esc_attr($prefix); ?>withdrawal-single-detail-item-value"><?php echo $ts_created ? esc_html(date_i18n('Y/m/d - H:i', $ts_created)) : '—'; ?></span>
                        </div>
                    </div>
                    <div class="<?php echo esc_attr($prefix); ?>withdrawal-single-details-section details-section-2">
                        <div class="<?php echo esc_attr($prefix); ?>withdrawal-single-detail-item">
                            <span class="<?php echo esc_attr($prefix); ?>withdrawal-single-detail-item-title">اطلاعات بانکی</span>
                            <span class="<?php echo esc_attr($prefix); ?>withdrawal-single-detail-item-value">
                                <?php if (!empty($p_info['card_number'])) : ?>
                                    <span><?php echo esc_html('شماره کارت: ' . $p_info['card_number']); ?></span><br>
                                <?php endif; ?>
                                <?php if (!empty($p_info['iban'])) : ?>
                                    <span><?php echo esc_html('شبا: ' . $p_info['iban']); ?></span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php if (!empty($withdrawal->description)) : ?>
                            <div class="<?php echo esc_attr($prefix); ?>withdrawal-single-detail-item">
                                <span class="<?php echo esc_attr($prefix); ?>withdrawal-single-detail-item-title">توضیحات</span>
                                <span class="<?php echo esc_attr($prefix); ?>withdrawal-single-detail-item-value"><?php echo wp_kses_post(wpautop((string) $withdrawal->description)); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <a href="<?php echo esc_url(remove_query_arg('withdrawal-id')); ?>" class="sheyda_wallet_button button button-small <?php echo esc_attr($prefix); ?>withdrawal-single-back">بازگشت</a>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return;
}

$page   = isset($_GET['w-page']) ? max(1, absint($_GET['w-page'])) : 1;
$ppp    = 10;
$filter = ['balance_type' => 'toman', 'operation_type' => 'withdraw'];
$total  = $tx_manager->count_user_transactions($user_id, $filter);
$withdrawals = $tx_manager->get_user_transactions($user_id, array_merge($filter, [
    'limit'  => $ppp,
    'offset' => ($page - 1) * $ppp,
]));

?>
<div class="<?php echo esc_attr($prefix); ?>withdrawal-section <?php echo esc_attr($prefix); ?>section">
    <div class="<?php echo esc_attr($prefix); ?>withdrawal-title-wrap <?php echo esc_attr($prefix); ?>section-title-wrap">
        <i class="sheyda-wallet-icon-wallet-minus"></i>
        <span class="<?php echo esc_attr($prefix); ?>withdrawal-title <?php echo esc_attr($prefix); ?>section-title">درخواست برداشت</span>
    </div>

    <p id="mtw_withdrawal_ajax_status" class="<?php echo esc_attr($prefix); ?>withdrawal-note" style="display:none;" role="status" aria-live="polite"></p>

    <div class="<?php echo esc_attr($prefix); ?>withdrawal-notes">
        <p class="<?php echo esc_attr($prefix); ?>withdrawal-note withdrawable-credit">
            <?php printf('موجودی قابل برداشت: %s.', esc_html(MTW_MyAccount_UI::format_price($balance))); ?>
        </p>
        <?php if (!$can_access) : ?>
            <p class="<?php echo esc_attr($prefix); ?>withdrawal-note note-error">شما موجودی کافی برای برداشت ندارید.</p>
            <p class="<?php echo esc_attr($prefix); ?>withdrawal-note">
                <?php printf('حداقل مبلغ درخواست برداشت %s است.', esc_html(MTW_MyAccount_UI::format_price($min))); ?>
            </p>
        <?php endif; ?>
    </div>

    <?php if ($can_access) : ?>
        <form action="#" method="post" class="<?php echo esc_attr($prefix); ?>withdrawal-request-form" id="<?php echo esc_attr($prefix); ?>withdrawal-request-form" novalidate>
            <div class="<?php echo esc_attr($prefix); ?>withdrawal-field-wrap">
                <label for="<?php echo esc_attr($prefix); ?>withdrawal-amount-field" class="<?php echo esc_attr($prefix); ?>withdrawal-field-label">مبلغ (تومان)</label>
                <input type="text" id="<?php echo esc_attr($prefix); ?>withdrawal-amount-field" class="sheyda-wallet-price-input ltr <?php echo esc_attr($prefix); ?>withdrawal-field" name="amount" data-min="<?php echo esc_attr((string) $min); ?>" data-max="<?php echo esc_attr((string) min($balance, $max)); ?>" inputmode="numeric" autocomplete="off">
                <div class="<?php echo esc_attr($prefix); ?>withdrawal-errors"></div>
            </div>
            <div class="<?php echo esc_attr($prefix); ?>withdrawal-field-wrap">
                <label for="<?php echo esc_attr($prefix); ?>withdrawal-destination-field" class="<?php echo esc_attr($prefix); ?>withdrawal-field-label">حساب مقصد</label>
                <?php if (!empty($cards)) : ?>
                    <select id="<?php echo esc_attr($prefix); ?>withdrawal-destination-field" name="card_number">
                        <?php foreach ($cards as $num => $data) :
                            $digits = preg_replace('/\D/', '', (string) $num);
                            $label  = trim(chunk_split($digits, 4, '-'), '-');
                            $owner  = (string) ($data['owner'] ?? '');
                            if ($owner !== '') {
                                $label .= ' (' . $owner . ')';
                            }
                            ?>
                            <option value="<?php echo esc_attr($digits); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else : ?>
                    <p class="<?php echo esc_attr($prefix); ?>withdrawal-financial-error">
                        <?php
                        printf(
                            'شما هیچ حساب بانکی‌ای ثبت نکرده‌اید. لطفاً یک حساب از بخش <a href="%s">اطلاعات مالی</a> اضافه کنید.',
                            esc_url(MTW_MyAccount_UI::section_url('financial'))
                        );
                        ?>
                    </p>
                <?php endif; ?>
            </div>
            <p class="<?php echo esc_attr($prefix); ?>withdrawal-note">
                <?php printf('حداقل مبلغ درخواست برداشت %s است.', esc_html(MTW_MyAccount_UI::format_price($min))); ?>
            </p>
            <?php if (!empty($cards)) : ?>
                <button id="<?php echo esc_attr($prefix); ?>withdrawal-submit" class="<?php echo esc_attr($prefix); ?>withdrawal-submit sheyda_wallet_button" type="submit">
                    <span class="button-text">ثبت</span>
                    <div class="button-loading"><?php echo file_exists($loading) ? file_get_contents($loading) : ''; ?></div>
                </button>
            <?php endif; ?>
        </form>
    <?php endif; ?>
</div>

<div class="<?php echo esc_attr($prefix); ?>withdrawals <?php echo esc_attr($prefix); ?>section">
    <?php if (!empty($withdrawals)) : ?>
        <div class="<?php echo esc_attr($prefix); ?>withdrawals-list">
            <div class="<?php echo esc_attr($prefix); ?>withdrawals-head">
                <span class="<?php echo esc_attr($prefix); ?>withdrawals-head-item">شناسه</span>
                <span class="<?php echo esc_attr($prefix); ?>withdrawals-head-item">مبلغ</span>
                <span class="<?php echo esc_attr($prefix); ?>withdrawals-head-item">وضعیت</span>
                <span class="<?php echo esc_attr($prefix); ?>withdrawals-head-item">تاریخ</span>
                <span class="<?php echo esc_attr($prefix); ?>withdrawals-head-item">عملیات‌ها</span>
            </div>
            <div class="<?php echo esc_attr($prefix); ?>withdrawals-items">
                <?php foreach ($withdrawals as $withdrawal) :
                    $status = (string) ($withdrawal->status ?? '');
                    $ts     = !empty($withdrawal->created_at) ? strtotime($withdrawal->created_at) : 0;
                    ?>
                    <div class="<?php echo esc_attr($prefix); ?>withdrawal">
                        <span class="<?php echo esc_attr($prefix); ?>withdrawal-id <?php echo esc_attr($prefix); ?>withdrawal-item-cell">
                            <span class="<?php echo esc_attr($prefix); ?>withdrawal-item-title">شناسه</span>
                            <span class="<?php echo esc_attr($prefix); ?>withdrawal-item-value">#<?php echo esc_html((string) $withdrawal->id); ?></span>
                        </span>
                        <span class="<?php echo esc_attr($prefix); ?>withdrawal-amount <?php echo esc_attr($prefix); ?>withdrawal-item-cell">
                            <span class="<?php echo esc_attr($prefix); ?>withdrawal-item-title">مبلغ</span>
                            <span class="<?php echo esc_attr($prefix); ?>withdrawal-item-value"><?php echo esc_html(MTW_MyAccount_UI::format_price(abs((float) $withdrawal->amount))); ?></span>
                        </span>
                        <span class="<?php echo esc_attr($prefix); ?>withdrawal-status <?php echo esc_attr($prefix); ?>withdrawal-item-cell">
                            <span class="<?php echo esc_attr($prefix); ?>withdrawal-item-title">وضعیت</span>
                            <span class="<?php echo esc_attr($prefix); ?>withdrawal-item-value"><?php echo esc_html($status_labels[$status] ?? $status); ?></span>
                        </span>
                        <span class="<?php echo esc_attr($prefix); ?>withdrawal-date <?php echo esc_attr($prefix); ?>withdrawal-item-cell">
                            <span class="<?php echo esc_attr($prefix); ?>withdrawal-item-title">تاریخ</span>
                            <span class="<?php echo esc_attr($prefix); ?>withdrawal-item-value">
                                <?php if ($ts) : ?>
                                    <span><?php echo esc_html(date_i18n('j F Y', $ts)); ?></span>
                                    <small><?php echo esc_html(date_i18n('H:i', $ts)); ?></small>
                                <?php endif; ?>
                            </span>
                        </span>
                        <span class="<?php echo esc_attr($prefix); ?>withdrawal-action <?php echo esc_attr($prefix); ?>withdrawal-item-cell">
                            <a href="<?php echo esc_url(add_query_arg(['withdrawal-id' => (int) $withdrawal->id])); ?>" class="sheyda_wallet_button button-action button-small button-fullwidth">مشاهده</a>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        require_once MTW_PATH . 'templates/myaccount/pagination.php';
        mtw_myaccount_pagination([
            'query_arg_name' => 'w-page',
            'max_num_pages'  => (int) ceil($total / $ppp),
            'paged'          => $page,
        ]);
        ?>
    <?php else : ?>
        <div class="<?php echo esc_attr($prefix); ?>empty-section">
            <i class="sheyda-wallet-icon-document-text <?php echo esc_attr($prefix); ?>empty-section-icon"></i>
            <span class="<?php echo esc_attr($prefix); ?>empty-section-text">هیچ درخواست برداشتی ثبت نشده است.</span>
        </div>
    <?php endif; ?>
</div>
