<?php
/*
Plugin Name: Multi-Level Category Menu
Description: Creates customizable category menus with 5-level depth
Version: 3.4
Author: TM
Text Domain: mlcm
*/

defined('ABSPATH') || exit;

require_once plugin_dir_path(__FILE__) . 'includes/class-mlcm-core.php';

function mlcm_init() {
    return MLCM_Core::get_instance();
}

mlcm_init();