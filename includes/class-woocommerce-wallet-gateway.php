<?php

/**
 * WooCommerce Wallet Payment Gateway
 * 
 * Integrates wallet as a payment method in WooCommerce
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * برچسب fee پرداخت جزئی از کیف پول.
 *
 * هم برای اعمال fee روی سبد و هم برای خواندن مبلغ واقعاً اعمال‌شده روی سفارش
 * استفاده می‌شود؛ تنها منبع حقیقت مبلغ کسر از کیف پول همین fee است.
 */
if (!defined('WALLET_PARTIAL_FEE_LABEL')) {
    define('WALLET_PARTIAL_FEE_LABEL', 'پرداخت از کیف پول');
}

/**
 * Check if WooCommerce is active and initialize gateway
 */
function wallet_init_woocommerce_gateway()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    /**
     * Wallet Payment Gateway Class
     */
    class WC_Wallet_Gateway extends WC_Payment_Gateway
    {

        private $wallet_manager;

        /**
         * Constructor
         */
        public function __construct()
        {
            $this->id = 'wallet';
            $this->icon = '';
            $this->has_fields = true;
            $this->method_title = 'کیف پول';
            $this->method_description = 'پرداخت از طریق موجودی کیف پول کاربر';

            $this->supports = array(
                'products',
                'refunds'
            );

            // Load settings
            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->enabled = $this->get_option('enabled');

            // Load wallet manager
            $this->wallet_manager = Wallet_Manager::get_instance();

            // Save settings
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

            // Enqueue scripts
            add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
        }

        /**
         * Initialize gateway settings form fields
         */
        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => 'فعال/غیرفعال',
                    'type' => 'checkbox',
                    'label' => 'فعال‌سازی پرداخت از کیف پول',
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => 'عنوان',
                    'type' => 'text',
                    'description' => 'عنوانی که کاربر در صفحه تسویه‌حساب می‌بیند',
                    'default' => 'کیف پول',
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => 'توضیحات',
                    'type' => 'textarea',
                    'description' => 'توضیحاتی که در صفحه تسویه‌حساب نمایش داده می‌شود',
                    'default' => 'پرداخت از موجودی کیف پول خود',
                ),
                'allow_partial' => array(
                    'title' => 'پرداخت جزئی',
                    'type' => 'checkbox',
                    'label' => 'اجازه پرداخت جزئی از کیف پول',
                    'description' => 'اگر موجودی کافی نباشد، امکان پرداخت مابقی از درگاه دیگر',
                    'default' => 'yes'
                ),
            );
        }

        /**
         * Enqueue payment scripts
         */
        public function payment_scripts()
        {
            if (!is_checkout()) {
                return;
            }

            wp_enqueue_script('wallet-checkout', WALLET_PLUGIN_URL . 'assets/js/checkout.js', array('jquery'), WALLET_PLUGIN_VERSION, true);

            $user_id_for_script = get_current_user_id();
            $cart_total_for_script = WC()->cart ? WC()->cart->get_total('edit') : 0;
            $balance_for_script = $user_id_for_script ? $this->wallet_manager->get_balance($user_id_for_script) : 0;
            $partial_session = WC()->session ? WC()->session->get('wallet_partial_payment') : 0;

            wp_localize_script('wallet-checkout', 'walletCheckout', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wallet_partial_payment'),
                'balance' => $balance_for_script,
                'cart_total' => $cart_total_for_script,
                'partial_active' => $partial_session > 0 ? 'yes' : 'no',
                'error_need_gateway' => 'لطفا برای پرداخت مبلغ باقی مانده، یک درگاه پرداخت انتخاب کنید.',
            ));
        }

        /**
         * Check if gateway is available
         * کیف پول همیشه به عنوان روش پرداخت نشان داده می‌شود.
         * اما ثبت سفارش فقط زمانی موفق است که مبلغ قابل پرداخت = 0 باشد.
         */
        public function is_available()
        {
            if ($this->enabled !== 'yes') {
                return false;
            }

            if (!is_user_logged_in()) {
                return false;
            }

            if (!WC()->cart || WC()->cart->is_empty()) {
                return false;
            }

            return true;
        }

        /**
         * Payment fields (shown at checkout)
         * سه حالت:
         * 1. موجودی = 0 → checkbox disabled
         * 2. موجودی < مبلغ سفارش → checkbox فعال، پرداخت جزئی
         * 3. موجودی >= مبلغ سفارش → checkbox فعال، مبلغ = 0 و سفارش ثبت می‌شود
         */
        public function payment_fields()
        {
            if (!is_user_logged_in()) {
                echo '<p>برای استفاده از کیف پول باید وارد حساب کاربری خود شوید.</p>';
                return;
            }

            $user_id    = get_current_user_id();
            $balance    = $this->wallet_manager->get_balance($user_id);
            $cart_total = WC()->cart ? WC()->cart->get_total('edit') : 0;

            $is_partial_active = WC()->session && WC()->session->get('wallet_partial_payment') > 0;
            $checked           = $is_partial_active ? 'checked="checked"' : '';

            // وضعیت checkbox
            $disabled     = ($balance <= 0) ? 'disabled="disabled"' : '';
            $label_suffix = '';

            if ($balance <= 0) {
                $label_suffix = '<span class="wallet-badge-disabled">موجودی ندارید</span>';
            } elseif ($balance >= $cart_total) {
                $label_suffix = '<span class="wallet-badge-full">پرداخت کامل از کیف پول</span>';
            } else {
                $label_suffix = '<span class="wallet-badge-partial">کسر ' . wc_price($balance) . ' از مبلغ سفارش</span>';
            }

            echo '<div class="wallet-card">';

            // نمایش موجودی
            echo '<div class="wallet-row">';
            echo '<span class="wallet-label">موجودی کیف پول</span>';
            echo '<span class="wallet-price">' . wc_price($balance) . '</span>';
            echo '</div>';

            // checkbox همیشه نمایش داده می‌شود
            echo '
            <div class="wallet-partial">
                <div class="wallet-switch-wrapper">
                    <label class="wallet-switch">';

            echo '<input type="checkbox" id="use_wallet_partial" name="use_wallet_partial" value="1" ' . $checked . ' ' . $disabled . ' />';
            echo '<span class="wallet-slider"></span>';

            echo '</label>
                    <span class="wallet-switch-label">
                        استفاده از موجودی کیف پول
                        ' . $label_suffix . '
                    </span>
                </div>
            </div>';

            echo '</div>';

            if ($this->description) {
                echo '<p>' . wp_kses_post($this->description) . '</p>';
            }
        }

        /**
         * Add inline JavaScript for partial payment
         * Note: This is a fallback, main functionality is handled by checkout.js
         */
        private function add_partial_payment_inline_script()
        {
            // The main functionality is handled by checkout.js
            // This is kept for backward compatibility
        }

        /**
         * AJAX handler for partial payment (kept for backward compatibility)
         */
        public function ajax_apply_wallet_partial()
        {
            wallet_ajax_apply_partial_payment();
        }

        /**
         * Process payment
         * فقط زمانی سفارش ثبت می‌شود که مبلغ قابل پرداخت = 0 باشد
         * (یعنی موجودی کیف پول کل مبلغ را پوشش داده است)
         * در غیر این صورت کاربر باید درگاه پرداخت دیگری انتخاب کند.
         */
        public function process_payment($order_id)
        {
            $order      = wc_get_order($order_id);
            $user_id    = $order->get_user_id();
            $order_total = floatval($order->get_total());

            if (!$user_id) {
                wc_add_notice('خطا در شناسایی کاربر', 'error');
                return array('result' => 'failure');
            }

            // اگر مبلغ قابل پرداخت 0 شده (کیف پول کل مبلغ را پوشش داده)
            if ($order_total <= 0) {
                // مبلغ کسر = همان fee منفی که واقعاً روی سفارش اعمال شده است، نه مقدار session.
                // مقدار session ممکن است قدیمی و بزرگ‌تر از سبد فعلی باشد (حذف آیتم/کد تخفیف)
                // و کسر آن باعث برداشت بیشتر از تخفیفی می‌شود که کاربر گرفته است.
                $wallet_amount = $this->get_order_wallet_fee_amount($order);

                if ($wallet_amount <= 0) {
                    wc_add_notice('خطا در اطلاعات پرداخت کیف پول', 'error');
                    return array('result' => 'failure');
                }

                $result = $this->wallet_manager->reduce_balance(
                    $user_id,
                    $wallet_amount,
                    'spend',
                    array(
                        'transaction_code' => 'ORDER_' . $order->get_id(),
                        'description'      => 'پرداخت سفارش #' . $order->get_id() . ' از کیف پول'
                    )
                );

                if (is_wp_error($result)) {
                    wc_add_notice($result->get_error_message(), 'error');
                    return array('result' => 'failure');
                }

                $order->payment_complete();
                $order->add_order_note(sprintf('پرداخت کامل از کیف پول: %s تومان', number_format($wallet_amount, 0)));
                $order->update_meta_data('_wallet_payment_amount', $wallet_amount);
                $order->update_meta_data('_wallet_payment_type', 'full_from_wallet');
                $order->save();

                WC()->session->set('wallet_partial_payment', null);
                WC()->session->set('wallet_partial_user_id', null);
                WC()->cart->empty_cart();

                return array(
                    'result'   => 'success',
                    'redirect' => $this->get_return_url($order)
                );
            }

            // مبلغ باقیمانده دارد - کاربر باید درگاه دیگری انتخاب کند
            wc_add_notice('لطفا برای پرداخت مبلغ باقی‌مانده، یک درگاه پرداخت انتخاب کنید.', 'error');
            return array('result' => 'failure');
        }

        /**
         * مبلغ واقعی پرداخت‌شده از کیف پول روی این سفارش = مجموع fee منفی کیف پول.
         *
         * تنها منبع معتبر برای کسر موجودی؛ مقدار session فقط «سقف مجاز» است و
         * ممکن است با سبد فعلی هم‌خوان نباشد.
         */
        private function get_order_wallet_fee_amount($order): float
        {
            $total = 0.0;

            foreach ($order->get_fees() as $fee) {
                $fee_total = (float) $fee->get_total();
                if ($fee->get_name() === WALLET_PARTIAL_FEE_LABEL && $fee_total < 0) {
                    $total += abs($fee_total);
                }
            }

            return $total;
        }

        /**
         * Process full payment from wallet
         */
        private function process_full_payment($order, $user_id, $balance, $order_total)
        {
            if ($balance < $order_total) {
                wc_add_notice('موجودی کیف پول شما کافی نیست', 'error');
                return array('result' => 'failure');
            }



            // Reduce wallet balance
            $result = $this->wallet_manager->reduce_balance(
                $user_id,
                $order_total,
                'spend',
                array(
                    'transaction_code' => 'ORDER_' . $order->get_id(),
                    'description' => 'پرداخت سفارش #' . $order->get_id()
                )
            );

            if (is_wp_error($result)) {
                wc_add_notice($result->get_error_message(), 'error');
                return array('result' => 'failure');
            }

            // Mark order as paid
            $order->payment_complete();
            $order->add_order_note(
                sprintf('پرداخت از کیف پول: %s تومان', number_format($order_total, 0))
            );

            // Store payment info in order meta
            $order->update_meta_data('_wallet_payment_amount', $order_total);
            $order->update_meta_data('_wallet_payment_type', 'full');
            $order->save();

            // Empty cart
            WC()->cart->empty_cart();

            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order)
            );
        }

        /**
         * Process refund
         */
        public function process_refund($order_id, $amount = null, $reason = '')
        {
            $order = wc_get_order($order_id);
            $user_id = $order->get_user_id();

            if (!$user_id) {
                return new WP_Error('error', 'کاربر یافت نشد');
            }

            $wallet_payment = $order->get_meta('_wallet_payment_amount');
            $partial_payment = $order->get_meta('_wallet_partial_payment');

            $total_wallet_paid = $wallet_payment ? $wallet_payment : $partial_payment;

            if (!$total_wallet_paid) {
                return new WP_Error('error', 'این سفارش از کیف پول پرداخت نشده است');
            }

            if ($amount === null) {
                $amount = $total_wallet_paid;
            }
            // Refund to wallet
            $result = $this->wallet_manager->add_balance(
                $user_id,
                $amount,
                'charge',
                array(
                    'transaction_code' => 'REFUND_ORDER_' . $order_id,
                    'description' => 'بازگشت وجه سفارش #' . $order_id . ($reason ? ' - ' . $reason : '')
                )
            );

            if (is_wp_error($result)) {
                return $result;
            }

            $order->add_order_note(
                sprintf('بازگشت %s تومان به کیف پول کاربر', number_format($amount, 0))
            );

            return true;
        }
    }

    /**
     * Add gateway to WooCommerce payment gateways
     */
    function wallet_add_gateway_class($gateways)
    {
        $gateways[] = 'WC_Wallet_Gateway';
        return $gateways;
    }

    // Register the gateway with WooCommerce
    add_filter('woocommerce_payment_gateways', 'wallet_add_gateway_class', 10);
}

