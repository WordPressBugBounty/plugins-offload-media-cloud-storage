<?php

/**
 * Load Backend related actions
 *
 * @class   ACOOFMF_Backend
 */

if (!defined('ABSPATH')) {
    exit;
}


class ACOOFMF_Backend
{


    /**
     * Class intance for singleton  class
     *
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
     * Suffix for Javascripts.
     *
     * @var     string
     * @access  public
     * @since   1.0.0
     */
    public $script_suffix;

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
    public function __construct($file = '')
    {
        $this->version = ACOOFM_VERSION;
        $this->token = ACOOFM_TOKEN;
        $this->file = $file;
        $this->dir = dirname($this->file);
        $this->assets_dir = trailingslashit($this->dir) . 'assets';
        $this->assets_url = esc_url(trailingslashit(plugins_url('/assets/', $this->file)));
        $this->script_suffix = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? '' : '.min';
        $plugin = plugin_basename($this->file);

        // add action links to link to link list display on the plugins page.
        add_filter("plugin_action_links_$plugin", array($this, 'pluginActionLinks'));

        // reg activation hook.
        register_activation_hook($this->file, array($this, 'install'));
        // reg deactivation hook.
        register_deactivation_hook($this->file, array($this, 'deactivation'));

         // add our custom CSS classes to <body>
		add_filter( 'admin_body_class', array( $this, 'admin_body_class' ) );

        // Admin Init
        add_action('admin_init', array($this, 'adminInit'));

        // reg admin menu.
        add_action('admin_menu', array($this, 'registerRootPage'), 30);

        // enqueue scripts & styles.
        add_action('admin_enqueue_scripts', array($this, 'adminEnqueueScripts'), 10, 1);
        add_action('admin_enqueue_scripts', array($this, 'adminEnqueueStyles'), 10, 1);

        // Plugin Deactivation Survey
        add_action('admin_footer', array($this, 'acoofm_deactivation_form'));

        // Load media scripts
        add_action( 'load-upload.php', array( $this, 'load_media_assets' ), 11 );
    }


    /**
     * Method that is used on plugin initialization time
     * @since 1.0.0
     */
    public function adminInit() {
        if(get_option( $this->token.'_db_version' ) !== ACOOFM_DB_VERSION) {
            $this->do_database_upgrade();
        }

        if (get_option($this->token.'_do_activation_redirect', false) && !acoofm_is_service_enabled()) {
            delete_option($this->token.'_do_activation_redirect');
            wp_redirect(admin_url('admin.php?page=' . $this->token . '-admin-ui#/configure'));
        } else {
            delete_option($this->token.'_do_activation_redirect');
        }
    }

    /**
     * Database Upgrade 
     * @since 1.0.1
     */
    private function do_database_upgrade() {
        global $wpdb;
        $table_name         = ACOOFM_ITEM_TABLE;
        $current_version    = get_option( $this->token.'_db_version' );

        switch($current_version) {
            case '1.0.0':
                $wpdb->query( "ALTER TABLE $table_name DROP INDEX uidx_source_url;");
                $wpdb->query( "ALTER TABLE $table_name DROP source_url;");
                
                $files = $wpdb->get_results( "SELECT * FROM $table_name;");
                if($files) {
                    foreach($files as $file) {
                        $rel_path   = acoofm_get_attachment_relative_path($file->source_path);

                        // Do multiple iteration to correct url
                        for($i = 0; $i<4; $i++) {
                            $rel_path   = acoofm_get_attachment_relative_path($rel_path);
                        }

                        $id         = $file->id;
                        $wpdb->query( "UPDATE $table_name SET source_path = '$rel_path' WHERE id = $id;");
                    }
                }
                break;
            case '1.0.1':
                $wpdb->query( "ALTER TABLE $table_name ADD UNIQUE uidx_source_id (source_id, id);");
                break;
            default: null;
        }

        update_option( $this->token.'_db_version', ACOOFM_DB_VERSION, true );
    }
    
