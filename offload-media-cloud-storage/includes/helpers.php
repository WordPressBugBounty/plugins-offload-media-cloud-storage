<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('acoofm_empty')) {

    /**
     * Check a variable is empty
     * @since 1.0.0
     * @param string|integer|array|float 
     * @return boolean
     */
    function acoofm_empty($var)
    {
        if (is_array($var)) {
            return empty($var);
        } else {
            return ($var === null || $var === false || $var === '');
        }
    }

}

if (!function_exists('acoofm_update_option')) {

    /**
     * Function To update Plugin Specific Wordpress Option
     * @since 1.0.0
     * @return boolean
     */
    function acoofm_update_option($key, $options){
        $current_options = get_option( ACOOFM_OPTION_NAME, array() );
        if(isset($current_options[$key])) {
            if($current_options[$key] === $options) {
                return true;
            }
        }
        $current_options[$key] = $options;
        return update_option( ACOOFM_OPTION_NAME, $current_options, true);
    }

}

if (!function_exists('acoofm_get_option')) {

    /**
     * Function To get Plugin Specific Wordpress Option
     * @since 1.0.0
     * @return array|boolean|string|integer|float|double
     */
    function acoofm_get_option($key, $default=false){
        $current_options = get_option( ACOOFM_OPTION_NAME, array() );
        if(isset($current_options[$key])){
            return $current_options[$key];
        } else {
            return $default;
        }
    }

}

if (!function_exists('acoofm_get_service')) {

    /**
     * Function To get Current Service
     * @since 1.0.0
     * @return array|boolean|string|integer|float|double
     */
    function acoofm_get_service($option='', $default=false){
        $current_service = acoofm_get_option('service');
        if(isset($current_service) && !empty($current_service)){
            if(isset($option) && !empty($option)){
                if(isset($current_service[$option])) {
                    return $current_service[$option];
                } else {
                    return $default;
                }
            } else {
                return $current_service;
            }
        } else {
            return $default;
        }
    }

}

if (!function_exists('acoofm_get_credentials')) {

    /**
     * Function To get Current Bucket
     * @since 1.0.0
     * @return array|boolean|string|integer|float|double
     */
    function acoofm_get_credentials($option='', $default=false){
        $current_service = acoofm_get_option('credentials');
        if(isset($current_service) && !empty($current_service)){
            if(isset($option) && !empty($option)){
                if(isset($current_service[$option])) {
                    return $current_service[$option];
                } else {
                    return $default;
                }
            } else {
                return $current_service;
            }
        } else {
            return $default;
        }
    }

}

if (!function_exists('acoofm_get_settings')) {

    /**
     * Function To get Current settings
     * @since 1.0.0
     * @return array|boolean|string|integer|float|double
     */
    function acoofm_get_settings($option='', $default=false){
        $current_setttings = acoofm_get_option('settings');
        if(isset($current_setttings) && !empty($current_setttings)){
            if(isset($option) && !empty($option)){
                if(isset($current_setttings[$option])) {
                    return $current_setttings[$option];
                } else {
                    return $default;
                }
            } else {
                return $current_setttings;
            }
        } else {
            return $default;
        }
    }
}

if (!function_exists('acoofm_is_service_enabled')) {

    /**
     * Function To check service is enabled
     * @since 1.0.0
     * @return array|boolean|string|integer|float|double
     */
    function acoofm_is_service_enabled(){
        $service    = acoofm_get_option('service', false);

        if(
            isset($service) && !empty($service) &&
            isset($service['slug']) && !empty($service['slug']) 
        ) {
            return true;
        }
        return false;
    }

}


if (!function_exists('acoofm_is_copy_to_server')) {

    /**
     * Function To check is setting is enabled to copy to server
     * @since 1.0.0
     * @return array|boolean|string|integer|float|double
     */
    function acoofm_is_copy_to_server(){
        $settings   = acoofm_get_option('settings', false);
        if(
            isset($settings) && !empty($settings) &&
            isset($settings['copy_to_bucket']) && $settings['copy_to_bucket'] 
        ) {
            return true;
        }
        return false;
    }
}

if (!function_exists('acoofm_is_rewrite_url')) {

    /**
     * Function To check is setting is enabled for rewriting URL
     * @since 1.0.0
     * @return array|boolean|string|integer|float|double
     */
    function acoofm_is_rewrite_url(){
        $settings   = acoofm_get_option('settings', false);
        if(
            isset($settings) && !empty($settings) &&
            isset($settings['rewrite_url']) && $settings['rewrite_url'] 
        ) {
            return true;
        }
        return false;
    }
}

