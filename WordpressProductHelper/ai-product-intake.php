<?php
/**
 * Plugin Name: AI Product Intake
 * Description: Admin-only WooCommerce product intake workflow with AI-assisted draft generation.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AIPI_VERSION', '1.0.0');
define('AIPI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AIPI_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once AIPI_PLUGIN_DIR . 'includes/class-aipi-plugin.php';

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>AI Product Intake requires WooCommerce.</p></div>';
        });
        return;
    }

    $plugin = new AIPI_Plugin();
    $plugin->init();
});
