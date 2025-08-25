<?php

if (!defined('ABSPATH')) {
    exit;
}

class ACOOFMF_Public
{

    /**
     * @var    object
     * @access  private
     * @since    1.0.0
     */
    private static $instance = null;

    /**
     * The version number.
     *
     * @var     string
     * @access  public
     * @since   1.0.0
     */
    public $version;

    /**
     * The main plugin file.
     *
     * @var     string
     * @access  public
     * @since   1.0.0
     */
    public $file;

    /**
     * The token.
     *
     * @var     string
     * @access  public
     * @since   1.0.0
     */
    public $token;

    /**
     * The plugin assets URL.
     *
     * @var     string
     * @access  public
     * @since   1.0.0
     */
    public $assets_url;

    /**
     * Constructor function.
     *
     * @access  public
     * @param string $file Plugin root file path.
     * @since   1.0.0
     */
    public function __construct($file = '')
    {
        $this->version = ACOOFM_VERSION;
        $this->token = ACOOFM_TOKEN;
        $this->file = $file;
        $this->assets_url = esc_url(trailingslashit(plugins_url('/assets/', $this->file)));

        // Load frontend CSS.
        add_action('wp_enqueue_scripts', array($this, 'frontend_enqueue_styles'), 10);
        // Load frontend JS.
        add_action('wp_enqueue_scripts', array($this, 'frontend_enqueue_scripts'), 10);

        // Register Global variables
        add_action('init', array($this, 'register_global_variables'));

        add_action('init', array($this, 'init'));

        // Load additional Classes
        add_action('init', array($this, 'load_classes'));

        // Load action for modifying URL s and uploading media
        $this->register_actions();
    }


    /**
     * Register Actions for offload media Template
     * @access public
     * @return void
     * @since 1.0.8
     */

    public function load_classes()
    {
        ACOOFMF_COMPATIBILITY::instance($this->file);
        ACOOFMF_REWRITEURL::instance($this->file);
    }

    /**
     * Register Actions for offload media Template
     * @access public
     * @return void
     */

    public function register_actions()
    {
        /** URL RE WRITING HOOKS */
        add_filter('wp_get_attachment_url', array($this, 'wp_get_attachment_url'), 99, 2);
        add_filter('wp_get_attachment_image_attributes', array($this, 'wp_get_attachment_image_attributes'), 99, 3);
        add_filter('wp_calculate_image_srcset', array($this, 'wp_calculate_image_srcset'), 99, 5);
        add_filter('get_attached_file', array($this, 'get_attached_file'), 10, 2);
        add_filter('wp_get_original_image_path', array($this, 'get_attached_file'), 10, 2);
        add_filter('wp_prepare_attachment_for_js', array($this, 'acoofmp_wp_prepare_attachment_for_js'), 999, 6);

        /** FILE MANAGEMENT HOOKS */
        add_filter('wp_unique_filename', array($this, 'wp_unique_filename'), 10, 3);
        add_filter('wp_update_attachment_metadata', array($this, 'update_attachment_metadata'), 110, 2);
        add_filter('wp_generate_attachment_metadata', array($this, 'wp_generate_attachment_metadata'), 110, 3);
        add_filter('delete_attachment', array($this, 'delete_attachment'), 20);
        add_filter('update_attached_file', array($this, 'update_attached_file'), 100, 2); 
        add_filter('load_image_to_edit_path', array($this, 'load_image_to_edit_path'), 10, 3 );

    }


    /**
     * Function to execute on wp_generate_attachment_metadata
     * To delete files from server only after updating all meta
     * @since 1.0.4
     *
     */

