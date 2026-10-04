<?php
if (!defined('ABSPATH')) {
    exit;
}

$prefix  = MTW_MyAccount_UI::PREFIX;
$amounts = MTW_MyAccount_UI::predefined_topup_amounts();
$cards   = medyar_get_user_bank_cards(get_current_user_id());
$max     = function_exists('medyar_wallet_get_max_deposit') ? medyar_wallet_get_max_deposit() : 500000000;
$loading = MTW_PATH . 'assets/frontend/images/loading.svg';
?>
<div class="<?php echo esc_attr($prefix); ?>topup-form <?php echo esc_attr($prefix); ?>section">
    <div class="<?php echo esc_attr($prefix); ?>topup-title-wrap <?php echo esc_attr($prefix); ?>section-title-wrap">
        <i class="sheyda-wallet-icon-wallet-add"></i>
        <span class="<?php echo esc_attr($prefix); ?>topup-title <?php echo esc_attr($prefix); ?>section-title">افزایش موجودی کیف پول</span>
    </div>

    <?php if (empty($cards)) : ?>
        <p class="<?php echo esc_attr($prefix); ?>withdrawal-financial-error">
            <?php
            printf(
                'برای شارژ کیف پول ابتدا یک کارت بانکی در <a href="%s">اطلاعات مالی</a> ثبت کنید.',
                esc_url(MTW_MyAccount_UI::section_url('financial'))
            );
            ?>
        </p>
    <?php else : ?>
        <div class="<?php echo esc_attr($prefix); ?>withdrawal-field-wrap" style="margin-bottom:1rem;">
            <label for="mtw_topup_card" class="<?php echo esc_attr($prefix); ?>withdrawal-field-label">کارت بانکی</label>
            <select id="mtw_topup_card" name="card_number">
                <?php foreach ($cards as $num => $data) :
                    $digits = preg_replace('/\D/', '', (string) $num);
                    $label  = trim(chunk_split($digits, 4, '-'), '-');
                    $bank   = is_array($data['bank'] ?? null) ? ($data['bank']['fa'] ?? '') : '';
                    if ($bank !== '') {
                        $label .= ' (' . $bank . ')';
                    }
                    ?>
                    <option value="<?php echo esc_attr($digits); ?>"><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <span class="<?php echo esc_attr($prefix); ?>topup-text">مبلغ (تومان):</span>
        <?php if (!empty($amounts)) : ?>
            <div class="<?php echo esc_attr($prefix); ?>topup-predefined-amounts">
                <?php foreach ($amounts as $amount) : ?>
                    <button class="<?php echo esc_attr($prefix); ?>topup-predefined-amount-btn" type="button" data-amount="<?php echo esc_attr((string) $amount); ?>">
                        <?php echo esc_html(MTW_MyAccount_UI::format_price($amount)); ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <div class="<?php echo esc_attr($prefix); ?>topup-amount-field-wrap">
                <div class="<?php echo esc_attr($prefix); ?>topup-amount-field-separator-wrap">
                    <span class="<?php echo esc_attr($prefix); ?>topup-amount-field-separator">یا</span>
                </div>
                <input type="text" id="<?php echo esc_attr($prefix); ?>topup-amount-field" class="sheyda-wallet-price-input ltr" placeholder="مبلغ مورد نظر خود را وارد کنید…" inputmode="numeric" autocomplete="off">
            </div>
        <?php else : ?>
            <div class="<?php echo esc_attr($prefix); ?>topup-amount-field-wrap">
                <input type="text" id="<?php echo esc_attr($prefix); ?>topup-amount-field" class="sheyda-wallet-price-input ltr" placeholder="مبلغ مورد نظر خود را وارد کنید…" inputmode="numeric" autocomplete="off">
            </div>
        <?php endif; ?>

        <p class="<?php echo esc_attr($prefix); ?>withdrawal-note">
            <?php echo esc_html(function_exists('medyar_wallet_deposit_limit_phrase') ? medyar_wallet_deposit_limit_phrase($max) : ('سقف واریز: ' . MTW_MyAccount_UI::format_price($max))); ?>
        </p>

        <button id="<?php echo esc_attr($prefix); ?>topup-submit" class="<?php echo esc_attr($prefix); ?>topup-submit sheyda_wallet_button" type="button">
            <span class="button-text">افزایش موجودی کیف پول</span>
            <div class="button-loading"><?php echo file_exists($loading) ? file_get_contents($loading) : ''; ?></div>
        </button>
        <div class="<?php echo esc_attr($prefix); ?>topup-errors" role="alert" aria-live="assertive"></div>
    <?php endif; ?>
</div>
