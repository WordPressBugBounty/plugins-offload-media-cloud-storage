<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Persistent per-attachment offload pipeline.
 *
 * Offload used to run only inside wp_update_attachment_metadata. WordPress
 * calls that hook after every thumbnail, Gutenberg uploads many files in
 * parallel over REST, and the previous "skip during generation + cron retry"
 * path never recorded state if the request died first. Attachments then
 * existed locally with no queue entry.
 *
 * Every successfully created attachment is enrolled here as pending before
 * any cloud work. Status is queryable independently of the items table.
 */
class ACOOFMF_Offload_Queue
{
    const STATUS_PENDING             = 'pending';
    const STATUS_OFFLOADING          = 'offloading';
    const STATUS_COMPLETED           = 'completed';
    const STATUS_FAILED              = 'failed';
    const STATUS_RETRYING            = 'retrying';
    const STATUS_PERMANENTLY_FAILED  = 'permanently_failed';

    const META_STATUS   = '_acoofm_offload_status';
    const META_STATE    = '_acoofm_offload_state';
    const META_RETRY_AT = '_acoofm_offload_retry_at';

    // Per attachment, in the options table where option_name is unique (atomic insert).
    const LOCK_OPTION_PREFIX = 'acoofm_offload_lock_';

    const MAX_ATTEMPTS = 5;
    const LOCK_TTL     = 300;

    /**
     * Wait before retry n (seconds), so a provider outage of about an hour
     * does not use up every attempt. The last value repeats.
     *
     * @var int[]
     */
    private static $retry_delays = array(60, 300, 900, 3600);
    const CRON_HOOK    = 'acoofm_process_offload_queue';
    const CRON_INTERVAL = 'acoofm_offload_interval';

    /**
     * Attachment IDs enrolled during the current request.
     *
     * @var int[]
     */
    private $request_ids = array();

    /**
     * Lock values written by this request, keyed by attachment ID.
     *
     * @var int[]
     */
    private $held_locks = array();

    /**
     * Enroll an attachment so it cannot leave the pipeline untracked.
     *
     * @param int  $attachment_id Attachment ID.
     * @param bool $reprocess     The attachment metadata changed (edit, regenerate,
     *                            new sub-sizes), so an existing cloud copy no longer
     *                            means the attachment is fully offloaded.
     * @return bool True when the item still needs processing.
     */
    public function enqueue($attachment_id, $reprocess = false)
    {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0) {
            return false;
        }

        if ($reprocess) {
            // Another request (queue worker) may have changed the status since this request cached it.
            wp_cache_delete($attachment_id, 'post_meta');
        } else {
            global $acoofmItem;
            if ($acoofmItem && method_exists($acoofmItem, 'get') && $acoofmItem->get($attachment_id)) {
                $this->write($attachment_id, self::STATUS_COMPLETED);
                return false;
            }
        }

        $status = $this->get_status($attachment_id);
        if ($reprocess && self::STATUS_COMPLETED === $status) {
            $this->write($attachment_id, self::STATUS_PENDING, array('attempts' => 0, 'last_error' => ''));
            $status = self::STATUS_PENDING;
        }
        if (self::STATUS_COMPLETED === $status) {
            return false;
        }
        if (self::STATUS_PERMANENTLY_FAILED === $status) {
            return false;
        }
        if (self::STATUS_OFFLOADING === $status && $this->has_fresh_lock($attachment_id)) {
            if ($reprocess) {
                // The running worker uploads the previous metadata; complete() leaves this pending for another pass.
                $this->write($attachment_id, self::STATUS_PENDING);
                $this->request_ids[$attachment_id] = $attachment_id;
            }
            return true;
        }

        if (self::STATUS_RETRYING !== $status && self::STATUS_FAILED !== $status) {
            $this->write($attachment_id, self::STATUS_PENDING);
        }

