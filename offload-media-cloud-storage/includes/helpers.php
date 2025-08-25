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
        $sql        = 'select option_name from ' . $wpdb->prefix . 'options where option_name  like "_transient_' . ACOOFM_CACHE_GLOBAL_KEY . '%"';
        $results    = $wpdb->get_results( $sql, ARRAY_A );
        $transients = [];

        array_walk_recursive( $results, function ( $value ) use ( &$transients ) {
            $transient_name =  str_replace('_transient_', "", $value);
            delete_transient( $transient_name );		
        });

        update_option( ACOOFM_STORED_DATA_VERSION, time(), true );

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
            $meta_data = $wpdb->get_row( "SELECT meta_value FROM $wpdb->postmeta WHERE post_id=$attachment_id AND meta_key='$key'" );
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


