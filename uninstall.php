<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$options = [
    'medyar_wallet_max_deposit',
    'medyar_wallet_max_withdraw',
    'medyar_deposit_withdrawal_admin_ids',
    'wallet_sms_enabled',
];

foreach ($options as $key) {
    delete_option($key);
}
