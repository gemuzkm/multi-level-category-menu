<?php
/*
Plugin Name: Multi-Level Category Menu
Description: Creates customizable category menus with 5-level depth with advanced performance optimizations
Version: 3.5
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

        // ДОБАВЛЕНО: FlyingPress совместимость
        add_action('init', [$this, 'add_flyingpress_compatibility']);

        // Условная загрузка ресурсов
        add_action('wp_enqueue_scripts', [$this, 'maybe_enqueue_assets'], 5);
        add_action('wp_footer', [$this, 'ensure_assets_loaded']);

        add_action('enqueue_block_editor_assets', [$this, 'enqueue_block_editor_assets']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'add_admin_menu']);

        // ОПТИМИЗИРОВАНО: Ленивая загрузка подменю через AJAX
        add_action('wp_ajax_mlcm_get_subcategories', [$this, 'ajax_lazy_load_submenu']);
        add_action('wp_ajax_nopriv_mlcm_get_subcategories', [$this, 'ajax_lazy_load_submenu']);

        add_action('edited_category', [$this, 'clear_related_cache']);
        add_action('create_category', [$this, 'clear_related_cache']);
        add_action('delete_category', [$this, 'clear_related_cache']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // Автоматическая очистка устаревших транзиентов
        add_action('mlcm_cleanup_transients', [$this, 'cleanup_expired_transients']);
        if (!wp_next_scheduled('mlcm_cleanup_transients')) {
            wp_schedule_event(time(), 'daily', 'mlcm_cleanup_transients');
        }

        // Создание индексов при активации
        register_activation_hook(__FILE__, [$this, 'create_database_indexes']);
    }

    /**
     * ДОБАВЛЕНО: Фильтры совместимости с FlyingPress
     */
    public function add_flyingpress_compatibility() {
        // Исключение CSS от минификации FlyingPress
        add_filter('flying_press_exclude_from_minify:css', function ($exclude_keywords) {
            if (!is_array($exclude_keywords)) {
                $exclude_keywords = [];
            }
            $exclude_keywords[] = '/wp-content/plugins/multi-level-category-menu/assets/css/frontend.css';
            return array_unique($exclude_keywords);
        });

        // Исключение JS от минификации FlyingPress
        add_filter('flying_press_exclude_from_minify:js', function ($exclude_keywords) {
            if (!is_array($exclude_keywords)) {
                $exclude_keywords = [];
            }
            $exclude_keywords[] = '/wp-content/plugins/multi-level-category-menu/assets/js/frontend.js';
            return array_unique($exclude_keywords);
        });
    }

    /**
     * Создание композитных индексов для оптимизации запросов
     */
    public function create_database_indexes() {
        global $wpdb;

        // Проверяем и создаем индексы только если их нет
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
     * Правильная обработка HTML-entities с учетом utf8mb4_unicode_520_ci
     */
    private function sanitize_menu_title($title) {
        $decoded = html_entity_decode($title, ENT_QUOTES | ENT_HTML401, 'UTF-8');
        $sanitized = sanitize_text_field($decoded);
        $normalized = trim($sanitized);
        return mb_strtoupper($normalized, 'UTF-8');
    }

    /**
     * Query Monitor интеграция для мониторинга производительности
     */
    private function log_query_performance($query, $execution_time) {
        if (class_exists('QM_Collectors')) {
            do_action('qm/debug', "MLCM Query: {$query} - Time: {$execution_time}ms");
        }

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
    }

    /**
     * Условная загрузка ресурсов
     */
    public function maybe_enqueue_assets() {
        if ($this->should_load_assets_early()) {
            $this->enqueue_frontend_assets();
            $this->assets_enqueued = true;
        }
    }

    public function ensure_assets_loaded() {
        if (!$this->assets_enqueued && $this->should_load_assets_late()) {
            $this->enqueue_frontend_assets();
            $this->assets_enqueued = true;
        }
    }

    private function should_load_assets_early() {
        global $post;

        if (is_category() || is_archive()) {
            return true;
        }

        if (is_front_page() || is_home()) {
            return true;
        }

        if ($this->is_menu_widget_active()) {
            return true;
        }

        if (is_singular() && is_a($post, 'WP_Post')) {
            if ($this->has_menu_shortcode($post->post_content)) {
                return true;
            }

            if (has_block('mlcm/menu-block', $post)) {
                return true;
            }

            // Проверяем кастомные поля
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

    private function should_load_assets_late() {
        if (did_action('mlcm_shortcode_executed')) {
            return true;
        }

        if (isset($GLOBALS['mlcm_needed']) && $GLOBALS['mlcm_needed']) {
            return true;
        }

        return false;
    }

    private function has_menu_shortcode($content = '') {
        if (empty($content)) {
            return false;
        }

        if (has_shortcode($content, 'mlcm_menu')) {
            return true;
        }

        if (preg_match('/\\[mlcm_menu(?:\\s[^\\]]*)?]/', $content)) {
            return true;
        }

        return false;
    }

    private function is_menu_widget_active() {
        if (is_active_widget(false, false, 'mlcm_widget')) {
            return true;
        }

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
                ],
                'root_id' => [
                    'type' => 'number',
                    'default' => 0
                ]
            ]
        ]);
    }

    public function render_gutenberg_block($attributes) {
        $atts = shortcode_atts([
            'layout' => 'vertical',
            'levels' => 3,
            'root_id' => 0
        ], $attributes);

        return $this->generate_menu_html($atts);
    }

    /**
     * ИСПРАВЛЕНО: Добавлена поддержка параметра root_id в шорткоде
     */
    public function shortcode_handler($atts) {
        do_action('mlcm_shortcode_executed');
        $GLOBALS['mlcm_needed'] = true;

        if (!$this->assets_enqueued) {
            $this->enqueue_frontend_assets();
            $this->assets_enqueued = true;
        }

        $atts = shortcode_atts([
            'layout' => get_option('mlcm_menu_layout', 'vertical'),
            'levels' => absint(get_option('mlcm_initial_levels', 3)),
            'root_id' => 0 // ДОБАВЛЕНО: поддержка root_id в шорткоде
        ], $atts);

        return $this->generate_menu_html($atts);
    }

    /**
     * ИСПРАВЛЕНО: Добавлена поддержка root_id
     */
    private function generate_menu_html($atts) {
        $show_button = get_option('mlcm_show_button', '0') === '1';

        // ИСПРАВЛЕНО: Определяем корневую категорию из параметров или настроек
        $root_id = 0;
        if (!empty($atts['root_id']) && is_numeric($atts['root_id'])) {
            $root_id = absint($atts['root_id']);
        } else {
            $custom_root_id = get_option('mlcm_custom_root_id', '');
            if (!empty($custom_root_id) && is_numeric($custom_root_id)) {
                $root_id = absint($custom_root_id);
            }
        }

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
                <button class="mlcm-go-button">Go</button>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * ИСПРАВЛЕНО: Добавлены правильные label для accessibility
     */
    private function render_select($level, $root_id = 0) {
        $label = get_option("mlcm_level_{$level}_label", "Level {$level}");

        // ИСПРАВЛЕНО: Для первого уровня используем root_id
        $categories = [];
        if ($level === 1) {
            $categories = $this->get_menu_fragment(1, $root_id);
        }

        $select_id = "mlcm-select-level-{$level}";
        $label_id = "mlcm-label-level-{$level}";
        ?>
        <div class="mlcm-select-wrapper">
            <!-- ДОБАВЛЕНО: Скрытый label для accessibility -->
            <label for="<?= esc_attr($select_id) ?>" id="<?= esc_attr($label_id) ?>" class="sr-only">
                <?= esc_html($label) ?>
            </label>

            <select class="mlcm-select" 
                    id="<?= esc_attr($select_id) ?>"
                    aria-labelledby="<?= esc_attr($label_id) ?>"
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
     * Fragment Caching - кеширование отдельных частей меню
     */
    public function get_menu_fragment($level, $parent_id = 0) {
        $fragment_key = "mlcm_fragment_{$level}_{$parent_id}";

        // Объектный кеш
        $fragment = wp_cache_get($fragment_key, $this->cache_group);

        if (false === $fragment) {
            // Транзиент
            $fragment = get_transient($fragment_key);

            if (false === $fragment) {
                // ИСПРАВЛЕНО: Используем правильный parent_id
                $fragment = $this->build_hierarchical_menu($parent_id);

                // Кешируем на 2 часа
                set_transient($fragment_key, $fragment, 2 * HOUR_IN_SECONDS);
            }

            // Сохраняем в объектном кеше на час
            wp_cache_set($fragment_key, $fragment, $this->cache_group, HOUR_IN_SECONDS);
        }

        return $fragment;
    }

    /**
     * ИСПРАВЛЕНО: Упрощенная логика - теперь parent_id используется напрямую
     */
    public function build_hierarchical_menu($parent_id = 0) {
        global $wpdb;

        $start_time = microtime(true);

        $excluded = array_map('absint', array_filter(explode(',', get_option('mlcm_excluded_cats', ''))));
        $excluded_sql = !empty($excluded) ? 'AND t.term_id NOT IN (' . implode(',', $excluded) . ')' : '';

        // ИСПРАВЛЕНО: Используем переданный parent_id напрямую
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

        if ($wpdb->last_error) {
            error_log("MLCM SQL Error: " . $wpdb->last_error);
            return [];
        }

        $execution_time = (microtime(true) - $start_time) * 1000;
        $this->log_query_performance("build_hierarchical_menu SQL (parent: {$parent_id})", $execution_time);

        // Данные уже отсортированы в SQL
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
     * ОПТИМИЗИРОВАНО: Ленивая загрузка подменю через AJAX
     */
    public function ajax_lazy_load_submenu() {
        check_ajax_referer('mlcm_nonce', 'security');

        $start_time = microtime(true);
        $parent_id = absint($_POST['parent_id'] ?? 0);

        // Fragment cache для AJAX запросов
        $cached_key = "mlcm_submenu_{$parent_id}";

        // Объектный кеш
        $submenu = wp_cache_get($cached_key, $this->cache_group);

        if (false === $submenu) {
            // Транзиент
            $submenu = get_transient($cached_key);

            if (false === $submenu) {
                // Получаем данные оптимизированным запросом
                $categories = $this->build_hierarchical_menu($parent_id);

                $submenu = [];
                foreach ($categories as $id => $data) {
                    $submenu[$id] = [
                        'name' => $data['name'],
                        'slug' => $data['slug'],
                        'url' => get_category_link($id)
                    ];
                }

                // Кешируем на 30 минут как рекомендовано
                set_transient($cached_key, $submenu, 30 * MINUTE_IN_SECONDS);

                $execution_time = (microtime(true) - $start_time) * 1000;
                $this->log_query_performance("ajax_lazy_load_submenu from DB (parent: {$parent_id})", $execution_time);
            }

            // Сохраняем в объектном кеше на 15 минут
            wp_cache_set($cached_key, $submenu, $this->cache_group, 15 * MINUTE_IN_SECONDS);
        }

        wp_send_json_success($submenu);
    }

    /**
     * УЛУЧШЕНО: Очистка связанного кеша включая fragments
     */
    public function clear_related_cache($term_id) {
        $term = get_term($term_id);
        if (!$term || is_wp_error($term)) {
            return;
        }

        // Очищаем объектный кеш
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

        foreach ($cache_keys_to_clear as $key) {
            wp_cache_delete($key, $this->cache_group);
        }

        // Очищаем транзиенты
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

        // Если корневая категория - очищаем все возможные корневые кеши
        if ($term->parent == 0) {
            // Очищаем кеш для корня по умолчанию
            wp_cache_delete('mlcm_fragment_1_0', $this->cache_group);
            delete_transient('mlcm_fragment_1_0');

            // Очищаем кеш для custom root (если он равен этой категории)
            $custom_root_id = get_option('mlcm_custom_root_id', '');
            if (!empty($custom_root_id) && absint($custom_root_id) == $term->term_id) {
                wp_cache_delete("mlcm_fragment_1_{$custom_root_id}", $this->cache_group);
                delete_transient("mlcm_fragment_1_{$custom_root_id}");
            }
        }

        error_log("MLCM: Cleared cache for category {$term->name} (ID: {$term_id})");
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
            echo '<input type="number" step="0.1" name="mlcm_font_size" value="' . esc_attr($font_size) . '" placeholder="1.0" />';
        }, 'mlcm_options', 'mlcm_main');

        add_settings_field('mlcm_container_gap', 'Gap Between Menu Items (px)', function() {
            $gap = get_option('mlcm_container_gap', '');
            echo '<input type="number" name="mlcm_container_gap" value="' . esc_attr($gap) . '" placeholder="20" />';
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
            echo '<input type="number" step="0.1" name="mlcm_button_font_size" value="' . esc_attr($font_size) . '" placeholder="1.0" />';
        }, 'mlcm_options', 'mlcm_main');

        add_settings_field('mlcm_custom_root_id', 'Custom Root Category ID', function() {
            $custom_root_id = get_option('mlcm_custom_root_id', '');
            echo '<input type="number" name="mlcm_custom_root_id" value="' . esc_attr($custom_root_id) . '" placeholder="0" />';
            echo '<p class="description">Specify the ID of the category whose subcategories will be used as the first level of the menu. Leave blank to use root categories (parent = 0).</p>';

            // ДОБАВЛЕНО: Показываем текущие корневые категории для справки
            if (!empty($custom_root_id) && is_numeric($custom_root_id)) {
                $category = get_category($custom_root_id);
                if ($category && !is_wp_error($category)) {
                    echo '<p><strong>Current root category:</strong> ' . esc_html($category->name) . ' (ID: ' . $category->term_id . ')</p>';

                    // Показываем подкategории этой категории
                    $subcategories = get_categories(['parent' => $custom_root_id, 'hide_empty' => false]);
                    if (!empty($subcategories)) {
                        echo '<p><strong>Subcategories:</strong> ';
                        $sub_names = array_map(function($cat) { return $cat->name; }, $subcategories);
                        echo esc_html(implode(', ', $sub_names));
                        echo '</p>';
                    } else {
                        echo '<p><strong>Warning:</strong> This category has no subcategories.</p>';
                    }
                } else {
                    echo '<p><strong>Error:</strong> Category with ID ' . $custom_root_id . ' not found.</p>';
                }
            }
        }, 'mlcm_options', 'mlcm_main');

        add_settings_field('mlcm_layout', 'Menu Layout', function() {
            $layout = get_option('mlcm_menu_layout', 'vertical');
            echo '<label><input type="radio" name="mlcm_menu_layout" value="vertical" ' . checked($layout, 'vertical', false) . ' /> Vertical</label><br>';
            echo '<label><input type="radio" name="mlcm_menu_layout" value="horizontal" ' . checked($layout, 'horizontal', false) . ' /> Horizontal</label>';
        }, 'mlcm_options', 'mlcm_main');

        add_settings_field('mlcm_levels', 'Initial Levels', function() {
            $levels = get_option('mlcm_initial_levels', 3);
            echo '<select name="mlcm_initial_levels">';
            for ($i = 1; $i <= 5; $i++) {
                echo '<option value="' . $i . '" ' . selected($levels, $i, false) . '>' . $i . '</option>';
            }
            echo '</select>';
        }, 'mlcm_options', 'mlcm_main');

        add_settings_field('mlcm_width', 'Menu Width (px)', function() {
            $width = get_option('mlcm_menu_width', 250);
            echo '<input type="number" name="mlcm_menu_width" value="' . esc_attr($width) . '" />';
        }, 'mlcm_options', 'mlcm_main');

        add_settings_field('mlcm_show_button', 'Show Go Button', function() {
            $show = get_option('mlcm_show_button', '0');
            echo '<label><input type="checkbox" name="mlcm_show_button" value="1" ' . checked($show, '1', false) . ' /> ' . __('Enable Go button', 'mlcm') . '</label>';
        }, 'mlcm_options', 'mlcm_main');

        add_settings_field('mlcm_use_category_base', 'Use Category Base', function() {
            $use_base = get_option('mlcm_use_category_base', '1');
            echo '<label><input type="checkbox" name="mlcm_use_category_base" value="1" ' . checked($use_base, '1', false) . ' /> ' . __('Include "category" in URL', 'mlcm') . '</label>';
        }, 'mlcm_options', 'mlcm_main');

        add_settings_field('mlcm_exclude', 'Excluded Categories', function() {
            $excluded = get_option('mlcm_excluded_cats', '');
            echo '<input type="text" name="mlcm_excluded_cats" value="' . esc_attr($excluded) . '" placeholder="1,5,10" class="regular-text" />';
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
            echo '<button type="button" id="mlcm-clear-cache" class="button">Clear All Cache</button> ';
            echo '<button type="button" id="mlcm-cleanup-transients" class="button">Cleanup Expired Transients</button>';
            echo '<p class="description">Clear all cached category data including fragments to force refresh</p>';
            echo '<p><strong>Performance Features:</strong> Database Indexes | Fragment Caching | Lazy Loading | Optimized SQL | FlyingPress Compatibility | Accessibility Labels</p>';
            echo '<p><strong>Shortcode Usage:</strong> [mlcm_menu root_id="2"] - to start from specific category</p>';
        }, 'mlcm_options', 'mlcm_main');
    }

    public function enqueue_frontend_assets() {
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
     * УЛУЧШЕНО: Функция очистки всех кешей включая fragments
     */
    public function clear_all_caches() {
        global $wpdb;

        $start_time = microtime(true);

        // Очищаем объектный кеш
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group($this->cache_group);
        }

        // Очищаем все транзиенты включая fragments и submenu
        $result = $wpdb->query(
            "DELETE FROM $wpdb->options 
             WHERE option_name LIKE '_transient_mlcm_%' 
                OR option_name LIKE '_transient_timeout_mlcm_%'"
        );

        $execution_time = (microtime(true) - $start_time) * 1000;
        $this->log_query_performance("clear_all_caches including fragments", $execution_time);

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

// AJAX обработчики
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
?>