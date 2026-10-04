<?php
/**
 * Shared admin list query / render helpers (AJAX filters, CSV, pagination).
 * Admin-only — does not affect frontend wallet/trading APIs.
 */
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('mtw_admin_list_parse_args')) {
    /**
     * @param array<string,mixed> $src
     * @return array<string,mixed>
     */
    function mtw_admin_list_parse_args(array $src): array
    {
        $orderby = sanitize_key((string) ($src['orderby'] ?? 'created_at'));
        $order   = strtoupper((string) ($src['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $allowed_orderby = ['created_at', 'amount', 'status', 'id', 'unit_price', 'total_price', 'canceled_at', 'registered', 'updated_at'];
        if (!in_array($orderby, $allowed_orderby, true)) {
            $orderby = 'created_at';
        }

        return [
            'status'         => sanitize_text_field((string) ($src['status'] ?? $src['filter_status'] ?? '')),
            'user_q'         => sanitize_text_field((string) ($src['user_q'] ?? '')),
            'user_id'        => absint($src['user_id'] ?? $src['filter_user'] ?? 0),
            'date_from'      => sanitize_text_field((string) ($src['date_from'] ?? '')),
            'date_to'        => sanitize_text_field((string) ($src['date_to'] ?? '')),
            'amount_min'     => isset($src['amount_min']) && $src['amount_min'] !== '' ? (float) $src['amount_min'] : null,
            'amount_max'     => isset($src['amount_max']) && $src['amount_max'] !== '' ? (float) $src['amount_max'] : null,
            'tx_q'           => sanitize_text_field((string) ($src['tx_q'] ?? '')),
            'card_iban'      => sanitize_text_field((string) ($src['card_iban'] ?? '')),
            'operation_type' => sanitize_text_field((string) ($src['operation_type'] ?? $src['filter_operation'] ?? '')),
            'balance_type'   => sanitize_text_field((string) ($src['balance_type'] ?? $src['filter_type'] ?? '')),
            'gateway'        => sanitize_text_field((string) ($src['gateway'] ?? '')),
            'list_mode'      => sanitize_key((string) ($src['list_mode'] ?? 'all')),
            'doc_status'     => sanitize_key((string) ($src['doc_status'] ?? '')),
            'doc_type'       => sanitize_key((string) ($src['doc_type'] ?? '')),
            'phone'          => sanitize_text_field((string) ($src['phone'] ?? '')),
            'asset_type'     => sanitize_key((string) ($src['asset_type'] ?? '')),
            'grant_status'   => sanitize_key((string) ($src['grant_status'] ?? '')),
            'overdue_only'   => !empty($src['overdue_only']),
            'orderby'        => $orderby,
            'order'          => $order,
            'paged'          => max(1, absint($src['paged'] ?? 1)),
            'per_page'       => min(100, max(1, absint($src['per_page'] ?? 50))),
        ];
    }
}

if (!function_exists('wwp_admin_resolve_user_ids_from_q')) {
    /**
     * Resolve user IDs from free-text: numeric ID, email, login, display_name, phone, national_id.
     *
     * @return int[]
     */
    function wwp_admin_resolve_user_ids_from_q(string $q, int $limit = 50): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }

        global $wpdb;
        $ids = [];

        if (ctype_digit($q)) {
            $uid = (int) $q;
            if ($uid > 0 && get_userdata($uid)) {
                $ids[] = $uid;
            }
        }

        $like = '%' . $wpdb->esc_like($q) . '%';
        $by_user = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->users}
             WHERE user_login LIKE %s OR user_email LIKE %s OR display_name LIKE %s
             LIMIT %d",
            $like,
            $like,
            $like,
            $limit
        ));
        foreach ($by_user as $id) {
            $ids[] = (int) $id;
        }

        $digits = preg_replace('/\D/', '', $q);
        if ($digits !== '' && strlen($digits) >= 4) {
            $phone_like = '%' . $wpdb->esc_like($digits) . '%';
            $meta_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT user_id FROM {$wpdb->usermeta}
                 WHERE meta_key IN ('billing_phone','user_phone','national_id')
                   AND meta_value LIKE %s
                 LIMIT %d",
                $phone_like,
                $limit
            ));
            foreach ($meta_ids as $id) {
                $ids[] = (int) $id;
            }

            $login_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->users} WHERE user_login LIKE %s LIMIT %d",
                '%' . $wpdb->esc_like($digits) . '%',
                $limit
            ));
            foreach ($login_ids as $id) {
                $ids[] = (int) $id;
            }
        }

        $ids = array_values(array_unique(array_filter($ids)));
        return array_slice($ids, 0, $limit);
    }
}

