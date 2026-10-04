<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var string $prefix */
/** @var array $sections */
/** @var string $active_section */
/** @var string $base_url */
?>
<div class="<?php echo esc_attr($prefix); ?>wrap">
    <div class="<?php echo esc_attr($prefix); ?>head">
        <h2 class="<?php echo esc_attr($prefix); ?>title">کیف پول</h2>
        <div class="<?php echo esc_attr($prefix); ?>nav_items">
            <?php foreach ($sections as $section => $label) : ?>
                <a href="<?php echo esc_url(add_query_arg(['section' => $section], $base_url)); ?>"
                   class="<?php echo esc_attr($prefix); ?>nav_item<?php echo $active_section === $section ? ' active' : ''; ?>">
                    <?php echo esc_html($label); ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="<?php echo esc_attr($prefix); ?>section_content">
        <?php
        $file = MTW_PATH . 'templates/myaccount/section-' . $active_section . '.php';
        if (file_exists($file)) {
            include $file;
        }
        ?>
    </div>
</div>
