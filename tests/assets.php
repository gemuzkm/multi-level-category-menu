<?php
// Separate WP process for each mode: blank, early, late, widget, block.
if (!defined('WP_CLI') || !WP_CLI || getenv('MLCM_TEST_ENV') !== '1') exit(1);
$mode = $args[0] ?? 'blank';
$plugin = Multi_Level_Category_Menu::get_instance();
global $wp_query;
$wp_query->queried_object = new WP_Post((object) [
    'ID' => 0, 'post_content' => $mode === 'early' ? '[mlcm_menu]' : 'Plain content',
]);
$plugin->maybe_enqueue_frontend_assets();
if ($mode === 'early') {
    if (!wp_style_is('mlcm-frontend', 'enqueued') || !wp_script_is('mlcm-frontend', 'enqueued')) {
        throw new RuntimeException('Early shortcode detection failed.');
    }
} else {
    if (wp_style_is('mlcm-frontend', 'enqueued') || wp_script_is('mlcm-frontend', 'enqueued')) {
        throw new RuntimeException('Assets loaded without a menu.');
    }
    if ($mode !== 'blank') {
        ob_start();
        do_action('wp_head');
        ob_end_clean();
        if ($mode === 'widget') {
            $plugin->register_widget();
            ob_start();
            (new MLCM_Widget())->widget(['before_widget' => '', 'after_widget' => ''], []);
            $html = ob_get_clean();
        } elseif ($mode === 'block') {
            $html = do_blocks('<!-- wp:mlcm/menu-block /-->');
        } else {
            $html = do_shortcode('[mlcm_menu]');
        }
        if (!wp_style_is('mlcm-frontend', 'done')
            || strpos($html, 'mlcm-frontend-css') === false
            || strpos($html, 'mlcm-frontend-css') > strpos($html, 'class="mlcm-container')) {
            throw new RuntimeException('Late CSS must print before menu HTML.');
        }
        $second = do_shortcode('[mlcm_menu]');
        if (strpos($second, 'mlcm-frontend-css') !== false) throw new RuntimeException('Duplicate CSS.');
    }
}
WP_CLI::success('Conditional assets: ' . $mode);
