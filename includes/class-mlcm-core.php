<?php
class MLCM_Core {
    private static $instance;
    
    public static function get_instance() {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $this->load_dependencies();
        $this->setup_hooks();
    }

    private function load_dependencies() {
        require_once plugin_dir_path(__DIR__) . 'includes/class-mlcm-cache.php';
        require_once plugin_dir_path(__DIR__) . 'includes/class-mlcm-assets.php';
        require_once plugin_dir_path(__DIR__) . 'includes/class-mlcm-admin.php';
        require_once plugin_dir_path(__DIR__) . 'includes/class-mlcm-frontend.php';
    }

    private function setup_hooks() {
        add_action('init', [$this, 'init']);
    }

    public function init() {
        MLCM_Cache::get_instance();
        MLCM_Assets::get_instance();
        MLCM_Admin::get_instance();
        MLCM_Frontend::get_instance();
    }
}