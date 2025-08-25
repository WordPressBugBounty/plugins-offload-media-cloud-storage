<?php

if (!defined('ABSPATH')) {
    exit;
}

class ACOOFMF_Api
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
     * The token.
     *
     * @var     string
     * @access  public
     * @since   1.0.0
     */
    public $token;

    public function __construct()
    {
        $this->token = ACOOFM_TOKEN;

        add_action( 'rest_api_init', array($this, 'api_register'));
    }

    /**
     * Apis
     * @since 1.0.0
     */

    public function api_register()
    {
        $this->add_route('/commonSettings/', 'commonSettings' );
        $this->add_route('/updateCommonSettings/', 'updateCommonSettings', 'POST' );
        $this->add_route('/serviceConnect/', 'serviceConnect', 'POST' );
        $this->add_route('/serviceSave/', 'serviceSave', 'POST' );
        $this->add_route('/settingsSave/', 'settingsSave', 'POST' );
        $this->add_route('/settingsReset/', 'settingsReset', 'POST' );
        $this->add_route('/uploadCredentials/', 'uploadCredentials', 'POST' );
        // New UI routes
        $this->add_route('/verifyCredentials/', 'verifyCredentials', 'POST' );
        $this->add_route('/createBucket/', 'createBucket', 'POST' );
    }

    // New UI Functions starts here //
    /**
     * Service Connect and verify credentials
     * @since 1.4.0
     */
    public function verifyCredentials($request)
    {
        $connection_method = $request->get_param('connection_method');
        $provider = $request->get_param('provider') ? $request->get_param('provider') : '';
        $endpoint = '';
        $access_key = '';
        $secret_key = '';
        $account_id = '';
        $file_path = '';

        if ($connection_method === 'wp_config') {
            if (defined('ACOOFM_SETTINGS')) {
                $settings = unserialize(ACOOFM_SETTINGS);
                if($provider !== $settings['provider']) {
                    return new WP_REST_Response(array('message' => __('Provider mismatch. Please check the credentials', 'offload-media-cloud-storage'), 'code' => 400, 'success' => false), 400);
                }
                $access_key = isset($settings['access-key-id']) ? $settings['access-key-id'] : '';
                $secret_key = isset($settings['secret-access-key']) ? $settings['secret-access-key'] : '';
                $account_id = isset($settings['account-id']) ? $settings['account-id'] : '';
                $file_path = isset($settings['key-file-path']) ? $settings['key-file-path'] : '';
                $endpoint = isset($settings['endpoint']) ? $settings['endpoint'] : '';
            } else {
                return new WP_REST_Response(array('message' => __('Settings not defined in wp-config.', 'offload-media-cloud-storage'), 'code' => 400, 'success' => false), 400);
            }
        } elseif ($connection_method === 'define_constants') {
            $credentials = $request->get_param('credentials') ? $request->get_param('credentials') : array('access_key' => '', 'secret_key' => '');
            $account_id = isset($credentials['account_id']) ? $credentials['account_id'] : '';
            $endpoint = isset($credentials['endpoint']) ? $credentials['endpoint'] : '';
            if (!empty($credentials) && isset($credentials['access_key']) && isset($credentials['secret_key'])) {
                $access_key = $credentials['access_key'];
                $secret_key = $credentials['secret_key'];
            } else {
                return new WP_REST_Response(array('message' => __('Credentials not provided.', 'offload-media-cloud-storage'), 'code' => 400, 'success' => false), 400);
            }
        } elseif ($connection_method === 'upload_key') {
            $credentials = $request->get_param('credentials') ? $request->get_param('credentials') : '';
            $file_path = isset($credentials['path']) ? $credentials['path'] : '';
            if (empty($file_path)) {
                return new WP_REST_Response(array('message' => __('File path not provided.', 'offload-media-cloud-storage'), 'code' => 400, 'success' => false), 400);
            }
        } else {
            return new WP_REST_Response(array('message' => __('Invalid connection method.', 'offload-media-cloud-storage'), 'code' => 400, 'success' => false), 400);
        }

        if (($provider && $access_key && $secret_key) || ($provider && $file_path)) {
            $result = $this->verify_provider_credentials($provider, $access_key, $secret_key, $account_id, $file_path, $endpoint);
            if (is_array($result) && $result['success']) {
                return new WP_REST_Response(array('message' => $result['message'], 'code' => 200, 'success' => true, 'result' => $result), 200);
            } else {
                return new WP_REST_Response(array('message' => $result['message'], 'code' => 400, 'success' => false), 400);
            }
        } else {
            return new WP_REST_Response(array('message' => __('Missing provider or credentials.', 'offload-media-cloud-storage'), 'code' => 400, 'success' => false), 400);
        }
    }

    public function createBucket($request){
        $service = $request->get_param('service');
        $credentials = $request->get_param('credentials');

        if(!empty($service) && isset($credentials)){
            switch ($service) {
                case 's3':
                    $access_key = isset($credentials['access_key']) ? $credentials['access_key'] : '';
                    $secret_key = isset($credentials['secret_key']) ? $credentials['secret_key'] : '';
                    $region = isset($credentials['region']) ? $credentials['region'] : '';
                    $bucket_name = isset($credentials['bucket_name']) ? $credentials['bucket_name'] : '';
                    if(!empty($access_key) && !empty($secret_key) && !empty($region) && !empty($bucket_name)){
                        $acoofmS3 = new ACOOFMF_S3;
                        $result = $acoofmS3->create_bucket($access_key, $secret_key, $region, $bucket_name);
                        if($result['success']){
                            return new WP_REST_Response(array('message' => $result['message'], 'code' => 200, 'success' => true, 'result' => $result), 200);
                        } else {
                            return new WP_REST_Response(array('message' => $result['message'], 'code' => 400, 'success' => false), 400);
                        }
                    } else {
                        return new WP_REST_Response(array('message' => __('Missing provider or credentials.', 'offload-media-cloud-storage'), 'code' => 400, 'success' => false), 400);
                    }
                    break;
                case 'ocean':
                    $access_key = isset($credentials['access_key']) ? $credentials['access_key'] : '';
                    $secret_key = isset($credentials['secret_key']) ? $credentials['secret_key'] : '';
                    $region = isset($credentials['region']) ? $credentials['region'] : '';
                    $bucket_name = isset($credentials['bucket_name']) ? $credentials['bucket_name'] : '';
                    if(!empty($access_key) && !empty($secret_key) && !empty($region) && !empty($bucket_name)){
                        $acoofmOcean = new ACOOFMF_DIGITALOCEAN;
                        $result = $acoofmOcean->create_bucket($access_key, $secret_key, $region, $bucket_name);
                        if($result['success']){
                            return new WP_REST_Response(array('message' => $result['message'], 'code' => 200, 'success' => true, 'result' => $result), 200);
                        } else {
                            return new WP_REST_Response(array('message' => $result['message'], 'code' => 400, 'success' => false), 400);
                        }
                    } else {
                        return new WP_REST_Response(array('message' => __('Missing provider or credentials.', 'offload-media-cloud-storage'), 'code' => 400, 'success' => false), 400);
                    }
                    break;
                case 'r2':
                    $account_id = isset($credentials['account_id']) ? $credentials['account_id'] : '';
                    $access_key = isset($credentials['access_key']) ? $credentials['access_key'] : '';
                    $secret_key = isset($credentials['secret_key']) ? $credentials['secret_key'] : '';
                    $bucket_name = isset($credentials['bucket_name']) ? $credentials['bucket_name'] : '';
                    $api_token = isset($credentials['api_token']) ? $credentials['api_token'] : '';
                    $region = isset($credentials['region']) ? $credentials['region'] : '';
                    if(!empty($account_id) && !empty($access_key) && !empty($secret_key) && !empty($bucket_name) && !empty($region)){
                        $acoofmr2 = new ACOOFMF_R2;
                        $result = $acoofmr2->create_bucket($access_key, $secret_key, $account_id, $bucket_name, $region, $api_token);
                        if($result['success']){
                            return new WP_REST_Response(array('message' => $result['message'], 'code' => 200, 'success' => true, 'result' => $result), 200);
                        } else {
                            return new WP_REST_Response(array('message' => $result['message'], 'code' => 400, 'success' => false), 400);
                        }
                    } else {
                        return new WP_REST_Response(array('message' => __('Missing provider or credentials.', 'offload-media-cloud-storage'), 'code' => 400, 'success' => false), 400);
                    }
                    break;
                case 'google':
                    $file_path = isset($credentials['file_path']) ? $credentials['file_path'] : '';
                    $bucket_name = isset($credentials['bucket_name']) ? $credentials['bucket_name'] : '';
                    $region = isset($credentials['region']) ? $credentials['region'] : '';
                    if(!empty($file_path) && !empty($bucket_name) && !empty($region)){
                        $acoofmGoogle = new ACOOFMF_GOOGLE;
                        $result = $acoofmGoogle->create_bucket($file_path, $bucket_name, $region);
                        if($result['success']){
                            return new WP_REST_Response(array('message' => $result['message'], 'code' => 200, 'success' => true, 'result' => $result), 200);
                        } else {
                            return new WP_REST_Response(array('message' => $result['message'], 'code' => 400, 'success' => false), 400);
                        }
                    } else {
                        return new WP_REST_Response(array('message' => __('Missing provider or credentials.', 'offload-media-cloud-storage'), 'code' => 400, 'success' => false), 400);
                    }
                    break;

                case 'minio':
                    $access_key = isset($credentials['access_key']) ? $credentials['access_key'] : '';
                    $secret_key = isset($credentials['secret_key']) ? $credentials['secret_key'] : '';
                    $endpoint = isset($credentials['endpoint']) ? $credentials['endpoint'] : '';
                    $region = isset($credentials['region']) ? $credentials['region'] : '';
                    $bucket_name = isset($credentials['bucket_name']) ? $credentials['bucket_name'] : '';
                    if(!empty($access_key) && !empty($secret_key) && !empty($endpoint) && !empty($region) && !empty($bucket_name)){
                        $acoofmMinio = new ACOOFMF_MINIO;
                        $result = $acoofmMinio->create_bucket($access_key, $secret_key, $endpoint, $bucket_name, $region);
                        if($result['success']){
                            return new WP_REST_Response(array('message' => $result['message'], 'code' => 200, 'success' => true, 'result' => $result), 200);
                        } else {
                            return new WP_REST_Response(array('message' => $result['message'], 'code' => 400, 'success' => false), 400);
                        }
                    } else {
                        return new WP_REST_Response(array('message' => __('Missing provider or credentials.', 'offload-media-cloud-storage'), 'code' => 400, 'success' => false), 400);
                    }
                    break;

                default:
                    # code...
                    break;
            }
        }
        return new WP_REST_Response(array('message' => __('Missing provider or credentials.', 'offload-media-cloud-storage'), 'code' => 400, 'success' => false), 400);
    }

    private function verify_provider_credentials($provider, $access_key, $secret_key, $account_id, $file_path, $endpoint) {
        switch ($provider) {
            case 's3':
                $acoofmS3 = new ACOOFMF_S3;
                return $acoofmS3->verify_keys($access_key, $secret_key);
            case 'ocean':
                $acoofmOcean = new ACOOFMF_DIGITALOCEAN;
                return $acoofmOcean->verify_keys($access_key, $secret_key);
            case 'google':
                $acoofmGoogle = new ACOOFMF_GOOGLE;
                return $acoofmGoogle->verify_keys($file_path);
                break;
            case 'r2':
                $acoofmr2 = new ACOOFMF_R2;
                if (empty($account_id)) {
                    return array('success' => false, 'message' => 'Account ID not found.');
                }
                return $acoofmr2->verify_keys($account_id, $access_key, $secret_key);
                break;
            case 'minio':
                $acoofmMinio = new ACOOFMF_MINIO;
                if (empty($endpoint)) {
                    return array('success' => false, 'message' => 'Endpoint not found.');
                }
                return $acoofmMinio->verify_keys($access_key, $secret_key, $endpoint);
                break;
            default:
                return array('success' => false, 'message' => 'Credentials not verified.');
        }
    }

    // New UI Functions ends here //

    /**
     * Function to add route
     */
    private function add_route( $slug, $callBack, $method = 'GET' ) {
		register_rest_route(
			$this->token . '/v1',
			$slug,
			array(
				'methods'             => $method,
				'callback'            => array( $this, $callBack ),
				'permission_callback' => array( $this, 'getPermission' ),
			) );
	}


    /**
     * Get all settings
     * @since 1.0.0
     */

    public function commonSettings()
    {
        // Run API call to retrieve server data
        $data = array(
            'dashboard' => [
                'offloaded' => acoofm_get_media_count('offloaded'),
                'version' => 'v' . ACOOFM_VERSION,
            ],
            'configure' => [
                'service' => acoofm_get_option('service', []),
                'credentials' => acoofm_get_option('credentials', []),
            ],
            'settings' => acoofm_get_option('settings', []),
        );
        return new WP_REST_Response($data, 200);
        // return new WP_REST_Response('Error Fetching Data', 503);
    }

    /**
     * Update Default Settings
     * @since 1.0.0
     */
    public function updateCommonSettings($request)
    {
        $settings = $request->get_param('settings');
        $configure = $request->get_param('configure');
        if (
            isset($configure) && !empty($configure) &&
            isset($settings) && !empty($settings)
        ) {
            if (acoofm_update_option('settings', $settings)) {
                if (isset($configure['service']) && !empty($configure['service'])) {
                    acoofm_update_option('service', $configure['service']);
                }
                if ($configure['credentials'] && !empty($configure['credentials'])) {
                    acoofm_update_option('credentials', $configure['credentials']);
                }
            }
        }
        return new WP_REST_Response(__('Configuration Saved', 'offload-media-cloud-storage'), 200);
    }

    /**
     * Service Connect and verify credentials
     * @since 1.0.0
     */
    public function serviceConnect($request)
    {
        $service = $request->get_param('service');
        $credentials = $request->get_param('credentials');

        if (
            isset($service) && !empty($service) &&
            isset($credentials) && !empty($credentials)
        ) {
            if (!empty($service)) {
                switch ($service) {
                    case 's3':
                        $acoofmS3 = new ACOOFMF_S3;
                        if (
                            isset($credentials['region']) && !empty($credentials['region']) &&
                            isset($credentials['access_key']) && !empty($credentials['access_key']) &&
                            isset($credentials['secret_key']) && !empty($credentials['secret_key']) &&
                            isset($credentials['bucket_name']) && !empty($credentials['bucket_name'])
                        ) {
                            $result = $acoofmS3->verify($credentials['access_key'], $credentials['secret_key'], $credentials['region'], $credentials['bucket_name']);
                            return new WP_REST_Response(array('message' => $result['message'], 'code' => $result['code']));
                        } else {
                            return new WP_REST_Response(array('message' => __('Insufficient Data. Please try again', 'offload-media-cloud-storage'), 'code' => 405));
                        }
                        break;
                    case 'google':
                        $acoofmGoogle = new ACOOFMF_GOOGLE;
                        if (
                            isset($credentials['file_path']) && !empty($credentials['file_path']) &&
                            isset($credentials['bucket_name']) && !empty($credentials['bucket_name'])
                        ) {
                            $result = $acoofmGoogle->verify($credentials['file_path'], $credentials['bucket_name']);
                            return new WP_REST_Response(array('message' => $result['message'], 'code' => $result['code']));
                        } else {
                            return new WP_REST_Response(array('message' => __('Insufficient Data. Please try again', 'offload-media-cloud-storage'), 'code' => 405));
                        }
                        break;
                    case 'ocean':
                        $acoofmOcean = new ACOOFMF_DIGITALOCEAN;
                        if (
                            isset($credentials['region']) && !empty($credentials['region']) &&
                            isset($credentials['access_key']) && !empty($credentials['access_key']) &&
                            isset($credentials['secret_key']) && !empty($credentials['secret_key']) &&
                            isset($credentials['bucket_name']) && !empty($credentials['bucket_name'])
                        ) {
                            $result = $acoofmOcean->verify($credentials['access_key'], $credentials['secret_key'], $credentials['region'], $credentials['bucket_name']);
                            return new WP_REST_Response(array('message' => $result['message'], 'code' => $result['code']));
                        } else {
                            return new WP_REST_Response(array('message' => __('Insufficient Data. Please try again', 'offload-media-cloud-storage'), 'code' => 405));
                        }
                        break;
                    case 'r2':
                        $acoofmr2 = new ACOOFMF_R2;
                        if (
                            isset($credentials['account_id']) && !empty($credentials['account_id']) &&
                            isset($credentials['access_key']) && !empty($credentials['access_key']) &&
                            isset($credentials['secret_key']) && !empty($credentials['secret_key']) &&
                            isset($credentials['bucket_name']) && !empty($credentials['bucket_name'])
                        ) {
                            $result = $acoofmr2->verify($credentials['access_key'], $credentials['secret_key'], $credentials['account_id'], $credentials['bucket_name']);
                            return new WP_REST_Response(array('message' => $result['message'], 'code' => $result['code']));
                        } else {
                            return new WP_REST_Response(array('message' => __('Insufficient Data. Please try again', 'offload-media-cloud-storage'), 'code' => 405));
                        }
                        break;
                    case 'minio':
                        $acoofmMinio = new ACOOFMF_MINIO;
                        if (
                            isset($credentials['access_key']) && !empty($credentials['access_key']) &&
                            isset($credentials['secret_key']) && !empty($credentials['secret_key']) &&
                            isset($credentials['endpoint']) && !empty($credentials['endpoint'])
                        ) {
                            $result = $acoofmMinio->verify($credentials['access_key'], $credentials['secret_key'], $credentials['endpoint'], $credentials['bucket_name'], $credentials['region']);
                            return new WP_REST_Response(array('message' => $result['message'], 'code' => $result['code']));
                        } else {
                            return new WP_REST_Response(array('message' => __('Insufficient Data. Please try again', 'offload-media-cloud-storage'), 'code' => 405));
                        }
                        break;
                    default:
                        return new WP_REST_Response(array('message' => __('Invalid Service Data. Please try again', 'offload-media-cloud-storage'), 'code' => 405));
                }
            } else {
                return new WP_REST_Response(array('message' => __('Insufficient Data. Please try again', 'offload-media-cloud-storage'), 'code' => 405));
            }
        } else {
            return new WP_REST_Response(array('message' => __('Insufficient Data. Please try again', 'offload-media-cloud-storage'), 'code' => 405));
        }
    }

    /**
     * Service Credentials and service save
     * @since 1.0.0
     */
    public function serviceSave($request)
    {
        $service = $request->get_param('service');
        $credentials = $request->get_param('credentials');
        $settings = $request->get_param('settings');

        $current_service = acoofm_get_service('slug');
        $current_bucket_name = acoofm_get_credentials('bucket_name');
        if (
            isset($service) && !empty($service) &&
            isset($credentials) && !empty($credentials) &&
            isset($settings) && !empty($settings)
        ) {
            if (isset($service['slug']) && !empty($service['slug'])) {
                if (
                    isset($current_service) && ($current_service != false) &&
                    ($current_service == $service['slug']) && isset($current_bucket_name) && ($current_bucket_name == $credentials['bucket_name'])
                ) {
                    if (acoofm_update_option('credentials', $credentials)) {
                        acoofm_reset_attachement_meta_key($service['slug']);
                        return new WP_REST_Response(array('message' => __('Configuration Saved', 'offload-media-cloud-storage'), 'previousService' => true), 200);
                        acoofm_clear_cache();
                    } else {
                        return new WP_REST_Response(array('message' => __('Something went wrong', 'offload-media-cloud-storage'), 'previousService' => false), 403);
                    }
                } else if (
                    acoofm_update_option('service', $service) &&
                    acoofm_update_option('credentials', $credentials) &&
                    acoofm_update_option('settings', $settings)
                ) {
                    acoofm_reset_attachement_meta_key($service['slug']);
                    acoofm_clear_cache();
                    return new WP_REST_Response(array('message' => __('Configuration Saved', 'offload-media-cloud-storage'), 'previousService' => false), 200);
                } else {
                    return new WP_REST_Response(array('message' => __('Something went wrong', 'offload-media-cloud-storage'), 'previousService' => false), 403);
                }
            } else {
                return new WP_REST_Response(array('message' => __('Insufficient Data. Please try again', 'offload-media-cloud-storage'), 'previousService' => false), 405);
            }
        } else {
            return new WP_REST_Response(array('message' => __('Insufficient Data. Please try again', 'offload-media-cloud-storage'), 'previousService' => false), 405);
        }
    }

    /**
     * Save the settings
     * @since 1.0.0
     */
    public function settingsSave($request)
    {
        $settings = $request->get_param('settings');
        if (isset($settings) && !empty($settings)) {
            // Validate URLS
            if (
                isset($settings['enable_cdn']) && $settings['enable_cdn'] == true &&
                isset($settings['cdn_url']) && !empty($settings['cdn_url'])
            ) {
                if (filter_var($settings['cdn_url'], FILTER_VALIDATE_URL) === false) {
                    return new WP_REST_Response(__('Invalid CDN url. Please enter a valid url.', 'offload-media-cloud-storage'), 400);
                } else if(is_ssl() && strpos($settings['cdn_url'], 'https') !== 0) {
                    return new WP_REST_Response(__('Unsuitable CDN URL. <small>An SSL-enabled site should use an SSL-enabled CDN URL.</small>', 'offload-media-cloud-storage'), 400);
                }
            }

            if (acoofm_update_option('settings', $settings)) {
                acoofm_clear_cache();
                return new WP_REST_Response(__('Settings updated', 'offload-media-cloud-storage'), 200);
            } else {
                return new WP_REST_Response(__('Something went wrong', 'offload-media-cloud-storage'), 403);
            }
        } else {
            return new WP_REST_Response(__('Insufficient Data. Please try again', 'offload-media-cloud-storage'), 405);
        }
    }

    /**
     * Reset the settings
     * @since 1.0.0
     */
    public function settingsReset($request)
    {
        $settings = $request->get_param('settings');
        if (isset($settings) && !empty($settings)) {
            if (acoofm_update_option('settings', $settings)) {
                acoofm_clear_cache();
                return new WP_REST_Response(__('Settings reset to default settings', 'offload-media-cloud-storage'), 200);
            } else {
                return new WP_REST_Response(__('Something went wrong', 'offload-media-cloud-storage'), 403);
            }
        } else {
            return new WP_REST_Response(__('Insufficient Data. Please try again', 'offload-media-cloud-storage'), 405);
        }
    }

    /**
     * Upload Credentials
     * @since 1.0.0
     */
    public function uploadCredentials($request)
    {
        if (isset($_FILES['file']) && !empty($_FILES['file'])) {
            $config_file = $_FILES['file'];
            if (isset($config_file['type']) && $config_file['type'] == 'application/json') {
                if (file_exists($config_file["tmp_name"])) {

                    add_filter( 'upload_dir', array($this, 'change_file_upload_dir'));
                    add_filter( 'mime_types', array($this, 'add_custom_mime_type_json'));

                    if ( ! function_exists( 'wp_handle_upload' ) ) {
                        require_once( ABSPATH . 'wp-admin/includes/file.php' );
                    }

                    $upload_overrides = array(
                        'test_form' => false,
                        'test_type' => true,
                        'mimes'     => array ( 'json'=>'application/json' )
                    );

                    $movefile = wp_handle_upload( $config_file, $upload_overrides );

                    remove_filter( 'upload_dir', array($this, 'change_file_upload_dir'));
                    remove_filter( 'mime_types', array($this, 'add_custom_mime_type_json'));

                    if ($movefile && !isset($movefile['error'])) {
                        $result     = array(
                            'success' => true,
                            'file_path' => $movefile['file'],
                            'file_name' => basename($movefile['file']),
                        );
                        return new WP_REST_Response($result, 200);
                    } else {
                        return new WP_REST_Response($movefile['error'], 415);
                    }
                }
            } else {
                return new WP_REST_Response(__('Invalid file format', 'offload-media-cloud-storage'), 415);
            }
        } else {
            return new WP_REST_Response(__('Insufficient data. Please try again', 'offload-media-cloud-storage'), 405);
        }
    }


    /**
     * Change File Upload Directory
     * @since 1.0.0
     */
    public function change_file_upload_dir($upload) {
        // $upload_dir = wp_get_upload_dir();
        $path   = $upload['basedir'] . '/' . ACOOFM_UPLOADS;
        $url    = $upload['baseurl'] . '/' . ACOOFM_UPLOADS;

        if (!is_dir($path)) {
            mkdir($path);
        }   

        $upload['subdir'] = '/' . ACOOFM_UPLOADS;
        $upload['path'] = $upload['basedir'] . '/' . ACOOFM_UPLOADS;
        $upload['url'] = $upload['baseurl'] . '/' . ACOOFM_UPLOADS;

        return $upload;
    }

    /**
     * Add Custom Mime Type JSON
     * @since 1.0.0
     */
    public function add_custom_mime_type_json($mimes) {
        $mimes['json'] = 'application/json';
        // Return the array back to the function with our added mime type.
        return $mimes;
    }
    

    /**
     *
     * Ensures only one instance of APIFW is loaded or can be loaded.
     *
     * @param string $file Plugin root path.
     * @return Main APIFW instance
     * @see WordPress_Plugin_Template()
     * @since 1.0.0
     * @static
     */
    public static function instance()
    {
        if (is_null(self::$instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Permission Callback
     **/
    public function getPermission()
    {
        if (current_user_can('administrator')) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Cloning is forbidden.
     *
     * @since 1.0.0
     */
    public function __clone()
    {
        _doing_it_wrong(__FUNCTION__, __('Cheatin&#8217; huh?'), $this->_version);
    }

    /**
     * Unserializing instances of this class is forbidden.
     *
     * @since 1.0.0
     */
    public function __wakeup()
    {
        _doing_it_wrong(__FUNCTION__, __('Cheatin&#8217; huh?'), $this->_version);
    }
}
