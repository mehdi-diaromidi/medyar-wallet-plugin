<?php
if (!defined('ABSPATH')) { exit; }

if (class_exists('Wallet_Admin_Menu')) { return; }

class Wallet_Admin_Menu
{
    private static $instance = null;
    public static function get_instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {
        if (is_admin()) {
            require_once WALLET_PLUGIN_PATH . 'admin/class-admin-list.php';
            Wallet_Admin_List::get_instance();
        }
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_filter('user_row_actions', [$this, 'add_medyar_panel_user_row_action'], 10, 2);
        add_action('admin_post_medyar_update_transaction', [$this, 'handle_update_transaction']);
        add_action('admin_post_mtw_modal_update_transaction', [$this, 'handle_modal_update']);
        add_action('admin_post_medyar_manual_deposit', [$this, 'handle_manual_deposit']);
        add_action('admin_post_medyar_save_settings', [$this, 'handle_save_settings']);
        add_action('wp_ajax_medyar_deposit_search', [$this, 'ajax_deposit_search']);
        add_action('wp_ajax_medyar_manual_deposit_batch', [$this, 'ajax_manual_deposit_batch']);
    }
    public function register_menu() {
        add_menu_page('مدیریت کیف پول مدیار', 'کیف پول مدیار', 'manage_options', 'medyar-wallet', [$this, 'render_dashboard'], 'dashicons-money-alt', 30);
        add_submenu_page('medyar-wallet', 'داشبورد', 'داشبورد', 'manage_options', 'medyar-wallet', [$this, 'render_dashboard']);
        add_submenu_page('medyar-wallet', 'تنظیمات', 'تنظیمات', 'manage_options', 'medyar-wallet-settings', [$this, 'render_settings']);
        add_submenu_page('medyar-wallet', 'تراکنش‌ها', 'تراکنش‌ها', 'manage_options', 'medyar-wallet-transactions', [$this, 'render_transactions']);
        add_submenu_page('medyar-wallet', 'تراکنش‌های اخیر', 'تراکنش‌های اخیر', 'manage_options', 'medyar-wallet-recent-transactions', [$this, 'render_recent_transactions']);
        add_submenu_page('medyar-wallet', 'برداشت‌ها', 'برداشت‌ها', 'manage_options', 'medyar-wallet-withdrawals', [$this, 'render_withdrawals']);
        add_submenu_page('medyar-wallet', 'واریز', 'واریز', 'manage_options', 'medyar-wallet-deposit', [$this, 'render_deposit']);
        add_submenu_page('options.php', 'پنل کاربر', 'پنل کاربر', 'manage_options', 'medyar-wallet-user-insights', [$this, 'render_user_insights']);
    }
    public function add_medyar_panel_user_row_action($actions, $user) {
        if (!current_user_can('manage_options') || !($user instanceof WP_User)) return $actions;
        $url = add_query_arg(['page' => 'medyar-wallet-user-insights', 'user_id' => (int) $user->ID], admin_url('admin.php'));
        $actions['medyar_wallet_insights'] = '<a href="' . esc_url($url) . '">پنل کیف پول</a>';
        return $actions;
    }
    public function enqueue_admin_assets($hook)
    {
        $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';
        $is_wallet = (strpos((string) $hook, 'medyar-wallet') !== false)
            || ($page !== '' && strpos($page, 'medyar-wallet') === 0);
        if (!$is_wallet) {
            return;
        }

        $style_deps = [];
        $script_deps = ['jquery'];
        // همان datepicker شمسی ووکامرس فارسی (persianDatepicker)
        $pw_dp_css = '';
        $pw_dp_js  = '';
        if (function_exists('PW')) {
            $pw_dp_css = PW()->plugin_url('assets/css/persian-datepicker.css');
            $pw_dp_js  = PW()->plugin_url('assets/js/persian-datepicker.min.js');
        } elseif (defined('PW_URL')) {
            $pw_dp_css = PW_URL . 'assets/css/persian-datepicker.css';
            $pw_dp_js  = PW_URL . 'assets/js/persian-datepicker.min.js';
        } else {
            $pw_dp_css = plugins_url('persian-woocommerce/assets/css/persian-datepicker.css');
            $pw_dp_js  = plugins_url('persian-woocommerce/assets/js/persian-datepicker.min.js');
        }
        $pw_ver = defined('PW_VERSION') ? PW_VERSION : '10.0.2';
        if ($pw_dp_css !== '') {
            wp_enqueue_style('pw-datepicker-css', $pw_dp_css, [], $pw_ver);
            $style_deps[] = 'pw-datepicker-css';
        }
        if ($pw_dp_js !== '') {
            wp_enqueue_script('pw-datepicker-js', $pw_dp_js, ['jquery'], $pw_ver, true);
            $script_deps[] = 'pw-datepicker-js';
        }

        wp_enqueue_style(
            'wwp-admin-styles',
            WALLET_PLUGIN_URL . 'assets/css/admin-styles.css',
            $style_deps,
            (string) filemtime(WALLET_PLUGIN_PATH . 'assets/css/admin-styles.css')
        );

        wp_enqueue_script(
            'wwp-admin-list',
            WALLET_PLUGIN_URL . 'assets/js/admin-list.js',
            $script_deps,
            WALLET_PLUGIN_VERSION,
            true
        );

        wp_localize_script('wwp-admin-list', 'wwpAdminList', [
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'adminPostUrl' => admin_url('admin-post.php'),
            'nonce'        => wp_create_nonce('mtw_admin_list'),
            'bulkNonces'   => [
                'withdrawals'  => wp_create_nonce('wallet_bulk_update_withdrawals'),
                'trades'       => wp_create_nonce('wallet_bulk_cancel_trades'),
                'manual'       => wp_create_nonce('wallet_bulk_manual_trade_action'),
                'documents'    => wp_create_nonce('wallet_bulk_document_action'),
            ],
            'i18n' => [
                'loading'   => 'در حال بارگذاری…',
                'error'     => 'خطا در دریافت لیست',
                'confirmBulk' => 'این عملیات روی موارد انتخاب‌شده اعمال شود؟',
                'selectRows'  => 'حداقل یک ردیف را انتخاب کنید.',
                'cleared'     => 'حافظهٔ فیلترهای این مرورگر پاک شد.',
            ],
        ]);

        if ($page === 'medyar-wallet-deposit') {
            wp_enqueue_script(
                'wwp-admin-deposit',
                WALLET_PLUGIN_URL . 'assets/js/admin-deposit.js',
                ['jquery'],
                WALLET_PLUGIN_VERSION,
                true
            );
            wp_localize_script('wwp-admin-deposit', 'wwpAdminDeposit', [
                'ajaxUrl'       => admin_url('admin-ajax.php'),
                'adminPostUrl'  => admin_url('admin-post.php'),
                'pageUrl'       => admin_url('admin.php?page=medyar-wallet-deposit'),
                'nonce'         => wp_create_nonce('medyar_deposit_ajax'),
                'depositNonce'  => wp_create_nonce('medyar_manual_deposit'),
                'maxBatch'      => 50,
                'i18n'          => [
                    'searching'       => 'در حال جستجو…',
                    'searchError'     => 'خطا در جستجو. دوباره تلاش کنید.',
                    'noResults'       => 'کاربری با این مشخصات یافت نشد.',
                    'needQuery'       => 'حداقل یکی از فیلدهای جستجو را پر کنید.',
                    'invalidAmount'   => 'مبلغ معتبر وارد کنید.',
                    'queueEmpty'      => 'صف واریز خالی است.',
                    'queueAdded'      => 'به صف واریز اضافه شد.',
                    'queueDuplicate'  => 'این کارت/شبا از قبل در صف است — مبلغ به‌روز شد.',
                    'confirmBatch'    => 'واریز همه موارد صف انجام شود؟',
                    'batchRunning'    => 'در حال واریز…',
                    'batchError'      => 'خطا در اجرای واریز گروهی.',
                    'noCard'          => 'کارتی ثبت نشده — ابتدا کارت را در پنل کاربر بررسی کنید.',
                    'insights'        => 'پنل کاربر',
                    'depositNow'      => 'واریز',
                    'addToQueue'      => 'افزودن به صف',
                    'remove'          => 'حذف',
                    'dash'            => '—',
                ],
            ]);
        }
    }
    public function apply_transaction_status_change(object $tx, string $new_status, string $note): ?string
    {
        $tm = Wallet_Transaction_Manager::get_instance();
        return $this->_apply_status_change($tm, $tx, $new_status, $note);
    }
    public function render_dashboard()    { require_once WALLET_PLUGIN_PATH . 'admin/admin-pages/dashboard.php'; }
    public function render_settings()     { require_once WALLET_PLUGIN_PATH . 'admin/admin-pages/settings.php'; }
    public function render_transactions() { require_once WALLET_PLUGIN_PATH . 'admin/admin-pages/transactions.php'; }
    public function render_recent_transactions() { require_once WALLET_PLUGIN_PATH . 'admin/admin-pages/recent-transactions.php'; }
    public function render_withdrawals()  { require_once WALLET_PLUGIN_PATH . 'admin/admin-pages/withdrawals.php'; }
    public function render_deposit()      { require_once WALLET_PLUGIN_PATH . 'admin/admin-pages/deposit.php'; }
    public function render_user_insights() { require_once WALLET_PLUGIN_PATH . 'admin/admin-pages/user-insights.php'; }
    private function resolve_modal_redirect_url(): string
    {
        $allowed = [
            'medyar-wallet',
            'medyar-wallet-transactions',
            'medyar-wallet-withdrawals',
            'medyar-wallet-deposit',
            'medyar-wallet-deposit',
            'medyar-wallet-user-insights',
            'medyar-wallet',
            'medyar-wallet-recent-transactions',
            'medyar-wallet-transactions',
            'medyar-wallet',
            'medyar-wallet',
            'medyar-wallet',
        ];
        $page = sanitize_text_field($_POST['redirect_page'] ?? 'medyar-wallet');
        if (!in_array($page, $allowed, true)) {
            $page = 'medyar-wallet';
        }
        $url = admin_url('admin.php?page=' . $page);
        if ($page === 'medyar-wallet-user-insights') {
            $uid = intval($_POST['redirect_user_id'] ?? 0);
            if ($uid > 0) {
                $url = add_query_arg('user_id', $uid, $url);
            } else {
                $url = admin_url('users.php');
            }
        }
        return $url;
    }

