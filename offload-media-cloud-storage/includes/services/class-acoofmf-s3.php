<?php

/**
 * Load All S3 related actions
 *
 * @class   ACOOFMF_S3
 *
 * It is used to divide functionality of amazon s3 connection into different parts
 */

if (!defined('ABSPATH')) {
    exit;
}

// Libraries
use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Aws\S3\MultipartUploader;
use Aws\Exception\MultipartUploadException;

class ACOOFMF_S3
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
     * The s3 client.
     *
     * @var     object
     * @access  public
     * @since   1.0.0
     */

    public $acoofm_S3_Client=false;
    /**
     * The plugin hook suffix.
     *
     * @var     array
     * @access  public
     * @since   1.0.0
     */
    public $hook_suffix = array();


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
            
            if($settings['provider'] !== 's3') {
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
            $credentials = [
                'version'     => '2006-03-01',
                'region'      => $this->config['region'],
                'use_accelerate_endpoint' => isset($this->config['transfer_accilaration'])
                                                ? $this->config['transfer_accilaration'] : false,
                'use_aws_shared_config_files' => false,
                'credentials' => [
                    'key'    => $this->config['access_key'],
                    'secret' => $this->config['secret_key'],
                ],
            ];

            $credentials = apply_filters('acoofm_s3_client_credentials', $credentials, $this->config);      
            $this->acoofm_S3_Client = new S3Client(acoofm_s3_client_args($credentials));
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
            $provider_label = 'Amazon S3';
            $credentials_payload = array(
                'access_key' => $accessKey,
                'secret_key' => $secretKey
            );

            try {
                $credentials = [
                    'version'     => '2006-03-01',
                    'region'      => 'us-east-1', // Required by SDK but doesn't matter for verification
                    'credentials' => [
                        'key'    => $accessKey,
                        'secret' => $secretKey,
                    ],
                ];
                $credentials = apply_filters('acoofm_s3_client_credentials', $credentials, $this->config);
                $s3 = new S3Client(acoofm_s3_client_args($credentials));

                try {
                    $result = $s3->listBuckets();
                } catch (Aws\S3\Exception\S3Exception $list_ex) {
                    $classified = ACOOFMF_VERIFY_HELPER::classify_s3_error($list_ex, 'list', $provider_label);
                    if ($classified['error_code'] === 'list_denied') {
                        return ACOOFMF_VERIFY_HELPER::list_denied_success($credentials_payload, $provider_label);
                    }
                    return ACOOFMF_VERIFY_HELPER::failure_from_classified($classified);
                }

                $bucketDetails = [];
                foreach ($result['Buckets'] as $bucket) {
                    $bucketName = $bucket['Name'];
                    $region = 'unknown';

                    try {
                        $locationResult = $s3->getBucketLocation([
                            'Bucket' => $bucketName
                        ]);
                        $region = $locationResult['LocationConstraint'] ?: 'us-east-1';
                    } catch (AwsException $e) {
                        if ($e->getAwsErrorCode() === 'AuthorizationHeaderMalformed') {
                            $errorMessage = $e->getMessage();
                            if (preg_match('/expecting \'([a-z0-9-]+)\'/', $errorMessage, $matches)) {
                                $region = $matches[1];
                            }
                        } else {
                            $region = 'us-east-1';
                        }
                    }

                    $bucketDetails[] = [
                        'name' => $bucketName,
                        'region' => $region
                    ];
                }

                return ACOOFMF_VERIFY_HELPER::listed_success(
                    $credentials_payload,
                    $bucketDetails,
                    __('AWS credentials are valid', 'offload-media-cloud-storage')
                );

            } catch (Aws\S3\Exception\S3Exception $ex){
                return ACOOFMF_VERIFY_HELPER::failure_from_classified(
                    ACOOFMF_VERIFY_HELPER::classify_s3_error($ex, 'auth', $provider_label)
                );
            }
        } else {
            return array('message' => __('Access key or secret key is missing', 'offload-media-cloud-storage'), 'code' => 400, 'success' => false);
        }
    }

    /**
    * Creates an S3 bucket with the specified parameters.
    *
    * @since 1.4.0
    *
    * @param string $access_key The AWS access key.
    * @param string $secret_key The AWS secret key.
    * @param string $region The AWS region where the bucket will be created.
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
                $credentials = [
                    'version'     => '2006-03-01',
                    'region'      => $region,
                    'use_accelerate_endpoint' => false,
                    'use_aws_shared_config_files' => false,
                    'credentials' => [
                        'key'    => $access_key,
                        'secret' => $secret_key,
                    ],
                ];
                $credentials = apply_filters('acoofm_s3_client_credentials', $credentials, $this->config);
                $s3Client = new S3Client(acoofm_s3_client_args($credentials));

                // Create the bucket. us-east-1 is the default location and S3
                // rejects it as an explicit LocationConstraint.
                $create_args = ['Bucket' => $bucket_name];
                if ($region !== 'us-east-1') {
                    $create_args['CreateBucketConfiguration'] = ['LocationConstraint' => $region];
                }
                $result = $s3Client->createBucket($create_args);

                // Wait until the bucket is created
                $s3Client->waitUntil('BucketExists', [
                    'Bucket' => $bucket_name,
                ]);

                // Ensure bucket owner has full control over ACLs
                $s3Client->putBucketOwnershipControls([
                    'Bucket' => $bucket_name,
                    'OwnershipControls' => [
                        'Rules' => [
                            ['ObjectOwnership' => 'BucketOwnerPreferred']
                        ]
                    ]
                ]);

                // Turn off "Block Public Access" settings for this bucket
                $s3Client->putPublicAccessBlock([
                    'Bucket' => $bucket_name,
                    'PublicAccessBlockConfiguration' => [
                        'BlockPublicAcls' => false,
                        'IgnorePublicAcls' => false,
                        'BlockPublicPolicy' => false,
                        'RestrictPublicBuckets' => false
                    ]
                ]);
                
                // Public read access for media URLs. Uploads, deletes and listing
                // use the signed credentials above, so anonymous users get nothing else.
                $policy = [
                    'Version' => '2012-10-17',
                    'Statement' => [
                        [
                            'Effect' => 'Allow',
                            'Principal' => '*',
                            'Action' => 's3:GetObject',
                            'Resource' => "arn:aws:s3:::{$bucket_name}/*"
                        ]
                    ]
                ];
                
                // Add a short delay to ensure the settings are updated
                sleep(2);
                
                $s3Client->putBucketPolicy([
                    'Bucket' => $bucket_name,
                    'Policy' => json_encode($policy)
                ]);
                
                // Configure CORS for the bucket
                $s3Client->putBucketCors([
                    'Bucket' => $bucket_name,
                    'CORSConfiguration' => [
                        'CORSRules' => [
                            [
                                'AllowedHeaders' => ['*'],
                                'AllowedMethods' => ['GET', 'PUT', 'POST', 'DELETE', 'HEAD'],
                                'AllowedOrigins' => [!empty($_SERVER['HTTP_ORIGIN']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_ORIGIN'])) : untrailingslashit(home_url())],
                                'ExposeHeaders' => ['ETag', 'Content-Length', 'Content-Type'],
                                'MaxAgeSeconds' => 3000
                            ]
                        ]
                    ]
                ]);

                // Retrieve the bucket's location and request ID from the result
                $bucketLocation = isset($result['Location']) ? $result['Location'] : 'unknown location';
                $requestId = isset($result['@metadata']['requestId']) ? $result['@metadata']['requestId'] : 'unknown request ID';

                // Return a success message with the retrieved details
                $msg = "Bucket '{$bucket_name}' created successfully at location: {$bucketLocation}. Request ID: {$requestId}.";
                return array('message' => $msg, 'code' => 200, 'success' => true);
            } catch (Aws\S3\Exception\S3Exception $ex) {
                return array('message' => $ex->getAwsErrorMessage() ?? __('Please check the authorization details', 'offload-media-cloud-storage'), 'code' => $ex->getStatusCode() ?? 405, 'success' => false);
            } catch (Exception $ex) {
                // e.g. the BucketExists waiter timing out or a network error
                return array('message' => $ex->getMessage(), 'code' => 500, 'success' => false);
            }
        } else {
            return array( 'message' => __('Insufficient Data. Please try again', 'offload-media-cloud-storage'), 'code' =>  405, 'success' => false);
        }
    }

    /**
     * Verify Credentials
     * @since 1.0.0
     * @return boolean
     */
    public function verify($access_key, $secret_key, $region, $bucket_name, $transfer_accilaration = false)
    {
        $provider_label = 'Amazon S3';

        if (empty($region) || empty($access_key) || empty($secret_key) || empty($bucket_name)) {
            return [
                'message' => __('Insufficient Data. Please try again', 'offload-media-cloud-storage'),
                'code'    => 405,
                'success' => false,
                'error_code' => 'insufficient_data',
            ];
        }

        try {
            /** --------------------------------------------------------
             * STEP 1: Initialize AWS S3 Client
             * -------------------------------------------------------- */
            $credentials = [
                'version'                     => '2006-03-01',
                'region'                      => $region,
                'use_accelerate_endpoint'     => $transfer_accilaration,
                'use_aws_shared_config_files' => false,
                'credentials'                 => [
                    'key'    => $access_key,
                    'secret' => $secret_key,
                ]
            ];

            // Allow other plugins to modify config
            $credentials = apply_filters('acoofm_s3_client_credentials', $credentials, $this->config);

            $s3Client = new S3Client(acoofm_s3_client_args($credentials));

            /** --------------------------------------------------------
             * STEP 2: Scoped bucket access check (no account-wide list)
             * -------------------------------------------------------- */
            $probe = ACOOFMF_VERIFY_HELPER::probe_bucket_access($s3Client, $bucket_name, $provider_label);
            if (empty($probe['ok'])) {
                return ACOOFMF_VERIFY_HELPER::failure_from_classified($probe);
            }

            /** --------------------------------------------------------
             * STEP 3: Create local test file in uploads directory
             * -------------------------------------------------------- */
            $upload_dir = wp_upload_dir();
            $fileName   = "acoofm_verify.txt";
            $filePath   = trailingslashit($upload_dir['basedir']) . $fileName;

            $verify_file = fopen($filePath, "w");
            if (!$verify_file) {
                return [
                    'message' => __('Unable to create verification file. Check uploads directory permissions.', 'offload-media-cloud-storage'),
                    'code'    => 500,
                    'success' => false,
                    'error_code' => 'local_fs',
                ];
            }

            fwrite($verify_file, "We are verifying input/output operations in AWS S3\n");
            fclose($verify_file);

            /** --------------------------------------------------------
             * STEP 4: Upload test file
             * -------------------------------------------------------- */
            try {
                $upload = $this->put_object($s3Client, $bucket_name, $fileName, $filePath);
            } catch (Exception $e) {
                @unlink($filePath);
                return ACOOFMF_VERIFY_HELPER::failure_from_classified(
                    ACOOFMF_VERIFY_HELPER::classify_s3_error($e, 'write', $provider_label)
                );
            }

            if (empty($upload['ObjectURL'])) {
                @unlink($filePath);
                return [
                    'message' => __('Upload failed. Possible permission issue.', 'offload-media-cloud-storage'),
                    'code'    => 403,
                    'success' => false,
                    'error_code' => 'write_denied',
                ];
            }

            /** --------------------------------------------------------
             * STEP 5: Download test file back from S3
             * -------------------------------------------------------- */
            $localDownload = trailingslashit($upload_dir['basedir']) . 'acoofm-local-verify.txt';

            try {
                $s3Client->getObject([
                    'Bucket' => $bucket_name,
                    'Key'    => $fileName,
                    'SaveAs' => $localDownload
                ]);
            } catch (Exception $e) {
                @unlink($filePath);
                return ACOOFMF_VERIFY_HELPER::failure_from_classified(
                    ACOOFMF_VERIFY_HELPER::classify_s3_error($e, 'read', $provider_label)
                );
            }

            if (!file_exists($localDownload)) {
                @unlink($filePath);
                return [
                    'message' => __('Downloaded file missing. Read permission issue.', 'offload-media-cloud-storage'),
                    'code'    => 403,
                    'success' => false,
                    'error_code' => 'read_denied',
                ];
            }

            @unlink($localDownload);

            /** --------------------------------------------------------
             * STEP 6: Delete object from S3
             * -------------------------------------------------------- */
            try {
                $s3Client->deleteObject([
                    'Bucket' => $bucket_name,
                    'Key'    => $fileName
                ]);
            } catch (Exception $e) {
                @unlink($filePath);
                return ACOOFMF_VERIFY_HELPER::failure_from_classified(
                    ACOOFMF_VERIFY_HELPER::classify_s3_error($e, 'delete', $provider_label)
                );
            }

            if ($s3Client->doesObjectExist($bucket_name, $fileName)) {
                @unlink($filePath);
                return [
                    'message' => __('Delete failed. Object still exists in bucket.', 'offload-media-cloud-storage'),
                    'code'    => 403,
                    'success' => false,
                    'error_code' => 'delete_denied',
                ];
            }

            /** --------------------------------------------------------
             * FINAL CLEANUP
             * -------------------------------------------------------- */
            if (file_exists($filePath)) {
                @unlink($filePath);
            }

            /** --------------------------------------------------------
             * SUCCESS
             * -------------------------------------------------------- */
            return [
                'message' => __('Configuration for AWS/S3 verified successfully!', 'offload-media-cloud-storage'),
                'code'    => 200,
                'success' => true
            ];

        } catch (Aws\S3\Exception\S3Exception $ex) {
            return ACOOFMF_VERIFY_HELPER::failure_from_classified(
                ACOOFMF_VERIFY_HELPER::classify_s3_error($ex, 'auth', $provider_label)
            );
        }
    }


    /**
     * Connect Function To Identify the congfigurations are correct
     * @since 1.0.0
     */
    public function connect()
    {
        if ($this->acoofm_S3_Client) {
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
            $this->acoofm_S3_Client->putObjectAcl([
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
            $this->acoofm_S3_Client->putObjectAcl([
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
     * Check the object exist 
     * @since 1.1.8
     */
    public function is_exist($key) {
        if(!$key) return false;

        if($this->acoofm_S3_Client->doesObjectExist($this->config['bucket_name'], $key)) {
            return true;
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
                if (filesize($media_absolute_path) <= ACOOFM_S3_MULTIPART_UPLOAD_MINIMUM_SIZE) {
                    // Upload a publicly accessible file. The file size and type are determined by the SDK.
                    try {
                        $upload = $this->put_object($this->acoofm_S3_Client, $this->config['bucket_name'], $upload_path, $media_absolute_path);

                        $result = array(
                            'success'   => true,
                            'code'      => 200,
                            'file_url'  => $upload->get('ObjectURL'),
                            'key'       => $upload_path,
                            'Message'   => __('File Uploaded Successfully', 'offload-media-cloud-storage')
                        );
                    } catch (Aws\S3\Exception\S3Exception $e) {
                        $result = array(
                            'success' => false,
                            'code'    => 403,
                            'Message' => $e->getMessage()
                        );
                    }
                } else {
                    try {
                        $uploaded = $this->multipart_upload($this->config['bucket_name'], $upload_path, $media_absolute_path);

                        if (isset($uploaded['ObjectURL']) && !empty($uploaded['ObjectURL'])) {
                            $result = array(
                                'success' => true,
                                'code'    => 200,
                                'file_url' => urldecode($uploaded['ObjectURL']),
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
        $path_parts = pathinfo($save_path);
        if (!file_exists($path_parts['dirname'])) {
            mkdir($path_parts['dirname'], 0755, true);
        }

        try {
            $getObject = $this->acoofm_S3_Client->GetObject([
                'Bucket' => $this->config['bucket_name'],
                'Key'    => $key,
                'SaveAs' => $save_path
            ]);
            if (file_exists($save_path)) {
                return true;
            }
        } catch (Aws\S3\Exception\S3Exception $e) {
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
                $this->acoofm_S3_Client->deleteObject([
                    'Bucket' => $this->config['bucket_name'],
                    'Key'    => $key
                ]);

                if (!$this->acoofm_S3_Client->doesObjectExist($this->config['bucket_name'], $key)) {  
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
            } catch (Aws\S3\Exception\S3Exception $e) {
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
     * Whether uploads to a bucket send the public-read object ACL.
     *
     * Buckets with Object Ownership "Bucket owner enforced" (the S3 default
     * since April 2023) reject every ACL. Such a bucket is remembered the
     * first time S3 refuses one; public access then comes from its bucket policy.
     *
     * @param string $bucket_name Bucket name.
     * @return boolean
     */
    private function use_object_acl($bucket_name)
    {
        $disabled = get_option('acoofm_s3_acl_disabled_buckets', array());
        $use_acl  = !(is_array($disabled) && !empty($disabled[$bucket_name]));

        return (bool) apply_filters('acoofm_s3_use_object_acl', $use_acl, $bucket_name);
    }

    /**
     * Remember that a bucket rejects object ACLs.
     *
     * @param string $bucket_name Bucket name.
     * @return void
     */
    private function disable_object_acl($bucket_name)
    {
        $disabled = get_option('acoofm_s3_acl_disabled_buckets', array());
        if (!is_array($disabled)) {
            $disabled = array();
        }
        $disabled[$bucket_name] = true;
        update_option('acoofm_s3_acl_disabled_buckets', $disabled, true);
    }

    /**
     * putObject with the public-read ACL, or without it when the bucket rejects ACLs.
     *
     * @param S3Client $client      S3 client.
     * @param string   $bucket_name Bucket name.
     * @param string   $key         Object key.
     * @param string   $file_path   Absolute file path.
     * @return \Aws\ResultInterface
     */
    private function put_object($client, $bucket_name, $key, $file_path)
    {
        $args = [
            'Bucket' => $bucket_name,
            'Key'    => $key,
            'Body'   => fopen($file_path, 'r'),
        ];

        if (!$this->use_object_acl($bucket_name)) {
            return $client->putObject($args);
        }

        try {
            return $client->putObject($args + ['ACL' => 'public-read']);
        } catch (AwsException $e) {
            if (!acoofm_is_acl_not_supported_error($e)) {
                throw $e;
            }
            $this->disable_object_acl($bucket_name);
            $args['Body'] = fopen($file_path, 'r');
            return $client->putObject($args);
        }
    }

    /**
     * Multipart upload with the public-read ACL, or without it when the bucket rejects ACLs.
     *
     * @param string $bucket_name Bucket name.
     * @param string $key         Object key.
     * @param string $file_path   Absolute file path.
     * @return \Aws\ResultInterface
     * @throws MultipartUploadException
     */
    private function multipart_upload($bucket_name, $key, $file_path)
    {
        $options = [
            'bucket' => $bucket_name,
            'key'    => $key,
        ];
        if ($this->use_object_acl($bucket_name)) {
            $options['acl'] = 'public-read';
        }

        try {
            return acoofm_multipart_upload($this->acoofm_S3_Client, $file_path, $options);
        } catch (MultipartUploadException $e) {
            if (!isset($options['acl']) || !acoofm_is_acl_not_supported_error($e)) {
                throw $e;
            }
            $this->disable_object_acl($bucket_name);
            unset($options['acl']);
            return acoofm_multipart_upload($this->acoofm_S3_Client, $file_path, $options);
        }
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
                $cmd = $this->acoofm_S3_Client->getCommand('GetObject', [
                    'Bucket' => $this->config['bucket_name'],
                    'Key'    => $key
                ]);

                $expires = acoofm_get_presigned_expire_minutes($this->settings, 10080); // SigV4 maximum: 7 days

                $request = $this->acoofm_S3_Client->createPresignedRequest($cmd, sprintf('+%s  minutes', $expires));

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
            } catch (Aws\S3\Exception\S3Exception $e) {
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
