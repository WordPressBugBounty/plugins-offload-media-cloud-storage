<?php

/**
 * Load All Cloudeflare R2 related actions
 * It is used same sdk of s3
 *
 * @class   ACOOFMF_R2
 *
 * It is used to divide functionality of cloudflare r2 connection into different parts
 */

if (!defined('ABSPATH')) {
    exit;
}

// Libraries
use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Aws\S3\MultipartUploader;
use Aws\Exception\MultipartUploadException;
use Aws\CommandPool;

class ACOOFMF_R2
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
     * The Cloudeflare R2 client.
     *
     * @var     object
     * @access  public
     * @since   1.0.0
     */

    public $acoofm_r2_client=false;
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
            
            if($settings['provider'] !== 'r2') {
                return new WP_REST_Response(array('message' => __('Provider mismatch. Please check the credentials', 'offload-media-cloud-storage'), 'code' => 400, 'success' => false), 400);
            }
            $this->config['account_id'] = $settings['account-id'];
            $this->config['access_key'] = $settings['access-key-id'];
            $this->config['secret_key'] = $settings['secret-access-key'];
        }

        if (
            isset($this->config['account_id']) && !empty($this->config['account_id']) &&
            isset($this->config['access_key']) && !empty($this->config['access_key']) &&
            isset($this->config['secret_key']) && !empty($this->config['secret_key'])
        ) {
            $access_key_id = $this->config['access_key'];
            $access_key_secret = $this->config['secret_key'];
            $account_id  = $this->config['account_id'];

            $credentials = new Aws\Credentials\Credentials($access_key_id, $access_key_secret);
            $options = [
                'region' => 'auto',
                'endpoint' => "https://$account_id.r2.cloudflarestorage.com",
                'version' => 'latest',
                'credentials' => $credentials
            ];

            $this->acoofm_r2_client = new Aws\S3\S3Client($options);
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

    public function create_bucket($access_key, $secret_key, $account_id, $bucket_name, $region = 'auto', $api_token = '') {
        // Validate all required parameters
        if (empty($access_key) || empty($secret_key) || empty($account_id) || empty($bucket_name)) {
            return [
                'message' => __('Insufficient data. Please provide all required fields.', 'offload-media-cloud-storage'),
                'code' => 400,
                'success' => false
            ];
        }
    
        try {
            // Set up credentials and client options
            $credentials = new \Aws\Credentials\Credentials($access_key, $secret_key);
            $options = [
                'region' => $region,
                'endpoint' => "https://{$account_id}.r2.cloudflarestorage.com",
                'version' => 'latest',
                'credentials' => $credentials
            ];
            
            // Create S3 client
            $r2Client = new \Aws\S3\S3Client($options);
            
            // Create the bucket
            $result = $r2Client->createBucket([
                'Bucket' => $bucket_name,
            ]);
            
            // Wait until the bucket is created
            $r2Client->waitUntil('BucketExists', [
                'Bucket' => $bucket_name,
            ]);
            
            // Configure CORS for the bucket
            $corsConfiguration = [
                'CORSRules' => [
                    [
                        'AllowedHeaders' => ['*'],
                        'AllowedMethods' => ['GET', 'HEAD', 'PUT', 'POST', 'DELETE'],
                        'AllowedOrigins' => [$_SERVER['HTTP_ORIGIN']],
                        'ExposeHeaders' => ['ETag', 'Content-Length', 'Content-Type'],
                        'MaxAgeSeconds' => 86400 // 24 hours
                    ]
                ]
            ];
            
            $r2Client->putBucketCors([
                'Bucket' => $bucket_name,
                'CORSConfiguration' => $corsConfiguration
            ]);

            // Enable public access via Cloudflare API
            $cfApiUrl = "https://api.cloudflare.com/client/v4/accounts/{$account_id}/r2/buckets/{$bucket_name}/domains/managed";
            $cfApiHeaders = [
                'Authorization: Bearer ' . $api_token,
                'Content-Type: application/json',
            ];
            $cfApiData = json_encode(['enabled' => true]);

            $ch = curl_init($cfApiUrl);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_HTTPHEADER, $cfApiHeaders);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $cfApiData);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $cfApiResponse = curl_exec($ch);
            $cfApiHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($cfApiHttpCode !== 200) {
                return [
                    'message' => __('Bucket created, but failed to enable public access.', 'offload-media-cloud-storage'),
                    'code' => $cfApiHttpCode,
                    'success' => true
                ];
            }

            // Get public bucket URL
            $public_bucket_url = '';
            if ($cfApiHttpCode === 200) {
                $cfDomainData = json_decode($cfApiResponse, true);
                if (!empty($cfDomainData['result']['domain'])) {
                    $public_bucket_url = "https://" . $cfDomainData['result']['domain'];
                }
            }
            
            // Get bucket information
            $location = isset($result['Location']) ? $result['Location'] : 'default location';
            $requestId = isset($result['@metadata']['requestId']) ? $result['@metadata']['requestId'] : 'unknown';
            
            // For R2, we need to use public access setting via bucket settings in Cloudflare dashboard
            // or use R2 custom domains with public access
            return [
                'message' => "Bucket '{$bucket_name}' created successfully with CORS configuration. Location: {$location}. Request ID: {$requestId}. Note: For public access, configure the bucket in Cloudflare dashboard or set up a public R2 bucket using a custom domain.",
                'code' => 200,
                'success' => true,
                'additional_fields' => [
                    'public_bucket_url' => [
                        'name' => 'public_bucket_url',
                        'label' => __('Public Bucket URL', 'offload-media-cloud-storage'),
                        'value' => $public_bucket_url,
                        'type' => 'code'
                    ],
                    'enable_cdn' => [
                        'name' => 'enable_cdn',
                        'value' => true,
                        'type' => 'hidden'
                    ],
                    'cdn_url' => [
                        'name' => 'cdn_url',
                        'value' => $public_bucket_url,
                        'type' => 'hidden'
                    ]
                ]
            ];
        } catch (\Aws\S3\Exception\S3Exception $e) {
            return [
                'message' => $e->getAwsErrorMessage() ?: __('Error creating bucket. Please check your credentials and permissions.', 'offload-media-cloud-storage'),
                'code' => $e->getStatusCode() ?: 500,
                'success' => false
            ];
        } catch (\Exception $e) {
            return [
                'message' => $e->getMessage(),
                'code' => 500,
                'success' => false
            ];
        }
    }

    /**
     * Verify if the provided access key, secret key, and account ID are valid.
     * 
     * @param string $accountId The account ID to verify.
     * @param string $accessKey The access key to verify.
     * @param string $secretKey The secret key to verify.
     * @return array An array with 'success' as boolean, 'message', and 'credentials'.
     * @since 1.4.0
     */
    public function verify_keys($accountId, $accessKey, $secretKey) {
        if (!empty($accessKey) && !empty($secretKey)) {
            try {
                $client = new S3Client([
                    'version' => 'latest',
                    'region'  => 'auto',
                    'endpoint' => "https://{$accountId}.r2.cloudflarestorage.com",
                    'credentials' => [
                        'key'    => $accessKey,
                        'secret' => $secretKey
                    ],
                    'use_path_style_endpoint' => false
                ]);
                
                $result = $client->listBuckets();
                $bucketNames = [];
                $bucketDetails = [];
                foreach ($result['Buckets'] as $bucket) {

                    $bucketName = $bucket['Name'];
                    $region = 'unknown';
                    
                    try {
                        $locationResult = $client->getBucketLocation([
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
                            // Other error
                            $region = 'Error: ' . $e->getMessage();
                        }
                    }
                    
                    $bucketDetails[] = [
                        'name' => $bucketName,
                        'region' => $region
                    ];
                    $bucketNames[] = $bucket['Name'];
                }

                return array(
                    'message' => __('Cloudflare R2 credentials are valid', 'offload-media-cloud-storage'),
                    'code' => 200,
                    'success' => true,
                    'credentials' => array(
                        'account_id' => $accountId,
                        'access_key' => $accessKey,
                        'secret_key' => $secretKey
                    ),
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
    public function verify($access_key, $secret_key, $account_id, $bucket_name, $transfer_accilaration = false)
    {
        if (
            isset($account_id) && !empty($account_id) &&
            isset($access_key) && !empty($access_key) &&
            isset($secret_key) && !empty($secret_key) &&
            isset($bucket_name) && !empty($bucket_name)
        ) {
            try {
                $credentials = new Aws\Credentials\Credentials($access_key, $secret_key);
                $options = [
                    'region' => 'auto',
                    'endpoint' => "https://$account_id.r2.cloudflarestorage.com",
                    'version' => 'latest',
                    'credentials' => $credentials
                ];
                $r2Client = new Aws\S3\S3Client($options);

                //Listing all cloudeflare r2 Bucket
                $buckets = $r2Client->listBuckets();
                $bucket_found = false;
                $region_correct = false;
                if ($buckets) {
                    foreach ($buckets['Buckets'] as $bucket) {
                        if ($bucket['Name']==$bucket_name) {
                            $bucket_found = true;
                        }
                    }
                } else {
                    return array('message' => __('No Buckets found', 'offload-media-cloud-storage'), 'code' => 403, 'success' => false);
                }
                if ($bucket_found) {
                    $fileName = "acoofm_verify.txt";
                    $verify_file = fopen('./'.$fileName, "w");
                    $txt = "We are verifying input/output operations in Clodeflare R2\n";
                    fwrite($verify_file, $txt);
                    fclose($verify_file);

                    $upload = $r2Client->putObject([
                        'Bucket' => $bucket_name,
                        'Key'    => $fileName,
                        'Body'   => fopen('./'.$fileName, "r"),
                        'ACL'    => 'public-read', // make file 'public'
                    ]);
                    
                    @unlink($fileName);
                    if ($upload->get('ObjectURL')) {
                        try {
                            $getObject = $r2Client->GetObject([
                                'Bucket' => $bucket_name,
                                'Key'    => $fileName,
                                'SaveAs' => 'acoofm-local-verify.txt'
                            ]);

                            if (file_exists('acoofm-local-verify.txt')) {
                                @unlink('acoofm-local-verify.txt');
                                $r2Client->deleteObject([
                                    'Bucket' => $bucket_name,
                                    'Key'    => $fileName,
                                ]);
                
                                if (!$r2Client->doesObjectExist($bucket_name, $fileName)) {
                                    return array('message' => __('Configuration for Cloudflare R2 has verified successfully', 'offload-media-cloud-storage'), 'code' => 200, 'success' => true);
                                } else {
                                    return array('message' => __('User has permission issues on deleting the object from space, Please check ACL permission as well as policies', 'offload-media-cloud-storage'), 'code' => 403, 'success' => false);
                                }
                            } else {
                                return array('message' => __('User has permission issues on getting the object from space, Please check ACL permission as well as policies', 'offload-media-cloud-storage'), 'code' => 403, 'success' => false);
                            }
                        } catch (Aws\S3\Exception\S3Exception $ex) {
                            return array('message' => $ex->getAwsErrorMessage(), 'code' => $ex->getAwsErrorCode(), 'success' => false);
                        }
                    } else {
                        return array('message' => __('User has permission issues on putting object in to space, Please check ACL permission as well as policies', 'offload-media-cloud-storage'), 'code' => 403, 'success' => false);
                    }
                } else {
                    return array('message' => __('Space Name / Region is incorrect', 'offload-media-cloud-storage'), 'code' => 403, 'success' => false);
                }
            } catch (Aws\S3\Exception\S3Exception $ex) {
                return array('message' =>  $ex->getAwsErrorMessage() ?: __('Please check the authorization details', 'offload-media-cloud-storage'), 'code' => $ex->getStatusCode() ?? 405 , 'success' => false);
            }
        } else {
            return array( 'message' => __('Insufficient Data. Please try again', 'offload-media-cloud-storage'), 'code' =>  405, 'success' => false);
        }
    }

    /**
     * Connect Function To Identify the congfigurations are correct
     * @since 1.0.0
     */
    public function connect()
    {
        if ($this->acoofm_r2_client) {
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

        if($this->acoofm_r2_client->doesObjectExist($this->config['bucket_name'], $key)) {
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
            $this->acoofm_r2_client->putObjectAcl([
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
            $this->acoofm_r2_client->putObjectAcl([
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
                if (filesize($media_absolute_path) <= ACOOFM_CLOUDFLARE_MULTIPART_UPLOAD_MINIMUM_SIZE) {
                    // Upload a publicly accessible file. The file size and type are determined by the SDK.
                    try {
                        $upload = $this->acoofm_r2_client->putObject([
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
                    $multiUploader = new MultipartUploader($this->acoofm_r2_client, $media_absolute_path, [
                        'bucket' => $this->config['bucket_name'],
                        'key'    => $upload_path,
                        'acl'    => 'public-read', // make file 'public'
                    ]);
                    
                    try {
                        do {
                            try {
                                $uploaded = $multiUploader->upload();
                            } catch (MultipartUploadException $e) {
                                $multiUploader = new MultipartUploader($this->acoofm_r2_client, $media_absolute_path, [
                                    'state' => $e->getState(),
                                ]);
                            }
                        } while (!isset($uploaded));
                        if (isset($uploaded['ObjectURL']) && !empty($uploaded['ObjectURL'])) {
                            $url = (!preg_match("~^(?:f|ht)tps?://~i", $uploaded['ObjectURL'])) 
                                        ? "https://" . $uploaded['ObjectURL']
                                        : $uploaded['ObjectURL'];

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
            $getObject = $this->acoofm_r2_client->GetObject([
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
                $this->acoofm_r2_client->deleteObject([
                    'Bucket' => $this->config['bucket_name'],
                    'Key'    => $key
                ]);

                if (!$this->acoofm_r2_client->doesObjectExist($this->config['bucket_name'], $key)) {
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
                $cmd = $this->acoofm_r2_client->getCommand('GetObject', [
                    'Bucket' => $this->config['bucket_name'],
                    'Key'    => $key
                ]);

                $expires = isset($this->settings['presigned_expire']) ? $this->settings['presigned_expire'] : 20;

                $request = $this->acoofm_r2_client->createPresignedRequest($cmd, sprintf('+%s  minutes', $expires));

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