    public function wp_generate_attachment_metadata( $attachment_meta, $attachment_id, $action) {
        if($action == 'create') {
            global $acoofmService;
            global $acoofmItem;
    
            if (!(
                acoofm_is_service_enabled() &&
                acoofm_is_copy_to_server() &&
                isset($attachment_id) && !empty($attachment_id) &&
                $acoofmItem->get((int)$attachment_id)
            )) {
                return $attachment_meta;
            }

            $type       = get_post_mime_type((int) $attachment_id);
            $is_image   = (0 === strpos($type, 'image/'));
            $settings   = acoofm_get_settings();
            $upload_dir = wp_get_upload_dir();
            $file           = '';
            $rel_path       = '';
            $file_path      = '';
            $file_path_dir  = '';
            $original_file  = '';
            $old_item       = $acoofmItem->get((int)$attachment_id);
            $old_extras     = $acoofmItem->get_extras((int)$attachment_id);

            // Get File Name/Path from Image meta
            if (
                isset($attachment_meta) && !empty($attachment_meta) &&
                isset($attachment_meta['file']) && !empty($attachment_meta['file'])
            ) {
                $file = $attachment_meta['file'];
            } else {
                $file = acoofm_get_post_meta((int) $attachment_id, '_wp_attached_file', true);
            }

            // Generate relative path of the file 
            $rel_path = acoofm_get_attachment_relative_path($file);

            // Delete file from server if exist
            if (
                $rel_path && 
                isset($settings['remove_from_server']) && 
                $settings['remove_from_server']
            ) {
                $file_path = trailingslashit($upload_dir['basedir']) . $rel_path;
                $file_path_dir = isset(pathinfo($file_path)['dirname']) ? pathinfo($file_path)['dirname'] : '';

                if(file_exists($file_path)) {
                    wp_delete_file($file_path);
                }

                // Delete original image if exist
                if (
                    $is_image &&
                    isset($attachment_meta['original_image']) && !empty($attachment_meta['original_image']) &&
                    isset($old_extras['original']) && !empty($old_extras['original']) &&
                    isset($old_extras['original']['source']) && !empty($old_extras['original']['source']) 
                ) {
                    $original_file = $attachment_meta['original_image'];
                    if( isset($original_file) && !empty($original_file)) {
                        $original_file_path     = trailingslashit($file_path_dir) . $original_file;
                        if(file_exists($original_file_path)) {
                            wp_delete_file($original_file_path);
                        }
                    }
                }

                // Confirm Every size is deleted
                if (
                    $is_image &&
                    isset($attachment_meta['sizes']) && !empty($attachment_meta['sizes']) &&
                    isset($old_extras['sizes']) && !empty($old_extras['sizes']) &&
                    isset($file_path_dir) && !empty($file_path_dir)
                ) {
                    foreach ($attachment_meta['sizes'] as $size => $sub_image) {
                        if (
                            isset($sub_image) && !empty($sub_image) &&
                            isset($sub_image['file']) && !empty($sub_image['file']) &&
                            isset($old_extras['sizes'][$size]) && !empty($old_extras['sizes'][$size]) &&
                            isset($old_extras['sizes'][$size]['url']) && !empty($old_extras['sizes'][$size]['url'])
                        ) {
                            $sub_file_path = $file_path_dir . '/' . $sub_image['file'];
                        }
                    
                        if (file_exists($sub_file_path)){
                            wp_delete_file($sub_file_path);
                        }
                    }
                }
            }
        } 

        return $attachment_meta;
    }


