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
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(
                esc_html__('MRAF Plugin requires WordPress 5.0 or higher. Please update WordPress.', 'mraf'),
                esc_html__('Plugin Activation Error', 'mraf'),
                array('back_link' => true)
            );
        }

        if (version_compare(PHP_VERSION, '7.4', '<')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(
                esc_html__('MRAF Plugin requires PHP 7.4 or higher. Please contact your hosting provider.', 'mraf'),
                esc_html__('Plugin Activation Error', 'mraf'),
                array('back_link' => true)
            );
        }

        if (!get_option('mraf_plugin_version')) {
            add_option('mraf_plugin_version', MRAF_VERSION);
        }

        $default_options = array(
            'keep_basename' => true,
            'update_refs' => true,
            'create_backup' => true,
            'debug_mode' => false,
        );

        if (!get_option('mraf_plugin_options')) {
            add_option('mraf_plugin_options', $default_options);
        }

        $backup_dir = WP_CONTENT_DIR . '/mraf-backups';
        if (!file_exists($backup_dir)) {
            wp_mkdir_p($backup_dir);
            file_put_contents($backup_dir . '/.htaccess', "Order Deny,Allow\nDeny from all");
            file_put_contents($backup_dir . '/index.php', '<?php // Silence is golden');
        }

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