    /**
     * Ensures only one instance of Class is loaded or can be loaded.
     *
     * @param string $file plugin start file path.
     * @return Main Class instance
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
     * Show action links on the plugin screen.
     *
     * @param mixed $links Plugin Action links.
     *
     * @return array
     */
    public function pluginActionLinks($links)
    {
        $action_links = array(
            'getstarted' => '<a href="' . admin_url('admin.php?page=' . $this->token . '-admin-ui#/configure') . '">' . esc_html__('Get Started', 'offload-media-cloud-storage') . '</a>',
            'settings' => '<a href="' . admin_url('admin.php?page=' . $this->token . '-admin-ui/') . '">' . esc_html__('Settings', 'offload-media-cloud-storage') . '</a>',
        );

        return array_merge($action_links, $links);
    }


    /**
     * Installation. Runs on activation.
     *
     * @access  public
     * @return  void
     * @since   1.0.0
     */
    public function install()
    {
        global $wpdb;

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

        $table_name = ACOOFM_ITEM_TABLE;
        $queries = array();
        $charset_collate = $wpdb->get_charset_collate();
        if($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $queries[] = "
                CREATE TABLE {$table_name} (
                id BIGINT(20) NOT NULL AUTO_INCREMENT,
                provider varchar(18) NOT NULL,
                region varchar(255) NOT NULL,
                bucket varchar(255) NOT NULL,
                source_id bigint(20) NOT NULL,
                source_path varchar(1024) NOT NULL,
                source_type varchar(18) NOT NULL,
                url varchar(1024) NOT NULL,
                path varchar(1024) NOT NULL,
                is_private tinyint(1) NOT NULL DEFAULT 0,
                extra_info longtext DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uidx_url (url(190), id),
                UNIQUE KEY uidx_path (path(190), id),
                UNIQUE KEY uidx_source_path (source_path(190), id),
                UNIQUE KEY uidx_source_id (source_id, id)
            ) $charset_collate;
            ";
        } 

        dbDelta( $queries );

        // Redirection on activation
        add_option($this->token.'_do_activation_redirect', true);

        //Protect directories
        $this->_protect_upload_dir();
    }

    /**
     * Protect Directory from external access.
     *
     * @access  private
     * @return  void
     * @since   1.0.0
     */
    private function _protect_upload_dir(){
        $upload_dir = wp_upload_dir();

        $files = array(
            array(
                'base' => $upload_dir['basedir'] . '/' . ACOOFM_UPLOADS,
                'file' => '.htaccess',
                'content' => 'Options -Indexes' . "\n"
                    . '<Files *.php>' . "\n"
                    . 'deny from all' . "\n"
                    . '</Files>'
            ),
            array(
                'base' => $upload_dir['basedir'] . '/' . ACOOFM_UPLOADS,
                'file' => 'index.php',
                'content' => '<?php ' . "\n"
                    . '// Silence is golden.'
            )
        );

        foreach ($files as $file) {


            if ((wp_mkdir_p($file['base'])) && (!file_exists(trailingslashit($file['base']) . $file['file']))  // If file not exist
            ) {
                if ($file_handle = @fopen(trailingslashit($file['base']) . $file['file'], 'w')) {
                    fwrite($file_handle, $file['content']);
                    fclose($file_handle);
                }
            }
        }
    }


   
    /**
     * Creating admin pages
     */
    public function registerRootPage()
    {
        $this->hook_suffix[] = add_menu_page( 
            __('Offload Media', 'offload-media-cloud-storage'),
            __('Offload Media', 'offload-media-cloud-storage'),
            'manage_options', 
            $this->token . '-admin-ui', 
            array($this, 'adminUi'), 
            esc_url($this->assets_url) . '/images/acoofm-logo.svg', 25
        );
        $this->hook_suffix[] = add_submenu_page(
            $this->token . '-admin-ui', 
            __('Dashboard', 'offload-media-cloud-storage'), 
            __('Dashboard', 'offload-media-cloud-storage'), 
            'manage_options', 
            $this->token . '-admin-ui' ,
            array($this, 'adminUi')
        );

        if(acoofm_is_service_enabled()) {
            $this->hook_suffix[] = add_submenu_page(
                $this->token . '-admin-ui', 
                __('Settings', 'offload-media-cloud-storage'), 
                __('Settings', 'offload-media-cloud-storage'), 
                'manage_options', 
                $this->token . '-admin-ui#/settings' ,
                array($this, 'adminUi')
            );
        }

        $this->hook_suffix[] = add_submenu_page(
            $this->token . '-admin-ui', 
            __('Configure', 'offload-media-cloud-storage'), 
            __('Configure', 'offload-media-cloud-storage'), 
            'manage_options', 
            $this->token . '-admin-ui#/configure' ,
            array($this, 'adminUi')
        );
        // $this->hook_suffix[] = add_submenu_page(
        //     $this->token . '-admin-ui', 
        //     __('License', 'offload-media-cloud-storage'), 
        //     __('License', 'offload-media-cloud-storage'), 
        //     'manage_options', 
        //     $this->token . '-admin-ui#/license' ,
        //     array($this, 'adminUi')
        // );
        $this->hook_suffix[] = add_submenu_page(
            $this->token . '-admin-ui', 
            __('Documentation', 'offload-media-cloud-storage'), 
            __('Documentation', 'offload-media-cloud-storage'), 
            'manage_options', 
            'https://acowebs.com/guideline/plugin-docs-faqs/wordpress-offload-media'
        );
    }

