<?php
if (!defined('ABSPATH')) {
    exit;
}
$user_id    = get_current_user_id();
$bank_cards = medyar_get_user_bank_cards($user_id);
?>
<div class="medyar-bank-cards-endpoint">
    <div class="bank-cards-wrapper" id="bankCardsContainer">
        <div class="bank-cards-header">
            <div class="bank-cards-title">کارت‌های ثبت شده</div>
            <button class="add-card-btn" id="addCardBtn" type="button">افزودن کارت</button>
        </div>
        <div class="bank-cards-list" id="bankCardsList"></div>
        <div class="no-cards-message-ws" id="noCardsMessage" style="display:none;">کارت بانکی ثبت نشده است.</div>
    </div>

    <div class="modal-overlay" id="addCardModal" style="display:none;">
        <div class="modal-content">
            <div class="modal-body">
                <label for="newCardNumber">شماره کارت</label>
                <input type="text" id="newCardNumber" maxlength="19" placeholder="1234-5678-9012-3456" />
            </div>
            <div class="modal-footer">
                <button type="button" id="cancelAddCardBtn">انصراف</button>
                <button type="button" id="submitCardBtn">ثبت کارت</button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="removeCardModal" style="display:none;">
        <div class="modal-content">
            <p>حذف این کارت؟</p>
            <button type="button" id="cancelRemoveCardBtn">انصراف</button>
            <button type="button" id="confirmRemoveCardBtn">حذف کارت</button>
        </div>
    </div>
</div>
<script>jQuery(function(){ if (window.initBankCardsSection) window.initBankCardsSection(); });</script>
