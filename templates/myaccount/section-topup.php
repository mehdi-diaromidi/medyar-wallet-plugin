<?php
if (!defined('ABSPATH')) {
    exit;
}

$prefix  = MTW_MyAccount_UI::PREFIX;
$amounts = MTW_MyAccount_UI::predefined_topup_amounts();
$user_id = get_current_user_id();
$cards   = medyar_get_user_bank_cards($user_id);
$balance = class_exists('Wallet_Manager')
    ? (int) Wallet_Manager::get_instance()->get_balance($user_id)
    : 0;
$max     = function_exists('medyar_wallet_get_max_deposit') ? medyar_wallet_get_max_deposit() : 500000000;
$limit_phrase = function_exists('medyar_wallet_deposit_limit_phrase')
    ? medyar_wallet_deposit_limit_phrase($max)
    : ('سقف واریز: ' . MTW_MyAccount_UI::format_price($max));
$loading = MTW_PATH . 'assets/frontend/images/loading.svg';
?>
<div class="<?php echo esc_attr($prefix); ?>topup-form <?php echo esc_attr($prefix); ?>section mtw-topup">
    <div class="mtw-topup__head">
        <div class="<?php echo esc_attr($prefix); ?>topup-title-wrap <?php echo esc_attr($prefix); ?>section-title-wrap">
            <i class="sheyda-wallet-icon-wallet-add" aria-hidden="true"></i>
            <div class="mtw-topup__titles">
                <span class="<?php echo esc_attr($prefix); ?>topup-title <?php echo esc_attr($prefix); ?>section-title">شارژ کیف پول</span>
                <p class="mtw-topup__lead">مبلغ را انتخاب کنید و از درگاه بانکی پرداخت کنید.</p>
            </div>
        </div>
    </div>

    <div class="mtw-topup__facts" aria-label="اطلاعات شارژ">
        <div class="mtw-topup__fact">
            <span class="mtw-topup__fact-label">موجودی فعلی</span>
            <strong class="mtw-topup__fact-value"><?php echo esc_html(MTW_MyAccount_UI::format_price($balance)); ?></strong>
        </div>
        <div class="mtw-topup__fact">
            <span class="mtw-topup__fact-label">سقف واریز</span>
            <strong class="mtw-topup__fact-value"><?php echo esc_html(MTW_MyAccount_UI::format_price($max, false)); ?> تومان</strong>
        </div>
    </div>

    <?php if (empty($cards)) : ?>
        <div class="<?php echo esc_attr($prefix); ?>withdrawal-financial-error mtw-topup__empty-cards">
            <?php
            printf(
                'برای شارژ کیف پول ابتدا یک کارت بانکی در <a href="%s">کارت‌های بانکی</a> ثبت کنید.',
                esc_url(MTW_MyAccount_UI::section_url('financial'))
            );
            ?>
        </div>
    <?php else : ?>
        <section class="mtw-topup__panel" aria-labelledby="mtwTopupCardHeading">
            <div class="mtw-topup__panel-head">
                <h3 class="mtw-topup__panel-title" id="mtwTopupCardHeading">۱. کارت مبدأ</h3>
                <a class="mtw-topup__panel-link" href="<?php echo esc_url(MTW_MyAccount_UI::section_url('financial')); ?>">مدیریت کارت‌ها</a>
            </div>
            <div class="<?php echo esc_attr($prefix); ?>withdrawal-field-wrap mtw-topup__field">
                <label for="mtw_topup_card" class="<?php echo esc_attr($prefix); ?>withdrawal-field-label">پرداخت از کارت</label>
                <span class="mtw-field-control mtw-field-control--select">
                    <select id="mtw_topup_card" name="card_number" class="mtw-select-native" data-mtw-select>
                        <?php foreach ($cards as $num => $data) :
                            $digits  = preg_replace('/\D/', '', (string) $num);
                            $label   = trim(chunk_split($digits, 4, ' '), ' ');
                            $bank_fa = is_array($data['bank'] ?? null) ? (string) ($data['bank']['fa'] ?? '') : '';
                            $bank_en = is_array($data['bank'] ?? null) ? (string) ($data['bank']['en'] ?? '') : '';
                            if ($bank_en === '') {
                                $bank_en = 'bank-logo-default-ws';
                            }
                            if ($bank_fa !== '') {
                                $label = $bank_fa . ' — ' . $label;
                            }
                            ?>
                            <option value="<?php echo esc_attr($digits); ?>" data-bank="<?php echo esc_attr($bank_en); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="mtw-field-control__chevron" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg></span>
                </span>
            </div>
        </section>

        <section class="mtw-topup__panel" aria-labelledby="mtwTopupAmountHeading">
            <div class="mtw-topup__panel-head">
                <h3 class="mtw-topup__panel-title" id="mtwTopupAmountHeading">۲. مبلغ شارژ</h3>
                <span class="mtw-topup__panel-hint">حداقل ۱٬۰۰۰ تومان</span>
            </div>

            <span class="<?php echo esc_attr($prefix); ?>topup-text mtw-topup__sr-only">مبلغ (تومان)</span>

            <?php if (!empty($amounts)) : ?>
                <div class="<?php echo esc_attr($prefix); ?>topup-predefined-amounts mtw-topup__chips" role="group" aria-label="مبالغ پیشنهادی">
                    <?php foreach ($amounts as $amount) : ?>
                        <button class="<?php echo esc_attr($prefix); ?>topup-predefined-amount-btn mtw-topup__chip"
                                type="button"
                                data-amount="<?php echo esc_attr((string) $amount); ?>">
                            <strong><?php echo esc_html(MTW_MyAccount_UI::format_price($amount, false)); ?></strong>
                            <span>تومان</span>
                        </button>
                    <?php endforeach; ?>
                </div>

                <div class="<?php echo esc_attr($prefix); ?>topup-amount-field-wrap mtw-topup__custom">
                    <div class="<?php echo esc_attr($prefix); ?>topup-amount-field-separator-wrap">
                        <span class="<?php echo esc_attr($prefix); ?>topup-amount-field-separator">یا مبلغ دلخواه</span>
                    </div>
                    <label class="mtw-topup__custom-label" for="<?php echo esc_attr($prefix); ?>topup-amount-field">مبلغ دلخواه</label>
                    <div class="mtw-topup__amount-box">
                        <input type="text"
                               id="<?php echo esc_attr($prefix); ?>topup-amount-field"
                               class="sheyda-wallet-price-input ltr mtw-topup__amount-input"
                               placeholder="مثلاً 750000"
                               inputmode="numeric"
                               autocomplete="off"
                               aria-describedby="mtwTopupLimitNote">
                        <span class="mtw-topup__amount-unit" aria-hidden="true">تومان</span>
                    </div>
                </div>
            <?php else : ?>
                <div class="<?php echo esc_attr($prefix); ?>topup-amount-field-wrap mtw-topup__custom">
                    <label class="mtw-topup__custom-label" for="<?php echo esc_attr($prefix); ?>topup-amount-field">مبلغ (تومان)</label>
                    <div class="mtw-topup__amount-box">
                        <input type="text"
                               id="<?php echo esc_attr($prefix); ?>topup-amount-field"
                               class="sheyda-wallet-price-input ltr mtw-topup__amount-input"
                               placeholder="مثلاً 750000"
                               inputmode="numeric"
                               autocomplete="off"
                               aria-describedby="mtwTopupLimitNote">
                        <span class="mtw-topup__amount-unit" aria-hidden="true">تومان</span>
                    </div>
                </div>
            <?php endif; ?>

            <p class="<?php echo esc_attr($prefix); ?>withdrawal-note mtw-topup__limit" id="mtwTopupLimitNote">
                <?php echo esc_html($limit_phrase); ?>
            </p>
        </section>

        <div class="mtw-topup__checkout">
            <div class="mtw-topup__summary">
                <span class="mtw-topup__summary-label">مبلغ قابل پرداخت</span>
                <strong class="mtw-topup__summary-value" id="mtwTopupSummaryAmount">—</strong>
            </div>
            <button id="<?php echo esc_attr($prefix); ?>topup-submit"
                    class="<?php echo esc_attr($prefix); ?>topup-submit sheyda_wallet_button mtw-topup__submit"
                    type="button">
                <span class="button-text">ادامه و پرداخت</span>
                <div class="button-loading"><?php echo file_exists($loading) ? file_get_contents($loading) : ''; ?></div>
            </button>
        </div>

        <div class="<?php echo esc_attr($prefix); ?>topup-errors" role="alert" aria-live="assertive"></div>
    <?php endif; ?>
</div>
