<?php

/**
 * Shared credential / bucket verification helpers for storage providers.
 *
 * @class ACOOFMF_VERIFY_HELPER
 * @since 1.4.x
 */

if (!defined('ABSPATH')) {
    exit;
}

class ACOOFMF_VERIFY_HELPER
{
    /**
     * Classify S3-compatible exceptions into actionable error codes.
     *
     * @param mixed  $ex
     * @param string $context list|auth|bucket|write|read|delete
     * @param string $provider_label Human-readable provider name.
     * @return array{error_code:string,message:string,code:int}
     */
    public static function classify_s3_error($ex, $context = 'auth', $provider_label = 'storage')
    {
        $aws_message = (method_exists($ex, 'getAwsErrorMessage') ? $ex->getAwsErrorMessage() : '')
            ?: (method_exists($ex, 'getMessage') ? $ex->getMessage() : '');
        $aws_code = method_exists($ex, 'getAwsErrorCode') ? (string) $ex->getAwsErrorCode() : '';
        $status = method_exists($ex, 'getStatusCode') ? (int) $ex->getStatusCode() : 405;

        if ($aws_code === 'InvalidAccessKeyId') {
            return array(
                'error_code' => 'invalid_credentials',
                'message' => sprintf(
                    /* translators: %s: provider name */
                    __('Invalid Access Key. Please verify your %s Access Key.', 'offload-media-cloud-storage'),
                    $provider_label
                ),
                'code' => $status ?: 403,
            );
        }

        if ($aws_code === 'SignatureDoesNotMatch') {
            return array(
                'error_code' => 'invalid_credentials',
                'message' => sprintf(
                    /* translators: %s: provider name */
                    __('Invalid Secret Key. Please verify your %s Secret Key.', 'offload-media-cloud-storage'),
                    $provider_label
                ),
                'code' => $status ?: 403,
            );
        }

        if (
            $aws_code === 'NoSuchBucket' ||
            $aws_code === 'NotFound' ||
            $status === 404
        ) {
            return array(
                'error_code' => 'bucket_missing',
                'message' => __('Bucket not found. Please check the bucket name.', 'offload-media-cloud-storage'),
                'code' => 404,
            );
        }

        $is_access_denied = (
            $aws_code === 'AccessDenied' ||
            $aws_code === 'AccessDeniedException' ||
            $aws_code === 'AllAccessDisabled' ||
            $status === 403 ||
            stripos((string) $aws_message, 'Access Denied') !== false ||
            stripos((string) $aws_message, 'not authorized') !== false ||
            stripos((string) $aws_message, 'Permission denied') !== false ||
            stripos((string) $aws_message, 'PermissionDenied') !== false
        );

        if ($is_access_denied) {
            switch ($context) {
                case 'list':
                    return array(
                        'error_code' => 'list_denied',
                        'message' => __('Bucket listing denied. Enter the bucket name manually. Listing permission is only needed for the bucket dropdown.', 'offload-media-cloud-storage'),
                        'code' => 403,
                    );
                case 'write':
                    return array(
                        'error_code' => 'write_denied',
                        'message' => sprintf(
                            /* translators: %s: provider name */
                            __('Unable to upload object to %s. Check write permissions (and ACL permissions if required).', 'offload-media-cloud-storage'),
                            $provider_label
                        ),
                        'code' => 403,
                    );
                case 'read':
                    return array(
                        'error_code' => 'read_denied',
                        'message' => sprintf(
                            /* translators: %s: provider name */
                            __('Unable to download test object from %s. Check read permissions.', 'offload-media-cloud-storage'),
                            $provider_label
                        ),
                        'code' => 403,
                    );
                case 'delete':
                    return array(
                        'error_code' => 'delete_denied',
                        'message' => sprintf(
                            /* translators: %s: provider name */
                            __('Unable to delete object from %s. Check delete permissions.', 'offload-media-cloud-storage'),
                            $provider_label
                        ),
                        'code' => 403,
                    );
                case 'bucket':
                    return array(
                        'error_code' => 'bucket_access_denied',
                        'message' => __('Bucket not found or access denied. Check the bucket name and object read/write permissions on this bucket.', 'offload-media-cloud-storage'),
                        'code' => 403,
                    );
                default:
                    return array(
                        'error_code' => 'access_denied',
                        'message' => sprintf(
                            /* translators: %s: provider name */
                            __('Access Denied from %s. Please verify your credentials.', 'offload-media-cloud-storage'),
                            $provider_label
                        ),
                        'code' => 403,
                    );
            }
        }

        return array(
            'error_code' => 'unknown',
            'message' => $aws_message ?: __('Please check the authorization details', 'offload-media-cloud-storage'),
            'code' => $status ?: 405,
        );
    }

