<?php
/**
 * Uninstall Offload Media - Cloud Storage.
 *
 * Always removes runtime data: queue state, locks, caches and scheduled events.
 *
 * Kept by default: the offload records (acoofm_items table and attachment
 * links), the settings with the provider credentials and uploaded key files.
 * Without them media already offloaded (and removed from the server) cannot be
 * served after a reinstall, and the Pro version migrates them. To delete them
 * as well, add to wp-config.php:
 *
 *     define( 'ACOOFM_REMOVE_ALL_DATA', true );
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (!function_exists('acoofm_uninstall_site')) {

    /**
     * Remove the plugin data of the current site.
     *
     * @return void
     */
    function acoofm_uninstall_site() {
        global $wpdb;

        wp_clear_scheduled_hook('acoofm_process_offload_queue');
        wp_clear_scheduled_hook('acoofm_process_offload_queue', array('soon'));

        // Offload queue state and presigned URLs (regenerated when needed).
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s, %s, %s, %s, %s)",
            '_acoofm_offload_status',
            '_acoofm_offload_state',
            '_acoofm_offload_retry_at',
            '_acoofm_offload_retry_count',
            'acoofm_presigned_urls',
            'acoofm_item_pool_data'
        ));

        // Queue locks and cached item data.
        foreach (array('acoofm_offload_lock_', '_transient_acoofm_cached_data_', '_transient_timeout_acoofm_cached_data_') as $prefix) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like($prefix) . '%'
            ));
        }

        delete_transient('acoofmf_cdn_base_urls');
        foreach (array(
            'acoofm_stored_data_version',
            'acoofm_common_item_pool_data',
            'acoofm_s3_acl_disabled_buckets',
            'acoofm_gcs_acl_disabled_buckets',
            'acoofm_do_activation_redirect',
            'acoofm_key_files_version',
        ) as $option) {
            delete_option($option);
        }

        if (!(defined('ACOOFM_REMOVE_ALL_DATA') && ACOOFM_REMOVE_ALL_DATA)) {
            return;
        }

        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}acoofm_items");
        delete_option('acoofm_settings');
        delete_option('acoofm_db_version');
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", 'acoofm_item_id'));

        // Uploaded key files (Google service account JSON).
        $upload_dir = wp_get_upload_dir();
        $key_dir = $upload_dir['basedir'] . '/acoofm_uploads';
        if (is_dir($key_dir)) {
            foreach ((array) scandir($key_dir) as $name) {
                if ($name !== '.' && $name !== '..' && is_file($key_dir . '/' . $name)) {
                    @unlink($key_dir . '/' . $name);
                }
            }
            @rmdir($key_dir);
        }
    }
}

if (is_multisite()) {
    foreach (get_sites(array('fields' => 'ids', 'number' => 0)) as $site_id) {
        switch_to_blog($site_id);
        acoofm_uninstall_site();
        restore_current_blog();
    }
} else {
    acoofm_uninstall_site();
}
