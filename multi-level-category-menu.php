<?php
/*
Plugin Name: Multi-Level Category Menu
Description: Creates customizable category menus with 5-level depth
Version: 3.6.1
Author: Name
Text Domain: mlcm
*/

defined('ABSPATH') || exit;

class Multi_Level_Category_Menu {
    
    private static $instance;
    private $options_cache = null;
    private $nonce_cache = null;
    private $cache_dir = null;
    private $cache_url = null;

    public static function get_instance() {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $uploads = wp_upload_dir();
        $this->cache_dir = $uploads['basedir'] . '/mlcm-menu-cache';
        $this->cache_url = $uploads['baseurl'] . '/mlcm-menu-cache';

        add_shortcode('mlcm_menu', [$this, 'shortcode_handler']);
        add_action('widgets_init', [$this, 'register_widget']);
        add_action('init', [$this, 'register_gutenberg_block']);
        
        // ✅ FIXED: Always load frontend assets (removed unreliable check)
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_block_editor_assets']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        
        add_action('wp_ajax_mlcm_get_subcategories', [$this, 'ajax_handler']);
        add_action('wp_ajax_nopriv_mlcm_get_subcategories', [$this, 'ajax_handler']);
        add_action('wp_ajax_mlcm_generate_menu', [$this, 'ajax_generate_menu']);
        add_action('wp_ajax_mlcm_delete_cache', [$this, 'ajax_delete_cache']);
        
        add_action('edited_category', [$this, 'invalidate_cache']);
        add_action('create_category', [$this, 'invalidate_cache']);
        add_action('delete_category', [$this, 'invalidate_cache']);
        
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    private function init_cache_dir() {
        if (!is_dir($this->cache_dir)) {
            wp_mkdir_p($this->cache_dir);
            
            $htaccess = $this->cache_dir . '/.htaccess';
            if (!file_exists($htaccess)) {
                $content = "AddType application/javascript .js\n";
                $content .= "<FilesMatch \"\.js$\">\n";
                $content .= "  Header set Cache-Control \"public, max-age=604800\"\n";
                $content .= "</FilesMatch>\n";
                $content .= "<FilesMatch \"\.js\.gz$\">\n";
                $content .= "  Header set Content-Type \"application/javascript\"\n";
                $content .= "  Header set Content-Encoding gzip\n";
                $content .= "  Header set Cache-Control \"public, max-age=604800\"\n";
                $content .= "</FilesMatch>\n";
                file_put_contents($htaccess, $content);
            }
        }
    }

    private function get_options() {
        if (null === $this->options_cache) {
            $this->options_cache = [
                'font_size' => sanitize_text_field(get_option('mlcm_font_size', '')),
                'container_gap' => absint(get_option('mlcm_container_gap', 0)),
                'button_bg_color' => sanitize_hex_color(get_option('mlcm_button_bg_color', '')),
                'button_font_size' => sanitize_text_field(get_option('mlcm_button_font_size', '')),
                'button_hover_bg_color' => sanitize_hex_color(get_option('mlcm_button_hover_bg_color', '')),
                'menu_layout' => sanitize_text_field(get_option('mlcm_menu_layout', 'vertical')),
                'initial_levels' => absint(get_option('mlcm_initial_levels', 3)),
                'menu_width' => absint(get_option('mlcm_menu_width', 250)),
                'show_button' => get_option('mlcm_show_button', '0') === '1',
                'use_category_base' => get_option('mlcm_use_category_base', '1') === '1',
                'custom_root_id' => absint(get_option('mlcm_custom_root_id', 0)),
                'excluded_cats' => sanitize_text_field(get_option('mlcm_excluded_cats', '')),
                'labels' => array_map(function($i) {
                    return sanitize_text_field(get_option("mlcm_level_{$i}_label", "Level {$i}"));
                }, range(1, 5)),
                'use_static_files' => get_option('mlcm_use_static_files', '1') === '1'
            ];
        }
        return $this->options_cache;
    }

    private function generate_inline_css($options) {
        $css = [];
        
        if (!empty($options['menu_width']) && $options['menu_width'] > 0) {
            $css[] = ".mlcm-select{width:{$options['menu_width']}px}";
        }
        
        if (!empty($options['font_size']) && is_numeric($options['font_size'])) {
            $css[] = ".mlcm-select{font-size:{$options['font_size']}rem}";
        }
        
        if (!empty($options['container_gap']) && $options['container_gap'] > 0) {
            $css[] = ".mlcm-container{gap:{$options['container_gap']}px}";
        }
        
        if (!empty($options['button_bg_color']) && preg_match('/^#[a-fA-F0-9]{6}$/', $options['button_bg_color'])) {
            $css[] = ".mlcm-go-button{background:{$options['button_bg_color']}}";
        }
        
        if (!empty($options['button_font_size']) && is_numeric($options['button_font_size'])) {
            $css[] = ".mlcm-go-button{font-size:{$options['button_font_size']}rem}";
        }
        
        if (!empty($options['button_hover_bg_color']) && preg_match('/^#[a-fA-F0-9]{6}$/', $options['button_hover_bg_color'])) {
            $css[] = ".mlcm-go-button:hover{background:{$options['button_hover_bg_color']}}";
        }
        
        return implode('', $css);
    }

    public function register_gutenberg_block() {
        $block_editor_js_path = plugin_dir_path(__FILE__) . 'assets/js/block-editor.js';
        $block_editor_version = file_exists($block_editor_js_path) 
            ? filemtime($block_editor_js_path) 
            : '3.6.1';
        
        wp_register_script(
            'mlcm-block-editor',
            plugins_url('assets/js/block-editor.js', __FILE__),
            ['wp-blocks', 'wp-i18n', 'wp-element', 'wp-components', 'wp-editor'],
            $block_editor_version
        );
        
        register_block_type('mlcm/menu-block', [
            'editor_script' => 'mlcm-block-editor',
            'render_callback' => [$this, 'render_gutenberg_block'],
            'attributes' => [
                'layout' => ['type' => 'string', 'default' => 'vertical'],
                'levels' => ['type' => 'number', 'default' => 3]
            ]
        ]);
    }

    public function render_gutenberg_block($attributes) {
        $atts = shortcode_atts(['layout' => 'vertical', 'levels' => 3], $attributes);
        return $this->generate_menu_html($atts);
    }

    public function shortcode_handler($atts) {
        $options = $this->get_options();
        $atts = shortcode_atts(['layout' => $options['menu_layout'], 'levels' => $options['initial_levels']], $atts);
        return $this->generate_menu_html($atts);
    }

    private function get_categories_data($parent_id = 0) {
        $options = $this->get_options();
        $excluded_str = $options['excluded_cats'];
        $excluded = [];
        
        if (!empty($excluded_str)) {
            $excluded = array_filter(array_map('absint', array_map('trim', explode(',', $excluded_str))));
        }
        
        $categories = get_categories([
            'parent' => $parent_id,
            'exclude' => $excluded,
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
            'fields' => 'all'
        ]);
        
        $result = [];
        foreach ($categories as $category) {
            if (!isset($category->term_id) || !isset($category->name)) {
                continue;
            }
            
            $children_count = count(get_term_children($category->term_id, 'category'));
            
            $result[$category->term_id] = [
                'id' => $category->term_id,
                'name' => strtoupper(htmlspecialchars_decode($category->name)),
                'slug' => isset($category->slug) ? $category->slug : '',
                'url' => get_category_link($category->term_id),
                'hasChildren' => $children_count > 0
            ];
        }
        
        $this->sort_categories($result);
        return $result;
    }

    private function sort_categories(&$categories) {
        if (!is_array($categories) || empty($categories)) {
            return;
        }
        
        uasort($categories, function($a, $b) {
            $name_a = isset($a['name']) ? trim((string)$a['name']) : '';
            $name_b = isset($b['name']) ? trim((string)$b['name']) : '';
            
            if (empty($name_a) && empty($name_b)) return 0;
            if (empty($name_a)) return 1;
            if (empty($name_b)) return -1;
            
            return strcasecmp($name_a, $name_b);
        });
    }

    public function generate_static_menus() {
        $this->init_cache_dir();
        $options = $this->get_options();
        $custom_root_id = $options['custom_root_id'] > 0 ? $options['custom_root_id'] : 0;
        
        try {
            $level_1_data = $this->get_categories_data($custom_root_id);
            $level_1_array = array_values($level_1_data);
            $this->write_js_file('level-1.js', $level_1_array);
            
            $current_level_parents = array_keys($level_1_data);
            
            for ($level = 2; $level <= 5; $level++) {
                $level_data = [];
                $next_level_parents = [];
                
                foreach ($current_level_parents as $parent_id) {
                    $subcats = $this->get_categories_data($parent_id);
                    if (!empty($subcats)) {
                        $level_data[$parent_id] = array_values($subcats);
                        $next_level_parents = array_merge($next_level_parents, array_keys($subcats));
                    }
                }
                
                if (!empty($level_data)) {
                    $this->write_js_file("level-{$level}.js", $level_data);
                    $current_level_parents = array_unique($next_level_parents);
                } else {
                    break;
                }
            }
            
            $meta = [
                'generated_at' => current_time('mysql'),
                'generated_timestamp' => time(),
                'custom_root_id' => $custom_root_id,
                'levels_count' => $level
            ];
            $this->write_js_file('meta.js', $meta);
            
            return ['success' => true, 'message' => 'Menu generated successfully', 'levels' => $level - 1];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function write_js_file($filename, $data) {
        $filepath = $this->cache_dir . '/' . $filename;
        
        $var_name = 'mlcmData';
        if (preg_match('/level-(\d+)\.js$/', $filename, $matches)) {
            $var_name = 'mlcmLevel' . $matches[1];
        } elseif (preg_match('/meta\.js$/', $filename)) {
            $var_name = 'mlcmMeta';
        }
        
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('JSON encoding error: ' . json_last_error_msg());
        }
        
        $js_content = "window.{$var_name} = {$json};";
        
        if (file_put_contents($filepath, $js_content) === false) {
            throw new Exception("Failed to write file: {$filepath}");
        }
        
        if (extension_loaded('zlib')) {
            $gz_filepath = $filepath . '.gz';
            $gz_data = gzencode($js_content, 9);
            if ($gz_data !== false) {
                file_put_contents($gz_filepath, $gz_data);
            }
        }
    }

    private function get_level_1_data() {
        $cache_file = $this->cache_dir . '/level-1.js';
        
        if (file_exists($cache_file)) {
            $content = file_get_contents($cache_file);
            if ($content !== false) {
                if (preg_match('/window\.mlcmLevel1\s*=\s*(.+?);?\s*$/s', $content, $matches)) {
                    $json_data = trim($matches[1]);
                    $decoded = json_decode($json_data, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        return $decoded;
                    }
                }
            }
        }
        
        return array_values($this->get_categories_data(
            $this->get_options()['custom_root_id'] > 0 ? $this->get_options()['custom_root_id'] : 0
        ));
    }

    private function get_static_js_url($level, $parent_id = null) {
        if ($level === 1) {
            return $this->cache_url . '/level-1.js';
        }
        
        $cache_file = $this->cache_dir . "/level-{$level}.js";
        if (file_exists($cache_file)) {
            return $this->cache_url . "/level-{$level}.js?v=" . filemtime($cache_file);
        }
        
        return null;
    }

    private function get_file_versions() {
        $versions = [];
        
        for ($level = 1; $level <= 5; $level++) {
            $cache_file = $this->cache_dir . "/level-{$level}.js";
            if (file_exists($cache_file)) {
                $versions[$level] = filemtime($cache_file);
            } else {
                $versions[$level] = 0;
            }
        }
        
        return $versions;
    }

    public function invalidate_cache() {
        $files = glob($this->cache_dir . '/level-*.js*');
        foreach ($files as $file) {
            @unlink($file);
        }
        @unlink($this->cache_dir . '/meta.js');
        @unlink($this->cache_dir . '/meta.js.gz');
    }

    public function ajax_generate_menu() {
        check_ajax_referer('mlcm_admin_nonce', 'security');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
            wp_die();
        }
        
        $result = $this->generate_static_menus();
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
        
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
        header('Cache-Control: no-cache, no-store, must-revalidate');
        
        $parent_id = absint($_POST['parent_id'] ?? 0);
        
        if ($parent_id < 0) {
            wp_send_json_error(['message' => 'Invalid parent ID']);
            wp_die();
        }
        
        $categories = $this->get_categories_data($parent_id);
        $response = array_values($categories);
        
        wp_send_json_success($response);
    }

    private function generate_menu_html($atts) {
        $options = $this->get_options();
        $level_1_data = $this->get_level_1_data();
        
        ob_start();
        ?>
        <div class="mlcm-container <?php echo esc_attr($atts['layout']); ?>"
             data-levels="<?php echo absint($atts['levels']); ?>"
             data-use-static="<?php echo $options['use_static_files'] ? '1' : '0'; ?>"
             data-static-url="<?php echo esc_url($this->cache_url); ?>">
            <?php for($i = 1; $i <= $atts['levels']; $i++): ?>
                <div class="mlcm-level" data-level="<?php echo $i; ?>">
                    <?php $this->render_select($i, $level_1_data); ?>
                </div>
            <?php endfor; ?>
            
            <?php if ($options['show_button']): ?>
                <button type="button" class="mlcm-go-button <?php echo esc_attr($atts['layout']); ?>">
                    <?php esc_html_e('Go', 'mlcm'); ?>
                </button>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_select($level, $level_1_data = []) {
        $options = $this->get_options();
        $label = $options['labels'][$level - 1];
        $select_id = "mlcm-select-level-{$level}";
        $label_id = "mlcm-label-level-{$level}";
        
        $categories = ($level === 1) ? $level_1_data : [];
        ?>
        <label for="<?= esc_attr($select_id) ?>" id="<?= esc_attr($label_id) ?>" class="mlcm-screen-reader-text">
            <?= esc_html($label) ?>
        </label>
        <select id="<?= esc_attr($select_id) ?>" class="mlcm-select" data-level="<?= $level ?>"
                aria-labelledby="<?= esc_attr($label_id) ?>"
                <?= $level > 1 ? 'disabled' : '' ?>>
            <option value="-1"><?= esc_html($label) ?></option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= absint($cat['id']) ?>"
                        data-slug="<?= esc_attr($cat['slug'] ?? '') ?>"
                        data-url="<?= esc_url($cat['url'] ?? '') ?>">
                    <?= esc_html($cat['name'] ?? '') ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public function register_settings() {
        register_setting('mlcm_options', 'mlcm_font_size', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('mlcm_options', 'mlcm_container_gap', ['sanitize_callback' => 'absint']);
        register_setting('mlcm_options', 'mlcm_button_bg_color', ['sanitize_callback' => 'sanitize_hex_color']);
        register_setting('mlcm_options', 'mlcm_button_font_size', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('mlcm_options', 'mlcm_button_hover_bg_color', ['sanitize_callback' => 'sanitize_hex_color']);
        register_setting('mlcm_options', 'mlcm_menu_layout', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('mlcm_options', 'mlcm_initial_levels', ['sanitize_callback' => 'absint']);
        register_setting('mlcm_options', 'mlcm_excluded_cats', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('mlcm_options', 'mlcm_menu_width', ['sanitize_callback' => 'absint']);
        register_setting('mlcm_options', 'mlcm_show_button', ['sanitize_callback' => 'rest_sanitize_boolean']);
        register_setting('mlcm_options', 'mlcm_use_category_base', ['sanitize_callback' => 'rest_sanitize_boolean']);
        register_setting('mlcm_options', 'mlcm_custom_root_id', ['sanitize_callback' => 'absint']);
        register_setting('mlcm_options', 'mlcm_use_static_files', ['sanitize_callback' => 'rest_sanitize_boolean']);
        
        for ($i = 1; $i <= 5; $i++) {
            register_setting('mlcm_options', "mlcm_level_{$i}_label", ['sanitize_callback' => 'sanitize_text_field']);
        }
        
        add_settings_section('mlcm_main', 'Main Settings', null, 'mlcm_options');
        $options = $this->get_options();
        
        add_settings_field('mlcm_font_size', 'Font Size for Menu Items (rem)', function() use ($options) {
            echo '<input type="number" step="0.1" min="0.5" max="5" name="mlcm_font_size" value="'.esc_attr($options['font_size']).'">';
        }, 'mlcm_options', 'mlcm_main');
        
        add_settings_field('mlcm_container_gap', 'Gap Between Menu Items (px)', function() use ($options) {
            echo '<input type="number" min="0" step="1" name="mlcm_container_gap" value="'.esc_attr($options['container_gap']).'">';
        }, 'mlcm_options', 'mlcm_main');
        
        add_settings_field('mlcm_button_bg_color', 'Button Background Color', function() use ($options) {
            echo '<input type="color" name="mlcm_button_bg_color" value="'.esc_attr($options['button_bg_color']).'">';
        }, 'mlcm_options', 'mlcm_main');
        
        add_settings_field('mlcm_button_hover_bg_color', 'Button Hover Background Color', function() use ($options) {
            echo '<input type="color" name="mlcm_button_hover_bg_color" value="'.esc_attr($options['button_hover_bg_color']).'">';
        }, 'mlcm_options', 'mlcm_main');
        
        add_settings_field('mlcm_button_font_size', 'Button Font Size (rem)', function() use ($options) {
            echo '<input type="number" step="0.1" min="0.5" max="5" name="mlcm_button_font_size" value="'.esc_attr($options['button_font_size']).'">';
        }, 'mlcm_options', 'mlcm_main');
        
        add_settings_field('mlcm_custom_root_id', 'Custom Root Category ID', function() use ($options) {
            echo '<input type="text" name="mlcm_custom_root_id" value="' . esc_attr($options['custom_root_id']) . '">';
            echo '<p class="description">Specify the ID of the category whose subcategories will be used as the first level of the menu. Leave blank to use root categories.</p>';
        }, 'mlcm_options', 'mlcm_main');
        
        add_settings_field('mlcm_layout', 'Menu Layout', function() use ($options) {
            $layout = $options['menu_layout'];
            echo '<select name="mlcm_menu_layout">
                <option value="vertical" '.selected($layout, 'vertical', false).'>Vertical</option>
                <option value="horizontal" '.selected($layout, 'horizontal', false).'>Horizontal</option>
            </select>';
        }, 'mlcm_options', 'mlcm_main');
        
        add_settings_field('mlcm_levels', 'Initial Levels', function() use ($options) {
            echo '<input type="number" min="1" max="5" name="mlcm_initial_levels" value="'.esc_attr($options['initial_levels']).'">';
        }, 'mlcm_options', 'mlcm_main');
        
        add_settings_field('mlcm_width', 'Menu Width (px)', function() use ($options) {
            echo '<input type="number" min="100" step="10" name="mlcm_menu_width" value="'.esc_attr($options['menu_width']).'">';
        }, 'mlcm_options', 'mlcm_main');
        
        add_settings_field('mlcm_show_button', 'Show Go Button', function() use ($options) {
            $show = $options['show_button'] ? '1' : '0';
            echo '<label><input type="checkbox" name="mlcm_show_button" value="1" '.checked($show, '1', false).'> '.__('Enable Go button', 'mlcm').'</label>';
        }, 'mlcm_options', 'mlcm_main');
        
        add_settings_field('mlcm_use_category_base', 'Use Category Base', function() use ($options) {
            $use_base = $options['use_category_base'] ? '1' : '0';
            echo '<label><input type="checkbox" name="mlcm_use_category_base" value="1" '.checked($use_base, '1', false).'> '.__('Include "category" in URL', 'mlcm').'</label>';
        }, 'mlcm_options', 'mlcm_main');
        
        add_settings_field('mlcm_use_static', 'Use Static JavaScript Files', function() use ($options) {
            $use_static = $options['use_static_files'] ? '1' : '0';
            echo '<label><input type="checkbox" name="mlcm_use_static_files" value="1" '.checked($use_static, '1', false).'> '.__('Enable static file generation for better performance', 'mlcm').'</label>';
            echo '<p class="description">When enabled, category data is stored in static JavaScript files instead of database queries. Click "Generate Menu" to create files.</p>';
        }, 'mlcm_options', 'mlcm_main');
        
        add_settings_field('mlcm_exclude', 'Excluded Categories', function() use ($options) {
            echo '<input type="text" name="mlcm_excluded_cats" placeholder="Comma-separated IDs" value="'.esc_attr($options['excluded_cats']).'">';
        }, 'mlcm_options', 'mlcm_main');
        
        for ($i = 1; $i <= 5; $i++) {
            add_settings_field("mlcm_label_{$i}", "Level {$i} Label", function() use ($i, $options) {
                $label = $options['labels'][$i - 1];
                echo '<input type="text" name="mlcm_level_'.$i.'_label" value="'.esc_attr($label).'">';
            }, 'mlcm_options', 'mlcm_main');
        }
        
        add_settings_field('mlcm_generation', 'Menu Generation', function() {
            echo '<button type="button" class="button button-primary" id="mlcm-generate-menu">'.__('Generate Menu Files', 'mlcm').'</button>
                <span class="spinner" style="float:none; margin-left:10px"></span>
                <button type="button" class="button button-secondary" id="mlcm-delete-cache" style="margin-left:10px;">'.__('Delete Cache Files', 'mlcm').'</button>
                <span class="spinner" style="float:none; margin-left:10px"></span>
                <div id="mlcm-generation-status" style="margin-top:10px;"></div>';
        }, 'mlcm_options', 'mlcm_main');
        
        add_action('update_option', [$this, 'maybe_clear_options_cache'], 10, 2);
    }

    public function maybe_clear_options_cache($option_name, $old_value) {
        if (strpos($option_name, 'mlcm_') === 0) {
            $this->options_cache = null;
        }
    }

    /**
     * ✅ FIXED: Always load frontend assets
     * REMOVED: has_menu_on_page() check (was unreliable and buggy)
     */
    public function enqueue_frontend_assets() {
        // ✅ REMOVED THIS BUGGY CHECK:
        // if (!$this->has_menu_on_page()) { return; }
        
        $options = $this->get_options();
        
        // Get plugin directory URL and path
        $plugin_dir_url = plugin_dir_url(__FILE__);
        $plugin_dir_path = plugin_dir_path(__FILE__);
        
        // CSS - check for minified version first
        $frontend_css_min = $plugin_dir_path . 'assets/css/frontend.min.css';
        $frontend_css_regular = $plugin_dir_path . 'assets/css/frontend.css';
        
        if (file_exists($frontend_css_min)) {
            $css_file = $plugin_dir_url . 'assets/css/frontend.min.css';
            $css_version = filemtime($frontend_css_min);
        } elseif (file_exists($frontend_css_regular)) {
            $css_file = $plugin_dir_url . 'assets/css/frontend.css';
            $css_version = filemtime($frontend_css_regular);
        } else {
            $css_file = false;
            $css_version = '3.6.1';
        }
        
        if ($css_file) {
            wp_enqueue_style(
                'mlcm-frontend',
                $css_file,
                [],
                $css_version
            );
        }
        
        // JS - check for minified version first
        $frontend_js_min = $plugin_dir_path . 'assets/js/frontend.min.js';
        $frontend_js_regular = $plugin_dir_path . 'assets/js/frontend.js';
        
        if (file_exists($frontend_js_min)) {
            $js_file = $plugin_dir_url . 'assets/js/frontend.min.js';
            $js_version = filemtime($frontend_js_min);
        } elseif (file_exists($frontend_js_regular)) {
            $js_file = $plugin_dir_url . 'assets/js/frontend.js';
            $js_version = filemtime($frontend_js_regular);
        } else {
            $js_file = false;
            $js_version = '3.6.1';
        }
        
        if ($js_file) {
            wp_enqueue_script(
                'mlcm-frontend',
                $js_file,
                ['jquery'],
                $js_version,
                true
            );
            
            $file_versions = $this->get_file_versions();
            
            wp_localize_script('mlcm-frontend', 'mlcmVars', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'labels' => $options['labels'],
                'use_category_base' => $options['use_category_base'],
                'use_static' => $options['use_static_files'],
                'static_url' => esc_url($this->cache_url),
                'file_versions' => $file_versions,
            ]);
        }
        
        // Add inline CSS from settings
        $custom_css = $this->generate_inline_css($options);
        if (!empty($custom_css) && $css_file) {
            wp_add_inline_style('mlcm-frontend', $custom_css);
        }
    }

    /**
     * ✅ REMOVED: has_menu_on_page() method
     * This method was buggy and unreliable
     */

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
            <h1><?php esc_html_e('Category Menu Settings', 'mlcm') ?></h1>
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
        $options = $this->get_options();
        $plugin_dir_url = plugin_dir_url(__FILE__);
        $plugin_dir_path = plugin_dir_path(__FILE__);
        
        // JS
        $block_editor_js_min = $plugin_dir_path . 'assets/js/block-editor.min.js';
        $block_editor_js_regular = $plugin_dir_path . 'assets/js/block-editor.js';
        
        if (file_exists($block_editor_js_min)) {
            $js_file = $plugin_dir_url . 'assets/js/block-editor.min.js';
            $js_version = filemtime($block_editor_js_min);
        } elseif (file_exists($block_editor_js_regular)) {
            $js_file = $plugin_dir_url . 'assets/js/block-editor.js';
            $js_version = filemtime($block_editor_js_regular);
        } else {
            $js_file = $plugin_dir_url . 'assets/js/block-editor.js';
            $js_version = '3.6.1';
        }
        
        wp_enqueue_script(
            'mlcm-block-editor',
            $js_file,
            ['wp-blocks', 'wp-i18n', 'wp-element', 'wp-components', 'wp-editor'],
            $js_version
        );
        
        // CSS
        $block_editor_css_min = $plugin_dir_path . 'assets/css/block-editor.min.css';
        $block_editor_css_regular = $plugin_dir_path . 'assets/css/block-editor.css';
        
        if (file_exists($block_editor_css_min)) {
            $css_file = $plugin_dir_url . 'assets/css/block-editor.min.css';
            $css_version = filemtime($block_editor_css_min);
        } elseif (file_exists($block_editor_css_regular)) {
            $css_file = $plugin_dir_url . 'assets/css/block-editor.css';
            $css_version = filemtime($block_editor_css_regular);
        } else {
            $css_file = $plugin_dir_url . 'assets/css/block-editor.css';
            $css_version = '3.6.1';
        }
        
        wp_enqueue_style(
            'mlcm-block-editor',
            $css_file,
            [],
            $css_version
        );
        
        wp_localize_script('mlcm-block-editor', 'mlcmBlockVars', [
            'default_layout' => $options['menu_layout'],
            'default_levels' => $options['initial_levels']
        ]);
    }

    public function enqueue_admin_assets($hook) {
        if ($hook === 'settings_page_mlcm-settings') {
            $plugin_dir_url = plugin_dir_url(__FILE__);
            $plugin_dir_path = plugin_dir_path(__FILE__);
            
            // CSS
            $admin_css_min = $plugin_dir_path . 'assets/css/admin.min.css';
            $admin_css_regular = $plugin_dir_path . 'assets/css/admin.css';
            
            if (file_exists($admin_css_min)) {
                $css_file = $plugin_dir_url . 'assets/css/admin.min.css';
                $css_version = filemtime($admin_css_min);
            } elseif (file_exists($admin_css_regular)) {
                $css_file = $plugin_dir_url . 'assets/css/admin.css';
                $css_version = filemtime($admin_css_regular);
            } else {
                $css_file = $plugin_dir_url . 'assets/css/admin.css';
                $css_version = '3.6.1';
            }
            
            wp_enqueue_style(
                'mlcm-admin',
                $css_file,
                [],
                $css_version
            );
            
            // JS
            $admin_js_min = $plugin_dir_path . 'assets/js/admin.min.js';
            $admin_js_regular = $plugin_dir_path . 'assets/js/admin.js';
            
            if (file_exists($admin_js_min)) {
                $js_file = $plugin_dir_url . 'assets/js/admin.min.js';
                $js_version = filemtime($admin_js_min);
            } elseif (file_exists($admin_js_regular)) {
                $js_file = $plugin_dir_url . 'assets/js/admin.js';
                $js_version = filemtime($admin_js_regular);
            } else {
                $js_file = $plugin_dir_url . 'assets/js/admin.js';
                $js_version = '3.6.1';
            }
            
            wp_enqueue_script(
                'mlcm-admin',
                $js_file,
                ['jquery'],
                $js_version,
                true
            );
            
            wp_localize_script('mlcm-admin', 'mlcmAdmin', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('mlcm_admin_nonce'),
                'i18n' => [
                    'generating' => __('Generating menu...', 'mlcm'),
                    'menu_generated' => __('Menu generated successfully', 'mlcm'),
                    'error' => __('Error generating menu', 'mlcm'),
                    'deleting' => __('Deleting cache files...', 'mlcm'),
                    'cache_deleted' => __('Cache files deleted successfully', 'mlcm'),
                    'delete_error' => __('Error deleting cache files', 'mlcm'),
                    'confirm_delete' => __('Are you sure you want to delete all cache files?', 'mlcm')
                ]
            ]);
        }
    }
}

Multi_Level_Category_Menu::get_instance();