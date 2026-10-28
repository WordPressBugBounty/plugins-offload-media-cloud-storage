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
        add_action('wp_ajax_acoofm_deactivation_feedback', array($this, 'acoofm_deactivation_feedback'));

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

        // Key files stored by earlier versions: web-accessible and guessable.
        if (get_option($this->token.'_key_files_version') !== '1') {
            acoofm_secure_key_files();
            update_option($this->token.'_key_files_version', '1', true);
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

                        $wpdb->update( $table_name, array( 'source_path' => $rel_path ), array( 'id' => (int) $file->id ) );
                    }
                }
                // 1.0.0 also needs the 1.0.1 upgrade
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
        acoofm_protect_key_file_dir();
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
            wp_enqueue_style($this->token . '-deactivation-message', esc_url($this->assets_url).'css/deactivate.css', array(), $this->version);
            wp_enqueue_script($this->token . '-deactivation-message', esc_url($this->assets_url).'js/deactivate.js', array('jquery'), $this->version, true);
            wp_localize_script($this->token . '-deactivation-message', 'acoofm_deactivation', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('acoofm_deactivation_feedback'),
                'plugin'   => plugin_basename(ACOOFM_FILE),
                'strings'  => array(
                    'reason'       => __('Please select a reason.', 'offload-media-cloud-storage'),
                    'details'      => __('Please tell us a little more.', 'offload-media-cloud-storage'),
                    'deactivating' => __('Deactivating…', 'offload-media-cloud-storage'),
                ),
            ));
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
     * Deactivation feedback dialog (Plugins screen); styles in css/deactivate.css.
     *
     * The submitted Reason values are the ones the feedback service has always
     * received, so its reports stay comparable; only the labels are translated.
     *
     * @since 1.0.2
     */
    public function acoofm_deactivation_form() {
        $currentScreen = get_current_screen();
        if (!$currentScreen || $currentScreen->id !== 'plugins') {
            return;
        }

        // Value sent => array(label, whether details are required).
        $reasons = array(
            "I'm only deactivating temporarily"           => array(__("I'm only deactivating temporarily", 'offload-media-cloud-storage'), false),
            'I no longer need the plugin'                 => array(__('I no longer need the plugin', 'offload-media-cloud-storage'), false),
            'I only needed the plugin for a short period' => array(__('I only needed the plugin for a short period', 'offload-media-cloud-storage'), false),
            'I found a better plugin'                     => array(__('I found a better plugin', 'offload-media-cloud-storage'), false),
            'Upgrading to PRO version'                    => array(__('Upgrading to the Pro version', 'offload-media-cloud-storage'), false),
            "Plugin doesn't meets my requirement"         => array(__("The plugin doesn't meet my requirements", 'offload-media-cloud-storage'), true),
            'Plugin broke my site'                        => array(__('The plugin broke my site', 'offload-media-cloud-storage'), true),
            'Plugin suddenly stopped working'             => array(__('The plugin suddenly stopped working', 'offload-media-cloud-storage'), true),
            'I found a bug'                               => array(__('I found a bug', 'offload-media-cloud-storage'), true),
            'Other'                                       => array(__('Other', 'offload-media-cloud-storage'), true),
        );
        $support_url = 'https://support.acowebs.com/portal/en/newticket?departmentId=361181000000006907&layoutId=361181000000074011';
        ?>
        <div id="acoofm-deactivate-modal" class="acoofm-dm" hidden>
            <div class="acoofm-dm__backdrop" data-acoofm-dm-close></div>
            <div class="acoofm-dm__dialog" role="dialog" aria-modal="true" aria-labelledby="acoofm-dm-title" aria-describedby="acoofm-dm-desc">
                <form class="acoofm-dm__form" novalidate>
                    <div class="acoofm-dm__header">
                        <div>
                            <h2 id="acoofm-dm-title" class="acoofm-dm__title"><?php esc_html_e('Before you deactivate', 'offload-media-cloud-storage'); ?></h2>
                            <p id="acoofm-dm-desc" class="acoofm-dm__desc"><?php esc_html_e('If you have a moment, please let us know why you are deactivating Offload Media. Your feedback is sent with your site address and name, and we only use it to improve the plugin.', 'offload-media-cloud-storage'); ?></p>
                        </div>
                        <button type="button" class="acoofm-dm__close" data-acoofm-dm-close aria-label="<?php esc_attr_e('Close', 'offload-media-cloud-storage'); ?>">
                            <svg viewBox="0 0 20 20" width="20" height="20" aria-hidden="true" focusable="false"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                        </button>
                    </div>

                    <div class="acoofm-dm__body">
                        <input name="Plugin" type="hidden" value="<?php echo esc_attr(ACOOFM_TOKEN); ?>">
                        <input name="Date" type="hidden" value="<?php echo esc_attr(gmdate('m/d/Y')); ?>">
                        <input name="Website" type="hidden" value="<?php echo esc_attr(get_site_url()); ?>">
                        <input name="Title" type="hidden" value="<?php echo esc_attr(get_bloginfo('name')); ?>">
                        <input name="Version" type="hidden" value="<?php echo esc_attr(ACOOFM_VERSION); ?>">

                        <fieldset class="acoofm-dm__reasons">
                            <legend class="screen-reader-text"><?php esc_html_e('Reason for deactivating', 'offload-media-cloud-storage'); ?></legend>
                            <?php foreach ($reasons as $value => $reason) : ?>
                                <label class="acoofm-dm__reason">
                                    <input type="radio" name="Reason" value="<?php echo esc_attr($value); ?>"<?php echo $reason[1] ? ' data-details' : ''; ?>>
                                    <span><?php echo esc_html($reason[0]); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>

                        <div class="acoofm-dm__details" hidden>
                            <label for="acoofm-dm-comments" class="acoofm-dm__details-label"><?php esc_html_e('Please tell us a little more', 'offload-media-cloud-storage'); ?></label>
                            <textarea id="acoofm-dm-comments" name="Comments" rows="3" disabled></textarea>
                            <p class="acoofm-dm__support">
                                <?php esc_html_e('Need help instead?', 'offload-media-cloud-storage'); ?>
                                <a href="<?php echo esc_url($support_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open a support ticket', 'offload-media-cloud-storage'); ?></a>
                            </p>
                        </div>

                        <p class="acoofm-dm__error" role="alert"></p>
                    </div>

                    <div class="acoofm-dm__footer">
                        <a href="#" class="acoofm-dm__skip" data-acoofm-dm-skip><?php esc_html_e('Skip & Deactivate', 'offload-media-cloud-storage'); ?></a>
                        <div class="acoofm-dm__actions">
                            <button type="button" class="acoofm-dm__btn acoofm-dm__btn--secondary" data-acoofm-dm-close><?php esc_html_e('Cancel', 'offload-media-cloud-storage'); ?></button>
                            <button type="submit" class="acoofm-dm__btn acoofm-dm__btn--primary"><?php esc_html_e('Submit & Deactivate', 'offload-media-cloud-storage'); ?></button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    <?php }


    /**
     * Send the deactivation survey from the server instead of loading a
     * remote script (JSONP) in wp-admin.
     *
     * @return void
     */
    public function acoofm_deactivation_feedback() {
        check_ajax_referer('acoofm_deactivation_feedback', 'nonce');
        if (!current_user_can('activate_plugins')) {
            wp_send_json_error(null, 403);
        }

        $fields = array();
        foreach (array('Plugin', 'Date', 'Website', 'Title', 'Version', 'Reason', 'Comments') as $key) {
            if (isset($_POST[$key])) {
                $fields[$key] = sanitize_textarea_field(wp_unslash($_POST[$key]));
            }
        }

        // Same query parameters the JSONP request sent; deactivation does not wait for it.
        wp_remote_get(add_query_arg(rawurlencode_deep($fields), 'https://feedback.acowebs.com/plugin.php'), array(
            'timeout'  => 5,
            'blocking' => false,
        ));

        wp_send_json_success();
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
