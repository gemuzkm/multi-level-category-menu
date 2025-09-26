<?php
/**
 * Plugin Name: Multi-Level Category Menu
 * Plugin URI: https://github.com/gemuzkm/multi-level-category-menu
 * Description: Creates customizable category menus with 5-level depth featuring advanced performance optimizations, AJAX lazy loading, responsive design, and accessibility compliance
 * Version: 3.6
 * Author: Name
 * Text Domain: mlcm
 * Domain Path: /languages
 * Requires at least: 5.8
 * Tested up to: 6.8
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Network: false
 */

// Exit if accessed directly - security measure
defined('ABSPATH') || exit;

/**
 * Main Plugin Class - Multi-Level Category Menu
 * 
 * Handles all plugin functionality including shortcodes, widgets, Gutenberg blocks,
 * caching system, database optimization, and admin interface.
 * 
 * @since 3.0
 */
class Multi_Level_Category_Menu {
    
    /**
     * Single instance of the plugin (Singleton pattern)
     * @var Multi_Level_Category_Menu|null
     */
    private static $instance;
    
    /**
     * Cache group name for WordPress object cache
     * @var string
     */
    private $cache_group = 'mlcm_cache';
    
    /**
     * Flag to track if frontend assets have been enqueued
     * @var boolean
     */
    private $assets_enqueued = false;