if (!function_exists('acoofm_maybe_convert_size_to_string')) {

    /**
     * Maybe convert size to string
     *
     * @param int   $attachment_id
     * @param mixed $size
     *
     * @return null|string
     */
    function acoofm_maybe_convert_size_to_string( $attachment_id, $size ) {
        if ( is_array( $size ) ) {
            $width  = ( isset( $size[0] ) && $size[0] > 0 ) ? $size[0] : 1;
			$height = ( isset( $size[1] ) && $size[1] > 0 ) ? $size[1] : 1;
			$original_aspect_ratio = $width / $height;
			$meta   = wp_get_attachment_metadata( $attachment_id );

			if ( ! isset( $meta['sizes'] ) || empty( $meta['sizes'] ) ) {
				return false;
			}

			$sizes = $meta['sizes'];
			uasort( $sizes, function ( $a, $b ) {
				// Order by image area
				return ( $a['width'] * $a['height'] ) - ( $b['width'] * $b['height'] );
			} );

			$near_matches = array();

			foreach ( $sizes as $size => $value ) {
				if ( $width > $value['width'] || $height > $value['height'] ) {
					continue;
				}
				$aspect_ratio = $value['width'] / $value['height'];
				if ( $aspect_ratio === $original_aspect_ratio ) {
					return $size;
				}
				$near_matches[] = $size;
			}
			// Return nearest match
			if ( ! empty( $near_matches ) ) {
				return $near_matches[0];
			}
        }

        return $size;
    }
}


if (!function_exists('acoofm_get_attachment_relative_path')) {

    /**
     * Get Image URL and path from URL
     * Return Relative URL and Path of an attachment.
     * @since 1.0.0
     * 
     */
    function acoofm_get_attachment_relative_path($file) {
        if ( isset($file) && !empty($file) ) {
            $uploads            = wp_get_upload_dir();
            $file_path          = '';
            $site_url           = site_url('/');
            $server_base_path   = acoofm_get_settings('base_path');
            if ( $uploads && false === $uploads['error'] ) {

                $uploadDir  = substr( $uploads['baseurl'], strpos( $uploads['baseurl'], $site_url ) + strlen($site_url)); 

                // Get URL and PATH
                if ( 0 === strpos( $file, $uploads['basedir'] ) ||  0 === strpos( $file, $uploads['baseurl'] ) ) {   // If URL is full link 
                    $file_path  = str_replace( $uploads['basedir'], '', $file );
                    $file_path  = str_replace( $uploads['baseurl'], '', $file_path ); // Replace if has URl
                } else if ( 
                    0 === strpos( $file, str_replace('/','\\', $uploads['basedir'] )) ||  
                    0 === strpos( $file, str_replace('\\','/', $uploads['baseurl'] )) 
                ) {   // If URL is full link and the url is slash unified (Like: str_replace('/','\\', $dir))
                    $file_path  = str_replace( str_replace('/','\\', $uploads['basedir'] ), '', $file );
                    $file_path  = str_replace( str_replace('\\','/', $uploads['baseurl'] ), '', $file_path ); // Replace if has URl
                    $file_path  = str_replace('\\','/', $file_path );
                } else if ( false !== strpos( $file, $uploadDir ) ) {   //If URL has sub Directory That matches end of base URL(eg: wp-content/uploads)
                    $fileDir    = dirname( $file );
                    $start_pos  = strpos( $fileDir, $uploadDir ) + strlen($uploadDir); 
                    $subDir     = substr( $fileDir, $start_pos, strlen($fileDir)); // Find Sub Directory
                    $file_name  = wp_basename( $file );

                    $file_path  = trailingslashit($subDir) . $file_name;
                } else if($server_base_path && false !== strpos( $file, trailingslashit($server_base_path))) {
                    $fileDir    = dirname( $file );
                    $start_pos  = strpos( $fileDir, $server_base_path) + strlen($server_base_path); 
                    $subDir     = substr( $fileDir, $start_pos, strlen($fileDir)); // Find Sub Directory
                    $file_name  = wp_basename( $file );

                    $file_path  = trailingslashit($subDir) . $file_name;
                } else if(filter_var($file, FILTER_VALIDATE_URL)) {
                    $parsed     = parse_url($file); 
                    $path       = isset($parsed["path"]) ? $parsed["path"] : '';
                    $query      = isset($parsed["query"]) ? '?'.$parsed["query"] : '';
                    $file_path  = $path. $query;
                } else {
                    $file_path  = $file;
                }

                return apply_filters( 'acoofm_get_relative_file_path_from_upload_directory', untrailingslashit(ltrim( $file_path, '/\\' )), $file );
            } 
        } 
        return false;
    }
}



