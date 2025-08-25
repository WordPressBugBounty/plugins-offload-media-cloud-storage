<?php

/**
 * Manage URL Rewrite Compatibility here
 *
 * @class   ACOOFMF_REWRITEURL
 * 
 * 
 */

if (!defined('ABSPATH')) {
    exit;
}

//

class ACOOFMF_REWRITEURL {

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
    public static $version;

    /**
     * The token.
     *
     * @var     string
     * @access  public
     * @since   1.0.0
     */
    public static $token;

    /**
     * The main plugin file.
     *
     * @var     string
     * @access  public
     * @since   1.0.0
     */
    public static $file;

    /**
     * The main plugin directory.
     *
     * @var     string
     * @access  public
     * @since   1.0.0
     */
    public static $dir;

    /**
     * The plugin assets directory.
     *
     * @var     string
     * @access  public
     * @since   1.0.0
     */
    public static $assets_dir;

    /**
     * The plugin assets URL.
     *
     * @var     string
     * @access  public
     * @since   1.0.0
     */

    public static $assets_url;
 
    /**
     * The plugin hook suffix.
     *
     * @var     array
     * @access  public
     * @since   1.0.0
     */
    public static $hook_suffix = array();

    /**
     * The meta stored urls
     *
     * @var     array
     * @access  public
     * @since   1.0.0
     */
    public static $stored_items = [];

    /**
     * Service
     *
     * @var     string
     * @access  protected
     * @since   1.0.0
     */
    protected static $service='';


    /**
     * The plugin Settings.
     *
     * @var     array
     * @access  protected
     * @since   1.0.0
     */
    protected static $settings;
  

    /**
     * Id of the page
     *
     * @var     int
     * @access  public
     * @since   1.0.0
     */
    public static $pageID = 0;

    /**
     * Timestamp That need to reset cache data
     *
     * @var     int
     * @access  public
     * @since   1.0.0
     */
    public static $dataExpiryVersion = 0;
  

    /**
     * Constructor function.
     *
     * @access  public
     * @param string $file plugin start file path.
     * @since   1.0.0
     */
    public function __construct($file = ''){
        self::$version              = ACOOFM_VERSION;
        self::$token                = ACOOFM_TOKEN;
        self::$file                 = $file;
        self::$dir                  = dirname(self::$file);
        self::$assets_dir           = trailingslashit(self::$dir) . 'assets';
        self::$assets_url           = esc_url(trailingslashit(plugins_url('/assets/', self::$file)));
        self::$settings             = acoofm_get_option('settings', []);
        self::$service              = acoofm_get_service('slug');
        self::$dataExpiryVersion    = get_option( ACOOFM_STORED_DATA_VERSION, 0 );

        if ( 
            isset(self::$settings['rewrite_url']) && 
            self::$settings['rewrite_url']  && 
            acoofm_is_service_enabled()
        ) {
            // self::start_buffering();
            add_filter('wp_content_img_tag', array($this, 'acoofmf_custom_modify_img_tag'), 10, 3);
            add_filter('rest_prepare_post', array($this, 'acoofmf_rest_prepare_page'), 10, 3);
            add_filter('rest_prepare_page', array($this, 'acoofmf_rest_prepare_page'), 10, 3);
        }
    }

