<?php
/**
 * Handles caching functionality for category menus
 * @since 3.4
 */
class MLCM_Cache {
    private static $instance;
    private const CACHE_DURATION = WEEK_IN_SECONDS;
    private const CACHE_PREFIX = 'mlcm_';
    private const MAX_CACHE_ENTRIES = 1000; // Prevent cache flooding
    
    /** @var array Holds runtime cache */
    private $memory_cache = [];

    /**
     * Get singleton instance with lazy loading
     * @return self
     */
    public static function get_instance(): self {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - sets up hooks and initializes cache pruning
     */
    public function __construct() {
        // Category modification hooks
        add_action('edited_category', [$this, 'clear_related_cache']);
        add_action('create_category', [$this, 'clear_related_cache']);
        add_action('delete_category', [$this, 'clear_related_cache']);
        
        // Schedule cache cleanup for expired items only
        if (!wp_next_scheduled('mlcm_cache_cleanup')) {
            wp_schedule_event(time(), 'daily', 'mlcm_cache_cleanup');
        }
        add_action('mlcm_cache_cleanup', [$this, 'cleanup_old_caches']);
    }

    /**
     * Get categories with multi-level caching strategy
     * @param array $args Query arguments
     * @return array Cached or fresh category data
     */
    public function get_categories(array $args = []): array {
        $cache_key = $this->generate_cache_key($args);

        // Check memory cache first
        if (isset($this->memory_cache[$cache_key])) {
            return $this->memory_cache[$cache_key];
        }

        // Check transient cache
        $cached_data = get_transient($cache_key);
        if (false !== $cached_data) {
            $this->memory_cache[$cache_key] = $cached_data;
            return $cached_data;
        }

        // Fetch fresh data
        $data = $this->fetch_and_cache_categories($args);
        $this->memory_cache[$cache_key] = $data;
        
        return $data;
    }

    /**
     * Generate secure cache key based on arguments
     * @param array $args Query arguments
     * @return string Cache key
     */
    private function generate_cache_key(array $args): string {
        $key_parts = [self::CACHE_PREFIX];
        
        if (!empty($args['parent'])) {
            $key_parts[] = 'subcats_' . absint($args['parent']);
        } else {
            $key_parts[] = 'root_cats';
        }

        if (!empty($args['orderby'])) {
            $key_parts[] = sanitize_key($args['orderby']);
        }

        $key = implode('_', $key_parts);
        return (string) apply_filters('mlcm_cache_key', $key, $args);
    }

    /**
     * Fetch and cache categories with error handling
     * @param array $args Query arguments
     * @return array Category data
     * @throws RuntimeException If categories cannot be retrieved
     */
    private function fetch_and_cache_categories(array $args): array {
        $defaults = [
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
            'fields' => 'all'
        ];

        $args = wp_parse_args($args, $defaults);

        try {
            $categories = get_categories($args);
            if (is_wp_error($categories)) {
                throw new RuntimeException($categories->get_error_message());
            }

            $cache = $this->format_categories_for_cache($categories);
            $cache_key = $this->generate_cache_key($args);
            
            set_transient($cache_key, $cache, self::CACHE_DURATION);
            
            return $cache;
        } catch (Exception $e) {
            error_log('MLCM Cache Error: ' . $e->getMessage());
            return []; // Return empty array instead of failing
        }
    }

    /**
     * Format categories for caching with sanitization
     * @param array $categories Raw category data
     * @return array Formatted category data
     */
    private function format_categories_for_cache(array $categories): array {
        return array_reduce($categories, function($cache, $category) {
            $cache[$category->term_id] = [
                'name' => strtoupper(wp_kses(
                    html_entity_decode($category->name, ENT_QUOTES, 'UTF-8'),
                    ['b' => [], 'em' => [], 'i' => [], 'strong' => []]
                )),
                'slug' => sanitize_title($category->slug),
                'url' => esc_url(get_category_link($category->term_id))
            ];
            return $cache;
        }, []);
    }

    /**
     * Get current cache entries count
     * @return int Number of cache entries
     */
    private function get_cache_entries_count(): int {
        global $wpdb;
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $wpdb->options WHERE option_name LIKE %s",
            $wpdb->esc_like('_transient_' . self::CACHE_PREFIX) . '%'
        ));
        
        return (int) $count;
    }

    /**
     * Cleanup old caches
     * @return int Number of entries cleaned
     */
    public function cleanup_old_caches(): int {
        global $wpdb;
        
        // Delete expired transients first
        $wpdb->query(
            "DELETE FROM $wpdb->options 
            WHERE option_name LIKE '_transient_timeout_" . self::CACHE_PREFIX . "%' 
            AND option_value < " . time()
        );

        // Then delete corresponding transient values
        $deleted = $wpdb->query(
            "DELETE FROM $wpdb->options 
            WHERE option_name LIKE '_transient_" . self::CACHE_PREFIX . "%' 
            AND option_name NOT IN (
                SELECT REPLACE(option_name, '_timeout', '')
                FROM $wpdb->options
                WHERE option_name LIKE '_transient_timeout_" . self::CACHE_PREFIX . "%'
            )"
        );

        // Clear memory cache
        $this->memory_cache = [];
        
        return (int) $deleted;
    }

    /**
     * Clear cache for a specific category and its parent
     * @param int $term_id Category ID
     */
    public function clear_related_cache($term_id) {
        $term = get_term($term_id);
        if (!$term || is_wp_error($term)) {
            return;
        }

        // Clear cache for this category
        delete_transient(self::CACHE_PREFIX . "subcats_{$term->term_id}");
        
        // Clear parent category cache if exists
        if ($term->parent != 0) {
            delete_transient(self::CACHE_PREFIX . "subcats_{$term->parent}");
        } else {
            delete_transient(self::CACHE_PREFIX . 'root_cats');
        }
    }

    /**
     * Clear all category caches
     * @return int Number of caches cleared
     */
    public function clear_all_caches() {
        global $wpdb;
        
        // Clear root categories cache
        delete_transient(self::CACHE_PREFIX . 'root_cats');
        
        // Clear all subcategory caches
        $sql = $wpdb->prepare(
            "DELETE FROM $wpdb->options 
            WHERE option_name LIKE %s 
            OR option_name LIKE %s",
            $wpdb->esc_like('_transient_' . self::CACHE_PREFIX . 'subcats_') . '%',
            $wpdb->esc_like('_transient_timeout_' . self::CACHE_PREFIX . 'subcats_') . '%'
        );
        
        return $wpdb->query($sql);
    }
}