if (!function_exists('acoofm_generate_object_key')) {

    /**
     * Generate Key for Objects
     * @since 1.0.0
     */
    function acoofm_generate_object_key($file_name, $prefix, $media_absolute_path = '') {
        $upload_path = '';
        $base_path = acoofm_get_settings('base_path');
        $year_month = acoofm_get_settings('year_month');

        if(isset($base_path) && !empty($base_path)) {
            $upload_path.= preg_replace('~/+~', '/', 
                                    str_replace('\\', '/', 
                                        trim($base_path," \n\r\t\v\x00\/ ")
                                    )
                                );
        }
        if(isset($year_month) && $year_month) {
            $year_month_path = '/' . date("Y/m");
            $use_wp_default_path = apply_filters('acoofm_use_wp_default_path', true);
            if (preg_match('/uploads\/(\d{4})\/(\d{2})\//', $media_absolute_path, $matches) && $use_wp_default_path) {
                $year = $matches[1];
                $month = $matches[2];
                if ($year && $month) {
                    $year_month_path = '/' . $year . '/' . $month;
                }
            }
            $upload_path.= $year_month_path;
        }

        $upload_path.= '/'.$prefix.$file_name;
        return ltrim($upload_path,'/');
    }
}


if (!function_exists('acoofm_is_extension_available')) {

    /**
     * Check extension is compatible
     * @since 1.0.0
     * @return boolean
     */
    function acoofm_is_extension_available($path){
        // Rebuild the URL without the query string
        $parsedUrl = parse_url($path);
        if(isset($parsedUrl['scheme']) && isset($parsedUrl['host']) && isset($parsedUrl['path'])){
            $path = $parsedUrl['scheme'] . "://" . $parsedUrl['host'] . $parsedUrl['path'];
        }
        

        $settings   = acoofm_get_settings();
        $path_parts = pathinfo($path);

        if(!isset($path_parts['basename']) || !isset($path_parts['extension'])) return false;

        $alowed         = isset($settings['extensions_include']) ? $settings['extensions_include'] : [];
        $not_allowed    = isset($settings['extensions_exclude']) ? $settings['extensions_exclude'] : [];

        if(
            (in_array($path_parts['extension'], $not_allowed)) || 
            (!empty($alowed) && !in_array($path_parts['extension'], $alowed))
        ) {
            return false;
        }
        
        $type_and_ext = wp_check_filetype_and_ext($path, $path_parts['basename']);
        $ext             = empty( $type_and_ext['ext'] ) ? '' : $type_and_ext['ext'];
		$type            = empty( $type_and_ext['type'] ) ? '' : $type_and_ext['type'];

		if ( ( ! $type || ! $ext ) && ! current_user_can( 'unfiltered_upload' ) ) {
			return false;
		}
        
        return true;
    }

}


if (!function_exists('acoofm_check_existing_file_names')) {

    /**
     * Check whether a file exist in a list of files
     * @since 1.0.1
     * @return boolean
     */
    function acoofm_check_existing_file_names( $filename, $files ) {
        $fname = pathinfo( $filename, PATHINFO_FILENAME );
        $ext   = pathinfo( $filename, PATHINFO_EXTENSION );
    
        // Edge case, file names like `.ext`.
        if ( empty( $fname ) ) {
            return false;
        }
    
        if ( $ext ) {
            $ext = ".$ext";
        }
    
        $regex = '/^' . preg_quote( $fname ) . '-(?:\d+x\d+|scaled|rotated)' . preg_quote( $ext ) . '$/i';
    
        foreach ( $files as $file ) {
            if ( 
                preg_match( $regex, wp_basename($file) ) || 
                $filename == $file
            ) {
                return true;
            }
        }
    
        return false;
    }
}


