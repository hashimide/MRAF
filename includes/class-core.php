<?php
/**
 * The core plugin class
 *
 * @package    MRAF
 * @subpackage MRAF/includes
 */

if (!defined('ABSPATH')) {
    exit;
}

class MRAF_Core
{
    protected $plugin_name;
    protected $version;

    public function __construct()
    {
        $this->plugin_name = 'mraf';
        $this->version = defined('MRAF_VERSION') ? MRAF_VERSION : '3.0.0';

        $this->load_dependencies();
        $this->set_locale();
        $this->define_admin_hooks();
    }

    private function load_dependencies()
    {
        require_once MRAF_PLUGIN_DIR . 'includes/class-image-replacer.php';

        if (is_admin()) {
            require_once MRAF_PLUGIN_DIR . 'admin/class-admin.php';
            require_once MRAF_PLUGIN_DIR . 'admin/class-media-studio.php';
            require_once MRAF_PLUGIN_DIR . 'admin/class-library-studio.php';
            require_once MRAF_PLUGIN_DIR . 'admin/class-image-usage.php';
        }
    }

    private function set_locale()
    {
        add_action('plugins_loaded', array($this, 'load_plugin_textdomain'));
    }

    public function load_plugin_textdomain()
    {
        load_plugin_textdomain(
            'mraf',
            false,
            dirname(MRAF_PLUGIN_BASENAME) . '/languages/'
        );
    }

    private function define_admin_hooks()
    {
        if (!is_admin()) {
            return;
        }

        // Each class registers its own hooks in its constructor. The same five
        // filters used to be added again here, which was simply a second copy
        // of the list to keep in step.
        new MRAF_Admin($this->get_plugin_name(), $this->get_version());
        new MRAF_Media_Studio();
        new MRAF_Image_Usage();
        new MRAF_Library_Studio();
    }

    public function get_plugin_name()
    {
        return $this->plugin_name;
    }

    public function get_version()
    {
        return $this->version;
    }

    public function run()
    {
        do_action('mraf_plugin_loaded');
    }
}
