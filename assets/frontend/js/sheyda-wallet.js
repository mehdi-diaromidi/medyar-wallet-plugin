(function($) {
	$(document).ready(function(){
		$(document).on('input', '.sheyda-wallet-price-input', function() {
			$(this).val(sheydaWallet.formatPrice($(this).val()));
		})

		$(document).on('input', '.sheyda-wallet-numeric-input', function() {
			const $input = $(this);

			let value = sheydaWallet.convertChars($input.val())
				.trim()
				.replace(/[^0-9]/g, '');

			$input.val(value);
		});

		// Card Number
		$(document).on('input', ".sheyda-wallet-card-number-input", function() {
			// Cache the jQuery object for the input to avoid repeated DOM lookups
			const $input = $(this);

			// Remove all non-digit characters and limit the input to 11 digits
			let value = sheydaWallet.convertChars($input.val()).trim().replace(/\D/g, '').slice(0, 16);

			// Format the value into the #### #### #### #### pattern dynamically
			value = value.replace(/^(\d{4})(\d{0,4})(\d{0,4})(\d{0,4})$/, (_, a, b, c, d) => {
				// Join the captured groups (a, b, c, d) with spaces, filtering out empty groups
				return [a, b, c, d].filter(Boolean).join(' ');
			});

			// Update the input field with the formatted value
			$input.val(value);
		});

		// Shaba Number
		$(document).on('input', ".sheyda-wallet-shaba-number-input", function() {
			// Cache the jQuery object for the input to avoid repeated DOM lookups
			const $input = $(this);

			// Remove all non-digit characters and limit the input to 16 digits
			let value = sheydaWallet.convertChars($input.val()).trim().replace(/\D/g, '').slice(0, 24);

			// Format the value into the IR## #### #### #### #### #### ## pattern dynamically
			value = value.replace(/^(\d{2})(\d{0,4})(\d{0,4})(\d{0,4})(\d{0,4})(\d{0,4})(\d{0,2})$/, (_, a, b, c, d, e, f, g) => {
				// Join the captured groups (a, b, c, d, e, f, g) with spaces, filtering out empty groups
				return [a, b, c, d, e, f, g].filter(Boolean).join(' ');
			});

			// Update the input field with the formatted value
			$input.val(`IR${value}`);
		});

		sheydaWallet.initSelect2();
	});
})(jQuery);