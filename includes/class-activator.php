<?php
/**
 * Fired during plugin activation
 *
 * @package    MRAF
 * @subpackage MRAF/includes
 */

if (!defined('ABSPATH')) {
    exit;
}

class MRAF_Activator
{
    public static function activate()
    {
        if (version_compare(get_bloginfo('version'), '5.0', '<')) {
            deactivate_plugins(MRAF_PLUGIN_BASENAME);
            wp_die(
                esc_html__('MRAF Plugin requires WordPress 5.0 or higher. Please update WordPress.', 'mraf'),
                esc_html__('Plugin Activation Error', 'mraf'),
                array('back_link' => true)
            );
        }

        if (version_compare(PHP_VERSION, '7.4', '<')) {
            deactivate_plugins(MRAF_PLUGIN_BASENAME);
            wp_die(
                esc_html__('MRAF Plugin requires PHP 7.4 or higher. Please contact your hosting provider.', 'mraf'),
                esc_html__('Plugin Activation Error', 'mraf'),
                array('back_link' => true)
            );
        }

        if (!get_option('mraf_plugin_version')) {
            add_option('mraf_plugin_version', MRAF_VERSION);
        }

        require_once MRAF_PLUGIN_DIR . 'includes/class-image-replacer.php';

        wp_mkdir_p(MRAF_Image_Replacer::backup_root());
        MRAF_Image_Replacer::protect_backup_dir();

        flush_rewrite_rules();

        $activation_log = array(
            'version' => MRAF_VERSION,
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'activated_at' => current_time('mysql'),
            'activated_by' => get_current_user_id(),
        );
        update_option('mraf_last_activation', $activation_log);
    }
}