    /**
     * Function to execute on wp_update_attachment_metadata
     * @since 1.0.0
     *
     */
    public function update_attachment_metadata($attachment_meta, $attachment_id)
    {
        global $acoofmService;
        global $acoofmProService;
        global $acoofmItem;

        if (!(
            acoofm_is_service_enabled() &&
            acoofm_is_copy_to_server() &&
            isset($attachment_id) && !empty($attachment_id)
        )) {
            return $attachment_meta;
        }

        //Generate attachment meta if empty
        if (!(isset($attachment_meta) && !empty($attachment_meta))) {
            $attachment_meta = wp_get_attachment_metadata($attachment_id);
        }

        $file_path_dir          = '';
        $file                   = '';
        $file_path              = '';
        $original_file_rel_path = '';
        $original_file          = '';
        $original_file_path     = '';
        $uploaded_original      = array();
        $uploaded               = array();
        $sizes                  = array();
        $meta                   = array();
        $upload_dir             = wp_get_upload_dir();
        $type                   = get_post_mime_type((int) $attachment_id);
        $is_image               = (0 === strpos($type, 'image/'));
        $prefix                 = '';
        $update_to_db           = false;
        $keep_backup            = true;
        $restore_backup         = false;

        $old_item               = $acoofmItem->get((int) $attachment_id);
        $old_extras             = $acoofmItem->get_extras((int) $attachment_id) ? $acoofmItem->get_extras((int) $attachment_id) : [];
        $settings               = acoofm_get_settings();

        $offloaded_count        = 0;

        // Add prefix if object versioning is ON
        if (isset($settings['object_versioning']) && $settings['object_versioning']) {
            $prefix         = ($old_extras && isset($old_extras['prefix']) && !empty($old_extras['prefix'])) 
                                ? $old_extras['prefix'] 
                                : acoofm_generate_object_versioning_prefix();
            $meta['prefix'] = $prefix;
        }

        // Get width and height from image meta
        if (isset($attachment_meta) && !empty($attachment_meta)) {
            $meta['width']  = (isset($attachment_meta['width']) && !empty($attachment_meta['width'])) ? $attachment_meta['width'] : 0;
            $meta['height'] = (isset($attachment_meta['height']) && !empty($attachment_meta['height'])) ? $attachment_meta['height'] : 0;
        }

        // Get File Name/Path from Image meta
        if (
            isset($attachment_meta) && !empty($attachment_meta) &&
            isset($attachment_meta['file']) && !empty($attachment_meta['file'])
        ) {
            $file = $attachment_meta['file'];
        } else {
            $file = acoofm_get_post_meta((int) $attachment_id, '_wp_attached_file', true);
        }

        // Generate relative path of the file 
        $rel_path = acoofm_get_attachment_relative_path($file);

        // Get Original File Name/Path from Image meta
        if (
            isset($attachment_meta) && !empty($attachment_meta) &&
            isset($attachment_meta['original_image']) && !empty($attachment_meta['original_image'])
        ) {
            $original_file = $attachment_meta['original_image'];
        } 


        if ($rel_path) {
            $file_path = trailingslashit($upload_dir['basedir']) . $rel_path;
            $file_path_dir = isset(pathinfo($file_path)['dirname']) ? pathinfo($file_path)['dirname'] : '';

            // Check whether the extension is enabled for uploading
            if (!acoofm_is_extension_available($file_path)) {
                return $attachment_meta;
            }

            if(isset($original_file) && !empty($original_file)) {
                $original_file_path     = trailingslashit($file_path_dir) . $original_file;
                $original_file_rel_path = acoofm_get_attachment_relative_path($original_file_path);
            }

            //Check whether file already exist or It is already uploaded to server
            if (file_exists($file_path) || ( $old_item && isset($old_item['source_path']) )) {
                // Check the item is not same in db
                $proceedUpload = true;
                if ($old_item) {
                    if ( $old_item['source_path'] == $rel_path ) {
                        $proceedUpload = false;
                        $keep_backup = false;
                        $uploaded = array(
                            'success' => true,
                            'file_url' => $old_item['url'],
                            'key' => $old_item['path'],
                        );
                    }
                } else {
                    $keep_backup = false;
                }

                $proceedUploadOriginal = true;
                if ($old_extras) {
                    //Check for restore
                    if (isset($old_extras['backup']) && !empty($old_extras['backup'])) {
                        $backup = unserialize($old_extras['backup']);
                        if ( $backup['source_path'] == $rel_path ) {
                            $restore_backup = true;
                        }
                    }

                    if (isset($old_extras['original']) && !empty($old_extras['original'])) {
                        $uploaded_original = array(
                            'success'   => true,
                            'source'    => isset($old_extras['original']['source']) 
                                                ? $old_extras['original']['source'] : '',
                            'file_url'  => isset($old_extras['original']['file_url']) 
                                                ? $old_extras['original']['file_url'] : '',
                            'key'       => isset($old_extras['original']['key']) 
                                                ? $old_extras['original']['key'] : '',
                        );
                        $proceedUploadOriginal = false;
                    }

                }

                if ($proceedUpload && !$restore_backup && file_exists($file_path)) {
                    $update_to_db = true;

                    if (is_object($acoofmService) && method_exists($acoofmService, 'connect') && method_exists($acoofmService, 'uploadSingle')) {
                        $service = $acoofmService;
                    } else {
                        $service = $acoofmProService;
                    }

                    if ($service->connect()) {
                        $uploaded = $service->uploadSingle($file_path, $rel_path, $prefix);
                
                        // Upload Original file if exist
                        if(
                            isset($original_file) && !empty($original_file) && 
                            file_exists($original_file_path) && $proceedUploadOriginal &&
                            isset($uploaded) && !empty($uploaded) &&
                            isset($uploaded['success']) && $uploaded['success']
                        ) {
                            $uploaded_original = $service->uploadSingle($original_file_path, $original_file_rel_path, $prefix);
                            $uploaded_original['source'] = $original_file;
                            
                            if(isset($uploaded_original['success']) && $uploaded_original['success']){
                                $offloaded_count++;
                            }
                        }
                        
                        if(
                            isset($uploaded_original['success']) && 
                            $uploaded_original['success'] && 
                            !$keep_backup
                        ) {
                            $offloaded_count++;
                        }
                    }
                }
            }
        }

        if (!$restore_backup) {
            if (
                $is_image &&
                isset($attachment_meta['sizes']) && !empty($attachment_meta['sizes']) &&
                isset($uploaded) && !empty($uploaded) &&
                isset($uploaded['success']) && $uploaded['success'] &&
                isset($file_path_dir) && !empty($file_path_dir)
            ) {
                foreach ($attachment_meta['sizes'] as $size => $sub_image) {
                    $uploaded_sub_image = array();
                    $sub_file_path      = '';
                    $sub_file_rel_path  = '';
                    $sub_file_width     = 0;
                    $sub_file_height    = 0;

                    if (
                        isset($sub_image) && !empty($sub_image) &&
                        isset($sub_image['file']) && !empty($sub_image['file'])
                    ) {
                        $sub_file_path = $file_path_dir . '/' . $sub_image['file'];
                        $sub_file_rel_path = acoofm_get_attachment_relative_path($sub_file_path);
                    }

                    if (isset($sub_image['width']) && !empty($sub_image['width'])) {
                        $sub_file_width = $sub_image['width'];
                    }

                    if (isset($sub_image['height']) && !empty($sub_image['height'])) {
                        $sub_file_height = $sub_image['height'];
                    }

                    if ( 
                        file_exists($sub_file_path) || 
                        (isset($old_extras) && isset($old_extras['sizes'][$size]['source_path']) )
                    ) {
                        $proceedUpload = true;
                        if (isset($old_extras['sizes']) && !empty($old_extras['sizes'])) {
                            $old_sizes = $old_extras['sizes'];
                            if (isset($old_sizes[$size]) && !empty($old_sizes[$size])) {
                                if ($old_sizes[$size]['source_path'] == $sub_file_rel_path) {
                                    $proceedUpload  = false;
                                    $sizes[$size]   = $old_sizes[$size];
                                }
                            }
                        }
                        if ($proceedUpload && file_exists($sub_file_path)) {
                            $update_to_db       = true;
                            $uploaded_sub_image = array();

                            if (is_object($acoofmService) && method_exists($acoofmService, 'connect') && method_exists($acoofmService, 'uploadSingle')) {
                                $service = $acoofmService;
                            } else {
                                $service = $acoofmProService;
                            }

                            if ($service->connect()) {
                                $uploaded_sub_image = $service->uploadSingle($sub_file_path, $sub_file_rel_path, $prefix);
                                if(
                                    !$keep_backup && 
                                    isset($uploaded_sub_image['success']) && 
                                    $uploaded_sub_image['success']
                                ) {
                                    $offloaded_count++;
                                }
                            }

                            if (!empty($uploaded_sub_image)) {
                                if (isset($uploaded_sub_image['success']) && $uploaded_sub_image['success']) {
                                    $sizes[$size]['source_path']    = $sub_file_rel_path;
                                    $sizes[$size]['url']            = $uploaded_sub_image['file_url'];
                                    $sizes[$size]['path']           = $uploaded_sub_image['key'];
                                    $sizes[$size]['width']          = $sub_file_width;
                                    $sizes[$size]['height']         = $sub_file_height;
                                }
                            } else {
                                $sizes[$size] = [];
                            }
                        }

                        if (
                            file_exists($sub_file_path) && 
                            isset($settings['remove_from_server']) && 
                            $settings['remove_from_server'] &&
                            !empty($uploaded_sub_image) &&
                            isset($uploaded_sub_image['success']) && $uploaded_sub_image['success']
                        ) {
                            wp_delete_file($sub_file_path);
                        }
                    }
                }
            }

            if (isset($sizes) && !empty($sizes)) {
                $meta['sizes'] = $sizes;
            }

            if(
                isset($uploaded_original) && !empty($uploaded_original) &&
                isset($uploaded_original['success']) && $uploaded_original['success']
            ) {
                $meta['original'] = array(
                    'source'    => $uploaded_original['source'] ,
                    'file_url'  => $uploaded_original['file_url'] ,
                    'key'       => $uploaded_original['key']
                );
            }

            if (
                isset($uploaded) && !empty($uploaded) &&
                isset($uploaded['success']) && $uploaded['success']
            ) {
                if ($old_item) {
                    // Update Existing && Keep Backup
                    if (isset($old_extras['backup']) && !empty($old_extras['backup'])) {
                        $meta["backup"] = $old_extras['backup'];
                        // Delete old Edited Files Just before adding New files
                        $this->delete_current_item_files_from_server($old_item);
                    } else if($keep_backup) {
                        $meta["backup"] = serialize($old_item);
                    }

                    $item_data = array(
                        'source_path'   => $rel_path,
                        'source_type'   => 'media-library',
                        'url'           => $uploaded['file_url'],
                        'path'          => $uploaded['key'],
                        'is_private'    => 0,
                        'extra_info'    => maybe_serialize($meta),
                    );

                    $acoofmItem->update((int) $attachment_id, $item_data);
                } else {
                    $acoofmItem->add((int) $attachment_id, $uploaded['file_url'], $uploaded['key'], $rel_path, $meta);
                }

                // // Delete file if enabled remove file from server
                // if (isset($settings['remove_from_server']) && $settings['remove_from_server'] ) {
                //     wp_delete_file($file_path);
                // }

                acoofm_update_media_count('offloaded', $offloaded_count);
            }

             // Delete File That is added when Image editor is Started
             if (
                isset($old_item) && !empty($old_item) &&
                isset($old_item['source_path']) && 
                isset($settings['remove_from_server']) && 
                $settings['remove_from_server'] && 
                $keep_backup
            ) {
                $old_file_path = trailingslashit($upload_dir['basedir']) . $old_item['source_path'];
                if(file_exists($old_file_path)) {
                    wp_delete_file($old_file_path);
                }
            }

        } else {
            if (isset($old_item) && !empty($old_item)) {
                $back_extra = unserialize($old_item['extra_info']);
                if (isset($back_extra['backup']) && !empty($back_extra['backup'])) {
                    $backup = unserialize($back_extra['backup']);
                    if ($backup['source_path'] == $rel_path ) {
                        $acoofmItem->update((int) $attachment_id, $backup);
                        $this->delete_attachment_by_item($old_item, false);
                    } 
                }
            }
        }

        return $attachment_meta;
    }