    /**
     * بازگشت پس از لغو معامله P2P
     */
    private function resolve_trade_cancel_redirect_url(): string
    {
        $page = sanitize_text_field($_POST['redirect_page'] ?? 'medyar-wallet-transactions');
        if (!in_array($page, ['medyar-wallet-transactions', 'medyar-wallet-user-insights'], true)) {
            $page = 'medyar-wallet-transactions';
        }
        $url = admin_url('admin.php?page=' . $page);
        if ($page === 'medyar-wallet-user-insights') {
            $uid = intval($_POST['redirect_user_id'] ?? 0);
            if ($uid > 0) {
                $url = add_query_arg('user_id', $uid, $url);
            } else {
                $url = admin_url('users.php');
            }
        }
        return $url;
    }

    // ═══════════════════════════════════════════════════════════════════
    // HANDLER: دکمه‌های تایید / لغو (داشبورد + withdrawals + transactions)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * فراخوانی‌شده توسط دکمه‌های تایید/لغو در داشبورد و صفحه برداشت‌ها
     *
     * منطق به تفکیک operation_type:
     *
     *  buy_silver / silver:
     *    completed → نقره به موجودی اضافه می‌شود
     *    canceled  → cancel_transaction (تومان برمی‌گردد، تراکنش پرداخت completed می‌ماند)
     *
     *  sell_silver / toman:
     *    completed → تومان به موجودی اضافه می‌شود
     *    canceled  → cancel_transaction (نقره برمی‌گردد، تراکنش نقره completed می‌ماند)
     *
     *  withdraw / toman:
     *    completed → فقط status (موجودی قبلاً در STAGE 0 کم شده)
     *    canceled  → cancel_transaction (تومان برمی‌گردد)
     */
    public function handle_update_transaction()
    {
        if (!current_user_can('manage_options')) wp_die('دسترسی غیرمجاز.');
        check_admin_referer('medyar_update_transaction', 'medyar_update_nonce');

        $tx_id       = intval($_POST['transaction_id'] ?? 0);
        $new_status  = sanitize_text_field($_POST['new_status'] ?? '');
        $redirect    = $this->resolve_modal_redirect_url();

        if (!$tx_id || !in_array($new_status, ['completed', 'canceled', 'processing'], true)) {
            wp_redirect(add_query_arg('wwp_error', urlencode('اطلاعات ناقص است.'), $redirect));
            exit;
        }

        $tm  = Wallet_Transaction_Manager::get_instance();
        $tx  = $tm->get_transaction($tx_id);
        $admin_name = $this->get_admin_display_name();

        if (!$tx) {
            wp_redirect(add_query_arg('wwp_error', urlencode('تراکنش یافت نشد.'), $redirect));
            exit;
        }

        // یادداشت بر اساس status
        $note = ($new_status === 'completed')
            ? ('تأیید توسط ادمین ' . $admin_name)
            : (($new_status === 'canceled') ? ('لغو توسط ادمین ' . $admin_name) : ('تغییر وضعیت توسط ادمین ' . $admin_name));

        error_log(sprintf(
            '[WalletAdmin] handle_update_transaction: tx=%d op=%s bal=%s %s→%s',
            $tx_id, $tx->operation_type, $tx->balance_type, $tx->status, $new_status
        ));

        $error = $this->_apply_status_change($tm, $tx, $new_status, $note);

        wp_redirect(
            $error
                ? add_query_arg('wwp_error', urlencode($error), $redirect)
                : add_query_arg('wwp_updated', '1', $redirect)
        );
        exit;
    }

