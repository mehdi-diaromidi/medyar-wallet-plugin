<?php
/**
 * WooCommerce My Account Endpoints
 *
 * Registers wallet dashboard in WooCommerce My Account
 */

if (!defined('ABSPATH')) {
    exit;
}

if (class_exists('Wallet_WooCommerce_Endpoints')) {
    return;
}

class Wallet_WooCommerce_Endpoints
{
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
        add_action('init', [$this, 'add_endpoints']);
        add_filter('woocommerce_account_menu_items', [$this, 'add_menu_item']);
        add_action('woocommerce_account_wallet_endpoint', [$this, 'endpoint_content']);
        add_filter('the_title', [$this, 'endpoint_title'], 10, 2);
        add_filter('query_vars', [$this, 'add_query_vars'], 0);
    }

    public function add_endpoints()
    {
        add_rewrite_endpoint('wallet', EP_ROOT | EP_PAGES);
    }

    public function add_query_vars($vars)
    {
        $vars[] = 'wallet';
        return $vars;
    }

    public function add_menu_item($items)
    {
        $new_items = [];

        foreach ($items as $key => $value) {
            $new_items[$key] = $value;
            if ($key === 'dashboard') {
                $new_items['wallet'] = 'کیف پول';
            }
        }

        if (!isset($new_items['wallet'])) {
            $new_items['wallet'] = 'کیف پول';
        }

        return $new_items;
    }

    public function endpoint_content()
    {
        if (class_exists('MTW_MyAccount_UI')) {
            MTW_MyAccount_UI::get_instance()->render();
            return;
        }

        $template = locate_template('woocommerce/myaccount/wallet-dashboard.php');
        if ($template) {
            include $template;
            return;
        }

        echo '<p>' . esc_html__('رابط کیف پول در دسترس نیست.', 'medyar-toman-wallet') . '</p>';
    }

    public function endpoint_title($title, $id = 0)
    {
        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('wallet') && in_the_loop()) {
            $title = 'کیف پول من';
        }
        return $title;
    }

    public static function get_user_balance_data($user_id = null)
    {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }

        if (!$user_id) {
            return [
                'irt_balance'    => 0,
                'silver_balance' => 0,
                'total_asset'    => 0,
                'silver_price'   => 0,
            ];
        }

        $irt_balance = Wallet_Manager::get_instance()->get_balance($user_id);

        return [
            'irt_balance'    => $irt_balance,
            'silver_balance' => 0,
            'total_asset'    => (int) round((float) $irt_balance),
            'silver_price'   => 0,
        ];
    }

    public static function flush_rewrite_rules()
    {
        add_rewrite_endpoint('wallet', EP_ROOT | EP_PAGES);
        flush_rewrite_rules();
    }
}

add_action('plugins_loaded', function () {
    if (class_exists('WooCommerce')) {
        Wallet_WooCommerce_Endpoints::get_instance();
    }
}, 20);
