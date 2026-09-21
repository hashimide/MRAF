<?php
/**
 * Fired when the plugin is uninstalled
 *
 * @package MRAF
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Remove everything MRAF wrote to one site.
 */
function mraf_uninstall_site()
{
    global $wpdb;

    $options = array(
        // Current.
        'mraf_studio_options',
        'mraf_show_debug_tab',
        'mraf_bytes_saved',
        'mraf_plugin_version',
        'mraf_last_activation',
        'mraf_last_deactivation',
        // Written by versions before 3.0.
        'mraf_plugin_options',
    );

    foreach ($options as $option) {
        delete_option($option);
    }

    // Per-attachment state.
    foreach (array('_mraf_converted', '_mraf_replaced_at', '_mraf_last_replaced') as $meta_key) {
        delete_post_meta_by_key($meta_key);
    }

    $wpdb->query(
        "DELETE FROM {$wpdb->options}
        WHERE option_name LIKE '_transient_mraf_%'
        OR option_name LIKE '_transient_timeout_mraf_%'"
    );
}

/**
 * Delete a directory and everything in it.
 *
 * @param string $dir Absolute path.
 */
function mraf_uninstall_rmdir($dir)
{
    if (!is_dir($dir)) {
        return;
    }

    $items = glob($dir . '/*');

    foreach ($items ? $items : array() as $item) {
        if (is_dir($item)) {
            mraf_uninstall_rmdir($item);
            continue;
        }

        @unlink($item);
    }

    // glob() skips dotfiles, and the backup directory is guarded by one.
    foreach (array('.htaccess', '.gitignore') as $hidden) {
        if (file_exists($dir . '/' . $hidden)) {
            @unlink($dir . '/' . $hidden);
        }
    }

    @rmdir($dir);
}

mraf_uninstall_site();

if (is_multisite()) {
    global $wpdb;

    foreach (array('mraf_plugin_version', 'mraf_plugin_options', 'mraf_last_activation', 'mraf_last_deactivation') as $option) {
        delete_site_option($option);
    }

    $blog_ids = $wpdb->get_col("SELECT blog_id FROM {$wpdb->blogs}");

    foreach ($blog_ids as $blog_id) {
        switch_to_blog($blog_id);
        mraf_uninstall_site();

        // Every site has its own uploads directory, so the staging folder
        // has to be cleared from inside each one.
        $uploads = wp_get_upload_dir();
        mraf_uninstall_rmdir(trailingslashit($uploads['basedir']) . 'mraf-temp');

        restore_current_blog();
    }
}

$uploads = wp_get_upload_dir();
mraf_uninstall_rmdir(trailingslashit($uploads['basedir']) . 'mraf-temp');

// The backups are the only copy of the originals, so they go last and only
// once every option that could point at them is gone.
mraf_uninstall_rmdir(WP_CONTENT_DIR . '/mraf-backups');

wp_cache_flush();
