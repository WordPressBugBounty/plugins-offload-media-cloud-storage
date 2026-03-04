<?php

/**
 * Load All Digital ocean related actions
 * It is used same sdk of s3
 *
 * @class   ACOOFMF_DIGITALOCEAN
 *
 * It is used to divide functionality of digital ocean spaces connection into different parts
 */

if (!defined('ABSPATH')) {
    exit;
}

// Libraries
use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Aws\S3\MultipartUploader;
use Aws\Exception\MultipartUploadException;

class ACOOFMF_DIGITALOCEAN
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
     * The Digital Ocean client.
     *
     * @var     object
     * @access  public
     * @since   1.0.0
     */

    public $acoofm_ocean_client=false;
    /**
     * The plugin hook suffix.
     *
     * @var     array
     * @access  public
     * @since   1.0.0
     */
    public $hook_suffix = array();

    /**
     * The public base URL.
     *
     * @var     string
     * @access  public
     * @since   1.0.0
     */
    public $public_base_url;



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
        if(isset($this->config['connection_method']) && $this->config['connection_method'] == 'wp_config') {
            if (defined('ACOOFM_SETTINGS')) {
                $settings = unserialize(ACOOFM_SETTINGS);
            } else {
                return new WP_REST_Response(array('message' => __('Settings not found in wp-config.php', 'offload-media-cloud-storage'), 'code' => 400, 'success' => false), 400);
            }
            
            if($settings['provider'] !== 'ocean') {
                return new WP_REST_Response(array('message' => __('Provider mismatch. Please check the credentials', 'offload-media-cloud-storage'), 'code' => 400, 'success' => false), 400);
            }
            $this->config['access_key'] = $settings['access-key-id'];
            $this->config['secret_key'] = $settings['secret-access-key'];
        }
        
        if (
            isset($this->config['region']) && !empty($this->config['region']) &&
            isset($this->config['access_key']) && !empty($this->config['access_key']) &&
            isset($this->config['secret_key']) && !empty($this->config['secret_key'])
        ) {
            $this->acoofm_ocean_client = new S3Client([
                'version'                       => '2006-03-01',
                'region'                        => 'us-east-1',
                'endpoint'                      => 'https://'.$this->config['region'].'.digitaloceanspaces.com',
                'use_aws_shared_config_files'   => false,
                'credentials'                   => [
                                    'key'       => $this->config['access_key'],
                                    'secret'    => $this->config['secret_key'],
                ],
            ]);
        }

        if (!empty($this->config['bucket_name'])) {
            $bucket = $this->config['bucket_name'];
            $region = $this->config['region'];
            $this->public_base_url = "https://{$bucket}.{$region}.digitaloceanspaces.com";
        } else {
            // Cannot build URL without bucket name
            $this->public_base_url = null;
        }
    }

    /**
    * Creates an Digital Ocean spaces with the specified parameters.
    *
    * @since 1.4.0
    *
    * @param string $access_key The Digital Ocean access key.
    * @param string $secret_key The Digital Ocean secret key.
    * @param string $region The Digital Ocean region where the bucket will be created.
    * @param string $bucket_name The name of the bucket to be created.
    *
    * @return array An associative array containing the result of the bucket creation.
    *               - 'message' (string): A success or error message.
    *               - 'code' (int): The HTTP status code.
    *               - 'success' (bool): True if the bucket was created successfully, false otherwise.
    */

    public function create_bucket($access_key, $secret_key, $region, $bucket_name){
        if (
            isset($region) && !empty($region) &&
            isset($access_key) && !empty($access_key) &&
            isset($secret_key) && !empty($secret_key) &&
            isset($bucket_name) && !empty($bucket_name)
        ) {
            try {
                $s3Client = new S3Client([
                    'version'                       => '2006-03-01',
                    'region'                        => $region,
                    'endpoint'                      => 'https://'.$region.'.digitaloceanspaces.com',
                    'use_aws_shared_config_files'   => false,
                    'credentials'                   => [
                                        'key'       => $access_key,
                                        'secret'    => $secret_key,
                    ],
                ]);

                // Create the bucket
                $result = $s3Client->createBucket([
                    'Bucket' => $bucket_name,
                    'CreateBucketConfiguration' => [
                        'LocationConstraint' => $region,
                    ],
                ]);

                // Wait until the bucket is created
                $s3Client->waitUntil('BucketExists', [
                    'Bucket' => $bucket_name,
                ]);

                // Set CORS policy
                $corsConfiguration = [
                    'CORSRules' => [
                        [
                            'AllowedHeaders' => ['*'],
                            'AllowedMethods' => ['GET', 'POST', 'PUT', 'DELETE'],
                            'AllowedOrigins' => [$_SERVER['HTTP_ORIGIN']],
                            'ExposeHeaders'  => [],
                            'MaxAgeSeconds'  => 3000,
                        ],
                    ],
                ];
                
                $s3Client->putBucketCors([
                    'Bucket' => $bucket_name,
                    'CORSConfiguration' => $corsConfiguration,
                ]);

                // Retrieve the bucket's location and request ID from the result
                $bucketLocation = isset($result['Location']) ? $result['Location'] : 'unknown location';
                $requestId = isset($result['@metadata']['requestId']) ? $result['@metadata']['requestId'] : 'unknown request ID';

                // Return a success message with the retrieved details
                $msg = "Bucket '{$bucket_name}' created successfully at location: {$bucketLocation}. Request ID: {$requestId}.";
                return array('message' => $msg, 'code' => 200, 'success' => true);
            } catch (Aws\S3\Exception\S3Exception $ex) {
                return array('message' => $ex->getAwsErrorMessage() ?? __('Please check the authorization details', 'offload-media-cloud-storage'), 'code' => $ex->getStatusCode() ?? 405, 'success' => false);
            }
        } else {
            return array( 'message' => __('Insufficient Data. Please try again', 'offload-media-cloud-storage'), 'code' =>  405, 'success' => false);
        }
    }

    /**
     * Verify if the provided access key and secret key are valid.
     * 
     * @param string $access_key The access key to verify.
     * @param string $secret_key The secret key to verify.
     * @return array An array with 'success' as boolean and a 'message'.
     * @since 1.4.0
     */
    public function verify_keys($accessKey, $secretKey) {
        if (!empty($accessKey) && !empty($secretKey)) {
            try {

                $regions = ['nyc3', 'sfo2', 'sgp1', 'ams3', 'fra1']; // All DigitalOcean Spaces regions
                $allBuckets = [];
                $bucketDetails = [];

                foreach ($regions as $region) {
                    $client = new S3Client([
                        'version' => 'latest',
                        'region'  => $region,
                        'endpoint' => 'https://'.$region.'.digitaloceanspaces.com',
                        'credentials' => [
                            'key'    => $accessKey,
                            'secret' => $secretKey
                        ],
                        'use_path_style_endpoint' => false
                    ]);
                    
                    $result = $client->listBuckets();
                    $bucketNames = [];
                    foreach ($result['Buckets'] as $bucket) {
                        $bucketName = $bucket['Name'];
                        $bucketNames[] = $bucketName;
                        $bucketDetails[] = [
                            'name' => $bucketName,
                            'region' => $region
                        ];
                    }
                    
                    $allBuckets[$region] = $bucketNames;
                }

                return array(
                    'message' => __('Digital Ocean credentials are valid', 'offload-media-cloud-storage'),
                    'code' => 200,
                    'success' => true,
                    'credentials' => [
                        'access_key' => $accessKey,
                        'secret_key' => $secretKey
                    ],
                    'buckets' => $bucketDetails
                );

            } catch (Aws\S3\Exception\S3Exception $ex){
                return array('message' => $ex->getAwsErrorMessage() ?? __('Please check the authorization details', 'offload-media-cloud-storage'), 'code' => $ex->getStatusCode() ?? 405, 'success' => false);
            }
        } else {
            return array('message' => __('Access key or secret key is missing', 'offload-media-cloud-storage'), 'code' => 400, 'success' => false);
        }
    }

    /**
     * Verify Credentials
     * @since 1.0.0
     * @return boolean
     */
    public function verify($access_key, $secret_key, $region, $bucket_name, $transfer_accilaration = false)
    {
        if (
            empty($region) || empty($access_key) ||
            empty($secret_key) || empty($bucket_name)
        ) {
            return [
                'message' => __('Insufficient Data. Please try again', 'offload-media-cloud-storage'),
                'code'    => 405,
                'success' => false
            ];
        }

        try {
            $oceanClient = new S3Client([
                'version'                     => '2006-03-01',
                'region'                      => 'us-east-1', // DO uses this fixed region for auth
                'endpoint'                    => 'https://' . $region . '.digitaloceanspaces.com',
                'use_aws_shared_config_files' => false,
                'credentials'                 => [
                    'key'    => $access_key,
                    'secret' => $secret_key,
                ],
            ]);

            /** ---------------------------------------------------
             * STEP 1: Check if bucket exists
             * -------------------------------------------------- */
            $buckets      = $oceanClient->listBuckets();
            $bucket_found = false;

            foreach ($buckets['Buckets'] as $bucket) {
                if ($bucket['Name'] === $bucket_name) {
                    $bucket_found = true;
                    break;
                }
            }

            if (!$bucket_found) {
                return [
                    'message' => __('Space Name / Region is incorrect', 'offload-media-cloud-storage'),
                    'code'    => 403,
                    'success' => false
                ];
            }

            /** ---------------------------------------------------
             * STEP 2: Create test file in WordPress uploads folder
             * -------------------------------------------------- */
            $upload_dir = wp_upload_dir();
            $local_file = trailingslashit($upload_dir['basedir']) . 'acoofm_verify.txt';

            $verify_file = fopen($local_file, "w");

            if (!$verify_file) {
                return [
                    'message' => __('Unable to create verification file. Check directory permissions.', 'offload-media-cloud-storage'),
                    'code'    => 500,
                    'success' => false
                ];
            }

            fwrite($verify_file, "We are verifying input/output operations in DigitalOcean Spaces\n");
            fclose($verify_file);

            $fileName = 'acoofm_verify.txt';

            /** ---------------------------------------------------
             * STEP 3: Upload test file to DO Spaces
             * -------------------------------------------------- */
            $upload = $oceanClient->putObject([
                'Bucket' => $bucket_name,
                'Key'    => $fileName,
                'Body'   => fopen($local_file, "r"),
                'ACL'    => 'public-read',
            ]);

            if (!isset($upload['ObjectURL'])) {
                @unlink($local_file);

                return [
                    'message' => __('User has permission issues on uploading object. Check ACL & policies.', 'offload-media-cloud-storage'),
                    'code'    => 403,
                    'success' => false
                ];
            }

            /** ---------------------------------------------------
             * STEP 4: Try downloading the file back locally
             * -------------------------------------------------- */
            $download_to = trailingslashit($upload_dir['basedir']) . 'acoofm-local-verify.txt';

            try {
                $oceanClient->getObject([
                    'Bucket' => $bucket_name,
                    'Key'    => $fileName,
                    'SaveAs' => $download_to
                ]);
            } catch (Aws\S3\Exception\S3Exception $ex) {
                @unlink($local_file);

                return [
                    'message' => __('User cannot GET object. Check read permission.', 'offload-media-cloud-storage'),
                    'code'    => 403,
                    'success' => false
                ];
            }

            // Cleanup local downloaded file
            if (file_exists($download_to)) {
                @unlink($download_to);
            }

            /** ---------------------------------------------------
             * STEP 5: Delete test object from DO Spaces
             * -------------------------------------------------- */
            $oceanClient->deleteObject([
                'Bucket' => $bucket_name,
                'Key'    => $fileName,
            ]);

            // Cleanup local verification file
            if (file_exists($local_file)) {
                @unlink($local_file);
            }

            if ($oceanClient->doesObjectExist($bucket_name, $fileName)) {
                return [
                    'message' => __('User cannot delete object from Space. Check delete permissions.', 'offload-media-cloud-storage'),
                    'code'    => 403,
                    'success' => false
                ];
            }

            /** ---------------------------------------------------
             * ALL GOOD 🎉
             * -------------------------------------------------- */
            return [
                'message' => __('Configuration for DigitalOcean Spaces has been verified successfully!', 'offload-media-cloud-storage'),
                'code'    => 200,
                'success' => true
            ];

        } catch (Aws\S3\Exception\S3Exception $ex) {
            return [
                'message' => $ex->getAwsErrorMessage() ?: __('Please check the authorization details', 'offload-media-cloud-storage'),
                'code'    => $ex->getStatusCode() ?? 405,
                'success' => false
            ];
        }
    }


    /**
     * Connect Function To Identify the congfigurations are correct
     * @since 1.0.0
     */
    public function connect()
    {
        if ($this->acoofm_ocean_client) {
            return true;
        }
        return false;
    }


    /**
     * Check the object exist 
     * @since 1.1.8
     */
    public function is_exist($key) {
        if(!$key) return false;

        if($this->acoofm_ocean_client->doesObjectExist($this->config['bucket_name'], $key)) {
            return true;
        }
        
        return false;
    }


    
    /**
     * Make Object Private
     * @since 1.0.0
     * 
     */
    public function make_private($key) {
        if(!$key) return false;
        try {
            $this->acoofm_ocean_client->putObjectAcl([
                'Bucket'    => $this->config['bucket_name'],
                'Key'       => $key,
                'ACL'       => 'private'
            ]); 
            return true;
        } catch (Aws\S3\Exception\S3Exception $ex) {
            return false;
        }
        return false;
    }



    /**
     * Make Object Public
     * @since 1.0.0
     * 
     */
    public function make_public($key) {
        if(!$key) return false;
        try {
            $this->acoofm_ocean_client->putObjectAcl([
                'Bucket'    => $this->config['bucket_name'],
                'Key'       => $key,
                'ACL'       => 'public-read'
            ]); 
            return true;
        } catch (Aws\S3\Exception\S3Exception $ex) {
            return false;
        }
        return false;
    }


    /**
     * Upload Single
     * @since 1.0.0
     * @return boolean
     */
    public function uploadSingle($media_absolute_path, $media_path, $prefix='')
    {
        $result = array();
        if (
            isset($media_absolute_path) && !empty($media_absolute_path) &&
            isset($media_path) && !empty($media_path)
        ) {
            $file_name = wp_basename( $media_path );
            if ($file_name) {
                $upload_path = acoofm_generate_object_key($file_name, $prefix, $media_absolute_path);
               
                // Decide Multipart upload or normal put object
                if (filesize($media_absolute_path) <= ACOOFM_DIGITAL_OCEAN_MULTIPART_UPLOAD_MINIMUM_SIZE) {
                    // Upload a publicly accessible file. The file size and type are determined by the SDK.
                    try {
                        $upload = $this->acoofm_ocean_client->putObject([
                            'Bucket' => $this->config['bucket_name'],
                            'Key'    => $upload_path,
                            'Body'   => fopen($media_absolute_path, 'r'),
                            'ACL'    => 'public-read', // make file 'public'
                        ]);

                        $result = array(
                            'success'   => true,
                            'code'      => 200,
                            'file_url'  => $upload->get('ObjectURL'),
                            'key'       => $upload_path,
                            'Message'   => __('File Uploaded Successfully', 'offload-media-cloud-storage')
                        );
                    } catch (Exception $e) {
                        $result = array(
                            'success' => false,
                            'code'    => 403,
                            'Message' => $e->getMessage()
                        );
                    }
                } else {
                    $multiUploader = new MultipartUploader($this->acoofm_ocean_client, $media_absolute_path, [
                        'bucket' => $this->config['bucket_name'],
                        'key'    => $upload_path,
                        'acl'    => 'public-read', // make file 'public'
                    ]);
                    
                    try {
                        do {
                            try {
                                $uploaded = $multiUploader->upload();
                            } catch (MultipartUploadException $e) {
                                $multiUploader = new MultipartUploader($this->acoofm_ocean_client, $media_absolute_path, [
                                    'state' => $e->getState(),
                                ]);
                            }
                        } while (!isset($uploaded));
                        if (isset($uploaded['ObjectURL']) && !empty($uploaded['ObjectURL'])) {
                            // Build your clean URL
                            $baseUrl = rtrim($this->public_base_url, '/');
                            $key     = ltrim($upload_path, '/');

                            $url = $baseUrl . '/' . $key;


                            $result = array(
                                'success' => true,
                                'code'    => 200,
                                'file_url' => urldecode($url),
                                'key'     => $upload_path,
                                'Message' => __('File Uploaded Successfully', 'offload-media-cloud-storage')
                            );
                        } else {
                            $result = array(
                                'success' => false,
                                'code'    => 403,
                                'Message' => __('Something happened while uploading to server', 'offload-media-cloud-storage')
                            );
                        }
                    } catch (MultipartUploadException $e) {
                        $result = array(
                            'success' => false,
                            'code'    => 403,
                            'Message' => $e->getMessage()
                        );
                    }
                }
            } else {
                $result = array(
                    'success' => false,
                    'code'    => 403,
                    'Message' => __('Check the file you are trying to upload. Please try again', 'offload-media-cloud-storage')
                );
            }
        } else {
            $result = array(
                'success' => false,
                'code'    => 405,
                'Message' => __('Insufficient Data. Please try again', 'offload-media-cloud-storage')
            );
        }
        return $result;
    }

    /**
     * Save object to local
     * @since 1.0.0
     */
    public function object_to_local($key, $save_path)
    {
        try {
            $getObject = $this->acoofm_ocean_client->GetObject([
                'Bucket' => $this->config['bucket_name'],
                'Key'    => $key,
                'SaveAs' => $save_path
            ]);
            if (file_exists($save_path)) {
                return true;
            }
        } catch (Exception $e) {
            return false;
        }
        return false;
    }


    /**
     * Delete Single
     * @since 1.0.0
     * @return boolean
     */
    public function deleteSingle($key)
    {
        $result = array();
        if (isset($key) && !empty($key)) {
            try {
                $this->acoofm_ocean_client->deleteObject([
                    'Bucket' => $this->config['bucket_name'],
                    'Key'    => $key
                ]);

                if (!$this->acoofm_ocean_client->doesObjectExist($this->config['bucket_name'], $key)) {
                    $result = array(
                        'success' => true,
                        'code'    => 200,
                        'Message' => __('Deleted Successfully', 'offload-media-cloud-storage')
                    );
                } else {
                    $result = array(
                        'success' => false,
                        'code'    => 403,
                        'Message' => __('File not deleted', 'offload-media-cloud-storage')
                    );
                }
            } catch (Exception $e) {
                $result = array(
                    'success' => false,
                    'code'    => 403,
                    'Message' => $e->getMessage()
                );
            }
        } else {
            $result = array(
                'success' => false,
                'code'    => 405,
                'Message' => __('Insufficient Data. Please try again', 'offload-media-cloud-storage')
            );
        }
        return $result;
    }

    /**
     * get presigned URL
     * @since 1.0.0
     * @return boolean
     */
    public function get_presigned_url($key)
    {
        $result = array();
        if (isset($key) && !empty($key)) {
            try {
                $cmd = $this->acoofm_ocean_client->getCommand('GetObject', [
                    'Bucket' => $this->config['bucket_name'],
                    'Key'    => $key
                ]);

                $expires = isset($this->settings['presigned_expire']) ? $this->settings['presigned_expire'] : 20;

                $request = $this->acoofm_ocean_client->createPresignedRequest($cmd, sprintf('+%s  minutes', $expires));

                if ($presignedUrl = (string)$request->getUri()) {
                    $result = array(
                        'success'   => true,
                        'code'      => 200,
                        'file_url'  => $presignedUrl,
                        'Message'   => __('Got Presigned URL Successfully', 'offload-media-cloud-storage')
                    );
                } else {
                    $result = array(
                        'success' => false,
                        'code'    => 403,
                        'Message' => __('Error getting presigned URL', 'offload-media-cloud-storage')
                    );
                }
            } catch (Exception $e) {
                $result = array(
                    'success' => false,
                    'code'    => 403,
                    'Message' => $e->getMessage()
                );
            }
        } else {
            $result = array(
                'success' => false,
                'code'    => 405,
                'Message' => __('Insufficient Data. Please try again', 'offload-media-cloud-storage')
            );
        }
        return $result;
    }
}
