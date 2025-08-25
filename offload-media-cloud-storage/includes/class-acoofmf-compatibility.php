<?php

/**
 * Manage All URL Compatibilities here
 *
 * @class   ACOOFMF_COMPATIBILITY
 * 
 * 
 */

if (!defined('ABSPATH')) {
    exit;
}

//

class ACOOFMF_COMPATIBILITY {

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
     * The plugin assets URL.
     *
     * @var     string
     * @access  public
     * @since   1.0.0
     */

    public $assets_url;
 
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
    public function __construct($file = ''){
        $this->version          = ACOOFM_VERSION;
        $this->token            = ACOOFM_TOKEN;
        $this->file             = $file;
        $this->dir              = dirname($this->file);
        $this->assets_dir       = trailingslashit($this->dir) . 'assets';
        $this->assets_url       = esc_url(trailingslashit(plugins_url('/assets/', $this->file)));

        // Load action for compatibility
        $this->register_actions();
    }

    public function register_actions () {

        /** IMAGE EDITOR COMPATIBILITY */
        add_action( 'attachment_submitbox_misc_actions', array( $this, 'attachment_submitbox_metadata'),99 );
        //Media Modal Ajax
		add_action( 'wp_ajax_acoofm_get_attachment_details', array( $this, 'ajax_get_attachment_details' ) );
    }

    
    
    /**
	 * Edit Image Meta In View Image
	 * @since 1.0.0
	 */
	function attachment_submitbox_metadata( ) {
        global $acoofmItem;
        $post          = get_post();
	    $attachment_id = $post->ID;

        if(!acoofm_is_service_enabled()){
            return ;
        }

        if(!$acoofmItem->is_available_from_provider($attachment_id)) {
            return;
        }
        
        $provider = $acoofmItem->get_provider($attachment_id);
        if($provider) { ?>
            <div class="misc-pub-section misc-pub-provider">
                <?php _e( 'Provider :' ); ?> <strong><?php echo esc_textarea(!empty($provider['label']) ? $provider['label'] : $provider['slug']); ?></strong></a>
            </div>
        <?php
        }

        $region = $acoofmItem->get_region($attachment_id);
        if($region) { ?>
            <div class="misc-pub-section misc-pub-provider">
                <?php _e( 'Region :' ); ?> <strong><?php echo esc_textarea($region); ?></strong></a>
            </div>
        <?php
        }
        $private = $acoofmItem->is_private($attachment_id);
        ?>
            <div class="misc-pub-section misc-pub-provider">
                <?php _e( 'Access :' ); ?> <strong><?php $private ? _e( 'Private' ) : _e( 'Public' ); ?></strong></a>
            </div>
        <?php
	}


    /**
     * Function to get attachment details by ID
     */
    public function ajax_get_attachment_details() {
        $result=array(
            'status'    => false,
            'data'      => array(),
            'exclude'   => false
        );
        if ( ! isset( $_POST['id'] ) ) {
            wp_send_json_success( $result );
		}

		check_ajax_referer( 'get_media_provider_details', '_nonce' );

		$id= intval( sanitize_text_field( $_POST['id'] ) );
        global $acoofmItem;

        // Return if extension not allowed
        $proceed = true;
        $path = get_attached_file( $id );
        if(!acoofm_is_extension_available($path)) {
            $proceed = false;
            $result['exclude'] = true;
            wp_send_json_success( $result );
        }

        if (!(
            acoofm_is_service_enabled() &&
            acoofm_is_rewrite_url() &&
            $acoofmItem->is_available_from_provider($id)
        )) {
            wp_send_json_success( $result );
        }

        $provider = $acoofmItem->get_provider($id);
        if($provider){
            $item['provider'] = $provider;
        }
        $region = $acoofmItem->get_region($id);
        if($region){
            $item['region'] = $region;
        }
        $item['private'] = $acoofmItem->is_private($id);
        if($item) {
            $result= array(
                'status' => true,
                'data' => $item
            );
        }
        
        wp_send_json_success( $result );
    }



    /**
     * Ensures only one instance of ACOOFMF_COMPATIBILITY is loaded or can be loaded.
     *
     * @param string $file Plugin root file path.
     * @return Main ACOOFMF_COMPATIBILITY instance
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

 
}
