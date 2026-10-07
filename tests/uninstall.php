<?php
if (!defined('WP_CLI') || !WP_CLI || getenv('MLCM_TEST_ENV') !== '1') exit(1);
update_option('mlcm_auto_regenerate', '1');
update_option('mlcm_regen_delay', 45);
update_option('mlcm_generate_gzip', '1');
wp_schedule_single_event(time() + 30, 'mlcm_scheduled_regenerate');
define('WP_UNINSTALL_PLUGIN', 'multi-level-category-menu/multi-level-category-menu.php');
require dirname(__DIR__) . '/uninstall.php';
foreach (['mlcm_auto_regenerate', 'mlcm_regen_delay', 'mlcm_generate_gzip', 'mlcm_generation', 'mlcm_legacy_disabled'] as $option) {
    if (get_option($option) !== false) throw new RuntimeException('Uninstall left option: ' . $option);
}
if (wp_next_scheduled('mlcm_scheduled_regenerate')) throw new RuntimeException('Uninstall left cron.');
if (is_dir(wp_upload_dir()['basedir'] . '/mlcm-menu-cache')) throw new RuntimeException('Uninstall left cache.');
WP_CLI::success('Uninstall options, scheduled events and files');
