<?php
/**
 * Multi-Level Category Menu Widget
 * 
 * Provides WordPress widget functionality for displaying category menus
 * in sidebars and widget areas with customizable settings.
 * 
 * @package Multi_Level_Category_Menu
 * @since 3.0
 */

// Exit if accessed directly - security measure
defined('ABSPATH') || exit;

/**
 * MLCM Widget Class
 * 
 * Creates a WordPress widget for displaying multi-level category menus
 * with options for title, layout, levels, and button display.
 * 
 * @extends WP_Widget
 */
class MLCM_Widget extends WP_Widget {
    
    /**
     * Widget constructor
     * 
     * Sets up widget with proper options, description, and control settings
     * following WordPress widget development standards.
     */
    public function __construct() {
        // Widget display options
        $widget_ops = [
            'classname' => 'mlcm_widget',
            'description' => __('Multi-level category menu for navigation', 'mlcm'),
            'customize_selective_refresh' => true, // Enable selective refresh in customizer
        ];
        
        // Widget control panel options
        $control_ops = [
            'width' => 300,
            'height' => 350,
            'id_base' => 'mlcm_widget'
        ];
        
        // Initialize parent widget class
        parent::__construct(
            'mlcm_widget', // Base ID - unique identifier
            __('Category Menu', 'mlcm'), // Widget name displayed in admin
            $widget_ops,
            $control_ops
        );
    }
    
    /**
     * Frontend widget display
     * 
     * Renders the widget output on the frontend with user-configured settings.
     * Temporarily overrides global plugin settings with widget-specific settings.
     * 
     * @param array $args Widget display arguments from register_sidebar()
     * @param array $instance Widget instance settings from form
     */
    public function widget($args, $instance) {
        // Extract widget settings with defaults
        $title = !empty($instance['title']) ? $instance['title'] : '';
        $layout = !empty($instance['layout']) ? $instance['layout'] : get_option('mlcm_menu_layout', 'vertical');
        $levels = !empty($instance['levels']) ? absint($instance['levels']) : absint(get_option('mlcm_initial_levels', 3));
        $show_button = !empty($instance['show_button']) ? '1' : '0';
        
        // Apply WordPress title filter for theme compatibility
        $title = apply_filters('widget_title', $title, $instance, $this->id_base);
        
        // Output widget wrapper
        echo $args['before_widget'];
        
        // Display title if provided
        if (!empty($title)) {
            echo $args['before_title'] . esc_html($title) . $args['after_title'];
        }
        
        // Temporarily override global settings with widget settings
        $original_layout = get_option('mlcm_menu_layout');
        $original_levels = get_option('mlcm_initial_levels');
        $original_button = get_option('mlcm_show_button');
        
        // Set widget-specific options
        update_option('mlcm_menu_layout', $layout);
        update_option('mlcm_initial_levels', $levels);
        update_option('mlcm_show_button', $show_button);
        
        // Generate and display the menu
        echo do_shortcode('[mlcm_menu layout="' . esc_attr($layout) . '" levels="' . absint($levels) . '"]');
        
        // Restore original global settings
        update_option('mlcm_menu_layout', $original_layout);
        update_option('mlcm_initial_levels', $original_levels);
        update_option('mlcm_show_button', $original_button);
        
        // Close widget wrapper
        echo $args['after_widget'];
    }
    
    /**
     * Widget admin form
     * 
     * Creates the configuration form displayed in the WordPress admin
     * when adding or editing the widget in the customizer or widgets page.
     * 
     * @param array $instance Current widget instance settings
     */
    public function form($instance) {
        // Set default values for new widget instances
        $defaults = [
            'title' => '',
            'layout' => get_option('mlcm_menu_layout', 'vertical'),
            'levels' => absint(get_option('mlcm_initial_levels', 3)),
            'show_button' => '0'
        ];
        
        // Parse instance data with defaults
        $instance = wp_parse_args((array) $instance, $defaults);
        
        // Generate form fields
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
            <input type="checkbox" 
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
     * Widget settings update
     * 
     * Processes and sanitizes form data when widget settings are saved.
     * Includes validation and cache clearing for optimal performance.
     * 
     * @param array $new_instance New widget settings from form
     * @param array $old_instance Previous widget settings
     * @return array Sanitized widget settings to save
     */
    public function update($new_instance, $old_instance) {
        $instance = [];
        
        // Sanitize title input
        $instance['title'] = sanitize_text_field($new_instance['title']);
        
        // Validate and sanitize layout option
        $instance['layout'] = in_array($new_instance['layout'], ['vertical', 'horizontal']) 
            ? $new_instance['layout'] 
            : 'vertical';
        
        // Validate and sanitize levels (1-5 range)
        $instance['levels'] = absint($new_instance['levels']);
        if ($instance['levels'] < 1 || $instance['levels'] > 5) {
            $instance['levels'] = 3; // Default fallback
        }
        
        // Sanitize checkbox input
        $instance['show_button'] = !empty($new_instance['show_button']) ? '1' : '0';
        
        // Clear plugin cache when widget settings change
        if (class_exists('Multi_Level_Category_Menu')) {
            $mlcm = Multi_Level_Category_Menu::get_instance();
            if (method_exists($mlcm, 'clear_all_caches')) {
                $mlcm->clear_all_caches();
            }
        }
        
        return $instance;
    }
}