    // ═══════════════════════════════════════════════════════════════════
    // HANDLER: مودال (status + amount + admin_note)
    // ═══════════════════════════════════════════════════════════════════

    public function handle_modal_update()
    {
        if (!current_user_can('manage_options')) wp_die('دسترسی غیرمجاز.');
        $tx_id = intval($_POST['transaction_id'] ?? 0);
        check_admin_referer('wwp_modal_update_' . $tx_id, 'wwp_modal_nonce');

        $redirect = $this->resolve_modal_redirect_url();
        if (!$tx_id) {
            wp_redirect(add_query_arg('wwp_error', urlencode('شناسه تراکنش نامعتبر.'), $redirect));
            exit;
        }

        $tm = Wallet_Transaction_Manager::get_instance();
        $tx = $tm->get_transaction($tx_id);
        if (!$tx) {
            wp_redirect(add_query_arg('wwp_error', urlencode('تراکنش یافت نشد.'), $redirect));
            exit;
        }

        /* amount — برای تسویهٔ completed فقط از مبلغ ذخیره‌شده در DB استفاده می‌شود.
         * مقدار POST می‌تواند برای ویرایش ساده (بدون تغییر status) اعمال شود،
         * اما تأیید مالی نباید مبلغ دلخواه از کلاینت را بپذیرد. */
        $sign = floatval($tx->amount) >= 0 ? 1 : -1;
        if (isset($_POST['oruj_silver'])) {
            $raw_amount = abs(floatval($_POST['oruj_silver']));
        } elseif (isset($_POST['oruj_toman'])) {
            $raw_amount = abs(floatval($_POST['oruj_toman']));
        } else {
            $raw_amount = abs(floatval($_POST['amount'] ?? $tx->amount));
        }
        $posted_amount = $sign * $raw_amount;

        /* status */
        $new_status  = sanitize_text_field($_POST['new_status'] ?? $tx->status);
        if (!in_array($new_status, ['pending', 'processing', 'completed', 'canceled'], true)) {
            $new_status = $tx->status;
        }
        $admin_name     = $this->get_admin_display_name();
        $admin_note     = '';
        $status_changed = ($new_status !== $tx->status);

        // تأیید مالی (→ completed): مبلغ فقط از DB
        $new_amount = ($status_changed && $new_status === 'completed')
            ? (float) $tx->amount
            : $posted_amount;

        error_log(sprintf(
            '[WalletAdmin] handle_modal_update: tx=%d op=%s bal=%s %s→%s note=%s',
            $tx_id, $tx->operation_type, $tx->balance_type, $tx->status, $new_status, $admin_name
        ));

        if (!$status_changed) {
            // فقط amount عوض شده — توضیح فقط audit سیستمی
            $new_desc = $this->_append_note((string)$tx->description, 'ویرایش مبلغ توسط ادمین ' . $admin_name, true);
            $error    = $this->_db_simple_update($tx_id, $new_amount, $tx->status, $new_desc, false, $tx);
        } elseif ($new_status === 'canceled') {
            $reason = 'لغو توسط ادمین ' . $admin_name . ' از مودال';
            $res    = $tm->cancel_transaction($tx_id, $reason);
            $error  = is_wp_error($res) ? $res->get_error_message() : null;
        } else {
            // تغییر به completed یا processing
            $note  = ($new_status === 'completed')
                ? ('تأیید توسط ادمین ' . $admin_name . ' از مودال')
                : ('تغییر وضعیت توسط ادمین ' . $admin_name . ' از مودال');
            $error = $this->_apply_status_change_with_amount($tm, $tx, $new_status, $new_amount, $note);
        }

        wp_redirect($error
            ? add_query_arg('wwp_error', urlencode($error), $redirect)
            : add_query_arg('wwp_updated', '1', $redirect));
        exit;
    }