    /**
     * Calling view function for admin page components
     */
    public function adminUi()
    {

        echo (
            '<div id="' . $this->token . '_ui_root">
            <div class="' . $this->token . '_loader"><h1>' . __('Offload Media - Cloud Storage', 'offload-media-cloud-storage') . '</h1><p>' . __('Plugin is loading Please wait for a while..', 'offload-media-cloud-storage') . '</p></div>
            </div>'
        );
    }

    /**
     * Load admin CSS.
     *
     * @access  public
     * @return  void
     * @since   1.0.0
     */
    public function adminEnqueueStyles()
    {
        // If pro version installed
        if(acoofm_is_pro_active()) return;
        
        wp_enqueue_style( 'wpb-google-fonts-dm-sans', 'https://fonts.googleapis.com/css2?family=DM+Sans:ital,wght@0,400;0,500;0,700;1,400;1,500;1,700', false );
        wp_enqueue_style( 'wpb-google-fonts-roboto', 'https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700', false );
        wp_register_style($this->token . '-admin', esc_url($this->assets_url) . 'css/backend.css', array(), $this->version);
        wp_enqueue_style($this->token . '-admin');
    }

    /**
     * Load admin Javascript.
     *
     * @access  public
     * @return  void
     * @since   1.0.0
     */
    public function adminEnqueueScripts()
    {
        if (!isset($this->hook_suffix) || empty($this->hook_suffix)) {
            return;
        }

        $screen = get_current_screen();

        if($screen->id == 'plugins') {
            wp_enqueue_script($this->token . '-deactivation-message', esc_url($this->assets_url).'js/deactivate.js', array('jquery'), $this->version, true);
        }

        if (in_array($screen->id, $this->hook_suffix, true)) {

            if (!wp_script_is('wp-i18n', 'registered')) {
                wp_register_script('wp-i18n', esc_url($this->assets_url) . 'js/i18n.min.js', array(), $this->version, true);
            }

            // Enqueue WordPress media scripts.
            if (!did_action('wp_enqueue_media')) {
                wp_enqueue_media();
            }

            // Skip if pro active
            if(acoofm_is_pro_active()) return;

            // Enqueue custom backend script.
            wp_enqueue_script($this->token . '-backend', esc_url($this->assets_url) . 'js/backend.js', array('wp-i18n'), $this->version, true);

            // Localize a script.
            wp_localize_script(
                $this->token . '-backend',
                $this->token . '_object',
                array(
                    'api_nonce' => wp_create_nonce('wp_rest'),
                    'root' => rest_url($this->token . '/v1/'),
                    'assets_url' => $this->assets_url,
                )
            );
        }
    }

