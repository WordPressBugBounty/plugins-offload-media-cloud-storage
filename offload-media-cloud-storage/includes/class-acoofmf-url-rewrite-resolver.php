<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Central URL rewrite resolver for the free plugin.
 *
 * Selects the best valid media URL using acoofm_items DB records only.
 *
 * Priority order (highest to lowest):
 * 1. Offloaded file via CDN (enable_cdn + valid cdn_url)
 * 2. Offloaded file via presigned URL (enable_presigned, CDN disabled)
 * 3. Offloaded file direct from provider URL (DB url field)
 * 4. Local file on disk (when still present)
 * 5. Original input URL (safe fallback)
 *
 * CDN is applied after presigned generation when both are enabled,
 * matching existing may_generate_cdn_url() behaviour.
 */
class ACOOFMF_URL_Rewrite_Resolver {

    /** @var array<string, string> */
    private static $request_resolve_cache = array();

    /** @var array<int, array> */
    private static $attachment_context_cache = array();

    /** @var array<string, bool> */
    private static $local_exists_cache = array();

    /** @var array|null */
    private static $settings = null;

    /**
     * Resolve the best available URL for a media asset.
     *
     * @param string      $url           Local or cloud URL to rewrite.
     * @param int|null    $attachment_id Attachment post ID when known.
     * @param string      $size          Image size slug ('full', 'thumbnail', etc.).
     * @return string
     */
    public static function resolve( $url, $attachment_id = null, $size = 'full' ) {
        if ( empty( $url ) || ! is_string( $url ) ) {
            return $url;
        }

        $original_url = preg_replace( '/([^:])(\/{2,})/', '$1/', $url );
        $post_id      = $attachment_id ? (int) $attachment_id : (int) attachment_url_to_postid( $original_url );

        if ( ! $post_id || ! self::should_rewrite( $post_id ) ) {
            return $original_url;
        }

        $size          = is_string( $size ) && $size !== '' ? $size : 'full';
        $settings      = self::get_settings();
        $settings_hash = self::get_settings_hash( $settings );
        $resolve_key   = md5( $post_id . '|' . $original_url . '|' . $size . '|' . $settings_hash );

        if ( isset( self::$request_resolve_cache[ $resolve_key ] ) ) {
            return self::$request_resolve_cache[ $resolve_key ];
        }

        $context  = self::get_attachment_context( $post_id );
        $resolved = $original_url;

        if ( self::cloud_record_exists( $context, $size ) ) {
            $cloud_url = self::build_cloud_url( $post_id, $size );

            if ( ! empty( $cloud_url ) && is_string( $cloud_url ) ) {
                $resolved = $cloud_url;
            } elseif ( self::local_file_available( $context, $size, $original_url ) ) {
                $resolved = $original_url;
            }
        } elseif ( self::local_file_available( $context, $size, $original_url ) ) {
            $resolved = $original_url;
        }

        $resolved = apply_filters(
            'acoofm_rewrite_url',
            $resolved,
            $original_url,
            $post_id,
            $size,
            $settings
        );

        self::$request_resolve_cache[ $resolve_key ] = $resolved;

        return $resolved;
    }

    /**
     * Rewrite each srcset entry only when the matching size exists in plugin records.
     *
     * @param array $sources       Srcset sources from wp_calculate_image_srcset.
     * @param int   $attachment_id Attachment post ID.
     * @return array
     */
    public static function resolve_srcset_sources( $sources, $attachment_id ) {
        if ( empty( $sources ) || ! is_array( $sources ) || ! $attachment_id ) {
            return $sources;
        }

        if ( ! self::should_rewrite( $attachment_id ) ) {
            return $sources;
        }

        $context         = self::get_attachment_context( $attachment_id );
        $width_to_size   = self::build_width_to_size_map( $context, $attachment_id );

        foreach ( $sources as $width => $source ) {
            if ( empty( $source['url'] ) || ! is_string( $source['url'] ) ) {
                continue;
            }

            $size = isset( $width_to_size[ (int) $width ] ) ? $width_to_size[ (int) $width ] : 'full';
            $sources[ $width ]['url'] = self::resolve( $source['url'], $attachment_id, $size );
        }

        return $sources;
    }

    /**
     * Clear in-memory resolver caches (called on cache clear and between tests).
     */
    public static function clear_request_cache() {
        self::$request_resolve_cache    = array();
        self::$attachment_context_cache = array();
        self::$local_exists_cache       = array();
        self::$settings                 = null;
    }

    /**
     * Drop cached context for one attachment (e.g. after meta or DB updates).
     *
     * @param int $post_id Attachment post ID.
     */
    public static function invalidate_attachment( $post_id ) {
        unset( self::$attachment_context_cache[ (int) $post_id ] );
    }

