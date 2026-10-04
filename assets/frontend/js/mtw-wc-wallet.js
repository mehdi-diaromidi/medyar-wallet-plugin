(function ($) {
	'use strict';

	function cfg() {
		return window.mtwWalletAjax || {};
	}

	function i18n(key, fallback) {
		var dict = cfg().i18n || {};
		return dict[key] || fallback || '';
	}

	function convertAmount(raw) {
		var convert = (window.sheydaWallet && sheydaWallet.convertChars)
			? sheydaWallet.convertChars
			: function (v) { return String(v); };
		return parseFloat(convert(String(raw || '')).replace(/[^\d]/g, '')) || 0;
	}

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

	function postAjax(action, data) {
		var payload = $.extend({}, data || {}, {
			action: action,
			nonce: cfg().nonce || ''
		});
		return $.ajax({
			url: cfg().ajaxUrl || '',
			type: 'POST',
			dataType: 'text',
			data: payload
		}).then(function (raw) {
			var parsed = parseAjaxResponse(raw);
			if (!parsed) {
				return $.Deferred().reject({ status: 500, responseText: raw }).promise();
			}
			return parsed;
		});
	}

	function showBox($el, message, isError) {
		if (!$el || !$el.length) {
			return;
		}
		$el
			.toggleClass('note-error', !!isError)
			.html(String(message || ''))
			.stop(true, true)
			.slideDown();
	}

	function hideBox($el) {
		if (!$el || !$el.length) {
			return;
		}
		$el.slideUp({
			complete: function () {
				$(this).empty().removeClass('note-error');
			}
		});
	}

	function lockButton($btn, locked) {
		if (!$btn || !$btn.length) {
			return;
		}
		$btn.toggleClass('loading', !!locked);
		$btn.prop('disabled', !!locked);
		if (locked) {
			$btn.addClass('disabled');
		} else {
			$btn.removeClass('disabled');
		}
	}

	function errorMessage(res, xhr) {
		if (res && res.data && res.data.message) {
			return res.data.message;
		}
		if (xhr && xhr.status === 429) {
			return i18n('rateLimited', 'تعداد درخواست‌ها زیاد است. کمی صبر کنید.');
		}
		if (xhr && (xhr.status === 403 || xhr.status === 401)) {
			return i18n('securityError', 'خطای امنیتی. صفحه را رفرش کنید.');
		}
		return i18n('networkError', 'خطا در ارتباط با سرور.');
	}

	$(document).ready(function () {
		var actions = cfg().actions || {};
		var busy = {
			topup: false,
			withdraw: false,
			financial: false
		};

		$('.sheyda_wallet_topup-predefined-amount-btn').on('click', function () {
			$('#sheyda_wallet_topup-amount-field').val($(this).attr('data-amount')).trigger('input');
		});

		$('#sheyda_wallet_topup-amount-field').on('change input', function () {
			hideBox($('.sheyda_wallet_topup-errors'));
		});

		$('#sheyda_wallet_topup-submit').on('click', function (e) {
			e.preventDefault();
			if (busy.topup) {
				return;
			}

			var $btn = $(this);
			var amount = convertAmount($('#sheyda_wallet_topup-amount-field').val());
			var cardNumber = $('#mtw_topup_card').val() || '';
			var $errors = $('.sheyda_wallet_topup-errors');

			if (!amount || amount < 1000) {
				showBox($errors, i18n('invalidAmount', 'مبلغ نامعتبر است.'), true);
				return;
			}
			if (!cardNumber) {
				showBox($errors, i18n('selectCard', 'یک کارت بانکی انتخاب کنید.'), true);
				return;
			}

			busy.topup = true;
			lockButton($btn, true);
			hideBox($errors);

			postAjax(actions.topup || 'mtw_wallet_topup', {
				amount: amount,
				card_number: cardNumber
			})
				.done(function (res) {
					if (res && res.success && res.data && res.data.checkoutUrl) {
						window.location.replace(res.data.checkoutUrl);
						return;
					}
					showBox($errors, errorMessage(res), true);
					busy.topup = false;
					lockButton($btn, false);
				})
				.fail(function (xhr) {
					var res = xhr.responseJSON || null;
					showBox($errors, errorMessage(res, xhr), true);
					busy.topup = false;
					lockButton($btn, false);
				});
		});

		$('#sheyda_wallet_financial-new-account-btn').on('click', function () {
			if (typeof wp === 'undefined' || !wp.template) {
				return;
			}
			var template = wp.template('sheyda-wallet-financial-account');
			var newItem = template({
				index: $('.sheyda_wallet_financial-account').length
			});
			$('.sheyda_wallet_financial-accounts').append(newItem);

			if ($.fn.select2) {
				$('.sheyda_wallet_financial-account_type').select2({
					width: '100%',
					minimumResultsForSearch: Infinity
				});
			}
		});

		$(document).on('click', '.sheyda_wallet_financial-account-remove', function () {
			$(this).closest('.sheyda_wallet_financial-account').remove();
			$('.sheyda_wallet_financial-account').each(function (index) {
				$(this).find('input, select').each(function () {
					var name = $(this).attr('name');
					if (name) {
						$(this).attr('name', name.replace(/\d+/g, String(index)));
					}
					var id = $(this).attr('id');
					if (id) {
						$(this).attr('id', id.replace(/\d+/g, String(index)));
					}
					var $label = $(this).siblings('label');
					if ($label.length && $label.attr('for')) {
						$label.attr('for', $label.attr('for').replace(/\d+/g, String(index)));
					}
				});
			});
		});

		if ($.fn.select2) {
			$('#sheyda_wallet_withdrawal-destination-field, #mtw_topup_card, .sheyda_wallet_financial-account_type').select2({
				width: '100%',
				minimumResultsForSearch: Infinity
			});
		}

		function checkWithdrawalAmount() {
			var $amountField = $('#sheyda_wallet_withdrawal-amount-field');
			var $submitButton = $('#sheyda_wallet_withdrawal-submit');
			if (!$amountField.length) {
				return true;
			}
			var value = convertAmount($amountField.val());
			var minValue = parseFloat($amountField.attr('data-min'));
			var maxValue = parseFloat($amountField.attr('data-max'));

			if (!isNaN(value) && value >= minValue && value <= maxValue) {
				$submitButton.removeClass('disabled');
				hideBox($('.sheyda_wallet_withdrawal-errors'));
				return true;
			}

			$submitButton.addClass('disabled');
			var msg = value < minValue
				? i18n('minWithdrawalError', 'مبلغ کمتر از حداقل مجاز است.')
				: i18n('maxWithdrawalError', 'مبلغ بیشتر از موجودی است.');
			showBox($('.sheyda_wallet_withdrawal-errors'), msg, true);
			return false;
		}

		$('#sheyda_wallet_withdrawal-amount-field').on('input', function () {
			checkWithdrawalAmount();
		});

		$('#sheyda_wallet_withdrawal-request-form').on('submit', function (e) {
			e.preventDefault();
			if (busy.withdraw) {
				return;
			}
			if (!checkWithdrawalAmount()) {
				return;
			}

			var $form = $(this);
			var $btn = $('#sheyda_wallet_withdrawal-submit');
			var $status = $('#mtw_withdrawal_ajax_status');
			var amount = convertAmount($('#sheyda_wallet_withdrawal-amount-field').val());
			var cardNumber = $('#sheyda_wallet_withdrawal-destination-field').val() || '';

			if (!cardNumber) {
				showBox($('.sheyda_wallet_withdrawal-errors'), i18n('selectCard', 'یک کارت بانکی انتخاب کنید.'), true);
				return;
			}

			busy.withdraw = true;
			lockButton($btn, true);
			hideBox($status);

			postAjax(actions.withdraw || 'mtw_wallet_withdraw', {
				amount: amount,
				card_number: cardNumber
			})
				.done(function (res) {
					if (res && res.success) {
						showBox($status, (res.data && res.data.message) || i18n('withdrawSuccess', 'درخواست برداشت با موفقیت ثبت شد.'), false);
						if (res.data && res.data.balanceText) {
							$('.sheyda_wallet_withdrawal-note.withdrawable-credit').text(
								'موجودی قابل برداشت: ' + res.data.balanceText + '.'
							);
						}
						window.setTimeout(function () {
							var url = (res.data && res.data.reloadUrl) || (cfg().urls && cfg().urls.withdrawal) || window.location.href;
							window.location.assign(url);
						}, 600);
						return;
					}
					showBox($('.sheyda_wallet_withdrawal-errors'), errorMessage(res), true);
					busy.withdraw = false;
					lockButton($btn, false);
				})
				.fail(function (xhr) {
					var res = xhr.responseJSON || null;
					showBox($('.sheyda_wallet_withdrawal-errors'), errorMessage(res, xhr), true);
					busy.withdraw = false;
					lockButton($btn, false);
				});
		});

		$('#sheyda_wallet_withdrawal-submit').on('click', function (e) {
			e.preventDefault();
			$('#sheyda_wallet_withdrawal-request-form').trigger('submit');
		});

		// Financial section is bank-cards AJAX UI (Medyar_Bank_Cards); no form submit here.
	});
})(jQuery);
