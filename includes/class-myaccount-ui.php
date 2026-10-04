<?php
/**
 * WooCommerce My Account wallet UI (Medyar visual skin over shared classnames, MTW logic).
 * All mutating user actions go through authenticated, rate-limited AJAX.
 */
if (!defined('ABSPATH')) {
    exit;
}

if (class_exists('MTW_MyAccount_UI')) {
    return;
}

class MTW_MyAccount_UI
{
    public const PREFIX = 'sheyda_wallet_';
    public const NONCE_ACTION = 'mtw_wallet_ajax';

    private static $instance = null;

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets'], 20);
        add_action('wp_enqueue_scripts', [$this, 'dequeue_conflicting_theme_wallet_assets'], 100);

        add_action('wp_ajax_mtw_wallet_topup', [$this, 'ajax_topup']);
        add_action('wp_ajax_mtw_wallet_withdraw', [$this, 'ajax_withdraw']);
        add_action('wp_ajax_mtw_wallet_save_financial', [$this, 'ajax_save_financial']);
    }

    public static function sections(): array
    {
        return [
            'dashboard'    => 'پیشخوان',
            'topup'        => 'شارژ کیف پول',
            'withdrawal'   => 'برداشت',
            'transactions' => 'تراکنش‌ها',
            'financial'    => 'کارت‌های بانکی',
        ];
    }

    public static function get_active_section(): string
    {
        $sections = array_keys(self::sections());
        $section  = isset($_GET['section']) ? sanitize_key(wp_unslash($_GET['section'])) : '';
        if (!in_array($section, $sections, true)) {
            return 'dashboard';
        }
        return $section;
    }

    public static function base_url(): string
    {
        if (function_exists('wc_get_account_endpoint_url')) {
            return wc_get_account_endpoint_url('wallet');
        }
        return home_url('/my-account/wallet/');
    }

    public static function section_url(string $section, array $args = []): string
    {
        $args['section'] = $section;
        return add_query_arg($args, self::base_url());
    }

    public static function format_price($amount, bool $with_symbol = true): string
    {
        $formatted = number_format_i18n((float) $amount);
        return $with_symbol ? ($formatted . ' تومان') : $formatted;
    }

    public static function operation_labels(): array
    {
        return [
            'charge'    => 'شارژ',
            'withdraw'  => 'برداشت',
            'spend'     => 'خرید',
            'admin_add' => 'واریز دستی',
            'refund'    => 'بازگشت وجه',
        ];
    }

    public static function status_labels(): array
    {
        return [
            'pending'    => 'در انتظار',
            'processing' => 'در حال پردازش',
            'completed'  => 'تکمیل‌شده',
            'canceled'   => 'لغو شده',
            'rejected'   => 'رد شده',
        ];
    }

    public static function predefined_topup_amounts(): array
    {
        $defaults = [100000, 500000, 1000000, 2000000, 5000000];
        $amounts  = apply_filters('mtw_topup_predefined_amounts', $defaults);
        $clean    = [];
        foreach ((array) $amounts as $amount) {
            $amount = (int) $amount;
            if ($amount > 0) {
                $clean[] = $amount;
            }
        }
        return $clean ?: $defaults;
    }

    public static function min_withdrawal(): int
    {
        return (int) apply_filters('mtw_min_withdrawal', 10000);
    }

    public function enqueue_assets()
    {
        if (!function_exists('is_account_page') || !is_account_page()) {
            return;
        }
        if (!$this->is_wallet_request()) {
            return;
        }

        $ver_iconly = (string) @filemtime(MTW_PATH . 'assets/frontend/css/iconly.min.css');
        $ver_wallet = (string) @filemtime(MTW_PATH . 'assets/frontend/css/wallet.min.css');
        $ver_wc     = (string) @filemtime(MTW_PATH . 'assets/frontend/css/wc-wallet.min.css');
        $ver_utils  = (string) @filemtime(MTW_PATH . 'assets/frontend/js/sheyda-utils.js');
        $ver_base   = (string) @filemtime(MTW_PATH . 'assets/frontend/js/sheyda-wallet.js');
        $ver_ui     = (string) @filemtime(MTW_PATH . 'assets/frontend/js/mtw-wc-wallet.js');

        wp_enqueue_style('mtw-wallet-icons', MTW_URL . 'assets/frontend/css/iconly.min.css', [], $ver_iconly ?: MTW_VERSION);
        wp_enqueue_style('mtw-wallet', MTW_URL . 'assets/frontend/css/wallet.min.css', ['mtw-wallet-icons'], $ver_wallet ?: MTW_VERSION);
        wp_enqueue_style('mtw-wallet-wc', MTW_URL . 'assets/frontend/css/wc-wallet.min.css', ['mtw-wallet'], $ver_wc ?: MTW_VERSION);

        $ver_skin = (string) @filemtime(MTW_PATH . 'assets/frontend/css/mtw-medyar-skin.css');
        wp_enqueue_style(
            'mtw-medyar-skin',
            MTW_URL . 'assets/frontend/css/mtw-medyar-skin.css',
            ['mtw-wallet-wc'],
            $ver_skin ?: MTW_VERSION
        );

        wp_enqueue_script('mtw-wallet-utils', MTW_URL . 'assets/frontend/js/sheyda-utils.js', ['jquery'], $ver_utils ?: MTW_VERSION, true);
        wp_enqueue_script('mtw-wallet', MTW_URL . 'assets/frontend/js/sheyda-wallet.js', ['jquery', 'mtw-wallet-utils'], $ver_base ?: MTW_VERSION, true);

        $section = self::get_active_section();
        if (in_array($section, ['financial', 'withdrawal', 'topup'], true)) {
            wp_enqueue_style(
                'mtw-wallet-select2',
                MTW_URL . 'assets/frontend/libs/select2/select2.min.css',
                [],
                (string) (@filemtime(MTW_PATH . 'assets/frontend/libs/select2/select2.min.css') ?: MTW_VERSION)
            );
            wp_enqueue_script(
                'mtw-wallet-select2',
                MTW_URL . 'assets/frontend/libs/select2/select2.min.js',
                ['jquery'],
                (string) (@filemtime(MTW_PATH . 'assets/frontend/libs/select2/select2.min.js') ?: MTW_VERSION),
                true
            );
        }
        if ($section === 'financial' && class_exists('Medyar_Bank_Cards')) {
            Medyar_Bank_Cards::enqueue_assets();
        }

        $deps = ['jquery', 'mtw-wallet'];
        if (in_array($section, ['financial', 'withdrawal', 'topup'], true)) {
            $deps[] = 'mtw-wallet-select2';
        }

        wp_enqueue_script('mtw-wallet-wc', MTW_URL . 'assets/frontend/js/mtw-wc-wallet.js', $deps, $ver_ui ?: MTW_VERSION, true);

        $user_id = get_current_user_id();
        $balance = $user_id ? (int) Wallet_Manager::get_instance()->get_balance($user_id) : 0;
        $min     = self::min_withdrawal();
        $max_w   = function_exists('medyar_wallet_get_max_withdraw') ? medyar_wallet_get_max_withdraw() : 0;
        $max_d   = function_exists('medyar_wallet_get_max_deposit') ? medyar_wallet_get_max_deposit() : 0;

        wp_localize_script('mtw-wallet-wc', 'mtwWalletAjax', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE_ACTION),
            'actions' => [
                'topup'     => 'mtw_wallet_topup',
                'withdraw'  => 'mtw_wallet_withdraw',
                'financial' => 'mtw_wallet_save_financial',
            ],
            'limits'  => [
                'minWithdraw' => $min,
                'maxWithdraw' => $max_w,
                'maxDeposit'  => $max_d,
                'balance'     => $balance,
            ],
            'urls'    => [
                'withdrawal' => self::section_url('withdrawal'),
                'financial'  => self::section_url('financial'),
                'topup'      => self::section_url('topup'),
            ],
            'i18n'    => [
                'genericError'         => 'خطایی رخ داد. دوباره تلاش کنید.',
                'networkError'         => 'خطا در ارتباط با سرور.',
                'securityError'        => 'خطای امنیتی. صفحه را رفرش کنید.',
                'rateLimited'          => 'تعداد درخواست‌ها زیاد است. کمی صبر کنید.',
                'maxWithdrawalError'   => 'مبلغ درخواستی شما بیشتر از موجودی کیف پولتان است.',
                'minWithdrawalError'   => 'مبلغ درخواستی شما کمتر از حداقل مبلغ مجاز برای برداشت است.',
                'withdrawSuccess'      => 'درخواست برداشت با موفقیت ثبت شد.',
                'financialSuccess'     => 'اطلاعات مالی ذخیره شد.',
                'invalidAmount'        => 'مبلغ نامعتبر است.',
                'selectCard'           => 'یک کارت بانکی انتخاب کنید.',
                'saving'               => 'در حال ذخیره…',
            ],
        ]);

        // Backward-compatible aliases used by copied Sheyda helpers.
        wp_localize_script('mtw-wallet-wc', 'sheydaWalletVars', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
        ]);
        wp_localize_script('mtw-wallet-wc', 'walletWC', [
            'i18n' => [
                'maxWithdrawalError' => 'مبلغ درخواستی شما بیشتر از موجودی کیف پولتان است.',
                'minWithdrawalError' => 'مبلغ درخواستی شما کمتر از حداقل مبلغ مجاز برای برداشت است.',
            ],
        ]);
    }

    public function render()
    {
        if (!is_user_logged_in()) {
            echo '<p>' . esc_html__('برای مشاهده کیف پول وارد شوید.', 'medyar-toman-wallet') . '</p>';
            return;
        }

        // Safety net if wp_enqueue_scripts ran before endpoint detection.
        $this->enqueue_assets();

        $sections       = self::sections();
        $active_section = self::get_active_section();
        $base_url       = self::base_url();
        $prefix         = self::PREFIX;

        include MTW_PATH . 'templates/myaccount/wallet.php';
    }

    private function is_wallet_request(): bool
    {
        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('wallet')) {
            return true;
        }

        global $wp;
        if (isset($wp->query_vars['wallet'])) {
            return true;
        }

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        return (bool) preg_match('#/my-account/wallet(?:/|\?|$)#', $uri);
    }

    public function dequeue_conflicting_theme_wallet_assets(): void
    {
        if (!$this->is_wallet_request()) {
            return;
        }

        // Prevent Sheyda theme wallet JS/CSS from fighting MTW Medyar skin.
        foreach ([
            'sheyda-wallet-wc',
            'sheyda-wallet',
            'sheyda-wallet-utils',
            'sheyda-wallet-select2',
        ] as $handle) {
            wp_dequeue_script($handle);
            wp_deregister_script($handle);
        }

        foreach ([
            'sheyda-wallet',
            'sheyda-wallet-wc',
            'sheyda-wallet-icons',
        ] as $handle) {
            wp_dequeue_style($handle);
            wp_deregister_style($handle);
        }
    }

    /**
     * @return int User ID on success (never returns on failure).
     */
    private function assert_ajax_access(string $rate_bucket, int $max_hits = 8, int $window_seconds = 60): int
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'ورود الزامی است.', 'code' => 'auth'], 401);
        }

        if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error(['message' => 'خطای امنیتی. صفحه را رفرش کنید.', 'code' => 'nonce'], 403);
        }

        $user_id = get_current_user_id();
        if (!$this->consume_rate_limit($user_id, $rate_bucket, $max_hits, $window_seconds)) {
            wp_send_json_error(['message' => 'تعداد درخواست‌ها زیاد است. کمی صبر کنید.', 'code' => 'rate_limited'], 429);
        }

        return $user_id;
    }

    private function consume_rate_limit(int $user_id, string $bucket, int $max_hits, int $window_seconds): bool
    {
        $key   = 'mtw_rl_' . $bucket . '_' . $user_id;
        $state = get_transient($key);
        if (!is_array($state) || empty($state['count']) || empty($state['start'])) {
            set_transient($key, ['count' => 1, 'start' => time()], $window_seconds);
            return true;
        }

        $elapsed = time() - (int) $state['start'];
        if ($elapsed >= $window_seconds) {
            set_transient($key, ['count' => 1, 'start' => time()], $window_seconds);
            return true;
        }

        $count = (int) $state['count'];
        if ($count >= $max_hits) {
            return false;
        }

        $state['count'] = $count + 1;
        set_transient($key, $state, max(1, $window_seconds - $elapsed));
        return true;
    }

    public function ajax_topup()
    {
        $user_id = $this->assert_ajax_access('topup', 5, 60);

        $raw_amount = isset($_POST['amount']) ? (string) wp_unslash($_POST['amount']) : '';
        $amount     = (int) preg_replace('/[^\d]/', '', $this->normalize_digits($raw_amount));

        if ($amount < 1000) {
            wp_send_json_error(['message' => 'مبلغ شارژ نامعتبر است.', 'code' => 'invalid_amount']);
        }

        $max = function_exists('medyar_wallet_get_max_deposit') ? medyar_wallet_get_max_deposit() : 500000000;
        if ($amount > $max) {
            $msg = function_exists('medyar_wallet_max_deposit_error_message')
                ? medyar_wallet_max_deposit_error_message($max)
                : 'مبلغ از سقف مجاز بیشتر است.';
            wp_send_json_error(['message' => $msg, 'code' => 'max_exceeded']);
        }

        $card_number = isset($_POST['card_number'])
            ? preg_replace('/\D/', '', (string) wp_unslash($_POST['card_number']))
            : '';

        $user   = get_userdata($user_id);
        $mobile = $this->resolve_user_mobile($user_id);

        $result = Wallet_Manager::get_instance()->initiate_charge_via_gateway($user_id, $amount, [
            'mobile'      => $mobile,
            'email'       => $user ? (string) $user->user_email : '',
            'card_number' => $card_number,
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message(), 'code' => $result->get_error_code()]);
        }

        $redirect = isset($result['redirect_url']) ? esc_url_raw((string) $result['redirect_url']) : '';
        if ($redirect === '') {
            wp_send_json_error(['message' => 'آدرس درگاه در دسترس نیست.', 'code' => 'gateway']);
        }

        wp_send_json_success([
            'checkoutUrl' => $redirect,
            'txId'        => isset($result['tx_id']) ? (int) $result['tx_id'] : 0,
        ]);
    }

    public function ajax_withdraw()
    {
        $user_id = $this->assert_ajax_access('withdraw', 4, 120);

        $raw_amount = isset($_POST['amount']) ? (string) wp_unslash($_POST['amount']) : '';
        $amount     = (int) preg_replace('/[^\d]/', '', $this->normalize_digits($raw_amount));
        $card_key   = isset($_POST['card_number'])
            ? preg_replace('/\D/', '', (string) wp_unslash($_POST['card_number']))
            : '';

        $min = self::min_withdrawal();
        if ($amount < $min) {
            wp_send_json_error([
                'message' => sprintf('حداقل مبلغ درخواست برداشت %s است.', self::format_price($min)),
                'code'    => 'min',
            ]);
        }

        if (strlen($card_key) !== 16) {
            wp_send_json_error(['message' => 'کارت بانکی معتبر انتخاب نشده است.', 'code' => 'card']);
        }

        $cards   = medyar_get_user_bank_cards($user_id);
        $matched = null;
        foreach ($cards as $key => $data) {
            if (preg_replace('/\D/', '', (string) $key) === $card_key) {
                $matched = is_array($data) ? $data : [];
                break;
            }
        }

        if ($matched === null) {
            wp_send_json_error(['message' => 'کارت بانکی معتبر انتخاب نشده است.', 'code' => 'card']);
        }

        $iban  = strtoupper(preg_replace('/\s+/', '', (string) ($matched['iban'] ?? '')));
        $owner = sanitize_text_field((string) ($matched['owner'] ?? ''));
        $bank  = is_array($matched['bank'] ?? null) ? $matched['bank'] : [];

        $result = Wallet_Manager::get_instance()->request_withdrawal(
            $user_id,
            $amount,
            $card_key,
            $iban,
            [
                'owner' => $owner,
                'bank'  => $bank,
            ]
        );

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message(), 'code' => $result->get_error_code()]);
        }

        $new_balance = (int) Wallet_Manager::get_instance()->get_balance($user_id);
        $tx_id       = is_array($result) ? (int) ($result['sti'] ?? 0) : 0;

        wp_send_json_success([
            'message'    => 'درخواست برداشت با موفقیت ثبت شد.',
            'balance'    => $new_balance,
            'balanceText'=> self::format_price($new_balance),
            'txId'       => $tx_id,
            'reloadUrl'  => self::section_url('withdrawal'),
        ]);
    }

    public function ajax_save_financial()
    {
        $user_id = $this->assert_ajax_access('financial', 6, 60);

        $incoming = [];
        if (isset($_POST['accounts']) && is_array($_POST['accounts'])) {
            $incoming = wp_unslash($_POST['accounts']);
        } elseif (isset($_POST['mtw_financial_account']) && is_array($_POST['mtw_financial_account'])) {
            $incoming = wp_unslash($_POST['mtw_financial_account']);
        }

        $old_cards = medyar_get_user_bank_cards($user_id);
        $new_cards = [];
        $max       = function_exists('medyar_bank_card_max_count') ? medyar_bank_card_max_count() : 3;
        $errors    = [];

        foreach ($incoming as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (count($new_cards) >= $max) {
                break;
            }

            $number = $this->normalize_digits((string) ($row['number'] ?? ''));
            $owner  = sanitize_text_field((string) ($row['owner'] ?? ''));
            $card   = preg_replace('/\D/', '', $number);

            if ($card === '') {
                continue;
            }
            if (strlen($card) !== 16) {
                $errors[] = 'شماره کارت باید ۱۶ رقم باشد.';
                continue;
            }
            if (isset($new_cards[$card])) {
                continue;
            }

            $bank = medyar_get_bank_info_by_card_number($card);
            if (!$bank) {
                $errors[] = 'بانک صادرکننده یکی از کارت‌ها شناسایی نشد.';
                continue;
            }

            if ($owner === '') {
                $user  = get_userdata($user_id);
                $owner = $user ? trim((string) $user->display_name) : '';
            }

            $existing_iban = '';
            foreach ($old_cards as $k => $d) {
                if (preg_replace('/\D/', '', (string) $k) === $card) {
                    $existing_iban = sanitize_text_field((string) ($d['iban'] ?? ''));
                    break;
                }
            }

            $new_cards[$card] = [
                'iban'  => $existing_iban,
                'bank'  => [
                    'fa' => sanitize_text_field((string) ($bank['fa'] ?? '')),
                    'en' => sanitize_text_field((string) ($bank['en'] ?? '')),
                ],
                'owner' => $owner,
            ];
        }

        if (empty($new_cards) && !empty($incoming) && !empty($errors)) {
            wp_send_json_error([
                'message' => $errors[0],
                'code'    => 'validation',
                'errors'  => array_values(array_unique($errors)),
            ]);
        }

        delete_user_meta($user_id, Medyar_Bank_Cards::META_KEY);
        if (!empty($new_cards)) {
            update_user_meta($user_id, Medyar_Bank_Cards::META_KEY, $new_cards);
        }

        $cards_out = [];
        foreach ($new_cards as $num => $data) {
            $digits = preg_replace('/\D/', '', (string) $num);
            $cards_out[] = [
                'number'   => $digits,
                'owner'    => (string) ($data['owner'] ?? ''),
                'bank'     => (string) ($data['bank']['fa'] ?? ''),
                'label'    => trim(chunk_split($digits, 4, '-'), '-'),
            ];
        }

        wp_send_json_success([
            'message' => 'اطلاعات مالی ذخیره شد.',
            'cards'   => $cards_out,
            'count'   => count($cards_out),
        ]);
    }

    private function resolve_user_mobile(int $user_id): string
    {
        foreach (['billing_phone', 'digits_phone', 'mobile'] as $key) {
            $val = (string) get_user_meta($user_id, $key, true);
            if ($val !== '') {
                return $val;
            }
        }
        return '';
    }

    private function normalize_digits(string $value): string
    {
        $map = [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ];
        return strtr($value, $map);
    }
}
