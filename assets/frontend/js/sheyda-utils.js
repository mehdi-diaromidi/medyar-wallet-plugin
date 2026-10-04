sheydaWallet = {
	convertChars: function(string) {
		string = string.toString();
		let replaces = {
			'۰'	: '0',
			'۱'	: '1',
			'۲'	: '2',
			'۳'	: '3',
			'۴'	: '4',
			'۵'	: '5',
			'۶'	: '6',
			'۷'	: '7',
			'۸'	: '8',
			'۹'	: '9',
			'٪'	: '%',
			'÷'	: '/',
			'×'	: '*',
			'-'	: '-',
			'ـ'	: '_',
			'ي'	: 'ی',
			'ك'	: 'ک',
		}
		return string.replace(/[۰۱۲۳۴۵۶۷۸۹٪÷×ـيك]/g, match => replaces[match]);
	},
	formatPrice: function(price, decimals = null) {
		price = String(price);
		let original = sheydaWallet.convertChars(price).replace(/\s+/g, '').replaceAll(',',''),
			addLastCharDot = price.slice(-1) == "." && price.includes('.');
		price = parseFloat(original);
		if(isNaN(price)) return "";
		if( decimals !== null && ( original.includes('0.0') || !Number.isInteger(price) ) ) {
			let priceParts = original.split('.');
			if(priceParts[1] && priceParts[1].length > decimals) {
				priceParts[1] = priceParts[1].slice(0, decimals);
			}
			priceParts[0] = parseInt(priceParts[0]).toLocaleString();
			
			return parseFloat(priceParts.join("."));
		} else {
			price = price.toLocaleString();
			return addLastCharDot ? `${price}.` : price;
		}
	},
	initSelect2: function() {
		if(jQuery('.sheyda-wallet-select2:not([data-select2-id])').length) {
			jQuery('.sheyda-wallet-select2:not([data-select2-id])').select2({
				width: '25em',
			});
		}
	},
}