    /**
     * Function to remove data from provider by attachment ID
     * @since 1.0.0
     *
     */
    public function delete_attachment($post_id)
    {

        if (!(
            acoofm_is_service_enabled() 
        )) {
            return $post_id;
        }

        global $acoofmItem;

        $acoofm_Item = $acoofmItem->get($post_id);

        if (!($acoofm_Item && $acoofmItem->is_available_from_provider($post_id, false))) {
            return $post_id;
        }

        $this->delete_attachment_by_item($acoofm_Item);

        $acoofmItem->delete($post_id);

        return $post_id;
    }

    /**
     * Delete File from Provider by table item
     */
    public function delete_attachment_by_item($item, $delete_backup = true, $is_backup = false)
    {
        global $acoofmService;
        global $acoofmProService;

        if (is_object($acoofmService) && method_exists($acoofmService, 'deleteSingle')) {
            $service = $acoofmService;
        } else {
            $service = $acoofmProService;
        }

        if (
            isset($item) && !empty($item) &&
            isset($item['provider']) && !empty($item['provider'])
        ) {
            global $acoofmItem;

            $upload_dir     = wp_get_upload_dir();
            $deleted        = 0;
    
            if (isset($item['extra_info']) && !empty($item['extra_info'])) {
                $meta = unserialize($item['extra_info']);
                if (
                    isset($meta) && !empty($meta) &&
                    isset($meta['sizes']) && !empty($meta['sizes'])
                ) {
                    foreach ($meta['sizes'] as $sub_image) {
                        if (isset($sub_image['path']) && !empty($sub_image['path'])) {
                            $delete = $service->deleteSingle($sub_image['path']);
                            if(isset($delete['success']) && $delete['success']) {
                                $deleted++;
                            }
                        }
                    }
                }

                if (
                    isset($meta) && !empty($meta) &&
                    isset($meta['original']) && !empty($meta['original'])
                ) {
                    $delete = $service->deleteSingle($meta['original']['key']);
                    if(isset($delete['success']) && $delete['success']) {
                        $deleted++;
                    }
                }

                if (
                    isset($meta) && !empty($meta) &&
                    isset($meta['backup']) && !empty($meta['backup']) &&
                    $delete_backup
                ) {
                    $backup = unserialize($meta['backup']);
                    if (isset($backup) && !empty($backup)) {
                        $this->delete_attachment_by_item($backup, false, true);
                    }
                }
            }
            if (isset($item['path']) && !empty($item['path'])) {
                $delete = $service->deleteSingle($item['path']);
                if(isset($delete['success']) && $delete['success']) {
                    $deleted++;
                }
            }

            if(!$is_backup) {
                acoofm_update_media_count('offloaded', $deleted, false);
            }
        }
    }


