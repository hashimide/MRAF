<?php
/**
 * Fired during plugin deactivation
 *
 * @package    MRAF
 * @subpackage MRAF/includes
 */

if (!defined('ABSPATH')) {
    exit;
}

class MRAF_Deactivator
{
    public static function deactivate()
    {
        global $wpdb;

        $wpdb->query(
            "DELETE FROM {$wpdb->options}
            WHERE option_name LIKE '_transient_mraf_%'
            OR option_name LIKE '_transient_timeout_mraf_%'"
        );

        wp_cache_flush();
        flush_rewrite_rules();

        $deactivation_log = array(
            'version' => defined('MRAF_VERSION') ? MRAF_VERSION : 'unknown',
            'deactivated_at' => current_time('mysql'),
            'deactivated_by' => get_current_user_id(),
        );
        update_option('mraf_last_deactivation', $deactivation_log);
    }
}
