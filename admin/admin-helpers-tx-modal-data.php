<?php

/**
 * ساخت آرایه wwpTxData برای مودال تراکنش (مشترک بین صفحات ادمین)
 */
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wwp_format_modal_wallet_tx_row')) {
    /**
     * یک ردیف تراکنش کیف پول برای مودال (همان قالب trades).
     *
     * @param object $tx
     * @return array<string, mixed>
     */
    function wwp_format_modal_wallet_tx_row(object $tx, string $role_note = ''): array
    {
        return [
            'id'               => (int) $tx->id,
            'balance_type'     => (string) $tx->balance_type,
            'balance_label'    => wwp_label_balance_type((string) $tx->balance_type),
            'operation_type'   => (string) $tx->operation_type,
            'operation_label'  => wwp_label_operation_type((string) $tx->operation_type),
            'amount'           => (float) $tx->amount,
            'status'           => (string) $tx->status,
            'status_label'     => wwp_label_status((string) $tx->status),
            'transaction_code' => (string) ( $tx->transaction_code ?? '' ),
            'created_at'       => date_i18n('Y/m/d H:i', strtotime((string) $tx->created_at)),
            'description'      => wwp_humanize_transaction_description( (string) ( $tx->description ?? '' ) ),
            'role_note'        => $role_note,
        ];
    }
}

if (!function_exists('wwp_enrich_credit_grant_modal_entry')) {
    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    function wwp_enrich_credit_grant_modal_entry(array $entry): array
    {
        if (($entry['operation_type'] ?? '') !== 'credit_grant' || !class_exists('Credit_Manager')) {
            return $entry;
        }

        $grant_id = (int) ($entry['id'] ?? 0);
        if ($grant_id <= 0) {
            return $entry;
        }

        $related = Credit_Manager::get_instance()->get_grant_modal_wallet_transactions($grant_id);
        $entry['credit_collateral_wallet_txs'] = $related['collateral'] ?? [];
        $entry['credit_closure_wallet_txs']      = $related['closure'] ?? [];

        return $entry;
    }
}

