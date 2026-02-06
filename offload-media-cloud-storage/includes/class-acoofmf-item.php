<?php

/**
 * Manage All Server Uploaded Items
 *
 * @class   ACOOFMF_ITEM
 *
 * It is used to divide functionality of database items
 */

if (!defined('ABSPATH')) {
    exit;
}

// Libraries

class ACOOFMF_ITEM
{

    /**
     * The version number.
     *
     * @var     string
     * @access  public
     * @since   1.0.0
     */
    public $version;

    /**
     * The token.
     *
     * @var     string
     * @access  public
     * @since   1.0.0
     */
    public $token;

    /**
     * The main plugin file.
     *
     * @var     string
     * @access  public
     * @since   1.0.0
     */
    public $file;

    /**
     * The main plugin directory.
     *
     * @var     string
     * @access  public
     * @since   1.0.0
     */
    public $dir;

    /**
     * The plugin assets directory.
     *
     * @var     string
     * @access  public
     * @since   1.0.0
     */
    public $assets_dir;

    /**
     * The plugin Configuration.
     *
     * @var     array
     * @access  protected
     * @since   1.0.0
     */

    protected $config;

    /**
     * The plugin Settings.
     *
     * @var     array
     * @access  protected
     * @since   1.0.0
     */

    protected $settings;

    /**
     * The plugin assets URL.
     *
     * @var     string
     * @access  public
     * @since   1.0.0
     */

    public $assets_url;

    /**
     * The plugin hook suffix.
     *
     * @var     array
     * @access  public
     * @since   1.0.0
     */
    public $hook_suffix = array();

    /**
     * Service Bucket name.
     *
     * @var     string
     * @access  protected
     * @since   1.0.0
     */
    protected $bucket_name = '';

    /**
     * Service Bucket Region.
     *
     * @var     string
     * @access  protected
     * @since   1.0.0
     */
    protected $region = '';

    /**
     * Service
     *
     * @var     string
     * @access  protected
     * @since   1.0.0
     */
    protected $service = '';

    /**
     * Constructor function.
     *
     * @access  public
     * @param string $file plugin start file path.
     * @since   1.0.0
     */
    public function __construct($file = '')
    {
        $this->version = ACOOFM_VERSION;
        $this->token = ACOOFM_TOKEN;
        $this->file = $file;
        $this->dir = dirname($this->file);
        $this->assets_dir = trailingslashit($this->dir) . 'assets';
        $this->assets_url = esc_url(trailingslashit(plugins_url('/assets/', $this->file)));
        $plugin = plugin_basename($this->file);
        $this->config = acoofm_get_option('credentials', []);
        $this->settings = acoofm_get_option('settings', []);
        $this->service = acoofm_get_service('slug');

        if (isset($this->config['bucket_name'])) {
            $this->bucket_name = $this->config['bucket_name'];
        }
        if (isset($this->config['region'])) {
            $this->region = $this->config['region'];
        }
    }

    /**
     * Add Item In database
     * @since 1.0.0
     * @param
     */
    public function add(
        $source_id,
        $url,
        $path,
        $source_path,
        $meta,
        $source_type = 'media-library',
        $is_private = 0
    ) {
        global $wpdb;
        $item_id = false;

        $data = array(
            'provider' => $this->service,
            'region' => $this->region,
            'bucket' => $this->bucket_name,
            'source_id' => $source_id,
            'source_path' => $source_path,
            'source_type' => $source_type,
            'url' => $url,
            'path' => $path,
            'is_private' => $is_private,
            'extra_info' => maybe_serialize($meta),
        );
        if ($wpdb->insert(ACOOFM_ITEM_TABLE, $data)) {
            $item_id = $wpdb->insert_id;
            $data['id'] = $item_id;
            acoofm_set_cache($source_id, $data, $source_id);
            add_post_meta((int) $source_id, ACOOFM_ATTACHMENT_META_KEY, $item_id);
        }

        return $item_id;
    }