    // ═══════════════════════════════════════════════════════════════════
    // منطق تغییر وضعیت
    // ═══════════════════════════════════════════════════════════════════

    /**
     * تغییر وضعیت با amount از tx فعلی
     */
    private function _apply_status_change(
        Wallet_Transaction_Manager $tm,
        object $tx,
        string $new_status,
        string $note
    ): ?string {
        return $this->_apply_status_change_with_amount(
            $tm, $tx, $new_status, floatval($tx->amount), $note
        );
    }

    /**
     * منطق کامل تغییر وضعیت + موجودی
     *
     * قوانین completed:
     *  buy_silver/silver   → نقره اضافه می‌شود (SELECT FOR UPDATE)
     *  sell_silver/toman   → تومان اضافه می‌شود (SELECT FOR UPDATE)
     *  withdraw/toman      → فقط status
     *  سایر                → فقط status
     *
     * قوانین canceled:
     *  همیشه cancel_transaction() → بازگشت موجودی + تراکنش returned
     *
     * @return string|null  پیام خطا یا null
     */
    private function _apply_status_change_with_amount(
        Wallet_Transaction_Manager $tm,
        object $tx,
        string $new_status,
        float  $new_amount,
        string $note
    ): ?string {
        global $wpdb;
        $tx_id   = intval($tx->id);
        $user_id = intval($tx->user_id);
        $op      = $tx->operation_type;
        $bal     = $tx->balance_type;

        if ($tx->status === 'canceled' && $new_status === 'completed') {
            return 'تراکنش لغو شده قابل تأیید مجدد نیست.';
        }

        if ($new_status === 'canceled') {
            $res = $tm->cancel_transaction($tx_id, $note);
            return is_wp_error($res) ? $res->get_error_message() : null;
        }

        // description با یادداشت
        $new_desc = $this->_append_note((string)$tx->description, $note, true);

        if ($new_status === 'completed') {

            if ($op === 'buy_silver' && $bal === 'silver') {
                // ── تأیید خرید نقره → نقره اضافه می‌شود ─────────────
                $grams = abs($new_amount);
                if ($grams <= 0) return 'میزان دارایی نامعتبر است.';

                $wpdb->query('START TRANSACTION');
                try {
                    // CAS: فقط اگر هنوز completed نشده باشد
                    $claimed = $wpdb->query($wpdb->prepare(
                        "UPDATE {$wpdb->prefix}wallet_transactions
                         SET status = 'completed', amount = %f, description = %s
                         WHERE id = %d AND status IN ('pending', 'processing')",
                        $grams,
                        $new_desc,
                        $tx_id
                    ));

                    if ($claimed !== 1) {
                        throw new Exception('این تراکنش قبلاً تأیید یا لغو شده است.');
                    }

                    $current_silver = wallet_locked_read_balance($user_id, 'silver');
                    wallet_write_balance($user_id, 'silver', $current_silver + $grams);

                    $wpdb->query('COMMIT');

                    $updated_tx = $tm->get_transaction($tx_id);
                    do_action('wallet_transaction_status_changed', $tx_id, $tx->transaction_code, 'completed', $tx->status, $updated_tx ?: $tx);

                    $toman_for_fee = 0;
                    if (!empty($tx->p_info)) {
                        $p_info = is_string($tx->p_info) ? json_decode($tx->p_info, true) : (array) $tx->p_info;
                        if (!empty($p_info['type']) && $p_info['type'] === 'Wallet_gateway' && !empty($p_info['id'])) {
                            $pay_tx = $tm->get_transaction((int) $p_info['id']);
                            if ($pay_tx) {
                                $toman_for_fee = abs((int) $pay_tx->amount);
                            }
                        }
                    }
                    if ($toman_for_fee <= 0 && !empty($tx->s_price)) {
                        $toman_for_fee = (int) round(abs((float) $grams) * (float) $tx->s_price);
                    }
                    do_action('wallet_silver_purchase_confirmed', $user_id, $grams, $toman_for_fee, $tx_id);

                    error_log("[WalletAdmin] buy_silver completed: user=$user_id grams=$grams tx=$tx_id");
                } catch (Exception $e) {
                    $wpdb->query('ROLLBACK');
                    wallet_flush_balance_cache($user_id);
                    return 'خطا در تأیید خرید دارایی: ' . $e->getMessage();
                }
                return null;
            }

            if ($op === 'sell_silver' && $bal === 'toman') {
                // ── تأیید فروش نقره → تومان اضافه می‌شود ─────────────
                $toman = abs(intval($new_amount));
                if ($toman <= 0) return 'میزان تومان نامعتبر است.';

                $wpdb->query('START TRANSACTION');
                try {
                    // CAS: فقط اگر هنوز completed نشده باشد
                    $claimed = $wpdb->query($wpdb->prepare(
                        "UPDATE {$wpdb->prefix}wallet_transactions
                         SET status = 'completed', amount = %d, description = %s
                         WHERE id = %d AND status IN ('pending', 'processing')",
                        $toman,
                        $new_desc,
                        $tx_id
                    ));

                    if ($claimed !== 1) {
                        throw new Exception('این تراکنش قبلاً تأیید یا لغو شده است.');
                    }

                    $current_toman = wallet_locked_read_balance($user_id, 'toman');
                    wallet_write_balance($user_id, 'toman', $current_toman + $toman);

                    $wpdb->query('COMMIT');

                    $updated_tx = $tm->get_transaction($tx_id);
                    do_action('wallet_transaction_status_changed', $tx_id, $tx->transaction_code, 'completed', $tx->status, $updated_tx ?: $tx);
                    error_log("[WalletAdmin] sell_silver completed: user=$user_id toman=$toman tx=$tx_id");
                } catch (Exception $e) {
                    $wpdb->query('ROLLBACK');
                    wallet_flush_balance_cache($user_id);
                    return 'خطا در تأیید فروش دارایی: ' . $e->getMessage();
                }
                return null;
            }

            // withdraw و سایر: فقط status
            return $this->_db_simple_update($tx_id, $new_amount, $new_status, $new_desc, true, $tx);
        }

        // سایر تغییرات status
        return $this->_db_simple_update($tx_id, $new_amount, $new_status, $new_desc, true, $tx);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ساخت description با یادداشت تجمعی
     *
     * اولین یادداشت (description خالی): بدون تاریخ
     * یادداشت‌های بعدی: با تاریخ فارسی
     *
     * @param string $existing  description فعلی
     * @param string $note      یادداشت جدید
     * @param bool   $with_date آیا با تاریخ ثبت شود؟ (اولین ثبت بدون تاریخ است)
     */
    private function _append_note(string $existing, string $note, bool $with_date = true): string
    {
        if (empty($note)) return $existing;

        $is_first = empty(trim($existing));

        if ($is_first) {
            // اولین یادداشت: بدون تاریخ
            return $note;
        }

        // یادداشت‌های بعدی: با تاریخ
        $now = current_time('Y/m/d H:i');
        return $existing . "\n[{$now}] " . $note;
    }

    /**
     * آپدیت ساده DB (بدون منطق موجودی)
     */
    private function _db_simple_update(
        int    $tx_id,
        float  $new_amount,
        string $new_status,
        string $new_desc,
        bool   $fire_hook,
        object $tx
    ): ?string {
        global $wpdb;

        $result = $wpdb->update(
            $wpdb->prefix . 'wallet_transactions',
            ['status' => $new_status, 'amount' => $new_amount, 'description' => $new_desc],
            ['id' => $tx_id],
            ['%s', '%f', '%s'], ['%d']
        );

        if ($result === false) {
            return 'خطا در به‌روزرسانی: ' . $wpdb->last_error;
        }

        if ($fire_hook && $new_status !== $tx->status) {
            $updated_tx = Wallet_Transaction_Manager::get_instance()->get_transaction($tx_id);
            do_action('wallet_transaction_status_changed', $tx_id, $tx->transaction_code, $new_status, $tx->status, $updated_tx ?: $tx);
        }

        error_log("[WalletAdmin] db_simple_update: tx=$tx_id status=$new_status amount=$new_amount");
        return null;
    }
    private function execute_manual_deposit(int $user_id, float $amount, string $card_number, string $iban)
    {
        require_once WALLET_PLUGIN_PATH . 'admin/admin-helpers.php';

        $card_number = preg_replace('/\D/', '', $card_number);
        $iban        = strtoupper(preg_replace('/\s+/', '', $iban));

        if ($user_id <= 0 || $amount <= 0) {
            return new WP_Error('invalid_input', 'کاربر یا مبلغ نامعتبر است.');
        }
        if ($card_number === '' && $iban === '') {
            return new WP_Error('missing_card', 'شماره کارت یا شبا الزامی است.');
        }

        $matches = wwp_find_users_by_card_or_iban($card_number, $iban);
        $matched = null;
        foreach ($matches as $row) {
            if ((int) $row['user_id'] !== $user_id) {
                continue;
            }
            if ($card_number !== '' && $row['card_number'] !== $card_number) {
                continue;
            }
            if ($iban !== '' && strtoupper($row['iban']) !== $iban) {
                continue;
            }
            $matched = $row;
            break;
        }

        if (!$matched) {
            return new WP_Error('not_found', 'کاربر با این کارت/شبا یافت نشد.');
        }

        $admin = wp_get_current_user();
        $desc  = sprintf(
            'واریز دستی توسط ادمین — کارت %s — شبا %s — ادمین: %s',
            $matched['card_number'] ?: '—',
            $matched['iban'] ?: '—',
            $admin->user_login
        );

        $result = Wallet_Manager::get_instance()->add_balance($user_id, $amount, 'charge', [
            'status'      => 'completed',
            'description' => $desc,
            'p_info'      => [
                'type'        => 'admin_manual_deposit',
                'card_number' => $matched['card_number'],
                'iban'        => $matched['iban'],
                'admin_id'    => (int) $admin->ID,
            ],
        ]);

        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }

    public function handle_manual_deposit()
    {
        wallet_verify_security('medyar_manual_deposit_nonce', 'medyar_manual_deposit');

        $user_id     = absint($_POST['user_id'] ?? 0);
        $amount      = wallet_sanitize_amount($_POST['amount'] ?? 0);
        $card_number = preg_replace('/\D/', '', (string) ($_POST['card_number'] ?? ''));
        $iban        = strtoupper(preg_replace('/\s+/', '', sanitize_text_field(wp_unslash($_POST['iban'] ?? ''))));
        $search_card = preg_replace('/\D/', '', (string) ($_POST['search_card'] ?? ''));
        $search_iban = strtoupper(preg_replace('/\s+/', '', sanitize_text_field(wp_unslash($_POST['search_iban'] ?? ''))));
        $search_user = sanitize_text_field(wp_unslash($_POST['search_user_q'] ?? ''));

        $redirect_args = ['page' => 'medyar-wallet-deposit'];
        if ($search_card !== '') {
            $redirect_args['card'] = $search_card;
        }
        if ($search_iban !== '') {
            $redirect_args['iban'] = $search_iban;
        }
        if ($search_user !== '') {
            $redirect_args['user_q'] = $search_user;
        }
        $redirect_url = add_query_arg($redirect_args, admin_url('admin.php'));

        $result = $this->execute_manual_deposit($user_id, $amount, $card_number, $iban);
        if (is_wp_error($result)) {
            wp_redirect(add_query_arg('error', rawurlencode($result->get_error_message()), $redirect_url));
            exit;
        }

        wp_redirect(add_query_arg([
            'success' => '1',
            'uid'     => $user_id,
            'amount'  => (string) $amount,
        ], $redirect_url));
        exit;
    }

    public function ajax_deposit_search()
    {
        check_ajax_referer('medyar_deposit_ajax', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز'], 403);
        }

        require_once WALLET_PLUGIN_PATH . 'admin/admin-helpers.php';
        require_once WALLET_PLUGIN_PATH . 'admin/admin-list-query.php';

        $card   = preg_replace('/\D/', '', (string) wp_unslash($_REQUEST['card'] ?? ''));
        $iban   = strtoupper(preg_replace('/\s+/', '', sanitize_text_field(wp_unslash($_REQUEST['iban'] ?? ''))));
        $user_q = sanitize_text_field(wp_unslash($_REQUEST['user_q'] ?? ''));

        if ($card === '' && $iban === '' && $user_q === '') {
            wp_send_json_error(['message' => 'حداقل یکی از فیلدهای جستجو را پر کنید.']);
        }

        $raw     = wwp_deposit_collect_matches($card, $iban, $user_q);
        $matches = [];
        foreach ($raw as $row) {
            $matches[] = wwp_deposit_enrich_match_for_admin($row);
        }

        wp_send_json_success([
            'count'   => count($matches),
            'matches' => $matches,
        ]);
    }

    public function ajax_manual_deposit_batch()
    {
        check_ajax_referer('medyar_deposit_ajax', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز'], 403);
        }

        $raw_items = wp_unslash($_POST['items'] ?? []);
        if (is_string($raw_items)) {
            $decoded = json_decode($raw_items, true);
            $raw_items = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw_items) || empty($raw_items)) {
            wp_send_json_error(['message' => 'صف واریز خالی است.']);
        }

        $max = 50;
        if (count($raw_items) > $max) {
            wp_send_json_error(['message' => sprintf('حداکثر %d واریز در هر ارسال مجاز است.', $max)]);
        }

        $ok     = [];
        $failed = [];

        foreach ($raw_items as $idx => $item) {
            if (!is_array($item)) {
                $failed[] = [
                    'index'   => (int) $idx,
                    'message' => 'داده نامعتبر',
                ];
                continue;
            }
            $user_id = absint($item['user_id'] ?? 0);
            $amount  = wallet_sanitize_amount($item['amount'] ?? 0);
            $card    = preg_replace('/\D/', '', (string) ($item['card_number'] ?? ''));
            $iban    = strtoupper(preg_replace('/\s+/', '', sanitize_text_field((string) ($item['iban'] ?? ''))));

            $user = $user_id > 0 ? get_userdata($user_id) : false;
            $label = $user ? (string) ($user->display_name ?: $user->user_login) : '';

            $result = $this->execute_manual_deposit($user_id, $amount, $card, $iban);
            if (is_wp_error($result)) {
                $failed[] = [
                    'index'       => (int) $idx,
                    'user_id'     => $user_id,
                    'display_name'=> $label,
                    'card_number' => $card,
                    'iban'        => $iban,
                    'amount'      => $amount,
                    'message'     => $result->get_error_message(),
                ];
                continue;
            }
            $ok[] = [
                'index'        => (int) $idx,
                'user_id'      => $user_id,
                'display_name' => $label,
                'card_number'  => $card,
                'iban'         => $iban,
                'amount'       => $amount,
            ];
        }

        $token = wp_generate_password(12, false, false);
        $log   = [
            'created_at'  => time(),
            'admin_id'    => get_current_user_id(),
            'ok'          => $ok,
            'failed'      => $failed,
            'ok_count'    => count($ok),
            'fail_count'  => count($failed),
            'ok_total'    => array_sum(array_map(static function ($r) {
                return (float) ($r['amount'] ?? 0);
            }, $ok)),
            'fail_total'  => array_sum(array_map(static function ($r) {
                return (float) ($r['amount'] ?? 0);
            }, $failed)),
        ];
        set_transient('wwp_deposit_batch_log_' . get_current_user_id() . '_' . $token, $log, 15 * MINUTE_IN_SECONDS);

        wp_send_json_success([
            'ok'         => $ok,
            'failed'     => $failed,
            'ok_count'   => count($ok),
            'fail_count' => count($failed),
            'log_token'  => $token,
        ]);
    }
    private function get_admin_display_name(): string
    {
        $admin = wp_get_current_user();
        if (!$admin || !$admin->exists()) {
            return 'ادمین';
        }
        return $admin->display_name ?: ($admin->user_login ?: 'ادمین');
    }
    private function sanitize_support_admin_ids($raw)
    {
        if (!is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $raw_id) {
            $uid = absint($raw_id);
            if ($uid <= 0) {
                continue;
            }
            $user = get_userdata($uid);
            if (!$user instanceof WP_User) {
                continue;
            }
            if (!array_intersect(['administrator', 'author'], (array) $user->roles)) {
                continue;
            }
            $ids[] = $uid;
        }

        return array_values(array_unique($ids));
    }