if (!function_exists('acoofm_create_no_query_url')) {

    /**
     * Create URL Without Query 
     * @since 1.0.0
     * @return boolean
     */
    function acoofm_create_no_query_url($parsed_url){
        $scheme   = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : '';
        $host     = isset($parsed_url['host']) ? $parsed_url['host'] : '';
        $port     = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
        $user     = isset($parsed_url['user']) ? $parsed_url['user'] : '';
        $pass     = isset($parsed_url['pass']) ? ':' . $parsed_url['pass']  : '';
        $pass     = ($user || $pass) ? "$pass@" : '';
        $path     = isset($parsed_url['path']) ? $parsed_url['path'] : '';
        return "$scheme$user$pass$host$port$path";
    }

}


if (!function_exists('acoofm_generate_object_versioning_prefix')) {

    /**
     * Generate prefix for object versioning
     * @since 1.0.0
     * @return string
     */
    function acoofm_generate_object_versioning_prefix(){
        $settings = acoofm_get_settings();
        if (isset($settings['year_month']) && $settings['year_month']) {
            $date_format = 'dHis';
        } else {
            $date_format = 'YmdHis';
        }

        // Use current time so that object version is unique
        $time = current_time('timestamp');

        $object_version = date($date_format, $time) . '/';
        $object_version = apply_filters('acoofm_object_version_prefix', $object_version);

        return $object_version;
    }
}



if (!function_exists('acoofm_set_cache')) {

    /**
     * Set Cache
     * @since 1.0.0
     * @return boolean
     */
    function acoofm_set_cache($key, $data, $id, $expiry = ACOOFM_CACHE_EXPIRY){
        if(!empty($data)){
            $cache_postfix = ceil(intval($id)/ACOOFM_CACHE_COUNT_PER_KEY);
            $existing = get_transient( ACOOFM_CACHE_GLOBAL_KEY . $cache_postfix );
            if(!is_array($existing)) {
                $existing=[];
            }
            $existing[ACOOFM_CACHE_KEY_PREFIX . $key] = $data;
            set_transient( ACOOFM_CACHE_GLOBAL_KEY . $cache_postfix, $existing, $expiry == false ? 0 : $expiry );
        }
    }

}

if (!function_exists('acoofm_get_cache')) {

    /**
     * Get Cache
     * @since 1.0.0
     * @return boolean
     */
    function acoofm_get_cache($key, $id){
        $cache_postfix = ceil(intval($id)/ACOOFM_CACHE_COUNT_PER_KEY);
        $existing = get_transient( ACOOFM_CACHE_GLOBAL_KEY . $cache_postfix );

        if(!is_array($existing)) {
            return false;
        }

        return isset($existing[ACOOFM_CACHE_KEY_PREFIX . $key]) 
                    ? $existing[ACOOFM_CACHE_KEY_PREFIX . $key] 
                    : false;
    }

}

if (!function_exists('acoofm_delete_cache')) {

    /**
     * Delete Cache
     * @since 1.0.0
     * @return boolean
     */
    function acoofm_delete_cache($key, $id){
        $cache_postfix = ceil(intval($id)/ACOOFM_CACHE_COUNT_PER_KEY);
        $existing = get_transient( ACOOFM_CACHE_GLOBAL_KEY . $cache_postfix );

        if(isset($existing[ACOOFM_CACHE_KEY_PREFIX . $key])) {
            unset($existing[ACOOFM_CACHE_KEY_PREFIX . $key]);
        }

        set_transient( ACOOFM_CACHE_GLOBAL_KEY . $cache_postfix, $existing, ACOOFM_CACHE_EXPIRY );
    }

}



if (!function_exists('acoofm_clear_cache')) {

    /**
     * Delete All Transients
     * @since 1.0.0
     * @return boolean
     */
    function acoofm_clear_cache(){
        global $wpdb;
        $sql        = $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like( '_transient_' . ACOOFM_CACHE_GLOBAL_KEY ) . '%'
        );
        $results    = $wpdb->get_results( $sql, ARRAY_A );
        $transients = [];

        array_walk_recursive( $results, function ( $value ) use ( &$transients ) {
            $transient_name =  str_replace('_transient_', "", $value);
            delete_transient( $transient_name );		
        });

        update_option( ACOOFM_STORED_DATA_VERSION, time(), true );

        delete_transient( 'acoofmf_cdn_base_urls' );

        if ( class_exists( 'ACOOFMF_URL_Rewrite_Resolver' ) ) {
            ACOOFMF_URL_Rewrite_Resolver::clear_request_cache();
        }

        /**
         * Compatibility for pro version to clear sync activity
         * @since 1.1.9
         */
        if(defined('ACOOFM_SYNC_ACTIVITY_DATA')) {
            acoofm_update_option( ACOOFM_SYNC_ACTIVITY_DATA, ACOOFMP_Schema::get_schema('syncActivity') );
        }
    }
}


