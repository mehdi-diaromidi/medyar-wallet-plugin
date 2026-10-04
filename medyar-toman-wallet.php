<?php
/**
 * Plugin Name: Medyar Toman Wallet
 * Description: Toman wallet, deposits, withdrawals, Zarinpal charge, bank cards.
 * Version: 1.0.0
 * Author: M.Mehdi
 * Text Domain: medyar-toman-wallet
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MTW_VERSION', '1.0.0');
define('MTW_PATH', plugin_dir_path(__FILE__));
define('MTW_URL', plugin_dir_url(__FILE__));
define('MTW_BASENAME', plugin_basename(__FILE__));

define('WALLET_PLUGIN_VERSION', MTW_VERSION);
define('WALLET_PLUGIN_PATH', MTW_PATH);
define('WALLET_PLUGIN_URL', MTW_URL);
define('WALLET_PLUGIN_BASENAME', MTW_BASENAME);

final class Medyar_Toman_Wallet
{
    private static $instance = null;

    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        register_activation_hook(__FILE__, [$this, 'activate']);
        add_action('plugins_loaded', [$this, 'load_dependencies'], 5);
        add_action('plugins_loaded', [$this, 'init'], 10);
        add_action('rest_api_init', [$this, 'register_rest_routes'], 20);
    }

    public function load_dependencies()
    {
        require_once MTW_PATH . 'helpers/stubs.php';
        require_once MTW_PATH . 'helpers/security.php';
        require_once MTW_PATH . 'helpers/atomic-balance.php';
        require_once MTW_PATH . 'helpers/validations.php';
        require_once MTW_PATH . 'helpers/wallet-deposit-settings.php';
        require_once MTW_PATH . 'helpers/wallet-withdraw-settings.php';
        require_once MTW_PATH . 'admin/admin-helpers.php';

        require_once MTW_PATH . 'includes/class-transaction-manager.php';
        require_once MTW_PATH . 'includes/class-payment-gateway.php';
        require_once MTW_PATH . 'includes/class-zarinpal-gateway.php';
        require_once MTW_PATH . 'includes/class-wallet-manager.php';
        require_once MTW_PATH . 'includes/class-wallet-sms.php';
        require_once MTW_PATH . 'includes/class-payment-handler.php';
        require_once MTW_PATH . 'includes/class-bank-cards.php';

        if (class_exists('WooCommerce')) {
            require_once MTW_PATH . 'includes/class-woocommerce-wallet-gateway.php';
            require_once MTW_PATH . 'includes/class-myaccount-ui.php';
            require_once MTW_PATH . 'includes/class-woocommerce-endpoints.php';
        }

        if (is_admin()) {
            require_once MTW_PATH . 'admin/class-admin-menu.php';
        }
    }

    public function init()
    {
        Wallet_Transaction_Manager::get_instance();
        Wallet_Manager::get_instance();
        Medyar_Bank_Cards::init();

        if (class_exists('WooCommerce')) {
            MTW_MyAccount_UI::get_instance();
            Wallet_WooCommerce_Endpoints::get_instance();
        }

        if (is_admin()) {
            Wallet_Admin_Menu::get_instance();
        }
    }

    public function register_rest_routes()
    {
        register_rest_route('medyar/v1', '/balance', [
            'methods'             => 'GET',
            'callback'            => [$this, 'rest_get_balance'],
            'permission_callback' => function () {
                return is_user_logged_in();
            },
        ]);
    }

    public function rest_get_balance($request)
    {
        $user_id = get_current_user_id();
        $balance = Wallet_Manager::get_instance()->get_balance($user_id);

        return rest_ensure_response([
            'irt_balance' => $balance,
            'toman_balance' => $balance,
        ]);
    }

    public function activate()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $tx_table = $wpdb->prefix . 'wallet_transactions';
        dbDelta("CREATE TABLE $tx_table (
            id               bigint(20) unsigned  NOT NULL AUTO_INCREMENT,
            user_id          bigint(20) unsigned  NOT NULL,
            amount           decimal(20,3)        NOT NULL,
            balance_type     varchar(20)          NOT NULL,
            operation_type   varchar(50)          NOT NULL,
            status           varchar(20)          NOT NULL DEFAULT 'pending',
            transaction_code varchar(32)          NOT NULL,
            description      text                 DEFAULT NULL,
            p_info           text                 DEFAULT NULL,
            linked_tx_id     bigint(20) unsigned  DEFAULT NULL,
            trade_tx_code    varchar(32)          DEFAULT NULL,
            created_at       datetime             NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY transaction_code (transaction_code),
            KEY user_id (user_id),
            KEY balance_type (balance_type),
            KEY operation_type (operation_type),
            KEY status (status),
            KEY created_at (created_at),
            KEY linked_tx_id (linked_tx_id),
            KEY trade_tx_code (trade_tx_code)
        ) $charset_collate;");

        $pay_table = $wpdb->prefix . 'wallet_payment_transactions';
        dbDelta("CREATE TABLE $pay_table (
            id           bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            tx_id        bigint(20) unsigned NOT NULL,
            user_id      bigint(20) unsigned NOT NULL,
            gateway      varchar(50)         NOT NULL,
            amount       int(11) unsigned    NOT NULL,
            authority    varchar(100)        DEFAULT NULL,
            reference_id varchar(100)        DEFAULT NULL,
            card_pan     varchar(20)         DEFAULT NULL,
            card_hash    varchar(100)        DEFAULT NULL,
            fee          int(11)             NOT NULL DEFAULT 0,
            fee_type     varchar(20)         DEFAULT NULL,
            status       varchar(20)         NOT NULL DEFAULT 'pending',
            verified_at  datetime            DEFAULT NULL,
            created_at   datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY reference_id (reference_id),
            KEY tx_id (tx_id),
            KEY user_id (user_id),
            KEY status (status)
        ) $charset_collate;");

        if (class_exists('WooCommerce')) {
            add_rewrite_endpoint('wallet', EP_ROOT | EP_PAGES);
            flush_rewrite_rules();
        }
    }
}

Medyar_Toman_Wallet::instance();
