<?php
/**
 * Zarinpal callback — wallet charge only.
 */
if (!defined('ABSPATH')) {
    exit;
}

if (class_exists('Wallet_Payment_Handler')) { return; }

class Wallet_Payment_Handler
{
    private static $instance = null;

    const TRANSIENT_PREFIX = 'wallet_payment_pending_';
    const TRANSIENT_EXPIRY = 3600;
    const CALLBACK_SLUG    = 'compelete-transactions';
    const RESULT_PAGE_URL  = '/my-account/compelete-transactions/';

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('init', [$this, 'handle_callback'], 1);
    }

    public function handle_callback()
    {
        $request_path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
        if (strpos($request_path, self::CALLBACK_SLUG) === false || empty($_GET['Authority'])) {
            return;
        }

        $authority    = sanitize_text_field($_GET['Authority']);
        $status       = isset($_GET['Status']) ? sanitize_text_field($_GET['Status']) : '';
        $result_url   = home_url(self::RESULT_PAGE_URL);
        $fallback_url = function_exists('wc_get_account_endpoint_url')
            ? wc_get_account_endpoint_url('wallet')
            : home_url('/my-account/wallet/');

        $transient_key = self::TRANSIENT_PREFIX . $authority;
        $pending       = get_transient($transient_key);
        if (!$pending) {
            wp_redirect($fallback_url);
            exit;
        }

        $user_id = (int) ($pending['user_id'] ?? 0);
        $tx_id   = (int) ($pending['tx_id'] ?? 0);
        $pay_id  = (int) ($pending['pay_id'] ?? 0);
        $tx_code = $pending['tx_code'] ?? '';
        $unq     = $tx_id . '-' . $tx_code;

        $tx_manager = Wallet_Transaction_Manager::get_instance();
        $pay        = $pay_id > 0 ? $tx_manager->get_payment_record($pay_id) : $tx_manager->get_payment_by_authority($authority);

        if (
            !$pay
            || (int) $pay->user_id !== $user_id
            || (string) ($pay->authority ?? '') !== $authority
            || !in_array((string) $pay->status, ['pending', 'processing'], true)
        ) {
            delete_transient($transient_key);
            wp_redirect($fallback_url);
            exit;
        }

        $pay_id       = (int) $pay->id;
        $toman_amount = (int) $pay->amount;
        if ($toman_amount <= 0) {
            delete_transient($transient_key);
            wp_redirect($fallback_url);
            exit;
        }

        if ($status !== 'OK') {
            if ($tx_manager->transition_payment_status($pay_id, 'pending', 'canceled')) {
                $tx_manager->update_status($tx_id, 'canceled');
            }
            delete_transient($transient_key);
            wp_redirect(add_query_arg('unq', $unq, $result_url));
            exit;
        }

        $gateway = new Wallet_Zarinpal_Gateway();
        $verify  = $gateway->verify_payment([
            'authority' => $authority,
            'amount'    => $toman_amount,
            'status'    => $status,
        ]);

        if (!$verify['success']) {
            if ($tx_manager->transition_payment_status($pay_id, 'pending', 'canceled')) {
                $tx_manager->update_status($tx_id, 'canceled');
            }
            delete_transient($transient_key);
            wp_redirect(add_query_arg('unq', $unq, $result_url));
            exit;
        }

        $ref_id   = (string) $verify['ref_id'];
        $card_pan = $verify['card_pan'] ?? '';

        if (!empty($verify['already_verified'])) {
            delete_transient($transient_key);
            wp_redirect(add_query_arg('unq', $unq, $result_url));
            exit;
        }

        $expected_card = preg_replace('/\D/', '', (string) ($pending['card_pan'] ?? ''));
        if (
            $expected_card !== ''
            && !Wallet_Zarinpal_Gateway::masked_card_matches($expected_card, (string) $card_pan)
        ) {
            if ($tx_manager->transition_payment_status($pay_id, 'pending', 'canceled')) {
                $tx_manager->update_status($tx_id, 'canceled');
            }
            $tx_manager->update_payment_record($pay_id, [
                'reference_id' => $ref_id,
                'card_pan'     => $card_pan,
                'card_hash'    => $verify['card_hash'] ?? '',
            ]);
            delete_transient($transient_key);
            wp_redirect(add_query_arg('unq', $unq, $result_url));
            exit;
        }

        if ($tx_manager->is_reference_id_used($ref_id)) {
            delete_transient($transient_key);
            wp_redirect(add_query_arg('unq', $unq, $result_url));
            exit;
        }

        if (!$tx_manager->claim_payment_for_processing($pay_id)) {
            delete_transient($transient_key);
            wp_redirect(add_query_arg('unq', $unq, $result_url));
            exit;
        }

        $this->complete_charge($tx_id, $pay_id, $user_id, $toman_amount, $ref_id, $card_pan, $verify);
        delete_transient($transient_key);
        wp_redirect(add_query_arg('unq', $unq, $result_url));
        exit;
    }

    private function complete_charge(
        int $tx_id,
        int $pay_id,
        int $user_id,
        int $toman_amount,
        string $ref_id,
        string $card_pan,
        array $verify
    ) {
        $tx_manager = Wallet_Transaction_Manager::get_instance();
        $new_balance = wallet_atomic_adjust_balance($user_id, 'toman', (float) $toman_amount);

        if (is_wp_error($new_balance)) {
            $tx_manager->update_transaction($tx_id, [
                'status'      => 'pending',
                'description' => 'خطا در واریز شارژ — نیازمند بررسی دستی (ref: ' . $ref_id . ')',
            ]);
            $tx_manager->update_payment_record($pay_id, [
                'reference_id' => $ref_id,
                'card_pan'     => $card_pan,
                'status'       => 'pending',
                'verified_at'  => current_time('mysql'),
            ]);
            return false;
        }

        $tx_manager->update_transaction($tx_id, [
            'status'      => 'completed',
            'description' => sprintf(
                'شارژ کیف پول — %s تومان اضافه شد (ref: %s)',
                number_format($toman_amount, 0),
                $ref_id
            ),
        ]);

        $tx_manager->update_payment_record($pay_id, [
            'reference_id' => $ref_id,
            'card_pan'     => $card_pan,
            'card_hash'    => $verify['card_hash'] ?? '',
            'fee'          => $verify['fee'] ?? 0,
            'fee_type'     => $verify['fee_type'] ?? '',
            'status'       => 'completed',
            'verified_at'  => current_time('mysql'),
        ]);

        do_action('wallet_charge_completed', $user_id, $toman_amount, $tx_id, $ref_id);
        return true;
    }

    public static function store_pending(string $authority, array $data): void
    {
        set_transient(self::TRANSIENT_PREFIX . $authority, array_merge($data, ['created_at' => time()]), self::TRANSIENT_EXPIRY);
    }
}

add_action('plugins_loaded', function () {
    Wallet_Payment_Handler::get_instance();
}, 25);
