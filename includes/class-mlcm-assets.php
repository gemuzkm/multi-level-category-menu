<?php
class MLCM_Assets {
    private static $instance;
    
    public static function get_instance() {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_block_editor_assets']);
    }

    public function enqueue_frontend_assets() {
        wp_enqueue_style('mlcm-frontend', plugins_url('assets/css/frontend.css', dirname(__FILE__)));
        wp_enqueue_script('mlcm-frontend', plugins_url('assets/js/frontend.js', dirname(__FILE__)), ['jquery'], null, true);
        
        wp_localize_script('mlcm-frontend', 'mlcmVars', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mlcm_nonce'),
            'labels' => $this->get_level_labels(),
            'use_category_base' => get_option('mlcm_use_category_base', '1') === '1',
        ]);

        $this->add_inline_styles();
    }

    private function get_level_labels() {
        return array_map(function($i) {
            return get_option("mlcm_level_{$i}_label", "Level {$i}");
        }, range(1,5));
    }

    private function add_inline_styles() {
        $styles = $this->generate_custom_styles();
        if (!empty($styles)) {
            wp_add_inline_style('mlcm-frontend', $styles);
        }
    }

    private function generate_custom_styles() {
        $styles = [];
        $options = [
            'font_size' => ['selector' => '.mlcm-select', 'property' => 'font-size', 'unit' => 'rem'],
            'container_gap' => ['selector' => '.mlcm-container', 'property' => 'gap', 'unit' => 'px'],
            'button_bg_color' => ['selector' => '.mlcm-go-button', 'property' => 'background'],
            'button_font_size' => ['selector' => '.mlcm-go-button', 'property' => 'font-size', 'unit' => 'rem'],
            'button_hover_bg_color' => ['selector' => '.mlcm-go-button:hover', 'property' => 'background']
        ];

        foreach ($options as $key => $data) {
            $value = get_option("mlcm_{$key}", '');
            if (!empty($value)) {
                $unit = isset($data['unit']) ? $data['unit'] : '';
                $styles[] = sprintf('%s { %s: %s%s; }', 
                    $data['selector'], 
                    $data['property'], 
                    $value, 
                    $unit
                );
            }
        }

        return implode("\n", $styles);
    }
}