// Initialize gateway on plugins loaded
add_action('plugins_loaded', 'wallet_init_woocommerce_gateway', 20);

/**
 * Display wallet payment info in admin order details
 */
add_action('woocommerce_admin_order_data_after_billing_address', 'wallet_display_payment_info_admin');

function wallet_display_payment_info_admin($order)
{
    $wallet_amount = $order->get_meta('_wallet_payment_amount');
    $partial_amount = $order->get_meta('_wallet_partial_payment');

    if ($wallet_amount || $partial_amount) {
        echo '<div class="order_data_column" style="clear:both; padding-top: 15px; width: 100% !important;">';
        echo '<h3 style="border-bottom: 1px solid #ddd; padding-bottom: 10px;">اطلاعات پرداخت کیف پول</h3>';

        if ($wallet_amount) {
            echo '<p><strong>مبلغ پرداختی از کیف پول:</strong> ' . wc_price($wallet_amount) . '</p>';
        }

        if ($partial_amount) {
            echo '<p><strong>پرداخت جزئی از کیف پول:</strong> ' . wc_price($partial_amount) . '</p>';
            echo '<p><em>مابقی از درگاه دیگر پرداخت شده است</em></p>';
        }

        echo '</div>';
    }
}

/**
 * Display wallet payment info in customer order view
 */
