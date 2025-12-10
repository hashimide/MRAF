<?php
/**
 * Fired when the plugin is uninstalled
 *
 * @package MRAF
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('mraf_plugin_version');
delete_option('mraf_plugin_options');
delete_option('mraf_last_activation');
delete_option('mraf_last_deactivation');

global $wpdb;
$wpdb->query(
    "DELETE FROM {$wpdb->options}
    WHERE option_name LIKE '_transient_mraf_%'
    OR option_name LIKE '_transient_timeout_mraf_%'"
);

if (is_multisite()) {
    delete_site_option('mraf_plugin_version');
    delete_site_option('mraf_plugin_options');
    delete_site_option('mraf_last_activation');
    delete_site_option('mraf_last_deactivation');

    $blog_ids = $wpdb->get_col("SELECT blog_id FROM {$wpdb->blogs}");
    foreach ($blog_ids as $blog_id) {
        switch_to_blog($blog_id);

        delete_option('mraf_plugin_version');
        delete_option('mraf_plugin_options');
        delete_option('mraf_last_activation');
        delete_option('mraf_last_deactivation');

        $wpdb->query(
            "DELETE FROM {$wpdb->options}
            WHERE option_name LIKE '_transient_mraf_%'
            OR option_name LIKE '_transient_timeout_mraf_%'"
        );

        restore_current_blog();
    }
}

wp_cache_flush();
