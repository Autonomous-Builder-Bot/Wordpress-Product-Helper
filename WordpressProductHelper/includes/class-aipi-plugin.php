<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIPI_Plugin {

	public function init() {
		$this->load_dependencies();

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'render_woocommerce_notice' ) );
			return;
		}

		AIPI_Capabilities::init();
		AIPI_Settings::init();
		AIPI_Logger::init();

		new AIPI_Admin();
	}

	private function load_dependencies() {
		require_once AIPI_PLUGIN_DIR . 'includes/class-aipi-capabilities.php';
		require_once AIPI_PLUGIN_DIR . 'includes/class-aipi-utils.php';
		require_once AIPI_PLUGIN_DIR . 'includes/class-aipi-admin-notices.php';
		require_once AIPI_PLUGIN_DIR . 'includes/class-aipi-settings.php';
		require_once AIPI_PLUGIN_DIR . 'includes/class-aipi-draft-store.php';
		require_once AIPI_PLUGIN_DIR . 'includes/class-aipi-logger.php';
		require_once AIPI_PLUGIN_DIR . 'includes/class-aipi-prompt-builder.php';
		require_once AIPI_PLUGIN_DIR . 'includes/class-aipi-ai-normalizer.php';
		require_once AIPI_PLUGIN_DIR . 'includes/class-aipi-openai.php';
		require_once AIPI_PLUGIN_DIR . 'includes/class-aipi-media.php';
		require_once AIPI_PLUGIN_DIR . 'includes/class-aipi-taxonomy.php';
		require_once AIPI_PLUGIN_DIR . 'includes/class-aipi-validator.php';
		require_once AIPI_PLUGIN_DIR . 'includes/class-aipi-product-factory.php';
		require_once AIPI_PLUGIN_DIR . 'includes/class-aipi-admin.php';
	}

	public function render_woocommerce_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>' . esc_html__( 'AI Product Intake requires WooCommerce to be installed and active.', 'ai-product-intake' ) . '</p></div>';
	}
}
