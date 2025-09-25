<?php
defined('ABSPATH') || exit;

/**
 * ИСПРАВЛЕНО: Правильная структура виджета WordPress
 */
class MLCM_Widget extends WP_Widget {
    
    /**
     * Конструктор виджета
     */
    public function __construct() {
        $widget_ops = [
            'classname' => 'mlcm_widget',
            'description' => __('Multi-level category menu for navigation', 'mlcm'),
            'customize_selective_refresh' => true,
        ];
        
        $control_ops = [
            'width' => 300,
            'height' => 350,
            'id_base' => 'mlcm_widget'
        ];
        
        parent::__construct(
            'mlcm_widget', // Base ID
            __('Category Menu', 'mlcm'), // Name
            $widget_ops,
            $control_ops
        );
    }
    
    /**
     * Фронтенд виджета
     */
    public function widget($args, $instance) {
        // Извлекаем параметры виджета
        $title = !empty($instance['title']) ? $instance['title'] : '';
        $layout = !empty($instance['layout']) ? $instance['layout'] : get_option('mlcm_menu_layout', 'vertical');
        $levels = !empty($instance['levels']) ? absint($instance['levels']) : absint(get_option('mlcm_initial_levels', 3));
        $show_button = !empty($instance['show_button']) ? '1' : '0';
        
        // Применяем фильтр к заголовку
        $title = apply_filters('widget_title', $title, $instance, $this->id_base);
        
        // Выводим виджет
        echo $args['before_widget'];
        
        if (!empty($title)) {
            echo $args['before_title'] . esc_html($title) . $args['after_title'];
        }
        
        // Генерируем меню с настройками из виджета
        $shortcode_atts = [
            'layout' => $layout,
            'levels' => $levels,
            'show_button' => $show_button
        ];
        
        // Создаем временные опции для виджета
        $original_layout = get_option('mlcm_menu_layout');
        $original_levels = get_option('mlcm_initial_levels');
        $original_button = get_option('mlcm_show_button');
        
        update_option('mlcm_menu_layout', $layout);
        update_option('mlcm_initial_levels', $levels);
        update_option('mlcm_show_button', $show_button);
        
        // Выводим меню
        echo do_shortcode('[mlcm_menu layout="' . esc_attr($layout) . '" levels="' . absint($levels) . '"]');
        
        // Восстанавливаем оригинальные опции
        update_option('mlcm_menu_layout', $original_layout);
        update_option('mlcm_initial_levels', $original_levels);
        update_option('mlcm_show_button', $original_button);
        
        echo $args['after_widget'];
    }
    
    /**
     * Административная форма виджета
     */
    public function form($instance) {
        // Значения по умолчанию
        $defaults = [
            'title' => '',
            'layout' => get_option('mlcm_menu_layout', 'vertical'),
            'levels' => absint(get_option('mlcm_initial_levels', 3)),
            'show_button' => '0'
        ];
        
        $instance = wp_parse_args((array) $instance, $defaults);
        
        // Поля формы
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>">
                <?php _e('Title:', 'mlcm'); ?>
            </label>
            <input class="widefat" 
                   id="<?php echo esc_attr($this->get_field_id('title')); ?>" 
                   name="<?php echo esc_attr($this->get_field_name('title')); ?>" 
                   type="text" 
                   value="<?php echo esc_attr($instance['title']); ?>" 
                   placeholder="<?php _e('Optional widget title', 'mlcm'); ?>" />
        </p>
        
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('layout')); ?>">
                <?php _e('Layout:', 'mlcm'); ?>
            </label>
            <select class="widefat" 
                    id="<?php echo esc_attr($this->get_field_id('layout')); ?>" 
                    name="<?php echo esc_attr($this->get_field_name('layout')); ?>">
                <option value="vertical" <?php selected($instance['layout'], 'vertical'); ?>>
                    <?php _e('Vertical', 'mlcm'); ?>
                </option>
                <option value="horizontal" <?php selected($instance['layout'], 'horizontal'); ?>>
                    <?php _e('Horizontal', 'mlcm'); ?>
                </option>
            </select>
        </p>
        
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('levels')); ?>">
                <?php _e('Number of Levels:', 'mlcm'); ?>
            </label>
            <select class="widefat" 
                    id="<?php echo esc_attr($this->get_field_id('levels')); ?>" 
                    name="<?php echo esc_attr($this->get_field_name('levels')); ?>">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <option value="<?php echo $i; ?>" <?php selected($instance['levels'], $i); ?>>
                        <?php echo $i; ?> <?php echo ($i == 1) ? __('Level', 'mlcm') : __('Levels', 'mlcm'); ?>
                    </option>
                <?php endfor; ?>
            </select>
        </p>
        
        <p>
            <input class="checkbox" 
                   type="checkbox" 
                   <?php checked($instance['show_button'], '1'); ?>
                   id="<?php echo esc_attr($this->get_field_id('show_button')); ?>" 
                   name="<?php echo esc_attr($this->get_field_name('show_button')); ?>" 
                   value="1" />
            <label for="<?php echo esc_attr($this->get_field_id('show_button')); ?>">
                <?php _e('Show Go Button', 'mlcm'); ?>
            </label>
        </p>
        
        <p class="description">
            <?php _e('This widget uses the Multi-Level Category Menu plugin settings as defaults. Widget-specific settings will override global settings for this instance only.', 'mlcm'); ?>
        </p>
        <?php
    }
    
    /**
     * Обновление виджета
     */
    public function update($new_instance, $old_instance) {
        $instance = [];
        
        // Санитизация данных
        $instance['title'] = sanitize_text_field($new_instance['title']);
        $instance['layout'] = in_array($new_instance['layout'], ['vertical', 'horizontal']) 
            ? $new_instance['layout'] 
            : 'vertical';
        $instance['levels'] = absint($new_instance['levels']);
        if ($instance['levels'] < 1 || $instance['levels'] > 5) {
            $instance['levels'] = 3;
        }
        $instance['show_button'] = !empty($new_instance['show_button']) ? '1' : '0';
        
        // Очищаем кеш при обновлении настроек виджета
        if (class_exists('Multi_Level_Category_Menu')) {
            $mlcm = Multi_Level_Category_Menu::get_instance();
            if (method_exists($mlcm, 'clear_all_caches')) {
                $mlcm->clear_all_caches();
            }
        }
        
        return $instance;
    }
}