    /**
     * Filter REST API response for posts/pages to rewrite image URLs to CDN.
     *
     * @param WP_REST_Response $response The response object.
     * @param WP_Post $post The post object.
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response Modified response with rewritten image URLs.
     */
    public function acoofmf_rest_prepare_page($response, $post, $request) {
        // Only proceed if rewrite_url is enabled in settings
        if (!(isset(self::$settings['rewrite_url']) && self::$settings['rewrite_url'])) {
            return $response;
        }

        global $wpdb;

        // Get cached CDN base URLs
        $cdn_urls = get_transient('acoofmf_cdn_base_urls');
        if (!is_array($cdn_urls)) {
            $cdn_urls = [];
        }

        // Check if content exists in the response
        if (isset($response->data['content']) && !empty($response->data['content']['raw'])) {
            $content = $response->data['content']['raw'];

            // Replace image src based on wp-image-{ID} class
            $content = preg_replace_callback(
                '/<img[^>]+class="[^"]*wp-image-(\d+)[^"]*"[^>]*>/',
                function ($matches) use (&$cdn_urls, $wpdb) {
                    $img_tag = $matches[0];
                    $attachment_id = (int) $matches[1];

                    // Get original attachment URL
                    $original_url = wp_get_attachment_url($attachment_id);
                    if (!$original_url) {
                        // Could not get original URL, leave unchanged
                        return $img_tag;
                    }

                    // Check if CDN base URL is cached for this attachment
                    if (!isset($cdn_urls[$attachment_id])) {
                        $item_id = get_post_meta($attachment_id, ACOOFM_ATTACHMENT_META_KEY, true);

                        if (!empty($item_id)) {
                            // Get CDN URL from database
                            $url = false;
                            try {
                                $url = $wpdb->get_var(
                                    $wpdb->prepare("SELECT url FROM " . ACOOFM_ITEM_TABLE . " WHERE id = %d", $item_id)
                                );
                            } catch (Exception $e) {
                                // Log error if needed
                                error_log('CDN URL DB error: ' . $e->getMessage());
                                return $img_tag;
                            }

                            if (!empty($url)) {
                                $cdn_urls[$attachment_id] = dirname($url);
                                set_transient('acoofmf_cdn_base_urls', $cdn_urls, 12 * HOUR_IN_SECONDS); // Cache for 12 hours
                            } else {
                                // CDN URL not found in DB, leave unchanged
                                return $img_tag;
                            }
                        } else {
                            // No item ID found, leave unchanged
                            return $img_tag;
                        }
                    }

                    $cdn_base_url = isset($cdn_urls[$attachment_id]) ? $cdn_urls[$attachment_id] : '';
                    if (empty($cdn_base_url)) {
                        // CDN base URL not found, leave unchanged
                        return $img_tag;
                    }

                    // Rewrite to CDN URL
                    $cdn_url = str_replace(site_url('/wp-content/uploads'), $cdn_base_url, $original_url);

                    // Replace src attribute in the <img> tag
                    $new_img_tag = preg_replace('/src="[^"]*"/', 'src="' . esc_url($cdn_url) . '"', $img_tag);
                    if ($new_img_tag === null) {
                        // preg_replace error, leave unchanged
                        return $img_tag;
                    }

                    return $new_img_tag;
                },
                $content
            );

            // Update the response content
            $response->data['content']['raw'] = $content;
        }

        return $response;
    }

    /**
     * Rewrite URLs in content to use CDN links.
     *
     * @access  public
     * @param   string $contents Content to be processed.
     * @return  string Processed content with URLs rewritten.
     * @since   1.0.0
     */
    public function acoofmf_custom_modify_img_tag($filtered_image, $context, $attachment_id) {
        if (!(isset(self::$settings['rewrite_url']) && self::$settings['rewrite_url'])) {
            return $filtered_image;
        }

        global $wpdb;
        global $acoofmItem;

        // Try to get the cached CDN URLs array
        $cdn_urls = get_transient('acoofmf_cdn_base_urls');
        if (!is_array($cdn_urls)) {
            $cdn_urls = [];
        }

        // Check if URL is already cached for this attachment ID
        if (!isset($cdn_urls[$attachment_id])) {
            $item_id = get_post_meta($attachment_id, ACOOFM_ATTACHMENT_META_KEY, true);

            if (!empty($item_id)) {
                $url = $wpdb->get_var(
                    $wpdb->prepare("SELECT url FROM " . ACOOFM_ITEM_TABLE . " WHERE id = %d", $item_id)
                );

                if (!empty($url)) {
                    $cdn_urls[$attachment_id] = dirname($url);
                    set_transient('acoofmf_cdn_base_urls', $cdn_urls, 12 * HOUR_IN_SECONDS); // Cache for 12 hours
                }
            } else {
                return $filtered_image; // No item ID
            }
        }

        $cdn_base_url = $cdn_urls[$attachment_id];
        if (empty($cdn_base_url)) {
            return $filtered_image; // No URL found in DB or cache
        }

        // Now replace the src in <img> tag
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $filtered_image);

