<?php
/**
 * Plugin Name: Offload Media - Cloud Storage
 * Version: 1.6.0
 * Description: Offload Media - Cloud Storage helps to offload your wordpress media to the cloud server services.
 * Author: Acowebs
 * Author URI: http://acowebs.com
 * Requires at least: 4.4.0
 * Tested up to: 6.7
 * Requires PHP: 7.0 or higher
 * Text Domain: offload-media-cloud-storage
 */
 
if (!defined('ABSPATH')) {
    exit;
}
global $wpdb;

define('ACOOFM_TOKEN', 'acoofm');
define('ACOOFM_VERSION', '1.6.0');
define('ACOOFM_FILE', __FILE__);
define('ACOOFM_PLUGIN_NAME', 'Offload Media - Cloud Storage');

define('ACOOFM_OPTION_NAME', 'acoofm_settings');
define('ACOOFM_ITEM_TABLE', $wpdb->prefix . ACOOFM_TOKEN .'_items');
define('ACOOFM_DB_VERSION', '1.0.2');

define('ACOOFM_S3_MULTIPART_UPLOAD_MINIMUM_SIZE', 5242880);
define('ACOOFM_GOOGLE_MULTIPART_UPLOAD_MINIMUM_SIZE', 5242880);
define('ACOOFM_DIGITAL_OCEAN_MULTIPART_UPLOAD_MINIMUM_SIZE', 5242880);
define('ACOOFM_CLOUDFLARE_MULTIPART_UPLOAD_MINIMUM_SIZE', 5242880);

define('ACOOFM_ATTACHMENT_META_KEY', ACOOFM_TOKEN.'_item_id');
define('ACOOFM_UPLOADS', 'acoofm_uploads');

define('ACOOFM_CACHE_KEY_PREFIX', ACOOFM_TOKEN.'_cache_key_');
define('ACOOFM_CACHE_GLOBAL_KEY', ACOOFM_TOKEN.'_cached_data_');
define('ACOOFM_CACHE_COUNT_PER_KEY', 500);
define('ACOOFM_ITEM_POOL_OPTION_KEY', ACOOFM_TOKEN.'_common_item_pool_data');
define('ACOOFM_ITEM_POOL_META_KEY', ACOOFM_TOKEN.'_item_pool_data');
define('ACOOFM_STORED_DATA_VERSION', ACOOFM_TOKEN.'_stored_data_version');
define('ACOOFM_CACHE_EXPIRY', 24*60*60);

// Helpers.
require_once realpath(plugin_dir_path(__FILE__)) . DIRECTORY_SEPARATOR . 'includes/helpers.php';

// Init.
add_action('plugins_loaded', 'acoofm_init');
if (!function_exists('acoofm_init')) {
    /**
     * Load plugin text domain
     *
     * @return  void
     */
    function acoofm_init()
    {
        $plugin_rel_path = basename(dirname(__FILE__)) . '/languages'; /* Relative to WP_PLUGIN_DIR */
        load_plugin_textdomain('offload-media-cloud-storage', false, $plugin_rel_path);
    }
}

// Loading Classes.
if (!function_exists('ACOOFMF_autoloader')) {

    function ACOOFMF_autoloader($class_name)
    {
        if (0 === strpos($class_name, 'ACOOFMF')) {
            $classes_dir = realpath(plugin_dir_path(__FILE__)) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR;
            $class_file = 'class-' . str_replace('_', '-', strtolower($class_name)) . '.php';
            if(file_exists($classes_dir . $class_file)){
                require_once $classes_dir . $class_file;
            } else {
                require_once $classes_dir . 'services' . DIRECTORY_SEPARATOR . $class_file;
            }
        }
        
        //include services
        require_once realpath(plugin_dir_path(__FILE__)) . DIRECTORY_SEPARATOR .'includes/s3/vendor/autoload.php';
        require_once realpath(plugin_dir_path(__FILE__)) . DIRECTORY_SEPARATOR .'includes/google/vendor/autoload.php';
    }
}
spl_autoload_register('ACOOFMF_autoloader');

// Backend UI.
if (!function_exists('ACOOFMF_Backend')) {
    function ACOOFMF_Backend()
    {
        return ACOOFMF_Backend::instance(__FILE__);
    }
}
if (!function_exists('ACOOFMF_Public')) {
    function ACOOFMF_Public()
    {
        return ACOOFMF_Public::instance(__FILE__);
    }
}

// Front end.
ACOOFMF_Public();
if (is_admin()) {
    ACOOFMF_Backend();
}
global $acoofmItem;
$acoofmItem = new ACOOFMF_ITEM;

new ACOOFMF_Api();

/**
 * Make sure the pro version loads first
 * @since 1.0.0
 */
add_filter( 'pre_update_option_active_plugins', 'ACOOFM_pro_make_pro_version_load_first' );
add_filter( 'pre_update_option_active_sitewide_plugins', 'ACOOFM_pro_make_pro_version_load_first' );

/** 
 * Move your plugin to the end
 */
if (!function_exists('ACOOFM_pro_make_pro_version_load_first')) {
    function ACOOFM_pro_make_pro_version_load_first( $plugins ) {
        $basename = 'offload-media-cloud-storage-pro/start.php';;
        if ( $key = array_search( $basename, $plugins ) ) {
            unset( $plugins[ $key ] );
            $plugins[] = $basename;
        }
        return $plugins;
    }
}