if (!function_exists('wwp_admin_sql_date_bounds')) {
    /**
     * Convert Persian digits to Latin.
     */
    function wwp_admin_en_digits(string $s): string
    {
        return strtr($s, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    /**
     * Normalize a filter date to Gregorian Y-m-d[ H:i[:s]].
     * Accepts Jalali (like PW product fields) or already-Gregorian values.
     */
    function wwp_admin_filter_date_to_gregorian(string $raw): string
    {
        $raw = trim(wwp_admin_en_digits($raw));
        if ($raw === '') {
            return '';
        }
        $raw = str_replace('T', ' ', $raw);
        $raw = str_replace('/', '-', $raw);

        if (!preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ ]+(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?)?$/', $raw, $m)) {
            return '';
        }

        $y  = (int) $m[1];
        $mo = (int) $m[2];
        $d  = (int) $m[3];
        $h  = isset($m[4]) ? (int) $m[4] : null;
        $i  = isset($m[5]) ? (int) $m[5] : null;
        $s  = isset($m[6]) ? (int) $m[6] : null;

        // Jalali years used in UI (e.g. 1403) — convert like Persian WooCommerce.
        if ($y < 1700) {
            $converted = '';
            if (class_exists('\Morilog\Jalali\Jalalian')) {
                try {
                    $j = \Morilog\Jalali\Jalalian::fromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $y, $mo, $d));
                    $converted = $j->toCarbon()->format('Y-m-d');
                } catch (\Throwable $e) {
                    $converted = '';
                }
            }
            if ($converted === '' && class_exists('\Morilog\Jalali\CalendarUtils')) {
                try {
                    $g = \Morilog\Jalali\CalendarUtils::toGregorian($y, $mo, $d);
                    if (is_array($g) && count($g) >= 3) {
                        $converted = sprintf('%04d-%02d-%02d', (int) $g[0], (int) $g[1], (int) $g[2]);
                    }
                } catch (\Throwable $e) {
                    $converted = '';
                }
            }
            if ($converted === '') {
                return '';
            }
            $ymd = $converted;
        } else {
            $ymd = sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }

        if ($h === null) {
            return $ymd;
        }
        if ($s === null) {
            return sprintf('%s %02d:%02d', $ymd, $h, (int) $i);
        }
        return sprintf('%s %02d:%02d:%02d', $ymd, $h, (int) $i, $s);
    }

    /**
     * Accepts Jalali or Gregorian date (Y-m-d / Y/m/d) or datetime.
     * Date-only from → start of day; date-only to → end of day.
     *
     * @return array{0:?string,1:?string} [from datetime, to datetime inclusive end]
     */
    function wwp_admin_sql_date_bounds(string $date_from, string $date_to): array
    {
        $from = null;
        $to   = null;

        $date_from = wwp_admin_filter_date_to_gregorian($date_from);
        $date_to   = wwp_admin_filter_date_to_gregorian($date_to);

        if ($date_from !== '') {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
                $from = $date_from . ' 00:00:00';
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $date_from)) {
                $from = $date_from . ':00';
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $date_from)) {
                $from = $date_from;
            }
        }
        if ($date_to !== '') {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
                $to = $date_to . ' 23:59:59';
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $date_to)) {
                $to = $date_to . ':59';
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $date_to)) {
                $to = $date_to;
            }
        }
        return [$from, $to];
    }
}

if (!function_exists('wwp_admin_tx_scope_sql')) {
    function wwp_admin_tx_scope_sql(string $scope): string
    {
        if ($scope === 'medyar_trades') {
            return "((operation_type = 'buy_silver' AND balance_type = 'silver') OR (operation_type = 'sell_silver' AND balance_type = 'toman'))";
        }
        if ($scope === 'recent') {
            return "NOT ((operation_type = 'buy_silver' AND balance_type = 'silver') OR (operation_type = 'sell_silver' AND balance_type = 'toman') OR (operation_type = 'withdraw' AND balance_type = 'toman'))";
        }
        if ($scope === 'withdraw') {
            return "(operation_type = 'withdraw' AND balance_type = 'toman')";
        }
        if ($scope === 'processing') {
            return "status = 'processing'";
        }
        if ($scope === 'manual_deposit') {
            return "(operation_type = 'charge' AND (description LIKE '%واریز دستی%' OR p_info LIKE '%admin_manual_deposit%'))";
        }
        return '1=1';
    }
}