add_action('woocommerce_order_details_after_order_table', 'wallet_display_payment_info_customer');

function wallet_display_payment_info_customer($order)
{
    $wallet_amount = $order->get_meta('_wallet_payment_amount');
    $partial_amount = $order->get_meta('_wallet_partial_payment');

    if ($wallet_amount || $partial_amount) {
        echo '<section class="woocommerce-wallet-payment-info" style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-right: 4px solid #2271b1;">';
        echo '<h2 style="margin-top: 0;">اطلاعات پرداخت از کیف پول</h2>';

        if ($wallet_amount) {
            echo '<p>مبلغ پرداختی از کیف پول: <strong>' . wc_price($wallet_amount) . '</strong></p>';
        }

        if ($partial_amount) {
            echo '<p>پرداخت جزئی از کیف پول: <strong>' . wc_price($partial_amount) . '</strong></p>';
        }

        echo '</section>';
    }
}

/**
 * AJAX handler for partial payment
 * Registered outside class to ensure it's always available
 */
function wallet_ajax_apply_partial_payment()
{
    check_ajax_referer('wallet_partial_payment', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error();
    }

    $user_id = get_current_user_id();
    $wallet_manager = Wallet_Manager::get_instance();

    $balance = $wallet_manager->get_balance($user_id);

    if (!$balance || $balance <= 0) {
        wp_send_json_error(['message' => 'موجودی کافی نیست']);
    }

    $cart_total = WC()->cart ? WC()->cart->get_total('edit') : 0;

    $amount = min($balance, $cart_total);

    WC()->session->set('wallet_partial_payment', $amount);
    WC()->session->set('wallet_partial_user_id', $user_id);

    wp_send_json_success([
        'message' => 'پرداخت جزئی اعمال شد'
    ]);
}
add_action('wp_ajax_apply_wallet_partial_payment', 'wallet_ajax_apply_partial_payment');