if (!function_exists('acoofm_update_media_count')) {

    /**
     * Function To update media counts
     * @since 1.1.5
     * @return array|boolean|string|integer|float|double
     */
    function acoofm_update_media_count($key, $value, $increment = true, $replace = false){
        $current_options = acoofm_get_option('dashboard', array('offloaded' => 0 ));

        if($replace) { 
            $current_options[$key] = $value;
            return acoofm_update_option('dashboard', $current_options);
        }

        if(isset($current_options[$key])){
            $currentValue = (int)$current_options[$key];
            $current_options[$key] = ($increment) 
                                        ? $currentValue + $value 
                                        : $currentValue - $value;
        } else {
            $current_options[$key] = $value;
        }
        return acoofm_update_option('dashboard', $current_options);
    }
}

if (!function_exists('acoofm_get_media_count')) {

    /**
     * Function To get media counts
     * @since 1.1.5
     * @return array|boolean|string|integer|float|double
     */
    function acoofm_get_media_count($key){
        $current_options = acoofm_get_option('dashboard', array('offloaded' => 0 ));

        if(isset($current_options[$key])){
            return $current_options[$key];
        }
        return 0;
    }
}


if (!function_exists('acoofm_get_post_meta')) {

    /**
     * Get Post Meta Data By Query
     * @since 1.0.0
     * @return boolean
     */
    function acoofm_get_post_meta($attachment_id, $key, $single=false, $db_query=false){
        global $wpdb;
        if(!(!empty($key) || $attachment_id)) return false;

        if($db_query) {
            $meta_data = $wpdb->get_row( $wpdb->prepare( "SELECT meta_value FROM $wpdb->postmeta WHERE post_id = %d AND meta_key = %s", $attachment_id, $key ) );
            if ($wpdb->last_error || null === $meta_data || !isset($meta_data)) {
                return false;
            }
            return $meta_data->meta_value;
        } else {
            return get_post_meta( $attachment_id, $key, $single );
        }
    }

}

if (!function_exists('acoofm_reset_attachement_meta_key')) {

    /**
     * Get Post Meta Data By Query
     * @since 1.0.0
     * @return boolean
     */
    function acoofm_reset_attachement_meta_key($provider){
        global $acoofmItem;
        $items = $acoofmItem->get_columns($provider);
        $meta_key = ACOOFM_ATTACHMENT_META_KEY;

        $attachments = get_posts(array(
            'post_type'      => 'attachment',
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'fields'         => 'ids',
        ));

        
        foreach ($attachments as $attachment_id) {
            delete_post_meta($attachment_id, $meta_key);
        }

        foreach ($items as $item){
            $source_id = $item['source_id'];
            $item_id = $item['id'];
            add_post_meta((int) $source_id, $meta_key, $item_id);
        }
        
    }

}

if (!function_exists('acoofm_is_pro_active')) {

    /**
     * Function To check pro is active
     * @since 1.1.5
     * @return boolean
     */
    function acoofm_is_pro_active(){
        // If pro version installed
        if (defined('ACOOFM_PRO_VERSION')) {
            return true;
        }
        return false;
    }
}

if (!function_exists('acoofm_get_presigned_expire_minutes')) {

    /**
     * Presigned URL lifetime in minutes from the settings.
     *
     * Empty or invalid values fall back to 20 minutes. S3 compatible
     * signatures (SigV4) are valid for at most 7 days, so pass that cap for them.
     *
     * @param array $settings    Plugin settings
     * @param int   $max_minutes Upper limit, 0 for none
     * @return int
     */
    function acoofm_get_presigned_expire_minutes($settings, $max_minutes = 0){
        $minutes = isset($settings['presigned_expire']) ? (int) $settings['presigned_expire'] : 0;
        if ($minutes < 1) {
            $minutes = 20;
        }
        if ($max_minutes > 0 && $minutes > $max_minutes) {
            $minutes = $max_minutes;
        }

        return $minutes;
    }
}

