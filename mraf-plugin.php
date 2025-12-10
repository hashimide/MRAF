<?php
/**
 * Plugin Name:       Media Replace Any Format (MRAF)
 * Plugin URI:        https://yourwebsite.com/mraf-plugin
 * Description:       Replace media files with any image format including WebP. Supports single and bulk replacement with automatic reference updates.
 * Version:           2.0.4
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * Author:            Hashim Ali
 * Author URI:        https://yourwebsite.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       mraf
 * Domain Path:       /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MRAF_VERSION', '2.0.4');
define('MRAF_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MRAF_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MRAF_PLUGIN_BASENAME', plugin_basename(__FILE__));

function activate_mraf_plugin()
{
    require_once MRAF_PLUGIN_DIR . 'includes/class-activator.php';
    MRAF_Activator::activate();
}

function deactivate_mraf_plugin()
{
    require_once MRAF_PLUGIN_DIR . 'includes/class-deactivator.php';
    MRAF_Deactivator::deactivate();
}

register_activation_hook(__FILE__, 'activate_mraf_plugin');
register_deactivation_hook(__FILE__, 'deactivate_mraf_plugin');

require_once MRAF_PLUGIN_DIR . 'includes/class-core.php';

function run_mraf_plugin()
{
    $plugin = new MRAF_Core();
    $plugin->run();
}

run_mraf_plugin();
