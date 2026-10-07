<?php
// Run only in a disposable WP database: wp eval-file tests/integration.php.
if (!defined('WP_CLI') || !WP_CLI) exit;
if (getenv('MLCM_TEST_ENV') !== '1') {
    WP_CLI::error('Use a disposable database and explicitly set MLCM_TEST_ENV=1.');
}

function mlcm_assert($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    WP_CLI::log('PASS: ' . $message);
}
function mlcm_term($name, $parent = 0) {
    $result = wp_insert_term($name, 'category', ['parent' => $parent]);
    if (is_wp_error($result)) throw new RuntimeException($result->get_error_message());
    return (int) $result['term_id'];
}
function mlcm_data($path) {
    $content = file_get_contents($path);
    return json_decode(substr($content, strpos($content, '=') + 1, -1), true);
}

$plugin = Multi_Level_Category_Menu::get_instance();
$plugin->invalidate_cache();
delete_option('mlcm_generate_gzip');
$suffix = wp_generate_uuid4();
update_option('mlcm_use_static_files', '1');
update_option('mlcm_auto_regenerate', '1');
update_option('mlcm_max_levels', 3);
$root = mlcm_term('Root ' . $suffix);
$a = mlcm_term('марка A ' . $suffix, $root);
$b = mlcm_term('Brand B ' . $suffix, $root);
$child = mlcm_term('Child ' . $suffix, $a);
$grandchild = mlcm_term('Grandchild ' . $suffix, $child);
$excluded = mlcm_term('Excluded ' . $suffix, $root);
$hidden = mlcm_term('Hidden ' . $suffix, $excluded);
update_option('mlcm_custom_root_id', $root);
update_option('mlcm_excluded_cats', (string) $excluded);

$queries = [];
$spy = function ($terms, $taxonomies, $args) use (&$queries) {
    if (in_array('category', (array) $taxonomies, true)) $queries[] = $args;
    return $terms;
};
add_filter('get_terms', $spy, 10, 3);
$result = $plugin->generate_static_menus();
remove_filter('get_terms', $spy, 10);
mlcm_assert($result['success'], 'generation succeeds');
mlcm_assert(count($queries) === 1, 'one get_terms call for tree generation');
mlcm_assert($result['levels'] === 3, 'actual populated depth has no off-by-one');
$base = wp_upload_dir()['basedir'] . '/mlcm-menu-cache';
$generation = get_option('mlcm_generation');
$dir = $base . '/' . $generation['id'];
$top = mlcm_data($dir . '/level-1.js');
mlcm_assert(count($top) === 2, 'custom root and excluded branch respected');
mlcm_assert(in_array($a, array_column($top, 'id'), true), 'first level contains custom-root children');
mlcm_assert(mlcm_data($dir . '/l2-' . $a . '.js')[0]['id'] === $child, 'per-parent child file');
mlcm_assert(mlcm_data($dir . '/l2-' . $b . '.js') === [], 'empty leaf file is generated');
mlcm_assert(!file_exists($dir . '/l2-' . $excluded . '.js'), 'excluded descendants not traversed');
mlcm_assert(!glob($dir . '/*.gz'), 'gzip disabled by default');
mlcm_assert(!file_exists($base . '/.htaccess'), 'no new web-server configuration');
if (function_exists('mb_strtoupper')) {
    $names = array_column($top, 'name', 'id');
    mlcm_assert(strpos($names[$a], 'МАРКА') === 0, 'Unicode uppercase');
}

$queries = [];
add_filter('get_terms', $spy, 10, 3);
$html = do_shortcode('[mlcm_menu]') . do_shortcode('[mlcm_menu]');
remove_filter('get_terms', $spy, 10);
mlcm_assert(count($queries) === 0, 'SSR reads generated level 1 without taxonomy queries');
preg_match_all('/\bid="([^"]+)"/', $html, $ids);
mlcm_assert(count($ids[1]) === count(array_unique($ids[1])), 'multiple menus have unique IDs');
mlcm_assert(strpos($html, 'data-static-url') === false, 'unused HTML data attributes removed');
mlcm_assert(wp_script_is('mlcm-frontend', 'enqueued'), 'shortcode queues frontend script');
$scripts = wp_scripts();
mlcm_assert(strpos($scripts->registered['mlcm-frontend']->src, '.min.js') !== false, 'production frontend is minified');
if (version_compare(get_bloginfo('version'), '6.3', '>=')) {
    mlcm_assert($scripts->get_data('mlcm-frontend', 'strategy') === 'defer', 'defer on supported WordPress');
}
mlcm_assert(in_array('wp-block-editor', $scripts->registered['mlcm-block-editor']->deps, true), 'block editor dependency is explicit');
mlcm_assert(WP_Block_Type_Registry::get_instance()->is_registered('mlcm/menu-block'), 'dynamic block registered');