if (!function_exists('acoofm_get_http_timeouts')) {

    /**
     * Network timeouts (seconds) for the cloud storage clients, so a stalled
     * provider cannot hang a request indefinitely.
     * @return array connect_timeout and timeout
     */
    function acoofm_get_http_timeouts(){
        return apply_filters('acoofm_http_timeouts', array(
            'connect_timeout' => 10,
            'timeout'         => 300, // Whole request, including a 5 MB upload part.
        ));
    }
}

if (!function_exists('acoofm_s3_client_args')) {

    /**
     * Defaults for every S3 compatible client (S3, DigitalOcean, R2, MinIO):
     * network timeouts, and no AWS SDK PHP-version deprecation notice on
     * every request (WordPress decides the supported PHP versions).
     * Values already set, e.g. through the client filters, are kept.
     *
     * @param array $args S3Client arguments
     * @return array
     */
    function acoofm_s3_client_args(array $args){
        $args += array('suppress_php_deprecation_warning' => true);

        $http = isset($args['http']) && is_array($args['http']) ? $args['http'] : array();
        $args['http'] = $http + acoofm_get_http_timeouts();

        // One place to adjust every S3 compatible client (e.g. a proxy).
        return apply_filters('acoofm_s3_client_args', $args);
    }
}

if (!function_exists('acoofm_google_client_args')) {

    /**
     * Network timeouts for Google Cloud Storage clients; values already set are kept.
     *
     * @param array $args StorageClient arguments
     * @return array
     */
    function acoofm_google_client_args(array $args){
        $timeouts = acoofm_get_http_timeouts();

        $args += array('requestTimeout' => $timeouts['timeout']);
        $rest = isset($args['restOptions']) && is_array($args['restOptions']) ? $args['restOptions'] : array();
        $args['restOptions'] = $rest + array('connect_timeout' => $timeouts['connect_timeout']);

        return $args;
    }
}

if (!function_exists('acoofm_mask_credentials')) {

    /**
     * Credentials as sent to the browser: secrets replaced by a placeholder.
     * acoofm_unmask_credentials() restores them when the placeholder comes back.
     *
     * @param array $credentials Stored credentials
     * @return array
     */
    function acoofm_mask_credentials($credentials){
        if (!is_array($credentials)) {
            return $credentials;
        }
        foreach (array('secret_key', 'api_token') as $field) {
            if (isset($credentials[$field]) && is_string($credentials[$field]) && $credentials[$field] !== '') {
                $credentials[$field] = ACOOFM_SECRET_PLACEHOLDER;
            }
        }

        return $credentials;
    }
}

if (!function_exists('acoofm_unmask_credentials')) {

    /**
     * Replace secret placeholders coming back from the browser with the stored secrets.
     *
     * @param array $credentials Credentials from a request
     * @return array
     */
    function acoofm_unmask_credentials($credentials){
        if (!is_array($credentials)) {
            return $credentials;
        }
        $stored = acoofm_get_option('credentials', array());
        foreach (array('secret_key', 'api_token') as $field) {
            if (isset($credentials[$field]) && $credentials[$field] === ACOOFM_SECRET_PLACEHOLDER) {
                $credentials[$field] = (is_array($stored) && isset($stored[$field])) ? $stored[$field] : '';
            }
        }

        return $credentials;
    }
}

if (!function_exists('acoofm_is_acl_not_supported_error')) {

    /**
     * Whether an S3 error says the bucket does not accept object ACLs
     * (Object Ownership "Bucket owner enforced", the S3 default since April 2023)
     * @param \Exception $e Error from putObject or a multipart upload
     * @return boolean
     */
    function acoofm_is_acl_not_supported_error($e){
        // Multipart uploads wrap the CreateMultipartUpload error.
        if ($e instanceof \Aws\Exception\MultipartUploadException) {
            $e = $e->getPrevious();
        }

        return $e instanceof \Aws\Exception\AwsException && $e->getAwsErrorCode() === 'AccessControlListNotSupported';
    }
}

