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
        add_action('add_attachment', array($this, 'enqueue_new_attachment'), 20);
        add_action('acoofm_process_offload_attachment', array($this, 'process_scheduled_offload'), 10, 1);
        add_action('acoofm_process_offload_queue', array($this, 'process_offload_queue'));
        add_action('shutdown', array($this, 'maybe_process_offload_on_shutdown'), 100);
        add_filter('cron_schedules', array('ACOOFMF_Offload_Queue', 'cron_schedules'));
        add_filter('rest_request_before_callbacks', array($this, 'track_rest_media_request'), 10, 3);
        add_filter('rest_request_after_callbacks', array($this, 'untrack_rest_media_request'), 10, 3);

    }


    /**
     * Function to execute on wp_generate_attachment_metadata
     * To delete files from server only after updating all meta
     *
     * The offload runs after sub-size generation (final metadata update or the
     * queue worker), so the item does not exist yet here. The attachment is
     * flagged instead and remove_generated_local_files() deletes the local
     * copies once that offload completes.
     * @since 1.0.4
     *
     */

    public function wp_generate_attachment_metadata( $attachment_meta, $attachment_id, $action) {
        if($action == 'create') {
            if (!(
                acoofm_is_service_enabled() &&
                acoofm_is_copy_to_server() &&
                isset($attachment_id) && !empty($attachment_id)
            )) {
                return $attachment_meta;
            }

            $settings = acoofm_get_settings();
            if (!(isset($settings['remove_from_server']) && $settings['remove_from_server'])) {
                return $attachment_meta;
            }

            $rel_path = $this->get_attachment_rel_path($attachment_meta, (int) $attachment_id);
            if ($rel_path) {
                $this->get_offload_queue()->request_local_cleanup((int) $attachment_id, $rel_path);
            }
        }

        return $attachment_meta;
    }

    /**
     * Relative path of the attachment main file, as recorded in the items table.
     *
     * @param array $attachment_meta Attachment metadata.
     * @param int   $attachment_id   Attachment ID.
     * @return string|false
     */
    private function get_attachment_rel_path($attachment_meta, $attachment_id)
    {
        if (
            isset($attachment_meta) && !empty($attachment_meta) &&
            isset($attachment_meta['file']) && !empty($attachment_meta['file'])
        ) {
            $file = $attachment_meta['file'];
        } else {
            $file = acoofm_get_post_meta((int) $attachment_id, '_wp_attached_file', true);
        }

        return acoofm_get_attachment_relative_path($file);
    }

    /**
     * Delete the local files of a newly generated attachment once it is offloaded.
     *
     * Only runs for an attachment flagged by wp_generate_attachment_metadata
     * ('create') whose main file is still the generated one, and each file is
     * deleted only when the item records that same file in the cloud.
     *
     * @param int   $attachment_id   Attachment ID.
     * @param array $attachment_meta Attachment metadata (may be empty).
     * @return void
     */
    private function remove_generated_local_files($attachment_id, $attachment_meta)
    {
        global $acoofmItem;

        $cleanup_path = $this->get_offload_queue()->take_local_cleanup($attachment_id);
        if ($cleanup_path === '') {
            return;
        }

        $settings = acoofm_get_settings();
        if (!(isset($settings['remove_from_server']) && $settings['remove_from_server'])) {
            return;
        }

        if (!(isset($attachment_meta) && !empty($attachment_meta))) {
            $attachment_meta = wp_get_attachment_metadata($attachment_id);
        }

        $rel_path = $this->get_attachment_rel_path($attachment_meta, $attachment_id);
        $item     = $acoofmItem->get((int) $attachment_id);
        if (
            !$rel_path || $rel_path !== $cleanup_path ||
            !$item || !isset($item['source_path']) || $item['source_path'] !== $rel_path
        ) {
            return;
        }

        $upload_dir     = wp_get_upload_dir();
        $file_path      = trailingslashit($upload_dir['basedir']) . $rel_path;
        $file_path_dir  = dirname($file_path);
        $extras         = $acoofmItem->get_extras((int) $attachment_id);
        if (!is_array($extras)) {
            $extras = array();
        }

        if (file_exists($file_path)) {
            wp_delete_file($file_path);
        }

        // Delete original image if it is offloaded
        if (
            isset($attachment_meta['original_image']) && !empty($attachment_meta['original_image']) &&
            isset($extras['original']['source']) && $extras['original']['source'] === $attachment_meta['original_image']
        ) {
            $original_file_path = trailingslashit($file_path_dir) . $attachment_meta['original_image'];
            if (file_exists($original_file_path)) {
                wp_delete_file($original_file_path);
            }
        }

        // Confirm every offloaded size is deleted
        if (
            isset($attachment_meta['sizes']) && is_array($attachment_meta['sizes']) &&
            isset($extras['sizes']) && is_array($extras['sizes'])
        ) {
            foreach ($attachment_meta['sizes'] as $size => $sub_image) {
                if (
                    !isset($sub_image['file']) || empty($sub_image['file']) ||
                    !isset($extras['sizes'][$size]['url']) || empty($extras['sizes'][$size]['url'])
                ) {
                    continue;
                }

                $sub_file_path = $file_path_dir . '/' . $sub_image['file'];
                if (
                    isset($extras['sizes'][$size]['source_path']) &&
                    $extras['sizes'][$size]['source_path'] !== acoofm_get_attachment_relative_path($sub_file_path)
                ) {
                    continue;
                }

                if (file_exists($sub_file_path)) {
                    wp_delete_file($sub_file_path);
                }
            }
        }
    }


    /**
     * Persistent offload pipeline for the current request.
     *
     * @var ACOOFMF_Offload_Queue|null
     */
    private $offload_queue = null;

    /**
     * Enroll a newly created attachment before thumbnails or cloud upload run.
     *
     * add_attachment fires from wp_insert_attachment after the file is on disk
     * and the ID exists. Gallery REST uses this path. If metadata generation
     * later times out, the item remains pending instead of disappearing.
     *
     * @param int $post_id Attachment ID.
     * @return void
     */
    public function enqueue_new_attachment($post_id)
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return;
        }

        if (!(acoofm_is_service_enabled() && acoofm_is_copy_to_server())) {
            return;
        }

        if (function_exists('get_post_type') && get_post_type($post_id) && get_post_type($post_id) !== 'attachment') {
            return;
        }

        $queue = $this->get_offload_queue();
        if ($queue->enqueue($post_id)) {
            // Hold it while this request generates sub-sizes; released after the offload or at shutdown.
            $queue->acquire_lock($post_id);
        }
        $queue->ensure_cron();
    }

    /**
     * Drain pending/retrying attachments outside the media upload request.
     *
     * @return void
     */
    public function process_offload_queue()
    {
        if (!(acoofm_is_service_enabled() && acoofm_is_copy_to_server())) {
            return;
        }

        $queue = $this->get_offload_queue();

        // Work for a limited time per run instead of a fixed 3 items, so a large
        // batch is not spread over many minutes; anything left gets another run.
        $deadline = microtime(true) + (int) apply_filters('acoofm_offload_queue_time_limit', 20);
        $tried = array();
        do {
            // Skip IDs tried in this run (still locked by their upload request, or retried later).
            $ids = array_diff($queue->get_pending_ids(count($tried) + 5), $tried);
            foreach ($ids as $attachment_id) {
                $tried[] = $attachment_id;
                $this->process_scheduled_offload($attachment_id);
                if (microtime(true) >= $deadline) {
                    break 2;
                }
            }
        } while (!empty($ids));

        if (array_diff($queue->get_pending_ids(count($tried) + 1), $tried)) {
            $queue->ensure_cron(true);
        }
    }

    /**
     * After FastCGI has flushed the REST response, offload this request's items.
     *
     * @return void
     */
    public function maybe_process_offload_on_shutdown()
    {
        $queue = $this->get_offload_queue();

        if (!(acoofm_is_service_enabled() && acoofm_is_copy_to_server())) {
            $queue->release_held_locks();
            return;
        }

        // Uploads waiting for their client-side finalize are offloaded by that request.
        $ids = array_diff($queue->get_request_ids(), $this->awaiting_finalize);
        if (empty($ids)) {
            $queue->release_held_locks();
            return;
        }

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            foreach ($ids as $attachment_id) {
                $this->process_scheduled_offload($attachment_id);
            }
            $queue->release_held_locks();
            return;
        }

        // Hand this request's attachments to the queue worker.
        $queue->release_held_locks();
        $queue->ensure_cron();
    }

    /**
     * @return ACOOFMF_Offload_Queue
     */
    private function get_offload_queue()
    {
        if (!$this->offload_queue) {
            $this->offload_queue = new ACOOFMF_Offload_Queue();
        }

        return $this->offload_queue;
    }

    /**
     * Client-side media REST request being served: 'upload' or 'finalize'.
     *
     * @var array{type: string, id: int}|null
     */
    private $rest_media_request = null;

    /**
     * Outer requests' values while a nested REST request (rest_do_request) runs.
     *
     * @var array
     */
    private $rest_media_request_stack = array();

    /**
     * Attachments of this request that wait for their client-side finalize.
     *
     * @var int[]
     */
    private $awaiting_finalize = array();

    /**
     * Note client-side media processing requests (WordPress 7.1+).
     *
     * The block editor uploads only the original (generate_sub_sizes=false),
     * sideloads the sub-sizes it made in the browser, then calls /finalize,
     * whose response gives the editor the URLs it keeps.
     *
     * @param mixed           $response Response so far.
     * @param array           $handler  Route handler.
     * @param WP_REST_Request $request  Request.
     * @return mixed
     */
    public function track_rest_media_request($response, $handler, $request)
    {
        $this->rest_media_request_stack[] = $this->rest_media_request;
        $this->rest_media_request = null;

        if (!($request instanceof WP_REST_Request) || 'POST' !== $request->get_method()) {
            return $response;
        }

        $route = $request->get_route();
        if (preg_match('#^/wp/v2/media/(\d+)/finalize$#', $route, $matches)) {
            $this->rest_media_request = array('type' => 'finalize', 'id' => (int) $matches[1]);
        } elseif ('/wp/v2/media' === $route && false === $request['generate_sub_sizes']) {
            $this->rest_media_request = array('type' => 'upload', 'id' => 0);
        }

        return $response;
    }

    /**
     * @param mixed           $response Response.
     * @param array           $handler  Route handler.
     * @param WP_REST_Request $request  Request.
     * @return mixed
     */
    public function untrack_rest_media_request($response, $handler, $request)
    {
        $this->rest_media_request = $this->rest_media_request_stack
            ? array_pop($this->rest_media_request_stack)
            : null;

        return $response;
    }

    /**
     * Whether this request is the client-side finalize of the attachment.
     *
     * @param int $attachment_id Attachment ID.
     * @return bool
     */
    private function is_finalize_request($attachment_id)
    {
        return $this->rest_media_request
            && 'finalize' === $this->rest_media_request['type']
            && (int) $attachment_id === $this->rest_media_request['id'];
    }

    /**
     * Gallery /wp/v2/media POST must not perform cloud I/O in-process.
     *
     * The client-side /finalize request is the exception: every file is on
     * disk by then, and its response is where the editor gets the URLs.
     *
     * @param int $attachment_id Attachment ID.
     * @return bool
     */
    private function should_defer_offload($attachment_id = 0)
    {
        if (!empty($GLOBALS['acoofm_defer_offload'])) {
            return true;
        }

        if (
            $this->is_finalize_request($attachment_id) &&
            apply_filters('acoofm_offload_on_finalize', true, (int) $attachment_id)
        ) {
            return false;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : '';
            if (in_array($method, array('POST', 'PUT', 'PATCH'), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Offload an attachment after WordPress has saved its metadata.
     *
     * WordPress calls wp_update_attachment_metadata after every sub-size during
     * wp_generate_attachment_metadata. Gutenberg also uploads many files in
     * parallel. Cloud uploads in those intermediate/hot paths time out or throw,
     * which 500s /wp/v2/media (editor “Something went wrong”) and leaves the
     * local attachment unoffloaded.
     *
     * The attachment is enrolled as pending first. Cloud work is deferred on
     * REST media uploads and during thumbnail generation, then completed by
     * the queue worker so no ID can vanish from the pipeline. A client-side
     * upload (WordPress 7.1+) waits for its /finalize request, which offloads
     * in-process so the editor receives cloud URLs.
     *
     * Already offloaded attachments are enrolled again, so edited images,
     * regenerated thumbnails and new sub-sizes reach the cloud as well.
     *
     * @since 1.0.0
     */
    public function update_attachment_metadata($attachment_meta, $attachment_id)
    {
        if (!(
            acoofm_is_service_enabled() &&
            acoofm_is_copy_to_server() &&
            isset($attachment_id) && !empty($attachment_id)
        )) {
            return $attachment_meta;
        }

        $queue = $this->get_offload_queue();
        $queue->enqueue((int) $attachment_id, true);

        if ($this->is_during_image_subsize_generation()) {
            // Keep the queue worker off a partial set of sub-sizes until generation finishes.
            $queue->acquire_lock((int) $attachment_id);
            $queue->ensure_cron();
            return $attachment_meta;
        }

        if ($this->should_defer_offload($attachment_id)) {
            if ($this->rest_media_request && 'upload' === $this->rest_media_request['type']) {
                // Only the original exists yet; /finalize offloads the complete set.
                // The worker still takes it if the editor never finalizes.
                $queue->delay(
                    (int) $attachment_id,
                    (int) apply_filters('acoofm_client_side_finalize_wait', 300, (int) $attachment_id)
                );
                $this->awaiting_finalize[(int) $attachment_id] = (int) $attachment_id;
            }
            $queue->ensure_cron();
            return $attachment_meta;
        }

        $is_finalize = $this->is_finalize_request($attachment_id);
        if ($is_finalize) {
            // The upload runs before the response; keep it within the lock lifetime.
            $time_limit = (int) ini_get('max_execution_time');
            if ($time_limit > 0 && $time_limit < ACOOFMF_Offload_Queue::LOCK_TTL && function_exists('set_time_limit')) {
                @set_time_limit(ACOOFMF_Offload_Queue::LOCK_TTL);
            }
        }

        $this->run_offload_attempt((int) $attachment_id, $attachment_meta);

        if ($is_finalize) {
            // The response is built next in this request; resolve URLs from the new item.
            ACOOFMF_URL_Rewrite_Resolver::clear_request_cache();
        }

        return $attachment_meta;
    }

    /**
     * Process one queued attachment. Safe to call from cron after the upload REST request has finished.
     *
     * @param int $attachment_id Attachment ID.
     * @return void
     */
    public function process_scheduled_offload($attachment_id)
    {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0) {
            return;
        }

        if (!(acoofm_is_service_enabled() && acoofm_is_copy_to_server())) {
            return;
        }

        $attachment_meta = wp_get_attachment_metadata($attachment_id);
        if (!is_array($attachment_meta)) {
            $attachment_meta = array();
        }

        $this->run_offload_attempt($attachment_id, $attachment_meta);
    }

    /**
     * Why the last offload attempt uploaded nothing (provider message), if known.
     *
     * @var string
     */
    private $last_offload_error = '';

    /**
     * Attempt a single offload while holding a per-attachment lock.
     *
     * @param int   $attachment_id   Attachment ID.
     * @param array $attachment_meta Attachment metadata (may be empty).
     * @return void
     */
    private function run_offload_attempt($attachment_id, $attachment_meta)
    {
        $queue = $this->get_offload_queue();
        $status = $queue->get_status($attachment_id);

        if (ACOOFMF_Offload_Queue::STATUS_COMPLETED === $status) {
            return;
        }
        if (ACOOFMF_Offload_Queue::STATUS_PERMANENTLY_FAILED === $status) {
            return;
        }

        if (!$queue->acquire_lock($attachment_id)) {
            return;
        }

        $queue->enqueue($attachment_id);
        $queue->mark($attachment_id, ACOOFMF_Offload_Queue::STATUS_OFFLOADING);

        $requeued = false;
        $this->last_offload_error = '';
        try {
            $this->offload_attachment_on_metadata_update($attachment_meta, $attachment_id);

            global $acoofmItem;
            if ($acoofmItem && $acoofmItem->get($attachment_id)) {
                if (ACOOFMF_Offload_Queue::STATUS_COMPLETED === $queue->complete($attachment_id)) {
                    $this->remove_generated_local_files($attachment_id, $attachment_meta);
                } else {
                    $requeued = true;
                }
                delete_post_meta($attachment_id, '_acoofm_offload_retry_count');
            } else {
                $current = $queue->get_status($attachment_id);
                if (ACOOFMF_Offload_Queue::STATUS_PERMANENTLY_FAILED !== $current) {
                    $queue->mark_failure(
                        $attachment_id,
                        'Remote object was not recorded' . ('' !== $this->last_offload_error ? ': ' . $this->last_offload_error : '')
                    );
                }
            }
        } catch (\Throwable $e) {
            $queue->mark_failure($attachment_id, $e->getMessage());
        }

        $queue->release_lock($attachment_id);

        if ($requeued) {
            // Metadata changed during the upload; let the worker pick up the new version.
            $queue->ensure_cron();
        }
    }

    /**
     * Whether the current metadata update is an in-progress thumbnail save.
     *
     * @return bool
     */
    private function is_during_image_subsize_generation()
    {
        $skip_functions = array(
            'wp_generate_attachment_metadata',
            'wp_create_image_subsizes',
            '_wp_make_subsizes',
        );

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20) as $frame) {
            if (!empty($frame['function']) && in_array($frame['function'], $skip_functions, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the active storage client, or null when none is usable.
     *
     * @return object|null
     */
    private function get_storage_service()
    {
        global $acoofmService;
        global $acoofmProService;

        if (is_object($acoofmService) && method_exists($acoofmService, 'connect') && method_exists($acoofmService, 'uploadSingle')) {
            return $acoofmService;
        }

        if (is_object($acoofmProService) && method_exists($acoofmProService, 'connect') && method_exists($acoofmProService, 'uploadSingle')) {
            return $acoofmProService;
        }

        return null;
    }

    /**
     * Copy local attachment files to the configured provider.
     *
     * @param array $attachment_meta Attachment metadata.
     * @param int   $attachment_id   Attachment ID.
     * @return array
     */
    private function offload_attachment_on_metadata_update($attachment_meta, $attachment_id)
    {
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
                $this->get_offload_queue()->mark(
                    (int) $attachment_id,
                    ACOOFMF_Offload_Queue::STATUS_PERMANENTLY_FAILED,
                    'Extension is not enabled for offload'
                );
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
                    $service = $this->get_storage_service();

                    if (!($service && $service->connect())) {
                        $this->last_offload_error = 'Storage service is not connected';
                    } else {
                        $uploaded = $service->uploadSingle($file_path, $rel_path, $prefix);

                        if (empty($uploaded['success']) && !empty($uploaded['Message'])) {
                            $this->last_offload_error = (string) $uploaded['Message'];
                        }

                        if (!empty($uploaded['success'])) {
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
                            $service = $this->get_storage_service();

                            if ($service && $service->connect()) {
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
                    delete_post_meta((int) $attachment_id, '_acoofm_offload_retry_count');
                } else {
                    $acoofmItem->add((int) $attachment_id, $uploaded['file_url'], $uploaded['key'], $rel_path, $meta);
                    delete_post_meta((int) $attachment_id, '_acoofm_offload_retry_count');
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

        // No usable provider (e.g. a Pro-only provider while Pro is inactive).
        if (!(is_object($service) && method_exists($service, 'deleteSingle'))) {
            return;
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

        if (!(is_object($service) && method_exists($service, 'deleteSingle'))) {
            return;
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
        $new_url = ACOOFMF_URL_Rewrite_Resolver::resolve($url, $attachment_id, 'full');

        return apply_filters('acoofm_wp_get_attachment_url', $new_url, $url, $attachment_id);
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

        if (!$attachment) {
            return $attr;
        }

        $size = acoofm_maybe_convert_size_to_string($attachment->ID, $size);
        if ($size === false) {
            return $attr;
        }

        if (isset($attr['src']) && !empty($attr['src'])) {
            $attr['src'] = ACOOFMF_URL_Rewrite_Resolver::resolve($attr['src'], $attachment->ID, $size);
        }

        $acoofm_Item = ($acoofmItem && $acoofmItem->is_available_from_provider($attachment->ID))
            ? $acoofmItem->get($attachment->ID)
            : null;

        /**
         * Filtered list of attachment image attributes.
         *
         * @param array              $attr       Attributes for the image markup.
         * @param WP_Post            $attachment Image attachment post.
         * @param string             $size       Requested size.
         * @param ACOOFMF_ITEM|null  $acoofm_Item
         */
        return apply_filters('acoofm_wp_get_attachment_image_attributes', $attr, $attachment, $size, $acoofm_Item);
    }

    /**
     * Keep get_attached_file / wp_get_original_image_path as filesystem paths.
     *
     * Cloud URLs belong on wp_get_attachment_url / srcset / image attributes.
     * Returning a remote URL here makes WordPress 7 REST media (and
     * wp_get_missing_image_subsizes) call wp_getimagesize() on the original
     * over HTTP after each offload, which crashes the block editor during
     * gallery batch uploads.
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

        $acoofm_Item = ($acoofmItem && $acoofmItem->is_available_from_provider($attachment->ID))
            ? $acoofmItem->get($attachment->ID)
            : null;

        if (isset($response['url'])) {
            $response['url'] = ACOOFMF_URL_Rewrite_Resolver::resolve($response['url'], $attachment->ID, 'full');
        }

        if (isset($response['sizes']) && is_array($response['sizes'])) {
            foreach ($response['sizes'] as $size => $details) {
                if (!empty($details['url'])) {
                    $response['sizes'][$size]['url'] = ACOOFMF_URL_Rewrite_Resolver::resolve(
                        $details['url'],
                        $attachment->ID,
                        $size
                    );
                }
            }
        }

        /**
         * Filtered response for JS with updated thumbnail URLs.
         *
         * @param array              $response      The prepared attachment response.
         * @param WP_Post            $attachment    Attachment object.
         * @param string             $context       Context for response.
         * @param ACOOFMF_ITEM|null  $acoofm_Item   Item instance if available.
         */
        return apply_filters('acoofmp_wp_prepare_attachment_for_js', $response, $attachment, $context, $acoofm_Item);
    }

    /**
     * Change src attributes
     * @since 1.0.0
     */

    public function wp_calculate_image_srcset($sources, $size_array, $image_src, $attachment_meta, $attachment_id = 0)
    {
        if (!$attachment_id) {
            return $sources;
        }

        return ACOOFMF_URL_Rewrite_Resolver::resolve_srcset_sources($sources, (int) $attachment_id);
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