    /**
	 * Load the media Pro assets
	 */
	public function load_media_assets() {
        // Skip if pro active
        if(acoofm_is_pro_active()) return;


        /** CSS */
        wp_enqueue_style($this->token . '-media', esc_url($this->assets_url) . 'css/media.css', array(), $this->version);
        
    
        /** CSS */
        wp_enqueue_style($this->token . '-media', esc_url($this->assets_url) . 'css/media.css', array(), $this->version);
        
        /** JS */
        wp_enqueue_script(
            $this->token . '-media', 
            esc_url($this->assets_url) . 'js/media.js', 
            array(
                'jquery',
                'media-views',
                'media-grid',
                'wp-util'
            ),
            $this->version, 
            true
        );

        // Localize a script.
        wp_localize_script(
            $this->token . '-media',
            $this->token . '_media_object',
            array(
                'file_details_nonce'    => wp_create_nonce('get_media_provider_details'),
                'admin_ajax_url'        => admin_url('admin-ajax.php'),
                'strings'               => array(
                    'provider'          => __("Provider: ", "offload-media-cloud-storage"),
                    'region'            => __("Region: ", "offload-media-cloud-storage"),
                    'access'            => __("Access: ", "offload-media-cloud-storage"),
                    'access_private'    => __("Private", "offload-media-cloud-storage"),
                    'access_public'     => __("Public", "offload-media-cloud-storage"),
                )      
            )
        );
    }


    /**
	 * Add custom classes to the HTML body tag
	 *
	 * @param string $classes
	 *
	 * @return string
	 */
	public function admin_body_class( $classes ) {
		if ( ! $classes ) {
			$classes = array();
		} else {
			$classes = explode( ' ', $classes );
		}

		$classes[] = $this->token.'_page';

		/**
         *  Recommended way to target WP 3.8+
         *  http://make.wordpress.org/ui/2013/11/19/targeting-the-new-dashboard-design-in-a-post-mp6-world/
         * 
         */
		if ( version_compare( $GLOBALS['wp_version'], '3.8-alpha', '>' ) ) {
			if ( ! in_array( 'mp6', $classes ) ) {
				$classes[] = 'mp6';
			}
		}

		return implode( ' ', $classes );
	}


    /**
     * Deactivation hook
     */
    public function deactivation()
    {
    }


