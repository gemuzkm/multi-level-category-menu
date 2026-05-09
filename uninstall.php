<?php
/**
 * Runs when the plugin is deleted via the WordPress admin.
 * Removes all plugin options and the cache directory.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

// ── 1. Delete all mlcm_* options ───────────────────────────────────────────
// Static options
$option_names = [
    'mlcm_font_size',
    'mlcm_container_gap',
    'mlcm_button_bg_color',
    'mlcm_button_font_size',
    'mlcm_button_hover_bg_color',
    'mlcm_menu_layout',
    'mlcm_initial_levels',
    'mlcm_max_levels',
    'mlcm_menu_width',
    'mlcm_show_button',
    'mlcm_use_category_base',
    'mlcm_custom_root_id',
    'mlcm_excluded_cats',
    'mlcm_use_static_files',
];

// Dynamically delete all possible mlcm_level_{N}_label options
// Read max_levels from DB first; fall back to 10 to ensure full cleanup
$max_levels = max(1, absint(get_option('mlcm_max_levels', 10)));
if ($max_levels < 10) {
    $max_levels = 10; // always clean up at least 10 levels on uninstall
}
for ($i = 1; $i <= $max_levels; $i++) {
    $option_names[] = 'mlcm_level_' . $i . '_label';
}

foreach ($option_names as $opt) {
    delete_option($opt);
}

// ── 2. Remove cache directory via WP_Filesystem ────────────────────────────
$uploads   = wp_upload_dir();
$cache_dir = $uploads['basedir'] . '/mlcm-menu-cache';

if (is_dir($cache_dir)) {
    require_once ABSPATH . 'wp-admin/includes/file.php';

    WP_Filesystem();
    global $wp_filesystem;

    if ($wp_filesystem) {
        $wp_filesystem->rmdir($cache_dir, true); // true = recursive
    } else {
        // Fallback: manual recursive delete
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($cache_dir);
    }
}
