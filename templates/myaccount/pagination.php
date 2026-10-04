<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * @param array{query_arg_name?:string,max_num_pages?:int,paged?:int} $args
 */
function mtw_myaccount_pagination(array $args): void
{
    $query_arg = !empty($args['query_arg_name']) ? sanitize_key($args['query_arg_name']) : 'page';
    $total     = max(1, (int) ($args['max_num_pages'] ?? 1));
    $paged     = max(1, (int) ($args['paged'] ?? 1));

    if ($total <= 1) {
        return;
    }

    $base = remove_query_arg(array_keys($_GET));
    $link = add_query_arg(array_merge($_GET, [$query_arg => '%#%']), $base);
    $is_rtl = is_rtl();

    $prev_text = $is_rtl ? "<i class='sheyda-wallet-icon-chevron-right-dot'></i>" : "<i class='sheyda-wallet-icon-chevron-left-dot'></i>";
    $prev_text .= 'صفحه قبل';
    $next_text = 'صفحه بعد';
    $next_text .= $is_rtl ? "<i class='sheyda-wallet-icon-chevron-left-dot'></i>" : "<i class='sheyda-wallet-icon-chevron-right-dot'></i>";
    ?>
    <div class="pagination sheyda_wallet_pagination">
        <?php
        echo paginate_links([
            'base'                 => $link,
            'total'                => $total,
            'current'              => $paged,
            'format'               => '?paged=%#%',
            'prev_next'            => true,
            'type'                 => 'plain',
            'prev_text'            => $prev_text,
            'next_text'            => $next_text,
            'mid_size'             => 3,
            'before_page_number'   => '<span class="meta-nav screen-reader-text">صفحه </span>',
        ]);
        ?>
    </div>
    <?php
}