if (!function_exists('acoofm_is_gcs_acl_not_supported_error')) {

    /**
     * Whether a Google Cloud Storage error says the bucket does not accept object ACLs
     * (uniform bucket-level access, the default for new buckets)
     * @param \Exception $e Error from an object upload or update
     * @return boolean
     */
    function acoofm_is_gcs_acl_not_supported_error($e){
        return $e instanceof \Google\Cloud\Core\Exception\ServiceException
            && 400 === (int) $e->getCode()
            && false !== stripos(acoofm_get_gcs_error_message($e), 'uniform bucket-level access');
    }
}

if (!function_exists('acoofm_get_gcs_error_message')) {

    /**
     * Readable message of a Google Cloud error, whose message is often the raw JSON response.
     * @param \Exception $e Error
     * @return string
     */
    function acoofm_get_gcs_error_message($e){
        $message = (string) $e->getMessage();
        $decoded = json_decode($message, true);

        if (is_array($decoded) && !empty($decoded['error']['message']) && is_string($decoded['error']['message'])) {
            return $decoded['error']['message'];
        }

        return $message;
    }
}

if (!function_exists('acoofm_multipart_upload')) {

    /**
     * Multipart upload to an S3 compatible bucket.
     *
     * Failed parts are resumed a limited number of times; after that the
     * incomplete upload is aborted, so its parts are not left (and billed)
     * in the bucket, and the error is thrown.
     *
     * @param \Aws\S3\S3Client $client  S3 compatible client
     * @param string           $source  Absolute file path
     * @param array            $options MultipartUploader options (bucket, key, acl)
     * @return \Aws\ResultInterface
     * @throws \Aws\Exception\MultipartUploadException
     */
    function acoofm_multipart_upload($client, $source, array $options){
        $max_attempts = 3;
        $uploader = new \Aws\S3\MultipartUploader($client, $source, $options);

        for ($attempt = 1; ; $attempt++) {
            try {
                return $uploader->upload();
            } catch (\Aws\Exception\MultipartUploadException $e) {
                if ($attempt >= $max_attempts || acoofm_is_acl_not_supported_error($e)) {
                    $state = $e->getState();
                    if ($state->isInitiated()) {
                        try {
                            $client->abortMultipartUpload($state->getId());
                        } catch (\Exception $abort_error) {
                            // The bucket lifecycle rules can still clean it up.
                        }
                    }
                    throw $e;
                }

                // Resume, keeping the parts already uploaded.
                $uploader = new \Aws\S3\MultipartUploader($client, $source, array(
                    'state' => $e->getState(),
                ));
            }
        }
    }
}

if (!function_exists('acoofm_get_key_file_dir')) {

    /**
     * Directory holding uploaded provider key files (Google service account JSON)
     * @return string
     */
    function acoofm_get_key_file_dir(){
        $upload_dir = wp_get_upload_dir();
        return $upload_dir['basedir'] . '/' . ACOOFM_UPLOADS;
    }
}

if (!function_exists('acoofm_protect_key_file_dir')) {

    /**
     * Create the key file directory and deny all web access to it.
     * Key files also get random names, as .htaccess is ignored on nginx and IIS.
     * @param string $dir Key file directory, required inside an upload_dir filter.
     * @return boolean Whether the directory exists
     */
    function acoofm_protect_key_file_dir($dir = ''){
        if (!$dir) {
            $dir = acoofm_get_key_file_dir();
        }
        if (!wp_mkdir_p($dir)) {
            return false;
        }

        $files = array(
            '.htaccess' => '# Offload Media: provider key files must never be served.' . "\n"
                . 'Options -Indexes' . "\n"
                . '<IfModule mod_authz_core.c>' . "\n"
                . '    Require all denied' . "\n"
                . '</IfModule>' . "\n"
                . '<IfModule !mod_authz_core.c>' . "\n"
                . '    Order deny,allow' . "\n"
                . '    Deny from all' . "\n"
                . '</IfModule>' . "\n",
            'index.php' => '<?php ' . "\n"
                . '// Silence is golden.'
        );

        foreach ($files as $name => $content) {
            $file = trailingslashit($dir) . $name;
            // Older versions wrote an .htaccess that only blocked PHP files.
            if (file_exists($file) && ($name !== '.htaccess' || file_get_contents($file) === $content)) {
                continue;
            }
            if ($file_handle = @fopen($file, 'w')) {
                fwrite($file_handle, $content);
                fclose($file_handle);
            }
        }

        return true;
    }
}

