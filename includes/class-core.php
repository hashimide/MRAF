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
        $this->version = defined('MRAF_VERSION') ? MRAF_VERSION : '1.3.2';

        $this->load_dependencies();
        $this->set_locale();
        $this->define_admin_hooks();
    }

    private function load_dependencies()
    {
        require_once MRAF_PLUGIN_DIR . 'includes/class-image-replacer.php';

        if (is_admin()) {
            require_once MRAF_PLUGIN_DIR . 'admin/class-admin.php';
            require_once MRAF_PLUGIN_DIR . 'admin/class-bulk-replace.php';
            require_once MRAF_PLUGIN_DIR . 'admin/class-image-usage.php';
            require_once MRAF_PLUGIN_DIR . 'admin/class-image-converter.php';
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

        $admin = new MRAF_Admin($this->get_plugin_name(), $this->get_version());
        $bulk_replace = new MRAF_Bulk_Replace();
        $image_usage = new MRAF_Image_Usage();
        $image_converter = new MRAF_Image_Converter();

        add_filter('upload_mimes', array($admin, 'allow_webp_mime'));
        add_action('post_edit_form_tag', array($admin, 'force_multipart_form'));
        add_action('add_meta_boxes_attachment', array($admin, 'add_metaboxes'));
        add_action('load-post.php', array($admin, 'maybe_handle_replace_on_edit_screen'));
        add_action('admin_notices', array($admin, 'admin_notices'));
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