        $this->request_ids[$attachment_id] = $attachment_id;
        return true;
    }

    /**
     * @param int $attachment_id Attachment ID.
     * @return string Empty when never enrolled.
     */
    public function get_status($attachment_id)
    {
        $status = (string) get_post_meta((int) $attachment_id, self::META_STATUS, true);
        return $status;
    }

    /**
     * @param int $attachment_id Attachment ID.
     * @return array
     */
    public function get_state($attachment_id)
    {
        $state = get_post_meta((int) $attachment_id, self::META_STATE, true);
        if (!is_array($state)) {
            $state = array();
        }

        return array_merge(
            array(
                'status'     => $this->get_status($attachment_id),
                'attempts'   => 0,
                'last_error' => '',
                'updated_at' => 0,
            ),
            $state
        );
    }

    /**
     * @param int[] $attachment_ids Attachment IDs.
     * @return array<string,int>
     */
    public function summarize(array $attachment_ids)
    {
        $counts = array(
            'pending'            => 0,
            'offloading'         => 0,
            'completed'          => 0,
            'failed'             => 0,
            'retrying'           => 0,
            'permanently_failed' => 0,
            'missing'            => 0,
        );

        foreach ($attachment_ids as $attachment_id) {
            $status = $this->get_status((int) $attachment_id);
            if ($status === '' || !isset($counts[$status])) {
                $counts['missing']++;
                continue;
            }
            $counts[$status]++;
        }

        return $counts;
    }

    /**
     * @param int    $attachment_id Attachment ID.
     * @param string $status         Pipeline status.
     * @param string $error          Last error message.
     * @return void
     */
    public function mark($attachment_id, $status, $error = '')
    {
        $attachment_id = (int) $attachment_id;
        $state = $this->get_state($attachment_id);
        if (self::STATUS_RETRYING === $status || self::STATUS_FAILED === $status || self::STATUS_PERMANENTLY_FAILED === $status) {
            $state['attempts'] = (int) $state['attempts'] + 1;
        }
        if (self::STATUS_COMPLETED === $status) {
            $state['attempts'] = (int) $state['attempts'];
            $state['last_error'] = '';
        }
        if ($error !== '') {
            $state['last_error'] = $error;
        }
        $this->write($attachment_id, $status, $state);
    }

    /**
     * Record a failed attempt and decide retry vs permanent failure.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $error          Error message.
     * @return string Resulting status.
     */
    public function mark_failure($attachment_id, $error = '')
    {
        $state = $this->get_state($attachment_id);
        $attempts = (int) $state['attempts'] + 1;
        $status = $attempts >= self::MAX_ATTEMPTS
            ? self::STATUS_PERMANENTLY_FAILED
            : self::STATUS_RETRYING;

        $state['attempts'] = $attempts;
        $state['last_error'] = $error;
        $this->write($attachment_id, $status, $state);
        if (self::STATUS_RETRYING === $status) {
            $delay = self::$retry_delays[min($attempts, count(self::$retry_delays)) - 1];
            update_post_meta((int) $attachment_id, self::META_RETRY_AT, time() + $delay);
        }
        $this->release_lock($attachment_id);

        return $status;
    }

    /**
     * Queue permanently failed attachments again, e.g. after the credentials
     * or the allowed file types changed.
     *
     * @return int Number of attachments queued.
     */
    public function reset_permanently_failed()
    {
        global $wpdb;

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
            self::META_STATUS,
            self::STATUS_PERMANENTLY_FAILED
        ));
        if (!is_array($ids)) {
            return 0;
        }

        foreach ($ids as $attachment_id) {
            $this->write((int) $attachment_id, self::STATUS_PENDING, array('attempts' => 0, 'last_error' => ''));
        }

        return count($ids);
    }

    /**
     * Record a successful attempt.
     *
     * When the metadata changed while the files were uploading, enqueue() has
     * set the status back to pending; it then stays pending for another pass.
     *
     * @param int $attachment_id Attachment ID.
     * @return string Resulting status.
     */
    public function complete($attachment_id)
    {
        $attachment_id = (int) $attachment_id;
        wp_cache_delete($attachment_id, 'post_meta');

        if (self::STATUS_PENDING === $this->get_status($attachment_id)) {
            return self::STATUS_PENDING;
        }

        $this->mark($attachment_id, self::STATUS_COMPLETED);
        return self::STATUS_COMPLETED;
    }

    /**
     * Keep a pending attachment away from the queue worker for a while.
     *
     * Uses the retry time get_pending_ids() already honours. The next write()
     * with any status other than retrying (e.g. enqueue() again) clears it.
     *
     * @param int $attachment_id Attachment ID.
     * @param int $seconds       How long to wait.
     * @return void
     */
    public function delay($attachment_id, $seconds)
    {
        $attachment_id = (int) $attachment_id;
        $seconds = (int) $seconds;
        if ($attachment_id <= 0 || $seconds <= 0 || self::STATUS_PENDING !== $this->get_status($attachment_id)) {
            return;
        }

        update_post_meta($attachment_id, self::META_RETRY_AT, time() + $seconds);
    }

    /**
     * Remember that the local files of a newly generated attachment can be
     * removed once it is offloaded (remove_from_server).
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $rel_path      Relative path of the generated main file.
     * @return void
     */
    public function request_local_cleanup($attachment_id, $rel_path)
    {
        $attachment_id = (int) $attachment_id;
        $state = $this->get_state($attachment_id);
        $state['cleanup_path'] = (string) $rel_path;
        update_post_meta($attachment_id, self::META_STATE, $state);
    }

    /**
     * Take the pending local cleanup request, if any.
     *
     * @param int $attachment_id Attachment ID.
     * @return string Relative path of the generated main file, empty when none.
     */
    public function take_local_cleanup($attachment_id)
    {
        $attachment_id = (int) $attachment_id;
        $state = $this->get_state($attachment_id);
        if (empty($state['cleanup_path'])) {
            return '';
        }

        $rel_path = (string) $state['cleanup_path'];
        unset($state['cleanup_path']);
        update_post_meta($attachment_id, self::META_STATE, $state);

        return $rel_path;
    }

    /**
     * @param int $limit Max IDs.
     * @return int[]
     */
    public function get_pending_ids($limit = 5)
    {
        $limit = max(1, (int) $limit);
        $pending = array();

        global $wpdb;
        if (isset($wpdb) && is_object($wpdb) && isset($wpdb->postmeta) && method_exists($wpdb, 'get_col')) {
            // Retries wait for their backoff time (META_RETRY_AT).
            $sql = $wpdb->prepare(
                "SELECT s.post_id FROM {$wpdb->postmeta} s
                    LEFT JOIN {$wpdb->postmeta} r ON r.post_id = s.post_id AND r.meta_key = %s
                    WHERE s.meta_key = %s AND s.meta_value IN (%s, %s, %s, %s)
                    AND (r.meta_value IS NULL OR CAST(r.meta_value AS UNSIGNED) <= %d)
                    LIMIT %d",
                self::META_RETRY_AT,
                self::META_STATUS,
                self::STATUS_PENDING,
                self::STATUS_RETRYING,
                self::STATUS_FAILED,
                self::STATUS_OFFLOADING,
                time(),
                $limit
            );
            $rows = $wpdb->get_col($sql);
            if (is_array($rows)) {
                return array_map('intval', $rows);
            }
        }

        if (!empty($GLOBALS['post_meta']) && is_array($GLOBALS['post_meta'])) {
            foreach ($GLOBALS['post_meta'] as $post_id => $meta) {
                $status = isset($meta[self::META_STATUS]) ? $meta[self::META_STATUS] : '';
                if (in_array($status, array(self::STATUS_PENDING, self::STATUS_RETRYING, self::STATUS_FAILED, self::STATUS_OFFLOADING), true)) {
                    $pending[] = (int) $post_id;
                    if (count($pending) >= $limit) {
                        break;
                    }
                }
            }
        }

        return $pending;
    }

    /**
     * @param int $attachment_id Attachment ID.
     * @return bool
     */
    public function acquire_lock($attachment_id)
    {
        global $wpdb;

        $attachment_id = (int) $attachment_id;
        $name = self::LOCK_OPTION_PREFIX . $attachment_id;
        $now = time();

        // Re-entrant: the request generating sub-sizes keeps its lock until the final offload.
        if (isset($this->held_locks[$attachment_id])) {
            $held = $this->held_locks[$attachment_id];
            if ($this->swap_lock($name, $held, $now)) {
                $this->held_locks[$attachment_id] = $now;
                return true;
            }
            if ($this->read_lock($attachment_id) === $held) {
                return true; // Refreshed within the same second.
            }
            // Expired and taken over by another worker.
            unset($this->held_locks[$attachment_id]);
        }

        // Atomic like WP_Upgrader::create_lock(): option_name is unique, so one request wins.
        $created = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $name,
            $now
        ));
        if (!$created) {
            $current = $this->read_lock($attachment_id);
            // Take over a stale lock; only one request can swap the old value.
            if (!$current || ($now - $current) < self::LOCK_TTL || !$this->swap_lock($name, $current, $now)) {
                return false;
            }
        }

        $this->held_locks[$attachment_id] = $now;
        return true;
    }

    /**
     * @param int $attachment_id Attachment ID.
     * @return void
     */
    public function release_lock($attachment_id)
    {
        global $wpdb;

        $attachment_id = (int) $attachment_id;
        if (!isset($this->held_locks[$attachment_id])) {
            return;
        }

        // Only remove our own lock, not one another worker took after ours expired.
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            self::LOCK_OPTION_PREFIX . $attachment_id,
            $this->held_locks[$attachment_id]
        ));
        unset($this->held_locks[$attachment_id]);
    }

    /**
     * Release every lock this request still holds.
     *
     * @return void
     */
    public function release_held_locks()
    {
        foreach (array_keys($this->held_locks) as $attachment_id) {
            $this->release_lock($attachment_id);
        }
    }

    /**
     * IDs enrolled in this PHP request.
     *
     * @return int[]
     */
    public function get_request_ids()
    {
        return array_values($this->request_ids);
    }

    /**
     * Ensure a recurring drain job exists and wake WP-Cron.
     *
     * @param bool $soon Schedule a near-term single event as well.
     * @return void
     */
    public function ensure_cron($soon = false)
    {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) {
            return;
        }

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), self::CRON_INTERVAL, self::CRON_HOOK);
        }

        // The recurring event has no args, so the extra run needs its own to be scheduled.
        if ($soon && function_exists('wp_schedule_single_event') && !wp_next_scheduled(self::CRON_HOOK, array('soon'))) {
            wp_schedule_single_event(time(), self::CRON_HOOK, array('soon'));
        }

        // spawn_cron() itself ignores DISABLE_WP_CRON (sites running a real cron).
        if (function_exists('spawn_cron') && !(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON)) {
            spawn_cron();
        }
    }

    /**
     * Remove the queue events (plugin deactivation).
     *
     * @return void
     */
    public static function unschedule()
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_clear_scheduled_hook(self::CRON_HOOK, array('soon'));
    }

    /**
     * @return array
     */
    public static function cron_schedules($schedules)
    {
        if (!is_array($schedules)) {
            $schedules = array();
        }
        $schedules[self::CRON_INTERVAL] = array(
            'interval' => 60,
            'display'  => __('Offload Media queue', 'offload-media-cloud-storage'),
        );
        return $schedules;
    }

    /**
     * @param int   $attachment_id Attachment ID.
     * @param string $status       Status.
     * @param array  $state        Extra state.
     * @return void
     */
    private function write($attachment_id, $status, array $state = array())
    {
        $attachment_id = (int) $attachment_id;
        $current = $this->get_state($attachment_id);
        $state = array_merge($current, $state);
        $state['status'] = $status;
        $state['updated_at'] = time();

        update_post_meta($attachment_id, self::META_STATUS, $status);
        update_post_meta($attachment_id, self::META_STATE, $state);

        // Only retries wait (mark_failure() sets the time after this write).
        if (self::STATUS_RETRYING !== $status && get_post_meta($attachment_id, self::META_RETRY_AT, true) !== '') {
            delete_post_meta($attachment_id, self::META_RETRY_AT);
        }
    }

    /**
     * @param int $attachment_id Attachment ID.
     * @return bool
     */
    private function has_fresh_lock($attachment_id)
    {
        $lock = $this->read_lock($attachment_id);
        return $lock > 0 && (time() - $lock) < self::LOCK_TTL;
    }

    /**
     * Lock time, read from the database (not the options cache).
     *
     * @param int $attachment_id Attachment ID.
     * @return int 0 when unlocked.
     */
    private function read_lock($attachment_id)
    {
        global $wpdb;

        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            self::LOCK_OPTION_PREFIX . (int) $attachment_id
        ));
        return null === $value ? 0 : (int) $value;
    }

    /**
     * Replace a lock value only if it is still the expected one.
     *
     * @param string $name     Lock option name.
     * @param int    $expected Current lock time.
     * @param int    $value    New lock time.
     * @return bool
     */
    private function swap_lock($name, $expected, $value)
    {
        global $wpdb;

        return (bool) $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
            $value,
            $name,
            $expected
        ));
    }
}