    /**
     * Invalidate cached attachment context when rewrite-related meta changes.
     *
     * @param int    $meta_id    Meta row ID.
     * @param int    $object_id  Post ID.
     * @param string $meta_key   Meta key.
     * @param mixed  $meta_value Meta value.
     */
    public static function maybe_invalidate_attachment_meta( $meta_id, $object_id, $meta_key, $meta_value ) {
        unset( $meta_id, $meta_value );

        static $watched_keys = null;

        if ( null === $watched_keys ) {
            $watched_keys = array(
                ACOOFM_ATTACHMENT_META_KEY,
                'acoofm_presigned_urls',
            );
        }

        if ( in_array( $meta_key, $watched_keys, true ) ) {
            self::invalidate_attachment( $object_id );
        }
    }

    /**
     * Register hooks that keep rewrite caches coherent with attachment meta.
     */
    public static function acoofm_init() {
        add_action( 'updated_post_meta', array( __CLASS__, 'maybe_invalidate_attachment_meta' ), 10, 4 );
        add_action( 'deleted_post_meta', array( __CLASS__, 'maybe_invalidate_attachment_meta' ), 10, 4 );
    }

    /**
     * Whether URL rewriting should run for this attachment.
     *
     * @param int $attachment_id Attachment post ID.
     * @return bool
     */
    private static function should_rewrite( $attachment_id ) {
        global $acoofmItem;

        if ( ! $acoofmItem || ! acoofm_is_service_enabled() || ! acoofm_is_rewrite_url() ) {
            return false;
        }

        return $acoofmItem->is_available_from_provider( $attachment_id );
    }

    /**
     * Load and cache attachment offload metadata from acoofm_items.
     *
     * @param int $post_id Attachment post ID.
     * @return array
     */
    private static function get_attachment_context( $post_id ) {
        if ( isset( self::$attachment_context_cache[ $post_id ] ) ) {
            return self::$attachment_context_cache[ $post_id ];
        }

        global $acoofmItem;

        $item   = $acoofmItem ? $acoofmItem->get( $post_id ) : false;
        $extras = ( $acoofmItem && $item ) ? $acoofmItem->get_extras( $post_id ) : false;

        if ( ! is_array( $extras ) ) {
            $extras = array();
        }

        $settings = self::get_settings();

        $context = array(
            'post_id'             => $post_id,
            'item'                => is_array( $item ) ? $item : array(),
            'extras'              => $extras,
            'is_offloaded'        => is_array( $item ) && ! empty( $item['url'] ) && ! empty( $item['path'] ),
            'remove_from_server'  => ! empty( $settings['remove_from_server'] ),
        );

        self::$attachment_context_cache[ $post_id ] = $context;

        return $context;
    }

    /**
     * Confirm a cloud record exists for the requested size.
     *
     * @param array  $context Attachment context.
     * @param string $size    Image size slug.
     * @return bool
     */
    private static function cloud_record_exists( array $context, $size ) {
        if ( empty( $context['is_offloaded'] ) ) {
            return false;
        }

        if ( 'full' === $size ) {
            return ! empty( $context['item']['url'] ) && ! empty( $context['item']['path'] );
        }

        if ( 'original' === $size ) {
            return ! empty( $context['extras']['original']['file_url'] )
                && ! empty( $context['extras']['original']['key'] );
        }

        return ! empty( $context['extras']['sizes'][ $size ]['url'] )
            && ! empty( $context['extras']['sizes'][ $size ]['path'] );
    }

    /**
     * Build a cloud URL via the item layer (presigned / CDN / direct).
     *
     * @param int    $attachment_id Attachment post ID.
     * @param string $size          Image size slug.
     * @return string|false
     */
    private static function build_cloud_url( $attachment_id, $size ) {
        global $acoofmItem;

        if ( ! $acoofmItem ) {
            return false;
        }

        if ( 'full' === $size ) {
            return $acoofmItem->get_url( $attachment_id );
        }

        return $acoofmItem->get_thumbnail_url( $attachment_id, $size );
    }

    /**
     * Whether the local copy of the requested size is readable on disk.
     *
     * @param array  $context Attachment context.
     * @param string $size    Image size slug.
     * @param string $url     Fallback URL used to derive a local path.
     * @return bool
     */
    private static function local_file_available( array $context, $size, $url ) {
        $relative = self::get_local_relative_path( $context, $size );

        if ( $relative ) {
            $upload_dir = wp_get_upload_dir();
            $path       = trailingslashit( $upload_dir['basedir'] ) . ltrim( $relative, '/\\' );

            return self::local_file_exists_by_path( $path );
        }

        return self::local_file_exists( $url );
    }

