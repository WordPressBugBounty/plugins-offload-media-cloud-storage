<?php
/**
 * Plugin Name: Offload Media - Cloud Storage
 * Version: 1.8.3
 * Description: Offload Media - Cloud Storage helps to offload your WordPress media to cloud storage services.
 * Author: Acowebs
 * Author URI: https://acowebs.com
 * Requires at least: 5.6
 * Requires PHP: 7.2
 * Tested up to: 7.0
 * Text Domain: offload-media-cloud-storage
 */

if (!defined('ABSPATH')) {
    exit;
}
global $wpdb;

define('ACOOFM_TOKEN', 'acoofm');
define('ACOOFM_VERSION', '1.8.3');
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

require_once realpath(plugin_dir_path(__FILE__)) . DIRECTORY_SEPARATOR . 'includes/helpers.php';

add_action('plugins_loaded', 'acoofm_init');
if (!function_exists('acoofm_init')) {
    function acoofm_init() {
        load_plugin_textdomain('offload-media-cloud-storage', false, basename(dirname(__FILE__)) . '/languages');
        if ( class_exists( 'ACOOFMF_URL_Rewrite_Resolver' ) ) {
            ACOOFMF_URL_Rewrite_Resolver::acoofm_init();
        }
    }
}

/**
 * Register a Composer vendor autoloader with a class-existence guard.
 *
 * Wraps Composer's ClassLoader with a guard that skips already-defined
 * classes/interfaces/traits, preventing conflicts with other plugins that
 * bundle the same namespaces (e.g. Google\*, Aws\*). The loader is appended
 * to the SPL stack so earlier autoloaders take priority.
 *
 * @param string $autoload_file Absolute path to the Composer vendor/autoload.php.
 */
if ( ! function_exists( 'acoofmf_register_guarded_vendor' ) ) {
    function acoofmf_register_guarded_vendor( string $autoload_file ): void {
        $composer_loader = require $autoload_file;

        if ( ! ( $composer_loader instanceof \Composer\Autoload\ClassLoader ) ) {
            return; // Already loaded by another plugin; loader is already on the SPL stack.
        }

        $composer_loader->unregister();

        spl_autoload_register(
            static function ( string $class ) use ( $composer_loader ): void {
                if (
                    class_exists( $class, false )
                    || interface_exists( $class, false )
                    || trait_exists( $class, false )
                ) {
                    return;
                }
                $composer_loader->loadClass( $class );
            },
            true,  // throw on error
            false  // append — other autoloaders get first priority
        );
    }
}

if (!function_exists('ACOOFMF_autoloader')) {
    /**
     * Plugin class autoloader with lazy vendor loading.
     *
     * Vendor autoloaders (AWS SDK, Google Cloud SDK) are registered only when
     * a service class that needs them is first loaded, avoiding global namespace
     * conflicts with other plugins.
     *
     * @param string $class_name The fully-qualified class name being autoloaded.
     */
    function ACOOFMF_autoloader($class_name) {
        if (0 !== strpos($class_name, 'ACOOFMF')) {
            return;
        }

        $plugin_dir  = realpath(plugin_dir_path(__FILE__));
        $classes_dir = $plugin_dir . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR;
        $class_file  = 'class-' . str_replace('_', '-', strtolower($class_name)) . '.php';

        static $s3_vendor_loaded     = false;
        static $google_vendor_loaded = false;

        $s3_service_classes = array( 'ACOOFMF_S3', 'ACOOFMF_DIGITALOCEAN', 'ACOOFMF_R2', 'ACOOFMF_MINIO' );
        if ( ! $s3_vendor_loaded && in_array( $class_name, $s3_service_classes, true ) ) {
            acoofmf_register_guarded_vendor( $plugin_dir . DIRECTORY_SEPARATOR . 'includes/s3/vendor/autoload.php' );
            $s3_vendor_loaded = true;
        }

        if ( ! $google_vendor_loaded && $class_name === 'ACOOFMF_GOOGLE' ) {
            acoofmf_register_guarded_vendor( $plugin_dir . DIRECTORY_SEPARATOR . 'includes/google/vendor/autoload.php' );
            $google_vendor_loaded = true;
        }

        if ( file_exists( $classes_dir . $class_file ) ) {
            require_once $classes_dir . $class_file;
        } else {
            require_once $classes_dir . 'services' . DIRECTORY_SEPARATOR . $class_file;
        }
    }
}
spl_autoload_register('ACOOFMF_autoloader');

if (!function_exists('ACOOFMF_Backend')) {
    function ACOOFMF_Backend() {
        return ACOOFMF_Backend::instance(__FILE__);
    }
}
if (!function_exists('ACOOFMF_Public')) {
    function ACOOFMF_Public() {
        return ACOOFMF_Public::instance(__FILE__);
    }
}

ACOOFMF_Public();
if (is_admin()) {
    ACOOFMF_Backend();
}
global $acoofmItem;
$acoofmItem = new ACOOFMF_ITEM;

new ACOOFMF_Api();

// Ensure the pro version loads after the free version.
add_filter( 'pre_update_option_active_plugins', 'ACOOFM_pro_make_pro_version_load_first' );
add_filter( 'pre_update_option_active_sitewide_plugins', 'ACOOFM_pro_make_pro_version_load_first' );

if (!function_exists('ACOOFM_pro_make_pro_version_load_first')) {
    function ACOOFM_pro_make_pro_version_load_first( $plugins ) {
        $basename = 'offload-media-cloud-storage-pro/start.php';
        if ( $key = array_search( $basename, $plugins ) ) {
            unset( $plugins[ $key ] );
            $plugins[] = $basename;
        }
        return $plugins;
    }
}
