<?php
defined('ABSPATH') || exit;

class MLCM_Widget extends WP_Widget {
    public function __construct() {
        parent::__construct(
            'mlcm_widget',
            __('Category Menu', 'mlcm'),
            ['description' => __('Multi-level category menu', 'mlcm')]
        );
    }

    public function widget($args, $instance) {
        echo $args['before_widget'];
        if (!empty($instance['title'])) {
            echo $args['before_title'].apply_filters(
                'widget_title', $instance['title']).$args['after_title'];
        }
        
        $use_modal = !empty($instance['use_modal']) ? $instance['use_modal'] : false;
        
        if ($use_modal) {
            echo do_shortcode('[mlcm_modal_button]');
        } else {
            echo do_shortcode('[mlcm_menu]');
        }
        
        echo $args['after_widget'];
    }

    public function form($instance) {
        $title = !empty($instance['title']) ? $instance['title'] : '';
        $use_modal = !empty($instance['use_modal']) ? $instance['use_modal'] : false;
        ?>
        <p>
            <label for="<?php echo $this->get_field_id('title'); ?>">
                <?php _e('Title:', 'mlcm'); ?>
            </label>
            <input class="widefat" 
                   id="<?php echo $this->get_field_id('title'); ?>"
                   name="<?php echo $this->get_field_name('title'); ?>" 
                   type="text" 
                   value="<?php echo esc_attr($title); ?>">
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('use_modal'); ?>">
                <input type="checkbox" 
                       id="<?php echo $this->get_field_id('use_modal'); ?>"
                       name="<?php echo $this->get_field_name('use_modal'); ?>" 
                       value="1" <?php checked($use_modal, 1); ?>>
                <?php _e('Use modal window', 'mlcm'); ?>
            </label>
        </p>
        <?php
    }

    public function update($new_instance, $old_instance) {
        $instance = [];
        $instance['title'] = sanitize_text_field($new_instance['title']);
        $instance['use_modal'] = !empty($new_instance['use_modal']) ? 1 : 0;
        return $instance;
    }
}