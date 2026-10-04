<?php

/**
 * Wallet Manager Class
 * 
 * Manages Toman balance operations including charging, spending, and withdrawals
 * This is the core class for Toman wallet functionality
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

if (class_exists('Wallet_Manager')) { return; }

class Wallet_Manager
{

    private static $instance = null;
    private $transaction_manager;

    /**
     * Get singleton instance
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        $this->transaction_manager = Wallet_Transaction_Manager::get_instance();
    }

    /**
     * Get user Toman balance
     * 
     * @param int $user_id User ID
     * @return float Current Toman balance
     */
    public function get_balance($user_id)
    {
        $balance = get_user_meta($user_id, 'wallet_toman_balance', true);
        return intval($balance);
    }

    /**
     * Add Toman balance to user
     * 
     * @param int $user_id User ID
     * @param float $amount Amount to add
     * @param string $operation_type Operation type
     * @param array $transaction_data Additional transaction data
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function add_balance($user_id, $amount, $operation_type = 'charge', $transaction_data = array())
    {
        // Validate inputs
        $validations = array(
            wallet_validate_user_id($user_id),
            wallet_validate_amount($amount),
            wallet_validate_operation_type($operation_type)
        );

        $validation_result = wallet_check_validations($validations);
        if (is_wp_error($validation_result)) {
            return $validation_result;
        }

        global $wpdb;

        $transaction_info = array_merge(
            array(
                'user_id' => $user_id,
                'amount' => $amount,
                'balance_type' => 'toman',
                'operation_type' => $operation_type,
                'status' => 'pending'
            ),
            $transaction_data
        );

        // Ø§ÙØ²Ø§ÛŒØ´ Ù…ÙˆØ¬ÙˆØ¯ÛŒ Ùˆ Ø«Ø¨Øª ØªØ±Ø§Ú©Ù†Ø´ Ø¯Ø± ÛŒÚ© ÙˆØ§Ø­Ø¯ Ø§ØªÙ…ÛŒÚ© Ø¨Ø§ Ù‚ÙÙ„ Ø±Ø¯ÛŒÙ Ù…ÙˆØ¬ÙˆØ¯ÛŒ
        $wpdb->query('START TRANSACTION');

        try {
            $current_balance = wallet_locked_read_balance($user_id, 'toman');
            wallet_write_balance($user_id, 'toman', $current_balance + $amount);

            $transaction_id = $this->transaction_manager->create_transaction($transaction_info);

            if (is_wp_error($transaction_id)) {
                throw new Exception($transaction_id->get_error_message());
            }

            $wpdb->query('COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            wallet_flush_balance_cache($user_id);

            return new WP_Error('balance_add_failed', $e->getMessage());
        }

        // Fire action hook
        do_action('wallet_toman_balance_added', $user_id, $amount, $operation_type, $transaction_id);

        return true;
    }

    /**
     * Reduce Toman balance from user
     * 
     * @param int $user_id User ID
     * @param float $amount Amount to reduce
     * @param string $operation_type Operation type
     * @param array $transaction_data Additional transaction data
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function reduce_balance($user_id, $amount, $operation_type = 'spend', $transaction_data = array())
    {
        // Validate inputs
        $validations = array(
            wallet_validate_user_id($user_id),
            wallet_validate_amount($amount),
            wallet_validate_operation_type($operation_type),
            wallet_validate_sufficient_balance($user_id, $amount, 'toman')
        );

        $validation_result = wallet_check_validations($validations);
        if (is_wp_error($validation_result)) {
            return $validation_result;
        }

        global $wpdb;

        $transaction_info = array_merge(
            array(
                'user_id' => $user_id,
                'amount' => -$amount, // Negative for reduction
                'balance_type' => 'toman',
                'operation_type' => $operation_type,
                'status' => 'pending'
            ),
            $transaction_data
        );

        // Ú©Ø³Ø± Ù…ÙˆØ¬ÙˆØ¯ÛŒ Ùˆ Ø«Ø¨Øª ØªØ±Ø§Ú©Ù†Ø´ Ø¯Ø± ÛŒÚ© ÙˆØ§Ø­Ø¯ Ø§ØªÙ…ÛŒÚ© Ø¨Ø§ Ù‚ÙÙ„ Ø±Ø¯ÛŒÙ Ù…ÙˆØ¬ÙˆØ¯ÛŒ
        $wpdb->query('START TRANSACTION');

        try {
            $current_balance = wallet_locked_read_balance($user_id, 'toman');
            $new_balance     = $current_balance - $amount;

            if ($new_balance < 0) {
                throw new Exception(__('Ù…ÙˆØ¬ÙˆØ¯ÛŒ Ú©ÛŒÙ Ù¾ÙˆÙ„ Ø´Ù…Ø§ Ú©Ø§ÙÛŒ Ù†ÛŒØ³Øª.', 'medyar-toman-wallet'));
            }

            wallet_write_balance($user_id, 'toman', $new_balance);

            $transaction_id = $this->transaction_manager->create_transaction($transaction_info);

            if (is_wp_error($transaction_id)) {
                throw new Exception($transaction_id->get_error_message());
            }

            $wpdb->query('COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            wallet_flush_balance_cache($user_id);

            return new WP_Error('balance_reduce_failed', $e->getMessage());
        }

        // Fire action hook
        do_action('wallet_toman_balance_reduced', $user_id, $amount, $operation_type, $transaction_id);

        return true;
    }

    /**
     * Ø´Ø±ÙˆØ¹ Ø´Ø§Ø±Ú˜ Ú©ÛŒÙ Ù¾ÙˆÙ„ ØªÙˆÙ…Ø§Ù†ÛŒ Ø§Ø² Ø·Ø±ÛŒÙ‚ Ø¯Ø±Ú¯Ø§Ù‡ Ù¾Ø±Ø¯Ø§Ø®Øª (Ø²Ø±ÛŒÙ†â€ŒÙ¾Ø§Ù„)
     *
     * ÙÙ„Ùˆ:
     *   â‘  ØªØ±Ø§Ú©Ù†Ø´ charge Ø¨Ø§ status=pending Ø«Ø¨Øª Ù…ÛŒâ€ŒØ´ÙˆØ¯
     *   â‘¡ Ø¯Ø±Ø®ÙˆØ§Ø³Øª Ø¨Ù‡ Ø²Ø±ÛŒÙ†â€ŒÙ¾Ø§Ù„ â†’ authority
     *   â‘¢ Ø±Ú©ÙˆØ±Ø¯ Ù¾Ø±Ø¯Ø§Ø®Øª Ø¯Ø± wp_wallet_payment_transactions Ø«Ø¨Øª Ù…ÛŒâ€ŒØ´ÙˆØ¯
     *   â‘£ p_info ØªØ±Ø§Ú©Ù†Ø´ Ø¨Ù‡ Ø±Ú©ÙˆØ±Ø¯ Ù¾Ø±Ø¯Ø§Ø®Øª Ù„ÛŒÙ†Ú© Ù…ÛŒâ€ŒØ´ÙˆØ¯
     *   â‘¤ transient Ø°Ø®ÛŒØ±Ù‡ Ù…ÛŒâ€ŒØ´ÙˆØ¯
     *   â‘¥ redirect_url Ø¨Ø±Ù…ÛŒâ€ŒÚ¯Ø±Ø¯Ø¯
     *
     * Ù¾Ø³ Ø§Ø² callback Ù…ÙˆÙÙ‚:
     *   ØªØ±Ø§Ú©Ù†Ø´ â†’ completed Ùˆ ØªÙˆÙ…Ø§Ù† ÙÙˆØ±ÛŒ Ø¨Ù‡ Ù…ÙˆØ¬ÙˆØ¯ÛŒ Ø§Ø¶Ø§ÙÙ‡ Ù…ÛŒâ€ŒØ´ÙˆØ¯
     *   (Ù†ÛŒØ§Ø²ÛŒ Ø¨Ù‡ ØªØ£ÛŒÛŒØ¯ Ø§Ø¯Ù…ÛŒÙ† Ù†ÛŒØ³Øª)
     *
     * @param int    $user_id
     * @param int    $amount      Ù…Ø¨Ù„Øº Ø¨Ù‡ ØªÙˆÙ…Ø§Ù†
     * @param array  $user_info   mobile | email | card_number (Ø§Ù„Ø²Ø§Ù…ÛŒ)
     * @param string $gateway_id
     * @return array|WP_Error
     */
    public function initiate_charge_via_gateway(
        int    $user_id,
        int    $amount,
        array  $user_info  = [],
        string $gateway_id = 'zarinpal'
    ) {
        $check = wallet_check_validations([
            wallet_validate_user_id($user_id),
            wallet_validate_amount($amount),
        ]);
        if (is_wp_error($check)) {
            return $check;
        }

        $card_pan = Wallet_Zarinpal_Gateway::resolve_user_card_pan(
            $user_id,
            $user_info['card_number'] ?? ($user_info['card_pan'] ?? '')
        );
        if (is_wp_error($card_pan)) {
            return $card_pan;
        }

        $gateway = new Wallet_Zarinpal_Gateway();

        // â‘  Ø«Ø¨Øª ØªØ±Ø§Ú©Ù†Ø´ charge Ø¨Ø§ status=pending
        $tx_id = $this->transaction_manager->create_transaction([
            'user_id'        => $user_id,
            'amount'         => $amount,
            'balance_type'   => 'toman',
            'operation_type' => 'charge',
            'status'         => 'pending',
            'description'    => sprintf(
                'Ø´Ø§Ø±Ú˜ Ú©ÛŒÙ Ù¾ÙˆÙ„ ØªÙˆÙ…Ø§Ù†ÛŒ â€” Ù…Ø¨Ù„Øº %s ØªÙˆÙ…Ø§Ù† â€” Ø¯Ø± Ø§Ù†ØªØ¸Ø§Ø± Ù¾Ø±Ø¯Ø§Ø®Øª Ø§Ø² Ø¯Ø±Ú¯Ø§Ù‡',
                number_format($amount, 0)
            ),
        ]);

        if (is_wp_error($tx_id)) {
            return $tx_id;
        }

        $tx_row  = $this->transaction_manager->get_transaction($tx_id);
        $tx_code = $tx_row->transaction_code;

        // â‘¡ Ø¯Ø±Ø®ÙˆØ§Ø³Øª Ø¨Ù‡ Ø²Ø±ÛŒÙ†â€ŒÙ¾Ø§Ù„
        $user       = get_user_by('id', $user_id);
        $user_email = $user_info['email']  ?? ($user ? $user->user_email : '');
        $user_phone = $user_info['mobile'] ?? '';

        $result = $gateway->initiate_payment($amount, $user_id, [
            'purpose'     => 'charge',
            'description' => sprintf('Ø´Ø§Ø±Ú˜ Ú©ÛŒÙ Ù¾ÙˆÙ„ â€” %s ØªÙˆÙ…Ø§Ù†', number_format($amount, 0)),
            'mobile'      => $user_phone,
            'email'       => $user_email,
            'order_id'    => $tx_code,
            'card_pan'    => $card_pan,
        ]);

        if (!$result['success']) {
            $this->transaction_manager->update_status($tx_id, 'canceled');
            return new WP_Error('gateway_error', $result['error']);
        }

        $authority = $result['authority'];

        // â‘¢ Ø«Ø¨Øª Ø±Ú©ÙˆØ±Ø¯ Ù¾Ø±Ø¯Ø§Ø®Øª
        $pay_id = $this->transaction_manager->create_payment_record([
            'tx_id'     => $tx_id,
            'user_id'   => $user_id,
            'gateway'   => $gateway_id,
            'amount'    => $amount,
            'authority' => $authority,
            'status'    => 'pending',
        ]);

        if (is_wp_error($pay_id)) {
            $this->transaction_manager->update_status($tx_id, 'canceled');
            return $pay_id;
        }

        // â‘£ Ù„ÛŒÙ†Ú© p_info
        $this->transaction_manager->update_transaction($tx_id, [
            'p_info' => ['type' => 'Pay_gateway', 'id' => $pay_id],
        ]);

        // â‘¤ Ø°Ø®ÛŒØ±Ù‡ Ø¯Ø± transient
        Wallet_Payment_Handler::store_pending($authority, [
            'purpose'      => 'charge',
            'user_id'      => $user_id,
            'toman_amount' => $amount,
            'tx_id'        => $tx_id,
            'pay_id'       => $pay_id,
            'tx_code'      => $tx_code,
            'gateway_id'   => $gateway_id,
            'card_pan'     => $card_pan,
        ]);

        return [
            'success'      => true,
            'redirect_url' => $result['redirect_url'],
            'authority'    => $authority,
            'tx_id'        => $tx_id,
            'pay_id'       => $pay_id,
        ];
    }

    /**
     * Request withdrawal to bank account
     *
     * Ù…ÙˆØ¬ÙˆØ¯ÛŒ ØªÙˆÙ…Ø§Ù† Ú©Ø§Ù‡Ø´ Ù…ÛŒâ€ŒÛŒØ§Ø¨Ø¯ Ùˆ ØªØ±Ø§Ú©Ù†Ø´ Ø¯Ø± processing Ù…ÛŒâ€ŒÙ…Ø§Ù†Ø¯
     * ØªØ§ Ø§Ø¯Ù…ÛŒÙ† Ø¢Ù† Ø±Ø§ ØªØ£ÛŒÛŒØ¯ (completed) ÛŒØ§ Ø±Ø¯ (rejected) Ú©Ù†Ø¯
     *
     * @param int    $user_id
     * @param int    $amount
     * @param string $card_number   Ø´Ù…Ø§Ø±Ù‡ Ú©Ø§Ø±Øª Û±Û¶ Ø±Ù‚Ù…ÛŒ
     * @param string $iban          Ø´Ù…Ø§Ø±Ù‡ Ø´Ø¨Ø§ (IR + Û²Û´ Ø±Ù‚Ù…)
     * @param array  $bank_info     Ø§Ø·Ù„Ø§Ø¹Ø§Øª ØªÚ©Ù…ÛŒÙ„ÛŒ
     * @return true|WP_Error
     */
    public function request_withdrawal($user_id, $amount, $card_number, $iban = '', $bank_info = [])
    {
        global $wpdb;

        $check = wallet_check_validations([
            wallet_validate_user_id($user_id),
            wallet_validate_amount($amount),
            wallet_validate_sufficient_balance($user_id, $amount, 'toman'),
        ]);
        if (is_wp_error($check)) {
            return $check;
        }

        if (function_exists('medyar_verification_wallet_withdrawal_check')) {
            $verification_check = medyar_verification_wallet_withdrawal_check($user_id);
            if (is_wp_error($verification_check)) {
                return $verification_check;
            }
        }

        $amount = intval($amount);

        if (function_exists('medyar_wallet_get_max_withdraw')) {
            $max_withdraw = medyar_wallet_get_max_withdraw();
            if ($amount > $max_withdraw) {
                $msg = function_exists('medyar_wallet_max_withdraw_error_message')
                    ? medyar_wallet_max_withdraw_error_message($max_withdraw)
                    : sprintf('Ø­Ø¯Ø§Ú©Ø«Ø± Ù…Ø¨Ù„Øº Ø¨Ø±Ø¯Ø§Ø´Øª %s ØªÙˆÙ…Ø§Ù† Ø§Ø³Øª.', number_format_i18n($max_withdraw));
                return new WP_Error('withdraw_max_exceeded', $msg);
            }
        }

        if (empty($card_number) && empty($iban)) {
            return new WP_Error('missing_bank_info', 'Ø´Ù…Ø§Ø±Ù‡ Ú©Ø§Ø±Øª ÛŒØ§ Ø´Ù…Ø§Ø±Ù‡ Ø´Ø¨Ø§ Ø§Ù„Ø²Ø§Ù…ÛŒ Ø§Ø³Øª.');
        }

        if (function_exists('medyar_user_can_request_withdrawal') && !medyar_user_can_request_withdrawal($user_id)) {
            return new WP_Error(
                'withdraw_daily_limit',
                'Ø´Ù…Ø§ Ù…ÛŒâ€ŒØªÙˆØ§Ù†ÛŒØ¯ Ø­Ø¯Ø§Ú©Ø«Ø± Û² Ø¯Ø±Ø®ÙˆØ§Ø³Øª Ø¨Ø±Ø¯Ø§Ø´Øª ÙˆØ¬Ù‡ Ø¯Ø± Ù‡Ø± Û²Û´ Ø³Ø§Ø¹Øª Ø«Ø¨Øª Ú©Ù†ÛŒØ¯.'
            );
        }

        $card_number = sanitize_text_field($card_number);
        $iban        = strtoupper(sanitize_text_field($iban));

        // Ø§Ø¹ØªØ¨Ø§Ø±Ø³Ù†Ø¬ÛŒ Ø´Ø¨Ø§: IR + Û²Û´ Ø±Ù‚Ù…
        if (!empty($iban) && !preg_match('/^IR\d{24}$/', $iban)) {
            return new WP_Error('invalid_iban', 'Ø´Ù…Ø§Ø±Ù‡ Ø´Ø¨Ø§ Ù†Ø§Ù…Ø¹ØªØ¨Ø± Ø§Ø³Øª. ÙØ±Ù…Øª ØµØ­ÛŒØ­: IR + Û²Û´ Ø±Ù‚Ù…');
        }

        // Ø³Ø§Ø®Øª ØªÙˆØ¶ÛŒØ­Ø§Øª Ø§ÙˆÙ„ÛŒÙ‡ (Ø¨Ø¯ÙˆÙ† ØªØ§Ø±ÛŒØ®)
        $desc_parts = [sprintf('Ø¯Ø±Ø®ÙˆØ§Ø³Øª Ø¨Ø±Ø¯Ø§Ø´Øª %s ØªÙˆÙ…Ø§Ù†', number_format($amount, 0))];
        if (!empty($card_number)) $desc_parts[] = 'Ø´Ù…Ø§Ø±Ù‡ Ú©Ø§Ø±Øª: ' . $card_number;
        if (!empty($iban))        $desc_parts[] = 'Ø´Ø¨Ø§: ' . $iban;
        $desc_parts[] = 'Ø¯Ø± Ø§Ù†ØªØ¸Ø§Ø± ØªØ£ÛŒÛŒØ¯ Ø§Ø¯Ù…ÛŒÙ†';

        $wpdb->query('START TRANSACTION');

        try {
            // â‘  Ú©Ø§Ù‡Ø´ Ù…ÙˆØ¬ÙˆØ¯ÛŒ Ø¨Ø§ SELECT FOR UPDATE
            $current = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->usermeta}
                 WHERE user_id=%d AND meta_key='wallet_toman_balance' LIMIT 1 FOR UPDATE",
                $user_id
            ));

            $new_bal = $current - $amount;
            if ($new_bal < 0) {
                throw new Exception('Ù…ÙˆØ¬ÙˆØ¯ÛŒ Ú©Ø§ÙÛŒ Ù†ÛŒØ³Øª.');
            }
            update_user_meta($user_id, 'wallet_toman_balance', $new_bal);

            // â‘¡ Ø«Ø¨Øª ØªØ±Ø§Ú©Ù†Ø´ Ø¨Ø±Ø¯Ø§Ø´Øª Ø¨Ø§ status=processing
            $tx_id = $this->transaction_manager->create_transaction([
                'user_id'        => $user_id,
                'amount'         => -$amount,
                'balance_type'   => 'toman',
                'operation_type' => 'withdraw',
                'status'         => 'processing',
                'description'    => implode(' â€” ', $desc_parts),
                'p_info'         => [
                    'card_number' => $card_number,
                    'iban'        => $iban,
                ],
            ]);

            if (is_wp_error($tx_id)) {
                throw new Exception($tx_id->get_error_message());
            }

            $wpdb->query('COMMIT');

            error_log(sprintf(
                '[WalletWithdraw] user=%d amount=%d card=%s iban=%s tx=%d',
                $user_id,
                $amount,
                $card_number,
                $iban,
                $tx_id
            ));
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log('[WalletWithdraw] FAILED user=' . $user_id . ' error=' . $e->getMessage());
            return new WP_Error('withdraw_failed', $e->getMessage());
        }

        // Ø°Ø®ÛŒØ±Ù‡ Ø§Ø·Ù„Ø§Ø¹Ø§Øª Ø¨Ø§Ù†Ú©ÛŒ Ú©Ø§Ø±Ø¨Ø±
        $bank_info['card_number'] = $card_number;
        $bank_info['iban']        = $iban;
        update_user_meta($user_id, '_wallet_last_bank_info', $bank_info);


        $tx_row = $this->transaction_manager->get_transaction($tx_id);


        do_action('wallet_withdrawal_requested', $user_id, $amount, $bank_info, $tx_id);

        $result = [
            'sti' => $tx_id,
            'stc' => $tx_row->transaction_code,
        ];

        return $result;
    }
}