$old = file_get_contents($dir . '/level-1.js');
wp_update_term($a, 'category', ['name' => 'Changed ' . $suffix]);
mlcm_assert(file_get_contents($dir . '/level-1.js') === $old, 'stale snapshot retained after edit');
mlcm_assert((bool) wp_next_scheduled(Multi_Level_Category_Menu::CRON_HOOK), 'regeneration scheduled');
$plugin->on_category_change();
$cron = _get_cron_array();
$events = 0;
foreach ($cron as $hooks) $events += isset($hooks[Multi_Level_Category_Menu::CRON_HOOK]) ? count($hooks[Multi_Level_Category_Menu::CRON_HOOK]) : 0;
mlcm_assert($events === 1, 'rapid edits coalesce into one scheduled event');

$failure = function ($pre, $query) { return new WP_Error('test', 'Injected query failure'); };
add_filter('terms_pre_query', $failure, 10, 2);
$failed = $plugin->generate_static_menus();
remove_filter('terms_pre_query', $failure, 10);
mlcm_assert(!$failed['success'], 'term-query error reported');
mlcm_assert(get_option('mlcm_generation')['id'] === $generation['id'], 'query failure preserves published pointer');
mlcm_assert(file_get_contents($dir . '/level-1.js') === $old, 'query failure preserves published files');

$link_failure = function ($url, $term) use ($child) {
    return (int) $term->term_id === $child ? new WP_Error('test', 'Injected mid-generation failure') : $url;
};
add_filter('term_link', $link_failure, 10, 2);
$failed = $plugin->generate_static_menus();
remove_filter('term_link', $link_failure, 10);
mlcm_assert(!$failed['success'], 'mid-generation failure reported');
mlcm_assert(get_option('mlcm_generation')['id'] === $generation['id'], 'partial generation never published');
mlcm_assert(count(glob($base . '/gen-*')) === 1, 'incomplete generation cleaned up');
$publication_failure = function ($value, $old_value) { return $old_value; };
add_filter('pre_update_option_mlcm_generation', $publication_failure, 10, 2);
$failed = $plugin->generate_static_menus();
remove_filter('pre_update_option_mlcm_generation', $publication_failure, 10);
mlcm_assert(!$failed['success'], 'publication failure reported');
mlcm_assert(get_option('mlcm_generation')['id'] === $generation['id'], 'publication failure preserves old pointer');

$lock = fopen($base . '/.generation.lock', 'c');
flock($lock, LOCK_EX);
mlcm_assert(!$plugin->generate_static_menus()['success'], 'concurrent generation fails safely');
flock($lock, LOCK_UN);
fclose($lock);

update_option('mlcm_generate_gzip', '1');
$plugin->scheduled_regenerate();
$new = get_option('mlcm_generation');
mlcm_assert($new['id'] !== $generation['id'], 'successful regeneration switches snapshot');
mlcm_assert(file_exists($dir . '/level-1.js'), 'old snapshot retained for cached HTML');
mlcm_assert((bool) glob($base . '/' . $new['id'] . '/*.gz'), 'gzip opt-in creates sidecars');
update_option('mlcm_auto_regenerate', '0');
$plugin->scheduled_regenerate();
mlcm_assert(get_option('mlcm_generation')['id'] === $new['id'], 'queued cron respects disabled auto-regeneration');
$plugin->on_category_change();
mlcm_assert(!get_option('mlcm_generation'), 'auto-off category change detaches snapshot');
mlcm_assert(!wp_next_scheduled(Multi_Level_Category_Menu::CRON_HOOK), 'auto-off removes pending cron');

update_option('mlcm_custom_root_id', $b);
update_option('mlcm_max_levels', 1);
$result = $plugin->generate_static_menus();
$empty = get_option('mlcm_generation');
mlcm_assert($result['levels'] === 1, 'one-level empty tree reports one level');
mlcm_assert(mlcm_data($base . '/' . $empty['id'] . '/level-1.js') === [], 'empty tree publishes valid array');
$plugin->invalidate_cache();
mlcm_assert(!get_option('mlcm_generation') && !glob($base . '/gen-*'), 'explicit deletion removes snapshots and pointer');
mlcm_assert(!wp_next_scheduled(Multi_Level_Category_Menu::CRON_HOOK), 'explicit deletion cancels cron');
WP_CLI::success('MLCM integration suite passed on WordPress ' . get_bloginfo('version') . ', PHP ' . PHP_VERSION);
