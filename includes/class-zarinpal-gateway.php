<?php

/**
 * ZarinPal Payment Gateway
 *
 * پیاده‌سازی درگاه زرین‌پال طبق مستندات رسمی:
 * https://www.zarinpal.com/docs/paymentGateway/
 *
 * توجه: لوگوی پذیرنده روی صفحه پرداخت از طریق API ارسال نمی‌شود؛
 * باید در پنل زرین‌پال → تنظیمات درگاه آپلود شود.
 * محدودیت کارت پرداخت‌کننده با metadata.card_pan اعمال می‌شود.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (class_exists('Wallet_Zarinpal_Gateway')) { return; }

class Wallet_Zarinpal_Gateway extends Wallet_Payment_Gateway
{
    const REQUEST_URL  = 'https://payment.zarinpal.com/pg/v4/payment/request.json';
    const VERIFY_URL   = 'https://payment.zarinpal.com/pg/v4/payment/verify.json';
    const INQUIRY_URL  = 'https://payment.zarinpal.com/pg/v4/payment/inquiry.json';
    const STARTPAY_URL = 'https://payment.zarinpal.com/pg/StartPay/';

    const HTTP_TIMEOUT = 30;

    /** @var string */
    private $merchant_id;

    public function __construct()
    {
        $this->gateway_id   = 'zarinpal';
        $this->gateway_name = 'زرین‌پال';
        $this->enabled      = true;

        $settings = get_option('woocommerce_WC_ZPal_settings', []);
        $this->merchant_id = isset($settings['merchantcode'])
            ? sanitize_text_field((string) $settings['merchantcode'])
            : '';
    }

    /**
     * نرمال‌سازی و اعتبارسنجی شماره کارت ثبت‌شده کاربر.
     *
     * @param int    $user_id
     * @param string $card_number
     * @return string|WP_Error شماره کارت ۱۶ رقمی
     */
    public static function resolve_user_card_pan(int $user_id, $card_number)
    {
        $card_number = preg_replace('/\D/', '', (string) $card_number);
        $cards       = function_exists('medyar_get_user_bank_cards')
            ? medyar_get_user_bank_cards($user_id)
            : [];

        if (!is_array($cards)) {
            $cards = [];
        }

        if ($card_number === '' && count($cards) === 1) {
            $card_number = (string) array_key_first($cards);
            $card_number = preg_replace('/\D/', '', $card_number);
        }

        if (strlen($card_number) !== 16) {
            return new WP_Error('invalid_card', 'شماره کارت بانکی معتبر (۱۶ رقم) الزامی است.');
        }

        $normalized = [];
        foreach ($cards as $key => $data) {
            $digits = preg_replace('/\D/', '', (string) $key);
            if ($digits !== '') {
                $normalized[$digits] = $data;
            }
        }

        if (!isset($normalized[$card_number])) {
            return new WP_Error('card_not_owned', 'شماره کارت انتخاب‌شده در حساب شما ثبت نشده است.');
        }

        return $card_number;
    }

    /**
     * تطبیق شماره کارت کامل با card_pan ماسک‌شده زرین‌پال (۶ رقم اول + ۴ رقم آخر).
     */
    public static function masked_card_matches(string $full_pan, string $masked_pan): bool
    {
        $full_pan   = preg_replace('/\D/', '', $full_pan);
        $masked_pan = (string) $masked_pan;

        if (strlen($full_pan) !== 16 || $masked_pan === '') {
            return false;
        }

        // نمونه: 502229******5995
        if (!preg_match('/^(\d{6})\*+(\d{4})$/', $masked_pan, $m)) {
            $digits = preg_replace('/\D/', '', $masked_pan);
            if (strlen($digits) < 10) {
                return false;
            }
            $prefix = substr($digits, 0, 6);
            $suffix = substr($digits, -4);
            return substr($full_pan, 0, 6) === $prefix && substr($full_pan, -4) === $suffix;
        }

        return substr($full_pan, 0, 6) === $m[1] && substr($full_pan, -4) === $m[2];
    }

    /**
     * ماسک شماره کارت برای لاگ (بدون افشای کامل).
     */
    public static function mask_card_for_log(string $card_pan): string
    {
        $card_pan = preg_replace('/\D/', '', $card_pan);
        if (strlen($card_pan) !== 16) {
            return '****';
        }
        return substr($card_pan, 0, 6) . '******' . substr($card_pan, -4);
    }

    /**
     * ارسال درخواست پرداخت به زرین‌پال و دریافت authority.
     *
     * @param float $amount          مبلغ به تومان (currency=IRT)
     * @param int   $user_id         شناسه کاربر
     * @param array $additional_data شامل card_pan (الزامی)، description، mobile، email، order_id، purpose
     * @return array
     */
    public function initiate_payment($amount, $user_id, $additional_data = [])
    {
        $amount = (int) $this->sanitize_amount($amount);

        if ($this->merchant_id === '') {
            $this->log('Merchant ID is not set.', 'error');
            return ['success' => false, 'error' => 'کد مرچنت زرین‌پال تنظیم نشده است.'];
        }

        if ($amount <= 0) {
            return ['success' => false, 'error' => 'مبلغ نامعتبر است.'];
        }

        $card_pan = preg_replace('/\D/', '', (string) ($additional_data['card_pan'] ?? ''));
        if (strlen($card_pan) !== 16) {
            return ['success' => false, 'error' => 'شماره کارت بانکی معتبر (۱۶ رقم) برای پرداخت الزامی است.'];
        }

        $description = isset($additional_data['description'])
            ? (string) $additional_data['description']
            : 'شارژ کیف پول';
        $description = trim($description);
        if ($description === '') {
            return ['success' => false, 'error' => 'توضیحات تراکنش الزامی است.'];
        }
        if (function_exists('mb_substr')) {
            $description = mb_substr($description, 0, 500);
        } else {
            $description = substr($description, 0, 500);
        }

        $callback_url = $this->get_callback_url();

        $metadata = [
            'card_pan'    => $card_pan,
            'auto_verify' => false,
        ];

        if (!empty($additional_data['mobile'])) {
            $metadata['mobile'] = sanitize_text_field((string) $additional_data['mobile']);
        }
        if (!empty($additional_data['email'])) {
            $metadata['email'] = sanitize_email((string) $additional_data['email']);
        }
        if (!empty($additional_data['order_id'])) {
            $metadata['order_id'] = sanitize_text_field((string) $additional_data['order_id']);
        }

        $payload = [
            'merchant_id'  => $this->merchant_id,
            'amount'       => $amount,
            'currency'     => 'IRT',
            'callback_url' => $callback_url,
            'description'  => $description,
            'metadata'     => $metadata,
        ];

        $response = $this->post_json(self::REQUEST_URL, $payload);

        if (is_wp_error($response)) {
            $this->log('Request failed: ' . $response->get_error_message(), 'error');
            return ['success' => false, 'error' => 'خطا در اتصال به زرین‌پال: ' . $response->get_error_message()];
        }

        $data = $response;
        $code = isset($data['data']['code']) ? (int) $data['data']['code'] : 0;
        $authority = isset($data['data']['authority']) ? (string) $data['data']['authority'] : '';

        if ($code !== 100 || $authority === '') {
            $error_code = $this->extract_error_code($data);
            $this->log(
                'ZarinPal request error. Code: ' . $error_code
                . ' Card: ' . self::mask_card_for_log($card_pan)
                . ' User: ' . (int) $user_id,
                'error'
            );
            return [
                'success' => false,
                'error'   => $this->map_error_message($error_code),
                'code'    => $error_code,
            ];
        }

        $this->log(
            'Payment initiated. Authority: ' . $authority
            . ', Amount: ' . $amount
            . ', User: ' . (int) $user_id
            . ', Card: ' . self::mask_card_for_log($card_pan)
        );

        return [
            'success'        => true,
            'authority'      => $authority,
            'transaction_id' => $authority,
            'redirect_url'   => self::STARTPAY_URL . $authority,
            'card_pan'       => $card_pan,
            'message'        => 'درخواست پرداخت با موفقیت ارسال شد.',
        ];
    }

    /**
     * تأیید پرداخت پس از بازگشت از درگاه (Status=OK).
     *
     * @param array $callback_data authority | amount (IRT) | status
     * @return array
     */
    public function verify_payment($callback_data)
    {
        $authority = isset($callback_data['authority']) ? sanitize_text_field((string) $callback_data['authority']) : '';
        $status    = isset($callback_data['status']) ? sanitize_text_field((string) $callback_data['status']) : '';
        $amount    = isset($callback_data['amount']) ? (int) $callback_data['amount'] : 0;

        if ($status !== 'OK') {
            $this->log('Payment cancelled or failed. Authority: ' . $authority, 'warning');
            return ['success' => false, 'error' => 'پرداخت توسط کاربر لغو شد یا ناموفق بود.'];
        }

        if ($authority === '' || $amount <= 0) {
            return ['success' => false, 'error' => 'اطلاعات بازگشتی از درگاه ناقص است.'];
        }

        if ($this->merchant_id === '') {
            return ['success' => false, 'error' => 'کد مرچنت زرین‌پال تنظیم نشده است.'];
        }

        $response = $this->post_json(self::VERIFY_URL, [
            'merchant_id' => $this->merchant_id,
            'amount'      => $amount,
            'authority'   => $authority,
        ]);

        if (is_wp_error($response)) {
            $this->log('Verify request failed: ' . $response->get_error_message(), 'error');
            return ['success' => false, 'error' => 'خطا در تأیید پرداخت با زرین‌پال.'];
        }

        $data = $response;
        $code = isset($data['data']['code']) ? (int) $data['data']['code'] : $this->extract_error_code($data);

        // 100 = موفق، 101 = قبلاً verify شده
        if ($code === 100 || $code === 101) {
            $ref_id    = isset($data['data']['ref_id']) ? $data['data']['ref_id'] : '';
            $card_pan  = isset($data['data']['card_pan']) ? (string) $data['data']['card_pan'] : '';
            $card_hash = isset($data['data']['card_hash']) ? (string) $data['data']['card_hash'] : '';
            $fee       = isset($data['data']['fee']) ? (int) $data['data']['fee'] : 0;
            $fee_type  = isset($data['data']['fee_type']) ? (string) $data['data']['fee_type'] : '';

            $this->log('Payment verified. Ref ID: ' . $ref_id . ', Authority: ' . $authority . ', Code: ' . $code);

            return [
                'success'          => true,
                'already_verified' => ($code === 101),
                'amount'           => $amount,
                'ref_id'           => $ref_id,
                'transaction_code' => (string) $ref_id,
                'card_pan'         => $card_pan,
                'card_hash'        => $card_hash,
                'fee'              => $fee,
                'fee_type'         => $fee_type,
                'authority'        => $authority,
                'message'          => ($code === 101)
                    ? 'این تراکنش قبلاً تأیید شده است.'
                    : 'پرداخت با موفقیت تأیید شد.',
            ];
        }

        $this->log('Verify failed. Code: ' . $code . ', Authority: ' . $authority, 'error');
        return [
            'success' => false,
            'error'   => $this->map_error_message($code),
            'code'    => $code,
        ];
    }

    /**
     * استعلام وضعیت تراکنش (بدون verify) — inquiry.json
     *
     * @return array{success:bool,status?:string,code?:int|string,message?:string,error?:string}
     */
    public function inquiry_payment(string $authority)
    {
        $authority = sanitize_text_field($authority);

        if ($this->merchant_id === '' || $authority === '') {
            return ['success' => false, 'error' => 'اطلاعات استعلام ناقص است.'];
        }

        $response = $this->post_json(self::INQUIRY_URL, [
            'merchant_id' => $this->merchant_id,
            'authority'   => $authority,
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => $response->get_error_message()];
        }

        $code = isset($response['data']['code']) ? (int) $response['data']['code'] : $this->extract_error_code($response);
        if ($code !== 100) {
            return [
                'success' => false,
                'code'    => $code,
                'error'   => $this->map_error_message($code),
            ];
        }

        return [
            'success' => true,
            'status'  => isset($response['data']['status']) ? (string) $response['data']['status'] : '',
            'code'    => $code,
            'message' => isset($response['data']['message']) ? (string) $response['data']['message'] : 'Success',
        ];
    }

    /**
     * callback_url ثابت؛ شناسه‌های تراکنش از طریق transient منتقل می‌شوند.
     */
    protected function get_callback_url($params = [])
    {
        return home_url('/my-account/compelete-transactions/');
    }

    /**
     * @param string $url
     * @param array  $payload
     * @return array|WP_Error
     */
    private function post_json(string $url, array $payload)
    {
        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'body'    => wp_json_encode($payload),
            'timeout' => self::HTTP_TIMEOUT,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data)) {
            return new WP_Error('invalid_json', 'پاسخ نامعتبر از زرین‌پال دریافت شد.');
        }

        return $data;
    }

    /**
     * استخراج کد خطا از پاسخ زرین‌پال (اشکال مختلف errors).
     *
     * @param array $data
     * @return int|string
     */
    private function extract_error_code(array $data)
    {
        if (isset($data['errors']['code'])) {
            return is_numeric($data['errors']['code'])
                ? (int) $data['errors']['code']
                : $data['errors']['code'];
        }

        if (isset($data['errors']) && is_array($data['errors'])) {
            foreach ($data['errors'] as $value) {
                if (is_array($value)) {
                    foreach ($value as $item) {
                        if (is_numeric($item) || (is_string($item) && preg_match('/^-?\d+$/', $item))) {
                            return (int) $item;
                        }
                    }
                } elseif (is_numeric($value)) {
                    return (int) $value;
                }
            }
        }

        if (isset($data['data']['code'])) {
            return (int) $data['data']['code'];
        }

        return 'نامشخص';
    }

    /**
     * پیام خوانا بر اساس کدهای مستندات زرین‌پال.
     *
     * @param int|string $code
     */
    private function map_error_message($code): string
    {
        $code = is_numeric($code) ? (int) $code : $code;

        $messages = [
            -9  => 'خطای اعتبارسنجی درخواست پرداخت.',
            -10 => 'آی‌پی یا مرچنت‌کد پذیرنده صحیح نیست.',
            -11 => 'مرچنت‌کد فعال نیست.',
            -12 => 'تلاش بیش از حد؛ لطفاً کمی بعد دوباره تلاش کنید.',
            -13 => 'محدودیت تراکنش ترمینال؛ مدارک پذیرنده را تکمیل کنید.',
            -14 => 'آدرس بازگشت با دامنه ثبت‌شده درگاه مغایرت دارد.',
            -15 => 'درگاه پرداخت تعلیق شده است.',
            -16 => 'سطح تأیید پذیرنده کافی نیست.',
            -17 => 'محدودیت پذیرنده در سطح فعلی.',
            -18 => 'آدرس ارجاع با دامنه ثبت‌شده مطابقت ندارد.',
            -19 => 'امکان ایجاد تراکنش برای این ترمینال وجود ندارد.',
            -30 => 'دسترسی به تسویه اشتراکی شناور وجود ندارد.',
            -31 => 'حساب بانکی تسویه را در پنل اضافه کنید.',
            -32 => 'مبلغ تسهیم از مبلغ کل بیشتر است.',
            -33 => 'درصدهای تسهیم صحیح نیست.',
            -34 => 'مبلغ تسهیم ثابت از مبلغ کل بیشتر است.',
            -35 => 'تعداد دریافت‌کنندگان تسهیم بیش از حد مجاز است.',
            -36 => 'حداقل مبلغ تسهیم رعایت نشده است.',
            -37 => 'یک یا چند شبا برای تسهیم غیرفعال است.',
            -38 => 'خطا در تعریف شبا؛ لطفاً دوباره تلاش کنید.',
            -39 => 'خطای تسهیم؛ با پشتیبانی زرین‌پال تماس بگیرید.',
            -40 => 'پارامتر expire_in نامعتبر است.',
            -41 => 'حداکثر مبلغ پرداختی ۱۰۰ میلیون تومان است.',
            -50 => 'مبلغ verify با مبلغ پرداخت‌شده یکسان نیست.',
            -51 => 'پرداخت ناموفق است.',
            -52 => 'خطای غیرمنتظره؛ با پشتیبانی زرین‌پال تماس بگیرید.',
            -53 => 'پرداخت متعلق به این مرچنت‌کد نیست.',
            -54 => 'اتوریتی نامعتبر است.',
            -55 => 'تراکنش مورد نظر یافت نشد.',
            101 => 'این تراکنش قبلاً تأیید شده است.',
        ];

        if (isset($messages[$code])) {
            return $messages[$code] . ' (کد ' . $code . ')';
        }

        return 'خطای زرین‌پال (کد ' . $code . ')';
    }
}
