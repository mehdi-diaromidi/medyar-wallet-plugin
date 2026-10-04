/**
 * Medyar bank vault — plastic preview + inline composer (no modal).
 * Keeps AJAX + selectors used by Medyar_Bank_Cards.
 */
(function ($) {
    'use strict';

    function parseAjaxResponse(raw) {
        if (raw && typeof raw === 'object') {
            return raw;
        }
        try {
            return JSON.parse(String(raw || '').replace(/^\uFEFF/, '').trim());
        } catch (e) {
            return null;
        }
    }

    function postBankCards(data) {
        return $.ajax({
            url: medyarBankCards.ajaxUrl,
            type: 'POST',
            dataType: 'text',
            data: data,
        }).then(function (raw) {
            const parsed = parseAjaxResponse(raw);
            if (!parsed) {
                return $.Deferred().reject({ status: 500, responseText: raw }).promise();
            }
            return parsed;
        });
    }

    function notify(type, message) {
        if (!message) {
            return;
        }
        if (type === 'success' && typeof window.showSuccessToast === 'function') {
            window.showSuccessToast(message);
            return;
        }
        if (type === 'error' && typeof window.showErrorToast === 'function') {
            window.showErrorToast(message);
            return;
        }
        let $toast = $('#mtwBankCardsToast');
        if (!$toast.length) {
            $toast = $('<div>', { id: 'mtwBankCardsToast', class: 'mtw-toast', role: 'status', 'aria-live': 'polite' }).appendTo(document.body);
        }
        $toast
            .removeClass('is-success is-error is-visible')
            .addClass(type === 'success' ? 'is-success' : 'is-error')
            .text(String(message))
            .addClass('is-visible');
        clearTimeout(window.__mtwToastTimer);
        window.__mtwToastTimer = setTimeout(function () {
            $toast.removeClass('is-visible');
        }, 2800);
    }

    let isEventsInitialized = false;

    function getMaxBankCards() {
        if (typeof medyarBankCards !== 'undefined' && medyarBankCards.max_cards) {
            const n = parseInt(medyarBankCards.max_cards, 10);
            if (n > 0) {
                return n;
            }
        }
        return 3;
    }

    function formatCardNumber(cardNumber) {
        if (!cardNumber) return '';
        const cleaned = String(cardNumber).replace(/\D/g, '');
        if (cleaned.length === 16) {
            return cleaned.match(/.{1,4}/g).join(' ');
        }
        return cardNumber;
    }

    function persianToEnglish(str) {
        if (typeof str !== 'string') return '';
        const map = {
            '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4',
            '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9',
            '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4',
            '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9',
        };
        return str.replace(/[۰-۹٠-٩]/g, function (d) {
            return map[d] || d;
        });
    }

    function getCardLast4(cardNumber) {
        if (typeof cardNumber !== 'string') return '';
        return persianToEnglish(cardNumber).replace(/\D/g, '').slice(-4);
    }

    function getSelectedCardFromStorage() {
        try {
            const stored = localStorage.getItem('selected_bank_card_last4');
            return stored && /^\d{4}$/.test(stored) ? stored : null;
        } catch (e) {
            return null;
        }
    }

    function saveSelectedCardToStorage(last4) {
        try {
            if (last4 && /^\d{4}$/.test(last4)) {
                localStorage.setItem('selected_bank_card_last4', last4);
            } else {
                localStorage.removeItem('selected_bank_card_last4');
            }
        } catch (e) {
            // ignore
        }
    }

    function updateCardCountMeter() {
        const n = $('#bankCardsList .bank-card-item-ws').length;
        $('#mtwCardCountLabel').text(String(n));
    }

    function setComposerOpen(open) {
        const $composer = $('#mtwAddCardComposer');
        const $body = $('#mtwComposerBody');
        const $btn = $('#addCardBtn');
        if (!$composer.length) {
            return;
        }
        $composer.toggleClass('is-open', !!open);
        $btn.attr('aria-expanded', open ? 'true' : 'false');
        if (open) {
            $body.prop('hidden', false);
            setTimeout(function () {
                $('#newCardNumber').trigger('focus');
            }, 40);
        } else {
            $body.prop('hidden', true);
            $('#newCardNumber').val('');
            $('#newCardNumberError').text('');
            updateLivePreview('');
        }
    }

    function guessBank(digits) {
        const bins = (typeof medyarBankCards !== 'undefined' && medyarBankCards.banks)
            ? medyarBankCards.banks
            : {};
        const bin6 = String(digits || '').slice(0, 6);
        if (bin6.length < 6) {
            return { fa: 'بانک', en: 'bank-logo-default-ws', known: false };
        }
        if (bins[bin6]) {
            return {
                fa: bins[bin6].fa || 'بانک',
                en: bins[bin6].en || 'bank-logo-default-ws',
                known: true,
            };
        }
        return { fa: 'بانک نامشخص', en: 'bank-logo-default-ws', known: false };
    }

    function updateLivePreview(raw) {
        const digits = persianToEnglish(String(raw || '')).replace(/\D/g, '').substring(0, 16);
        let display = '•••• •••• •••• ••••';
        if (digits.length) {
            const padded = (digits + '••••••••••••••••').slice(0, 16);
            display = padded.match(/.{1,4}/g).join(' ');
        }
        const bank = guessBank(digits);
        $('#mtwLiveCardNumber').text(display);
        $('#mtwLiveBankName').text(bank.fa);
        const $logo = $('#mtwLiveBankLogoImg');
        if ($logo.length) {
            $logo.attr('class', 'bank-logo-img ' + bank.en);
        }
        $('#mtwLiveCardPreview')
            .toggleClass('is-filled', digits.length >= 6 && bank.known)
            .toggleClass('is-unknown', digits.length >= 6 && !bank.known);
    }

    window.refreshBankCardsData = function () {
        fetchBankCardsData();
    };

    function fetchBankCardsData() {
        if (typeof medyarBankCards !== 'undefined' && Array.isArray(medyarBankCards.initialCards)) {
            populateBankCardsDOM({ cards: medyarBankCards.initialCards });
            return;
        }
        const $list = $('#bankCardsList');
        if ($list.children('.bank-card-item-ws').length > 0) {
            ensureSingleBankCardSelectedInList($list);
        }
        updateCardCountMeter();
    }

    function populateBankCardsDOM(data) {
        if (window.medyar_PANEL_SKELETON_DEBUG === true) {
            return;
        }
        if (Array.isArray(data.cards)) {
            renderBankCards(data.cards);
        }
    }

    function ensureSingleBankCardSelectedInList($list) {
        const $cards = $list.children('.bank-card-item-ws');
        if ($cards.length !== 1) {
            return;
        }
        const $only = $cards.first();
        const last4 = getCardLast4($only.find('.bank-card-number-ws').text());
        saveSelectedCardToStorage(last4);
        if ($only.hasClass('selected') && $only.find('.bank-card-selected-badge-ws').length) {
            return;
        }
        $cards.removeClass('selected').attr('aria-checked', 'false').attr('tabindex', '-1');
        $cards.find('.bank-card-selected-badge-ws, .bank-card-selected-badge').remove();
        $only.addClass('selected').attr('aria-checked', 'true').attr('tabindex', '0');
        const $numberEl = $only.find('.bank-card-number-ws').first();
        let $wrapper = $numberEl.parent();
        if (!$wrapper.hasClass('bank-card-number-wrapper-ws') && !$wrapper.hasClass('bank-card-number-wrapper')) {
            $numberEl.wrap('<div class="bank-card-number-wrapper-ws"></div>');
            $wrapper = $numberEl.parent();
        }
        if ($wrapper.find('.bank-card-selected-badge-ws, .bank-card-selected-badge').length === 0) {
            $wrapper.append($('<span>', { class: 'bank-card-selected-badge-ws', text: 'پیش‌فرض' }));
        }
    }

    function renderBankCards(cards) {
        const $list = $('#bankCardsList');
        $list.empty();

        if (!Array.isArray(cards) || cards.length === 0) {
            $('#noCardsMessage').show();
            updateCardCountMeter();
            setComposerOpen(true);
            return;
        }

        $('#noCardsMessage').hide();
        const storedLast4 = getSelectedCardFromStorage();
        const forceSelectOnly = cards.length === 1;

        cards.forEach(function (card) {
            const cardNumber = card.card_number_formatted || card.cardNumber || card.number || '';
            const bankNameFa = card.bank_name_fa || card.bankName || card.name || 'کارت بانکی';
            const bankNameEn = card.bank_name_en || card.bankNameEn || 'bank-logo-default-ws';
            const iban = card.iban || '';
            const owner = card.owner || '';
            const cardLast4 = getCardLast4(cardNumber);
            let isSelected = false;
            if (forceSelectOnly) {
                isSelected = true;
                saveSelectedCardToStorage(cardLast4);
            } else if (storedLast4 && storedLast4 === cardLast4) {
                isSelected = true;
            }
            $list.append(createBankCardElement({
                cardNumber: cardNumber,
                bankNameFa: bankNameFa,
                bankNameEn: bankNameEn,
                iban: iban,
                owner: owner,
                isSelected: isSelected,
            }));
        });

        updateCardCountMeter();
        if (cards.length >= getMaxBankCards()) {
            setComposerOpen(false);
        }
    }

    function createBankCardElement(opts) {
        const cardNumber = opts.cardNumber || '';
        const bankNameFa = opts.bankNameFa || 'کارت بانکی';
        const bankNameEn = opts.bankNameEn || 'default';
        const iban = opts.iban || '';
        const owner = opts.owner || '';
        const isSelected = !!opts.isSelected;
        const cardId = String(cardNumber).replace(/\D/g, '');
        const formattedCardNumber = formatCardNumber(cardNumber);

        const $cardItem = $('<div>', {
            class: 'bank-card-item bank-card-item-ws mtw-plastic' + (isSelected ? ' selected' : ''),
            role: 'radio',
            'aria-checked': isSelected,
            tabindex: isSelected ? 0 : -1,
            'data-card-id': cardId,
        });

        const logoClass = bankNameEn !== 'bank-logo-default-ws' ? 'bank-logo-img ' + bankNameEn : 'bank-logo-img bank-logo-default-ws';
        const $icon = $('<div>', { class: 'bank-card-icon bank-card-icon-ws' }).append(
            $('<div>', { class: logoClass, 'aria-label': bankNameFa })
        );

        const $numberWrapper = $('<div>', { class: 'bank-card-number-wrapper bank-card-number-wrapper-ws' }).append(
            $('<div>', { class: 'bank-card-number bank-card-number-ws', text: formattedCardNumber })
        );
        if (isSelected) {
            $numberWrapper.append($('<span>', { class: 'bank-card-selected-badge bank-card-selected-badge-ws', text: 'پیش‌فرض' }));
        }

        const $removeBtn = $('<button>', {
            class: 'bank-card-remove-btn bank-card-remove-btn-ws',
            type: 'button',
            'data-card-id': cardId,
            'aria-label': 'حذف کارت ' + bankNameFa,
            text: 'حذف',
        });

        const $confirm = $('<div>', { class: 'mtw-plastic__confirm', hidden: true }).append(
            $('<span>', { text: 'این کارت حذف شود؟' }),
            $('<button>', { type: 'button', class: 'mtw-plastic__confirm-yes', 'data-card-id': cardId, text: 'بله' }),
            $('<button>', { type: 'button', class: 'mtw-plastic__confirm-no', text: 'خیر' })
        );

        $cardItem.append(
            $('<div>', { class: 'bank-card-info bank-card-info-ws mtw-plastic__face' }).append(
                $icon,
                $('<div>', { class: 'bank-card-name bank-card-name-ws', text: bankNameFa }),
                $numberWrapper,
                $('<input>', { type: 'hidden', class: 'card-iban', value: iban }),
                $('<input>', { type: 'hidden', class: 'card-owner', value: owner })
            ),
            $('<div>', { class: 'bank-card-actions bank-card-actions-ws mtw-plastic__actions' }).append($removeBtn),
            $confirm
        );

        return $cardItem;
    }

    function clearRemoveConfirms() {
        $('.mtw-plastic').removeClass('is-confirming');
        $('.mtw-plastic__confirm').prop('hidden', true);
    }

    function removeCard(cardId) {
        if (typeof medyarBankCards === 'undefined') {
            notify('error', 'خطا در اتصال به سرور. لطفا صفحه را رفرش کنید.');
            return;
        }

        const $allMatches = $(
            '#bankCardsList .bank-card-item-ws[data-card-id="' + cardId + '"]'
        );
        if ($allMatches.length === 0) {
            notify('error', 'کارت یافت نشد.');
            return;
        }

        const $cardForNumber = $allMatches.first();
        const cardNumberText = $cardForNumber.find('.bank-card-number-ws').first().text();
        const cardNumber = cardNumberText.replace(/\D/g, '');

        if (!cardNumber || cardNumber.length !== 16) {
            notify('error', 'شماره کارت نامعتبر است.');
            return;
        }

        if ($cardForNumber.hasClass('selected')) {
            const cardLast4 = getCardLast4(cardNumberText);
            if (getSelectedCardFromStorage() === cardLast4) {
                saveSelectedCardToStorage(null);
            }
        }

        $allMatches.addClass('is-busy');

        postBankCards({
            action: medyarBankCards.removeAction,
            nonce: medyarBankCards.nonce,
            card_number: cardNumber,
        })
            .done(function (response) {
                if (response.success) {
                    if (response.data && response.data.cards) {
                        medyarBankCards.initialCards = response.data.cards;
                    }
                    clearRemoveConfirms();

                    if (window.MyAccountCache) {
                        if (typeof window.MyAccountCache.markBankCardsDirty === 'function') {
                            window.MyAccountCache.markBankCardsDirty();
                        }
                        window.MyAccountCache.invalidate('bank-cards');
                        window.MyAccountCache.invalidate('wallet');
                        window.MyAccountCache.invalidateSession('wallet');
                        window.MyAccountCache.invalidateSession('bank-cards');
                    }

                    $allMatches.css({
                        opacity: '0',
                        transform: 'translateY(8px) scale(0.98)',
                        transition: 'opacity 0.28s ease, transform 0.28s ease',
                    });

                    setTimeout(function () {
                        $allMatches.remove();
                        updateCardCountMeter();
                        if ($('#bankCardsList').children('.bank-card-item-ws').length === 0) {
                            $('#noCardsMessage').show();
                            setComposerOpen(true);
                        }
                    }, 280);

                    notify('success', (response.data && response.data.message) || 'کارت با موفقیت حذف شد');
                } else {
                    const msg = (response.data && response.data.message) ? response.data.message : 'خطا در حذف کارت بانکی';
                    notify('error', msg);
                }
            })
            .fail(function () {
                notify('error', 'خطا در ارتباط با سرور. لطفا دوباره تلاش کنید.');
            })
            .always(function () {
                $allMatches.removeClass('is-busy');
            });
    }

    window.initBankCardsSection = function () {
        if ($('#addCardBtn').length === 0 || $('#newCardNumber').length === 0) {
            setTimeout(window.initBankCardsSection, 100);
            return;
        }

        fetchBankCardsData();

        if (isEventsInitialized) {
            return;
        }
        isEventsInitialized = true;

        $(document).off('input.mtwVault', '#newCardNumber').on('input.mtwVault', '#newCardNumber', function () {
            let value = persianToEnglish(this.value).replace(/\D/g, '').substring(0, 16);
            this.value = value.length > 0 ? (value.match(/.{1,4}/g) || [value]).join('-') : '';
            updateLivePreview(this.value);
            $('#newCardNumberError').text('');
        });

        $(document).off('click.mtwVault', '#addCardBtn, #addCardFabBtn').on('click.mtwVault', '#addCardBtn, #addCardFabBtn', function () {
            const maxCards = getMaxBankCards();
            const cardCount = $('#bankCardsList .bank-card-item-ws, #bankCardsList .bank-card-item').length;
            const willOpen = !$('#mtwAddCardComposer').hasClass('is-open');

            if (willOpen && cardCount >= maxCards) {
                notify('error', 'حداکثر ' + maxCards + ' شماره کارت مجاز می‌باشد.');
                return;
            }
            setComposerOpen(willOpen);
        });

        $(document).off('click.mtwVault', '#cancelAddCardBtn').on('click.mtwVault', '#cancelAddCardBtn', function () {
            $('#newCardNumber').val('');
            $('#newCardNumberError').text('');
            updateLivePreview('');
            if ($('#bankCardsList .bank-card-item-ws').length > 0) {
                setComposerOpen(false);
            }
        });

        $(document).off('click.mtwVault', '.bank-card-remove-btn-ws, .bank-card-remove-btn').on('click.mtwVault', '.bank-card-remove-btn-ws, .bank-card-remove-btn', function (e) {
            e.stopPropagation();
            const $card = $(this).closest('.mtw-plastic, .bank-card-item-ws');
            clearRemoveConfirms();
            $card.addClass('is-confirming');
            $card.find('.mtw-plastic__confirm').prop('hidden', false);
        });

        $(document).off('click.mtwVault', '.mtw-plastic__confirm-no').on('click.mtwVault', '.mtw-plastic__confirm-no', function (e) {
            e.stopPropagation();
            clearRemoveConfirms();
        });

        $(document).off('click.mtwVault', '.mtw-plastic__confirm-yes').on('click.mtwVault', '.mtw-plastic__confirm-yes', function (e) {
            e.stopPropagation();
            const cardId = $(this).attr('data-card-id');
            if (cardId) {
                removeCard(cardId);
            }
        });

        $(document).off('click.mtwVault', '#submitCardBtn').on('click.mtwVault', '#submitCardBtn', function () {
            if (typeof medyarBankCards === 'undefined') {
                return;
            }
            const $err = $('#newCardNumberError');
            const cardNumber = persianToEnglish($('#newCardNumber').val()).replace(/\D/g, '');
            if (cardNumber.length !== 16) {
                $err.text('شماره کارت باید ۱۶ رقم باشد.');
                notify('error', 'شماره کارت ۱۶ رقم باشد.');
                return;
            }
            $err.text('');
            const $btn = $(this).prop('disabled', true).text('در حال ذخیره…');
            postBankCards({
                action: medyarBankCards.addAction,
                nonce: medyarBankCards.nonce,
                card_number: cardNumber,
            })
                .done(function (response) {
                    if (response.success) {
                        if (response.data && response.data.cards) {
                            medyarBankCards.initialCards = response.data.cards;
                        }
                        $('#newCardNumber').val('');
                        updateLivePreview('');
                        fetchBankCardsData();
                        setComposerOpen(false);
                        notify('success', (response.data && response.data.message) || 'ثبت شد');
                    } else {
                        const msg = (response.data && response.data.message) ? response.data.message : 'خطا';
                        $err.text(msg);
                        notify('error', msg);
                    }
                })
                .fail(function () {
                    notify('error', 'خطا در ارتباط با سرور');
                })
                .always(function () {
                    $btn.prop('disabled', false).text('ذخیره در کیف پول');
                });
        });
    };
})(jQuery);