    /**
     * To delete files only from server for the item
     * @since 1.0.4
     */
    private function delete_current_item_files_from_server($item){
        global $acoofmService;
        global $acoofmProService;

        if (is_object($acoofmService) && method_exists($acoofmService, 'deleteSingle')) {
            $service = $acoofmService;
        } else {
            $service = $acoofmProService;
        }

        if (
            isset($item) && !empty($item) &&
            isset($item['provider']) && !empty($item['provider'])
        ) {
            global $acoofmItem;

            if (isset($item['extra_info']) && !empty($item['extra_info'])) {
                $meta = unserialize($item['extra_info']);
                if (
                    isset($meta) && !empty($meta) &&
                    isset($meta['sizes']) && !empty($meta['sizes'])
                ) {
                    foreach ($meta['sizes'] as $sub_image) {
                        if (isset($sub_image['path']) && !empty($sub_image['path'])) {
                            $service->deleteSingle($sub_image['path']);
                        }
                    }
                }
            }
            if (isset($item['path']) && !empty($item['path'])) {
                $delete = $service->deleteSingle($item['path']);
            }
        }
    }

    /**
     * Allow processes to update the file on provider via update_attached_file()
     * @since 1.0.0
     * @param string $file
     * @param int    $attachment_id
     *
     * @return string
     */
    public function update_attached_file($file, $attachment_id)
    {
        global $acoofmItem;

        if(!$attachment_id || !$acoofmItem) {
            return $file;
        }

        if (!(
            acoofm_is_service_enabled() &&
            acoofm_is_copy_to_server() &&
            $acoofmItem->is_available_from_provider($attachment_id)
        )) {
            return $file;
        }

        $acoofm_Item = $acoofmItem->get($attachment_id);

        if (!$acoofm_Item) {
            return $file;
        }

        $file = apply_filters('acoofm_update_attached_file', $file, $attachment_id, $acoofm_Item);

        return $file;
    }

