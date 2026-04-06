<?php

if (!defined('ABSPATH')) {
    exit;
}

class AIPI_Capabilities
{
    const CAPABILITY = 'manage_ai_product_intake';

    public static function init()
    {
        add_action('init', [__CLASS__, 'add_caps']);
    }

    public static function activate()
    {
        self::add_caps();
    }

    public static function deactivate()
    {
        // Intentionally do not remove caps to avoid breaking roles unexpectedly
    }

    public static function add_caps()
    {
        $roles = ['administrator', 'shop_manager'];

        foreach ($roles as $role_name) {
            $role = get_role($role_name);
            if ($role && !$role->has_cap(self::CAPABILITY)) {
                $role->add_cap(self::CAPABILITY);
            }
        }
    }

    public static function current_user_can_manage()
    {
        return current_user_can(self::CAPABILITY);
    }
}
