<?php
/*
Plugin Name: Multi-Level Category Menu
Description: Creates customizable category menus with 5-level depth
Version: 3.4
Author: Name
Text Domain: mlcm
*/

defined('ABSPATH') || exit;

class Multi_Level_Category_Menu {
    private static $instance;

    public static function get_instance() {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_shortcode('mlcm_menu', [$this, 'shortcode_handler']);
        add_action('widgets_init', [$this, 'register_widget']);
        add_action('init', [$this, 'register_gutenberg_block']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_block_editor_assets']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('wp_ajax_mlcm_get_subcategories', [$this, 'ajax_handler']);
        add_action('wp_ajax_nopriv_mlcm_get_subcategories', [$this, 'ajax_handler']);
        add_action('edited_category', [$this, 'clear_related_cache']);
        add_action('create_category', [$this, 'clear_related_cache']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    public function register_gutenberg_block() {
        wp_register_script(
            'mlcm-block-editor',
            plugins_url('assets/js/block-editor.js', __FILE__),
            ['wp-blocks', 'wp-i18n', 'wp-element', 'wp-components', 'wp-editor'],
            filemtime(plugin_dir_path(__FILE__) . 'assets/js/block-editor.js')
        );

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
                ]
            ]
        ]);
    }

    public function render_gutenberg_block($attributes) {
        $atts = shortcode_atts([
            'layout' => 'vertical',
            'levels' => 3
        ], $attributes);

        return $this->generate_menu_html($atts);
    }

    public function shortcode_handler($atts) {
        $atts = shortcode_atts([
            'layout' => get_option('mlcm_menu_layout', 'vertical'),
            'levels' => absint(get_option('mlcm_initial_levels', 3))
        ], $atts);

        return $this->generate_menu_html($atts);
    }

    private function generate_menu_html($atts) {
        $show_button = get_option('mlcm_show_button', '0') === '1';
        ob_start(); ?>
        <div class="mlcm-container <?php echo esc_attr($atts['layout']); ?>" 
             data-levels="<?php echo absint($atts['levels']); ?>">
            <?php for($i = 1; $i <= $atts['levels']; $i++): ?>
                <div class="mlcm-level" data-level="<?php echo $i; ?>">
                    <?php $this->render_select($i); ?>
                </div>
            <?php endfor; ?>
            <?php if ($show_button): ?>
                <button type="button" class="mlcm-go-button <?php echo esc_attr($atts['layout']); ?>">
                    <?php esc_html_e('Go', 'mlcm'); ?>
                </button>
            <?php endif; ?>
        </div>
        <?php return ob_get_clean();
    }

    private function render_select($level) {
        $label = get_option("mlcm_level_{$level}_label", "Level {$level}");
        $categories = ($level === 1) ? $this->get_root_categories() : [];
        $select_id = "mlcm-select-level-{$level}";
        ?>
        <select id="<?= esc_attr($select_id) ?>" class="mlcm-select" data-level="<?= $level ?>" 
                <?= $level > 1 ? 'disabled' : '' ?>>
            <option value="-1"><?= esc_html($label) ?></option>
            <?php foreach ($categories as $id => $data): 
                $cat_link = get_category_link($id);
            ?>
                <option value="<?= absint($id) ?>" data-slug="<?= esc_attr($data['slug']) ?>" data-url="<?= esc_url($cat_link) ?>">
                    <?= esc_html($data['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    private function get_root_categories() {
        $custom_root_id = get_option('mlcm_custom_root_id', '');
        if (!empty($custom_root_id) && is_numeric($custom_root_id)) {
            $cache_key = 'mlcm_subcats_' . absint($custom_root_id);
            $cache = get_transient($cache_key);
            if (false === $cache) {
                $excluded = array_map('absint', explode(',', get_option('mlcm_excluded_cats', '')));
                $categories = get_categories([
                    'parent' => absint($custom_root_id),
                    'exclude' => $excluded,
                    'hide_empty' => false,
                    'orderby' => 'name',
                    'order' => 'ASC',
                    'fields' => 'all'
                ]);

                usort($categories, function($a, $b) {
                    return strcasecmp($a->name, $b->name);
                });

                $cache = [];
                foreach ($categories as $category) {
                    $cache[$category->term_id] = [
                        'name' => strtoupper(htmlspecialchars_decode($category->name)),
                        'slug' => $category->slug
                    ];
                }
                set_transient($cache_key, $cache, WEEK_IN_SECONDS);
            }
            return $cache;
        } else {
            $cache_key = 'mlcm_root_cats';
            $cache = get_transient($cache_key);
            if (false === $cache) {
                $excluded = array_map('absint', explode(',', get_option('mlcm_excluded_cats', '')));
                $categories = get_categories([
                    'parent' => 0,
                    'exclude' => $excluded,
                    'hide_empty' => false,
                    'orderby' => 'name',
                    'order' => 'ASC',
                    'fields' => 'all'
                ]);

                usort($categories, function($a, $b) {
                    return strcasecmp($a->name, $b->name);
                });

                $cache = [];
                foreach ($categories as $category) {
                    $cache[$category->term_id] = [
                        'name' => strtoupper(htmlspecialchars_decode($category->name)),
                        'slug' => $category->slug
                    ];
                }
                set_transient($cache_key, $cache, WEEK_IN_SECONDS);
            }
            return $cache;
        }
    }

    public function ajax_handler() {
        check_ajax_referer('mlcm_nonce', 'security');
        
        $parent_id = absint($_POST['parent_id'] ?? 0);
        $cache_key = "mlcm_subcats_{$parent_id}";
        
        if (false === ($response = get_transient($cache_key))) {
            $categories = get_categories([
                'parent' => $parent_id,
                'hide_empty' => false,
                'orderby' => 'name',
                'order' => 'ASC',
                'fields' => 'all'
            ]);
            
            $response = [];
            foreach ($categories as $category) {
                $response[$category->term_id] = [
                    'name' => strtoupper(htmlspecialchars_decode($category->name)),
                    'slug' => $category->slug,
                    'url' => get_category_link($category->term_id)
                ];
            }
            set_transient($cache_key, $response, WEEK_IN_SECONDS);
        }
        
        wp_send_json_success($response);
    }

    public function register_settings() {
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

        for ($i = 1; $i <= 5; $i++) {
            register_setting('mlcm_options', "mlcm_level_{$i}_label");
        }

        add_settings_section('mlcm_main', 'Main Settings', null, 'mlcm_options');

        add_settings_field('mlcm_font_size', 'Font Size for Menu Items (rem)', function() {
            $font_size = get_option('mlcm_font_size', '');
            echo '<input type="number" step="0.1" min="0.5" max="5" name="mlcm_font_size" value="'.esc_attr($font_size).'">';
        }, 'mlcm_options', 'mlcm_main');

        add_settings_field('mlcm_container_gap', 'Gap Between Menu Items (px)', function() {
            $gap = get_option('mlcm_container_gap', '');
            echo '<input type="number" min="0" step="1" name="mlcm_container_gap" value="'.esc_attr($gap).'">';
        }, 'mlcm_options', 'mlcm_main');

        add_settings_field('mlcm_button_bg_color', 'Button Background Color', function() {
            $bg_color = get_option('mlcm_button_bg_color', '');
            echo '<input type="color" name="mlcm_button_bg_color" value="'.esc_attr($bg_color).'">';
        }, 'mlcm_options', 'mlcm_main');

        add_settings_field('mlcm_button_hover_bg_color', 'Button Hover Background Color', function() {
            $hover_bg_color = get_option('mlcm_button_hover_bg_color', '');
            echo '<input type="color" name="mlcm_button_hover_bg_color" value="'.esc_attr($hover_bg_color).'">';
        }, 'mlcm_options', 'mlcm_main');

        add_settings_field('mlcm_button_font_size', 'Button Font Size (rem)', function() {
            $font_size = get_option('mlcm_button_font_size', '');
            echo '<input type="number" step="0.1" min="0.5" max="5" name="mlcm_button_font_size" value="'.esc_attr($font_size).'">';
        }, 'mlcm_options', 'mlcm_main');

        add_settings_field('mlcm_custom_root_id', 'Custom Root Category ID', function() {
            $custom_root_id = get_option('mlcm_custom_root_id', '');
            echo '<input type="text" name="mlcm_custom_root_id" value="' . esc_attr($custom_root_id) . '">';
            echo '<p class="description">Specify the ID of the category whose subcategories will be used as the first level of the menu. Leave blank to use root categories.</p>';
        }, 'mlcm_options', 'mlcm_main');

        add_settings_field('mlcm_layout', 'Menu Layout', function() {
            $layout = get_option('mlcm_menu_layout', 'vertical');
            echo '<select name="mlcm_menu_layout">
                <option value="vertical" '.selected($layout, 'vertical', false).'>Vertical</option>
                <option value="horizontal" '.selected($layout, 'horizontal', false).'>Horizontal</option>
            </select>';
        }, 'mlcm_options', 'mlcm_main');

        add_settings_field('mlcm_levels', 'Initial Levels', function() {
            $levels = get_option('mlcm_initial_levels', 3);
            echo '<input type="number" min="1" max="5" name="mlcm_initial_levels" value="'.absint($levels).'">';
        }, 'mlcm_options', 'mlcm_main');

        add_settings_field('mlcm_width', 'Menu Width (px)', function() {
            $width = get_option('mlcm_menu_width', 250);
            echo '<input type="number" min="100" step="10" name="mlcm_menu_width" value="'.absint($width).'">';
        }, 'mlcm_options', 'mlcm_main');

        add_settings_field('mlcm_show_button', 'Show Go Button', function() {
            $show = get_option('mlcm_show_button', '0');
            echo '<label><input type="checkbox" name="mlcm_show_button" value="1" '.checked($show, '1', false).'> '.__('Enable Go button', 'mlcm').'</label>';
        }, 'mlcm_options', 'mlcm_main');

        add_settings_field('mlcm_use_category_base', 'Use Category Base', function() {
            $use_base = get_option('mlcm_use_category_base', '1');
            echo '<label><input type="checkbox" name="mlcm_use_category_base" value="1" '.checked($use_base, '1', false).'> '.__('Include "category" in URL', 'mlcm').'</label>';
        }, 'mlcm_options', 'mlcm_main');

        add_settings_field('mlcm_exclude', 'Excluded Categories', function() {
            $excluded = get_option('mlcm_excluded_cats', '');
            echo '<input type="text" name="mlcm_excluded_cats" 
                placeholder="Comma-separated IDs" value="'.esc_attr($excluded).'">';
        }, 'mlcm_options', 'mlcm_main');

        for ($i = 1; $i <= 5; $i++) {
            add_settings_field(
                "mlcm_label_{$i}", 
                "Level {$i} Label",
                function() use ($i) {
                    $label = get_option("mlcm_level_{$i}_label", "Level {$i}");
                    echo '<input type="text" name="mlcm_level_'.$i.'_label" 
                        value="'.esc_attr($label).'">';
                },
                'mlcm_options', 
                'mlcm_main'
            );
        }

        add_settings_field('mlcm_cache', 'Cache Management', function() {
            echo '<button type="button" class="button" id="mlcm-clear-cache">
                '.__('Clear All Caches', 'mlcm').'</button>
                <span class="spinner" style="float:none; margin-left:10px"></span>';
        }, 'mlcm_options', 'mlcm_main');
    }

    public function enqueue_frontend_assets() {
        wp_enqueue_style(
            'mlcm-frontend', 
            plugins_url('assets/css/frontend.css', __FILE__)
        );
        
        wp_enqueue_script(
            'mlcm-frontend', 
            plugins_url('assets/js/frontend.js', __FILE__), 
            ['jquery'], 
            null, 
            true
        );
        
        wp_localize_script('mlcm-frontend', 'mlcmVars', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mlcm_nonce'),
            'labels' => array_map(function($i) {
                return get_option("mlcm_level_{$i}_label", "Level {$i}");
            }, range(1,5)),
            'use_category_base' => get_option('mlcm_use_category_base', '1') === '1',
        ]);

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

    public function clear_related_cache($term_id) {
        $term = get_term($term_id);
        if ($term) {
            delete_transient("mlcm_subcats_{$term->term_id}");
            if ($term->parent != 0) {
                delete_transient("mlcm_subcats_{$term->parent}");
            } else {
                $custom_root_id = get_option('mlcm_custom_root_id', '');
                if (empty($custom_root_id)) {
                    delete_transient('mlcm_root_cats');
                }
            }
        }
    }

    public function clear_all_caches() {
        global $wpdb;
        delete_transient('mlcm_root_cats');
        $result = $wpdb->query(
            "DELETE FROM $wpdb->options 
            WHERE option_name LIKE '_transient_mlcm_subcats_%' 
            OR option_name LIKE '_transient_timeout_mlcm_subcats_%'"
        );
        return $result !== false;
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
        if (!current_user_can('manage_options')) return; ?>
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
        require_once __DIR__.'/includes/widget.php';
        register_widget('MLCM_Widget');
    }

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
                    'error' => __('Error clearing cache', 'mlcm')
                ]
            ]);
        }
    }
}

Multi_Level_Category_Menu::get_instance();

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

add_action('wp_head', function() {
    $width = get_option('mlcm_menu_width', 250);
    echo '<style>.mlcm-select { width: '.absint($width).'px; }</style>';
});