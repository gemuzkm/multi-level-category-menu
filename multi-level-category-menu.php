<?php
/**
 * Plugin Name: Multi-Level Category Menu
 * Plugin URI: https://github.com/gemuzkm/multi-level-category-menu
 * Description: Advanced hierarchical product category menu with Cloudflare-optimized static JavaScript caching
 * Version: 3.6.1
 * Author: gemuzkm
 * Author URI: https://github.com/gemuzkm
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: multi-level-category-menu
 * Domain Path: /languages
 * GitHub Plugin URI: https://github.com/gemuzkm/multi-level-category-menu
 * GitHub Branch: main
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MLCM_VERSION', '3.6.1');
define('MLCM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MLCM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MLCM_CACHE_DIR', WP_CONTENT_DIR . '/uploads/mlcm-menu-cache');

class MLCMCacheManager {
    private $cache_dir = MLCM_CACHE_DIR;
    private $max_levels = 5;

    public function __construct() {
        add_action('wp_ajax_mlcm_generate_json', [$this, 'generate_menu_js_ajax']);
        add_action('edit_term', [$this, 'invalidate_cache_on_term_update'], 10, 3);
        add_action('delete_term', [$this, 'invalidate_cache_on_term_delete'], 10, 3);
        add_action('created_term', [$this, 'invalidate_cache_on_term_create'], 10, 3);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_shortcode('mlcm_menu', [$this, 'render_menu_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_scripts']);
        add_action('wp_footer', [$this, 'output_menu_script']);
    }

    /**
     * Generate menu JavaScript file (Cloudflare cacheable format)
     */
    public function generate_menu_js() {
        if (!wp_mkdir_p($this->cache_dir)) {
            return new WP_Error('mkdir_failed', __('Failed to create cache directory', 'multi-level-category-menu'));
        }

        $this->create_htaccess();

        $categories = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'parent' => 0,
            'number' => 500
        ]);

        if (is_wp_error($categories) || empty($categories)) {
            return new WP_Error('no_categories', __('No product categories found', 'multi-level-category-menu'));
        }

        $level_1_data = $this->build_level_structure($categories, 0);
        $this->write_menu_file(1, $level_1_data);

        for ($level = 2; $level <= $this->max_levels; $level++) {
            $level_data = $this->get_all_categories_by_level($level);
            if (!empty($level_data)) {
                $this->write_menu_file($level, $level_data);
            }
        }

        $meta = [
            'version' => MLCM_VERSION,
            'generated' => current_time('mysql'),
            'levels' => $this->max_levels,
            'categories' => count($categories)
        ];
        $this->write_menu_file('meta', $meta);

        do_action('mlcm_cache_generated');

        return ['success' => true, 'message' => __('Menu cache generated successfully', 'multi-level-category-menu')];
    }

    /**
     * Write menu data to JavaScript file
     */
    private function write_menu_file($level, $data) {
        $js_content = 'window.mlcmData=window.mlcmData||{}; window.mlcmData[' . $level . ']=' . json_encode($data) . ';';

        $file_path = $this->cache_dir . '/level-' . $level . '.js';
        
        if (function_exists('gzcompress')) {
            $gz_path = $file_path . '.gz';
            file_put_contents($gz_path, gzcompress($js_content, 9), LOCK_EX);
        }

        return file_put_contents($file_path, $js_content, LOCK_EX);
    }

    /**
     * Build category structure
     */
    private function build_level_structure($categories, $parent_id = 0, $current_level = 1) {
        $result = [];

        foreach ($categories as $category) {
            if ($category->parent == $parent_id) {
                $item = [
                    'id' => $category->term_id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'url' => get_term_link($category->term_id, 'product_cat'),
                    'count' => $category->count
                ];

                $children = get_terms([
                    'taxonomy' => 'product_cat',
                    'hide_empty' => false,
                    'parent' => $category->term_id,
                    'number' => 500
                ]);

                if (!empty($children) && !is_wp_error($children)) {
                    $item['has_children'] = true;
                    if ($current_level < $this->max_levels) {
                        $item['children'] = $this->build_level_structure($children, $category->term_id, $current_level + 1);
                    }
                } else {
                    $item['has_children'] = false;
                    $item['children'] = [];
                }

                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * Create .htaccess for caching headers
     */
    private function create_htaccess() {
        $htaccess_content = <<<'EOT'
<FilesMatch "\\.js$">
  Header set Cache-Control "public, max-age=2592000, immutable"
  Header set Content-Type "application/javascript; charset=utf-8"
  Header set X-Content-Type-Options "nosniff"
</FilesMatch>

<FilesMatch "\\.gz$">
  Header set Content-Encoding "gzip"
  Header set Content-Type "application/javascript"
  Header set Cache-Control "public, max-age=2592000, immutable"
</FilesMatch>
EOT;

        file_put_contents($this->cache_dir . '/.htaccess', $htaccess_content, LOCK_EX);
    }

    /**
     * Invalidate cache on term update
     */
    public function invalidate_cache_on_term_update($term_id, $taxonomy) {
        if ($taxonomy === 'product_cat') {
            $this->clear_cache();
            $this->generate_menu_js();
        }
    }

    /**
     * Invalidate cache on term delete
     */
    public function invalidate_cache_on_term_delete($term_id, $taxonomy) {
        if ($taxonomy === 'product_cat') {
            $this->clear_cache();
            $this->generate_menu_js();
        }
    }

    /**
     * Invalidate cache on term create
     */
    public function invalidate_cache_on_term_create($term_id, $taxonomy) {
        if ($taxonomy === 'product_cat') {
            $this->clear_cache();
            $this->generate_menu_js();
        }
    }

    /**
     * Clear all cache files
     */
    public function clear_cache() {
        if (!is_dir($this->cache_dir)) {
            return;
        }

        $files = glob($this->cache_dir . '/{*.js,*.js.gz,*.meta}', GLOB_BRACE);
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        wp_cache_flush();
    }

    /**
     * Get all categories at specific level
     */
    private function get_all_categories_by_level($level) {
        $result = [];
        $all_cats = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'number' => 1000
        ]);

        if (is_wp_error($all_cats) || empty($all_cats)) {
            return $result;
        }

        foreach ($all_cats as $cat) {
            $ancestors = get_ancestors($cat->term_id, 'product_cat');
            if (count($ancestors) == $level - 1) {
                $children = get_terms([
                    'taxonomy' => 'product_cat',
                    'hide_empty' => false,
                    'parent' => $cat->term_id,
                    'number' => 500
                ]);

                $result[] = [
                    'id' => $cat->term_id,
                    'name' => $cat->name,
                    'slug' => $cat->slug,
                    'url' => get_term_link($cat->term_id, 'product_cat'),
                    'parent_id' => $cat->parent,
                    'has_children' => !empty($children) && !is_wp_error($children),
                    'children' => $this->format_children($children)
                ];
            }
        }

        return $result;
    }

    /**
     * Format children categories
     */
    private function format_children($children) {
        if (empty($children) || is_wp_error($children)) {
            return [];
        }

        $result = [];
        foreach ($children as $child) {
            $result[] = [
                'id' => $child->term_id,
                'name' => $child->name,
                'slug' => $child->slug,
                'url' => get_term_link($child->term_id, 'product_cat')
            ];
        }
        return $result;
    }

    /**
     * AJAX handler for cache generation
     */
    public function generate_menu_js_ajax() {
        check_ajax_referer('mlcm_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'multi-level-category-menu'));
        }

        $result = $this->generate_menu_js();

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success($result);
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('mlcm_options', 'mlcm_use_static_files');
    }

    /**
     * Add settings page
     */
    public function add_settings_page() {
        add_options_page(
            __('Category Menu Settings', 'multi-level-category-menu'),
            __('Category Menu', 'multi-level-category-menu'),
            'manage_options',
            'mlcm-settings',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Enqueue frontend styles and scripts
     */
    public function enqueue_frontend_scripts() {
        if (!class_exists('WooCommerce')) return;
        
        wp_enqueue_style(
            'mlcm-frontend',
            MLCM_PLUGIN_URL . 'assets/css/frontend.css',
            [],
            MLCM_VERSION
        );

        wp_enqueue_script(
            'mlcm-frontend',
            MLCM_PLUGIN_URL . 'assets/js/frontend.js',
            ['jquery'],
            MLCM_VERSION,
            true
        );

        wp_localize_script('mlcm-frontend', 'mlcm_config', [
            'cache_url' => WP_CONTENT_URL . '/uploads/mlcm-menu-cache/',
            'version' => MLCM_VERSION,
            'nonce' => wp_create_nonce('mlcm_nonce')
        ]);
    }

    /**
     * Output menu script to footer
     */
    public function output_menu_script() {
        if (!class_exists('WooCommerce')) return;
        ?>
        <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function() {
            var cacheUrl = '<?php echo esc_js(WP_CONTENT_URL . '/uploads/mlcm-menu-cache/'); ?>';
            
            // Load level-1 cache file
            var script = document.createElement('script');
            script.src = cacheUrl + 'level-1.js?v=<?php echo esc_js(MLCM_VERSION); ?>';
            script.async = true;
            document.body.appendChild(script);
        });
        </script>
        <?php
    }

    /**
     * Render menu shortcode
     */
    public function render_menu_shortcode($atts = []) {
        if (!class_exists('WooCommerce')) {
            return '<p>' . __('WooCommerce is required for this menu', 'multi-level-category-menu') . '</p>';
        }

        $atts = shortcode_atts([
            'style' => 'list',
            'columns' => 3,
            'show_count' => true,
            'level' => 2
        ], $atts);

        $categories = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'parent' => 0,
            'number' => 500
        ]);

        if (is_wp_error($categories) || empty($categories)) {
            return '<p>' . __('No categories found', 'multi-level-category-menu') . '</p>';
        }

        $html = '<div class="mlcm-menu mlcm-menu-' . esc_attr($atts['style']) . '">';

        if ($atts['style'] === 'grid') {
            $html .= '<div class="mlcm-grid" style="display: grid; grid-template-columns: repeat(' . intval($atts['columns']) . ', 1fr); gap: 20px;">';
        } else {
            $html .= '<ul class="mlcm-list">';
        }

        foreach ($categories as $category) {
            $count = intval($atts['show_count']) ? ' (' . $category->count . ')' : '';
            $category_url = get_term_link($category->term_id, 'product_cat');

            if ($atts['style'] === 'grid') {
                $html .= '<div class="mlcm-category">';
                $html .= '<a href="' . esc_url($category_url) . '" class="mlcm-link">' . esc_html($category->name) . $count . '</a>';
            } else {
                $html .= '<li class="mlcm-item">';
                $html .= '<a href="' . esc_url($category_url) . '" class="mlcm-link">' . esc_html($category->name) . $count . '</a>';
            }

            // Add subcategories if level > 1
            if (intval($atts['level']) > 1) {
                $children = get_terms([
                    'taxonomy' => 'product_cat',
                    'hide_empty' => false,
                    'parent' => $category->term_id,
                    'number' => 500
                ]);

                if (!empty($children) && !is_wp_error($children)) {
                    $html .= '<ul class="mlcm-children">';
                    foreach ($children as $child) {
                        $child_count = intval($atts['show_count']) ? ' (' . $child->count . ')' : '';
                        $child_url = get_term_link($child->term_id, 'product_cat');
                        $html .= '<li class="mlcm-child-item">';
                        $html .= '<a href="' . esc_url($child_url) . '" class="mlcm-child-link">' . esc_html($child->name) . $child_count . '</a>';
                        $html .= '</li>';
                    }
                    $html .= '</ul>';
                }
            }

            if ($atts['style'] === 'grid') {
                $html .= '</div>';
            } else {
                $html .= '</li>';
            }
        }

        if ($atts['style'] === 'grid') {
            $html .= '</div>';
        } else {
            $html .= '</ul>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Multi-Level Category Menu Settings', 'multi-level-category-menu'); ?></h1>
            
            <div class="notice notice-info" style="margin: 20px 0;">
                <p>
                    <strong><?php _e('v3.6.1 Update:', 'multi-level-category-menu'); ?></strong>
                    <?php _e('Menu data is now cached as JavaScript files (.js) instead of JSON. This format is cached by Cloudflare automatically (HIT status) and provides 5x better performance.', 'multi-level-category-menu'); ?>
                </p>
            </div>

            <h2><?php _e('Usage', 'multi-level-category-menu'); ?></h2>
            <p><?php _e('Add this shortcode to any page or post to display the menu:', 'multi-level-category-menu'); ?></p>
            <code>[mlcm_menu]</code>

            <h3><?php _e('Shortcode Parameters:', 'multi-level-category-menu'); ?></h3>
            <ul style="margin-left: 20px; list-style-type: disc;">
                <li><code>style="list"</code> - Menu style: list or grid (default: list)</li>
                <li><code>columns="3"</code> - Grid columns (default: 3, for grid style only)</li>
                <li><code>show_count="true"</code> - Show product count (default: true)</li>
                <li><code>level="2"</code> - Show subcategories levels (default: 2)</li>
            </ul>

            <h3><?php _e('Examples:', 'multi-level-category-menu'); ?></h3>
            <ul style="margin-left: 20px; list-style-type: disc;">
                <li><code>[mlcm_menu style="list" level="2"]</code> - List with 2 levels</li>
                <li><code>[mlcm_menu style="grid" columns="3" level="1"]</code> - 3-column grid with main categories only</li>
                <li><code>[mlcm_menu show_count="false"]</code> - List without product counts</li>
            </ul>

            <div class="mlcm-cache-info" style="background: #f5f5f5; padding: 20px; margin: 20px 0; border-radius: 5px;">
                <h3><?php _e('Cache Information', 'multi-level-category-menu'); ?></h3>
                <p>
                    <strong><?php _e('Cache Directory:', 'multi-level-category-menu'); ?></strong><br />
                    <code><?php echo esc_html(MLCM_CACHE_DIR); ?></code>
                </p>
                <p>
                    <strong><?php _e('Cache Files:', 'multi-level-category-menu'); ?></strong><br />
                    <?php
                    $cache_files = glob(MLCM_CACHE_DIR . '/level-*.js');
                    if (!empty($cache_files)) {
                        echo '<ul style="margin-left: 20px;">';
                        foreach ($cache_files as $file) {
                            $size = size_format(filesize($file));
                            echo '<li>' . esc_html(basename($file)) . ' (' . esc_html($size) . ')</li>';
                        }
                        echo '</ul>';
                    } else {
                        echo '<em>' . __('No cache files found. Generate them using the button below.', 'multi-level-category-menu') . '</em>';
                    }
                    ?>
                </p>
            </div>

            <div class="mlcm-actions">
                <button type="button" id="mlcm-generate-btn" class="button button-primary">
                    <?php _e('Generate Menu JavaScript Files', 'multi-level-category-menu'); ?>
                </button>
                <button type="button" id="mlcm-clear-btn" class="button button-secondary">
                    <?php _e('Clear Cache', 'multi-level-category-menu'); ?>
                </button>
                <span id="mlcm-status" style="margin-left: 20px;"></span>
            </div>
        </div>

        <style>
            #mlcm-generate-btn, #mlcm-clear-btn {
                padding: 10px 20px;
                font-size: 14px;
            }
            .mlcm-cache-info code {
                background: #fff;
                padding: 10px;
                display: block;
                margin: 10px 0;
                border-left: 3px solid #0073aa;
            }
            #mlcm-status {
                display: inline-block;
                padding: 5px 10px;
                border-radius: 3px;
            }
            #mlcm-status.success {
                background: #c6e1b9;
                color: #1f4620;
            }
            #mlcm-status.error {
                background: #f8d7da;
                color: #721c24;
            }
        </style>

        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('#mlcm-generate-btn').on('click', function(e) {
                    e.preventDefault();
                    var $btn = $(this);
                    var $status = $('#mlcm-status');
                    $btn.prop('disabled', true).text('<?php _e('Generating...', 'multi-level-category-menu'); ?>');
                    $status.removeClass('success error').text('<?php _e('Generating...', 'multi-level-category-menu'); ?>');

                    $.ajax({
                        type: 'POST',
                        url: ajaxurl,
                        data: {
                            action: 'mlcm_generate_json',
                            nonce: '<?php echo wp_create_nonce('mlcm_nonce'); ?>'
                        },
                        success: function(response) {
                            $btn.prop('disabled', false).text('<?php _e('Generate Menu JavaScript Files', 'multi-level-category-menu'); ?>');
                            if (response.success) {
                                $status.addClass('success').text('✓ <?php _e('Cache generated successfully!', 'multi-level-category-menu'); ?>');
                                setTimeout(function() { location.reload(); }, 2000);
                            } else {
                                $status.addClass('error').text('✗ <?php _e('Error:', 'multi-level-category-menu'); ?> ' + response.data);
                            }
                        },
                        error: function() {
                            $btn.prop('disabled', false).text('<?php _e('Generate Menu JavaScript Files', 'multi-level-category-menu'); ?>');
                            $status.addClass('error').text('✗ AJAX <?php _e('Error', 'multi-level-category-menu'); ?>');
                        }
                    });
                });

                $('#mlcm-clear-btn').on('click', function(e) {
                    e.preventDefault();
                    if (!confirm('<?php _e('Clear all cache files?', 'multi-level-category-menu'); ?>')) return;
                    var $btn = $(this);
                    var $status = $('#mlcm-status');
                    $btn.prop('disabled', true).text('<?php _e('Clearing...', 'multi-level-category-menu'); ?>');
                    $status.removeClass('success error').text('<?php _e('Clearing...', 'multi-level-category-menu'); ?>');
                    
                    $.ajax({
                        type: 'POST',
                        url: ajaxurl,
                        data: {
                            action: 'mlcm_clear_cache',
                            nonce: '<?php echo wp_create_nonce('mlcm_nonce'); ?>'
                        },
                        success: function(response) {
                            $btn.prop('disabled', false).text('<?php _e('Clear Cache', 'multi-level-category-menu'); ?>');
                            if (response.success) {
                                $status.addClass('success').text('✓ <?php _e('Cache cleared!', 'multi-level-category-menu'); ?>');
                                setTimeout(function() { location.reload(); }, 1000);
                            } else {
                                $status.addClass('error').text('✗ <?php _e('Error', 'multi-level-category-menu'); ?>');
                            }
                        },
                        error: function() {
                            $btn.prop('disabled', false).text('<?php _e('Clear Cache', 'multi-level-category-menu'); ?>');
                            $status.addClass('error').text('✗ AJAX <?php _e('Error', 'multi-level-category-menu'); ?>');
                        }
                    });
                });
            });
        </script>
        <?php
    }
}

if (class_exists('WooCommerce')) {
    $mlcm = new MLCMCacheManager();
}

// AJAX handler for cache clearing
function mlcm_clear_cache_ajax() {
    check_ajax_referer('mlcm_nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Insufficient permissions', 'multi-level-category-menu'));
    }

    $cache_dir = MLCM_CACHE_DIR;
    if (is_dir($cache_dir)) {
        $files = glob($cache_dir . '/{*.js,*.js.gz,*.meta}', GLOB_BRACE);
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    wp_cache_flush();
    wp_send_json_success(['message' => __('Cache cleared successfully', 'multi-level-category-menu')]);
}
add_action('wp_ajax_mlcm_clear_cache', 'mlcm_clear_cache_ajax');
?>