function wallet_ajax_remove_partial_payment()
{
    check_ajax_referer('wallet_partial_payment', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error();
    }

    WC()->session->__unset('wallet_partial_payment');
    WC()->session->__unset('wallet_partial_user_id');

    wp_send_json_success();
}
add_action('wp_ajax_remove_wallet_partial_payment', 'wallet_ajax_remove_partial_payment');


/**
 * وقتی fee کیف پول اعمال شده و مبلغ قابل پرداخت صفر می‌شود،
 * WooCommerce به صورت پیش‌فرض هیچ درگاهی نشان نمی‌دهد.
 * این filter همیشه payment section را نمایش می‌دهد تا کاربر
 * بتواند در صورت تمایل از کیف پول استفاده نکند و درگاه دیگری انتخاب کند.
 */
add_filter('woocommerce_cart_needs_payment', function ($needs_payment) {
    // اگر session کیف پول فعال است، همیشه payment section را نشان بده
    $partial = WC()->session ? WC()->session->get('wallet_partial_payment') : 0;
    if ($partial > 0) {
        return true;
    }
    return $needs_payment;
});

/**
 * وقتی مبلغ قابل پرداخت صفر است (به خاطر fee کیف پول)،
 * WooCommerce لیست درگاه‌ها را خالی برمی‌گرداند.
 * این filter مطمئن می‌شود که درگاه کیف پول همیشه در لیست باشد.
 */
