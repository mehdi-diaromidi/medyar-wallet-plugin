<?php

/**
 * Transaction Manager
 *
 * مسئول تمام عملیات CRUD روی جدول wp_wallet_transactions
 * و جدول wp_wallet_payment_transactions
 */

if (!defined('ABSPATH')) {
    exit;
}

if (class_exists('Wallet_Transaction_Manager')) { return; }

class Wallet_Transaction_Manager
{
    private static $instance = null;
    private $tx_table;
    private $pay_table;

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        global $wpdb;
        $this->tx_table  = $wpdb->prefix . 'wallet_transactions';
        $this->pay_table = $wpdb->prefix . 'wallet_payment_transactions';
    }

    // ═══════════════════════════════════════════════════════════════════
    // wp_wallet_transactions
    // ═══════════════════════════════════════════════════════════════════

    public function create_transaction(array $data)
    {
        global $wpdb;

        foreach (['user_id', 'amount', 'balance_type', 'operation_type'] as $f) {
            if (!isset($data[$f])) {
                return new WP_Error('missing_field', "فیلد اجباری وجود ندارد: $f");
            }
        }

        $p_info = null;
        if (!empty($data['p_info']) && is_array($data['p_info'])) {
            $p_info = wp_json_encode($data['p_info']);
        }

        if (!empty($data['transaction_code'])) {
            $tx_code = sanitize_text_field($data['transaction_code']);
        } else {
            $tx_code = $this->generate_unique_code();
            if (is_wp_error($tx_code)) {
                return $tx_code;
            }
        }

        $trade_tx_code = null;
        if (!empty($data['trade_tx_code'])) {
            $trade_tx_code = substr(sanitize_text_field((string) $data['trade_tx_code']), 0, 32);
        }

        $row = [
            'user_id'          => intval($data['user_id']),
            'amount'           => floatval($data['amount']),
            'balance_type'     => wallet_sanitize_balance_type($data['balance_type']),
            'operation_type'   => wallet_sanitize_operation_type($data['operation_type']),
            'status'           => isset($data['status'])
                ? wallet_sanitize_status($data['status'])
                : 'pending',
            'transaction_code' => $tx_code,
            'trade_tx_code'    => $trade_tx_code,
            'description'      => isset($data['description'])
                ? sanitize_textarea_field($data['description'])
                : null,
            'p_info'           => $p_info,
            'linked_tx_id'     => !empty($data['linked_tx_id'])
                ? intval($data['linked_tx_id'])
                : null,
            's_price'          => !empty($data['s_price'])
                ? floatval($data['s_price'])
                : null,
            'created_at'       => current_time('mysql'),
        ];

        $link_check = $this->validate_credit_grant_child_link($row);
        if (is_wp_error($link_check)) {
            return $link_check;
        }

        $result = $wpdb->insert(
            $this->tx_table,
            $row,
            ['%d', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%f', '%s']
        );

        if ($result === false) {
            return new WP_Error('db_error', 'خطا در ثبت تراکنش: ' . $wpdb->last_error);
        }

        $new_id = $wpdb->insert_id;

        do_action('wallet_transaction_created', $new_id, $tx_code, $row['status'], '', $row);

        return $new_id;
    }

    public function update_status(int $tx_id, string $status): bool
    {
        global $wpdb;

        $tx = $this->get_transaction($tx_id);
        if (!$tx) {
            return false;
        }
        $prev_status = $tx->status;
        $new_status  = wallet_sanitize_status($status);

        $result = $wpdb->update(
            $this->tx_table,
            ['status' => $new_status],
            ['id'     => $tx_id],
            ['%s'],
            ['%d']
        ) !== false;

        if ($result) {
            do_action('wallet_transaction_status_changed', $tx_id, $tx->transaction_code, $new_status, $prev_status, $tx);
        }

        return $result;
    }

    public function update_transaction(int $tx_id, array $data)
    {
        global $wpdb;

        $prev_status = null;
        $tx          = null;
        if (array_key_exists('status', $data)) {
            $tx          = $this->get_transaction($tx_id);
            $prev_status = $tx ? $tx->status : null;
        }

        $update_data   = [];
        $update_format = [];

        if (array_key_exists('status', $data)) {
            $new_status = wallet_sanitize_status($data['status']);
            if ($prev_status === 'canceled' && $new_status === 'completed') {
                error_log(sprintf(
                    '[WalletTx] BLOCKED canceled→completed tx=%d',
                    $tx_id
                ));
                return false;
            }
            $update_data['status'] = $new_status;
            $update_format[]       = '%s';
        }

        if (array_key_exists('description', $data)) {
            $update_data['description'] = sanitize_textarea_field($data['description']);
            $update_format[]            = '%s';
        }

        if (array_key_exists('p_info', $data)) {
            $update_data['p_info'] = is_array($data['p_info'])
                ? wp_json_encode($data['p_info'])
                : null;
            $update_format[] = '%s';
        }

        if (array_key_exists('linked_tx_id', $data)) {
            $update_data['linked_tx_id'] = !empty($data['linked_tx_id'])
                ? intval($data['linked_tx_id'])
                : null;
            $update_format[] = '%d';
        }

        if (array_key_exists('s_price', $data)) {
            $update_data['s_price'] = !empty($data['s_price'])
                ? floatval($data['s_price'])
                : null;
            $update_format[] = '%f';
        }

        if (array_key_exists('trade_tx_code', $data)) {
            $update_data['trade_tx_code'] = !empty($data['trade_tx_code'])
                ? substr(sanitize_text_field((string) $data['trade_tx_code']), 0, 32)
                : null;
            $update_format[] = '%s';
        }

        if (array_key_exists('amount', $data)) {
            $update_data['amount'] = floatval($data['amount']);
            $update_format[]      = '%f';
        }

        if (empty($update_data)) {
            return false;
        }

        $result = $wpdb->update(
            $this->tx_table,
            $update_data,
            ['id' => $tx_id],
            $update_format,
            ['%d']
        );

        if ($result !== false && $prev_status !== null) {
            if (!$tx) {
                $tx = $this->get_transaction($tx_id);
            }
            $new_status = $update_data['status'] ?? ($tx ? $tx->status : '');

            do_action('wallet_transaction_status_changed', $tx_id, $tx->transaction_code ?? '', $new_status, $prev_status, $tx);
        }

        return $result !== false;
    }

    public function get_transaction(int $tx_id)
    {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->tx_table} WHERE id = %d", $tx_id)
        );
    }

    public function get_transactions_by_code(string $code, string $output = OBJECT)
    {
        global $wpdb;

        if (empty($code)) {
            return new WP_Error('empty_code', 'کد تراکنش خالی است');
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->tx_table} WHERE transaction_code = %s ORDER BY id ASC",
                sanitize_text_field($code)
            ),
            $output
        );

        if ($wpdb->last_error) {
            return new WP_Error('db_error', $wpdb->last_error);
        }

        if (empty($rows)) {
            return new WP_Error('not_found', 'تراکنش پیدا نشد');
        }

        return $rows;
    }

    public function get_transaction_by_code_and_type(string $code, string $balance_type, string $output = OBJECT)
    {
        global $wpdb;

        if (empty($code)) {
            return new WP_Error('empty_code', 'کد تراکنش خالی است');
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->tx_table}
                 WHERE transaction_code = %s AND balance_type = %s
                 LIMIT 1",
                sanitize_text_field($code),
                wallet_sanitize_balance_type($balance_type)
            ),
            $output
        );

        if ($wpdb->last_error) {
            return new WP_Error('db_error', $wpdb->last_error);
        }

        if (!$row) {
            return new WP_Error('not_found', 'تراکنش پیدا نشد');
        }

        return $row;
    }

    public function get_user_transactions(int $user_id, array $args = []): array
    {
        global $wpdb;

        $args = wp_parse_args($args, [
            'balance_type'   => null,
            'operation_type' => null,
            'status'         => null,
            'limit'          => 50,
            'offset'         => 0,
            'order'          => 'DESC',
        ]);

        $where = ["user_id = " . intval($user_id)];

        if ($args['balance_type']) {
            $where[] = $wpdb->prepare("balance_type = %s", wallet_sanitize_balance_type($args['balance_type']));
        }
        if ($args['operation_type']) {
            if (is_array($args['operation_type'])) {
                $types  = array_map('wallet_sanitize_operation_type', $args['operation_type']);
                $ph     = implode(',', array_fill(0, count($types), '%s'));
                $where[] = $wpdb->prepare("operation_type IN ($ph)", ...$types);
            } else {
                $where[] = $wpdb->prepare("operation_type = %s", wallet_sanitize_operation_type($args['operation_type']));
            }
        }
        if ($args['status']) {
            $where[] = $wpdb->prepare("status = %s", wallet_sanitize_status($args['status']));
        }

        $order  = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';
        $limit  = intval($args['limit']);
        $offset = intval($args['offset']);

        return $wpdb->get_results(
            "SELECT * FROM {$this->tx_table}
             WHERE " . implode(' AND ', $where) . "
             ORDER BY created_at $order
             LIMIT $limit OFFSET $offset"
        ) ?: [];
    }

    public function count_user_transactions(int $user_id, array $args = []): int
    {
        global $wpdb;

        $args = wp_parse_args($args, [
            'balance_type'   => null,
            'operation_type' => null,
            'status'         => null,
        ]);

        $where = ['user_id = ' . intval($user_id)];

        if ($args['balance_type']) {
            $where[] = $wpdb->prepare('balance_type = %s', wallet_sanitize_balance_type($args['balance_type']));
        }
        if ($args['operation_type']) {
            if (is_array($args['operation_type'])) {
                $types   = array_map('wallet_sanitize_operation_type', $args['operation_type']);
                $ph      = implode(',', array_fill(0, count($types), '%s'));
                $where[] = $wpdb->prepare("operation_type IN ($ph)", ...$types);
            } else {
                $where[] = $wpdb->prepare('operation_type = %s', wallet_sanitize_operation_type($args['operation_type']));
            }
        }
        if ($args['status']) {
            $where[] = $wpdb->prepare('status = %s', wallet_sanitize_status($args['status']));
        }

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->tx_table} WHERE " . implode(' AND ', $where)
        );
    }

    public function get_all_transactions(array $args = []): array
    {
        global $wpdb;

        $args = wp_parse_args($args, [
            'user_id'        => null,
            'balance_type'   => null,
            'operation_type' => null,
            'status'         => null,
            'limit'          => 100,
            'offset'         => 0,
            'order'          => 'DESC',
            'exclude_credit' => false,
        ]);

        $where = ['1=1'];

        if ($args['user_id']) {
            $where[] = $wpdb->prepare("user_id = %d", intval($args['user_id']));
        }
        if ($args['balance_type']) {
            $where[] = $wpdb->prepare("balance_type = %s", wallet_sanitize_balance_type($args['balance_type']));
        }
        if ($args['operation_type']) {
            $where[] = $wpdb->prepare("operation_type = %s", wallet_sanitize_operation_type($args['operation_type']));
        }
        if ($args['status']) {
            $where[] = $wpdb->prepare("status = %s", wallet_sanitize_status($args['status']));
        }
        if (!empty($args['exclude_credit']) && function_exists('wwp_credit_tx_exclude_condition')) {
            $where[] = wwp_credit_tx_exclude_condition();
        }

        $order  = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';
        $limit  = intval($args['limit']);
        $offset = intval($args['offset']);

        return $wpdb->get_results(
            "SELECT * FROM {$this->tx_table}
             WHERE " . implode(' AND ', $where) . "
             ORDER BY created_at $order
             LIMIT $limit OFFSET $offset"
        ) ?: [];
    }

    /**
     * FIX: وضعیت اولیه برداشت 'processing' است نه 'pending'
     */
    public function get_pending_withdraws(): array
    {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$this->tx_table}
             WHERE operation_type = 'withdraw' AND status = 'processing'
             ORDER BY created_at ASC"
        ) ?: [];
    }

    public function get_pending_deliveries(): array
    {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$this->tx_table}
             WHERE operation_type = 'delivery' AND status = 'pending'
             ORDER BY created_at ASC"
        ) ?: [];
    }

    public function count_transactions(array $args = []): int
    {
        global $wpdb;

        $where = ['1=1'];

        if (isset($args['user_id'])) {
            $where[] = $wpdb->prepare("user_id = %d", intval($args['user_id']));
        }
        if (isset($args['balance_type'])) {
            $where[] = $wpdb->prepare("balance_type = %s", wallet_sanitize_balance_type($args['balance_type']));
        }
        if (isset($args['status'])) {
            $where[] = $wpdb->prepare("status = %s", wallet_sanitize_status($args['status']));
        }

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->tx_table} WHERE " . implode(' AND ', $where)
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // wp_wallet_payment_transactions
    // ═══════════════════════════════════════════════════════════════════

    public function create_payment_record(array $data)
    {
        global $wpdb;

        foreach (['tx_id', 'user_id', 'gateway', 'amount'] as $f) {
            if (!isset($data[$f])) {
                return new WP_Error('missing_field', "فیلد اجباری وجود ندارد: $f");
            }
        }

        $row = [
            'tx_id'      => intval($data['tx_id']),
            'user_id'    => intval($data['user_id']),
            'gateway'    => sanitize_text_field($data['gateway']),
            'amount'     => intval($data['amount']),
            'authority'  => !empty($data['authority']) ? sanitize_text_field($data['authority']) : null,
            'status'     => !empty($data['status']) ? sanitize_text_field($data['status']) : 'pending',
            'created_at' => current_time('mysql'),
        ];

        $result = $wpdb->insert($this->pay_table, $row, ['%d', '%d', '%s', '%d', '%s', '%s', '%s']);

        if ($result === false) {
            return new WP_Error('db_error', 'خطا در ثبت رکورد پرداخت: ' . $wpdb->last_error);
        }

        return $wpdb->insert_id;
    }

    public function update_payment_record(int $pay_id, array $data)
    {
        global $wpdb;

        $update_data   = [];
        $update_format = [];

        $map = [
            'status'       => ['%s', 'sanitize_text_field'],
            'authority'    => ['%s', 'sanitize_text_field'],
            'card_pan'     => ['%s', 'sanitize_text_field'],
            'card_hash'    => ['%s', 'sanitize_text_field'],
            'fee_type'     => ['%s', 'sanitize_text_field'],
            'verified_at'  => ['%s', 'sanitize_text_field'],
            'fee'          => ['%d', 'intval'],
        ];

        foreach ($map as $field => [$fmt, $sanitizer]) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $update_data[$field] = $sanitizer($data[$field]);
                $update_format[]     = $fmt;
            }
        }

        if (!empty($data['reference_id'])) {
            $ref = sanitize_text_field($data['reference_id']);

            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->pay_table} WHERE reference_id = %s AND id != %d LIMIT 1",
                $ref,
                $pay_id
            ));

            if ($exists) {
                return new WP_Error('duplicate_ref', 'این شماره مرجع قبلاً ثبت شده است.');
            }

            $update_data['reference_id'] = $ref;
            $update_format[]             = '%s';
        }

        if (empty($update_data)) {
            return false;
        }

        $result = $wpdb->update($this->pay_table, $update_data, ['id' => $pay_id], $update_format, ['%d']);

        return $result !== false;
    }

    /**
     * تغییر وضعیت رکورد پرداخت به‌شرط وضعیت فعلی (compare-and-swap).
     *
     * @return bool true فقط اگر همین درخواست وضعیت را تغییر داده باشد
     */
    public function transition_payment_status(int $pay_id, string $from, string $to): bool
    {
        global $wpdb;

        $updated = $wpdb->update(
            $this->pay_table,
            ['status' => sanitize_text_field($to)],
            ['id' => $pay_id, 'status' => sanitize_text_field($from)],
            ['%s'],
            ['%d', '%s']
        );

        return $updated === 1;
    }

    /**
     * قفل منطقی رکورد پرداخت برای تسویه.
     *
     * تنها یک درخواست می‌تواند pending → processing را انجام دهد؛ بقیه false می‌گیرند.
     * جلوگیری از دو بار واریز شدن یک پرداخت وقتی callback همزمان/تکراری دریافت می‌شود.
     */
    public function claim_payment_for_processing(int $pay_id): bool
    {
        return $this->transition_payment_status($pay_id, 'pending', 'processing');
    }

    public function get_payment_record(int $pay_id)
    {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->pay_table} WHERE id = %d", $pay_id)
        );
    }

    public function get_payment_by_tx_id(int $tx_id)
    {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->pay_table} WHERE tx_id = %d LIMIT 1", $tx_id)
        );
    }

    public function get_payment_by_authority(string $authority)
    {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->pay_table} WHERE authority = %s LIMIT 1", sanitize_text_field($authority))
        );
    }

    public function is_reference_id_used(string $reference_id): bool
    {
        global $wpdb;
        return !empty($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->pay_table} WHERE reference_id = %s LIMIT 1",
            sanitize_text_field($reference_id)
        )));
    }

    // ═══════════════════════════════════════════════════════════════════
    // Cancel System
    // ═══════════════════════════════════════════════════════════════════

    /**
     * کنسل کردن یک تراکنش به همراه تمام تراکنش‌های مرتبط
     *
     * حالت‌های پوشش داده شده:
     *
     * ── buy_silver (تراکنش نقره) ────────────────────────────────────
     *  STAGE 2 لغو ادمین (status=processing):
     *    نقره هنوز اضافه نشده → فقط تومان برمی‌گردد + تراکنش returned ثبت می‌شود
     *  STAGE 3 لغو بعد از تأیید (status=completed):
     *    نقره قبلاً اضافه شده → نقره کم می‌شود + تومان برمی‌گردد + تراکنش returned ثبت می‌شود
     *
     * ── sell_silver (تراکنش تومان) ──────────────────────────────────
     *  STAGE 1 لغو ادمین (status=processing):
     *    تومان هنوز اضافه نشده → فقط نقره برمی‌گردد + تراکنش returned ثبت می‌شود
     *  STAGE 2 لغو بعد از تأیید (status=completed):
     *    تومان قبلاً اضافه شده → تومان کم می‌شود + نقره برمی‌گردد + تراکنش returned ثبت می‌شود
     *
     * ── withdraw ────────────────────────────────────────────────────
     *  STAGE 1 لغو ادمین (status=processing): تومان برمی‌گردد
     *  STAGE 2 لغو بعد از تأیید (status=completed): تومان برمی‌گردد
     *
     * @param int    $tx_id
     * @param string $reason
     * @param bool   $only_from_processing When true (user cancel), only processing rows may be canceled atomically.
     * @return true|WP_Error
     */
    public function cancel_transaction(int $tx_id, string $reason = '', bool $only_from_processing = false)
    {
        global $wpdb;

        $cancel_desc = $reason ?: 'تراکنش کنسل شد';

        $wpdb->query('START TRANSACTION');

        try {
            $tx = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$this->tx_table} WHERE id = %d FOR UPDATE",
                    $tx_id
                )
            );

            if (!$tx) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('not_found', "تراکنش $tx_id پیدا نشد.");
            }

            if ($tx->status === 'canceled') {
                $wpdb->query('ROLLBACK');
                return new WP_Error('already_canceled', "تراکنش $tx_id قبلاً کنسل شده است.");
            }

            if ($only_from_processing && $tx->status !== 'processing') {
                $wpdb->query('ROLLBACK');
                return new WP_Error(
                    'not_cancellable',
                    __('وضعیت این سفارش تغییر کرده و دیگر قابل لغو نیست.', 'medyar-toman-wallet')
                );
            }

            $user_id       = intval($tx->user_id);
            $op_type       = $tx->operation_type;
            $bal_type      = $tx->balance_type;
            $amount        = floatval($tx->amount);
            $prev_status   = (string) $tx->status;
            $was_completed = ($prev_status === 'completed');

            // H1: completed withdraws are never cancellable (no balance restore).
            if ($op_type === 'withdraw' && $was_completed) {
                $wpdb->query('ROLLBACK');
                return new WP_Error(
                    'withdraw_completed_not_cancellable',
                    __('درخواست برداشتِ تکمیل‌شده قابل لغو نیست.', 'medyar-toman-wallet')
                );
            }

            error_log(sprintf(
                '[WalletCancel] START tx=%d op=%s bal=%s status=%s was_completed=%s only_processing=%s reason=%s',
                $tx_id,
                $op_type,
                $bal_type,
                $prev_status,
                $was_completed ? 'yes' : 'no',
                $only_from_processing ? 'yes' : 'no',
                $reason
            ));

            // ── ① کنسل کردن تراکنش اصلی ───────────────────────────
            $now  = current_time('Y/m/d H:i');
            $desc = !empty($tx->description)
                ? $tx->description . "\n[{$now}] لغو شد. دلیل: {$cancel_desc}"
                : "لغو شد. دلیل: {$cancel_desc}";

            if ($only_from_processing) {
                $updated = $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$this->tx_table}
                         SET status = 'canceled', description = %s
                         WHERE id = %d AND status = 'processing'",
                        $desc,
                        $tx_id
                    )
                );
            } else {
                $updated = $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$this->tx_table}
                         SET status = 'canceled', description = %s
                         WHERE id = %d AND status != 'canceled'",
                        $desc,
                        $tx_id
                    )
                );
            }

            if ((int) $updated !== 1) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('already_canceled', "تراکنش $tx_id قبلاً کنسل شده است.");
            }

            do_action('wallet_transaction_status_changed', $tx_id, $tx->transaction_code, 'canceled', $prev_status, $tx);
            error_log("[WalletCancel] tx=$tx_id → canceled");

            // ── ② منطق بازگشت موجودی بر اساس operation_type ────────

            if ($op_type === 'buy_silver' && $bal_type === 'silver') {
                // ── خرید نقره ─────────────────────────────────────────
                // اگر completed: نقره قبلاً اضافه شده → باید کم شود
                if ($was_completed) {
                    $this->_deduct_balance_atomic($user_id, 'silver', abs($amount));
                    error_log("[WalletCancel] buy_silver was_completed → deducted silver=" . abs($amount) . " from user=$user_id");
                }

                // تومان را برمی‌گردانیم (از p_info مبلغ واقعی را می‌خوانیم)
                $refund_toman = $this->_get_buy_silver_refund_amount($tx);
                if ($refund_toman > 0) {
                    $this->_restore_balance_atomic($user_id, 'toman', $refund_toman);
                    $this->_create_returned_transaction($user_id, $refund_toman, 'toman', $tx_id, $cancel_desc);
                    error_log("[WalletCancel] buy_silver → returned toman=$refund_toman to user=$user_id");
                }

                // ⚠️ تراکنش تومان مرتبط (Wallet_gateway) → completed می‌ماند
                // کاربر پرداخت انجام داده؛ ما فقط به او تومان برگرداندیم، نه تراکنش را کنسل کردیم
                error_log("[WalletCancel] buy_silver → linked toman tx stays 'completed' (payment was made)");
            } elseif ($op_type === 'sell_silver' && $bal_type === 'toman') {
                // ── فروش نقره ─────────────────────────────────────────
                // اگر completed: تومان قبلاً اضافه شده → باید کم شود
                if ($was_completed) {
                    $this->_deduct_balance_atomic($user_id, 'toman', abs($amount));
                    error_log("[WalletCancel] sell_silver was_completed → deducted toman=" . abs($amount) . " from user=$user_id");
                }

                // نقره را برمی‌گردانیم
                $refund_silver = $this->_get_sell_silver_amount($tx);
                if ($refund_silver > 0) {
                    $this->_restore_balance_atomic($user_id, 'silver', $refund_silver);
                    $this->_create_returned_transaction($user_id, $refund_silver, 'silver', $tx_id, $cancel_desc);
                    error_log("[WalletCancel] sell_silver → returned silver=$refund_silver to user=$user_id");
                }

                // ⚠️ تراکنش نقره مرتبط → completed می‌ماند
                // کاربر نقره داده؛ ما فقط نقره را برگرداندیم، نه تراکنش را کنسل کردیم
                error_log("[WalletCancel] sell_silver → linked silver tx stays 'completed' (silver was given by user)");
            } elseif ($op_type === 'withdraw') {
                // فقط processing: completed بالاتر رد شده است
                $this->_restore_balance_atomic($user_id, 'toman', abs($amount));
                error_log("[WalletCancel] withdraw → restored toman=" . abs($amount) . " to user=$user_id");
            }

            $wpdb->query('COMMIT');
            error_log("[WalletCancel] SUCCESS tx=$tx_id");
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log("[WalletCancel] FAILED tx=$tx_id error=" . $e->getMessage());
            return new WP_Error('cancel_failed', 'کنسل کردن تراکنش ناموفق بود: ' . $e->getMessage());
        }

        do_action('wallet_transaction_canceled', $tx_id, $user_id, $reason);

        if ($op_type === 'buy_silver' && $bal_type === 'silver') {
            do_action('wallet_silver_buy_canceled', $user_id, $tx_id, $prev_status, $reason);
        }

        return true;
    }

    /**
     * تشخیص مبلغ تومانی که باید در لغو buy_silver برگردد
     */
    private function _get_buy_silver_refund_amount(object $tx): int
    {
        if (empty($tx->p_info)) {
            return 0;
        }

        $p_info = json_decode($tx->p_info, true);
        if (empty($p_info['type']) || empty($p_info['id'])) {
            return 0;
        }

        if ($p_info['type'] === 'Pay_gateway') {
            $pay = $this->get_payment_record(intval($p_info['id']));
            return $pay ? intval($pay->amount) : 0;
        }

        if ($p_info['type'] === 'Wallet_gateway') {
            // تراکنش تومانی که کاربر پرداخت کرده — مبلغ را می‌خوانیم
            $toman_tx = $this->get_transaction(intval($p_info['id']));
            return $toman_tx ? abs(intval($toman_tx->amount)) : 0;
        }

        return 0;
    }

    /**
     * تشخیص میزان نقره‌ای که باید در لغو sell_silver برگردد
     */
    private function _get_sell_silver_amount(object $tx): float
    {
        if (!empty($tx->linked_tx_id)) {
            $silver_tx = $this->get_transaction(intval($tx->linked_tx_id));
            if ($silver_tx && $silver_tx->balance_type === 'silver') {
                return abs(floatval($silver_tx->amount));
            }
        }

        if (!empty($tx->p_info)) {
            $p_info = json_decode($tx->p_info, true);
            if (!empty($p_info['id'])) {
                $silver_tx = $this->get_transaction(intval($p_info['id']));
                if ($silver_tx && $silver_tx->balance_type === 'silver') {
                    return abs(floatval($silver_tx->amount));
                }
            }
        }

        return 0.0;
    }

    /**
     * ثبت تراکنش بازگشت وجه (operation_type = returned)
     */
    private function _create_returned_transaction(
        int    $user_id,
        float  $amount,
        string $balance_type,
        int    $linked_to,
        string $reason
    ): void {
        $this->create_transaction([
            'user_id'        => $user_id,
            'amount'         => abs($amount),
            'balance_type'   => $balance_type,
            'operation_type' => 'returned',
            'status'         => 'completed',
            'description'    => "بازگشت وجه — مرتبط با تراکنش #$linked_to — $reason",
            'linked_tx_id'   => $linked_to,
        ]);
        error_log("[WalletCancel] returned tx created: user=$user_id bal=$balance_type amount=$amount linked_to=$linked_to");
    }

    /**
     * بازگشت موجودی با SELECT FOR UPDATE (atomic)
     */
    private function _restore_balance_atomic(int $user_id, string $type, float $amount): void
    {
        $current = wallet_locked_read_balance($user_id, $type);
        wallet_write_balance($user_id, $type, $current + $amount);

        error_log("[WalletCancel] restore user=$user_id type=$type amount=$amount");
    }

    /**
     * کسر موجودی با SELECT FOR UPDATE (atomic)
     */
    private function _deduct_balance_atomic(int $user_id, string $type, float $amount): void
    {
        $current = wallet_locked_read_balance($user_id, $type);
        if ($current + 0.0000001 < $amount) {
            throw new Exception(sprintf(
                /* translators: 1: balance type, 2: required amount, 3: available amount */
                __('لغو ممکن نیست: موجودی %1$s برای برگشت کافی نیست (مورد نیاز: %2$s — موجودی فعلی: %3$s).', 'medyar-toman-wallet'),
                function_exists('wwp_label_balance_type') ? wwp_label_balance_type($type) : $type,
                wallet_format_balance_for_message($amount, $type),
                wallet_format_balance_for_message($current, $type)
            ));
        }
        wallet_write_balance($user_id, $type, $current - $amount);

        error_log("[WalletCancel] deduct user=$user_id type=$type amount=$amount");
    }
    // ═══════════════════════════════════════════════════════════════════

    private function generate_unique_code(int $max_attempts = 5)
    {
        global $wpdb;

        for ($i = 0; $i < $max_attempts; $i++) {
            $code   = wallet_generate_transaction_code();
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->tx_table} WHERE transaction_code = %s LIMIT 1",
                $code
            ));

            if (!$exists) {
                return $code;
            }
        }

        return new WP_Error('code_generation_failed', 'تولید کد یکتا پس از چند تلاش ناموفق بود.');
    }

    /**
     * credit_repay و credit_grant_cancel باید به تراکنش credit_grant همان کاربر لینک شوند.
     *
     * @param array<string, mixed> $row
     * @return true|WP_Error
     */
    private function validate_credit_grant_child_link(array $row)
    {
        $op = (string) ($row['operation_type'] ?? '');
        if (!in_array($op, ['credit_repay', 'credit_grant_cancel'], true)) {
            return true;
        }

        $linked_id = (int) ($row['linked_tx_id'] ?? 0);
        if ($linked_id <= 0) {
            return new WP_Error(
                'missing_grant_link',
                'تراکنش ' . $op . ' باید linked_tx_id برابر شناسه credit_grant داشته باشد.'
            );
        }

        $grant = $this->get_transaction($linked_id);
        if (!$grant || $grant->operation_type !== 'credit_grant') {
            return new WP_Error(
                'invalid_grant_link',
                'linked_tx_id برای ' . $op . ' باید به تراکنش credit_grant اشاره کند.'
            );
        }

        if ((int) $grant->user_id !== (int) $row['user_id']) {
            return new WP_Error(
                'grant_user_mismatch',
                'grant مرتبط متعلق به همان کاربر تراکنش ' . $op . ' نیست.'
            );
        }

        return true;
    }

    /**
     * @deprecated از wallet_generate_transaction_code() در security.php استفاده کنید
     */
    public static function generate_random_code(int $length = 8): string
    {
        return wallet_generate_transaction_code();
    }
}