    /**
     * Get Item from database
     * @since 1.0.0
     * @param
     */
    public function get($source_id)
    {
        global $wpdb;
        if (isset($source_id) && !empty($source_id)) {
            $data = acoofm_get_cache($source_id, $source_id);
            if ($data === false || $data['provider'] !== $this->service) {
                $results = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . ACOOFM_ITEM_TABLE . " WHERE source_id = %d AND provider = %s AND bucket = %s LIMIT 1", $source_id, $this->service, $this->bucket_name), ARRAY_A);
                if ($wpdb->last_error || null === $results || !(isset($results) && !empty($results))) {
                    return false;
                }
                $data = $results[0];
                acoofm_set_cache($source_id, $data, $source_id);
            }
            return $data;
        }
        return false;
    }

    /**
     * Get service url of item from database
     * @since 1.0.0
     * @param
     */
    public function get_url($source_id)
    {
        if ($data = $this->get($source_id)) {
            if (
                isset($this->settings['enable_presigned']) && $this->settings['enable_presigned'] &&
                isset($this->settings['presigned_expire']) && !empty($this->settings['presigned_expire']) &&
                isset($data['path']) && !empty($data['path'])
            ) {
                $preSignedUrl = acoofm_get_cache('presigned_url_full_' . $source_id, $source_id);
                if ($preSignedUrl === false) {
                    global $acoofmService, $acoofmProService;
                    $service = null;
                    if (isset($acoofmService) && is_object($acoofmService) && method_exists($acoofmService, 'get_presigned_url')) {
                        $service = $acoofmService;
                    } elseif (isset($acoofmProService) && is_object($acoofmProService) && method_exists($acoofmProService, 'get_presigned_url')) {
                        $service = $acoofmProService;
                    }
                    $result = array();
                    $savedPUrl = $this->acoofm_get_saved_presigned_url($source_id, 'full');
                    $force = apply_filters('acoofm_force_to_change_presigned_url', false);
                    // delete_post_meta( $source_id, 'acoofm_presigned_urls' );

                    if ($service && (!$savedPUrl || $force)) {
                        $result = $service->get_presigned_url($data['path']);
                        $saved = $this->acoofm_update_presigned_url($result['file_url'], $source_id, 'full');
                    }else if (!empty($savedPUrl)){
                        $result['success'] = true;
                        $result['file_url'] = $savedPUrl;
                        $result['code'] = 200;
                        $result['Message'] = 'Got Presigned URL Successfully';
                    }

                    if (isset($result)) {
                        if (isset($result['success']) && $result['success']) {
                            $preSignedUrl = $this->may_generate_cdn_url($result['file_url'], $data['path']);
                            $expireMinutes = (int) $this->settings['presigned_expire'];
                            $expireSeconds = $expireMinutes * 60;

                            acoofm_set_cache('presigned_url_full_' . $source_id, $preSignedUrl, $source_id, $expireSeconds);
                        }
                    }
                }
                return $preSignedUrl;
            } else if (isset($data['url']) && !empty($data['url'])) {
                $deleted = delete_post_meta( $source_id, 'acoofm_presigned_urls' );
                return $this->may_generate_cdn_url($data['url'], $data['path']);
            }
        }
        return false;
    }

    public function acoofm_get_saved_presigned_url($attachment_id, $size){
        $url = '';
        $presigned_urls = get_post_meta( $attachment_id, 'acoofm_presigned_urls', true );
        if(isset($presigned_urls) && isset($presigned_urls[$size])){
            $url = $presigned_urls[$size];
        }
        return $url;
    }

    public function acoofm_update_presigned_url($url, $attachment_id, $size = 'full') {
        $presigned_urls = get_post_meta($attachment_id, 'acoofm_presigned_urls', true);

        if (!is_array($presigned_urls)) {
            $presigned_urls = [];
        }

        $presigned_urls[$size] = $url;
        return update_post_meta($attachment_id, 'acoofm_presigned_urls', $presigned_urls);
    }




    /**
     * Get extra values of item from database
     * @since 1.0.0
     * @param
     */
    public function get_extras($source_id)
    {

        if ($data = $this->get($source_id)) {
            if (isset($data['extra_info']) && !empty($data['extra_info'])) {
                return unserialize($data['extra_info']);
            }
        }
        return false;
    }

    /**
     * Get service path of item from database
     * @since 1.0.0
     * @param
     */
    public function get_path($source_id)
    {
        if ($data = $this->get($source_id)) {
            if (isset($data['path']) && !empty($data['path'])) {
                return $data['path'];
            }
        }
        return false;
    }

    /**
     * Get service path of item from database
     * @since 1.0.0
     * @param
     */
    public function moveToLocal($source_id, $size = 'full', $all = false)
    {
        $local_files = array();
        $localfile   = false;
        $upload_dir  = wp_get_upload_dir();

        if ($data = $this->get((int)$source_id)) {
            global $acoofmService, $acoofmProService;
            if($all) {
                if (
                    isset($data['source_path']) && !empty($data['source_path']) &&
                    isset($data['path']) && !empty($data['path'])
                ) {
                    $file_path = trailingslashit($upload_dir['basedir']) . $data['source_path'];
                    if(!file_exists($file_path)) {
                        $service = null;
                        if (isset($acoofmService) && is_object($acoofmService) && method_exists($acoofmService, 'object_to_local')) {
                            $service = $acoofmService;
                        } elseif (isset($acoofmProService) && is_object($acoofmProService) && method_exists($acoofmProService, 'object_to_local')) {
                            $service = $acoofmProService;
                        }
                        if ($service && $service->object_to_local($data['path'], $file_path)) {
                            $local_files['full'] = $file_path;
                        }
                    } else {
                        $local_files['full']   = $file_path;
                    }
                }
                $extras = $acoofmItem->get_extras((int) $source_id) ? $acoofmItem->get_extras((int) $source_id) : [];
                if (isset($extras['sizes']) && !empty($extras['sizes'])) {
                    $sizes = $extras['sizes'];
                    if(isset($sizes[$size]) && !empty($sizes[$size])) {
                        $sub_file_path = trailingslashit($upload_dir['basedir']) . $sizes[$size]['source_path'];
                        if(!file_exists($sub_file_path)) {
                            $service = null;
                            if (isset($acoofmService) && is_object($acoofmService) && method_exists($acoofmService, 'object_to_local')) {
                                $service = $acoofmService;
                            } elseif (isset($acoofmProService) && is_object($acoofmProService) && method_exists($acoofmProService, 'object_to_local')) {
                                $service = $acoofmProService;
                            }
                            if ($service && $service->object_to_local($sizes[$size]['path'], $sub_file_path)) {
                                $local_files[$size] = $sub_file_path;
                            }
                        } else {
                            $local_files[$size]   = $sub_file_path;
                        }
                    }
                }
                return !empty($local_files) ? $local_files : false;
            } else {
                if($size === 'full') {
                    if (
                        isset($data['source_path']) && !empty($data['source_path']) &&
                        isset($data['path']) && !empty($data['path'])
                    ) {
                        $file_path = trailingslashit($upload_dir['basedir']) . $data['source_path'];
                        $moved     = true;
                        if(!file_exists($file_path)) {
                            $service = null;
                            if (isset($acoofmService) && is_object($acoofmService) && method_exists($acoofmService, 'object_to_local')) {
                                $service = $acoofmService;
                            } elseif (isset($acoofmProService) && is_object($acoofmProService) && method_exists($acoofmProService, 'object_to_local')) {
                                $service = $acoofmProService;
                            }
                            if ($service) {
                                $moved  = $service->object_to_local($data['path'], $file_path);
                            }
                        } 
                        if($moved){
                            $localfile = $file_path;
                        }
                    }
                } else {
                    $extras = $acoofmItem->get_extras((int) $source_id) ? $acoofmItem->get_extras((int) $source_id) : [];
                    if (isset($extras['sizes']) && !empty($extras['sizes'])) {
                        $sizes = $extras['sizes'];
                        if(isset($sizes[$size]) && !empty($sizes[$size])) {
                            $sub_file_path = trailingslashit($upload_dir['basedir']) . $sizes[$size]['source_path'];
                            $moved  = true;
                            if(!file_exists($sub_file_path)) {
                                $service = null;
                                if (isset($acoofmService) && is_object($acoofmService) && method_exists($acoofmService, 'object_to_local')) {
                                    $service = $acoofmService;
                                } elseif (isset($acoofmProService) && is_object($acoofmProService) && method_exists($acoofmProService, 'object_to_local')) {
                                    $service = $acoofmProService;
                                }
                                if ($service) {
                                    $moved  = $service->object_to_local($sizes[$size]['path'], $sub_file_path);
                                }
                            } 
                            if($moved) {
                                $localfile = $sub_file_path;
                            }
                        }
                    }
                } 
                return $localfile;
            }
        }
        return false;
    }

    /**
     * Get service url of item from database
     * @since 1.0.0
     *
     */
    public function get_thumbnail_url($source_id, $size = 'thumbnail')
    {
        if ($data = $this->get($source_id)) {
            if ($size === 'full') {
                if (
                    isset($this->settings['enable_presigned']) && $this->settings['enable_presigned'] &&
                    isset($data['path']) && !empty($data['path'])
                ) {
                    $preSignedUrl = acoofm_get_cache('presigned_url_full_' . $source_id, $source_id);
                    if ($preSignedUrl === false) {

                        global $acoofmService, $acoofmProService;
                        $service = null;
                        if (isset($acoofmService) && is_object($acoofmService) && method_exists($acoofmService, 'get_presigned_url')) {
                            $service = $acoofmService;
                        } elseif (isset($acoofmProService) && is_object($acoofmProService) && method_exists($acoofmProService, 'get_presigned_url')) {
                            $service = $acoofmProService;
                        }
                        $result = false;
                        if ($service) {
                            $result = $service->get_presigned_url($data['path']);
                        }

                        if (!empty($result)) {
                            if (isset($result['success']) && $result['success']) {
                                $preSignedUrl = $this->may_generate_cdn_url($result['file_url'], $data['path']);
                                $expireMinutes = (int) (isset($this->settings['presigned_expire']) && !empty($this->settings['presigned_expire']))
                                ? $this->settings['presigned_expire']
                                : 20;
                                $expireSeconds = $expireMinutes * 60;

                                acoofm_set_cache('presigned_url_full_' . $source_id, $preSignedUrl, $source_id, $expireSeconds);
                            }
                        }
                    }
                    return $preSignedUrl;
                } else if (isset($data['url']) && !empty($data['url'])) {
                    return $this->may_generate_cdn_url($data['url'], $data['path']);
                }
                return false;
            } else if (isset($data['extra_info']) && !empty($data['extra_info'])) {
                $extra = unserialize($data['extra_info']);
                if (
                    isset($extra) && !empty($extra) &&
                    isset($extra['sizes']) && !empty($extra['sizes']) &&
                    isset($extra['sizes'][$size]) && !empty($extra['sizes'][$size])
                ) {
                    $thumb = $extra['sizes'][$size];
                    if (
                        isset($this->settings['enable_presigned']) && $this->settings['enable_presigned'] &&
                        isset($thumb['path']) && !empty($thumb['path'])
                    ) {
                        $preSignedUrl = acoofm_get_cache('presigned_url_' . $size . '_' . $source_id, $source_id);
                        if ($preSignedUrl === false) {
                            global $acoofmService, $acoofmProService;
                            $service = null;
                            if (isset($acoofmService) && is_object($acoofmService) && method_exists($acoofmService, 'get_presigned_url')) {
                                $service = $acoofmService;
                            } elseif (isset($acoofmProService) && is_object($acoofmProService) && method_exists($acoofmProService, 'get_presigned_url')) {
                                $service = $acoofmProService;
                            }
                            // $result = false;
                            // if ($service) {
                            //     $result = $service->get_presigned_url($thumb['path']);
                            // }

                            $result = array();
                            $savedPUrl = $this->acoofm_get_saved_presigned_url($source_id, 'full');
                            $force = apply_filters('acoofm_force_to_change_presigned_url', false);
                            // delete_post_meta( $source_id, 'acoofm_presigned_urls' );

                            if ($service && (!$savedPUrl || $force)) {
                                $result = $service->get_presigned_url($data['path']);
                                $saved = $this->acoofm_update_presigned_url($result['file_url'], $source_id, 'full');
                            }else if (!empty($savedPUrl)){
                                $result['success'] = true;
                                $result['file_url'] = $savedPUrl;
                                $result['code'] = 200;
                                $result['Message'] = 'Got Presigned URL Successfully';
                            }

                            if (!empty($result)) {
                                if (isset($result['success']) && $result['success']) {
                                    $preSignedUrl = $this->may_generate_cdn_url($result['file_url'], $thumb['path']);
                                    $expireMinutes = (int) (isset($this->settings['presigned_expire']) && !empty($this->settings['presigned_expire']))
                                    ? $this->settings['presigned_expire']
                                    : 20;
                                    $expireSeconds = $expireMinutes * 60;

                                    acoofm_set_cache('presigned_url_' . $size . '_' . $source_id, $preSignedUrl, $source_id, $expireSeconds);
                                }
                            }
                        }
                        return $preSignedUrl;
                    } else if (isset($thumb['url']) && !empty($thumb['url'])) {
                        return $this->may_generate_cdn_url($thumb['url'], $thumb['path']);
                    }
                } else if(
                    $size == 'original' &&
                    isset($extra) && !empty($extra) &&
                    isset($extra['original']) && !empty($extra['original'])
                ) {
                    $original = $extra['original'];
                    if (
                        isset($this->settings['enable_presigned']) && $this->settings['enable_presigned'] &&
                        isset($original['key']) && !empty($original['key'])
                    ) {
                        $preSignedUrl = acoofm_get_cache('presigned_url_original_' . $source_id, $source_id);
                        if ($preSignedUrl === false) {
                            global $acoofmService, $acoofmProService;
                            $service = null;
                            if (isset($acoofmService) && is_object($acoofmService) && method_exists($acoofmService, 'get_presigned_url')) {
                                $service = $acoofmService;
                            } elseif (isset($acoofmProService) && is_object($acoofmProService) && method_exists($acoofmProService, 'get_presigned_url')) {
                                $service = $acoofmProService;
                            }
                            // $result = false;
                            // if ($service) {
                            //     $result = $service->get_presigned_url($original['key']);
                            // }

                            $result = array();
                            $savedPUrl = $this->acoofm_get_saved_presigned_url($source_id, 'full');
                            $force = apply_filters('acoofm_force_to_change_presigned_url', false);
                            // delete_post_meta( $source_id, 'acoofm_presigned_urls' );

                            if ($service && (!$savedPUrl || $force)) {
                                $result = $service->get_presigned_url($data['path']);
                                $saved = $this->acoofm_update_presigned_url($result['file_url'], $source_id, 'full');
                            }else if (!empty($savedPUrl)){
                                $result['success'] = true;
                                $result['file_url'] = $savedPUrl;
                                $result['code'] = 200;
                                $result['Message'] = 'Got Presigned URL Successfully';
                            }

                            if (!empty($result)) {
                                if (isset($result['success']) && $result['success']) {
                                    $preSignedUrl = $this->may_generate_cdn_url($result['file_url'], $original['key']);
                                    $expireMinutes = (int) (isset($this->settings['presigned_expire']) && !empty($this->settings['presigned_expire']))
                                    ? $this->settings['presigned_expire']
                                    : 20;
                                    $expireSeconds = $expireMinutes * 60;

                                    acoofm_set_cache('presigned_url_original_' . $source_id, $preSignedUrl, $source_id, $expireSeconds);
                                }
                            }
                        }
                        return $preSignedUrl;
                    } else if (isset($original['file_url']) && !empty($original['file_url'])) {
                        return $this->may_generate_cdn_url($original['file_url'], $original['key']);
                    }
                }
            }
        }
        return false;
    }

    /**
     * Function to update item in data base
     * @since 1.0.0
     */
    public function update($source_id, $data)
    {
        global $wpdb;
        if (isset($source_id) && !empty($source_id)) {

            $data['provider'] = $this->service;
            $data['region'] = $this->region;
            $data['bucket'] = $this->bucket_name;

            $rows = $wpdb->update(ACOOFM_ITEM_TABLE, $data, array('source_id' => $source_id));
            if ($wpdb->last_error || false === $rows) {
                return false;
            }

            acoofm_delete_cache($source_id, $source_id);
            $current_data = $this->get($source_id);
            return true;
        }
        return false;
    }

    /**
     * Is Private
     * @since 1.1.8
     */
    public function is_private($source_id) {
        global $wpdb;
        if (isset($source_id) && !empty($source_id)) {
            $attachmentItem = $this->get($source_id);
            if(empty($attachmentItem)) return false;
            
            return !!(int)$attachmentItem['is_private'];
        }
        return false;
    }
    

    /**
     * Function to delete item from data base
     * @since 1.0.0
     */
    public function delete($source_id)
    {
        global $wpdb;
        if (isset($source_id) && !empty($source_id)) {
            $rows = $wpdb->delete(ACOOFM_ITEM_TABLE, array('source_id' => $source_id));
            if ($wpdb->last_error || false === $rows) {
                return false;
            }
            acoofm_delete_cache($source_id, $source_id);
            delete_post_meta($source_id, ACOOFM_ATTACHMENT_META_KEY);
            return true;
        }
        return false;
    }

    /**
     * Get similar existing files like origin path
     * @since 1.0.0
     */
    public function get_similar_files_by_path($path)
    {
        global $wpdb;
        if (isset($path) && !empty($path)) {
            $results = $wpdb->get_results("SELECT source_path FROM " . ACOOFM_ITEM_TABLE . " WHERE source_path LIKE '$path%'", ARRAY_A);
            if ($wpdb->last_error || null === $results || !(isset($results) && !empty($results))) {
                return false;
            }
            $source_paths = array();
            foreach ($results as $row) {
                $source_paths[] = $row['source_path'];
            }
            return $source_paths;
        }
        return false;
    }

    /**
     * Get images from the database that match the provided file information
     *
     * @param array $files Array of file information with 'basename' and 'extension' keys
     * @return array|false Array of images with their sizes, or false if no matches found
     */
    public function get_images_by_files($files)
    {
        global $wpdb;
        
        // Ensure ARRAY_A constant is defined for database results format
        if (!defined('ARRAY_A')) {
            define('ARRAY_A', 'ARRAY_A');
        }
        
        // Return false if no files provided
        if (empty($files)) {
            return false;
        }
        
        // Step 1: Filter files to only include valid ones (non-empty basename, exclude CSS/JS/PHP)
        $valid_files = array();
        foreach ($files as $file) {
            if (!empty($file['basename']) && 
                isset($file['extension']) && 
                !in_array(strtolower($file['extension']), ['css', 'js', 'php'])) {
                $valid_files[] = $file['basename'];
            }
        }
        
        // Return false if no valid files remain
        if (empty($valid_files)) {
            return false;
        }
        
        // Step 2: Build a secure SQL query as a single string with multiple OR conditions
        $conditions = array();
        foreach ($valid_files as $basename) {
            $safe_basename = $wpdb->esc_like($basename);
            $conditions[] = $wpdb->prepare(
                "(source_path LIKE %s OR path LIKE %s OR extra_info LIKE %s)",
                '%' . $safe_basename . '%',
                '%' . $safe_basename . '%',
                '%' . $safe_basename . '%'
            );
        }
        
        // Combine all file conditions with OR
        $where_clause = implode(' OR ', $conditions);
        
        // Step 3: Execute the query with a single WHERE clause
        $query = "SELECT * FROM " . ACOOFM_ITEM_TABLE . " WHERE " . $where_clause;
        $results = $wpdb->get_results($query, ARRAY_A);
        
        // Return false if query failed or returned no results
        if ($wpdb->last_error || empty($results)) {
            return false;
        }
        
        // Step 4: Process results into the expected format
        $images = array();
        foreach ($results as $item) {
            $source_id = $item['source_id'];
            
            // Initialize this image entry if it doesn't exist yet
            if (!isset($images[$source_id])) {
                $images[$source_id] = array('url' => $item['url']);
            }
            
            // Process extra_info if it exists
            if (!empty($item['extra_info'])) {
                // Use @ to suppress potential unserialize warnings
                $extra = @unserialize($item['extra_info']);
                
                // Add size information if available
                if (!empty($extra) && !empty($extra['sizes'])) {
                    if (!isset($images[$source_id]['sizes'])) {
                        $images[$source_id]['sizes'] = array();
                    }
                    
                    // Add each size's URL to the result
                    foreach ($extra['sizes'] as $key => $size) {
                        if (isset($size['url'])) {
                            $images[$source_id]['sizes'][$key]['url'] = $size['url'];
                        }
                    }
                }
            }
        }
        
        return $images;
    }

    /**
     * Checking Item that is served by provider And Is rewrite URL is enabled
     * @since 1.0.0
     * @param
     */
    public function is_available_from_provider($source_id, $check_rewrite = true)
    {
        $item_id = acoofm_get_post_meta($source_id, ACOOFM_ATTACHMENT_META_KEY, true, true);
        if(!(isset($item_id) && !empty($item_id))) {
            return false;
        }

        $provider = $this->get_provider($source_id);
        if (
            isset($provider) && !empty($provider) &&
            isset($provider['slug']) && !empty($provider['slug']) &&
            $provider['slug'] == $this->service
        ) {
            if(
                ($check_rewrite && (isset($this->settings['rewrite_url']) && $this->settings['rewrite_url'])) ||
                !$check_rewrite
            ) {
                return true;
            } 
        }
        return false;
    }

    /**
     * Get item specific provider
     * @since 1.0.0
     * @param
     */
    public function get_provider($source_id)
    {
        if ($data = $this->get($source_id)) {
            if (isset($data['provider'])) {
                $provider_name = '';
                $provider = $data['provider'];

                switch ($provider) {
                    case 's3':
                        $provider_name = __('AWS/S3', 'offload-media-cloud-storage');
                        break;
                    case 'google':
                        $provider_name = __('Google Cloud Storage', 'offload-media-cloud-storage');
                        break;
                    case 'ocean':
                        $provider_name = __('Digital Ocean Spaces', 'offload-media-cloud-storage');
                        break;
                    case 'r2':
                        $provider_name = __('Cloudflare R2', 'offload-media-cloud-storage');
                        break;
                    default:
                        $provider_name = '';
                }

                return array('slug' => $provider, 'label' => $provider_name);
            }
        }
        return false;
    }

    /**
     * Checking Item that is served by provider
     * @since 1.0.0
     * @param
     */
    public function get_region($source_id)
    {
        if ($data = $this->get($source_id)) {
            if (isset($data['region'])) {
                return $data['region'];
            }
        }
        return false;
    }

    /**
     * CDN url generate
     * @since 1.0.0
     *
     */
    public function may_generate_cdn_url($url, $path)
    {
        $new_url = $url;

        if (
            !empty($this->settings['enable_cdn']) &&
            !empty($this->settings['cdn_url']) &&
            !empty($url)
        ) {
            $parts = parse_url($url);
            if ($parts && isset($parts['scheme'], $parts['host'])) {
                $base_url = $parts['scheme'] . '://' . $parts['host'];
                $new_url = str_replace(trailingslashit($base_url), trailingslashit($this->settings['cdn_url']), $url);
            }
        }

        // remove duplicate slashes safely
        $new_url = preg_replace('#(?<!:)//+#', '/', $new_url);

        $cdn_url = isset($this->settings['cdn_url']) ? $this->settings['cdn_url'] : '';
        $new_url = apply_filters('acoofm_cdn_url', $new_url, $url, $cdn_url);

        return $new_url;
    }

    /**
     * Get Columns
     * 
     * @since 1.0.0
     * 
     */

    public function get_columns($provider = null, $columns = '*', $where='1', $limit = PHP_INT_MAX, $offset = 0) {
        global $wpdb;
        $query = "SELECT 
                        $columns FROM " . ACOOFM_ITEM_TABLE . " 
                    WHERE 
                        ($where) AND
                        provider='$provider'
                    LIMIT $offset,$limit";

        $results = $wpdb->get_results($query, ARRAY_A);

        if ($wpdb->last_error || null === $results || !(isset($results) && !empty($results))) {
            return [];
        }

        return $results;
    }
}
