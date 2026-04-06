<?php

if (!defined('ABSPATH')) {
    exit;
}

class AIPI_Plugin
{
    public function init()
    {
        $this->load_dependencies();
        $this->init_components();
    }

    private function load_dependencies()
    {
        require_once AIPI_PLUGIN_DIR . 'includes/class-aipi-settings.php';
        require_once AIPI_PLUGIN_DIR . 'includes/class-aipi-admin.php';
        require_once AIPI_PLUGIN_DIR . 'includes/class-aipi-capabilities.php';
        require_once AIPI_PLUGIN_DIR . 'includes/class-aipi-utils.php';
        require_once AIPI_PLUGIN_DIR . 'includes/class-aipi-validator.php';
        require_once AIPI_PLUGIN_DIR . 'includes/class-aipi-media.php';
        require_once AIPI_PLUGIN_DIR . 'includes/class-aipi-openai.php';
        require_once AIPI_PLUGIN_DIR . 'includes/class-aipi-ai-normalizer.php';
        require_once AIPI_PLUGIN_DIR . 'includes/class-aipi-product-factory.php';
        require_once AIPI_PLUGIN_DIR . 'includes/class-aipi-draft-store.php';
    }

    private function init_components()
    {
        AIPI_Capabilities::init();
        AIPI_Settings::init();
        new AIPI_Admin();
    }
}
