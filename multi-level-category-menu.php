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
    private $cache_group = 'mlcm_cache';
    private $assets_enqueued = false;
    
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
        
        // ИСПРАВЛЕНО: Более надежная условная загрузка ресурсов
        add_action('wp_enqueue_scripts', [$this, 'maybe_enqueue_assets'], 5);
        add_action('wp_footer', [$this, 'ensure_assets_loaded']); // Fallback
        
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_block_editor_assets']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('wp_ajax_mlcm_get_subcategories', [$this, 'ajax_handler']);
        add_action('wp_ajax_nopriv_mlcm_get_subcategories', [$this, 'ajax_handler']);
        add_action('edited_category', [$this, 'clear_related_cache']);
        add_action('create_category', [$this, 'clear_related_cache']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        
        // Автоматическая очистка устаревших транзиентов
        add_action('mlcm_cleanup_transients', [$this, 'cleanup_expired_transients']);
        if (!wp_next_scheduled('mlcm_cleanup_transients')) {
            wp_schedule_event(time(), 'daily', 'mlcm_cleanup_transients');
        }
    }
    
    /**
     * Правильная обработка HTML-entities с учетом utf8mb4_unicode_520_ci
     */
    private function sanitize_menu_title($title) {
        // Декодируем HTML-entities только один раз с полной поддержкой UTF-8
        $decoded = html_entity_decode($title, ENT_QUOTES | ENT_HTML401, 'UTF-8');
        
        // Применяем санитизацию без повторного кодирования
        $sanitized = sanitize_text_field($decoded);
        
        // Убираем лишние пробелы и нормализуем Unicode
        $normalized = trim($sanitized);
        
        // Возвращаем в верхнем регистре как было в оригинале
        return mb_strtoupper($normalized, 'UTF-8');
    }
    
    /**
     * Query Monitor интеграция для мониторинга производительности
     */
    private function log_query_performance($query, $execution_time) {
        if (class_exists('QM_Collectors')) {
            do_action('qm/debug', "MLCM Query: {$query} - Time: {$execution_time}ms");
        }
        
        // Дополнительно логируем медленные запросы
        if ($execution_time > 100) {
            error_log("MLCM Slow Query: {$query} - Time: {$execution_time}ms");
        }
    }
    
    /**
     * Автоматическая очистка устаревших транзиентов
     */
    public function cleanup_expired_transients() {
        global $wpdb;
        
        $start_time = microtime(true);
        
        $deleted_timeouts = $wpdb->query("DELETE FROM {$wpdb->options} 
                                         WHERE option_name LIKE '_transient_timeout_mlcm_%' 
                                         AND option_value < UNIX_TIMESTAMP()");
        
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
        
        error_log("MLCM Cleanup: Removed {$deleted_timeouts} timeout entries and {$deleted_transients} transient entries in {$execution_time}ms");
    }
    
    /**
     * ИСПРАВЛЕНО: Более надежная проверка необходимости загрузки ресурсов
     */
    public function maybe_enqueue_assets() {
        if ($this->should_load_assets_early()) {
            $this->enqueue_frontend_assets();
            $this->assets_enqueued = true;
        }
    }
    
    /**
     * ИСПРАВЛЕНО: Fallback для загрузки ресурсов в футере
     */
    public function ensure_assets_loaded() {
        // Если ресурсы еще не загружены, проверяем еще раз
        if (!$this->assets_enqueued && $this->should_load_assets_late()) {
            $this->enqueue_frontend_assets();
            $this->assets_enqueued = true;
            
            // Логируем поздную загрузку
            error_log("MLCM: Assets loaded in footer fallback");
        }
    }
    
    /**
     * ИСПРАВЛЕНО: Ранняя проверка (консервативная)
     */
    private function should_load_assets_early() {
        global $post;
        
        // На страницах категорий и архивов загружаем всегда
        if (is_category() || is_archive()) {
            return true;
        }
        
        // На главной странице загружаем всегда (может быть виджет)
        if (is_front_page() || is_home()) {
            return true;
        }
        
        // Если есть активные виджеты, загружаем
        if ($this->is_menu_widget_active()) {
            return true;
        }
        
        // На отдельных постах и страницах проверяем контент
        if (is_singular() && is_a($post, 'WP_Post')) {
            // Проверяем шорткод в контенте
            if ($this->has_menu_shortcode($post->post_content)) {
                return true;
            }
            
            // Проверяем Gutenberg блоки
            if (has_block('mlcm/menu-block', $post)) {
                return true;
            }
            
            // ДОБАВЛЕНО: Проверяем кастомные поля
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
     * ИСПРАВЛЕНО: Поздняя проверка (в футере, более точная)
     */
    private function should_load_assets_late() {
        global $post, $wp_registered_sidebars;
        
        // ДОБАВЛЕНО: Проверяем содержимое всех активных виджетов
        if (is_active_sidebar('sidebar-1') || is_active_sidebar('footer') || is_active_sidebar('header')) {
            $sidebars_widgets = get_option('sidebars_widgets', array());
            foreach ($sidebars_widgets as $sidebar_id => $widgets) {
                if (is_array($widgets)) {
                    foreach ($widgets as $widget_id) {
                        if (strpos($widget_id, 'mlcm_widget') !== false) {
                            return true;
                        }
                        
                        // Проверяем текстовые виджеты на наличие шорткода
                        if (strpos($widget_id, 'text') !== false) {
                            $widget_text = get_option('widget_text');
                            if (is_array($widget_text)) {
                                foreach ($widget_text as $instance) {
                                    if (isset($instance['text']) && $this->has_menu_shortcode($instance['text'])) {
                                        return true;
                                    }
                                }
                            }
                        }
                        
                        // Проверяем кастомные HTML виджеты
                        if (strpos($widget_id, 'custom_html') !== false) {
                            $widget_html = get_option('widget_custom_html');
                            if (is_array($widget_html)) {
                                foreach ($widget_html as $instance) {
                                    if (isset($instance['content']) && $this->has_menu_shortcode($instance['content'])) {
                                        return true;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        
        // ДОБАВЛЕНО: Проверяем, был ли шорткод выполнен
        if (did_action('mlcm_shortcode_executed')) {
            return true;
        }
        
        // Проверяем глобальную переменную (может быть установлена в шаблоне)
        if (isset($GLOBALS['mlcm_needed']) && $GLOBALS['mlcm_needed']) {
            return true;
        }
        
        return false;
    }
    
    /**
     * ИСПРАВЛЕНО: Более надежная проверка шорткода
     */
    private function has_menu_shortcode($content = '') {
        if (empty($content)) {
            return false;
        }
        
        // Проверяем наличие шорткода разными способами
        if (has_shortcode($content, 'mlcm_menu')) {
            return true;
        }
        
        // Проверяем через регулярное выражение (на случай если has_shortcode не сработает)
        if (preg_match('/\[mlcm_menu(?:\s[^\]]*)?]/', $content)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * ИСПРАВЛЕНО: Более точная проверка активности виджета
     */
    private function is_menu_widget_active() {
        // Проверяем стандартным способом
        if (is_active_widget(false, false, 'mlcm_widget')) {
            return true;
        }
        
        // ДОБАВЛЕНО: Дополнительная проверка через sidebars_widgets
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
    
    /**
     * ИСПРАВЛЕНО: Добавлен маркер выполнения шорткода
     */
    public function shortcode_handler($atts) {
        // ДОБАВЛЕНО: Устанавливаем маркер что шорткод был выполнен
        do_action('mlcm_shortcode_executed');
        $GLOBALS['mlcm_needed'] = true;
        
        // Если ресурсы еще не загружены, загружаем их сейчас
        if (!$this->assets_enqueued) {
            $this->enqueue_frontend_assets();
            $this->assets_enqueued = true;
        }
        
        $atts = shortcode_atts([
            'layout' => get_option('mlcm_menu_layout', 'vertical'),
            'levels' => absint(get_option('mlcm_initial_levels', 3))
        ], $atts);
        
        return $this->generate_menu_html($atts);
    }
    
    private function generate_menu_html($atts) {
        $show_button = get_option('mlcm_show_button', '0') === '1';
        ob_start(); ?>
        <div class="mlcm-container <?= esc_attr($atts['layout']) ?>" data-levels="<?= absint($atts['levels']) ?>">
            <?php for($i = 1; $i <= $atts['levels']; $i++): ?>
                <div class="mlcm-level" data-level="<?= $i ?>">
                    <?php $this->render_select($i); ?>
                </div>
            <?php endfor; ?>
            <?php if ($show_button): ?>
                <button class="mlcm-go-button" type="button"><?= __('Go', 'mlcm') ?></button>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    private function render_select($level) {
        $label = get_option("mlcm_level_{$level}_label", "Level {$level}");
        $categories = ($level === 1) ? $this->get_root_categories() : [];
        $select_id = "mlcm-select-level-{$level}";
        ?>
        <select id="<?= $select_id ?>" 
                class="mlcm-select" 
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
        <?php
    }
    
    /**
     * Улучшено: Добавлено логирование производительности + исправлена обработка символов
     */
    private function get_root_categories() {
        $start_time = microtime(true);
        
        $custom_root_id = get_option('mlcm_custom_root_id', '');
        $parent_id = !empty($custom_root_id) && is_numeric($custom_root_id) ? absint($custom_root_id) : 0;
        
        // Объектное кеширование (Redis/Memcached) - проверяем сначала
        $cache_key = "mlcm_cats_{$parent_id}";
        $categories = wp_cache_get($cache_key, $this->cache_group);
        
        if (false === $categories) {
            // Транзиенты - проверяем второй уровень кеша
            $transient_key = "mlcm_cats_{$parent_id}";
            $categories = get_transient($transient_key);
            
            if (false === $categories) {
                // Получаем данные из базы и обрабатываем
                $excluded = array_map('absint', explode(',', get_option('mlcm_excluded_cats', '')));
                
                $wp_categories = get_categories([
                    'parent' => $parent_id,
                    'exclude' => $excluded,
                    'hide_empty' => false,
                    'orderby' => 'name',
                    'order' => 'ASC',
                    'fields' => 'all'
                ]);
                
                // Принудительная сортировка для гарантии правильного порядка
                usort($wp_categories, function($a, $b) {
                    return strcasecmp($a->name, $b->name);
                });
                
                $categories = [];
                foreach ($wp_categories as $category) {
                    // Используем новую функцию обработки символов
                    $categories[$category->term_id] = [
                        'name' => $this->sanitize_menu_title($category->name),
                        'slug' => $category->slug
                    ];
                }
                
                // Сохраняем в транзиенты на месяц
                set_transient($transient_key, $categories, MONTH_IN_SECONDS);
                
                $execution_time = (microtime(true) - $start_time) * 1000;
                $this->log_query_performance("get_root_categories from DB (parent: {$parent_id})", $execution_time);
            }
            
            // Сохраняем в объектном кеше на неделю
            wp_cache_set($cache_key, $categories, $this->cache_group, WEEK_IN_SECONDS);
        }
        
        return $categories;
    }
    
    /**
     * Улучшено: Добавлено логирование производительности + исправлена обработка символов в AJAX
     */
    public function ajax_handler() {
        check_ajax_referer('mlcm_nonce', 'security');
        
        $start_time = microtime(true);
        $parent_id = absint($_POST['parent_id'] ?? 0);
        
        // Объектное кеширование - проверяем сначала
        $cache_key = "mlcm_subcats_{$parent_id}";
        $response = wp_cache_get($cache_key, $this->cache_group);
        
        if (false === $response) {
            // Транзиенты - проверяем второй уровень кеша
            $transient_key = "mlcm_subcats_{$parent_id}";
            $response = get_transient($transient_key);
            
            if (false === $response) {
                // Получаем данные из базы
                $categories = get_categories([
                    'parent' => $parent_id,
                    'hide_empty' => false,
                    'orderby' => 'name',
                    'order' => 'ASC',
                    'fields' => 'all'
                ]);
                
                // Принудительная сортировка для гарантии правильного порядка
                usort($categories, function($a, $b) {
                    return strcasecmp($a->name, $b->name);
                });
                
                $response = [];
                foreach ($categories as $category) {
                    // Используем новую функцию обработки символов
                    $response[$category->term_id] = [
                        'name' => $this->sanitize_menu_title($category->name),
                        'slug' => $category->slug,
                        'url' => get_category_link($category->term_id)
                    ];
                }
                
                // Сохраняем в транзиенты на месяц
                set_transient($transient_key, $response, MONTH_IN_SECONDS);
                
                $execution_time = (microtime(true) - $start_time) * 1000;
                $this->log_query_performance("ajax_handler from DB (parent: {$parent_id})", $execution_time);
            }
            
            // Сохраняем в объектном кеше на неделю
            wp_cache_set($cache_key, $response, $this->cache_group, WEEK_IN_SECONDS);
        }
        
        wp_send_json_success($response);
    }
    
    /**
     * Очистка объектного кеша + транзиентов при изменении категорий
     */
    public function clear_related_cache($term_id) {
        $term = get_term($term_id);
        if ($term) {
            // Очищаем объектный кеш
            wp_cache_delete("mlcm_cats_{$term->term_id}", $this->cache_group);
            wp_cache_delete("mlcm_subcats_{$term->term_id}", $this->cache_group);
            wp_cache_delete("mlcm_cats_{$term->parent}", $this->cache_group);
            wp_cache_delete("mlcm_subcats_{$term->parent}", $this->cache_group);
            
            // Очищаем транзиенты
            delete_transient("mlcm_cats_{$term->term_id}");
            delete_transient("mlcm_subcats_{$term->term_id}");
            delete_transient("mlcm_cats_{$term->parent}");
            delete_transient("mlcm_subcats_{$term->parent}");
            
            if ($term->parent == 0) {
                $custom_root_id = get_option('mlcm_custom_root_id', '');
                if (empty($custom_root_id)) {
                    wp_cache_delete('mlcm_cats_0', $this->cache_group);
                    delete_transient('mlcm_cats_0');
                }
            }
            
            error_log("MLCM: Cleared cache for category {$term->name} (ID: {$term_id})");
        }
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
            echo '<input type="number" step="0.1" min="0.5" max="5" name="mlcm_font_size" value="' . esc_attr($font_size) . '" />';
        }, 'mlcm_options', 'mlcm_main');
        
        add_settings_field('mlcm_container_gap', 'Gap Between Menu Items (px)', function() {
            $gap = get_option('mlcm_container_gap', '');
            echo '<input type="number" min="0" max="100" name="mlcm_container_gap" value="' . esc_attr($gap) . '" />';
        }, 'mlcm_options', 'mlcm_main');
        
        add_settings_field('mlcm_button_bg_color', 'Button Background Color', function() {
            $bg_color = get_option('mlcm_button_bg_color', '');
            echo '<input type="color" name="mlcm_button_bg_color" value="' . esc_attr($bg_color) . '" />';
        }, 'mlcm_options', 'mlcm_main');
        
        add_settings_field('mlcm_button_hover_bg_color', 'Button Hover Background Color', function() {
            $hover_bg_color = get_option('mlcm_button_hover_bg_color', '');
            echo '<input type="color" name="mlcm_button_hover_bg_color" value="' . esc_attr($hover_bg_color) . '" />';
        }, 'mlcm_options', 'mlcm_main');
        
        add_settings_field('mlcm_button_font_size', 'Button Font Size (rem)', function() {
            $font_size = get_option('mlcm_button_font_size', '');
            echo '<input type="number" step="0.1" min="0.5" max="5" name="mlcm_button_font_size" value="' . esc_attr($font_size) . '" />';
        }, 'mlcm_options', 'mlcm_main');
        
        add_settings_field('mlcm_custom_root_id', 'Custom Root Category ID', function() {
            $custom_root_id = get_option('mlcm_custom_root_id', '');
            echo '<input type="number" min="0" name="mlcm_custom_root_id" value="' . esc_attr($custom_root_id) . '" />';
            echo '<p class="description">Specify the ID of the category whose subcategories will be used as the first level of the menu. Leave blank to use root categories.</p>';
        }, 'mlcm_options', 'mlcm_main');
        
        add_settings_field('mlcm_layout', 'Menu Layout', function() {
            $layout = get_option('mlcm_menu_layout', 'vertical');
            echo '<label><input type="radio" name="mlcm_menu_layout" value="vertical"' . checked($layout, 'vertical', false) . '> Vertical</label><br>';
            echo '<label><input type="radio" name="mlcm_menu_layout" value="horizontal"' . checked($layout, 'horizontal', false) . '> Horizontal</label>';
        }, 'mlcm_options', 'mlcm_main');
        
        add_settings_field('mlcm_levels', 'Initial Levels', function() {
            $levels = get_option('mlcm_initial_levels', 3);
            echo '<input type="number" min="1" max="5" name="mlcm_initial_levels" value="' . absint($levels) . '" />';
        }, 'mlcm_options', 'mlcm_main');
        
        add_settings_field('mlcm_width', 'Menu Width (px)', function() {
            $width = get_option('mlcm_menu_width', 250);
            echo '<input type="number" min="100" max="500" name="mlcm_menu_width" value="' . absint($width) . '" />';
        }, 'mlcm_options', 'mlcm_main');
        
        add_settings_field('mlcm_show_button', 'Show Go Button', function() {
            $show = get_option('mlcm_show_button', '0');
            echo '<label><input type="checkbox" name="mlcm_show_button" value="1"' . checked($show, '1', false) . '> ' . __('Enable Go button', 'mlcm') . '</label>';
        }, 'mlcm_options', 'mlcm_main');
        
        add_settings_field('mlcm_use_category_base', 'Use Category Base', function() {
            $use_base = get_option('mlcm_use_category_base', '1');
            echo '<label><input type="checkbox" name="mlcm_use_category_base" value="1"' . checked($use_base, '1', false) . '> ' . __('Include "category" in URL', 'mlcm') . '</label>';
        }, 'mlcm_options', 'mlcm_main');
        
        add_settings_field('mlcm_exclude', 'Excluded Categories', function() {
            $excluded = get_option('mlcm_excluded_cats', '');
            echo '<input type="text" name="mlcm_excluded_cats" class="regular-text" placeholder="Comma-separated IDs" value="' . esc_attr($excluded) . '" />';
        }, 'mlcm_options', 'mlcm_main');
        
        for ($i = 1; $i <= 5; $i++) {
            add_settings_field(
                "mlcm_label_{$i}", 
                "Level {$i} Label",
                function() use ($i) {
                    $label = get_option("mlcm_level_{$i}_label", "Level {$i}");
                    echo '<input type="text" name="mlcm_level_' . $i . '_label" value="' . esc_attr($label) . '" />';
                },
                'mlcm_options', 
                'mlcm_main'
            );
        }
        
        add_settings_field('mlcm_cache', 'Cache Management', function() {
            echo '<button type="button" id="mlcm-clear-cache" class="button">Clear All Cache</button>';
            echo '<button type="button" id="mlcm-cleanup-transients" class="button" style="margin-left: 10px;">Cleanup Expired Transients</button>';
            echo '<span class="spinner"></span>';
            echo '<p class="description">Clear all cached category data to force refresh</p>';
            echo '<p class="description"><strong>Cache Settings:</strong> Object Cache: 1 week | Transients: 1 month | Auto cleanup: daily</p>';
            echo '<p class="description"><strong>Assets Loading:</strong> Conditional loading with fallback for reliability</p>';
        }, 'mlcm_options', 'mlcm_main');
    }
    
    /**
     * ИСПРАВЛЕНО: Всегда доступная функция загрузки ресурсов
     */
    public function enqueue_frontend_assets() {
        // Проверяем, не загружены ли уже ресурсы
        if (wp_style_is('mlcm-frontend', 'enqueued')) {
            return;
        }
        
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
    
    /**
     * Функция очистки всех кешей + мониторинг
     */
    public function clear_all_caches() {
        global $wpdb;
        
        $start_time = microtime(true);
        
        // Очищаем объектный кеш (если доступен Redis/Memcached)
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group($this->cache_group);
        }
        
        // Очищаем транзиенты
        delete_transient('mlcm_root_cats');
        $result = $wpdb->query(
            "DELETE FROM $wpdb->options 
             WHERE option_name LIKE '_transient_mlcm_%' 
             OR option_name LIKE '_transient_timeout_mlcm_%'"
        );
        
        $execution_time = (microtime(true) - $start_time) * 1000;
        $this->log_query_performance("clear_all_caches", $execution_time);
        
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
                    'error' => __('Error clearing cache', 'mlcm'),
                    'cleanup_done' => __('Expired transients cleaned up', 'mlcm')
                ]
            ]);
        }
    }
}

Multi_Level_Category_Menu::get_instance();

// AJAX обработчик для очистки кеша
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

// AJAX обработчик для очистки устаревших транзиентов
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

add_action('wp_head', function() {
    $width = get_option('mlcm_menu_width', 250);
    echo "<style>:root { --mlcm-width: {$width}px; }</style>";
});