if (!function_exists('wwp_admin_query_transactions')) {
    /**
     * @param array<string,mixed> $args from mtw_admin_list_parse_args + scope
     * @return array{rows:object[],total:int}
     */
    function wwp_admin_query_transactions(array $args, string $scope = 'recent'): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wallet_transactions';
        $where = [wwp_admin_tx_scope_sql($scope)];
        $params = [];

        $user_id = (int) ($args['user_id'] ?? 0);
        $user_q  = (string) ($args['user_q'] ?? '');
        if ($user_id <= 0 && $user_q !== '') {
            $uids = wwp_admin_resolve_user_ids_from_q($user_q);
            if (empty($uids)) {
                return ['rows' => [], 'total' => 0];
            }
            $ph = implode(',', array_fill(0, count($uids), '%d'));
            $where[] = "user_id IN ($ph)";
            foreach ($uids as $uid) {
                $params[] = $uid;
            }
        } elseif ($user_id > 0) {
            $where[]  = 'user_id = %d';
            $params[] = $user_id;
        }

        $status = (string) ($args['status'] ?? '');
        if ($scope === 'withdraw' && ($args['list_mode'] ?? '') === 'pending') {
            $where[] = "status IN ('pending','processing')";
        } elseif ($scope === 'withdraw' && ($args['list_mode'] ?? '') === 'history') {
            $where[] = "status NOT IN ('pending','processing')";
            if ($status !== '') {
                $where[]  = 'status = %s';
                $params[] = wallet_sanitize_status($status);
            }
        } elseif ($status !== '' && $scope !== 'processing') {
            $where[]  = 'status = %s';
            $params[] = wallet_sanitize_status($status);
        }

        [$from, $to] = wwp_admin_sql_date_bounds((string) ($args['date_from'] ?? ''), (string) ($args['date_to'] ?? ''));
        if ($from) {
            $where[]  = 'created_at >= %s';
            $params[] = $from;
        }
        if ($to) {
            $where[]  = 'created_at <= %s';
            $params[] = $to;
        }

        if ($args['amount_min'] !== null) {
            $where[]  = 'ABS(amount) >= %f';
            $params[] = (float) $args['amount_min'];
        }
        if ($args['amount_max'] !== null) {
            $where[]  = 'ABS(amount) <= %f';
            $params[] = (float) $args['amount_max'];
        }

        $tx_q = (string) ($args['tx_q'] ?? '');
        if ($tx_q !== '') {
            if (ctype_digit($tx_q)) {
                $where[]  = '(id = %d OR transaction_code LIKE %s)';
                $params[] = (int) $tx_q;
                $params[] = '%' . $wpdb->esc_like($tx_q) . '%';
            } else {
                $where[]  = 'transaction_code LIKE %s';
                $params[] = '%' . $wpdb->esc_like($tx_q) . '%';
            }
        }

        $op = (string) ($args['operation_type'] ?? '');
        if ($op !== '' && $scope === 'recent') {
            $where[]  = 'operation_type = %s';
            $params[] = sanitize_text_field($op);
        }
        $bal = (string) ($args['balance_type'] ?? '');
        if ($bal !== '' && $scope === 'recent') {
            $where[]  = 'balance_type = %s';
            $params[] = function_exists('wallet_sanitize_balance_type')
                ? wallet_sanitize_balance_type($bal)
                : sanitize_key($bal);
        }
        $gateway = (string) ($args['gateway'] ?? '');
        if ($gateway !== '' && $scope === 'recent') {
            $where[]  = 'p_info LIKE %s';
            $params[] = '%' . $wpdb->esc_like($gateway) . '%';
        }

        $card_iban = preg_replace('/\s+/', '', (string) ($args['card_iban'] ?? ''));
        if ($card_iban !== '' && $scope === 'withdraw') {
            $where[]  = 'p_info LIKE %s';
            $params[] = '%' . $wpdb->esc_like($card_iban) . '%';
        }

        $where_sql = implode(' AND ', $where);
        $orderby   = in_array($args['orderby'] ?? '', ['created_at', 'amount', 'status', 'id'], true)
            ? $args['orderby']
            : 'created_at';
        $order     = ($args['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        $per_page  = (int) ($args['per_page'] ?? 50);
        $paged     = (int) ($args['paged'] ?? 1);
        $offset    = ($paged - 1) * $per_page;

        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $list_sql  = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

        if ($params) {
            $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$params));
            $rows  = $wpdb->get_results($wpdb->prepare($list_sql, ...array_merge($params, [$per_page, $offset]))) ?: [];
        } else {
            $total = (int) $wpdb->get_var($count_sql);
            $rows  = $wpdb->get_results($wpdb->prepare($list_sql, $per_page, $offset)) ?: [];
        }

        return ['rows' => $rows, 'total' => $total];
    }
}