    /**
     * Classify Google Cloud Storage exceptions.
     *
     * @param mixed  $ex
     * @param string $context
     * @return array{error_code:string,message:string,code:int}
     */
    public static function classify_google_error($ex, $context = 'auth')
    {
        $message = method_exists($ex, 'getMessage') ? (string) $ex->getMessage() : '';
        $code = method_exists($ex, 'getCode') ? (int) $ex->getCode() : 405;

        $lower = strtolower($message);
        $is_permission = (
            $code === 403 ||
            strpos($lower, 'permission') !== false ||
            strpos($lower, 'access denied') !== false ||
            strpos($lower, 'forbidden') !== false ||
            strpos($lower, 'not authorized') !== false
        );

        $is_not_found = (
            $code === 404 ||
            strpos($lower, 'not found') !== false ||
            strpos($lower, 'nosuchbucket') !== false
        );

        $is_invalid_creds = (
            strpos($lower, 'invalid_grant') !== false ||
            strpos($lower, 'could not load the default credentials') !== false ||
            strpos($lower, 'invalid jwt') !== false ||
            strpos($lower, 'open_basedir') !== false ||
            strpos($lower, 'failed to open stream') !== false ||
            strpos($lower, 'unable to read') !== false
        );

        if ($is_invalid_creds && in_array($context, array('auth', 'list'), true)) {
            return array(
                'error_code' => 'invalid_credentials',
                'message' => __('Invalid Google Cloud credentials. Please check the service account JSON key file.', 'offload-media-cloud-storage'),
                'code' => $code ?: 403,
            );
        }

        if ($is_not_found) {
            return array(
                'error_code' => 'bucket_missing',
                'message' => __('Bucket not found. Please check the bucket name.', 'offload-media-cloud-storage'),
                'code' => 404,
            );
        }

        if ($is_permission) {
            switch ($context) {
                case 'list':
                    return array(
                        'error_code' => 'list_denied',
                        'message' => __('Bucket listing denied. Enter the bucket name manually. Listing permission is only needed for the bucket dropdown.', 'offload-media-cloud-storage'),
                        'code' => 403,
                    );
                case 'write':
                    return array(
                        'error_code' => 'write_denied',
                        'message' => __('Unable to upload object to Google Cloud Storage. Check write permissions on this bucket.', 'offload-media-cloud-storage'),
                        'code' => 403,
                    );
                case 'read':
                    return array(
                        'error_code' => 'read_denied',
                        'message' => __('Unable to download object from Google Cloud Storage. Check read permissions.', 'offload-media-cloud-storage'),
                        'code' => 403,
                    );
                case 'delete':
                    return array(
                        'error_code' => 'delete_denied',
                        'message' => __('Unable to delete object from Google Cloud Storage. Check delete permissions.', 'offload-media-cloud-storage'),
                        'code' => 403,
                    );
                case 'bucket':
                    return array(
                        'error_code' => 'bucket_access_denied',
                        'message' => __('Bucket not found or access denied. Check the bucket name and service account permissions.', 'offload-media-cloud-storage'),
                        'code' => 403,
                    );
                default:
                    return array(
                        'error_code' => 'access_denied',
                        'message' => __('Access Denied from Google Cloud Storage. Please verify your credentials.', 'offload-media-cloud-storage'),
                        'code' => 403,
                    );
            }
        }

        return array(
            'error_code' => 'unknown',
            'message' => $message ?: __('Please check the authorization details', 'offload-media-cloud-storage'),
            'code' => $code ?: 405,
        );
    }

