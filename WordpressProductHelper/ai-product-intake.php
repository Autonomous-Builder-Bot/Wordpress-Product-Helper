<?php
/**
 * Plugin Name: AI Product Intake
 * Plugin URI: https://github.com/Autonomous-Builder-Bot/Wordpress-Product-Helper
 * Description: Admin-only WooCommerce product intake workflow using image uploads and AI-assisted draft generation.
 * Version: 1.0.0
 * Author: Autonomous Builder Bot
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Text Domain: ai-product-intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'AIPI_VERSION' ) ) {
	define( 'AIPI_VERSION', '1.0.0' );
}

if ( ! defined( 'AIPI_PLUGIN_FILE' ) ) {
	define( 'AIPI_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'AIPI_PLUGIN_BASENAME' ) ) {
	define( 'AIPI_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
}

if ( ! defined( 'AIPI_PLUGIN_DIR' ) ) {
	define( 'AIPI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'AIPI_PLUGIN_URL' ) ) {
	define( 'AIPI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

require_once AIPI_PLUGIN_DIR . 'includes/class-aipi-plugin.php';

register_activation_hook( __FILE__, array( 'AIPI_Capabilities', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AIPI_Capabilities', 'deactivate' ) );

function aipi_boot_plugin() {
	$plugin = new AIPI_Plugin();
	$plugin->init();
}

add_action( 'plugins_loaded', 'aipi_boot_plugin' );