    /**
     * Get attachment url
     * @since 1.0.0
     * @param string $url
     * @param int    $attachment_id
     *
     * @return bool|mixed|WP_Error
     */
    public function wp_get_attachment_url($url, $attachment_id)
    {
        global $acoofmItem;
        $url = preg_replace('/([^:])(\/{2,})/', '$1/', $url); // to remove double slash trapped during any operations
        if(!$attachment_id || !$acoofmItem) {
            return $url;
        }

        if (!(
            acoofm_is_service_enabled() &&
            acoofm_is_rewrite_url() &&
            $acoofmItem->is_available_from_provider($attachment_id)
        )) {
            return $url;
        }

        $new_url = $acoofmItem->get_url($attachment_id);


        if (false === $new_url) {
            return $url;
        }

        $new_url = apply_filters('acoofm_wp_get_attachment_url', $new_url, $url, $attachment_id);

        return $new_url;
    }

    /**
     * Filters the list of attachment image attributes.
     *
     * @since 1.0.0
     * @param array        $attr  Attributes for the image markup.
     * @param WP_Post      $attachment Image attachment post.
     * @param string|array $size  Requested size. Image size or array of width and height values (in that order).
     *
     * @return array
     */
    public function wp_get_attachment_image_attributes($attr, $attachment, $size='thumbnail')
    {
        global $acoofmItem;
        
        if(!$attachment || !$acoofmItem) {
            return $attr;
        }

        if (!(
            acoofm_is_service_enabled() &&
            acoofm_is_rewrite_url() &&
            $acoofmItem->is_available_from_provider($attachment->ID)
        )) {
            return $attr;
        }

        $size = acoofm_maybe_convert_size_to_string($attachment->ID, $size);
        if ($size === false) {
            return $attr;
        }

        $acoofm_Item = $acoofmItem->get($attachment->ID);

        if (
            isset($size) && !empty($size) &&
            isset($attr['src']) && !empty($attr['src'])
        ) {
            $source = $acoofmItem->get_thumbnail_url($attachment->ID, $size);
            if (isset($source) && !empty($source)) {
                $attr['src'] = $source;
            }
        }

        /**
         * Filtered list of attachment image attributes.
         *
         * @param array              $attr       Attributes for the image markup.
         * @param WP_Post            $attachment Image attachment post.
         * @param string             $size       Requested size.
         * @param ACOOFMF_ITEM        $acoofm_Item
         */
        return apply_filters('acoofm_wp_get_attachment_image_attributes', $attr, $attachment, $size, $acoofm_Item);
    }

    /**
     * Return the provider URL when the local file is missing
     * unless we know who the calling process is and we are happy
     * to copy the file back to the server to be used.
     *
     * @handles get_attached_file
     * @handles wp_get_original_image_path
     * @since 1.0.0
     * @param string $file
     * @param int    $attachment_id
     *
     * @return string
     */
    public function get_attached_file($file, $attachment_id)
    {
        global $acoofmItem;

        if(!$attachment_id || !$acoofmItem) {
            return $file;
        }

        if (!(
            acoofm_is_service_enabled() &&
            acoofm_is_rewrite_url() &&
            $acoofmItem->is_available_from_provider($attachment_id)
        )) {
            return $file;
        }

        if( isset($_REQUEST['action']) &&  $_REQUEST['action'] === 'image-editor' ) {
            // Avoid rewriting URL when image restore in image editor
            if(isset($_REQUEST['do']) && $_REQUEST['do']==='restore') {
                return $file;
            }
            // Avoid rewriting URL when image save in image editor
            if(isset($_REQUEST['do']) && $_REQUEST['do'] === 'save') {
                return $file;
            }
        }
        
        $acoofm_item = $acoofmItem->get($attachment_id);

        $url = $acoofmItem->get_url((int) $attachment_id);

        if ($url) {
            $file = apply_filters('acoofm_get_attached_file', $url, $file, $attachment_id, $acoofm_item);
        }
        $file = preg_replace('/([^:])(\/{2,})/', '$1/', $file); // to remove double slash trapped during any operations
        return $file;
    }