    /**
     * Probe bucket access without listing all account buckets.
     *
     * @param object $client S3Client
     * @param string $bucket_name
     * @param string $provider_label
     * @return array
     */
    public static function probe_bucket_access($client, $bucket_name, $provider_label = 'storage')
    {
        try {
            $client->headBucket(array('Bucket' => $bucket_name));
            return array('ok' => true);
        } catch (Exception $head_ex) {
            $head_classified = self::classify_s3_error($head_ex, 'bucket', $provider_label);

            if ($head_classified['error_code'] === 'bucket_missing') {
                return array(
                    'ok' => false,
                    'error_code' => $head_classified['error_code'],
                    'message' => $head_classified['message'],
                    'code' => $head_classified['code'],
                );
            }

            try {
                $client->listObjectsV2(array(
                    'Bucket' => $bucket_name,
                    'MaxKeys' => 1,
                ));
                return array('ok' => true);
            } catch (Exception $list_ex) {
                $list_classified = self::classify_s3_error($list_ex, 'bucket', $provider_label);

                if (in_array($list_classified['error_code'], array('bucket_missing', 'invalid_credentials'), true)) {
                    return array(
                        'ok' => false,
                        'error_code' => $list_classified['error_code'],
                        'message' => $list_classified['message'],
                        'code' => $list_classified['code'],
                    );
                }

                // Access denied on probe is not final; object I/O is authoritative.
                return array('ok' => true, 'soft_denied' => true);
            }
        }
    }

    /**
     * Soft-success payload when credentials appear usable but listing is denied.
     *
     * @param array  $credentials
     * @param string $provider_label
     * @param string $warning
     * @return array
     */
    public static function list_denied_success($credentials, $provider_label, $warning = '')
    {
        if ($warning === '') {
            $warning = sprintf(
                /* translators: %s: provider name */
                __('Your %s credentials cannot list buckets. Enter the bucket name manually. Listing permission is only needed for the bucket dropdown.', 'offload-media-cloud-storage'),
                $provider_label
            );
        }

        return array(
            'message' => __('Credentials accepted. Bucket listing is not permitted for this token; enter the bucket name manually on the next step.', 'offload-media-cloud-storage'),
            'code' => 200,
            'success' => true,
            'credentials' => $credentials,
            'buckets' => array(),
            'capabilities' => array(
                'can_list_buckets' => false,
                'auth_status' => 'authenticated_unlisted',
            ),
            'warnings' => array($warning),
        );
    }

    /**
     * Success payload when bucket listing works.
     *
     * @param array  $credentials
     * @param array  $buckets
     * @param string $message
     * @return array
     */
    public static function listed_success($credentials, $buckets, $message)
    {
        return array(
            'message' => $message,
            'code' => 200,
            'success' => true,
            'credentials' => $credentials,
            'buckets' => $buckets,
            'capabilities' => array(
                'can_list_buckets' => true,
                'auth_status' => 'authenticated',
            ),
            'warnings' => array(),
        );
    }

    /**
     * Convert classified error into API failure payload.
     *
     * @param array $classified
     * @return array
     */
    public static function failure_from_classified($classified)
    {
        return array(
            'message' => $classified['message'],
            'code' => $classified['code'],
            'success' => false,
            'error_code' => $classified['error_code'],
        );
    }

    /**
     * Build serviceConnect response from verify() result.
     *
     * @param array $result
     * @return array
     */
    public static function service_connect_response($result)
    {
        $response = array(
            'message' => isset($result['message']) ? $result['message'] : '',
            'code' => isset($result['code']) ? $result['code'] : 405,
            'success' => !empty($result['success']),
        );

        if (!empty($result['error_code'])) {
            $response['error_code'] = $result['error_code'];
        }

        return $response;
    }
}
