<?php
/*
Plugin Name: Multi-Level Category Menu
Description: Creates customizable category menus with 5-level depth
Version: 3.1
Author: Your Name
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
    
        wp_register_style(
            'mlcm-block-editor-style',
            plugins_url('assets/css/block-editor.css', __FILE__),
            [],
            filemtime(plugin_dir_path(__FILE__) . 'assets/css/block-editor.css')
        );
    
        register_block_type('mlcm/menu-block', [
            'editor_script' => 'mlcm-block-editor',
            'editor_style' => 'mlcm-block-editor-style',
            'style' => 'mlcm-frontend',
            'render_callback' => [$this, 'render_gutenberg_block'],
            'attributes' => [
                'layout' => ['type' => 'string', 'default' => 'vertical'],
                'levels' => ['type' => 'number', 'default' => 3],
                'showButton' => ['type' => 'boolean', 'default' => true],
                'bgColor' => ['type' => 'string', 'default' => '#ffffff'],
                'textColor' => ['type' => 'string', 'default' => '#333333'],
                'fontSize' => ['type' => 'number', 'default' => 16],
                'spacing' => ['type' => 'number', 'default' => 20],
                'alignment' => ['type' => 'string', 'default' => 'left'],
                'buttonLabel' => ['type' => 'string', 'default' => 'Go'],
                'buttonWidth' => ['type' => 'number', 'default' => 100],
                'buttonWidthUnit' => ['type' => 'string', 'default' => 'px'],
                'buttonPosition' => ['type' => 'string', 'default' => 'after'],
                'buttonBgColor' => ['type' => 'string', 'default' => '#0073aa'],
                'buttonTextColor' => ['type' => 'string', 'default' => '#ffffff'],
                'buttonBorderRadius' => ['type' => 'number', 'default' => 4],
                'buttonBorderWidth' => ['type' => 'number', 'default' => 0],
                'buttonBorderColor' => ['type' => 'string', 'default' => '#0073aa'],
                'fontSizeUnit' => ['type' => 'string', 'default' => 'px'],
                'gap' => ['type' => 'number', 'default' => 20]
            ]
        ]);
    }

    public function render_gutenberg_block($attributes) {
        $atts = shortcode_atts([
            'layout' => 'vertical',
            'levels' => 3,
            'show_button' => true,
            'bg_color' => '#ffffff',
            'text_color' => '#333333',
            'font_size' => 16,
            'spacing' => 20,
            'alignment' => 'left',
            'button_label' => __('Go', 'mlcm'),
            'button_width' => 100,
            'button_width_unit' => 'px',
            'button_position' => 'after',
            'button_bg_color' => '#0073aa',
            'button_text_color' => '#ffffff',
            'button_border_radius' => 4,
            'button_border_width' => 0,
            'button_border_color' => '#0073aa',
            'font_size_unit' => 'px',
            'gap' => 20
        ], $attributes);

        $styles = [
            'background-color' => $atts['bg_color'],
            'color' => $atts['text_color'],
            'font-size' => $atts['font_size'] . $atts['font_size_unit'],
            'padding' => $atts['spacing'] . 'px',
            'gap' => $atts['gap'] . 'px',
            'justify-content' => $this->get_alignment_class($atts['alignment'])
        ];

        $inline_style = '';
        foreach ($styles as $prop => $value) {
            $inline_style .= "$prop:$value;";
        }

        return sprintf(
            '<div class="mlcm-container %s" style="%s" data-levels="%d">%s%s%s</div>',
            esc_attr($atts['layout']),
            esc_attr($inline_style),
            absint($atts['levels']),
            ($atts['button_position'] === 'before' && $atts['show_button']) ? $this->render_button($atts) : '',
            $this->generate_menu_html($atts),
            ($atts['button_position'] === 'after' && $atts['show_button']) ? $this->render_button($atts) : ''
        );
    }

    private function get_alignment_class($alignment) {
        $map = [
            'left' => 'flex-start',
            'center' => 'center',
            'right' => 'flex-end'
        ];
        return $map[$alignment] ?? 'flex-start';
    }

    private function render_button($atts) {
        $button_styles = [
            'background-color' => $atts['button_bg_color'],
            'color' => $atts['button_text_color'],
            'border-radius' => $atts['button_border_radius'] . 'px',
            'border' => $atts['button_border_width'] . 'px solid ' . $atts['button_border_color'],
            'width' => $atts['button_width'] . $atts['button_width_unit'],
            'font-size' => $atts['font_size'] . $atts['font_size_unit']
        ];

        $inline_style = '';
        foreach ($button_styles as $prop => $value) {
            $inline_style .= "$prop:$value;";
        }

        return sprintf(
            '<button type="button" class="mlcm-go-button" style="%s">%s</button>',
            esc_attr($inline_style),
            esc_html($atts['button_label'])
        );
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
        ?>
        <select class="mlcm-select" 
                data-level="<?php echo absint($level); ?>" 
                <?php echo ($level > 1) ? 'disabled' : ''; ?>>
            <option value="-1"><?php echo esc_html($label); ?></option>
            <?php foreach ($categories as $id => $name): ?>
                <option value="<?php echo absint($id); ?>"><?php echo esc_html($name); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    private function get_root_categories() {
        $cache = get_transient('mlcm_root_cats');
        
        if (false === $cache) {
            $excluded = array_map('absint', explode(',', get_option('mlcm_excluded_cats', '')));
            $categories = get_categories([
                'parent' => 0,
                'exclude' => $excluded,
                'fields' => 'id=>name',
                'orderby' => 'name',
                'hide_empty' => true
            ]);
            
            $cache = array_map('strtoupper', $categories);
            set_transient('mlcm_root_cats', $cache, WEEK_IN_SECONDS);
        }
        return $cache;
    }

    /*
    public function ajax_handler() {
        check_ajax_referer('mlcm_nonce', 'security');
        
        $parent_id = isset($_POST['parent_id']) ? absint($_POST['parent_id']) : 0;
        $cache_key = "mlcm_subcats_{$parent_id}";
        
        if (false === ($response = get_transient($cache_key))) {
            $categories = get_categories([
                'parent' => $parent_id,
                'fields' => 'id=>name',
                'orderby' => 'name',
                'hide_empty' => true
            ]);
            
            $response = array_map('strtoupper', $categories);
            set_transient($cache_key, $response, WEEK_IN_SECONDS);
        }
        
        wp_send_json_success($response);
    }
    */

    public function ajax_handler() {
        check_ajax_referer('mlcm_nonce', 'security');
    
        // Отключаем ненужные компоненты WordPress для ускорения
        wp_suspend_cache_addition(true);
        remove_all_actions('plugins_loaded');
        remove_all_filters('sanitize_title');
    
        $parent_id = absint($_POST['parent_id'] ?? 0);
        $cache_key = "mlcm_subcats_{$parent_id}";
    
        // Проверяем кеш
        if (false === ($response = get_transient($cache_key))) {
            global $wpdb;
    
            // Прямой SQL-запрос для получения категорий
            $response = $wpdb->get_results($wpdb->prepare("
                SELECT 
                    t.term_id as id, 
                    t.name 
                FROM {$wpdb->terms} t
                INNER JOIN {$wpdb->term_taxonomy} tt 
                    ON t.term_id = tt.term_id
                WHERE 
                    tt.parent = %d 
                    AND tt.taxonomy = 'category'
                    AND tt.count > 0  # Только непустые категории
                ORDER BY t.name ASC
            ", $parent_id), OBJECT_K);
    
            // Сохраняем в кеше
            if (!empty($response)) {
                $response = array_map(function($item) {
                    return strtoupper($item->name);
                }, $response);
                
                set_transient($cache_key, $response, WEEK_IN_SECONDS);
            }
        }
    
        wp_send_json_success($response);
    }

    public function register_settings() {
        register_setting('mlcm_options', 'mlcm_menu_layout');
        register_setting('mlcm_options', 'mlcm_initial_levels');
        register_setting('mlcm_options', 'mlcm_excluded_cats');
        register_setting('mlcm_options', 'mlcm_menu_width');
        register_setting('mlcm_options', 'mlcm_show_button');
        
        for ($i = 1; $i <= 5; $i++) {
            register_setting('mlcm_options', "mlcm_level_{$i}_label");
        }

        add_settings_section('mlcm_main', 'Main Settings', null, 'mlcm_options');

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

    public function clear_related_cache($term_id) {
        $term = get_term($term_id);
        if ($term->parent === 0) {
            delete_transient('mlcm_root_cats');
        } else {
            delete_transient("mlcm_subcats_{$term->parent}");
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
            }, range(1,5))
        ]);
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