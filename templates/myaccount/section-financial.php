<?php
if (!defined('ABSPATH')) {
    exit;
}

$prefix  = MTW_MyAccount_UI::PREFIX;
$user_id = get_current_user_id();
$cards   = function_exists('medyar_get_user_bank_cards') ? medyar_get_user_bank_cards($user_id) : [];
$max     = function_exists('medyar_bank_card_max_count') ? medyar_bank_card_max_count() : 3;
$has_cards = !empty($cards);
?>
<div class="<?php echo esc_attr($prefix); ?>section mtw-bank-cards-section mtw-vault">
    <div class="mtw-vault__intro">
        <div class="mtw-vault__intro-copy">
            <p class="mtw-vault__eyebrow">کیف پول مدیار</p>
            <h3 class="mtw-vault__heading">کارت‌های بانکی شما</h3>
            <p class="mtw-vault__lead">
                کارت‌ها فقط برای شارژ و برداشت کیف پول استفاده می‌شوند.
                می‌توانید تا <?php echo esc_html((string) $max); ?> کارت نگه دارید.
            </p>
        </div>
        <div class="mtw-vault__meter" aria-hidden="true">
            <span class="mtw-vault__meter-value" id="mtwCardCountLabel"><?php echo esc_html((string) count((array) $cards)); ?></span>
            <span class="mtw-vault__meter-sep">/</span>
            <span class="mtw-vault__meter-max"><?php echo esc_html((string) $max); ?></span>
            <span class="mtw-vault__meter-caption">کارت</span>
        </div>
    </div>

    <div class="mtw-vault__grid" id="bankCardsContainer">
        <section class="mtw-composer <?php echo $has_cards ? '' : 'is-open'; ?>" id="mtwAddCardComposer" aria-labelledby="mtwComposerTitle">
            <div class="mtw-composer__toolbar">
                <h4 class="mtw-composer__title" id="mtwComposerTitle">ثبت کارت جدید</h4>
                <button class="mtw-composer__toggle add-card-btn" id="addCardBtn" type="button"
                        aria-expanded="<?php echo $has_cards ? 'false' : 'true'; ?>"
                        aria-controls="mtwComposerBody"
                        aria-label="افزودن کارت بانکی جدید">
                    <span class="mtw-composer__toggle-open">+ افزودن کارت</span>
                    <span class="mtw-composer__toggle-close">بستن فرم</span>
                </button>
            </div>

            <div class="mtw-composer__body" id="mtwComposerBody" <?php echo $has_cards ? 'hidden' : ''; ?>>
                <div class="mtw-composer__stage">
                    <div class="mtw-live-card" id="mtwLiveCardPreview" aria-hidden="true">
                        <div class="mtw-live-card__shine"></div>
                        <div class="mtw-live-card__top">
                            <div class="mtw-live-card__logo-wrap" id="mtwLiveBankLogo" aria-hidden="true">
                                <div class="bank-logo-img bank-logo-default-ws" id="mtwLiveBankLogoImg"></div>
                            </div>
                            <span class="mtw-live-card__bank" id="mtwLiveBankName">بانک</span>
                        </div>
                        <div class="mtw-live-card__number" id="mtwLiveCardNumber">•••• •••• •••• ••••</div>
                        <div class="mtw-live-card__footer">
                            <span>به نام</span>
                            <strong><?php
                                $user = wp_get_current_user();
                                echo esc_html($user && $user->display_name ? $user->display_name : 'کاربر مدیار');
                            ?></strong>
                        </div>
                    </div>

                    <div class="mtw-composer__form">
                        <label class="mtw-composer__label" for="newCardNumber">شماره ۱۶ رقمی کارت</label>
                        <input type="text"
                               class="card-input mtw-composer__input"
                               id="newCardNumber"
                               name="newCardNumber"
                               placeholder="۶۰۳۷ - **** - **** - ****"
                               maxlength="19"
                               inputmode="numeric"
                               autocomplete="off"
                               aria-required="true"
                               aria-describedby="newCardNumberError" />
                        <span class="error-message" id="newCardNumberError" role="alert" aria-live="polite"></span>
                        <div class="mtw-composer__actions">
                            <button class="submit-btn mtw-composer__submit" id="submitCardBtn" type="button">ذخیره در کیف پول</button>
                            <button class="cancel-btn mtw-composer__cancel" id="cancelAddCardBtn" type="button">پاک کردن</button>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="mtw-vault__shelf" aria-label="کارت‌های ذخیره‌شده">
            <div class="bank-cards-header mtw-vault__shelf-head">
                <div class="bank-cards-title">کارت‌های ذخیره‌شده</div>
            </div>

            <div class="bank-cards-list mtw-vault__list" id="bankCardsList" role="list" aria-label="لیست کارت‌های بانکی">
                <?php if ($has_cards) :
                    foreach ($cards as $card_number => $card_data) :
                        $card_string = preg_replace('/\D/', '', (string) $card_number);
                        if (strlen($card_string) !== 16) {
                            continue;
                        }
                        $formatted = substr($card_string, 0, 4) . ' ' .
                            substr($card_string, 4, 4) . ' ' .
                            substr($card_string, 8, 4) . ' ' .
                            substr($card_string, 12, 4);
                        $bank_fa = !empty($card_data['bank']['fa']) ? (string) $card_data['bank']['fa'] : 'کارت بانکی';
                        $bank_en = !empty($card_data['bank']['en']) ? (string) $card_data['bank']['en'] : 'bank-logo-default-ws';
                        $iban    = (string) ($card_data['iban'] ?? '');
                        $owner   = (string) ($card_data['owner'] ?? '');
                        ?>
                        <div class="bank-card-item bank-card-item-ws mtw-plastic"
                             data-card-id="<?php echo esc_attr($card_string); ?>"
                             role="radio"
                             aria-checked="false"
                             tabindex="-1">
                            <div class="bank-card-info bank-card-info-ws mtw-plastic__face">
                                <div class="bank-card-icon bank-card-icon-ws">
                                    <div class="bank-logo-img <?php echo esc_attr($bank_en); ?>" aria-label="<?php echo esc_attr($bank_fa); ?>"></div>
                                </div>
                                <div class="bank-card-name bank-card-name-ws"><?php echo esc_html($bank_fa); ?></div>
                                <div class="bank-card-number-wrapper bank-card-number-wrapper-ws">
                                    <div class="bank-card-number bank-card-number-ws"><?php echo esc_html($formatted); ?></div>
                                </div>
                                <input class="card-iban" type="hidden" value="<?php echo esc_attr($iban); ?>">
                                <input class="card-owner" type="hidden" value="<?php echo esc_attr($owner); ?>">
                            </div>
                            <div class="bank-card-actions bank-card-actions-ws mtw-plastic__actions">
                                <button type="button"
                                        class="bank-card-remove-btn bank-card-remove-btn-ws"
                                        data-card-id="<?php echo esc_attr($card_string); ?>"
                                        aria-label="<?php echo esc_attr('حذف کارت ' . $bank_fa); ?>">حذف</button>
                            </div>
                            <div class="mtw-plastic__confirm" hidden>
                                <span>این کارت حذف شود؟</span>
                                <button type="button" class="mtw-plastic__confirm-yes" data-card-id="<?php echo esc_attr($card_string); ?>">بله</button>
                                <button type="button" class="mtw-plastic__confirm-no">خیر</button>
                            </div>
                        </div>
                    <?php endforeach;
                endif; ?>
            </div>

            <div class="no-cards-message no-cards-message-ws mtw-vault__empty" id="noCardsMessage" style="display: <?php echo $has_cards ? 'none' : 'block'; ?>;">
                هنوز کارتی ذخیره نشده — شماره کارت را در فرم بالا وارد کنید.
            </div>
        </section>
    </div>
</div>

<script>
jQuery(function ($) {
    if (typeof window.initBankCardsSection === 'function') {
        window.initBankCardsSection();
    }
});
</script>
