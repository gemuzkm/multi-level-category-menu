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
        echo do_shortcode('[mlcm_menu]');
        echo $args['after_widget'];
    }

    public function form($instance) {
        $title = !empty($instance['title']) ? $instance['title'] : '';
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
        <?php
    }

    public function update($new_instance, $old_instance) {
        $instance = [];
        $instance['title'] = sanitize_text_field($new_instance['title']);
        return $instance;
    }
}