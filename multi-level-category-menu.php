<?php
/*
Plugin Name: Multi-Level Category Menu
Description: Cache-friendly category navigation with per-parent static files, background regeneration, a block, widget and shortcode.
Version: 3.10.0
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Author: gemuzkm
Author URI: https://github.com/gemuzkm
Text Domain: mlcm
Domain Path: /languages
*/

defined('ABSPATH') || exit;

class Multi_Level_Category_Menu {

    private static $instance;
    private $options_cache = null;
    private $cache_dir     = null;
    private $cache_url     = null;
    private $versions_cache = null;
    private $assets_enqueued = false;
    private $menu_number = 0;
    private $collator = null;

    /** WP-Cron hook name for delayed regeneration. */
    const CRON_HOOK = 'mlcm_scheduled_regenerate';

    private function max_levels() {
        return max(1, min(10, absint(get_option('mlcm_max_levels', 5))));
    }

    public static function get_instance() {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $uploads          = wp_upload_dir();
        $this->cache_dir  = $uploads['basedir'] . '/mlcm-menu-cache';
        $this->cache_url  = $uploads['baseurl'] . '/mlcm-menu-cache';

        add_action('init',                        [$this, 'load_textdomain']);
        add_shortcode('mlcm_menu',                [$this, 'shortcode_handler']);
        add_action('widgets_init',                [$this, 'register_widget']);
        add_action('init',                        [$this, 'register_gutenberg_block']);
        add_action('wp_enqueue_scripts',          [$this, 'maybe_enqueue_frontend_assets']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_block_editor_assets']);
        add_action('admin_init',                  [$this, 'register_settings']);
        add_action('admin_menu',                  [$this, 'add_admin_menu']);

        add_action('wp_ajax_mlcm_get_subcategories',        [$this, 'ajax_handler']);
        add_action('wp_ajax_nopriv_mlcm_get_subcategories', [$this, 'ajax_handler']);
        add_action('wp_ajax_mlcm_generate_menu',            [$this, 'ajax_generate_menu']);
        add_action('wp_ajax_mlcm_delete_cache',             [$this, 'ajax_delete_cache']);

        // Category change hooks — invalidate cache and optionally schedule regeneration.
        add_action('edited_category', [$this, 'on_category_change']);
        add_action('create_category', [$this, 'on_category_change']);
        add_action('delete_category', [$this, 'on_category_change']);

        // WP-Cron callback for delayed regeneration.
        add_action(self::CRON_HOOK, [$this, 'scheduled_regenerate']);

        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('updated_option', [$this, 'maybe_clear_options_cache'], 10, 1);
        add_action('added_option', [$this, 'maybe_clear_options_cache'], 10, 1);
        add_action('deleted_option', [$this, 'maybe_clear_options_cache'], 10, 1);
    }

    // -------------------------------------------------------------------------
    // Activation / deactivation hooks (registered outside the class below).
    // -------------------------------------------------------------------------

    public static function on_activate() {
        // Nothing to do on activate — cron is scheduled lazily on first change.
    }

    public static function on_deactivate() {
        // Clear any pending scheduled regeneration so it doesn't fire after deactivation.
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    // -------------------------------------------------------------------------
    // Category change handler.
    // -------------------------------------------------------------------------

    /**
     * Fired on edited_category / create_category / delete_category.
     *
     * Keep the published snapshot available while automatic regeneration waits.
     * Without automatic regeneration, invalidate so AJAX can serve fresh data.
     *
     * If "Auto-regenerate" is enabled, schedules a single WP-Cron event
     * after the configured delay. Multiple rapid changes coalesce into one
     * scheduled event: if an event is already pending we reschedule it,
     * resetting the timer so the regeneration runs after the *last* change.
     */
    public function on_category_change() {
        $options = $this->get_options();
        if (!$options['use_static_files'] || !$options['auto_regenerate']) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
            // Detach the current snapshot; keep files for already-cached pages.
            delete_option('mlcm_generation');
            $this->versions_cache = null;
            // Legacy root files are no longer used by newly rendered pages.
            update_option('mlcm_legacy_disabled', '1', false);
            return;
        }

        $delay = max(10, min(60, (int) $options['regen_delay']));

        // Cancel any already-scheduled event so we don't get duplicate runs
        // and so rapid sequential changes reset the timer.
        $existing = wp_next_scheduled(self::CRON_HOOK);
        if ($existing) {
            wp_unschedule_event($existing, self::CRON_HOOK);
        }

        wp_schedule_single_event(time() + $delay, self::CRON_HOOK);
    }

    /**
     * WP-Cron callback: regenerate static menu files.
     * Runs $delay seconds after the last category change.
     */
    public function scheduled_regenerate() {
        $options = $this->get_options();
        if (!$options['use_static_files'] || !$options['auto_regenerate']) {
            return;
        }
        $result = $this->generate_static_menus();
        if (!$result['success']) {
            error_log('MLCM scheduled_regenerate failed: ' . $result['message']);
        }
    }

    // -------------------------------------------------------------------------

    public function load_textdomain() {
        load_plugin_textdomain(
            'mlcm',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );
    }

    private function init_cache_dir() {
        if (!is_dir($this->cache_dir) && !wp_mkdir_p($this->cache_dir)) {
            throw new RuntimeException('Cannot create menu cache directory.');
        }
        // Do not create/overwrite web-server configuration. Compression and
        // cache headers belong to the origin/CDN configuration.
    }

    private function get_options() {
        if (null === $this->options_cache) {
            $max = $this->max_levels();
            $this->options_cache = [
                'font_size'             => sanitize_text_field(get_option('mlcm_font_size', '')),
                'container_gap'         => absint(get_option('mlcm_container_gap', 0)),
                'button_bg_color'       => sanitize_hex_color(get_option('mlcm_button_bg_color', '')),
                'button_font_size'      => sanitize_text_field(get_option('mlcm_button_font_size', '')),
                'button_hover_bg_color' => sanitize_hex_color(get_option('mlcm_button_hover_bg_color', '')),
                'menu_layout'           => sanitize_text_field(get_option('mlcm_menu_layout', 'vertical')),
                'initial_levels'        => absint(get_option('mlcm_initial_levels', 3)),
                'max_levels'            => $max,
                'menu_width'            => absint(get_option('mlcm_menu_width', 250)),
                'show_button'           => (bool) get_option('mlcm_show_button', false),
                'custom_root_id'        => absint(get_option('mlcm_custom_root_id', 0)),
                'excluded_cats'         => sanitize_text_field(get_option('mlcm_excluded_cats', '')),
                'labels'                => array_map(function ($i) {
                    return sanitize_text_field(get_option("mlcm_level_{$i}_label", "Level {$i}"));
                }, range(1, $max)),
                'use_static_files'      => (bool) get_option('mlcm_use_static_files', true),
                // Auto-regeneration settings.
                'auto_regenerate'       => (bool) get_option('mlcm_auto_regenerate', false),
                'regen_delay'           => max(10, min(60, absint(get_option('mlcm_regen_delay', 30)))),
                'generate_gzip'         => (bool) get_option('mlcm_generate_gzip', false),
            ];
        }
        return $this->options_cache;
    }

    private function generate_inline_css($options) {
        $parts     = [];
        $sel_props = [];

        if (!empty($options['menu_width']) && $options['menu_width'] > 0) {
            $sel_props[] = "width:{$options['menu_width']}px";
        }
        if (!empty($options['font_size']) && is_numeric($options['font_size'])) {
            $sel_props[] = "font-size:{$options['font_size']}rem";
        }
        if ($sel_props) {
            $parts[] = '.mlcm-select{' . implode(';', $sel_props) . '}';
        }

        if (!empty($options['container_gap']) && $options['container_gap'] > 0) {
            $parts[] = ".mlcm-container{gap:{$options['container_gap']}px}";
        }

        $btn_props = [];
        if (!empty($options['button_bg_color'])) {
            $btn_props[] = "background:{$options['button_bg_color']}";
        }
        if (!empty($options['button_font_size']) && is_numeric($options['button_font_size'])) {
            $btn_props[] = "font-size:{$options['button_font_size']}rem";
        }
        if ($btn_props) {
            $parts[] = '.mlcm-go-button{' . implode(';', $btn_props) . '}';
        }

        if (!empty($options['button_hover_bg_color'])) {
            $parts[] = ".mlcm-go-button:hover{background:{$options['button_hover_bg_color']}}";
        }

        return implode('', $parts);
    }

    public function register_gutenberg_block() {
        $this->register_editor_assets();
        $block_json = plugin_dir_path(__FILE__) . 'assets/js/block.json';

        if (file_exists($block_json)) {
            register_block_type($block_json, [
                'render_callback' => [$this, 'render_gutenberg_block'],
                'api_version' => version_compare(get_bloginfo('version'), '6.3', '>=') ? 3 : 2,
            ]);
            return;
        }

        $js_path = plugin_dir_path(__FILE__) . 'assets/js/block-editor.js';
        wp_register_script(
            'mlcm-block-editor',
            plugins_url('assets/js/block-editor.js', __FILE__),
            ['wp-blocks', 'wp-block-editor', 'wp-i18n', 'wp-element', 'wp-components'],
            file_exists($js_path) ? filemtime($js_path) : '3.10.0'
        );

        register_block_type('mlcm/menu-block', [
            'editor_script'   => 'mlcm-block-editor',
            'render_callback' => [$this, 'render_gutenberg_block'],
            'attributes'      => [
                'layout' => ['type' => 'string', 'default' => 'vertical'],
                'levels' => ['type' => 'number', 'default' => 3],
            ],
        ]);
    }

    public function render_gutenberg_block($attributes) {
        $atts = shortcode_atts(['layout' => 'vertical', 'levels' => 3], $attributes);
        return $this->generate_menu_html($atts);
    }

    public function shortcode_handler($atts) {
        $options = $this->get_options();
        $atts    = shortcode_atts(['layout' => $options['menu_layout'], 'levels' => $options['initial_levels']], $atts);
        return $this->generate_menu_html($atts);
    }

    private function get_categories_data($parent_id = 0) {
        $options  = $this->get_options();
        $excluded = [];

        if (!empty($options['excluded_cats'])) {
            $excluded = array_filter(array_map('absint', array_map('trim', explode(',', $options['excluded_cats']))));
        }

        $categories = get_terms([
            'taxonomy'   => 'category',
            'parent'     => $parent_id,
            'exclude'    => $excluded,
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
            'fields'     => 'all',
            'update_term_meta_cache' => false,
        ]);

        if (is_wp_error($categories)) throw new RuntimeException($categories->get_error_message());
        if (empty($categories)) return [];

        // WordPress caches this hierarchy. parent__in is not a WP_Term_Query
        // argument; the previous query silently ignored it.
        $hierarchy = _get_term_hierarchy('category');

        $result = [];
        foreach ($categories as $category) {
            if (!isset($category->term_id, $category->name)) continue;

            $url = get_category_link($category->term_id);
            if (!is_string($url) || $url === '') throw new RuntimeException('Unable to resolve category URL.');
            $result[$category->term_id] = $this->format_category(
                $category, $url, !empty(array_diff($hierarchy[$category->term_id] ?? [], $excluded))
            );
        }

        $this->sort_categories($result);
        return $result;
    }

    private function sort_categories(&$categories) {
        if (empty($categories)) return;
        if (null === $this->collator) {
            $this->collator = class_exists('Collator') ? Collator::create(get_locale()) : false;
        }

        uasort($categories, function ($a, $b) {
            $na = trim((string) ($a['name'] ?? ''));
            $nb = trim((string) ($b['name'] ?? ''));
            if ($na === '' && $nb === '') return 0;
            if ($na === '') return 1;
            if ($nb === '') return -1;
            $order = $this->collator ? $this->collator->compare($na, $nb) : strnatcasecmp($na, $nb);
            return ($order === false ? strnatcasecmp($na, $nb) : $order)
                ?: ((int) $a['id'] <=> (int) $b['id']);
        });
    }

    private function format_category($category, $url, $has_children) {
        $name = htmlspecialchars_decode($category->name, ENT_QUOTES);
        return [
            'id' => (int) $category->term_id,
            'name' => function_exists('mb_strtoupper') ? mb_strtoupper($name, 'UTF-8') : strtoupper($name),
            'slug' => $category->slug,
            'url' => $url,
            'hasChildren' => (bool) $has_children,
        ];
    }

    private function build_tree() {
        $excluded = array_filter(array_map('absint', explode(',', $this->get_options()['excluded_cats'])));
        $terms = get_terms([
            'taxonomy' => 'category', 'hide_empty' => false, 'fields' => 'all',
            'number' => 0, 'orderby' => 'none', 'update_term_meta_cache' => false,
        ]);
        if (is_wp_error($terms)) throw new RuntimeException($terms->get_error_message());
        $children = [];
        foreach ($terms as $term) {
            if (in_array((int) $term->term_id, $excluded, true)) continue;
            $children[(int) $term->parent][(int) $term->term_id] = $term;
        }
        return $children;
    }

    private function tree_categories($children, $parent) {
        $result = [];
        foreach ($children[$parent] ?? [] as $id => $term) {
            $url = get_category_link($id);
            if (!is_string($url) || $url === '') throw new RuntimeException('Unable to resolve category URL.');
            $result[$id] = $this->format_category($term, $url, !empty($children[$id]));
        }
        $this->sort_categories($result);
        return $result;
    }

    private function generation() {
        $generation = get_option('mlcm_generation', []);
        return is_array($generation) && preg_match('/^gen-[a-f0-9-]+$/D', $generation['id'] ?? '')
            ? $generation : [];
    }

    private function data_directory() {
        $generation = $this->generation();
        return $this->cache_dir . ($generation ? '/' . $generation['id'] : '');
    }

    public function generate_static_menus() {
        $options     = $this->get_options();
        $custom_root = $options['custom_root_id'] > 0 ? $options['custom_root_id'] : 0;
        $max         = $options['max_levels'];
        $base_dir = $this->cache_dir;
        $lock = null;
        $staging = null;
        $published = false;
        try {
            $this->init_cache_dir();
            $lock = fopen($base_dir . '/.generation.lock', 'c');
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Menu generation or deletion is already running. Retry shortly.');
            }
            $children = $this->build_tree();
            $generation_id = 'gen-' . wp_generate_uuid4();
            $staging = $base_dir . '/' . $generation_id;
            $this->cache_dir = $staging;
            $this->init_cache_dir();
            $level_1_data          = $this->tree_categories($children, $custom_root);
            $this->write_js_file('level-1.js', array_values($level_1_data));
            $current_level_parents = array_keys($level_1_data);
            $levels_written = 1;

            for ($level = 2; $level <= $max; $level++) {
                $next_level_parents = [];
                foreach ($current_level_parents as $parent_id) {
                    $subcats = $this->tree_categories($children, $parent_id);
                    // Empty leaf files avoid an unnecessary 404/AJAX round trip.
                    $this->write_js_file("l{$level}-{$parent_id}.js", array_values($subcats));
                    $next_level_parents = array_merge($next_level_parents, array_keys($subcats));
                }
                if ($next_level_parents) $levels_written = $level;
                if (empty($next_level_parents)) break;
                $current_level_parents = array_unique($next_level_parents);
            }
            $versions = array_fill(1, $max, $generation_id);
            $this->write_js_file('versions.js', $versions);
            $meta = [
                'id'                  => $generation_id,
                'generated_at'        => current_time('mysql'),
                'generated_timestamp' => time(),
                'custom_root_id'      => $custom_root,
                'levels_count'        => $levels_written,
                'versions'            => $versions,
            ];
            $this->write_js_file('meta.js', $meta);
            // The only publication point. Old HTML continues using its immutable
            // generation; a failed write can never mix old and new levels.
            if (!update_option('mlcm_generation', $meta, false)) {
                throw new RuntimeException('Failed to publish menu generation.');
            }
            $published = true;
            $this->versions_cache = null;
            return ['success' => true, 'message' => 'Menu generated successfully', 'levels' => $levels_written];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        } finally {
            $this->cache_dir = $base_dir;
            if (!$published && $staging && is_dir($staging)) $this->remove_generation($staging);
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function remove_generation($directory) {
        if (is_link($directory)) return;
        foreach (glob($directory . '/*') ?: [] as $file) {
            if (is_file($file) && !is_link($file)) @unlink($file);
        }
        @rmdir($directory);
    }

    private function write_js_file($filename, $data) {
        $filepath = $this->cache_dir . '/' . $filename;
        $tmp_path = $filepath . '.tmp';

        $var_name = 'mlcmData';
        if (preg_match('/level-(\d+)\.js$/', $filename, $m)) {
            $var_name = 'mlcmLevel' . $m[1];
        } elseif (preg_match('/^l(\d+)-(\d+)\.js$/', $filename, $m)) {
            $var_name = 'mlcmL' . $m[1] . '_' . $m[2];
        } elseif ($filename === 'versions.js') {
            $var_name = 'mlcmVersions';
        } elseif (strpos($filename, 'meta.js') !== false) {
            $var_name = 'mlcmMeta';
        }

        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('JSON encoding error: ' . json_last_error_msg());
        }

        $js_content = "window.{$var_name} = {$json};";

        if (file_put_contents($tmp_path, $js_content) !== strlen($js_content)) {
            throw new Exception("Failed to write temp file: {$tmp_path}");
        }
        if (!rename($tmp_path, $filepath)) {
            @unlink($tmp_path);
            throw new Exception("Failed to rename temp file to: {$filepath}");
        }

        if ($this->get_options()['generate_gzip'] && extension_loaded('zlib')) {
            $gz_data = gzencode($js_content, 9);
            if ($gz_data === false) throw new RuntimeException('Failed to gzip menu data.');
            $gz_tmp = $filepath . '.gz.tmp';
            if (file_put_contents($gz_tmp, $gz_data) !== strlen($gz_data) || !rename($gz_tmp, $filepath . '.gz')) {
                @unlink($gz_tmp);
                throw new RuntimeException('Failed to write gzip menu data.');
            }
        }
    }

    private function get_level_1_data() {
        $options = $this->get_options();
        $use_cache = $options['use_static_files'] && ($this->generation() || !get_option('mlcm_legacy_disabled', false));
        $cache_file = $this->data_directory() . '/level-1.js';

        if ($use_cache && file_exists($cache_file)) {
            $content = file_get_contents($cache_file);
            if ($content !== false && preg_match('/window\.mlcmLevel1\s*=\s*(.+?);?\s*$/s', $content, $m)) {
                $decoded = json_decode(trim($m[1]), true);
                if (json_last_error() === JSON_ERROR_NONE) return $decoded;
                error_log('MLCM: Failed to parse level-1.js cache — ' . json_last_error_msg());
            } else {
                error_log('MLCM: Could not read level-1.js cache file.');
            }
        }

        $root = $this->get_options()['custom_root_id'];
        try {
            return array_values($this->get_categories_data($root > 0 ? $root : 0));
        } catch (Throwable $error) {
            error_log('MLCM first-level lookup failed: ' . $error->getMessage());
            return [];
        }
    }

    private function get_file_versions() {
        if (null !== $this->versions_cache) return $this->versions_cache;
        $generation = $this->generation();
        if ($generation) return $this->versions_cache = $generation['versions'];
        $versions_file = $this->cache_dir . '/versions.js';
        if (file_exists($versions_file)) {
            $content = file_get_contents($versions_file);
            if ($content !== false && preg_match('/window\.mlcmVersions\s*=\s*(\{[^}]+\})/', $content, $m)) {
                $decoded = json_decode($m[1], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $this->versions_cache = $decoded;
                }
            }
        }

        $versions = [];
        $max      = $this->max_levels();
        for ($level = 1; $level <= $max; $level++) {
            $f                = $this->cache_dir . "/level-{$level}.js";
            $versions[$level] = file_exists($f) ? filemtime($f) : 0;
        }
        return $this->versions_cache = $versions;
    }

    public function invalidate_cache() {
        $this->init_cache_dir();
        $lock = fopen($this->cache_dir . '/.generation.lock', 'c');
        if (!$lock) throw new RuntimeException('Cannot lock menu cache.');
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('Menu generation is running. Retry deletion shortly.');
        }
        try {
            wp_clear_scheduled_hook(self::CRON_HOOK);
            delete_option('mlcm_generation');
            update_option('mlcm_legacy_disabled', '1', false);
            $this->versions_cache = null;
            foreach (glob($this->cache_dir . '/gen-*', GLOB_ONLYDIR) ?: [] as $dir) $this->remove_generation($dir);
            foreach (glob($this->cache_dir . '/level-*.js*') ?: [] as $file) @unlink($file);
            foreach (['meta.js', 'meta.js.gz', 'versions.js', 'versions.js.gz'] as $f) @unlink($this->cache_dir . '/' . $f);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function ajax_generate_menu() {
        check_ajax_referer('mlcm_admin_nonce', 'security');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
            wp_die();
        }
        $result = $this->generate_static_menus();
        $result['success'] ? wp_send_json_success($result) : wp_send_json_error($result);
        wp_die();
    }

    public function ajax_delete_cache() {
        check_ajax_referer('mlcm_admin_nonce', 'security');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
            wp_die();
        }
        try {
            $this->invalidate_cache();
            wp_send_json_success(['message' => 'Cache files deleted successfully', 'success' => true]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Error deleting cache: ' . $e->getMessage(), 'success' => false]);
        }
        wp_die();
    }

    public function ajax_handler() {
        $parent_id = absint($_POST['parent_id'] ?? 0);
        if ($parent_id < 0) {
            wp_send_json_error(['message' => 'Invalid parent ID']);
            wp_die();
        }
        try {
            $categories = $this->get_categories_data($parent_id);
            wp_send_json_success(array_values($categories));
        } catch (Throwable $error) {
            error_log('MLCM AJAX lookup failed: ' . $error->getMessage());
            wp_send_json_error(['message' => 'Unable to load categories.'], 500);
        }
    }

    public function maybe_enqueue_frontend_assets() {
        $post = get_queried_object();
        $needed = $post instanceof WP_Post && (
            has_shortcode($post->post_content, 'mlcm_menu') || has_block('mlcm/menu-block', $post->post_content)
        );
        if (apply_filters('mlcm_enqueue_assets', $needed)) $this->enqueue_frontend_assets();
    }

    public function enqueue_frontend_assets() {
        if (is_admin() || $this->assets_enqueued) return;
        $this->assets_enqueued = true;

        $options         = $this->get_options();
        $plugin_dir_url  = plugin_dir_url(__FILE__);
        $plugin_dir_path = plugin_dir_path(__FILE__);

        // CSS
        $css_min = $plugin_dir_path . 'assets/css/frontend.min.css';
        $css_reg = $plugin_dir_path . 'assets/css/frontend.css';
        if (file_exists($css_min)) {
            $css_file = $plugin_dir_url . 'assets/css/frontend.min.css';
            $css_ver  = filemtime($css_min);
        } elseif (file_exists($css_reg)) {
            $css_file = $plugin_dir_url . 'assets/css/frontend.css';
            $css_ver  = filemtime($css_reg);
        } else {
            $css_file = false;
            $css_ver  = '3.10.0';
        }

        if ($css_file) {
            wp_enqueue_style('mlcm-frontend', $css_file, [], $css_ver);
        }

        // JS
        $js_min = $plugin_dir_path . 'assets/js/frontend.min.js';
        $js_reg = $plugin_dir_path . 'assets/js/frontend.js';
        if (file_exists($js_min)) {
            $js_file = $plugin_dir_url . 'assets/js/frontend.min.js';
            $js_ver  = filemtime($js_min);
        } elseif (file_exists($js_reg)) {
            $js_file = $plugin_dir_url . 'assets/js/frontend.js';
            $js_ver  = filemtime($js_reg);
        } else {
            $js_file = false;
            $js_ver  = '3.10.0';
        }

        if ($js_file) {
            $generation = $this->generation();
            $static_url = $this->cache_url . ($generation ? '/' . $generation['id'] : '');
            $versions_path = $this->data_directory() . '/versions.js';
            $use_static = $options['use_static_files'] && ($generation || !get_option('mlcm_legacy_disabled', false));
            $script_args = version_compare(get_bloginfo('version'), '6.3', '>=') ? ['strategy' => 'defer', 'in_footer' => true] : true;
            if ($use_static && file_exists($versions_path)) {
                wp_enqueue_script(
                    'mlcm-versions',
                    esc_url($static_url . '/versions.js'),
                    [],
                    filemtime($versions_path),
                    $script_args
                );
            }

            $deps = ($use_static && file_exists($versions_path))
                ? ['mlcm-versions']
                : [];

            wp_enqueue_script('mlcm-frontend', $js_file, $deps, $js_ver, $script_args);

            wp_localize_script('mlcm-frontend', 'mlcmVars', [
                'ajax_url'          => admin_url('admin-ajax.php'),
                'labels'            => $options['labels'],
                'use_static'        => $use_static ? '1' : '0',
                'static_url'        => esc_url($static_url),
                'parent_files'      => (bool) $generation,
                'custom_root_id'    => $options['custom_root_id'],
                'file_versions'     => $use_static ? $this->get_file_versions() : [],
                'max_levels'        => $options['max_levels'],
            ]);
        }

        $custom_css = $this->generate_inline_css($options);
        if (!empty($custom_css) && $css_file) {
            wp_add_inline_style('mlcm-frontend', $custom_css);
        }
    }

    private function generate_menu_html($atts) {
        $this->enqueue_frontend_assets();
        ++$this->menu_number;
        $options    = $this->get_options();
        $max        = $options['max_levels'];
        $levels_out = max(1, min(absint($atts['levels']), $max));
        $atts['layout'] = in_array($atts['layout'], ['vertical', 'horizontal'], true) ? $atts['layout'] : 'vertical';
        $level_1    = $this->get_level_1_data();

        ob_start();
        // Shortcodes in templates, widgets and synced blocks can be discovered
        // after wp_head. Print CSS immediately before the first menu, not at
        // the footer; WP's done list prevents duplicate styles.
        if (!is_admin() && did_action('wp_head') && !wp_style_is('mlcm-frontend', 'done')) {
            wp_print_styles(['mlcm-frontend']);
        }
        ?>
        <div class="mlcm-container <?php echo esc_attr($atts['layout']); ?>"
             data-levels="<?php echo esc_attr($levels_out); ?>">
            <?php for ($i = 1; $i <= $levels_out; $i++) : ?>
                <div class="mlcm-level" data-level="<?php echo esc_attr($i); ?>">
                    <?php $this->render_select($i, $level_1); ?>
                </div>
            <?php endfor; ?>
            <?php if ($options['show_button']) : ?>
                <button type="button" class="mlcm-go-button <?php echo esc_attr($atts['layout']); ?>">
                    <?php esc_html_e('Go', 'mlcm'); ?>
                </button>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_select($level, $level_1_data = []) {
        $options   = $this->get_options();
        $label     = $options['labels'][$level - 1] ?? "Level {$level}";
        $select_id = "mlcm-{$this->menu_number}-select-level-{$level}";
        $label_id  = "mlcm-{$this->menu_number}-label-level-{$level}";
        $cats      = ($level === 1) ? $level_1_data : [];
        ?>
        <label for="<?php echo esc_attr($select_id); ?>" id="<?php echo esc_attr($label_id); ?>" class="mlcm-screen-reader-text">
            <?php echo esc_html($label); ?>
        </label>
        <select id="<?php echo esc_attr($select_id); ?>" class="mlcm-select" data-level="<?php echo esc_attr($level); ?>"
                aria-labelledby="<?php echo esc_attr($label_id); ?>"
                <?php echo $level > 1 ? 'disabled' : ''; ?>>
            <option value="-1"><?php echo esc_html($label); ?></option>
            <?php foreach ($cats as $cat) : ?>
                <option value="<?php echo absint($cat['id']); ?>"
                        data-slug="<?php echo esc_attr($cat['slug'] ?? ''); ?>"
                        data-url="<?php echo esc_url($cat['url'] ?? ''); ?>">
                    <?php echo esc_html($cat['name'] ?? ''); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public function register_settings() {
        register_setting('mlcm_options', 'mlcm_font_size',             ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('mlcm_options', 'mlcm_container_gap',         ['sanitize_callback' => 'absint']);
        register_setting('mlcm_options', 'mlcm_button_bg_color',       ['sanitize_callback' => 'sanitize_hex_color']);
        register_setting('mlcm_options', 'mlcm_button_font_size',      ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('mlcm_options', 'mlcm_button_hover_bg_color', ['sanitize_callback' => 'sanitize_hex_color']);
        register_setting('mlcm_options', 'mlcm_menu_layout',           ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('mlcm_options', 'mlcm_initial_levels',        ['sanitize_callback' => 'absint']);
        register_setting('mlcm_options', 'mlcm_max_levels',            ['sanitize_callback' => 'absint']);
        register_setting('mlcm_options', 'mlcm_excluded_cats',         ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('mlcm_options', 'mlcm_menu_width',            ['sanitize_callback' => 'absint']);
        register_setting('mlcm_options', 'mlcm_show_button',           ['sanitize_callback' => 'rest_sanitize_boolean']);
        register_setting('mlcm_options', 'mlcm_custom_root_id',        ['sanitize_callback' => 'absint']);
        register_setting('mlcm_options', 'mlcm_use_static_files',      ['sanitize_callback' => 'rest_sanitize_boolean']);
        register_setting('mlcm_options', 'mlcm_auto_regenerate',       ['sanitize_callback' => 'rest_sanitize_boolean']);
        register_setting('mlcm_options', 'mlcm_generate_gzip', ['sanitize_callback' => 'rest_sanitize_boolean']);
        register_setting('mlcm_options', 'mlcm_regen_delay',           [
            'sanitize_callback' => function ($v) {
                return max(10, min(60, absint($v)));
            },
        ]);

        $max = $this->max_levels();
        for ($i = 1; $i <= $max; $i++) {
            register_setting('mlcm_options', "mlcm_level_{$i}_label", ['sanitize_callback' => 'sanitize_text_field']);
        }

        add_settings_section('mlcm_main', 'Main Settings', null, 'mlcm_options');
        $options = $this->get_options();

        add_settings_field('mlcm_font_size', 'Font Size for Menu Items (rem)', function () use ($options) {
            echo '<input type="number" step="0.1" min="0.5" max="5" name="mlcm_font_size" value="' . esc_attr($options['font_size']) . '">';
        }, 'mlcm_options', 'mlcm_main');

        add_settings_field('mlcm_container_gap', 'Gap Between Menu Items (px)', function () use ($options) {
            echo '<input type="number" min="0" step="1" name="mlcm_container_gap" value="' . esc_attr($options['container_gap']) . '">';
        }, 'mlcm_options', 'mlcm_main');

        add_settings_field('mlcm_button_bg_color', 'Button Background Color', function () use ($options) {
            echo '<input type="color" name="mlcm_button_bg_color" value="' . esc_attr($options['button_bg_color']) . '">';
        }, 'mlcm_options', 'mlcm_main');

        add_settings_field('mlcm_button_hover_bg_color', 'Button Hover Background Color', function () use ($options) {
            echo '<input type="color" name="mlcm_button_hover_bg_color" value="' . esc_attr($options['button_hover_bg_color']) . '">';
        }, 'mlcm_options', 'mlcm_main');

        add_settings_field('mlcm_button_font_size', 'Button Font Size (rem)', function () use ($options) {
            echo '<input type="number" step="0.1" min="0.5" max="5" name="mlcm_button_font_size" value="' . esc_attr($options['button_font_size']) . '">';
        }, 'mlcm_options', 'mlcm_main');

        add_settings_field('mlcm_custom_root_id', 'Custom Root Category ID', function () use ($options) {
            echo '<input type="text" name="mlcm_custom_root_id" value="' . esc_attr($options['custom_root_id']) . '">';
            echo '<p class="description">Specify the ID of the category whose subcategories will be used as the first level of the menu. Leave blank to use root categories.</p>';
        }, 'mlcm_options', 'mlcm_main');

        add_settings_field('mlcm_layout', 'Menu Layout', function () use ($options) {
            $layout = $options['menu_layout'];
            echo '<select name="mlcm_menu_layout">
                <option value="vertical" '   . selected($layout, 'vertical',   false) . '>Vertical</option>
                <option value="horizontal" ' . selected($layout, 'horizontal', false) . '>Horizontal</option>
            </select>';
        }, 'mlcm_options', 'mlcm_main');

        add_settings_field('mlcm_levels', 'Initial Visible Levels', function () use ($options) {
            echo '<input type="number" min="1" max="' . esc_attr($options['max_levels']) . '" name="mlcm_initial_levels" value="' . esc_attr($options['initial_levels']) . '">';
            echo '<p class="description">Number of dropdown selects visible on page load (cannot exceed Max Depth).</p>';
        }, 'mlcm_options', 'mlcm_main');

        add_settings_field('mlcm_max_levels', 'Max Menu Depth', function () use ($options) {
            echo '<input type="number" min="1" max="10" name="mlcm_max_levels" value="' . esc_attr($options['max_levels']) . '">';
            echo '<p class="description">Maximum number of category levels supported (1–10). Changing this requires regenerating menu files.</p>';
        }, 'mlcm_options', 'mlcm_main');

        add_settings_field('mlcm_width', 'Menu Width (px)', function () use ($options) {
            echo '<input type="number" min="100" step="10" name="mlcm_menu_width" value="' . esc_attr($options['menu_width']) . '">';
        }, 'mlcm_options', 'mlcm_main');

        add_settings_field('mlcm_show_button', 'Show Go Button', function () use ($options) {
            $show = $options['show_button'] ? '1' : '0';
            echo '<label><input type="checkbox" name="mlcm_show_button" value="1" ' . checked($show, '1', false) . '> ' . __('Enable Go button', 'mlcm') . '</label>';
        }, 'mlcm_options', 'mlcm_main');

        add_settings_field('mlcm_use_static', 'Use Static JavaScript Files', function () use ($options) {
            $use_static = $options['use_static_files'] ? '1' : '0';
            echo '<label><input type="checkbox" name="mlcm_use_static_files" value="1" ' . checked($use_static, '1', false) . '> ' . __('Enable static file generation for better performance', 'mlcm') . '</label>';
            echo '<p class="description">When enabled, category data is stored in static JavaScript files instead of database queries. Click "Generate Menu" to create files.</p>';
        }, 'mlcm_options', 'mlcm_main');

        add_settings_field('mlcm_auto_regenerate', 'Auto-Regenerate on Category Change', function () use ($options) {
            $checked = $options['auto_regenerate'] ? '1' : '0';
            echo '<label><input type="checkbox" name="mlcm_auto_regenerate" value="1" ' . checked($checked, '1', false) . '> '
                . __('Automatically regenerate menu files when a category is created, edited, or deleted', 'mlcm') . '</label>';
            echo '<p class="description">';
            echo __('Requires "Use Static JavaScript Files" to be enabled. Regeneration runs in the background via WP-Cron after the configured delay, so it does not slow down category saves.', 'mlcm');
            echo '</p>';
        }, 'mlcm_options', 'mlcm_main');

        add_settings_field('mlcm_generate_gzip', 'Generate Gzip Sidecars', function () use ($options) {
            echo '<label><input type="checkbox" name="mlcm_generate_gzip" value="1" ' . checked($options['generate_gzip'], true, false) . '> Generate .gz files</label>';
            echo '<p class="description">Off by default. Enable only if your origin is configured to serve precompressed .gz files. No .htaccess is created or changed.</p>';
        }, 'mlcm_options', 'mlcm_main');

        add_settings_field('mlcm_regen_delay', 'Auto-Regeneration Delay (seconds)', function () use ($options) {
            $delay = $options['regen_delay'];
            // Show scheduled status.
            $next = wp_next_scheduled(self::CRON_HOOK);
            $status = '';
            if ($next) {
                $in = $next - time();
                $status = ' &nbsp;<span style="color:#2271b1;">&rarr; ' . sprintf(__('Scheduled in %d sec', 'mlcm'), max(0, $in)) . '</span>';
            }
            echo '<input type="number" min="10" max="60" step="1" name="mlcm_regen_delay" value="' . esc_attr($delay) . '">' . $status;
            echo '<p class="description">' . __('Delay in seconds (10–60) between the last category change and menu file regeneration. Multiple rapid changes are coalesced — the timer resets on each change.', 'mlcm') . '</p>';
        }, 'mlcm_options', 'mlcm_main');

        add_settings_field('mlcm_exclude', 'Excluded Categories', function () use ($options) {
            echo '<input type="text" name="mlcm_excluded_cats" placeholder="Comma-separated IDs" value="' . esc_attr($options['excluded_cats']) . '">';
        }, 'mlcm_options', 'mlcm_main');

        for ($i = 1; $i <= $max; $i++) {
            add_settings_field("mlcm_label_{$i}", "Level {$i} Label", function () use ($i, $options) {
                $label = $options['labels'][$i - 1] ?? '';
                echo '<input type="text" name="mlcm_level_' . $i . '_label" value="' . esc_attr($label) . '">';
            }, 'mlcm_options', 'mlcm_main');
        }

        add_settings_field('mlcm_generation', 'Menu Generation', function () {
            echo '<button type="button" class="button button-primary" id="mlcm-generate-menu">' . __('Generate Menu Files', 'mlcm') . '</button>
                <span class="spinner" style="float:none; margin-left:10px"></span>
                <button type="button" class="button button-secondary" id="mlcm-delete-cache" style="margin-left:10px;">' . __('Delete Cache Files', 'mlcm') . '</button>
                <span class="spinner" style="float:none; margin-left:10px"></span>
                <div id="mlcm-generation-status" style="margin-top:10px;"></div>';
        }, 'mlcm_options', 'mlcm_main');

    }

    public function maybe_clear_options_cache($option_name) {
        if (strpos($option_name, 'mlcm_') === 0) {
            $this->options_cache = null;
            if (in_array($option_name, ['mlcm_auto_regenerate', 'mlcm_use_static_files'], true)
                && !get_option($option_name, false)) {
                wp_clear_scheduled_hook(self::CRON_HOOK);
            }
        }
    }

    public function add_admin_menu() {
        add_options_page(
            'Category Menu Settings',
            'Category Menu',
            'manage_options',
            'mlcm-settings',
            [$this, 'settings_page']
        );
    }

    public function settings_page() {
        if (!current_user_can('manage_options')) return;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Category Menu Settings', 'mlcm'); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('mlcm_options');
                do_settings_sections('mlcm_options');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function register_widget() {
        if (!class_exists('MLCM_Widget')) {
            require_once __DIR__ . '/includes/widget.php';
        }
        register_widget('MLCM_Widget');
    }

    public function enqueue_block_editor_assets() {
        $this->register_editor_assets();
        wp_enqueue_script('mlcm-block-editor');
        wp_enqueue_style('mlcm-block-editor');
    }

    private function register_editor_assets() {
        if (wp_script_is('mlcm-block-editor', 'registered')) return;
        $options         = $this->get_options();
        $plugin_dir_url  = plugin_dir_url(__FILE__);
        $plugin_dir_path = plugin_dir_path(__FILE__);

        $js_min = $plugin_dir_path . 'assets/js/block-editor.min.js';
        $js_reg = $plugin_dir_path . 'assets/js/block-editor.js';
        if (file_exists($js_min)) {
            $js_file = $plugin_dir_url . 'assets/js/block-editor.min.js';
            $js_ver  = filemtime($js_min);
        } elseif (file_exists($js_reg)) {
            $js_file = $plugin_dir_url . 'assets/js/block-editor.js';
            $js_ver  = filemtime($js_reg);
        } else {
            $js_file = $plugin_dir_url . 'assets/js/block-editor.js';
            $js_ver  = '3.10.0';
        }

        wp_register_script('mlcm-block-editor', $js_file,
            ['wp-blocks', 'wp-block-editor', 'wp-i18n', 'wp-element', 'wp-components'],
            $js_ver
        );

        $css_min = $plugin_dir_path . 'assets/css/block-editor.min.css';
        $css_reg = $plugin_dir_path . 'assets/css/block-editor.css';
        if (file_exists($css_min)) {
            $css_file = $plugin_dir_url . 'assets/css/block-editor.min.css';
            $css_ver  = filemtime($css_min);
        } elseif (file_exists($css_reg)) {
            $css_file = $plugin_dir_url . 'assets/css/block-editor.css';
            $css_ver  = filemtime($css_reg);
        } else {
            $css_file = $plugin_dir_url . 'assets/css/block-editor.css';
            $css_ver  = '3.10.0';
        }

        wp_register_style('mlcm-block-editor', $css_file, [], $css_ver);

        wp_localize_script('mlcm-block-editor', 'mlcmBlockVars', [
            'default_layout' => $options['menu_layout'],
            'default_levels' => $options['initial_levels'],
            'max_levels' => $options['max_levels'],
            'api_version' => version_compare(get_bloginfo('version'), '6.3', '>=') ? 3 : 2,
        ]);
    }

    public function enqueue_admin_assets($hook) {
        if ($hook !== 'settings_page_mlcm-settings') return;

        $plugin_dir_url  = plugin_dir_url(__FILE__);
        $plugin_dir_path = plugin_dir_path(__FILE__);

        $css_min = $plugin_dir_path . 'assets/css/admin.min.css';
        $css_reg = $plugin_dir_path . 'assets/css/admin.css';
        if (file_exists($css_min)) {
            $css_file = $plugin_dir_url . 'assets/css/admin.min.css';
            $css_ver  = filemtime($css_min);
        } elseif (file_exists($css_reg)) {
            $css_file = $plugin_dir_url . 'assets/css/admin.css';
            $css_ver  = filemtime($css_reg);
        } else {
            $css_file = $plugin_dir_url . 'assets/css/admin.css';
            $css_ver  = '3.10.0';
        }
        wp_enqueue_style('mlcm-admin', $css_file, [], $css_ver);

        $js_min = $plugin_dir_path . 'assets/js/admin.min.js';
        $js_reg = $plugin_dir_path . 'assets/js/admin.js';
        if (file_exists($js_min)) {
            $js_file = $plugin_dir_url . 'assets/js/admin.min.js';
            $js_ver  = filemtime($js_min);
        } elseif (file_exists($js_reg)) {
            $js_file = $plugin_dir_url . 'assets/js/admin.js';
            $js_ver  = filemtime($js_reg);
        } else {
            $js_file = $plugin_dir_url . 'assets/js/admin.js';
            $js_ver  = '3.10.0';
        }
        wp_enqueue_script('mlcm-admin', $js_file, ['jquery'], $js_ver, true);

        wp_localize_script('mlcm-admin', 'mlcmAdmin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('mlcm_admin_nonce'),
            'i18n'     => [
                'generating'     => __('Generating menu...', 'mlcm'),
                'menu_generated' => __('Menu generated successfully', 'mlcm'),
                'error'          => __('Error generating menu', 'mlcm'),
                'deleting'       => __('Deleting cache files...', 'mlcm'),
                'cache_deleted'  => __('Cache files deleted successfully', 'mlcm'),
                'delete_error'   => __('Error deleting cache files', 'mlcm'),
                'confirm_delete' => __('Are you sure you want to delete all cache files?', 'mlcm'),
            ],
        ]);
    }
}

// Activation / deactivation hooks must be registered before the instance is created.
register_activation_hook(__FILE__,   ['Multi_Level_Category_Menu', 'on_activate']);
register_deactivation_hook(__FILE__, ['Multi_Level_Category_Menu', 'on_deactivate']);

Multi_Level_Category_Menu::get_instance();