if (!function_exists('wwp_build_transaction_modal_js_data')) {
    /**
     * @param array<int, object> $transactions ردیف‌های wallet_transactions
     * @return array<int, array<string, mixed>>
     */
    function wwp_build_transaction_modal_js_data(array $transactions): array
    {
        if (file_exists(WALLET_PLUGIN_PATH . 'admin/admin-helpers-trades.php')) { require_once WALLET_PLUGIN_PATH . 'admin/admin-helpers-trades.php'; }

        $tm     = Wallet_Transaction_Manager::get_instance();
        global $wpdb;

        $tx_js_data = [];
        foreach ($transactions as $tx) {
            $user    = get_user_by('id', $tx->user_id);
            $p_info  = !empty($tx->p_info) ? json_decode($tx->p_info, true) : null;
            $pay_tbl = $wpdb->prefix . 'wallet_payment_transactions';

            $payment_record = null;
            $linked_tx_obj  = null;
            $skip_validity  = wwp_tx_skips_validity($tx);
            $validity       = ['valid' => null, 'reason' => ''];

            if (!$skip_validity && $tx->operation_type === 'buy_silver' && $p_info) {
                if ($p_info['type'] === 'Pay_gateway') {
                    $payment_record = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$pay_tbl} WHERE id=%d LIMIT 1", (int) $p_info['id']));
                    $validity = ($payment_record && $payment_record->status === 'completed')
                        ? ['valid' => true, 'reason' => 'پرداخت از طریق درگاه «' . esc_html($payment_record->gateway) . '» با کد مرجع ' . esc_html($payment_record->reference_id) . ' تأیید شده است.']
                        : ['valid' => false, 'reason' => 'تراکنش پرداخت درگاه هنوز تأیید (completed) نشده است.'];
                } elseif ($p_info['type'] === 'Wallet_gateway') {
                    $linked_tx_obj = $tm->get_transaction((int) $p_info['id']);
                    $validity = ($linked_tx_obj && $linked_tx_obj->status === 'completed')
                        ? ['valid' => true, 'reason' => 'پرداخت از طریق کیف پول (شناسه تراکنش: #' . $linked_tx_obj->id . ') با وضعیت «تکمیل شده» تأیید شده است.']
                        : ['valid' => false, 'reason' => 'تراکنش کیف پول مرتبط هنوز تأیید نشده است.'];
                }
            } elseif (!$skip_validity && $tx->operation_type === 'sell_silver' && !empty($tx->linked_tx_id)) {
                $linked_tx_obj = $tm->get_transaction((int) $tx->linked_tx_id);
                $linked_label = $linked_tx_obj ? wwp_label_balance_type((string) $linked_tx_obj->balance_type) : 'دارایی';
                $validity = ($linked_tx_obj && $linked_tx_obj->status === 'completed')
                    ? ['valid' => true, 'reason' => 'کاهش موجودی ' . $linked_label . ' (شناسه تراکنش: #' . $linked_tx_obj->id . ') با وضعیت «تکمیل شده» تأیید شده است.']
                    : ['valid' => false, 'reason' => 'تراکنش کاهش موجودی مرتبط هنوز تأیید نشده است.'];
            } elseif (!$skip_validity && $tx->operation_type === 'charge' && $p_info && $p_info['type'] === 'Pay_gateway') {
                $payment_record = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$pay_tbl} WHERE id=%d LIMIT 1", (int) $p_info['id']));
                $validity = ($payment_record && $payment_record->status === 'completed')
                    ? ['valid' => true, 'reason' => 'پرداخت از طریق درگاه تأیید شده است. این نوع تراکنش نیازی به تأیید دستی ندارد.']
                    : ['valid' => false, 'reason' => 'پرداخت درگاه هنوز تأیید نشده است.'];
            } elseif (!$skip_validity && $tx->operation_type === 'withdraw') {
                $validity = $tx->status === 'processing'
                    ? ['valid' => null, 'reason' => 'این تراکنش نیازمند تأیید دستی ادمین است.']
                    : ['valid' => true, 'reason' => 'تراکنش برداشت تکمیل شده است.'];
            } elseif (!$skip_validity && $tx->operation_type === 'trade_buy') {
                if (!empty($tx->linked_tx_id)) {
                    $linked_tx_obj = $tm->get_transaction((int) $tx->linked_tx_id);
                }
                $amt = (float) $tx->amount;
                $returned_for_validity = null;
                if ($tx->status === 'canceled') {
                    $returned_for_validity = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}wallet_transactions
                         WHERE operation_type = 'returned' AND linked_tx_id = %d LIMIT 1",
                        (int) $tx->id
                    ));
                    if (!$returned_for_validity && !empty($tx->linked_tx_id)) {
                        $returned_for_validity = $wpdb->get_row($wpdb->prepare(
                            "SELECT * FROM {$wpdb->prefix}wallet_transactions
                             WHERE operation_type = 'returned' AND linked_tx_id = %d LIMIT 1",
                            (int) $tx->linked_tx_id
                        ));
                    }
                    if (!$returned_for_validity && !empty($tx->trade_tx_code)) {
                        $returned_for_validity = $wpdb->get_row($wpdb->prepare(
                            "SELECT * FROM {$wpdb->prefix}wallet_transactions
                             WHERE operation_type = 'returned' AND trade_tx_code = %s
                             ORDER BY id DESC LIMIT 1",
                            $tx->trade_tx_code
                        ));
                    }
                }
                if ($tx->balance_type === 'toman' && $amt < 0) {
                    if ($tx->status === 'canceled') {
                        if ($linked_tx_obj && $returned_for_validity && $returned_for_validity->status === 'completed') {
                            $validity = [
                                'valid'  => true,
                                'reason' => 'کسر تومان لغو شده است. تراکنش دارایی معاملاتی #' . (int) $linked_tx_obj->id
                                    . ' نیز لغو شده و بازگشت وجه #' . (int) $returned_for_validity->id
                                    . ' با وضعیت «تکمیل شده» ثبت شده است.',
                            ];
                        } elseif ($linked_tx_obj) {
                            $validity = [
                                'valid'  => true,
                                'reason' => 'کسر تومان لغو شده و تراکنش دارایی معاملاتی #' . (int) $linked_tx_obj->id . ' ثبت شده است.',
                            ];
                        } else {
                            $validity = ['valid' => null, 'reason' => 'تراکنش دارایی مرتبط با این کسر تومان یافت نشد.'];
                        }
                    } else {
                        $validity = ($linked_tx_obj && $linked_tx_obj->balance_type !== 'toman')
                            ? ['valid' => true, 'reason' => 'مبلغ از کیف پول تومانی کسر شده و تراکنش دارایی معاملاتی #' . (int) $linked_tx_obj->id . ' ثبت شده است.']
                            : ['valid' => null, 'reason' => 'تراکنش دارایی مرتبط با این کسر تومان یافت نشد.'];
                    }
                } elseif ($tx->balance_type !== 'toman' && $amt > 0) {
                    if ($tx->status === 'canceled') {
                        $linked_canceled = $linked_tx_obj
                            && $linked_tx_obj->balance_type === 'toman'
                            && (float) $linked_tx_obj->amount < 0
                            && $linked_tx_obj->status === 'canceled';
                        $ret_ok = $returned_for_validity && $returned_for_validity->status === 'completed';
                        if ($linked_canceled && $ret_ok) {
                            $validity = [
                                'valid'  => true,
                                'reason' => 'معامله لغو شده است. تراکنش کسر تومان #' . (int) $linked_tx_obj->id
                                    . ' با وضعیت «لغو شده» و تراکنش بازگشت وجه #' . (int) $returned_for_validity->id
                                    . ' با وضعیت «تکمیل شده» ثبت شده؛ دارایی تحویل داده نمی‌شود.',
                            ];
                        } elseif ($linked_canceled) {
                            $validity = [
                                'valid'  => true,
                                'reason' => 'معامله لغو شده؛ تراکنش کسر تومان #' . (int) $linked_tx_obj->id . ' با وضعیت «لغو شده» ثبت شده است.',
                            ];
                        } else {
                            $validity = ['valid' => false, 'reason' => 'تراکنش کسر تومان مرتبط یافت نشد یا تکمیل نشده است.'];
                        }
                    } else {
                        $validity = ($linked_tx_obj && $linked_tx_obj->balance_type === 'toman' && (float) $linked_tx_obj->amount < 0 && $linked_tx_obj->status === 'completed')
                            ? ['valid' => true, 'reason' => 'پرداخت از کیف پول (کسر تومان، شناسه تراکنش: #' . $linked_tx_obj->id . ') با وضعیت «تکمیل شده» ثبت شده؛ دارایی در انتظار match معامله است.']
                            : ['valid' => false, 'reason' => 'تراکنش کسر تومان مرتبط یافت نشد یا تکمیل نشده است.'];
                    }
                }
            } elseif (!$skip_validity && $tx->operation_type === 'trade_sell') {
                if (!empty($tx->linked_tx_id)) {
                    $linked_tx_obj = $tm->get_transaction((int) $tx->linked_tx_id);
                }
                $amt = (float) $tx->amount;
                $returned_for_validity = null;
                if ($tx->status === 'canceled') {
                    $returned_for_validity = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}wallet_transactions
                         WHERE operation_type = 'returned' AND linked_tx_id = %d LIMIT 1",
                        (int) $tx->id
                    ));
                    if (!$returned_for_validity && !empty($tx->linked_tx_id)) {
                        $returned_for_validity = $wpdb->get_row($wpdb->prepare(
                            "SELECT * FROM {$wpdb->prefix}wallet_transactions
                             WHERE operation_type = 'returned' AND linked_tx_id = %d LIMIT 1",
                            (int) $tx->linked_tx_id
                        ));
                    }
                    if (!$returned_for_validity && !empty($tx->trade_tx_code)) {
                        $returned_for_validity = $wpdb->get_row($wpdb->prepare(
                            "SELECT * FROM {$wpdb->prefix}wallet_transactions
                             WHERE operation_type = 'returned' AND trade_tx_code = %s
                             ORDER BY id DESC LIMIT 1",
                            $tx->trade_tx_code
                        ));
                    }
                }
                if ($tx->balance_type !== 'toman' && $amt < 0) {
                    if ($tx->status === 'canceled') {
                        if ($linked_tx_obj && $returned_for_validity && $returned_for_validity->status === 'completed') {
                            $validity = [
                                'valid'  => true,
                                'reason' => 'کسر دارایی لغو شده است. تراکنش تومانی #' . (int) $linked_tx_obj->id
                                    . ' نیز لغو شده و بازگشت دارایی #' . (int) $returned_for_validity->id
                                    . ' با وضعیت «تکمیل شده» ثبت شده است.',
                            ];
                        } elseif ($linked_tx_obj) {
                            $validity = [
                                'valid'  => true,
                                'reason' => 'کسر دارایی لغو شده؛ تراکنش تومانی #' . (int) $linked_tx_obj->id
                                    . ' با وضعیت «' . wwp_label_status($linked_tx_obj->status) . '».',
                            ];
                        } else {
                            $validity = ['valid' => null, 'reason' => 'تراکنش تومانی مرتبط با کسر دارایی یافت نشد.'];
                        }
                    } else {
                        $validity = ($linked_tx_obj && $linked_tx_obj->balance_type === 'toman')
                            ? ['valid' => true, 'reason' => 'دارایی از کیف پول کسر شده؛ تراکنش تومانی #' . (int) $linked_tx_obj->id . ' با وضعیت «' . wwp_label_status($linked_tx_obj->status) . '».']
                            : ['valid' => null, 'reason' => 'تراکنش تومانی مرتبط با کسر دارایی یافت نشد.'];
                    }
                } elseif ($tx->balance_type === 'toman' && $amt > 0) {
                    if ($tx->status === 'canceled') {
                        $linked_canceled = $linked_tx_obj
                            && $linked_tx_obj->balance_type !== 'toman'
                            && (float) $linked_tx_obj->amount < 0
                            && $linked_tx_obj->status === 'canceled';
                        $ret_ok = $returned_for_validity && $returned_for_validity->status === 'completed';
                        if ($linked_canceled && $ret_ok) {
                            $validity = [
                                'valid'  => true,
                                'reason' => 'معامله لغو شده است. تراکنش کسر دارایی #' . (int) $linked_tx_obj->id
                                    . ' با وضعیت «لغو شده» و تراکنش بازگشت #' . (int) $returned_for_validity->id
                                    . ' با وضعیت «تکمیل شده» ثبت شده؛ مبلغ تومان تحویل داده نمی‌شود.',
                            ];
                        } elseif ($linked_canceled) {
                            $validity = [
                                'valid'  => true,
                                'reason' => 'معامله لغو شده؛ تراکنش کسر دارایی #' . (int) $linked_tx_obj->id . ' با وضعیت «لغو شده» ثبت شده است.',
                            ];
                        } else {
                            $validity = ['valid' => false, 'reason' => 'تراکنش کسر دارایی مرتبط هنوز تکمیل نشده است.'];
                        }
                    } else {
                        $validity = ($linked_tx_obj && $linked_tx_obj->balance_type !== 'toman' && (float) $linked_tx_obj->amount < 0 && $linked_tx_obj->status === 'completed')
                            ? ['valid' => true, 'reason' => 'کسر ' . wwp_label_balance_type($linked_tx_obj->balance_type) . ' (شناسه تراکنش: #' . $linked_tx_obj->id . ') با وضعیت «تکمیل شده» تأیید شده است.']
                            : ['valid' => false, 'reason' => 'تراکنش کسر دارایی مرتبط هنوز تکمیل نشده است.'];
                    }
                }
            } elseif (!$skip_validity && $tx->operation_type === 'returned') {
                if (!empty($tx->linked_tx_id)) {
                    $linked_tx_obj = $tm->get_transaction((int) $tx->linked_tx_id);
                }
                $trade_canceled = false;
                $peer_tx        = null;
                if (!empty($tx->trade_tx_code)) {
                    $tr_row = $wpdb->get_row($wpdb->prepare(
                        "SELECT status, wallet_asset_tx_id, wallet_toman_tx_id
                         FROM {$wpdb->prefix}medyar_trades
                         WHERE transaction_code = %s LIMIT 1",
                        $tx->trade_tx_code
                    ));
                    if ($tr_row && in_array((string) $tr_row->status, ['canceled', 'minor_canceled'], true)) {
                        $trade_canceled = true;
                    }
                    if ($linked_tx_obj && $tr_row) {
                        $peer_id = 0;
                        if ((int) $linked_tx_obj->id === (int) $tr_row->wallet_toman_tx_id) {
                            $peer_id = (int) $tr_row->wallet_asset_tx_id;
                        } elseif ((int) $linked_tx_obj->id === (int) $tr_row->wallet_asset_tx_id) {
                            $peer_id = (int) $tr_row->wallet_toman_tx_id;
                        }
                        if ($peer_id > 0) {
                            $peer_tx = $tm->get_transaction($peer_id);
                        }
                    }
                }
                $linked_canceled = $linked_tx_obj && $linked_tx_obj->status === 'canceled';
                $peer_canceled   = $peer_tx && $peer_tx->status === 'canceled';
                if ($linked_canceled && $peer_canceled) {
                    $validity = [
                        'valid'  => true,
                        'reason' => 'بازگشت وجه/دارایی معتبر است: تراکنش پرداخت/کسر #' . (int) $linked_tx_obj->id
                            . ' و تراکنش معامله #' . (int) $peer_tx->id
                            . ' هر دو لغو شده‌اند؛ مبلغ/دارایی به کیف پول بازگردانده شد.',
                    ];
                } elseif ($linked_canceled && $trade_canceled) {
                    $validity = [
                        'valid'  => true,
                        'reason' => 'بازگشت وجه/دارایی معتبر است: تراکنش مبدأ #' . (int) $linked_tx_obj->id
                            . ' لغو شده و معامله مرتبط نیز لغو شده است.',
                    ];
                } elseif ($linked_canceled) {
                    $validity = [
                        'valid'  => true,
                        'reason' => 'بازگشت به تراکنش لغو‌شده #' . (int) $linked_tx_obj->id . ' متصل است.',
                    ];
                } else {
                    $validity = ['valid' => null, 'reason' => 'تراکنش مبدأ لغو مرتبط با این بازگشت یافت نشد.'];
                }
            } elseif (!$skip_validity && $tx->operation_type === 'credit_grant') {
                if (!empty($tx->linked_tx_id)) {
                    $linked_tx_obj = $tm->get_transaction((int) $tx->linked_tx_id);
                }
                $validity = ($linked_tx_obj && in_array($linked_tx_obj->operation_type, ['spend', 'credit_collateral_freeze'], true))
                    ? ['valid' => true, 'reason' => 'وثیقه برای این اعتبار قفل شده و در سیستم ثبت است (تراکنش #' . (int) $linked_tx_obj->id . ').']
                    : ['valid' => null, 'reason' => 'تراکنش وثیقه مرتبط با این اعتبار پیدا نشد.'];
            } elseif (!$skip_validity && in_array($tx->operation_type, ['credit_collateral_freeze', 'spend'], true) && !empty($tx->linked_tx_id)) {
                $linked_tx_obj = $tm->get_transaction((int) $tx->linked_tx_id);
                if ($linked_tx_obj && $linked_tx_obj->operation_type === 'credit_grant') {
                    $validity = ['valid' => true, 'reason' => 'این مبلغ به‌عنوان وثیقه برای اعتبار #' . (int) $linked_tx_obj->id . ' قفل شده است.'];
                }
            } elseif (!$skip_validity && $tx->operation_type === 'credit_grant_cancel' && !empty($tx->linked_tx_id)) {
                $linked_tx_obj = $tm->get_transaction((int) $tx->linked_tx_id);
                $validity = ($linked_tx_obj && $linked_tx_obj->operation_type === 'credit_grant')
                    ? ['valid' => true, 'reason' => 'لغو اعتبار #' . (int) $linked_tx_obj->id . ' در سیستم ثبت شده است.']
                    : ['valid' => null, 'reason' => 'اعتبار مرتبط با این لغو پیدا نشد.'];
            } elseif (!$skip_validity && $tx->operation_type === 'credit_repay') {
                if (!empty($tx->linked_tx_id)) {
                    $linked_tx_obj = $tm->get_transaction((int) $tx->linked_tx_id);
                }
                $validity = ($linked_tx_obj && $linked_tx_obj->operation_type === 'credit_grant')
                    ? ['valid' => true, 'reason' => 'این پرداخت برای تسویه اعتبار #' . (int) $linked_tx_obj->id . ' ثبت شده است.']
                    : ['valid' => false, 'reason' => 'این پرداخت به هیچ اعتبار فعالی وصل نیست.'];
            } elseif (!$skip_validity && $tx->operation_type === 'credit_collateral_release' && !empty($tx->linked_tx_id)) {
                $linked_tx_obj = $tm->get_transaction((int) $tx->linked_tx_id);
                $validity = ($linked_tx_obj && $linked_tx_obj->operation_type === 'credit_grant')
                    ? ['valid' => true, 'reason' => 'وثیقه اعتبار #' . (int) $linked_tx_obj->id . ' آزاد شده است.']
                    : ['valid' => null, 'reason' => 'اعتبار مرتبط پیدا نشد.'];
            }

            $related_label = 'تراکنش مرتبط';
            if (in_array($tx->operation_type, ['buy_silver', 'charge'], true)) {
                $related_label = 'تراکنش پرداخت';
            } elseif ($tx->operation_type === 'sell_silver') {
                $related_label = 'تراکنش کسر دارایی';
            } elseif ($tx->operation_type === 'withdraw') {
                $related_label = 'تراکنش کسر موجودی';
            } elseif ($tx->operation_type === 'trade_buy') {
                $amt = (float) $tx->amount;
                if ($tx->balance_type === 'toman' && $amt < 0) {
                    $related_label = 'تراکنش دارایی معاملاتی';
                } elseif ($tx->balance_type !== 'toman') {
                    $related_label = 'تراکنش کسر تومان (کیف پول)';
                }
            } elseif ($tx->operation_type === 'trade_sell') {
                $amt = (float) $tx->amount;
                if ($tx->balance_type !== 'toman' && $amt < 0) {
                    $related_label = 'تراکنش تومانی معاملاتی';
                } elseif ($tx->balance_type === 'toman') {
                    $related_label = 'تراکنش کسر دارایی (کیف پول)';
                }
            } elseif ($tx->operation_type === 'returned') {
                $related_label = 'تراکنش کسر/پرداخت لغو‌شده';
            } elseif ($tx->operation_type === 'credit_grant') {
                $related_label = 'تراکنش وثیقه (فریز)';
            } elseif (in_array($tx->operation_type, ['credit_collateral_freeze', 'spend'], true) && !empty($tx->linked_tx_id)) {
                $linked_grant = $tm->get_transaction((int) $tx->linked_tx_id);
                if ($linked_grant && $linked_grant->operation_type === 'credit_grant') {
                    $related_label = 'تراکنش اعطای اعتبار';
                }
            } elseif (in_array($tx->operation_type, ['credit_repay', 'credit_grant_cancel', 'credit_collateral_release'], true) && !empty($tx->linked_tx_id)) {
                $linked_grant = $tm->get_transaction((int) $tx->linked_tx_id);
                if ($linked_grant && $linked_grant->operation_type === 'credit_grant') {
                    $related_label = 'تراکنش اعطای اعتبار (grant)';
                }
            }

            $returned_tx_obj = null;
            if ($tx->status === 'canceled') {
                $returned_tx_obj = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}wallet_transactions 
                     WHERE operation_type = 'returned' AND linked_tx_id = %d LIMIT 1",
                    $tx->id
                ));
                // دارایی/تومان در انتظار match: return به linked کسر وصل است
                if (!$returned_tx_obj && !empty($tx->linked_tx_id)) {
                    $returned_tx_obj = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}wallet_transactions 
                         WHERE operation_type = 'returned' AND linked_tx_id = %d LIMIT 1",
                        (int) $tx->linked_tx_id
                    ));
                }
                // دادهٔ قدیمی بدون linked_tx_id روی return
                if (!$returned_tx_obj && !empty($tx->trade_tx_code)) {
                    $returned_tx_obj = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}wallet_transactions 
                         WHERE operation_type = 'returned' AND trade_tx_code = %s
                         ORDER BY id DESC LIMIT 1",
                        $tx->trade_tx_code
                    ));
                }
            }

            $matched_trade_payload = null;
            $trade_row             = null;
            $trade_lineage         = null;
            if (!empty($tx->trade_tx_code)) {
                $tr_tbl    = $wpdb->prefix . 'medyar_trades';
                $trade_row = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$tr_tbl} WHERE transaction_code = %s LIMIT 1",
                    $tx->trade_tx_code
                ));
                if ($trade_row) {
                    $trade_lineage = wwp_build_trade_lineage_for_modal($trade_row);
                }
                if ($trade_row && !empty($trade_row->rel_trade_tx_code)) {
                    $mt = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$tr_tbl} WHERE transaction_code = %s LIMIT 1",
                        $trade_row->rel_trade_tx_code
                    ));
                    if ($mt) {
                        $mu    = get_user_by('id', (int) $mt->user_id);
                        $mname = '';
                        if ($mu) {
                            $mname = trim($mu->first_name . ' ' . $mu->last_name);
                            if ($mname === '') {
                                $mname = $mu->display_name;
                            }
                        }
                        $mob = '';
                        if (class_exists('Wallet_SMS')) {
                            $mob = Wallet_SMS::get_resolved_mobile((int) $mt->user_id);
                        }
                        $st_lbl = ($mt->status === 'pending')
                            ? 'ثبت در جدول'
                            : wwp_label_status($mt->status);
                        $matched_trade_payload = [
                            'id'               => (int) $mt->id,
                            'user_id'          => (int) $mt->user_id,
                            'user_display'     => $mname ?: 'نامشخص',
                            'user_admin_url'   => wwp_admin_user_insights_url((int) $mt->user_id),
                            'user_mobile'      => $mob,
                            'operation_type'   => $mt->operation_type,
                            'operation_label'  => wwp_label_trade_operation_plain($mt->operation_type),
                            'balance_type'     => $mt->balance_type,
                            'balance_label'    => wwp_label_balance_type($mt->balance_type),
                            'amount'           => (float) $mt->amount,
                            'amount_display'   => wwp_format_trade_amount((float) $mt->amount, $mt->balance_type),
                            'unit_price'       => (int) $mt->unit_price,
                            'total_price'      => (int) $mt->total_price,
                            'fee_amount'       => (int) $mt->fee_amount,
                            'status'           => $mt->status,
                            'status_label'     => $st_lbl,
                            'transaction_code' => $mt->transaction_code,
                            'created_at'       => date_i18n('Y/m/d H:i', strtotime($mt->created_at)),
                            'matched_at'       => !empty($mt->matched_at) ? date_i18n('Y/m/d H:i', strtotime($mt->matched_at)) : '',
                            'description'      => (string) $mt->description,
                        ];
                    }
                }
            }

            $trade_tx_code = isset($tx->trade_tx_code) ? (string) $tx->trade_tx_code : '';

            $linked_tx_arr  = $linked_tx_obj ? (array) $linked_tx_obj : null;
            $returned_tx_arr = $returned_tx_obj ? (array) $returned_tx_obj : null;
            if ($linked_tx_arr && isset($linked_tx_arr['description'])) {
                $linked_tx_arr['description'] = wwp_humanize_transaction_description((string) $linked_tx_arr['description']);
            }
            if ($returned_tx_arr && isset($returned_tx_arr['description'])) {
                $returned_tx_arr['description'] = wwp_humanize_transaction_description((string) $returned_tx_arr['description']);
            }

            // برای returned: تراکنش معاملهٔ همتا (مثلاً a وقتی linked=b) را هم نشان بده
            $peer_trade_tx_arr   = null;
            $peer_trade_label    = 'تراکنش معامله مرتبط';
            if ($tx->operation_type === 'returned' && $trade_row) {
                if (!$linked_tx_obj && !empty($tx->linked_tx_id)) {
                    $linked_tx_obj = $tm->get_transaction((int) $tx->linked_tx_id);
                    if ($linked_tx_obj) {
                        $linked_tx_arr = (array) $linked_tx_obj;
                        if (isset($linked_tx_arr['description'])) {
                            $linked_tx_arr['description'] = wwp_humanize_transaction_description((string) $linked_tx_arr['description']);
                        }
                    }
                }
                $peer_id = 0;
                if ($linked_tx_obj) {
                    if ((int) $linked_tx_obj->id === (int) ($trade_row->wallet_toman_tx_id ?? 0)) {
                        $peer_id = (int) ($trade_row->wallet_asset_tx_id ?? 0);
                    } elseif ((int) $linked_tx_obj->id === (int) ($trade_row->wallet_asset_tx_id ?? 0)) {
                        $peer_id = (int) ($trade_row->wallet_toman_tx_id ?? 0);
                    }
                }
                if ($peer_id <= 0) {
                    $peer_id = ($tx->balance_type === 'toman')
                        ? (int) ($trade_row->wallet_asset_tx_id ?? 0)
                        : (int) ($trade_row->wallet_toman_tx_id ?? 0);
                }
                if ($peer_id > 0 && $peer_id !== (int) ($tx->linked_tx_id ?? 0) && $peer_id !== (int) $tx->id) {
                    $peer_obj = $tm->get_transaction($peer_id);
                    if ($peer_obj) {
                        $peer_trade_tx_arr = (array) $peer_obj;
                        if (isset($peer_trade_tx_arr['description'])) {
                            $peer_trade_tx_arr['description'] = wwp_humanize_transaction_description((string) $peer_trade_tx_arr['description']);
                        }
                        if ($peer_obj->balance_type === 'toman') {
                            $peer_trade_label = 'تراکنش کسر تومان (لغو‌شده)';
                        } else {
                            $peer_trade_label = 'تراکنش معامله / دارایی (لغو‌شده)';
                        }
                    }
                }
            }

            $entry = [
                'id'                => (int) $tx->id,
                'user_id'           => (int) $tx->user_id,
                'user_name'         => $user ? $user->display_name : 'نامشخص',
                'user_email'        => $user ? $user->user_email : '',
                'balance_type'      => $tx->balance_type,
                'balance_label'     => wwp_label_balance_type($tx->balance_type),
                'operation_type'    => $tx->operation_type,
                'operation_label'   => wwp_label_operation_type($tx->operation_type),
                'amount'            => (float) $tx->amount,
                'status'            => $tx->status,
                'status_label'      => wwp_label_status($tx->status),
                'gateway'           => wwp_format_gateway($tx->p_info),
                'transaction_code'  => $tx->transaction_code,
                'trade_tx_code'     => $trade_tx_code,
                'description'       => wwp_humanize_transaction_description((string) ($tx->description ?? '')),
                'created_at'        => date_i18n('Y/m/d H:i', strtotime($tx->created_at)),
                'linked_tx_id'      => $tx->linked_tx_id,
                's_price'           => isset($tx->s_price) ? (float) $tx->s_price : null,
                'p_info'            => $p_info,
                'payment_record'    => $payment_record ? (array) $payment_record : null,
                'linked_tx'         => $linked_tx_arr,
                'returned_tx'       => $returned_tx_arr,
                'peer_trade_tx'     => $peer_trade_tx_arr,
                'peer_trade_label'  => $peer_trade_label,
                'related_label'     => $related_label,
                'validity'          => $validity,
                'skip_validity'     => $skip_validity,
                'matched_trade'     => $matched_trade_payload,
                'trade_lineage'     => $trade_lineage,
                'nonce'             => wp_create_nonce('wwp_modal_update_' . $tx->id),
                'ajax_nonce'        => wp_create_nonce('wwp_pnl_' . $tx->id),
                'credit_collateral_wallet_txs' => [],
                'credit_closure_wallet_txs'    => [],
            ];

            $tx_js_data[$tx->id] = wwp_enrich_credit_grant_modal_entry($entry);
        }

        return $tx_js_data;
    }
}
