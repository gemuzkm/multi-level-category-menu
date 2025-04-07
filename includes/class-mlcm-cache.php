<?php
class MLCM_Cache {
    private static $instance;
    
    public static function get_instance() {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action('edited_category', [$this, 'clear_related_cache']);
        add_action('create_category', [$this, 'clear_related_cache']);
    }

    public function get_categories($args = []) {
        $cache_key = $this->generate_cache_key($args);
        $cache = get_transient($cache_key);

        if (false === $cache) {
            $cache = $this->fetch_and_cache_categories($args);
        }

        return $cache;
    }

    private function generate_cache_key($args) {
        if (!empty($args['parent'])) {
            return 'mlcm_subcats_' . absint($args['parent']);
        }
        return 'mlcm_root_cats';
    }

    private function fetch_and_cache_categories($args) {
        $defaults = [
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
            'fields' => 'all'
        ];

        $args = wp_parse_args($args, $defaults);
        $categories = get_categories($args);

        $cache = [];
        foreach ($categories as $category) {
            $cache[$category->term_id] = [
                'name' => strtoupper(htmlspecialchars_decode($category->name)),
                'slug' => $category->slug,
                'url' => get_category_link($category->term_id)
            ];
        }

        $cache_key = $this->generate_cache_key($args);
        set_transient($cache_key, $cache, WEEK_IN_SECONDS);

        return $cache;
    }

    public function clear_related_cache($term_id) {
        $term = get_term($term_id);
        if ($term) {
            delete_transient("mlcm_subcats_{$term->term_id}");
            if ($term->parent != 0) {
                delete_transient("mlcm_subcats_{$term->parent}");
            } else {
                delete_transient('mlcm_root_cats');
            }
        }
    }

    public function clear_all_caches() {
        global $wpdb;
        delete_transient('mlcm_root_cats');
        return $wpdb->query(
            "DELETE FROM $wpdb->options 
            WHERE option_name LIKE '_transient_mlcm_subcats_%' 
            OR option_name LIKE '_transient_timeout_mlcm_subcats_%'"
        );
    }
}