    /**
     * Relative uploads path for a size, when known from plugin records or WP metadata.
     *
     * @param array  $context Attachment context.
     * @param string $size    Image size slug.
     * @return string|false
     */
    private static function get_local_relative_path( array $context, $size ) {
        if ( 'full' === $size && ! empty( $context['item']['source_path'] ) ) {
            return $context['item']['source_path'];
        }

        if ( 'original' === $size && ! empty( $context['extras']['original']['source'] ) ) {
            $upload_dir = wp_get_upload_dir();
            $base_dir   = ! empty( $context['item']['source_path'] )
                ? dirname( $context['item']['source_path'] )
                : '';

            if ( $base_dir ) {
                return trailingslashit( $base_dir ) . $context['extras']['original']['source'];
            }
        }

        if ( ! empty( $context['extras']['sizes'][ $size ]['source_path'] ) ) {
            return $context['extras']['sizes'][ $size ]['source_path'];
        }

        $meta = wp_get_attachment_metadata( $context['post_id'] );
        if ( is_array( $meta ) ) {
            if ( 'full' === $size && ! empty( $meta['file'] ) ) {
                return $meta['file'];
            }

            if ( isset( $meta['sizes'][ $size ]['file'] ) && ! empty( $meta['file'] ) ) {
                return trailingslashit( dirname( $meta['file'] ) ) . $meta['sizes'][ $size ]['file'];
            }
        }

        return false;
    }

    /**
     * Cached file_exists check keyed by URL.
     *
     * @param string $url Media URL.
     * @return bool
     */
    private static function local_file_exists( $url ) {
        $cache_key = md5( $url );

        if ( isset( self::$local_exists_cache[ $cache_key ] ) ) {
            return self::$local_exists_cache[ $cache_key ];
        }

        $relative = acoofm_get_attachment_relative_path( $url );
        if ( empty( $relative ) ) {
            self::$local_exists_cache[ $cache_key ] = false;
            return false;
        }

        $upload_dir = wp_get_upload_dir();
        $path       = trailingslashit( $upload_dir['basedir'] ) . ltrim( $relative, '/\\' );

        return self::local_file_exists_by_path( $path, $cache_key );
    }

    /**
     * Cached is_readable check keyed by absolute filesystem path.
     *
     * @param string      $path      Absolute filesystem path.
     * @param string|null $cache_key Optional cache key override.
     * @return bool
     */
    private static function local_file_exists_by_path( $path, $cache_key = null ) {
        $cache_key = $cache_key ? $cache_key : md5( $path );

        if ( isset( self::$local_exists_cache[ $cache_key ] ) ) {
            return self::$local_exists_cache[ $cache_key ];
        }

        $exists = is_readable( $path );
        self::$local_exists_cache[ $cache_key ] = $exists;

        return $exists;
    }

    /**
     * Map srcset widths to size slugs using plugin extras and WP metadata.
     *
     * @param array $context       Attachment context.
     * @param int   $attachment_id Attachment post ID.
     * @return array<int, string>
     */
    private static function build_width_to_size_map( array $context, $attachment_id ) {
        $map = array();

        if ( ! empty( $context['extras']['width'] ) ) {
            $map[ (int) $context['extras']['width'] ] = 'full';
        }

        if ( ! empty( $context['extras']['sizes'] ) && is_array( $context['extras']['sizes'] ) ) {
            foreach ( $context['extras']['sizes'] as $size => $size_data ) {
                if ( ! empty( $size_data['width'] ) ) {
                    $map[ (int) $size_data['width'] ] = $size;
                }
            }
        }

        $meta = wp_get_attachment_metadata( $attachment_id );
        if ( is_array( $meta ) ) {
            if ( ! empty( $meta['width'] ) && ! isset( $map[ (int) $meta['width'] ] ) ) {
                $map[ (int) $meta['width'] ] = 'full';
            }

            if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
                foreach ( $meta['sizes'] as $size => $size_data ) {
                    if ( ! empty( $size_data['width'] ) && ! isset( $map[ (int) $size_data['width'] ] ) ) {
                        $map[ (int) $size_data['width'] ] = $size;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * @return array
     */
    private static function get_settings() {
        if ( null === self::$settings ) {
            self::$settings = acoofm_get_settings();
            if ( ! is_array( self::$settings ) ) {
                self::$settings = array();
            }
        }

        return self::$settings;
    }

    /**
     * Hash settings that affect rewrite output for cache keys.
     *
     * @param array $settings Plugin settings.
     * @return string
     */
    private static function get_settings_hash( array $settings ) {
        return md5(
            wp_json_encode(
                array(
                    'rewrite_url'        => ! empty( $settings['rewrite_url'] ),
                    'enable_cdn'         => ! empty( $settings['enable_cdn'] ),
                    'cdn_url'            => $settings['cdn_url'] ?? '',
                    'enable_presigned'   => ! empty( $settings['enable_presigned'] ),
                    'presigned_expire'   => $settings['presigned_expire'] ?? '',
                    'remove_from_server' => ! empty( $settings['remove_from_server'] ),
                )
            )
        );
    }
}