if (!function_exists('wwp_admin_render_pagination_html')) {
    function wwp_admin_render_pagination_html(int $total, int $page, int $per_page, string $entity): string
    {
        $total_pages = max(1, (int) ceil($total / max(1, $per_page)));
        ob_start();
        ?>
        <div class="wallet-pagination" data-wwp-pagination data-entity="<?php echo esc_attr($entity); ?>">
            <span class="total-count"><?php echo esc_html(number_format_i18n($total)); ?> مورد | صفحه <?php echo (int) $page; ?> از <?php echo (int) $total_pages; ?></span>
            <div class="pagination-links">
                <?php if ($page > 1): ?>
                    <a href="#" data-page="1">&laquo;&laquo;</a>
                    <a href="#" data-page="<?php echo (int) ($page - 1); ?>">&laquo;</a>
                <?php endif; ?>
                <?php
                $start = max(1, $page - 2);
                $end   = min($total_pages, $page + 2);
                for ($i = $start; $i <= $end; $i++):
                    if ($i === $page): ?>
                        <span class="current"><?php echo (int) $i; ?></span>
                    <?php else: ?>
                        <a href="#" data-page="<?php echo (int) $i; ?>"><?php echo (int) $i; ?></a>
                    <?php endif;
                endfor; ?>
                <?php if ($page < $total_pages): ?>
                    <a href="#" data-page="<?php echo (int) ($page + 1); ?>">&raquo;</a>
                    <a href="#" data-page="<?php echo (int) $total_pages; ?>">&raquo;&raquo;</a>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}

if (!function_exists('wwp_admin_render_tx_rows_html')) {
    /**
     * @param object[] $rows
     */
    function wwp_admin_render_tx_rows_html(array $rows, array $opts = []): string
    {
        $show_view     = !empty($opts['show_view']);
        $show_actions  = !empty($opts['show_approve_cancel']);
        $show_checkbox = !empty($opts['show_checkbox']);
        $hide_user     = !empty($opts['hide_user']);
        $redirect      = sanitize_key((string) ($opts['redirect_page'] ?? 'medyar-wallet-transactions'));
        $withdraw_mode = !empty($opts['withdraw_mode']);

        if (empty($rows)) {
            $cols = $withdraw_mode ? 11 : 11;
            return '<tr class="wwp-empty-row"><td colspan="' . (int) $cols . '">موردی یافت نشد.</td></tr>';
        }

        ob_start();
        foreach ($rows as $tx) {
            $user = get_user_by('id', (int) $tx->user_id);
            $p_info = !empty($tx->p_info) ? json_decode((string) $tx->p_info, true) : null;
            if (!is_array($p_info)) {
                $p_info = [];
            }
            echo '<tr data-tx-id="' . (int) $tx->id . '">';
            if ($withdraw_mode || $show_checkbox) {
                echo '<td>';
                if ($show_checkbox) {
                    echo '<input type="checkbox" class="wwp-row-check" value="' . (int) $tx->id . '">';
                }
                echo '</td>';
            }
            echo '<td><strong>#' . (int) $tx->id . '</strong></td>';
            if (!$hide_user) {
                echo '<td class="col-user">' . esc_html($user ? $user->display_name : 'نامشخص');
                if ($user && $withdraw_mode) {
                    echo '<br><small>' . esc_html($user->user_email) . '</small>';
                }
                echo '</td>';
            }
            if (!$withdraw_mode) {
                echo '<td>' . wwp_label_balance_type((string) $tx->balance_type) . '</td>';
                echo '<td>' . wwp_label_operation_type((string) $tx->operation_type) . '</td>';
            }
            echo '<td>' . wwp_format_amount((float) $tx->amount, (string) $tx->balance_type) . '</td>';
            if ($withdraw_mode) {
                echo '<td dir="ltr">' . esc_html($p_info['card_number'] ?? '-') . '</td>';
                echo '<td dir="ltr">' . esc_html($p_info['iban'] ?? '-') . '</td>';
            }
            $status       = (string) $tx->status;
            $op           = (string) ($tx->operation_type ?? '');
            $status_label = wwp_label_status($status);
            // فقط نمایش در جدول تراکنش‌های اخیر — DB تغییر نمی‌کند
            if (
                $redirect === 'medyar-wallet-recent-transactions'
                && $status === 'pending'
                && in_array($op, ['trade_buy', 'trade_sell'], true)
            ) {
                $status_label = 'ثبت در جدول';
            }
            echo '<td><span class="wallet-badge ' . esc_attr($status) . '">' . esc_html($status_label) . '</span></td>';
            if (!$withdraw_mode) {
                echo '<td>' . wwp_format_gateway($tx->p_info) . '</td>';
            }
            echo '<td>' . ($tx->transaction_code ? esc_html((string) $tx->transaction_code) : '-') . '</td>';
            if (!$withdraw_mode) {
                $desc = $tx->description ? wwp_plain_transaction_description((string) $tx->description) : '';
                echo '<td class="col-description"><span class="wwp-desc-clamp">' . ($desc !== '' ? esc_html($desc) : '-') . '</span></td>';
            } elseif ($withdraw_mode) {
                echo '<td class="col-description"><span class="wwp-desc-clamp">' . esc_html($tx->description ?: '-') . '</span></td>';
            }
            echo '<td>' . esc_html(date_i18n('Y/m/d H:i', strtotime((string) $tx->created_at))) . '</td>';
            if ($show_view || $show_actions || $withdraw_mode) {
                echo '<td><div class="wallet-action-buttons">';
                if ($show_view) {
                    echo '<button type="button" class="button button-small wwp-btn-view" data-tx-id="' . (int) $tx->id . '">مشاهده</button>';
                }
                if ($show_actions) {
                    $admin_post = esc_url(admin_url('admin-post.php'));
                    echo '<form method="post" action="' . $admin_post . '" class="wallet-form-inline">';
                    wp_nonce_field('medyar_update_transaction', 'medyar_update_nonce');
                    echo '<input type="hidden" name="action" value="medyar_update_transaction">';
                    echo '<input type="hidden" name="transaction_id" value="' . (int) $tx->id . '">';
                    echo '<input type="hidden" name="new_status" value="completed">';
                    echo '<input type="hidden" name="redirect_page" value="' . esc_attr($redirect) . '">';
                    echo '<button type="submit" class="button button-primary button-small" onclick="return confirm(\'تایید؟\')">تایید</button></form>';
                    echo '<form method="post" action="' . $admin_post . '" class="wallet-form-inline">';
                    wp_nonce_field('medyar_update_transaction', 'medyar_update_nonce');
                    echo '<input type="hidden" name="action" value="medyar_update_transaction">';
                    echo '<input type="hidden" name="transaction_id" value="' . (int) $tx->id . '">';
                    echo '<input type="hidden" name="new_status" value="canceled">';
                    echo '<input type="hidden" name="redirect_page" value="' . esc_attr($redirect) . '">';
                    echo '<button type="submit" class="button button-small wwp-btn-danger" onclick="return confirm(\'لغو؟\')">لغو</button></form>';
                } elseif ($withdraw_mode) {
                    echo '—';
                }
                echo '</div></td>';
            }
            echo '</tr>';
        }
        return (string) ob_get_clean();
    }
}

if (!function_exists('wwp_admin_filter_bar_open')) {
    /**
     * @param string[] $fields
     * @param array<string,mixed> $extra
     */
    function wwp_admin_filter_bar_open(string $entity, string $page_slug, array $fields, array $extra = []): void
    {
        $title = (string) ($extra['title'] ?? 'فیلتر و ابزارها');
        $export_action = 'mtw_admin_export_' . $entity;
        ?>
        <div class="wallet-card wallet-filter-card wwp-admin-list-bar"
             data-wwp-admin-list
             data-entity="<?php echo esc_attr($entity); ?>"
             data-page="<?php echo esc_attr($page_slug); ?>"
             data-storage-key="mtw_admin_list_state:<?php echo esc_attr($page_slug); ?><?php echo !empty($extra['storage_suffix']) ? esc_attr(':' . $extra['storage_suffix']) : ''; ?>">
            <h2><?php echo esc_html($title); ?></h2>
            <form class="wwp-admin-list-form">
                <div class="wwp-filter-grid">
                    <?php if (in_array('user_q', $fields, true)): ?>
                        <label>کاربر / موبایل / ایمیل
                            <input type="text" name="user_q" class="regular-text" placeholder="شناسه، موبایل، ایمیل…" autocomplete="off">
                        </label>
                    <?php endif; ?>
                    <?php if (in_array('status', $fields, true)): ?>
                        <label>وضعیت
                            <select name="status"><?php wwp_status_options(''); ?></select>
                        </label>
                    <?php endif; ?>
                    <?php if (in_array('date_from', $fields, true)): ?>
                        <label>از تاریخ
                            <span class="wwp-datepicker-wrap">
                                <input type="text" name="date_from" class="wwp-admin-datepicker regular-text" autocomplete="off" dir="ltr" readonly>
                                <button type="button" class="wwp-datepicker-clear" title="پاک کردن تاریخ" aria-label="پاک کردن تاریخ" hidden>&times;</button>
                            </span>
                        </label>
                    <?php endif; ?>
                    <?php if (in_array('date_to', $fields, true)): ?>
                        <label>تا تاریخ
                            <span class="wwp-datepicker-wrap">
                                <input type="text" name="date_to" class="wwp-admin-datepicker regular-text" autocomplete="off" dir="ltr" readonly>
                                <button type="button" class="wwp-datepicker-clear" title="پاک کردن تاریخ" aria-label="پاک کردن تاریخ" hidden>&times;</button>
                            </span>
                        </label>
                    <?php endif; ?>
                    <?php if (in_array('amount_min', $fields, true)): ?>
                        <label>حداقل مبلغ
                            <input type="number" name="amount_min" step="any" min="0">
                        </label>
                    <?php endif; ?>
                    <?php if (in_array('amount_max', $fields, true)): ?>
                        <label>حداکثر مبلغ
                            <input type="number" name="amount_max" step="any" min="0">
                        </label>
                    <?php endif; ?>
                    <?php if (in_array('tx_q', $fields, true)): ?>
                        <label>کد مرجع / شناسه
                            <input type="text" name="tx_q" class="regular-text" placeholder="شناسه عددی یا کد مرجع تراکنش" autocomplete="off" title="فیلتر بر اساس شناسه (id) یا کد مرجع (transaction_code)">
                        </label>
                    <?php endif; ?>
                    <?php if (in_array('operation_type', $fields, true)): ?>
                        <label>نوع عملیات
                            <select name="operation_type">
                                <?php if (function_exists('wwp_operation_options')) { wwp_operation_options(''); } else { echo '<option value="">همه</option>'; } ?>
                            </select>
                        </label>
                    <?php endif; ?>
                    <?php if (in_array('balance_type', $fields, true)): ?>
                        <label>نوع دارایی
                            <select name="balance_type">
                                <?php if (function_exists('wwp_balance_type_options')) { wwp_balance_type_options(''); } else { echo '<option value="">همه</option>'; } ?>
                            </select>
                        </label>
                    <?php endif; ?>
                    <?php if (in_array('gateway', $fields, true)): ?>
                        <label>درگاه
                            <input type="text" name="gateway" autocomplete="off">
                        </label>
                    <?php endif; ?>
                    <?php if (in_array('card_iban', $fields, true)): ?>
                        <label>کارت / شبا
                            <input type="text" name="card_iban" dir="ltr" autocomplete="off">
                        </label>
                    <?php endif; ?>
                    <?php if (in_array('list_mode', $fields, true)): ?>
                        <label>حالت لیست
                            <select name="list_mode">
                                <option value="pending">در انتظار</option>
                                <option value="history">تاریخچه</option>
                            </select>
                        </label>
                    <?php endif; ?>
                    <?php if (in_array('doc_status', $fields, true)): ?>
                        <label>وضعیت مدرک
                            <select name="doc_status">
                                <option value="pending_review">در انتظار بررسی</option>
                                <option value="approved">تایید شده</option>
                                <option value="rejected">رد شده</option>
                                <option value="all">همه</option>
                            </select>
                        </label>
                    <?php endif; ?>
                    <?php if (in_array('doc_type', $fields, true)): ?>
                        <label>نوع مدرک
                            <select name="doc_type">
                                <option value="">همه</option>
                                <option value="national_id">کارت ملی</option>
                                <option value="bank_card">کارت بانکی</option>
                            </select>
                        </label>
                    <?php endif; ?>
                    <?php if (in_array('phone', $fields, true)): ?>
                        <label>موبایل
                            <input type="text" name="phone" dir="ltr" autocomplete="off">
                        </label>
                    <?php endif; ?>
                    <?php if (in_array('asset_type', $fields, true)): ?>
                        <label>دارایی
                            <select name="asset_type">
                                <?php if (function_exists('wwp_balance_type_options')) { wwp_balance_type_options('', true); } else { echo '<option value="">همه</option>'; } ?>
                            </select>
                        </label>
                    <?php endif; ?>
                    <?php if (in_array('grant_status', $fields, true)): ?>
                        <label>وضعیت اعتبار
                            <select name="grant_status">
                                <option value="">همه</option>
                                <option value="open">باز</option>
                                <option value="open_partial">باز (جزئی)</option>
                                <option value="settled">تسویه</option>
                                <option value="cancelled">لغو</option>
                            </select>
                        </label>
                    <?php endif; ?>
                    <?php if (in_array('overdue_only', $fields, true)): ?>
                        <label class="wwp-filter-check">
                            <input type="checkbox" name="overdue_only" value="1"> فقط باز / سررسیدنشده
                        </label>
                    <?php endif; ?>
                    <?php if (in_array('trade_status', $fields, true)): ?>
                        <label>وضعیت معامله
                            <select name="status">
                                <option value="">همه وضعیت‌ها</option>
                                <option value="pending">ثبت در جدول</option>
                                <option value="minor">خرد شده</option>
                                <option value="completed">تکمیل شده</option>
                                <option value="canceled">لغو شده</option>
                                <option value="minor_canceled">لغو جزئی</option>
                                <option value="minor_completed">جزئی تکمیل شده</option>
                            </select>
                        </label>
                    <?php endif; ?>
                    <?php if (in_array('trade_op', $fields, true)): ?>
                        <label>نوع معامله
                            <select name="operation_type">
                                <option value="">همه</option>
                                <option value="buy">خرید</option>
                                <option value="sell">فروش</option>
                            </select>
                        </label>
                    <?php endif; ?>
                    <?php
                    if (!empty($extra['extra_fields_html'])) {
                        echo $extra['extra_fields_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    }
                    ?>
                    <input type="hidden" name="orderby" value="<?php echo esc_attr((string) ($extra['default_orderby'] ?? 'created_at')); ?>">
                    <input type="hidden" name="order" value="<?php echo esc_attr((string) ($extra['default_order'] ?? 'DESC')); ?>">
                    <input type="hidden" name="paged" value="1">
                    <input type="hidden" name="per_page" value="<?php echo (int) ($extra['per_page'] ?? 50); ?>">
                </div>
                <p class="wallet-filter-actions">
                    <button type="submit" class="button button-primary wwp-admin-list-apply">اعمال فیلتر</button>
                    <button type="button" class="button wwp-admin-list-reset">بازنشانی</button>
                    <?php if (!empty($extra['export'])): ?>
                        <button type="button" class="button wwp-admin-list-export"
                                data-export-action="<?php echo esc_attr($export_action); ?>">خروجی CSV</button>
                    <?php endif; ?>
                    <?php
                    if (!empty($extra['toolbar_html'])) {
                        echo $extra['toolbar_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    }
                    ?>
                    <span class="wwp-admin-list-meta description" data-wwp-list-meta></span>
                </p>
            </form>
        </div>
        <?php
    }
}

if (!function_exists('wwp_admin_sortable_th')) {
    function wwp_admin_sortable_th(string $label, string $orderby_key, string $tip = ''): void
    {
        echo '<th class="wwp-sortable" data-orderby="' . esc_attr($orderby_key) . '">';
        echo '<a href="#" class="wwp-sort-link">' . esc_html($label);
        if ($tip !== '' && function_exists('wwp_col_tip')) {
            echo wwp_col_tip($tip); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        echo ' <span class="wwp-sort-ind"></span></a></th>';
    }
}

if (!function_exists('wwp_admin_csv_download')) {
    /**
     * @param string[][] $rows first row = header
     */
    function wwp_admin_csv_download(string $filename, array $rows): void
    {
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        if ($out === false) {
            exit;
        }
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }
}

if (!function_exists('wwp_admin_query_expiry_logs')) {
    /**
     * @return array{rows:object[],total:int}
     */
    function wwp_admin_query_expiry_logs(array $args): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'medyar_trade_expiry_logs';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if (!$exists) {
            return ['rows' => [], 'total' => 0];
        }

        $where  = ['1=1'];
        $params = [];

        $user_id = (int) ($args['user_id'] ?? 0);
        $user_q  = (string) ($args['user_q'] ?? '');
        if ($user_id <= 0 && $user_q !== '') {
            $uids = wwp_admin_resolve_user_ids_from_q($user_q);
            if (empty($uids)) {
                return ['rows' => [], 'total' => 0];
            }
            $ph = implode(',', array_fill(0, count($uids), '%d'));
            $where[] = "user_id IN ($ph)";
            foreach ($uids as $uid) {
                $params[] = $uid;
            }
        } elseif ($user_id > 0) {
            $where[]  = 'user_id = %d';
            $params[] = $user_id;
        }

        [$from, $to] = wwp_admin_sql_date_bounds((string) ($args['date_from'] ?? ''), (string) ($args['date_to'] ?? ''));
        if ($from) {
            $where[]  = 'canceled_at >= %s';
            $params[] = $from;
        }
        if ($to) {
            $where[]  = 'canceled_at <= %s';
            $params[] = $to;
        }

        $asset = (string) ($args['asset_type'] ?? $args['balance_type'] ?? '');
        if ($asset !== '') {
            $where[]  = 'balance_type = %s';
            $params[] = sanitize_key($asset);
        }
        $side = (string) ($args['operation_type'] ?? '');
        if ($side !== '') {
            $where[]  = 'operation_type = %s';
            $params[] = sanitize_key($side);
        }

        $where_sql = implode(' AND ', $where);
        $per_page  = (int) ($args['per_page'] ?? 50);
        $paged     = (int) ($args['paged'] ?? 1);
        $offset    = ($paged - 1) * $per_page;
        $order     = ($args['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $list_sql  = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY canceled_at {$order} LIMIT %d OFFSET %d";

        if ($params) {
            $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$params));
            $rows  = $wpdb->get_results($wpdb->prepare($list_sql, ...array_merge($params, [$per_page, $offset]))) ?: [];
        } else {
            $total = (int) $wpdb->get_var($count_sql);
            $rows  = $wpdb->get_results($wpdb->prepare($list_sql, $per_page, $offset)) ?: [];
        }

        return ['rows' => $rows, 'total' => $total];
    }
}

if (!function_exists('wwp_admin_query_credit_grants')) {
    /**
     * @return array{rows:array<int,array<string,mixed>>,total:int,tx_objects:object[]}
     */
    function wwp_admin_query_credit_grants(array $args): array
    {
        if (!class_exists('Credit_Manager')) {
            return ['rows' => [], 'total' => 0, 'tx_objects' => []];
        }

        global $wpdb;
        $tbl    = $wpdb->prefix . 'wallet_transactions';
        $where  = ["g.operation_type = 'credit_grant'", "g.status = 'completed'"];
        $params = [];

        $user_id = (int) ($args['user_id'] ?? 0);
        $user_q  = (string) ($args['user_q'] ?? '');
        if ($user_id <= 0 && $user_q !== '') {
            $uids = wwp_admin_resolve_user_ids_from_q($user_q);
            if (empty($uids)) {
                return ['rows' => [], 'total' => 0, 'tx_objects' => []];
            }
            $ph = implode(',', array_fill(0, count($uids), '%d'));
            $where[] = "g.user_id IN ($ph)";
            foreach ($uids as $uid) {
                $params[] = $uid;
            }
        } elseif ($user_id > 0) {
            $where[]  = 'g.user_id = %d';
            $params[] = $user_id;
        }

        $asset = (string) ($args['asset_type'] ?? '');
        if ($asset !== '') {
            $where[]  = 'g.balance_type = %s';
            $params[] = sanitize_key($asset);
        }

        [$from, $to] = wwp_admin_sql_date_bounds((string) ($args['date_from'] ?? ''), (string) ($args['date_to'] ?? ''));
        if ($from) {
            $where[]  = 'g.created_at >= %s';
            $params[] = $from;
        }
        if ($to) {
            $where[]  = 'g.created_at <= %s';
            $params[] = $to;
        }

        $where_sql = implode(' AND ', $where);
        // Fetch a working set then filter by display status in PHP when needed.
        $fetch_limit = !empty($args['overdue_only']) || !empty($args['grant_status'])
            ? 2000
            : ((int) ($args['per_page'] ?? 50) * (int) ($args['paged'] ?? 1) + 50);
        $fetch_limit = min(5000, max(50, $fetch_limit));

        $sql = "SELECT g.user_id, g.balance_type AS asset_type, g.amount AS credit, g.id AS grant_id,
                       g.created_at, g.transaction_code
                FROM {$tbl} g
                WHERE {$where_sql}
                ORDER BY g.created_at DESC
                LIMIT %d";

        if ($params) {
            $raw = $wpdb->get_results($wpdb->prepare($sql, ...array_merge($params, [$fetch_limit]))) ?: [];
            $total_raw = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tbl} g WHERE {$where_sql}", ...$params));
        } else {
            $raw = $wpdb->get_results($wpdb->prepare($sql, $fetch_limit)) ?: [];
            $total_raw = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tbl} g WHERE {$where_sql}");
        }

        $cm   = Credit_Manager::get_instance();
        $rows = $cm->get_admin_credit_grants(count($raw) ?: 1);
        // Re-query enriched only for our IDs to keep status accurate:
        $enriched = [];
        foreach ($raw as $grant) {
            $batch = $cm->get_admin_credit_grants(1, (int) $grant->user_id);
            // Above is inefficient — enrich manually:
            $gid = (int) $grant->grant_id;
            $status = $cm->get_grant_display_status($gid, (float) $grant->credit);
            $user = get_userdata((int) $grant->user_id);
            $row = [
                'grant_id'             => $gid,
                'user_id'              => (int) $grant->user_id,
                'user_display_name'    => $user ? $user->display_name : ('#' . $grant->user_id),
                'asset_type'           => (string) $grant->asset_type,
                'amount'               => (float) $grant->credit,
                'status_code'          => (string) ($status['code'] ?? ''),
                'status_label'         => (string) ($status['label'] ?? ''),
                'transaction_code'     => (string) ($grant->transaction_code ?? ''),
                'created_at'           => (string) ($grant->created_at ?? ''),
                'created_at_formatted' => $grant->created_at ? date_i18n('Y/m/d H:i', strtotime((string) $grant->created_at)) : '',
            ];
            $code = $row['status_code'];
            if (!empty($args['overdue_only']) && !in_array($code, ['open', 'open_partial'], true)) {
                continue;
            }
            $want = (string) ($args['grant_status'] ?? '');
            if ($want !== '') {
                if ($want === 'open' && !in_array($code, ['open', 'open_partial'], true)) {
                    continue;
                }
                if ($want === 'settled' && strpos($code, 'settled') === false && $code !== 'closed_repaid') {
                    // accept common settled codes
                    if (!in_array($code, ['settled', 'closed', 'closed_repaid', 'repaid'], true)) {
                        continue;
                    }
                }
                if ($want === 'cancelled' && strpos($code, 'cancel') === false) {
                    continue;
                }
                if ($want === 'open_partial' && $code !== 'open_partial') {
                    continue;
                }
            }
            $enriched[] = $row;
        }
        unset($rows);

        $filtered_total = (!empty($args['overdue_only']) || !empty($args['grant_status']))
            ? count($enriched)
            : $total_raw;

        $per_page = (int) ($args['per_page'] ?? 50);
        $paged    = (int) ($args['paged'] ?? 1);
        $offset   = ($paged - 1) * $per_page;
        $page_rows = array_slice($enriched, $offset, $per_page);

        // If not filtering by status, paginate at SQL and enrich only page
        if (empty($args['overdue_only']) && empty($args['grant_status'])) {
            $list_sql = "SELECT g.user_id, g.balance_type AS asset_type, g.amount AS credit, g.id AS grant_id,
                                g.created_at, g.transaction_code
                         FROM {$tbl} g WHERE {$where_sql}
                         ORDER BY g.created_at DESC LIMIT %d OFFSET %d";
            if ($params) {
                $page_raw = $wpdb->get_results($wpdb->prepare($list_sql, ...array_merge($params, [$per_page, $offset]))) ?: [];
            } else {
                $page_raw = $wpdb->get_results($wpdb->prepare($list_sql, $per_page, $offset)) ?: [];
            }
            $page_rows = [];
            foreach ($page_raw as $grant) {
                $status = $cm->get_grant_display_status((int) $grant->grant_id, (float) $grant->credit);
                $user = get_userdata((int) $grant->user_id);
                $coll_fmt = '—';
                if (method_exists($cm, 'get_admin_credit_grants')) {
                    $enriched = $cm->get_admin_credit_grants(5, (int) $grant->user_id);
                    foreach ($enriched as $er) {
                        if ((int) ($er['grant_id'] ?? 0) === (int) $grant->grant_id) {
                            $coll_fmt = (string) ($er['collateral_formatted'] ?? '—');
                            break;
                        }
                    }
                }
                $page_rows[] = [
                    'grant_id'             => (int) $grant->grant_id,
                    'user_id'              => (int) $grant->user_id,
                    'user_display_name'    => $user ? $user->display_name : ('#' . $grant->user_id),
                    'asset_type'           => (string) $grant->asset_type,
                    'amount'               => (float) $grant->credit,
                    'amount_asset_display' => function_exists('wwp_format_amount')
                        ? wwp_format_amount((float) $grant->credit, (string) $grant->asset_type)
                        : (string) $grant->credit,
                    'collateral_formatted' => $coll_fmt,
                    'status_code'          => (string) ($status['code'] ?? ''),
                    'status_label'         => (string) ($status['label'] ?? ''),
                    'transaction_code'     => (string) ($grant->transaction_code ?? ''),
                    'created_at'           => (string) ($grant->created_at ?? ''),
                    'created_at_formatted' => $grant->created_at ? date_i18n('Y/m/d H:i', strtotime((string) $grant->created_at)) : '',
                ];
            }
            $filtered_total = $total_raw;
        } else {
            foreach ($page_rows as &$r) {
                $r['amount_asset_display'] = function_exists('wwp_format_amount')
                    ? wwp_format_amount((float) $r['amount'], (string) $r['asset_type'])
                    : (string) $r['amount'];
            }
            unset($r);
        }

        return ['rows' => $page_rows, 'total' => $filtered_total, 'tx_objects' => []];
    }
}