add_filter('woocommerce_available_payment_gateways', function ($gateways) {
    if (!is_checkout() && !defined('DOING_AJAX')) {
        return $gateways;
    }

    $partial = WC()->session ? WC()->session->get('wallet_partial_payment') : 0;
    if ($partial <= 0) {
        return $gateways;
    }

    // اگر wallet در لیست نیست، دستی اضافه‌اش کن
    if (!isset($gateways['wallet'])) {
        $all_gateways = WC()->payment_gateways->payment_gateways();
        if (isset($all_gateways['wallet']) && $all_gateways['wallet']->enabled === 'yes') {
            $gateways['wallet'] = $all_gateways['wallet'];
        }
    }

    return $gateways;
});

add_action('woocommerce_cart_calculate_fees', function ($cart) {

    if (is_admin() && !defined('DOING_AJAX')) {
        return;
    }

    $amount  = WC()->session->get('wallet_partial_payment');
    $user_id = WC()->session->get('wallet_partial_user_id');

    if (!$amount || !$user_id) {
        return;
    }

    if ($user_id != get_current_user_id()) {
        return;
    }

    // محاسبه مبلغ واقعی قابل پرداخت (subtotal + shipping)
    $cart_total = $cart->subtotal + $cart->shipping_total;
    $amount     = min(floatval($amount), $cart_total);

    if ($amount > 0) {
        $cart->add_fee(WALLET_PARTIAL_FEE_LABEL, -$amount);
    }
});