    public function handle_save_settings()
    {
        if (!current_user_can('manage_options')) wp_die('دسترسی غیرمجاز');
        check_admin_referer('medyar_save_settings', 'settings_nonce');
        $sms_enabled = isset($_POST['wallet_sms_enabled']) ? 1 : 0;
        update_option('wallet_sms_enabled', $sms_enabled, false);
        if (function_exists('medyar_wallet_sanitize_max_deposit')) {
            update_option('medyar_wallet_max_deposit', medyar_wallet_sanitize_max_deposit($_POST['medyar_wallet_max_deposit'] ?? 0), false);
        }
        if (function_exists('medyar_wallet_sanitize_max_withdraw')) {
            update_option('medyar_wallet_max_withdraw', medyar_wallet_sanitize_max_withdraw($_POST['medyar_wallet_max_withdraw'] ?? 0), false);
        }
        $deposit_withdrawal_admin_ids = $this->sanitize_support_admin_ids($_POST['medyar_deposit_withdrawal_admin_ids'] ?? []);
        update_option('medyar_deposit_withdrawal_admin_ids', $deposit_withdrawal_admin_ids, false);
        wp_redirect(add_query_arg(['page' => 'medyar-wallet-settings', 'updated' => 'true'], admin_url('admin.php')));
        exit;
    }
}