        $imgs = $doc->getElementsByTagName('img');
        foreach ($imgs as $img) {
            $src = $img->getAttribute('src');
            $path = parse_url($src, PHP_URL_PATH);
            $thumbnail_size = self::acoofm_get_size_from_src($path, $attachment_id);
            
            if ($thumbnail_size === 'full') {
                $new_src = $acoofmItem->get_url($attachment_id);
            } else {
                $new_src = $acoofmItem->get_thumbnail_url($attachment_id, $thumbnail_size);
            }

            $img->setAttribute('src', $new_src);
        }

        $body = $doc->getElementsByTagName('body')->item(0);
        $filtered_html = '';
        foreach ($body->childNodes as $child) {
            $filtered_html .= $doc->saveHTML($child);
        }

        return $filtered_html;
    }

    function acoofm_get_size_from_src($src, $attachment_id) {
        if (empty($src) || empty($attachment_id)) {
            return false;
        }

        $meta = wp_get_attachment_metadata($attachment_id);
        if (empty($meta) || !isset($meta['sizes']) || !is_array($meta['sizes'])) {
            return false;
        }

        // $upload_dir = wp_upload_dir();
        // $base_url   = trailingslashit($upload_dir['baseurl']);
        // $base_path  = trailingslashit($upload_dir['basedir']);
        $filename  = basename($src);

        // Loop through registered image sizes
        foreach ($meta['sizes'] as $size_name => $data) {
            if (strpos($filename, $data['file']) !== false) {
                return $size_name;
            }
        }

        // If no match found, assume it's 'full'
        return 'full';
    }



    /**
     * start output buffering
     *
     * @since  1.0.0
     * 
     */

    private static function start_buffering() {
        ob_start( [ __CLASS__, 'end_buffering' ] );
    }


    /**
     * end output buffering and rewrite contents if applicable
     *
     * @since   1.0.0
     *
     * @param   string   $contents - contents from the output buffer
     * @param   integer  $phase - bitmask of PHP_OUTPUT_HANDLER_* constants
     * @return  string   $contents|$rewritten_contents  rewritten contents from the output buffer else unchanged
     */

    private static function end_buffering( $contents, $phase ) {

        if ( $phase & PHP_OUTPUT_HANDLER_FINAL || $phase & PHP_OUTPUT_HANDLER_END ) {
            if ( ! self::bypass_rewrite() ) {

                wp_reset_query();
                global $post;
                self::$pageID = ($post) ? $post->ID : 0; 

                $rewritten_contents = self::rewriter( $contents );

                return $rewritten_contents;
            }
        }

        return $contents;
    }


    /**
     * Rewrite contents
     *
     * @since   1.0.0
     *
     * @param   string  $contents - contents to parse
     * @return  string  $contents|$rewritten_contents  rewritten contents if applicable else unchange
     */


     public static function rewriter($contents) {
        // Early return if requirements aren't met
        if (!is_string($contents) || !(isset(self::$settings['rewrite_url']) && self::$settings['rewrite_url'])) {
            return $contents;
        }
    
        global $acoofmItem;
        $contents = apply_filters('acoofm_contents_before_rewrite', $contents);
        
        // Load cache once
        self::$stored_items = self::get_pool() ?: [];
        
        // Use a single regex to find URLs 
        $urls_regex = '/(http|https)?:?\/\/[^"\'\s<>()\\\]*/';
        
        // Process content in chunks if it's large
        if (strlen($contents) > 500000) { // 500KB chunks
            return self::process_large_content($contents, $urls_regex);
        }
    
        // Process URLs in batches
        preg_match_all($urls_regex, $contents, $matches);
        if (empty($matches[0])) {
            return $contents;
        }
    
        // Collect filtered URLs once
        $filtered_data = self::filter_urls($matches[0]);
        $filtered_urls = $filtered_data['urls'];
        $filtered_files = $filtered_data['files'];
        
        // Skip further processing if no valid URLs found
        if (empty($filtered_urls)) {
            return $contents;
        }
    
        // Get database items in one query
        $matchings_items = $acoofmItem->get_images_by_files($filtered_files);
        
        // Build a lookup table for fast matching
        $url_matches = self::build_url_matches($filtered_urls, $matchings_items);
        
        // Replace URLs efficiently
        $new_content = preg_replace_callback(
            $urls_regex,
            function($pattern_match) use ($url_matches, $acoofmItem) {
                $file_url = $pattern_match[0];
                
                // Check if we have a cached result
                if (isset(self::$stored_items[$file_url])) {
                    $cached_item = self::$stored_items[$file_url];
                    return ($cached_item['found'] && !empty($cached_item['url'])) 
                        ? $cached_item['url'] 
                        : $file_url;
                }
                
                // Check if this URL has a match
                if (isset($url_matches[$file_url])) {
                    $match = $url_matches[$file_url];
                    $url = ($match['size'] == 'full') 
                        ? $acoofmItem->get_url((int)$match['item_id']) 
                        : $acoofmItem->get_thumbnail_url((int)$match['item_id'], $match['size']);
                    
                    // Cache the result
                    self::$stored_items[$file_url] = ['url' => $url ?: '', 'found' => (bool)$url];
                    return $url ?: $file_url;
                }
                
                // No match found, cache the negative result
                self::$stored_items[$file_url] = ['url' => '', 'found' => false];
                return $file_url;
            },
            $contents
        );
        
        // Save the cache but limit its size
        self::limit_cache_size();
        self::set_pool(self::$stored_items);
        
        return apply_filters('acoofm_contents_after_rewrite', $new_content);
    }
    
    // Helper function to process large content in chunks
    private static function process_large_content($contents, $urls_regex) {
        $chunk_size = 500000; // 500KB
        $length = strlen($contents);
        $result = '';
        
        for ($i = 0; $i < $length; $i += $chunk_size) {
            $chunk = substr($contents, $i, $chunk_size);
            $result .= self::rewriter($chunk);
            
            // Clear some memory between chunks
            gc_collect_cycles();
        }
        
        return $result;
    }
    
    // Helper function to filter URLs
    private static function filter_urls($urls) {
        $filtered_urls = [];
        $filtered_files = [];
        
        foreach ($urls as $file_url) {
            $parsed_url = parse_url($file_url);
            if (!isset($parsed_url['path']) || empty($parsed_url['path'])) {
                continue;
            }
            
            $path_info = pathinfo($parsed_url['path']);
            $new_url = acoofm_create_no_query_url($parsed_url);
            
            // Skip if already in cache
            if (isset(self::$stored_items[$new_url])) {
                continue;
            }
            
            if (isset($path_info['extension'], $path_info['filename']) && 
                !empty($path_info['extension']) && !empty($path_info['filename'])) {
                $filtered_urls[] = $new_url;
                $path_info['url'] = $new_url;
                $filtered_files[] = $path_info;
            }
        }
        
        return ['urls' => $filtered_urls, 'files' => $filtered_files];
    }
    
    // Helper function to build URL matches
    private static function build_url_matches($filtered_urls, $matchings_items) {
        $url_matches = [];
        
        if (empty($matchings_items)) {
            return $url_matches;
        }
        
        foreach ($filtered_urls as $file_url) {
            $parsed_url = parse_url($file_url);
            if (!isset($parsed_url['path']) || empty($parsed_url['path'])) {
                continue;
            }
            
            $path_info = pathinfo($parsed_url['path']);
            $basename = $path_info['basename'];
            
            foreach ($matchings_items as $id => $item) {
                // Check main URL
                if (isset($item['url']) && self::urls_match($item['url'], $file_url, $basename)) {
                    $url_matches[$file_url] = ['item_id' => $id, 'size' => 'full'];
                    break;
                }
                
                // Check size URLs
                if (isset($item['sizes']) && !empty($item['sizes'])) {
                    foreach ($item['sizes'] as $s => $sub_size) {
                        if (isset($sub_size['url']) && self::urls_match($sub_size['url'], $file_url, $basename)) {
                            $url_matches[$file_url] = ['item_id' => $id, 'size' => $s];
                            break 2; // Break both loops
                        }
                    }
                }
            }
        }
        
        return $url_matches;
    }
    
    // Helper function to match URLs
    private static function urls_match($source_url, $target_url, $basename) {
        // Direct match
        if ($source_url == $target_url) {
            return true;
        }
        
        // Basename match
        if (substr($source_url, -strlen($basename)) == $basename) {
            return true;
        }
        
        // Path match
        $parsed_source = parse_url($source_url);
        $parsed_target = parse_url($target_url);
        
        if (isset($parsed_source['path'], $parsed_target['path']) && 
            $parsed_source['path'] == $parsed_target['path']) {
            return true;
        }
        
        return false;
    }
    
    // Limit cache size to prevent memory issues
    private static function limit_cache_size() {
        $max_items = 1000; // Adjust based on your needs
        
        if (count(self::$stored_items) > $max_items) {
            // Keep only the most recent items
            self::$stored_items = array_slice(self::$stored_items, -$max_items, $max_items, true);
        }
    }

    // public static function rewriter( $contents ) {

    //     // check rewrite requirements
    //     if ( ! is_string( $contents ) || !(isset(self::$settings['rewrite_url']) && self::$settings['rewrite_url']) ) {
    //         return $contents;
    //     }

    //     global $acoofmItem;
    //     $filtered_urls     = array();
    //     $filtered_files    = array();
    //     $upload_dir        = wp_get_upload_dir();

    //     $contents = apply_filters( 'acoofm_contents_before_rewrite', $contents );

    //     self::$stored_items = self::get_pool() ?: [];

    //     $urls_regex = '/(http|https)?:?\/\/[^"\'\s<>()\\\]*/';

    //     // Match all Media URLS that has extension
    //     preg_match_all($urls_regex, $contents, $matches);
    //     if(!empty($matches)) {
    //         $urls = $matches[0];
    //         if(isset($urls) && !empty($urls)) {
    //             foreach($urls as $file_url) {
    //                 $parsed_url = parse_url($file_url);
    //                 if(isset($parsed_url['path']) && !empty($parsed_url['path'])) {
    //                     $path_info = pathinfo($parsed_url['path']);
    //                     $new_url = acoofm_create_no_query_url($parsed_url);
    //                     if(
    //                         isset($path_info['extension']) && !empty($path_info['extension']) &&
    //                         isset($path_info['filename']) && !empty($path_info['extension'])
    //                      ) {
    //                         if(!(isset(self::$stored_items[$new_url]) && !empty(self::$stored_items[$new_url]))) {
    //                             $filtered_urls[]    = $new_url;
    //                             $path_info['url']   = $new_url;
    //                             $filtered_files[]   = $path_info;
    //                         } 
    //                     }
    //                 } 
    //             }
    //         }
    //     }

    //     //Get all database items That matches the Urls
    //     $matchings_items = $acoofmItem->get_images_by_files($filtered_files);

    //     $new_content = preg_replace_callback( $urls_regex, 
    //     function($pattern_match) use ($filtered_urls, $matchings_items, $upload_dir)  {
    //         global $acoofmItem;
    //         $found          = false;
    //         $size           = 'full';
    //         $item_id        = 0;
    //         $found_array    = array();
            
    //         $file_url   = $pattern_match[0]; // Get first URL From content that matches regex
    //         if(isset($file_url) && !empty($file_url)) {
    //             if(in_array($file_url, $filtered_urls)) { // Check the URL that exist in th e filtered URL
    //                 $parsed_url = parse_url($file_url);
    //                 if(isset($parsed_url['path']) && !empty($parsed_url['path'])) {
    //                     $path_info = pathinfo($parsed_url['path']);
    //                     $new_url = acoofm_create_no_query_url($parsed_url); // Create no query URL
    //                     if(isset($path_info['extension']) && !empty($path_info['extension'])) {
    //                         if(isset(self::$stored_items[$new_url]) && !empty(self::$stored_items[$new_url])){  // Check whether it is already saved in cache
    //                             $cached_item = self::$stored_items[$new_url];
    //                             return (
    //                                     $cached_item['found'] == true && 
    //                                     isset($cached_item['url']) && 
    //                                     !empty($cached_item['url'])
    //                                 ) 
    //                                     ? $cached_item['url'] 
    //                                     : $file_url;
    //                         } else if(isset($matchings_items) && !empty($matchings_items)) {
    //                             foreach($matchings_items as $id=>$item) {
    //                                 if(isset($item['url'])) {
    //                                     $source_url = $item['url'];
    //                                     if($source_url == $new_url) {
    //                                         $found      = true;
    //                                         $item_id    = $id;
    //                                     } 
                                        
    //                                     if(substr($source_url, strlen($source_url)-strlen($path_info['basename']), strlen($path_info['basename'])) == $path_info['basename']) {
    //                                         $found_array[] = array( 'item_id' => $id, 'matched' => $source_url, 'size' => 'full' );
    //                                     } 
                                        
    //                                     if(
    //                                         isset($item['url']) && 
    //                                         substr($item['url'], strlen($item['url'])-strlen($path_info['basename']), strlen($path_info['basename'])) == $path_info['basename']
    //                                     ) {
    //                                         $found_array[] = array( 'item_id' => $id, 'matched' => $item['url'], 'size' => 'full' );
    //                                     }
    //                                 }

    //                                 if(isset($item['sizes']) && !empty($item['sizes'])) {
    //                                     foreach($item['sizes'] as $s=>$sub_size) {
    //                                         if(isset($sub_size['url'])){
    //                                             $sub_source_url = $sub_size['url'];
    //                                             if($sub_source_url == $new_url) {
    //                                                 $found      = true;
    //                                                 $item_id    = $id;
    //                                                 $size       = $s;
    //                                                 break;
    //                                             } 
                                                
    //                                             if(substr($sub_source_url, strlen($sub_source_url)-strlen($path_info['basename']), strlen($path_info['basename'])) == $path_info['basename']) {
    //                                                 $found_array[] = array( 'item_id' => $id, 'matched' => $sub_source_url, 'size' => $s );
    //                                             }  
                                                
    //                                             if(
    //                                                 isset($sub_size['url']) && 
    //                                                 substr($sub_size['url'], strlen($sub_size['url'])-strlen($path_info['basename']), strlen($path_info['basename'])) == $path_info['basename']
    //                                             ) {
    //                                                 $found_array[] = array( 'item_id' => $id, 'matched' => $sub_size['url'], 'size' => $s );
    //                                             }
    //                                         }
    //                                     }
    //                                     if($found) {
    //                                         break;
    //                                     }
    //                                 }
    //                             }


    //                             if(isset($found_array) && !empty($found_array) && !$found) {
    //                                 foreach($found_array as $f_item) {
    //                                     // 7 Means length of "YYYY/MM" directory
    //                                     $possibly_date = substr($path_info['dirname'], strlen($path_info['dirname'])-7, 7);
    //                                     if(preg_match('/^([1-3][0-9]{3})\/(0[1-9]|1[0-2])$/', $possibly_date)) {
    //                                         $dated_file_name = $possibly_date.'/'.$path_info['basename'];
    //                                         if(
    //                                             substr($f_item['matched'], strlen($f_item['matched'])-strlen($dated_file_name), strlen($dated_file_name)) == $dated_file_name
    //                                         ) {
    //                                             $found      = true;
    //                                             $item_id    = $f_item['item_id'];
    //                                             $size       = $f_item['size'];
    //                                             break;
    //                                         }
    //                                     } else {
    //                                         $parsed_new_url         = parse_url($new_url);
    //                                         $parsed_f_item_url      = parse_url($f_item['matched']);
    //                                         $relative_path          = isset($parsed_new_url['path']) ? $parsed_new_url['path'] : '';
    //                                         $relative_path_f_item   = isset($parsed_f_item_url['path']) ? $parsed_f_item_url['path'] : '';
    //                                         if($relative_path == $relative_path_f_item) {
    //                                             $found      = true;
    //                                             $item_id    = $f_item['item_id'];
    //                                             $size       = $f_item['size'];
    //                                             break;
    //                                         }
    //                                     }
    //                                 }
    //                             }

    //                             if($found) {
    //                                 $url = ($size == 'full') 
    //                                             ? $acoofmItem->get_url((int)$item_id) 
    //                                             : $acoofmItem->get_thumbnail_url((int)$item_id, $size);
                                        
    //                                 if($url) {
    //                                     self::$stored_items[$new_url] = array('url'=>$url, 'found' => true);
    //                                 }
    //                                 return $url ? $url : $file_url;
    //                             } 
    //                         }
    //                     }
    //                     self::$stored_items[$new_url] = array('url'=>'', 'found' => false);
    //                 }
    //             }
    //         }
    //         return $file_url;
    //     }, $contents );

    //     self::set_pool(self::$stored_items);

    //     $rewritten_contents = apply_filters( 'acoofm_contents_after_rewrite', $new_content);
    //     return $rewritten_contents;
    // }


    /**
     * Checking rewrite should be bypassed
     *
     * @since   1.0.0
     * @return  boolean  true if rewrite should be bypassed else false
     */

    private static function bypass_rewrite() {

        // bypass rewrite hook
        if ( apply_filters( 'acoofm_bypass_url_rewrite', false ) ) {
            return true;
        }

        // check request method
        if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || $_SERVER['REQUEST_METHOD'] !== 'GET' ) {
            return true;
        }

        $is_admin = apply_filters( 'acoofm_exclude_admin', is_admin() );

        // check conditional tags
        if ( $is_admin || is_trackback() || is_robots() || is_preview() ) {
            return true;
        }

        return false;
    }



    /**
     * Set Frontend Items in Cache
     * @since 1.0.0
     * @return boolean
     */
    private static function set_pool($data){
        if(!empty($data)){
            $updated_data = ['data' => $data, 'version' => self::$dataExpiryVersion];
            if(self::$pageID === 0) {
                update_option( ACOOFM_ITEM_POOL_OPTION_KEY, $updated_data );
            } else {
                update_post_meta( self::$pageID, ACOOFM_ITEM_POOL_META_KEY, $updated_data );
            }
        }
    }
    
    
    
    /**
     * Get Frontend Items from Cache
     * @since 1.0.0
     * @return boolean
     */
    private static function get_pool(){
        $expired    = false;
        $data       = [];
        if(self::$pageID === 0) {
            $data = get_option( ACOOFM_ITEM_POOL_OPTION_KEY, [] );
        } else {
            $meta_data =  acoofm_get_post_meta( self::$pageID, ACOOFM_ITEM_POOL_META_KEY, true );
            $data = $meta_data ? $meta_data : [];
        }

        if(!empty($data) && isset($data['version']) && isset($data['data'])) {
            return ( (int)self::$dataExpiryVersion != (int)$data['version'] ) 
                        ? false 
                        :  $data['data'];                        
        } else {
            return false;
        }
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

}