if (!function_exists('acoofm_generate_key_file_path')) {

    /**
     * Unguessable path for a new key file in the key file directory
     * @return string
     */
    function acoofm_generate_key_file_path(){
        $dir = trailingslashit(acoofm_get_key_file_dir());
        do {
            $path = $dir . 'acoofm-key-' . wp_generate_password(32, false) . '.json';
        } while (file_exists($path));

        return $path;
    }
}

if (!function_exists('acoofm_is_generated_key_file')) {

    /**
     * Whether a key file already has a name from acoofm_generate_key_file_path()
     * @return boolean
     */
    function acoofm_is_generated_key_file($path){
        return (bool) preg_match('/^acoofm-key-[A-Za-z0-9]{32}\.json$/', wp_basename($path));
    }
}

if (!function_exists('acoofm_is_allowed_key_file_path')) {

    /**
     * Whether a key file path sent to the REST API may be used.
     *
     * Allowed: files uploaded to the key file directory, the key file set in
     * wp-config.php (ACOOFM_SETTINGS) and the key file already saved by an admin.
     * @return boolean
     */
    function acoofm_is_allowed_key_file_path($path){
        // No stream wrappers (phar://, http://), only local JSON files.
        if (!is_string($path) || $path === '' || false !== strpos($path, '://')) {
            return false;
        }
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'json') {
            return false;
        }

        $file = realpath($path);
        if (!$file || !is_file($file)) {
            return false;
        }

        $dir = realpath(acoofm_get_key_file_dir());
        if ($dir && 0 === strpos($file, rtrim($dir, '/\\') . DIRECTORY_SEPARATOR)) {
            return true;
        }

        $trusted = array(acoofm_get_credentials('file_path', ''));
        if (defined('ACOOFM_SETTINGS')) {
            $settings = unserialize(ACOOFM_SETTINGS);
            if (is_array($settings) && isset($settings['key-file-path'])) {
                $trusted[] = $settings['key-file-path'];
            }
        }
        foreach ($trusted as $trusted_path) {
            if (is_string($trusted_path) && $trusted_path !== '' && realpath($trusted_path) === $file) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('acoofm_is_google_credentials_file')) {

    /**
     * Whether a file holds Google Cloud credentials JSON
     * @return boolean
     */
    function acoofm_is_google_credentials_file($path){
        $max_size = 100 * 1024; // Service account keys are ~2.5 KB.
        if (!is_readable($path) || filesize($path) > $max_size) {
            return false;
        }

        $data = json_decode((string) file_get_contents($path), true);
        $types = array('service_account', 'authorized_user', 'external_account', 'impersonated_service_account');

        return is_array($data) && isset($data['type']) && in_array($data['type'], $types, true);
    }
}

if (!function_exists('acoofm_secure_key_files')) {

    /**
     * Upgrade key files stored by earlier versions: deny web access to the
     * directory and give every key file an unguessable name, keeping the
     * saved configuration pointing at the renamed file.
     * @return void
     */
    function acoofm_secure_key_files(){
        $dir = acoofm_get_key_file_dir();
        if (!is_dir($dir) || !acoofm_protect_key_file_dir()) {
            return;
        }

        $files = glob(trailingslashit($dir) . '*.json');
        if (!$files) {
            return;
        }

        $saved_path = acoofm_get_credentials('file_path', '');
        $saved_file = (is_string($saved_path) && $saved_path !== '') ? realpath($saved_path) : false;

        // A key file named in wp-config.php cannot be renamed from here.
        $config_file = false;
        if (defined('ACOOFM_SETTINGS')) {
            $settings = unserialize(ACOOFM_SETTINGS);
            if (is_array($settings) && !empty($settings['key-file-path'])) {
                $config_file = realpath($settings['key-file-path']);
            }
        }

        foreach ($files as $file) {
            if (
                !is_file($file) || acoofm_is_generated_key_file($file) ||
                ($config_file && realpath($file) === $config_file)
            ) {
                continue;
            }

            $is_saved = $saved_file && realpath($file) === $saved_file;
            $new_path = acoofm_generate_key_file_path();
            if (!@rename($file, $new_path)) {
                continue;
            }

            if ($is_saved) {
                $credentials = acoofm_get_option('credentials', array());
                $credentials['file_path'] = $new_path;
                if (!acoofm_update_option('credentials', $credentials)) {
                    // Keep the saved configuration working.
                    @rename($new_path, $file);
                }
            }
        }
    }
}