    /**
     * Get singleton instance of the plugin
     * 
     * @return Multi_Level_Category_Menu Single instance
     */
    public static function get_instance() {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - Initialize plugin hooks and functionality
     * 
     * Sets up all WordPress hooks, registers shortcodes, widgets, blocks,
     * and initializes caching and optimization features.
     */
    public function __construct() {
        // Register shortcode for menu display
        add_shortcode('mlcm_menu', [$this, 'shortcode_handler']);
        
        // Register widget for sidebar integration
        add_action('widgets_init', [$this, 'register_widget']);
        
        // Register Gutenberg block for block editor
        add_action('init', [$this, 'register_gutenberg_block']);
        
        // Add compatibility with FlyingPress caching plugin
        add_action('init', [$this, 'add_flyingpress_compatibility']);
        
        // Asset loading optimization
        add_action('wp_enqueue_scripts', [$this, 'maybe_enqueue_assets'], 5);
        add_action('wp_footer', [$this, 'ensure_assets_loaded']);
        
        // Block editor assets
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_block_editor_assets']);
        
        // Admin interface
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        
        // AJAX handlers for dynamic loading
        add_action('wp_ajax_mlcm_get_subcategories', [$this, 'ajax_lazy_load_submenu']);
        add_action('wp_ajax_nopriv_mlcm_get_subcategories', [$this, 'ajax_lazy_load_submenu']);
        
        // Cache invalidation on category changes
        add_action('edited_category', [$this, 'clear_related_cache']);
        add_action('create_category', [$this, 'clear_related_cache']);
        add_action('delete_category', [$this, 'clear_related_cache']);
        
        // Admin assets
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        
        // Scheduled cache cleanup
        add_action('mlcm_cleanup_transients', [$this, 'cleanup_expired_transients']);
        
        // Schedule daily cache cleanup if not already scheduled
        if (!wp_next_scheduled('mlcm_cleanup_transients')) {
            wp_schedule_event(time(), 'daily', 'mlcm_cleanup_transients');
        }
        
        // Create database indexes on plugin activation
        register_activation_hook(__FILE__, [$this, 'create_database_indexes']);
    }

    /**
     * Add compatibility with FlyingPress minification plugin
     * 
     * Excludes plugin assets from FlyingPress minification to prevent
     * conflicts and maintain functionality.
     */
    public function add_flyingpress_compatibility() {
        // Exclude CSS from FlyingPress minification
        add_filter('flying_press_exclude_from_minify:css', function ($exclude_keywords) {
            if (!is_array($exclude_keywords)) {
                $exclude_keywords = [];
            }
            $exclude_keywords[] = '/wp-content/plugins/multi-level-category-menu/assets/css/frontend.css';
            return array_unique($exclude_keywords);
        });
        
        // Exclude JavaScript from FlyingPress minification
        add_filter('flying_press_exclude_from_minify:js', function ($exclude_keywords) {
            if (!is_array($exclude_keywords)) {
                $exclude_keywords = [];
            }
            $exclude_keywords[] = '/wp-content/plugins/multi-level-category-menu/assets/js/frontend.js';
            return array_unique($exclude_keywords);
        });
    }

    /**
     * Create database indexes for performance optimization
     * 
     * Creates custom indexes on WordPress tables to improve category
     * hierarchy query performance. Called on plugin activation.
     * 
     * @global wpdb $wpdb WordPress database abstraction object
     */
    public function create_database_indexes() {
        global $wpdb;
        
        // Define indexes to create for optimized category queries
        $indexes_to_create = [
            'idx_category_parent_order' => [
                'table' => $wpdb->term_taxonomy,
                'query' => "CREATE INDEX idx_category_parent_order ON {$wpdb->term_taxonomy} (parent, term_taxonomy_id, taxonomy)",
                'check' => "SHOW INDEX FROM {$wpdb->term_taxonomy} WHERE Key_name = 'idx_category_parent_order'"
            ],
            'idx_category_hierarchy' => [
                'table' => $wpdb->term_relationships,
                'query' => "CREATE INDEX idx_category_hierarchy ON {$wpdb->term_relationships} (object_id, term_taxonomy_id)",
                'check' => "SHOW INDEX FROM {$wpdb->term_relationships} WHERE Key_name = 'idx_category_hierarchy'"
            ],
            'idx_taxonomy_parent_name' => [
                'table' => $wpdb->term_taxonomy,
                'query' => "CREATE INDEX idx_taxonomy_parent_name ON {$wpdb->term_taxonomy} (taxonomy, parent)",
                'check' => "SHOW INDEX FROM {$wpdb->term_taxonomy} WHERE Key_name = 'idx_taxonomy_parent_name'"
            ]
        ];
        
        // Create each index if it doesn't already exist
        foreach ($indexes_to_create as $index_name => $config) {
            $existing = $wpdb->get_results($config['check']);
            if (empty($existing)) {
                $result = $wpdb->query($config['query']);
                if ($result === false) {
                    error_log("MLCM: Failed to create index {$index_name}: " . $wpdb->last_error);
                } else {
                    error_log("MLCM: Successfully created index {$index_name}");
                }
            }
        }
    }

    /**
     * Sanitize menu title for consistent display
     * 
     * Handles HTML entities, special characters, and ensures consistent
     * uppercase formatting for menu items.
     * 
     * @param string $title Raw category title
     * @return string Sanitized and formatted title
     */
    private function sanitize_menu_title($title) {
        // Decode HTML entities
        $decoded = html_entity_decode($title, ENT_QUOTES | ENT_HTML401, 'UTF-8');
        
        // Sanitize for safe display
        $sanitized = sanitize_text_field($decoded);
        
        // Normalize whitespace
        $normalized = trim($sanitized);
        
        // Convert to uppercase for consistent display
        return mb_strtoupper($normalized, 'UTF-8');
    }

    /**
     * Log query performance for monitoring
     * 
     * Logs slow queries and integrates with Query Monitor if available
     * for performance debugging and optimization.
     * 
     * @param string $query Description of the query
     * @param float $execution_time Time taken in milliseconds
     */
    private function log_query_performance($query, $execution_time) {
        // Log to Query Monitor if available
        if (class_exists('QM_Collectors')) {
            do_action('qm/debug', "MLCM Query: {$query} - Time: {$execution_time}ms");
        }
        
        // Log slow queries to error log
        if ($execution_time > 100) {
            error_log("MLCM Slow Query: {$query} - Time: {$execution_time}ms");
        }
    }

    /**
     * Cleanup expired transient cache entries
     * 
     * Removes expired transients from database to prevent accumulation
     * of stale cache data. Called daily via WordPress cron.
     * 
     * @global wpdb $wpdb WordPress database abstraction object
     */
    public function cleanup_expired_transients() {
        global $wpdb;
        $start_time = microtime(true);
        
        // Delete expired timeout records
        $deleted_timeouts = $wpdb->query("DELETE FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_timeout_mlcm_%' 
            AND option_value < UNIX_TIMESTAMP()");
        
        // Delete orphaned transients (those without valid timeout records)
        $deleted_transients = $wpdb->query("DELETE FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_mlcm_%' 
            AND option_name NOT LIKE '_transient_timeout_%'
            AND REPLACE(option_name, '_transient_', '_transient_timeout_') NOT IN (
                SELECT option_name FROM {$wpdb->options} 
                WHERE option_name LIKE '_transient_timeout_mlcm_%'
            )");
        
        $execution_time = (microtime(true) - $start_time) * 1000;
        $this->log_query_performance(
            "Cleanup expired transients: {$deleted_timeouts} timeouts, {$deleted_transients} transients", 
            $execution_time
        );
    }

    /**
     * Conditionally enqueue assets early in page load
     * 
     * Determines if assets should be loaded early based on page type
     * and content to optimize performance and prevent render blocking.
     */
    public function maybe_enqueue_assets() {
        if ($this->should_load_assets_early()) {
            $this->enqueue_frontend_assets();
            $this->assets_enqueued = true;
        }
    }

    /**
     * Ensure assets are loaded if needed in footer
     * 
     * Fallback method to load assets in footer if they weren't loaded
     * earlier but are needed for menu functionality.
     */
    public function ensure_assets_loaded() {
        if (!$this->assets_enqueued && $this->should_load_assets_late()) {
            $this->enqueue_frontend_assets();
            $this->assets_enqueued = true;
        }
    }

    /**
     * Determine if assets should be loaded early
     * 
     * Checks page type, content, and active widgets to determine
     * if menu assets are needed for early loading.
     * 
     * @global WP_Post $post Current post object
     * @return boolean True if assets should be loaded early
     */
    private function should_load_assets_early() {
        global $post;
        
        // Load on category/archive pages
        if (is_category() || is_archive()) {
            return true;
        }
        
        // Load on front page and home page
        if (is_front_page() || is_home()) {
            return true;
        }
        
        // Load if widget is active
        if ($this->is_menu_widget_active()) {
            return true;
        }
        
        // Check singular posts for shortcode or block usage
        if (is_singular() && is_a($post, 'WP_Post')) {
            // Check for shortcode in post content
            if ($this->has_menu_shortcode($post->post_content)) {
                return true;
            }
            
            // Check for Gutenberg block
            if (has_block('mlcm/menu-block', $post)) {
                return true;
            }
            
            // Check custom fields for shortcode usage
            $custom_fields = get_post_meta($post->ID);
            foreach ($custom_fields as $field_value) {
                if (is_array($field_value)) {
                    foreach ($field_value as $value) {
                        if (is_string($value) && $this->has_menu_shortcode($value)) {
                            return true;
                        }
                    }
                }
            }
        }
        
        return false;
    }

    /**
     * Determine if assets should be loaded late (in footer)
     * 
     * Checks for runtime conditions that indicate the menu is needed
     * but wasn't detected during early loading.
     * 
     * @return boolean True if assets should be loaded late
     */
    private function should_load_assets_late() {
        // Check if shortcode was executed
        if (did_action('mlcm_shortcode_executed')) {
            return true;
        }
        
        // Check global flag set by menu usage
        if (isset($GLOBALS['mlcm_needed']) && $GLOBALS['mlcm_needed']) {
            return true;
        }
        
        return false;
    }

    /**
     * Check if content contains menu shortcode
     * 
     * Searches content for shortcode patterns using both WordPress
     * shortcode detection and regex patterns.
     * 
     * @param string $content Content to search
     * @return boolean True if shortcode found
     */
    private function has_menu_shortcode($content = '') {
        if (empty($content)) {
            return false;
        }
        
        // WordPress built-in shortcode detection
        if (has_shortcode($content, 'mlcm_menu')) {
            return true;
        }
        
        // Additional regex check for escaped or complex shortcodes
        if (preg_match('/\\[mlcm_menu(?:\\s[^\\]]*)?]/', $content)) {
            return true;
        }
        
        return false;
    }

    /**
     * Check if menu widget is active
     * 
     * Determines if the plugin widget is active in any sidebar
     * to enable early asset loading.
     * 
     * @return boolean True if widget is active
     */
    private function is_menu_widget_active() {
        // Check using WordPress widget detection
        if (is_active_widget(false, false, 'mlcm_widget')) {
            return true;
        }
        
        // Manual check of sidebar widgets
        $sidebars_widgets = get_option('sidebars_widgets', array());
        foreach ($sidebars_widgets as $sidebar_id => $widgets) {
            if (is_array($widgets)) {
                foreach ($widgets as $widget_id) {
                    if (strpos($widget_id, 'mlcm_widget') !== false) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    }

    /**
     * Register Gutenberg block for menu
     * 
     * Registers the menu block with the WordPress block editor,
     * including editor script and render callback.
     */
    public function register_gutenberg_block() {
        // Register block editor script
        wp_register_script(
            'mlcm-block-editor',
            plugins_url('assets/js/block-editor.js', __FILE__),
            ['wp-blocks', 'wp-i18n', 'wp-element', 'wp-components', 'wp-editor'],
            filemtime(plugin_dir_path(__FILE__) . 'assets/js/block-editor.js')
        );

        // Register block type with attributes
        register_block_type('mlcm/menu-block', [
            'editor_script' => 'mlcm-block-editor',
            'render_callback' => [$this, 'render_gutenberg_block'],
            'attributes' => [
                'layout' => [
                    'type' => 'string',
                    'default' => 'vertical'
                ],
                'levels' => [
                    'type' => 'number',
                    'default' => 3
                ],
                'root_id' => [
                    'type' => 'number',
                    'default' => 0
                ]
            ]
        ]);
    }

    /**
     * Render Gutenberg block content
     * 
     * Processes block attributes and generates menu HTML for
     * Gutenberg block display.
     * 
     * @param array $attributes Block attributes
     * @return string Rendered block HTML
     */
    public function render_gutenberg_block($attributes) {
        $atts = shortcode_atts([
            'layout' => 'vertical',
            'levels' => 3,
            'root_id' => 0
        ], $attributes);
        
        return $this->generate_menu_html($atts);
    }

    /**
     * Handle shortcode execution
     * 
     * Main shortcode handler that processes attributes, ensures assets
     * are loaded, and generates the menu HTML.
     * 
     * @param array|string $atts Shortcode attributes
     * @return string Generated menu HTML
     */
    public function shortcode_handler($atts) {
        // Trigger action to indicate shortcode execution
        do_action('mlcm_shortcode_executed');
        
        // Set global flag for late asset loading
        $GLOBALS['mlcm_needed'] = true;
        
        // Ensure assets are loaded
        if (!$this->assets_enqueued) {
            $this->enqueue_frontend_assets();
            $this->assets_enqueued = true;
        }
        
        // Process shortcode attributes with defaults
        $atts = shortcode_atts([
            'layout' => get_option('mlcm_menu_layout', 'vertical'),
            'levels' => absint(get_option('mlcm_initial_levels', 3)),
            'root_id' => 0
        ], $atts);
        
        return $this->generate_menu_html($atts);
    }

    /**
     * Generate menu HTML structure
     * 
     * Creates the complete menu HTML with select elements for each level,
     * optional navigation button, and proper accessibility attributes.
     * 
     * @param array $atts Menu configuration attributes
     * @return string Complete menu HTML
     */
    private function generate_menu_html($atts) {
        $show_button = get_option('mlcm_show_button', '0') === '1';
        $root_id = 0;
        
        // Determine root category ID
        if (!empty($atts['root_id']) && is_numeric($atts['root_id'])) {
            $root_id = absint($atts['root_id']);
        } else {
            $custom_root_id = get_option('mlcm_custom_root_id', '');
            if (!empty($custom_root_id) && is_numeric($custom_root_id)) {
                $root_id = absint($custom_root_id);
            }
        }
        
        // Generate menu HTML using output buffering
        ob_start(); ?>
        <div class="mlcm-container <?= esc_attr($atts['layout']) ?>" 
             data-levels="<?= absint($atts['levels']) ?>"
             data-root-id="<?= $root_id ?>">
            <?php for($i = 1; $i <= $atts['levels']; $i++): ?>
                <div class="mlcm-level">
                    <?php $this->render_select($i, $root_id); ?>
                </div>
            <?php endfor; ?>
            <?php if ($show_button): ?>
                <button type="button" class="mlcm-go-button">Go</button>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render individual select element for menu level
     * 
     * Generates HTML for a single select dropdown with proper
     * accessibility attributes and initial category options.
     * 
     * @param int $level Menu level number (1-5)
     * @param int $root_id Root category ID for first level
     */
    private function render_select($level, $root_id = 0) {
        $label = get_option("mlcm_level_{$level}_label", "Level {$level}");
        $categories = [];
        
        // Load categories for first level only
        if ($level === 1) {
            $categories = $this->get_menu_fragment(1, $root_id);
        }
        
        $select_id = "mlcm-select-level-{$level}";
        $label_id = "mlcm-label-level-{$level}";
        ?>
        <div class="mlcm-select-wrapper">
            <select class="mlcm-select" 
                    id="<?= esc_attr($select_id) ?>"
                    aria-label="<?= esc_attr($label) ?>"
                    title="<?= esc_attr($label) ?>"
                    data-level="<?= $level ?>" 
                    <?= $level > 1 ? 'disabled' : '' ?>>
                <option value="-1"><?= esc_html($label) ?></option>
                <?php foreach ($categories as $id => $data): 
                    $cat_link = get_category_link($id);
                ?>
                    <option value="<?= $id ?>" 
                            data-slug="<?= esc_attr($data['slug']) ?>" 
                            data-url="<?= esc_url($cat_link) ?>">
                        <?= esc_html($data['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php
    }

    /**
     * Get cached menu fragment for specific level and parent
     * 
     * Retrieves category data from cache or generates it if not cached.
     * Uses both object cache and transients for maximum performance.
     * 
     * @param int $level Menu level
     * @param int $parent_id Parent category ID
     * @return array Category data array
     */
    public function get_menu_fragment($level, $parent_id = 0) {
        $fragment_key = "mlcm_fragment_{$level}_{$parent_id}";
        
        // Try object cache first
        $fragment = wp_cache_get($fragment_key, $this->cache_group);
        
        if (false === $fragment) {
            // Try transient cache
            $fragment = get_transient($fragment_key);
            
            if (false === $fragment) {
                // Generate fresh data
                $fragment = $this->build_hierarchical_menu($parent_id);
                
                // Cache for 2 hours
                set_transient($fragment_key, $fragment, 2 * HOUR_IN_SECONDS);
            }
            
            // Store in object cache for 1 hour
            wp_cache_set($fragment_key, $fragment, $this->cache_group, HOUR_IN_SECONDS);
        }
        
        return $fragment;
    }

    /**
     * Build hierarchical menu data from database
     * 
     * Executes optimized SQL query to retrieve category hierarchy
     * with performance logging and error handling.
     * 
     * @param int $parent_id Parent category ID
     * @return array Formatted category data
     * @global wpdb $wpdb WordPress database abstraction object
     */
    public function build_hierarchical_menu($parent_id = 0) {
        global $wpdb;
        $start_time = microtime(true);
        
        // Get excluded categories from settings
        $excluded = array_map('absint', array_filter(explode(',', get_option('mlcm_excluded_cats', ''))));
        $excluded_sql = !empty($excluded) ? 'AND t.term_id NOT IN (' . implode(',', $excluded) . ')' : '';
        
        // Optimized query with prepared statement
        $sql = $wpdb->prepare("
            SELECT t.term_id, t.name, t.slug, tt.parent, tt.term_taxonomy_id
            FROM {$wpdb->terms} t
            INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
            WHERE tt.taxonomy = 'category' 
            AND tt.parent = %d 
            {$excluded_sql}
            ORDER BY t.name ASC
        ", $parent_id);
        
        $results = $wpdb->get_results($sql);
        
        // Log database errors
        if ($wpdb->last_error) {
            error_log("MLCM SQL Error: " . $wpdb->last_error);
            return [];
        }
        
        // Log performance metrics
        $execution_time = (microtime(true) - $start_time) * 1000;
        $this->log_query_performance("build_hierarchical_menu SQL (parent: {$parent_id})", $execution_time);
        
        // Format results for menu display
        $categories = [];
        foreach ($results as $category) {
            $categories[$category->term_id] = [
                'name' => $this->sanitize_menu_title($category->name),
                'slug' => $category->slug
            ];
        }
        
        return $categories;
    }

    /**
     * AJAX handler for lazy loading subcategories
     * 
     * Handles AJAX requests for loading subcategories dynamically
     * when user selects a parent category.
     */
    public function ajax_lazy_load_submenu() {
        // Verify nonce for security
        check_ajax_referer('mlcm_nonce', 'security');
        
        $start_time = microtime(true);
        $parent_id = absint($_POST['parent_id'] ?? 0);
        
        $cached_key = "mlcm_submenu_{$parent_id}";
        
        // Try object cache first
        $submenu = wp_cache_get($cached_key, $this->cache_group);
        
        if (false === $submenu) {
            // Try transient cache
            $submenu = get_transient($cached_key);
            
            if (false === $submenu) {
                // Build fresh submenu data
                $categories = $this->build_hierarchical_menu($parent_id);
                $submenu = [];
                
                foreach ($categories as $id => $data) {
                    $submenu[$id] = [
                        'name' => $data['name'],
                        'slug' => $data['slug'],
                        'url' => get_category_link($id)
                    ];
                }
                
                // Cache for 30 minutes
                set_transient($cached_key, $submenu, 30 * MINUTE_IN_SECONDS);
                
                $execution_time = (microtime(true) - $start_time) * 1000;
                $this->log_query_performance("ajax_lazy_load_submenu from DB (parent: {$parent_id})", $execution_time);
            }
            
            // Store in object cache for 15 minutes
            wp_cache_set($cached_key, $submenu, $this->cache_group, 15 * MINUTE_IN_SECONDS);
        }
        
        wp_send_json_success($submenu);
    }

    /**
     * Clear related cache entries when category is modified
     * 
     * Invalidates relevant cache entries when categories are created,
     * edited, or deleted to ensure data consistency.
     * 
     * @param int $term_id Category term ID
     */
    public function clear_related_cache($term_id) {
        $term = get_term($term_id);
        if (!$term || is_wp_error($term)) {
            return;
        }
        
        // Define cache keys to clear
        $cache_keys_to_clear = [
            "mlcm_cats_{$term->term_id}",
            "mlcm_subcats_{$term->term_id}",
            "mlcm_submenu_{$term->term_id}",
            "mlcm_fragment_1_{$term->term_id}",
            "mlcm_cats_{$term->parent}",
            "mlcm_subcats_{$term->parent}",
            "mlcm_submenu_{$term->parent}",
            "mlcm_fragment_1_{$term->parent}"
        ];
        
        // Clear object cache
        foreach ($cache_keys_to_clear as $key) {
            wp_cache_delete($key, $this->cache_group);
        }
        
        // Clear transients
        $transient_keys_to_clear = [
            "mlcm_cats_{$term->term_id}",
            "mlcm_subcats_{$term->term_id}",
            "mlcm_submenu_{$term->term_id}",
            "mlcm_fragment_1_{$term->term_id}",
            "mlcm_cats_{$term->parent}",
            "mlcm_subcats_{$term->parent}",
            "mlcm_submenu_{$term->parent}",
            "mlcm_fragment_1_{$term->parent}"
        ];
        
        foreach ($transient_keys_to_clear as $key) {
            delete_transient($key);
        }
        
        // Clear root level cache if this is a root category
        if ($term->parent == 0) {
            wp_cache_delete('mlcm_fragment_1_0', $this->cache_group);
            delete_transient('mlcm_fragment_1_0');
            
            // Clear custom root cache if applicable
            $custom_root_id = get_option('mlcm_custom_root_id', '');
            if (!empty($custom_root_id) && absint($custom_root_id) == $term->term_id) {
                wp_cache_delete("mlcm_fragment_1_{$custom_root_id}", $this->cache_group);
                delete_transient("mlcm_fragment_1_{$custom_root_id}");
            }
        }
        
        error_log("MLCM: Cleared cache for category {$term->name} (ID: {$term_id})");
    }

    /**
     * Register plugin settings and admin fields
     * 
     * Registers all plugin options and creates admin interface fields
     * for the settings page with proper sanitization and validation.
     */
    public function register_settings() {
        // Register all plugin settings
        register_setting('mlcm_options', 'mlcm_font_size');
        register_setting('mlcm_options', 'mlcm_container_gap');
        register_setting('mlcm_options', 'mlcm_button_bg_color');
        register_setting('mlcm_options', 'mlcm_button_font_size');
        register_setting('mlcm_options', 'mlcm_button_hover_bg_color');
        register_setting('mlcm_options', 'mlcm_menu_layout');
        register_setting('mlcm_options', 'mlcm_initial_levels');
        register_setting('mlcm_options', 'mlcm_excluded_cats');
        register_setting('mlcm_options', 'mlcm_menu_width');
        register_setting('mlcm_options', 'mlcm_show_button');
        register_setting('mlcm_options', 'mlcm_use_category_base');
        register_setting('mlcm_options', 'mlcm_custom_root_id');
        
        // Register level label settings
        for ($i = 1; $i <= 5; $i++) {
            register_setting('mlcm_options', "mlcm_level_{$i}_label");
        }
        
        // Add settings section
        add_settings_section('mlcm_main', __('Main Settings', 'mlcm'), null, 'mlcm_options');
        
        // Font size setting
        add_settings_field('mlcm_font_size', __('Font Size for Menu Items (rem)', 'mlcm'), function() {
            $font_size = get_option('mlcm_font_size', '');
            echo '<input type="number" step="0.1" min="0.5" max="3" name="mlcm_font_size" value="' . esc_attr($font_size) . '" placeholder="1.0" />';
        }, 'mlcm_options', 'mlcm_main');
        
        // Container gap setting
        add_settings_field('mlcm_container_gap', __('Gap Between Menu Items (px)', 'mlcm'), function() {
            $gap = get_option('mlcm_container_gap', '');
            echo '<input type="number" min="0" max="100" name="mlcm_container_gap" value="' . esc_attr($gap) . '" placeholder="20" />';
        }, 'mlcm_options', 'mlcm_main');
        
        // Button background color
        add_settings_field('mlcm_button_bg_color', __('Button Background Color', 'mlcm'), function() {
            $bg_color = get_option('mlcm_button_bg_color', '');
            echo '<input type="color" name="mlcm_button_bg_color" value="' . esc_attr($bg_color) . '" />';
        }, 'mlcm_options', 'mlcm_main');
        
        // Button hover color
        add_settings_field('mlcm_button_hover_bg_color', __('Button Hover Background Color', 'mlcm'), function() {
            $hover_bg_color = get_option('mlcm_button_hover_bg_color', '');
            echo '<input type="color" name="mlcm_button_hover_bg_color" value="' . esc_attr($hover_bg_color) . '" />';
        }, 'mlcm_options', 'mlcm_main');
        
        // Button font size
        add_settings_field('mlcm_button_font_size', __('Button Font Size (rem)', 'mlcm'), function() {
            $font_size = get_option('mlcm_button_font_size', '');
            echo '<input type="number" step="0.1" min="0.5" max="3" name="mlcm_button_font_size" value="' . esc_attr($font_size) . '" placeholder="1.0" />';
        }, 'mlcm_options', 'mlcm_main');
        
        // Custom root category ID with preview
        add_settings_field('mlcm_custom_root_id', __('Custom Root Category ID', 'mlcm'), function() {
            $custom_root_id = get_option('mlcm_custom_root_id', '');
            echo '<input type="number" min="0" name="mlcm_custom_root_id" value="' . esc_attr($custom_root_id) . '" placeholder="0" />';
            echo '<p class="description">' . __('Specify the ID of the category whose subcategories will be used as the first level of the menu. Leave blank to use root categories (parent = 0).', 'mlcm') . '</p>';
            
            // Show current root category info
            if (!empty($custom_root_id) && is_numeric($custom_root_id)) {
                $category = get_category($custom_root_id);
                if ($category && !is_wp_error($category)) {
                    echo '<p><strong>' . __('Current root category:', 'mlcm') . '</strong> ' . esc_html($category->name) . ' (ID: ' . $category->term_id . ')</p>';
                    
                    $subcategories = get_categories(['parent' => $custom_root_id, 'hide_empty' => false]);
                    if (!empty($subcategories)) {
                        echo '<p><strong>' . __('Subcategories:', 'mlcm') . '</strong> ';
                        $sub_names = array_map(function($cat) { return $cat->name; }, $subcategories);
                        echo esc_html(implode(', ', $sub_names));
                        echo '</p>';
                    } else {
                        echo '<p class="notice notice-warning inline"><strong>' . __('Warning:', 'mlcm') . '</strong> ' . __('This category has no subcategories.', 'mlcm') . '</p>';
                    }
                } else {
                    echo '<p class="notice notice-error inline"><strong>' . __('Error:', 'mlcm') . '</strong> ' . sprintf(__('Category with ID %s not found.', 'mlcm'), $custom_root_id) . '</p>';
                }
            }
        }, 'mlcm_options', 'mlcm_main');
        
        // Menu layout setting
        add_settings_field('mlcm_layout', __('Menu Layout', 'mlcm'), function() {
            $layout = get_option('mlcm_menu_layout', 'vertical');
            echo '<label><input type="radio" name="mlcm_menu_layout" value="vertical" ' . checked($layout, 'vertical', false) . ' /> ' . __('Vertical', 'mlcm') . '</label><br>';
            echo '<label><input type="radio" name="mlcm_menu_layout" value="horizontal" ' . checked($layout, 'horizontal', false) . ' /> ' . __('Horizontal', 'mlcm') . '</label>';
        }, 'mlcm_options', 'mlcm_main');
        
        // Initial levels setting
        add_settings_field('mlcm_levels', __('Initial Levels', 'mlcm'), function() {
            $levels = get_option('mlcm_initial_levels', 3);
            echo '<select name="mlcm_initial_levels">';
            for ($i = 1; $i <= 5; $i++) {
                echo '<option value="' . $i . '" ' . selected($levels, $i, false) . '>' . $i . '</option>';
            }
            echo '</select>';
        }, 'mlcm_options', 'mlcm_main');
        
        // Menu width setting
        add_settings_field('mlcm_width', __('Menu Width (px)', 'mlcm'), function() {
            $width = get_option('mlcm_menu_width', 250);
            echo '<input type="number" min="100" max="500" name="mlcm_menu_width" value="' . esc_attr($width) . '" />';
        }, 'mlcm_options', 'mlcm_main');
        
        // Show button setting
        add_settings_field('mlcm_show_button', __('Show Go Button', 'mlcm'), function() {
            $show = get_option('mlcm_show_button', '0');
            echo '<label><input type="checkbox" name="mlcm_show_button" value="1" ' . checked($show, '1', false) . ' /> ' . __('Enable Go button', 'mlcm') . '</label>';
        }, 'mlcm_options', 'mlcm_main');
        
        // Category base setting
        add_settings_field('mlcm_use_category_base', __('Use Category Base', 'mlcm'), function() {
            $use_base = get_option('mlcm_use_category_base', '1');
            echo '<label><input type="checkbox" name="mlcm_use_category_base" value="1" ' . checked($use_base, '1', false) . ' /> ' . __('Include "category" in URL', 'mlcm') . '</label>';
        }, 'mlcm_options', 'mlcm_main');
        
        // Excluded categories setting
        add_settings_field('mlcm_exclude', __('Excluded Categories', 'mlcm'), function() {
            $excluded = get_option('mlcm_excluded_cats', '');
            echo '<input type="text" name="mlcm_excluded_cats" value="' . esc_attr($excluded) . '" placeholder="1,2,3" class="regular-text" />';
            echo '<p class="description">' . __('Comma-separated category IDs to exclude from menus', 'mlcm') . '</p>';
        }, 'mlcm_options', 'mlcm_main');
        
        // Level label settings
        for ($i = 1; $i <= 5; $i++) {
            add_settings_field(
                "mlcm_label_{$i}", 
                sprintf(__('Level %d Label', 'mlcm'), $i),
                function() use ($i) {
                    $label = get_option("mlcm_level_{$i}_label", "Level {$i}");
                    echo '<input type="text" name="mlcm_level_' . $i . '_label" value="' . esc_attr($label) . '" class="regular-text" />';
                },
                'mlcm_options', 
                'mlcm_main'
            );
        }
        
        // Cache management section
        add_settings_field('mlcm_cache', __('Cache Management', 'mlcm'), function() {
            echo '<button type="button" id="mlcm-clear-cache" class="button button-secondary">' . __('Clear All Cache', 'mlcm') . '</button> ';
            echo '<button type="button" id="mlcm-cleanup-transients" class="button button-secondary">' . __('Cleanup Expired Transients', 'mlcm') . '</button>';
            echo '<p class="description">' . __('Clear all cached category data including fragments to force refresh', 'mlcm') . '</p>';
            echo '<p class="description"><strong>' . __('Performance Features:', 'mlcm') . '</strong> ' . __('Database Indexes | Fragment Caching | Lazy Loading | Optimized SQL | FlyingPress Compatibility | Accessibility Labels', 'mlcm') . '</p>';
            echo '<p class="description"><strong>' . __('Shortcode Usage:', 'mlcm') . '</strong> <code>[mlcm_menu root_id="2"]</code> - ' . __('to start from specific category', 'mlcm') . '</p>';
        }, 'mlcm_options', 'mlcm_main');
    }

    /**
     * Enqueue frontend assets (CSS and JavaScript)
     * 
     * Loads frontend stylesheets and scripts with proper dependencies,
     * localization, and custom styling based on plugin settings.
     */
    public function enqueue_frontend_assets() {
        // Prevent duplicate enqueueing
        if (wp_style_is('mlcm-frontend', 'enqueued')) {
            return;
        }
        
        // Enqueue CSS
        wp_enqueue_style(
            'mlcm-frontend', 
            plugins_url('assets/css/frontend.css', __FILE__)
        );
        
        // Enqueue JavaScript with dependencies
        wp_enqueue_script(
            'mlcm-frontend', 
            plugins_url('assets/js/frontend.js', __FILE__), 
            ['jquery'], 
            null, 
            true
        );
        
        // Localize script with AJAX and configuration data
        wp_localize_script('mlcm-frontend', 'mlcmVars', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mlcm_nonce'),
            'labels' => array_map(function($i) {
                return get_option("mlcm_level_{$i}_label", "Level {$i}");
            }, range(1,5)),
            'use_category_base' => get_option('mlcm_use_category_base', '1') === '1',
        ]);
        
        // Add custom CSS based on settings
        $font_size = get_option('mlcm_font_size', '');
        $container_gap = get_option('mlcm_container_gap', '');
        $button_bg_color = get_option('mlcm_button_bg_color', '');
        $button_font_size = get_option('mlcm_button_font_size', '');
        $button_hover_bg_color = get_option('mlcm_button_hover_bg_color', '');
        
        $custom_css = '';
        if (!empty($font_size)) {
            $custom_css .= ".mlcm-select { font-size: {$font_size}rem; }";
        }
        if (!empty($container_gap)) {
            $custom_css .= ".mlcm-container { gap: {$container_gap}px; }";
        }
        if (!empty($button_bg_color)) {
            $custom_css .= ".mlcm-go-button { background: {$button_bg_color}; }";
        }
        if (!empty($button_font_size)) {
            $custom_css .= ".mlcm-go-button { font-size: {$button_font_size}rem; }";
        }
        if (!empty($button_hover_bg_color)) {
            $custom_css .= ".mlcm-go-button:hover { background: {$button_hover_bg_color}; }";
        }
        
        if (!empty($custom_css)) {
            wp_add_inline_style('mlcm-frontend', $custom_css);
        }
    }

    /**
     * Clear all plugin caches
     * 
     * Removes all cached data including object cache and transients
     * to force fresh data loading.
     * 
     * @return boolean True on success, false on failure
     * @global wpdb $wpdb WordPress database abstraction object
     */
    public function clear_all_caches() {
        global $wpdb;
        $start_time = microtime(true);
        
        // Clear object cache group if supported
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group($this->cache_group);
        }
        
        // Clear all transients related to plugin
        $result = $wpdb->query(
            "DELETE FROM $wpdb->options 
            WHERE option_name LIKE '_transient_mlcm_%' 
            OR option_name LIKE '_transient_timeout_mlcm_%'"
        );
        
        $execution_time = (microtime(true) - $start_time) * 1000;
        $this->log_query_performance("clear_all_caches including fragments", $execution_time);
        
        return $result !== false;
    }

    /**
     * Add admin menu page
     * 
     * Creates settings page in WordPress admin under Settings menu.
     */
    public function add_admin_menu() {
        add_options_page(
            __('Category Menu Settings', 'mlcm'), 
            __('Category Menu', 'mlcm'), 
            'manage_options', 
            'mlcm-settings', 
            [$this, 'settings_page']
        );
    }

    /**
     * Render admin settings page
     * 
     * Displays the plugin settings form with proper security checks
     * and WordPress admin styling.
     */
    public function settings_page() {
        if (!current_user_can('manage_options')) return; ?>
        <div class="wrap">
            <h1><?php esc_html_e('Category Menu Settings', 'mlcm') ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('mlcm_options');
                do_settings_sections('mlcm_options');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Register widget class
     * 
     * Includes and registers the widget class for sidebar integration.
     */
    public function register_widget() {
        require_once __DIR__.'/includes/widget.php';
        register_widget('MLCM_Widget');
    }

    /**
     * Enqueue block editor assets
     * 
     * Loads JavaScript for Gutenberg block editor with proper
     * dependencies and localization.
     */
    public function enqueue_block_editor_assets() {
        wp_enqueue_script(
            'mlcm-block-editor',
            plugins_url('assets/js/block-editor.js', __FILE__),
            ['wp-blocks', 'wp-i18n', 'wp-element', 'wp-components', 'wp-editor'],
            filemtime(plugin_dir_path(__FILE__) . 'assets/js/block-editor.js')
        );
        
        wp_localize_script('mlcm-block-editor', 'mlcmBlockVars', [
            'default_layout' => get_option('mlcm_menu_layout', 'vertical'),
            'default_levels' => absint(get_option('mlcm_initial_levels', 3))
        ]);
    }

    /**
     * Enqueue admin assets on settings page
     * 
     * Loads admin-specific CSS and JavaScript only on plugin settings page.
     * 
     * @param string $hook Admin page hook
     */
    public function enqueue_admin_assets($hook) {
        if ($hook === 'settings_page_mlcm-settings') {
            wp_enqueue_style('mlcm-admin', plugins_url('assets/css/admin.css', __FILE__));
            
            wp_enqueue_script(
                'mlcm-admin', 
                plugins_url('assets/js/admin.js', __FILE__), 
                ['jquery'], 
                filemtime(plugin_dir_path(__FILE__) . 'assets/js/admin.js'),
                true
            );
            
            wp_localize_script('mlcm-admin', 'mlcmAdmin', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('mlcm_admin_nonce'),
                'i18n' => [
                    'clearing' => __('Clearing...', 'mlcm'),
                    'cache_cleared' => __('Cache successfully cleared', 'mlcm'),
                    'error' => __('Error clearing cache', 'mlcm'),
                    'cleanup_done' => __('Expired transients cleaned up', 'mlcm')
                ]
            ]);
        }
    }
}

// Initialize plugin instance
Multi_Level_Category_Menu::get_instance();

/**
 * AJAX handler for clearing all caches
 * 
 * Handles admin AJAX request to clear all plugin caches
 * with proper security verification.
 */
add_action('wp_ajax_mlcm_clear_all_caches', function() {
    check_ajax_referer('mlcm_admin_nonce', 'security');
    
    try {
        $success = Multi_Level_Category_Menu::get_instance()->clear_all_caches();
        wp_send_json_success(['message' => __('Cache cleared', 'mlcm')]);
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
    wp_die();
});

/**
 * AJAX handler for cleaning up transients
 * 
 * Handles admin AJAX request to cleanup expired transient cache entries.
 */
add_action('wp_ajax_mlcm_cleanup_transients', function() {
    check_ajax_referer('mlcm_admin_nonce', 'security');
    
    try {
        Multi_Level_Category_Menu::get_instance()->cleanup_expired_transients();
        wp_send_json_success(['message' => __('Expired transients cleaned up', 'mlcm')]);
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
    wp_die();
});

/**
 * Add dynamic CSS for menu width
 * 
 * Outputs custom CSS in head for menu width configuration.
 */
add_action('wp_head', function() {
    $width = get_option('mlcm_menu_width', 250);
    echo "<style>:root { --mlcm-width: {$width}px; }</style>";
});
?>