    /**
     * Prepares an attachment post object for JavaScript, formatting its data for use in the WordPress media library.
     *
     * This function retrieves and formats the attachment's metadata, such as URL, title, caption, description,
     * and other relevant information, making it suitable for use in JavaScript-based media interfaces.
     *
     * @param int|WP_Post $attachment The attachment ID or WP_Post object.
     * @return array An associative array of attachment data formatted for JavaScript.
     */
    public function acoofmp_wp_prepare_attachment_for_js($response, $attachment, $context = 'view', $include_meta = true, $include_icon = true, $include_thumbnail = true)
    {
        global $acoofmItem;

        // Only process images
        $mime_type = get_post_mime_type($attachment->ID);
        if (strpos($mime_type, 'image/') !== 0) {
            return $response;
        }

        // Ensure our service is enabled and attachment is available
        if (!(
            acoofm_is_service_enabled() &&
            acoofm_is_rewrite_url() &&
            $acoofmItem &&
            $acoofmItem->is_available_from_provider($attachment->ID)
        )) {
            return $response;
        }

        $acoofm_Item = $acoofmItem->get($attachment->ID);

        // Replace main image URL
        if (isset($response['url'])) {
            $source = $acoofmItem->get_url($attachment->ID);
            if (!empty($source)) {
                $response['url'] = $source;
            }
        }

        // Replace each thumbnail size URL
        if (isset($response['sizes']) && is_array($response['sizes'])) {
            foreach ($response['sizes'] as $size => $details) {
                if (!empty($details['url'])) {
                    $source = $acoofmItem->get_thumbnail_url($attachment->ID, $size);
                    if (!empty($source)) {
                        $response['sizes'][$size]['url'] = $source;
                    }
                }
            }
        }

        /**
         * Filtered response for JS with updated thumbnail URLs.
         *
         * @param array              $response      The prepared attachment response.
         * @param WP_Post            $attachment    Attachment object.
         * @param string             $context       Context for response.
         * @param ACOOFMF_ITEM       $acoofm_Item   Item instance if available.
         */
        return apply_filters('acoofmp_wp_prepare_attachment_for_js', $response, $attachment, $context, $acoofm_Item);
    }

    /**
     * Change src attributes
     * @since 1.0.0
     */

    public function wp_calculate_image_srcset($sources, $size_array, $image_src, $attachment_meta, $attachment_id = 0)
    {
        global $acoofmItem;

        // Must need $attachment_id other wise not possible to get data from the table
        if(!$attachment_id || !$acoofmItem) {
            return $sources;
        }

        if (!(
            acoofm_is_service_enabled() &&
            acoofm_is_rewrite_url() &&
            $acoofmItem->is_available_from_provider($attachment_id)
        )) {
            return $sources;
        }

        $item_extra = $acoofmItem->get_extras((int) $attachment_id);

        if (isset($item_extra['width']) && !empty($item_extra['width'])) {
            $sources[$item_extra['width']]=[
                'url' => $acoofmItem->get_url((int) $attachment_id),
                'descriptor' => 'w',
				'value'      => $item_extra['width']
            ];
        }

        if ($item_extra) {
            if (isset($item_extra['sizes']) && !empty($item_extra['sizes'])) {
                foreach ($item_extra['sizes'] as $size => $size_array) {
                    if (isset($size_array['width']) && !empty($size_array['width'])) {
                        $w = $size_array['width'];
                        if (isset($sources[$w]) && !empty($sources[$w])) {
                            $sources[$w]['url'] = $acoofmItem->get_thumbnail_url((int) $attachment_id, $size);
                        }
                    }
                }
            }
        }

        return $sources;
    }


     /**
     * Function to execute on load_image_to_edit_path
     * @since 1.0.1
     * @return string
     *
     */
    public function load_image_to_edit_path($path, $attachment_id, $size) {
        global $acoofmItem;

        // Must need $attachment_id other wise not possible to get data from the table
        if(!$attachment_id || !$acoofmItem) {
            return $path;
        }

        if (!(
            acoofm_is_service_enabled() &&
            acoofm_is_rewrite_url() &&
            $acoofmItem->is_available_from_provider($attachment_id)
        )) {
            return $path;
        }

        $local_file = false;
        //If operation is Image editor Image Save
        if(
            isset($_REQUEST['do']) &&
            isset($_REQUEST['action']) && 
            $_REQUEST['action'] === 'image-editor' &&
            $_REQUEST['do']==='save'
        ) {
            $local_file = $acoofmItem->moveToLocal($attachment_id, $size);
        }

        // add_filter('acoofm_wp_get_attachment_url', function( $new_url, $url, $attachment_id) {
        //     return $url;
        // }, 3, 10);

        if($local_file) {
            $path = $local_file;
        }

        return $path;
    }


    /**
     * Create unique names for files effects mainly on delete files from server settings
     * @since 1.0.0
     * @return string
     */
    public function wp_unique_filename($filename, $ext, $dir)
    {
        // Get Post ID if uploaded in post screen.
        $post_id = filter_input(INPUT_POST, 'post_id', FILTER_VALIDATE_INT);

        $filename = $this->filter_unique_filename($filename, $ext, $dir, $post_id);

        return $filename;
    }