    /**
     * Deactivation form
     * @since 1.0.2
     * 
     */
    public function acoofm_deactivation_form() {
        $currentScreen = get_current_screen();
        $screenID = $currentScreen->id;
        if ( $screenID == 'plugins' ) {
            $view = '<div id="acoofm-aco-survey-form-wrap"><div id="acoofm-aco-survey-form">
            <p>If you have a moment, please let us know why you are deactivating this plugin. All submissions are anonymous and we only use this feedback for improving our plugin.</p>
            <form method="POST">
                <input name="Plugin" type="hidden" placeholder="Plugin" value="'.ACOOFM_TOKEN.'" required>
                <input name="Date" type="hidden" placeholder="Date" value="'.date("m/d/Y").'" required>
                <input name="Website" type="hidden" placeholder="Website" value="'.get_site_url().'" required>
                <input name="Title" type="hidden" placeholder="Title" value="'.get_bloginfo( 'name' ).'" required>
                <input name="Version" type="hidden" placeholder="Version" value="'.ACOOFM_VERSION.'" required>
                <input type="radio" id="acoofm-temporarily" name="Reason" value="I\'m only deactivating temporarily">
                <label for="acoofm-temporarily">I\'m only deactivating temporarily</label><br>
                <input type="radio" id="acoofm-notneeded" name="Reason" value="I no longer need the plugin">
                <label for="acoofm-notneeded">I no longer need the plugin</label><br>
                <input type="radio" id="acoofm-short" name="Reason" value="I only needed the plugin for a short period">
                <label for="acoofm-short">I only needed the plugin for a short period</label><br>
                <input type="radio" id="acoofm-better" name="Reason" value="I found a better plugin">
                <label for="acoofm-better">I found a better plugin</label><br>
                <input type="radio" id="acoofm-upgrade" name="Reason" value="Upgrading to PRO version">
                <label for="acoofm-upgrade">Upgrading to PRO version</label><br>
                <input type="radio" id="acoofm-requirement" name="Reason" value="Plugin doesn\'t meets my requirement">
                <label for="acoofm-requirement">Plugin doesn\'t meets my requirement</label><br>
                <input type="radio" id="acoofm-broke" name="Reason" value="Plugin broke my site">
                <label for="acoofm-broke">Plugin broke my site</label><br>
                <input type="radio" id="acoofm-stopped" name="Reason" value="Plugin suddenly stopped working">
                <label for="acoofm-stopped">Plugin suddenly stopped working</label><br>
                <input type="radio" id="acoofm-bug" name="Reason" value="I found a bug">
                <label for="acoofm-bug">I found a bug</label><br>
                <input type="radio" id="acoofm-other" name="Reason" value="Other">
                <label for="acoofm-other">Other</label><br>
                <p id="acoofm-aco-error"></p>
                <div class="acoofm-aco-comments" style="display:none;">
                    <textarea type="text" name="Comments" placeholder="Please specify" rows="2"></textarea>
                    <p>For support queries <a href="https://support.acowebs.com/portal/en/newticket?departmentId=361181000000006907&layoutId=361181000000074011" target="_blank">Submit Ticket</a></p>
                </div>
                <button type="submit" class="aco_button" id="acoofm-aco_deactivate">Submit & Deactivate</button>
                <a href="#" class="aco_button" id="acoofm-aco_cancel">Cancel</a>
                <a href="#" class="aco_button" id="acoofm-aco_skip">Skip & Deactivate</a>
            </form></div></div>';
            echo $view;
        } ?>
        <style>
            #acoofm-aco-survey-form-wrap{ display: none;position: absolute;top: 0px;bottom: 0px;left: 0px;right: 0px;z-index: 10000;background: rgb(0 0 0 / 63%); } #acoofm-aco-survey-form{ display:none;margin-top: 15px;position: fixed;text-align: left;width: 40%;max-width: 600px;min-width:350px;z-index: 100;top: 50%;left: 50%;transform: translate(-50%, -50%);background: rgba(255,255,255,1);padding: 35px;border-radius: 6px;border: 2px solid #fff;font-size: 14px;line-height: 24px;outline: none;}#acoofm-aco-survey-form p{font-size: 14px;line-height: 24px;padding-bottom:20px;margin: 0;} #acoofm-aco-survey-form .aco_button { margin: 25px 5px 10px 0px; height: 42px;border-radius: 6px;background-color: #1eb5ff;border: none;padding: 0 36px;color: #fff;outline: none;cursor: pointer;font-size: 15px;font-weight: 600;letter-spacing: 0.1px;color: #ffffff;margin-left: 0 !important;position: relative;display: inline-block;text-decoration: none;line-height: 42px;} #acoofm-aco-survey-form .aco_button#acoofm-aco_deactivate{background: #fff;border: solid 1px rgba(88,115,149,0.5);color: #a3b2c5;} #acoofm-aco-survey-form .aco_button#acoofm-aco_skip{background: #fff;border: none;color: #a3b2c5;padding: 0px 15px;float:right;}#acoofm-aco-survey-form .acoofm-aco-comments{position: relative;}#acoofm-aco-survey-form .acoofm-aco-comments p{ position: absolute; top: -24px; right: 0px; font-size: 14px; padding: 0px; margin: 0px;} #acoofm-aco-survey-form .acoofm-aco-comments p a{text-decoration:none;}#acoofm-aco-survey-form .acoofm-aco-comments textarea{background: #fff;border: solid 1px rgba(88,115,149,0.5);width: 100%;line-height: 30px;resize:none;margin: 10px 0 0 0;} #acoofm-aco-survey-form p#acoofm-aco-error{margin-top: 10px;padding: 0px;font-size: 13px;color: #ea6464;}
       </style>
    <?php }


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