    /**
     * filter unique file names
     * @since 1.0.0
     * @return string
     */
    private function filter_unique_filename($filename, $ext, $dir, $post_id = null)
    {
        if (!acoofm_is_service_enabled()) {
            return $filename;
        }

        // sanitize the file name before we begin processing
        $filename   = sanitize_file_name($filename);
        $ext        = strtolower($ext);
        $name       = wp_basename($filename, $ext);

        // Edge case: if file is named '.ext', treat as an empty name.
        if ($name === $ext) {
            $name = '';
        }

        // Rebuild filename with lowercase extension as provider will have converted extension on upload.
        $filename = $name . $ext;

        return $this->generate_unique_filename($name, $ext, $dir);
    }

    /**
     * Generate unique filename
     * @since 1.0.0
     * @param string $name
     * @param string $ext
     * @param string $time
     *
     * @return string
     */
    private function generate_unique_filename($name, $ext, $dir)
    {
        global $acoofmItem;
        $upload_dir         = wp_get_upload_dir();
        $filename           = $name . $ext;
        $no_ext_path        = $dir . '/' . $name;
        $rel_no_ext_path    = substr($no_ext_path, strlen(trailingslashit($upload_dir['basedir'])),strlen($no_ext_path));
        $path               = $dir . '/' . $name . $ext;
        $rel_path           = substr($path, strlen(trailingslashit($upload_dir['basedir'])),strlen($path));


        $uploaded_files = $acoofmItem->get_similar_files_by_path($rel_no_ext_path);

        if ($uploaded_files !== false) {
            if (acoofm_check_existing_file_names($rel_path, $uploaded_files) || file_exists($path)) {
                $count = 1;
                $new_file_name = '';
                $found = true;
                while ($found) {
                    $tmp_path   = $dir . '/' . $name . '-' . $count . $ext;
                    $rel_temp_path   = substr($tmp_path, strlen(trailingslashit($upload_dir['basedir'])),strlen($tmp_path));

                    if (acoofm_check_existing_file_names($rel_temp_path, $uploaded_files) || file_exists($tmp_path)) {
                        $count++;
                    } else {
                        $found = false;
                        $new_file_name = $name . '-' . $count . $ext;
                    }
                }
                return $new_file_name;
            }
        } else {
            if (file_exists($path)) {
                $count = 1;
                $new_file_name = '';
                $found = true;

                while ($found) {
                    $tmp_path = $dir . '/' . $name . '-' . $count . $ext;
                    if (file_exists($tmp_path)) {
                        $count++;
                    } else {
                        $found = false;
                        $new_file_name = $name . '-' . $count . $ext;
                    }
                }
                return $new_file_name;
            }
        }

        return $filename;
    }

    /**
     * Create WP global variables
     *
     * @since  1.0.0
     *
     * @access  public
     * @return  Object
     */
    public function register_global_variables()
    {
        global $acoofmService;

        if (acoofm_is_service_enabled()) {
            $current_service = acoofm_get_service('slug');
            switch ($current_service) {
                case 's3':
                    $acoofmService = new ACOOFMF_S3;
                    break;
                case 'google':
                    $acoofmService = new ACOOFMF_GOOGLE;
                    break;
                case 'ocean':
                    $acoofmService = new ACOOFMF_DIGITALOCEAN;
                    break;
                case 'r2':
                    $acoofmService = new ACOOFMF_R2;
                    break;
                case 'minio':
                    $acoofmService = new ACOOFMF_MINIO;
                    break;
                default:$acoofmService = false;
            }
        }
    }

    /**
     * Print the global data in js
     *
     * @since  1.0.0
     * @access  public
     * @return  string
     */
    public function print_global_data()
    {
        $wcpa_global_vars = array();

        wp_localize_script($this->token . '-frontend', 'acoofm_global_vars', $wcpa_global_vars);
    }

    /*
    * Handle Post Type registration all here
     */
    public function init()
    {
    }

    /**
     * Ensures only one instance of ACOOFMF_FRONTEND is loaded or can be loaded.
     *
     * @param string $file Plugin root file path.
     * @return Main ACOOFMF_FRONTEND instance
     * @since 1.0.0
     * @static
     */
    public static function instance($file = '')
    {
        if (is_null(self::$instance)) {
            self::$instance = new self($file);
        }
        return self::$instance;
    }

    /**
     * Load Front End CSS.
     *
     * @access  public
     * @return  void
     * @since   1.0.0
     */
    public function frontend_enqueue_styles()
    {
        if(acoofm_is_pro_active()) return;
        // wp_register_style($this->token . '-frontend', esc_url($this->assets_url) . 'css/frontend.css', array(), $this->version);
        // wp_enqueue_style($this->token . '-frontend');
    }

    /**
     * Load Front End JS.
     *
     * @access  public
     * @return  void
     * @since   1.0.0
     */
    public function frontend_enqueue_scripts()
    {
        if(acoofm_is_pro_active()) return;
        // wp_register_script($this->token . '-frontend', esc_url($this->assets_url) . 'js/frontend.js', array(), $this->version, true);
        // wp_enqueue_script($this->token . '-frontend');
        $this->print_global_data();
    }
}
