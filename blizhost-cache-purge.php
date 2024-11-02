<?php
/*
Plugin Name: Blizhost CloudCache Purge – Speed, Security, and Optimization
Plugin URI: https://www.blizhost.com
Description: Automatic Cache Clearing and CloudCache Integration to Boost Speed and Protect Your Site with Enhanced Security.
Version: 5.0.0
Author: Blizhost
Author URI: https://www.blizhost.com
License: GPL v3
Text Domain: blizhost-cache-purge
Network: true
Domain Path: /lang/

Copyright 2017-2024: Blizhost, Mika A. Epstein - based on version 4.0.2

*/

/**
 * Blizhost CloudCache Purger Class
 *
 * @since 2.0
 */
class BlizCloudCachePurger {
	protected $purgeUrls = array();			// URLs to be purged
	protected $processedPosts = array();	// Posts that have been processed
	public $p_version = '5.0.0';			// Plugin version
	public $do_full_purge = false;			// Flag to trigger a full cache purge
	
	protected $site_host;
	protected $is_ssl;

	/**
	 * Constructor: Initializes the plugin
	 *
	 * @since 2.0
	 * @access public
	 */
	public function __construct() {

		// Gets the hostname, more reliable than obtaining the server name through headers, compatible with PHP via CLI
		$this->server_hostname = null !== gethostname() ? sanitize_text_field( wp_unslash( gethostname() ) ) : '';

		// Gets the PHP SAPI (Server API), compatible with PHP via CLI and other interfaces
		$this->server_sapi = sanitize_text_field( wp_unslash( PHP_SAPI ) );

		// Store the site's host without 'www.' prefix
		$site_url = wp_parse_url( home_url() );
		$this->site_host = isset( $site_url['host'] ) ? strtolower( $site_url['host'] ) : '';
		$this->site_host = preg_replace( '/^www\./', '', $this->site_host );

		// Store SSL status
		$this->is_ssl = is_ssl();

		// Add custom header to indicate the plugin is enabled
		add_action( 'send_headers', array( $this, 'add_blizhost_plugin_header' ) );

		// Enqueue styles in the admin area
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_blizhost_admin_style' ) );

		// Enqueue styles when the admin bar is initialized on the front-end
		add_action( 'admin_bar_init', array( $this, 'enqueue_blizhost_admin_bar_style' ) );

		add_filter( 'admin_init', array( $this, 'blizhost_donotcache' ) );
		add_filter( 'template_include', array( $this, 'donotcache_filter' ) );

		// Handle special POST requests for generating hash and CDN domain
		add_action( 'init', array( $this, 'handle_generate_hash' ) );
		add_action( 'init', array( $this, 'handle_generate_cdn_domain' ) );

		// Remove WordPress version from header for security reasons
		remove_action( 'wp_head', 'wp_generator' );

		// Remove WordPress version from RSS feeds for security reasons
		add_filter( 'the_generator', '__return_empty_string' );

		// Remove Slider Revolution version for security reasons
		add_filter( 'revslider_meta_generator', '__return_empty_string' );

		// Hash versions from scripts and styles for security reasons
		add_filter( 'style_loader_src', array( $this, 'remove_ver_scripts_styles' ), 9999 );
		add_filter( 'script_loader_src', array( $this, 'remove_ver_scripts_styles' ), 9999 );

		// Initialize the plugin
		add_action( 'init', array( $this, 'init' ) );
		// Add cache purge information to the dashboard
		add_action( 'activity_box_end', array( $this, 'blizccache_rightnow' ), 100 );

		// Notify if Jetpack is needed for WordPress Image CDN feature
		add_action( 'admin_notices', array( $this, 'needJetpackMessage' ) );
		// Dismiss Jetpack notice
		add_action( 'admin_init', array( $this, 'needJetpackMessage_dismissed' ) );
		// Initialize the CDN image URL replacement
		add_action( 'init', array( $this, 'bliz_cdn_imageurl' ) );

		// Support for CloudFlare Flexible SSL - since 3.1
		$HttpsServerOpts = array( 'HTTP_CF_VISITOR', 'HTTP_X_FORWARDED_PROTO' );
		foreach( $HttpsServerOpts as $sOpt ) {
			if ( isset( $_SERVER[ $sOpt ] ) ) {
				$server_opt = sanitize_text_field( wp_unslash( $_SERVER[ $sOpt ] ) );
				if ( strpos( $server_opt, 'https' ) !== false ) {
					$_SERVER['HTTPS'] = 'on';
					break;
				}
			}
		}
		// Ensure the plugin loads first in the admin area
		if ( is_admin() ) {
			add_action( 'admin_init', array( $this, 'keepsPluginAtLoadPosition' ) );
		}

		// Enqueue scripts for AJAX functionality
		if ( is_admin() ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_bliz_purge_scripts' ) );
		} else {
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_bliz_purge_scripts' ) );
		}

		// AJAX action handler for cache purging
		add_action( 'wp_ajax_bliz_purge_all', array( $this, 'bliz_purge_all_ajax_handler' ) );
	}

	/**
	* Add custom HTTP header to responses
	*/
	public function add_blizhost_plugin_header() {
		header( 'X-Blizhost-Plugin: Enabled' );
	}

	/**
	* Enqueue the Blizhost logo font style in the admin area
	*/
	public function enqueue_blizhost_admin_style() {
		// Enqueue the style in the admin area
		wp_enqueue_style( 'blizhost_logo_adminbar_css', plugin_dir_url( __FILE__ ) . 'font/style.css', array(), '1.5', 'all' );
	}

	/**
	* Enqueue the Blizhost logo font style on the front-end when the admin bar is shown
	*/
	public function enqueue_blizhost_admin_bar_style() {
		if ( is_admin_bar_showing() && ! is_admin() ) {
			// Enqueue the style on the front-end when the admin bar is visible
			wp_enqueue_style( 'blizhost_logo_adminbar_css', plugin_dir_url( __FILE__ ) . 'font/style.css', array(), '1.5', 'all' );
		}
	}

	/**
	* Remove version query strings from scripts and styles for security reasons
	*
	* @param string $src The source URL of the enqueued script or style.
	* @return string The modified source URL.
	*/
	public function remove_ver_scripts_styles( $src ) {
		if ( strpos( $src, $this->site_host ) === false ) {
			return $src;
		} elseif ( strpos( $src, 'ver=' ) !== false ) {
			preg_match( '~ver=([0-9.\-_]+)~', $src, $get_ver );
			if ( isset( $get_ver[1] ) ) {
				$hash_ver = preg_replace( '/\d+/u', '', md5( $get_ver[1] ) );
				$hash_ver = substr( $hash_ver, 0, 5 );
				$src = str_replace( 'ver=' . $get_ver[1], 'ver=' . $hash_ver, $src );
			}
		}
		return $src;
	}

	/**
	 * Plugin initialization
	 *
	 * @since 1.0
	 * @access public
	 */
	public function init() {
		global $blog_id;

		// Load plugin textdomain for translations
		load_plugin_textdomain( 'blizhost-cache-purge', false, dirname( plugin_basename( __FILE__ ) ) . '/lang' );

		// Warn if Pretty Permalinks are not enabled
		if ( '' == get_option( 'permalink_structure' ) && current_user_can( 'manage_options' ) ) {
			add_action( 'admin_notices', array( $this, 'blizprettyPermalinksMessage' ) );
			return;
		}

		// Handle the fallback URL
		if ( isset( $_GET['bliz_purge_cache'] ) ) {
			if ( check_admin_referer( 'bliz_purge_cache_action' ) ) {
				add_action( 'admin_notices', array( $this, 'blizpurgeMessage' ) );
				$this->handle_fallback_purge_cache_request();
			}
		}

		// Get registered events that trigger cache purging
		$events	 = $this->blizgetRegisterEvents();
		$noIDevents = $this->getNoIDEvents();

		// Ensure events are arrays
		if ( ! empty( $events ) && ! empty( $noIDevents ) ) {

			// Convert to arrays if necessary
			$events	 = (array) $events;
			$noIDevents = (array) $noIDevents;

			// Add the action for each event
			foreach ( $events as $event ) {
				if ( in_array( $event, $noIDevents ) ) {
					// Events that require a full cache purge
					add_action( $event, array( $this, 'purgeNoID' ) );
				} else {
					// Events that require purging specific posts
					add_action( $event, array( $this, 'blizpurgePost' ), 10, 2 );
				}
			}
		}

		// Add actions for events that trigger a full cache purge
		add_action( 'upgrader_process_complete', array( $this, 'purgeNoID' ), 10, 0 );		// After core/plugin/theme updates
		add_action( 'update_option_blogname', array( $this, 'purgeNoID' ), 10, 0 );			// When site title is updated
		add_action( 'update_option_blogdescription', array( $this, 'purgeNoID' ), 10, 0 );	// When site tagline is updated

		// Add action for when post status changes
		add_action( 'transition_post_status', array( $this, 'blizpurgePostStatus' ), 10, 3 );

		// Execute cache purge on shutdown
		add_action( 'shutdown', array( $this, 'blizexecutePurge' ) );

		// Check user permissions to add admin bar button
		if (
			// SingleSite - admins can always purge
			( ! is_multisite() && current_user_can( 'activate_plugins' ) ) ||
			// Multisite - Network Admin can always purge
			current_user_can( 'manage_network' ) ||
			// Multisite - Site admins can purge unless it's a subfolder install and we're on site #1
			( is_multisite() && current_user_can( 'activate_plugins' ) && ( SUBDOMAIN_INSTALL || ( ! SUBDOMAIN_INSTALL && ( BLOG_ID_CURRENT_SITE != $blog_id ) ) ) )
		) {
			// Add the cache purge button to the admin bar
			add_action( 'admin_bar_menu', array( $this, 'blizccache_rightnow_adminbar' ), 100 );
		}
	}

	/**
	 * Enqueue scripts for AJAX functionality
	 */
	public function enqueue_bliz_purge_scripts() {
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			if ( ! wp_script_is( 'bliz-purge-script', 'enqueued' ) ) {
				wp_enqueue_script( 'bliz-purge-script', plugin_dir_url( __FILE__ ) . 'js/bliz-purge.js', array( 'jquery' ), '1.6', true );
				wp_localize_script( 'bliz-purge-script', 'bliz_purge', array(
					'ajax_url'	   => admin_url( 'admin-ajax.php' ),
					'nonce'		  => wp_create_nonce( 'bliz_purge_all_nonce' ),
					'dismiss_notice' => __( 'Dismiss this notice.', 'blizhost-cache-purge' ),
					'error_message'  => __( 'An error occurred while purging the cache.', 'blizhost-cache-purge' ),
				) );
			}
		}
	}

	/**
	 * Set headers to prevent caching
	 *
	 * @since 3.6
	 */
	public function blizhost_donotcache() {
		// Do not cache in CloudCache if DONOTCACHEPAGE is true or if the request is for an administrative page
		if ( defined( 'DONOTCACHEPAGE' ) || defined( 'DOING_CRON' ) || is_admin() || ( isset( $GLOBALS['pagenow'] ) && $GLOBALS['pagenow'] === 'wp-login.php' ) ) {
			// Do not set header if headers have already been sent or if the request is AJAX
			if ( ! headers_sent() && ! defined( 'DOING_AJAX' ) && stripos( $this->server_hostname, 'blizhost' ) !== false ) {
				// Set header to bypass CloudCache if not home, front page, or single post
				if ( ! is_home() && ! is_front_page() && ! is_single() && isset( $_SERVER['REQUEST_URI'] ) && $_SERVER['REQUEST_URI'] != '/' ) {
					header( "BypassCloudCache: TRUE" );
					// Does not run in CLI, this breaks executions performed by cron
					if ( $this->server_sapi !== 'cli' && ! isset( $_SERVER['HTTP_X_BYPASSCCACHE'] ) ) {
						exit;
					}
				}
			}
		}
	}

	/**
	 * Set do not cache header via template_include
	 * This is the latest possible action prior to output
	 * Required to get DONOTCACHEPAGE in some cases
	 *
	 * @since 3.6
	 */
	public function donotcache_filter( $template ) {
		$this->blizhost_donotcache();
		return $template;
	}

	/**
	 * Display a success message after cache purging
	 *
	 * @since 2.0
	 */
	public function blizpurgeMessage() {
		echo "<div id='message' class='notice notice-success fade is-dismissible'><p><strong>";
		esc_html_e( 'All CloudCache has been purged!', 'blizhost-cache-purge' );
		echo "</strong></p></div>";
	}

	/**
	 * Display a message if Pretty Permalinks are not enabled
	 *
	 * @since 2.0
	 */
	public function blizprettyPermalinksMessage() {
		echo "<div id='message' class='error'><p>" . sprintf(
			wp_kses(
				/* translators: %1$s: URL of the permalinks options page */
				__( 'Blizhost CloudCache Purge requires you to use custom permalinks. Please go to the <a href="%1$s">Permalinks Options Page</a> to configure them.', 'blizhost-cache-purge' ),
				array( 'a' => array( 'href' => array() ) )
			),
			esc_url( admin_url( 'options-permalink.php' ) )
		) . "</p></div>";
	}

	/**
	 * Display a notice that Jetpack is required for the WordPress Image CDN feature
	 *
	 * @since 3.9.4
	 */
	public function needJetpackMessage() {
		if ( ! get_user_meta( get_current_user_id(), 'needJetpackMessage_dismissed' ) && ! class_exists( 'Jetpack' ) && stripos( $this->server_hostname, 'blizhost' ) === false ) {
			$dismiss_url = wp_nonce_url( add_query_arg( 'needjp', '0' ), 'bliz_dismiss_jetpack_notice' );
			$message = sprintf(
				/* translators: %1$s: URL to dismiss the notice */
				__( 'Blizhost CloudCache Purge requires the <a href="https://wordpress.org/plugins/jetpack/" target="_blank">Jetpack</a> plugin to use WordPress Image CDN. Please install and connect this plugin to automatically use this feature. <a href="%1$s">Do not show again.</a>', 'blizhost-cache-purge' ),
				esc_url( $dismiss_url )
			);

			$allowed_html = array(
				'a' => array(
					'href'   => array(),
					'target' => array(),
				),
			);

			echo '<div id="message" class="notice notice-warning"><p>' . wp_kses( $message, $allowed_html ) . '</p></div>';
		}
	}

	/**
	 * Save the dismissal of the Jetpack notice to prevent it from displaying again
	 */
	public function needJetpackMessage_dismissed() {
		if ( isset( $_GET['needjp'] ) && check_admin_referer( 'bliz_dismiss_jetpack_notice' ) ) {
			add_user_meta( get_current_user_id(), 'needJetpackMessage_dismissed', 'true', true );
		}
	}

	/**
	 * Get the home URL, allowing for domain mapping plugins
	 *
	 * This is for domain mapping plugins that do not filter on their own.
	 *
	 * @since 4.0
	 */
	static public function the_home_url() {
		$home_url = apply_filters( 'vhp_home_url', home_url() );
		return $home_url;
	}

	/**
	 * Add CloudCache Purge button in the admin bar
	 *
	 * @since 2.0
	 */
	public function blizccache_rightnow_adminbar( $admin_bar ) {
		$fallback_url = wp_nonce_url( add_query_arg( 'bliz_purge_cache', 1 ), 'bliz_purge_cache_action' );
		$admin_bar->add_menu( array(
			'id'	=> 'bliz-purge-ccache-cache',
			'title' => '<span class="ab-icon blizicon-symbol_16px" style="font-size: 18px; margin-top: 3px; font-family: \'blizhost_logo\'!important; speak: none; font-style: normal; font-weight: normal; font-variant: normal; text-transform: none; line-height: 1; -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale;"></span>' . __( 'Blizhost CloudCache', 'blizhost-cache-purge' ),
		) );

		// Determine CloudCache status
		$cloudcache_active = ( stripos( $this->server_hostname, 'blizhost' ) !== false );

		// Determine CDN status
		$cdn_active = ( stripos( $this->server_hostname, 'blizhost' ) !== false && ( ! defined( 'DISABLE_WP_CDN' ) || DISABLE_WP_CDN == false ) );

		// Define status dots with adjusted colors
		$green_dot = '<span style="color: #00ff3a; font-size: 16px; margin-left: 15px;">●</span>';
		$red_dot = '<span style="color: #e11e31; font-size: 16px; margin-left: 15px;">●</span>';

		// Assign status dots based on service status
		$cloudcache_status_dot = $cloudcache_active ? $green_dot : $red_dot;
		$cdn_status_dot = $cdn_active ? $green_dot : $red_dot;

		// After determining $cloudcache_active and $cdn_active
		// Add tooltips for inactive statuses with translation functions
		$cloudcache_tooltip = '';
		if ( ! $cloudcache_active ) {
			$cloudcache_tooltip = __( 'This feature is only available for Blizhost customers.', 'blizhost-cache-purge' );
		}

		$cdn_tooltip = '';
		if ( ! $cdn_active ) {
			$cdn_tooltip = __( 'This feature is only available for Blizhost customers.', 'blizhost-cache-purge' );
		}

		// Modify the $admin_bar->add_menu calls to include the 'meta' parameter
		// Add CloudCache Status menu item with dot after text
		$admin_bar->add_menu( array(
			'parent' => 'bliz-purge-ccache-cache',
			'id'	 => 'bliz-cloudcache-status',
			'title'  => '<span style="display: flex; align-items: center; width: auto;">' . __( 'CloudCache Status - Speed and Security', 'blizhost-cache-purge' ) . '<span style="margin-left: auto;">' . $cloudcache_status_dot . '</span></span>',
			'meta'   => array(
				'title' => $cloudcache_tooltip, // Add tooltip if inactive
			),
		) );

		// Add CDN Status menu item with dot after text
		$admin_bar->add_menu( array(
			'parent' => 'bliz-purge-ccache-cache',
			'id'	 => 'bliz-cdn-status',
			'title'  => '<span style="display: flex; align-items: center; width: auto;">' . __( 'CDN and Image Optimization Status', 'blizhost-cache-purge' ) . '<span style="margin-left: auto;">' . $cdn_status_dot . '</span></span>',
			'meta'   => array(
				'title' => $cdn_tooltip, // Add tooltip if inactive
			),
		) );

		// Existing Purge Entire Cache menu item with separator line above
		$admin_bar->add_menu( array(
			'parent' => 'bliz-purge-ccache-cache',
			'id'	 => 'bliz-purge-ccache-cache-all',
			'title'  => '<span style="display: flex; align-items: center; width: auto; border-top: 1px solid rgba(255, 255, 255, 0.4); margin-top: 10px;">' . __( 'Purge Entire Cache', 'blizhost-cache-purge' ) . '</span>',
			'href'   => $fallback_url, // Fallback URL
			'meta'   => array(
				'title' => __( 'Purge Entire Cache (All Pages)', 'blizhost-cache-purge' ),
				'class' => 'bliz-purge-all-cache', // Add class for JavaScript
			),
		) );
	}

	/**
	 * Display CloudCache information in the Right Now section of the dashboard
	 *
	 * @since 1.0
	 */
	public function blizccache_rightnow() {
		global $blog_id;
		$fallback_url = wp_nonce_url( add_query_arg( 'bliz_purge_cache', 1 ), 'bliz_purge_cache_action' );

		$intro = sprintf(
			/* translators: %1$s: URL of the Blizhost CloudCache Purge plugin */
			__( '<a href="%1$s" target="_blank">Blizhost CloudCache Purge</a> automatically clears the cache for Blizhost customers when a post is created or changed.', 'blizhost-cache-purge' ),
			esc_url( 'https://wordpress.org/plugins/blizhost-cache-purge/' )
		);

		$allowed_html = array(
			'a'	 => array(
				'href'   => array(),
				'target' => array(),
				'style' => array(),
			),
			'span'  => array(
				'class' => array(),
			),
			'strong' => array(),
			'p' => array(),
		);

		$intro = wp_kses( $intro, $allowed_html );

		$button  = esc_html__( 'Press the button below to force a cleanup of the entire cache:', 'blizhost-cache-purge' );
		$button .= '</p><p><span class="button bliz-purge-all-cache"><a href="' . $fallback_url . '" style="text-decoration:none;"><strong>';
		$button .= esc_html__( 'Purge CloudCache', 'blizhost-cache-purge' );
		$button .= '</strong></a></span>';

		$nobutton = esc_html__( 'You do not have permission to purge the cache for the whole site. Please contact your administrator.', 'blizhost-cache-purge' );

		if (
			( ! is_multisite() && current_user_can( 'activate_plugins' ) ) ||
			current_user_can( 'manage_network' ) ||
			( is_multisite() && ! current_user_can( 'manage_network' ) && ( SUBDOMAIN_INSTALL || ( ! SUBDOMAIN_INSTALL && ( BLOG_ID_CURRENT_SITE != $blog_id ) ) ) )
		) {
			$text = $intro . ' ' . $button;
		} else {
			$text = $intro . ' ' . $nobutton;
		}

		echo "<p class='ccache-rightnow'>" . wp_kses( $text, $allowed_html ) . "</p>\n";
	}

	// CloudFlare Flexible SSL functions - 1.2.2
	/**
	 * Ensure this plugin loads first among all plugins
	 */
	public function keepsPluginAtLoadPosition() {
		$sBaseFile	 = plugin_basename( __FILE__ );
		$nLoadPosition = $this->getAcPluginLoadPosition( $sBaseFile );
		if ( $nLoadPosition > 1 ) {
			$this->setAcPluginLoadPosition( $sBaseFile, 0 );
		}
	}

	/**
	 * Get the plugin's load position
	 *
	 * @param string $sPluginFile
	 * @return int
	 */
	public function getAcPluginLoadPosition( $sPluginFile ) {
		$sOptKey	= is_multisite() ? 'active_sitewide_plugins' : 'active_plugins';
		$aActive	= get_option( $sOptKey );
		$nPosition	= -1;
		if ( is_array( $aActive ) ) {
			$nPosition = array_search( $sPluginFile, $aActive );
			if ( $nPosition === false ) {
				$nPosition = -1;
			}
		}
		return $nPosition;
	}

	/**
	 * Set the plugin's load position
	 *
	 * @param string	$sPluginFile
	 * @param int		$nDesiredPosition
	 */
	public function setAcPluginLoadPosition( $sPluginFile, $nDesiredPosition = 0 ) {

		$aActive = $this->setValueToPosition( get_option( 'active_plugins' ), $sPluginFile, $nDesiredPosition );
		update_option( 'active_plugins', $aActive );

		if ( is_multisite() ) {
			$aActive = $this->setValueToPosition( get_option( 'active_sitewide_plugins' ), $sPluginFile, $nDesiredPosition );
			update_option( 'active_sitewide_plugins', $aActive );
		}
	}

	/**
	 * Set value to a specific position in an array
	 *
	 * @param array	$aSubjectArray
	 * @param mixed	$mValue
	 * @param int	$nDesiredPosition
	 * @return array
	 */
	public function setValueToPosition( $aSubjectArray, $mValue, $nDesiredPosition ) {

		if ( $nDesiredPosition < 0 || ! is_array( $aSubjectArray ) ) {
			return $aSubjectArray;
		}

		$nMaxPossiblePosition = count( $aSubjectArray ) - 1;
		if ( $nDesiredPosition > $nMaxPossiblePosition ) {
			$nDesiredPosition = $nMaxPossiblePosition;
		}

		$nPosition = array_search( $mValue, $aSubjectArray );
		if ( $nPosition !== false && $nPosition != $nDesiredPosition ) {

			// Remove existing and reset index
			unset( $aSubjectArray[ $nPosition ] );
			$aSubjectArray = array_values( $aSubjectArray );

			// Insert and update
			array_splice( $aSubjectArray, $nDesiredPosition, 0, $mValue );
		}

		return $aSubjectArray;
	}
	//

	// WordPress Image CDN functions
	/**
	 * Initializes the replacement of image URLs to use the CDN
	 */
	public function bliz_cdn_imageurl() {
		// Check if Jetpack/Photon is active or if we should exit
		if ( ( defined( 'DISABLE_WP_CDN' ) && DISABLE_WP_CDN === true ) ||
			( class_exists( 'Jetpack' ) && method_exists( 'Jetpack', 'get_active_modules' ) && in_array( 'photon', Jetpack::get_active_modules() ) ) ||
			stripos( $this->server_hostname, 'blizhost' ) === false ) {
			return;
		}

		// Avoid execution on admin pages or AJAX requests
		if ( defined( 'DOING_AJAX' ) || defined( 'DOING_CRON' ) || is_admin() ||
			( isset( $GLOBALS['pagenow'] ) && $GLOBALS['pagenow'] === 'wp-login.php' ) ) {
			return;
		}

		// Add filters at strategic points with high priority
		add_filter( 'wp_get_attachment_url', array( $this, 'bliz_cdn_get_attachment_url' ), 10, 2 );
		add_filter( 'the_content', array( $this, 'bliz_cdn_filter_content' ), PHP_INT_MAX );
		add_filter( 'post_thumbnail_html', array( $this, 'bliz_cdn_filter_html' ), PHP_INT_MAX );
		add_filter( 'get_avatar', array( $this, 'bliz_cdn_filter_html' ), PHP_INT_MAX );
		add_filter( 'widget_text', array( $this, 'bliz_cdn_filter_html' ), PHP_INT_MAX );
		add_filter( 'wp_calculate_image_srcset', array( $this, 'bliz_cdn_filter_srcset' ), 10, 5 );
		add_filter( 'wp_get_attachment_image', array( $this, 'bliz_cdn_filter_image_html' ), PHP_INT_MAX, 5 );
		add_filter( 'image_downsize', array( $this, 'bliz_cdn_image_downsize' ), PHP_INT_MAX, 3 );

		// Add resource hints for DNS prefetching
		add_filter( 'wp_resource_hints', array( $this, 'bliz_cdn_dns_prefetch' ), 10, 2 );
	}
	
	/**
	 * Filters the image HTML to replace image URLs with CDN URLs.
	 */
	public function bliz_cdn_filter_image_html( $html, $attachment_id, $size, $icon, $attr ) {
		// Use the existing content filter to replace image URLs within the HTML.
		return $this->bliz_cdn_filter_content( $html );
	}

	/**
	 * Filters the image data to replace image URLs with CDN URLs.
	 *
	 * This function is hooked into the 'image_downsize' filter. It attempts to
	 * replace the image URL with the CDN URL. If the image is not supported or
	 * cannot be processed, it returns false to allow WordPress to handle it normally.
	 *
	 * @param bool|array	$out The image data to return (false to let WordPress handle it).
	 * @param int			$id  Attachment ID for the image.
	 * @param string|array	$size Requested size. Image size or array of width and height values (in that order).
	 * @return bool|array	Image data array on success, false on failure.
	 */
	public function bliz_cdn_image_downsize( $out, $id, $size ) {
		// Retrieve the attachment metadata.
		$image_meta = wp_get_attachment_metadata( $id );

		if ( ! $image_meta || ! isset( $image_meta['file'] ) ) {
			// Cannot process the image; return false to let WordPress handle it.
			return false;
		}

		// Get the upload directory information.
		$upload_dir = wp_upload_dir();

		// Get the directory of the original image file.
		$original_file_dir = pathinfo( $image_meta['file'], PATHINFO_DIRNAME );
		if ( '.' === $original_file_dir ) {
			$original_file_dir = '';
		}

		if ( is_array( $size ) ) {
			// $size is an array of width and height.
			// Try to find the closest matching size.
			$data = image_get_intermediate_size( $id, $size );
			if ( $data ) {
				// Found an intermediate size.
				// Ensure that $data['file'] includes the correct path.
				if ( strpos( $data['file'], '/' ) !== false ) {
					$intermediate_file = $data['file'];
				} else {
					$intermediate_file = path_join( $original_file_dir, $data['file'] );
				}
				// Build the full URL to the image.
				$src = $upload_dir['baseurl'] . '/' . $intermediate_file;
				$width = $data['width'];
				$height = $data['height'];
				$is_intermediate = true;
			} else {
				// Use the full-size image as a fallback.
				$src = $upload_dir['baseurl'] . '/' . $image_meta['file'];
				$width = isset( $image_meta['width'] ) ? $image_meta['width'] : 0;
				$height = isset( $image_meta['height'] ) ? $image_meta['height'] : 0;
				$is_intermediate = false;
			}
		} else {
			// $size is a string (e.g., 'thumbnail', 'medium', 'large').
			if ( isset( $image_meta['sizes'][ $size ] ) ) {
				// The requested size exists in the metadata.
				$size_data = $image_meta['sizes'][ $size ];
				// Construct the file path for the intermediate image size.
				$intermediate_file = path_join( $original_file_dir, $size_data['file'] );
				// Build the full URL to the image.
				$src = $upload_dir['baseurl'] . '/' . $intermediate_file;
				$width = $size_data['width'];
				$height = $size_data['height'];
				$is_intermediate = true;
			} else {
				// Use the full-size image as a fallback.
				$src = $upload_dir['baseurl'] . '/' . $image_meta['file'];
				$width = isset( $image_meta['width'] ) ? $image_meta['width'] : 0;
				$height = isset( $image_meta['height'] ) ? $image_meta['height'] : 0;
				$is_intermediate = false;
			}
		}

		// Apply the CDN URL replacement.
		$new_src = $this->bliz_cdn_imageurl_replace( $src );

		// If the URL was not modified, return false to let WordPress handle the image.
		if ( $new_src === $src ) {
			return false;
		}

		// Return the modified image data array.
		return array( $new_src, $width, $height, $is_intermediate );
	}

	/**
	 * Replaces the attachment URL with the CDN URL
	 */
	public function bliz_cdn_get_attachment_url( $url, $post_id ) {
		return $this->bliz_cdn_imageurl_replace( $url );
	}

	/**
	 * Filters content to replace image URLs with CDN URLs using DOMDocument
	 */
	public function bliz_cdn_filter_content( $content ) {
		if ( empty( $content ) ) {
			return $content;
		}

		// Check if content contains any attributes we need to process
		$attributes_to_process = array(
			'src',
			'srcset',
			'data-src',
			'data-srcset',
			'data-lazyload',
			'data-src-rs-ref',
			'style',
		);

		$pattern = '/(' . implode( '|', array_map( 'preg_quote', $attributes_to_process ) ) . ')=/';
		if ( ! preg_match( $pattern, $content ) ) {
			// No relevant attributes found; no need to process
			return $content;
		}

		libxml_use_internal_errors( true );
		$doc = new DOMDocument();

		// Convert encoding to HTML-ENTITIES for proper parsing
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$content = mb_convert_encoding( $content, 'HTML-ENTITIES', 'UTF-8' );
		} else {
			return $content;
		}

		// Load the HTML content
		$doc->loadHTML( '<div>' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();

		// Use XPath to directly select elements with the attributes we're interested in
		$xpath = new DOMXPath( $doc );

		foreach ( $attributes_to_process as $attribute ) {
			// Select nodes with the specific attribute
			$nodes = $xpath->query( '//*[@' . $attribute . ']' );

			foreach ( $nodes as $node ) {
				$attr_value = $node->getAttribute( $attribute );

				// If the attribute is 'src' and 'data-lazyload' or 'data-src' is present, skip processing (compatibility with RevSlide)
				if ( 'src' === $attribute ) {
					if ( $node->hasAttribute( 'data-lazyload' ) || $node->hasAttribute( 'data-src' ) ) {
						continue;
					}
				}

				if ( 'style' === $attribute ) {
					// Handle background-image in style attribute
					$new_style = $this->bliz_cdn_replace_style_attribute( $attr_value );
					$node->setAttribute( $attribute, $new_style );
				} else {
					// Replace image URLs in the attribute
					$new_attr_value = $this->bliz_cdn_replace_attribute_value( $attr_value );
					$node->setAttribute( $attribute, $new_attr_value );
				}
			}
		}

		// Save the modified HTML content
		$newContent = $doc->saveHTML( $doc->documentElement );

		// Remove the added <div> wrapper
		if ( substr( $newContent, 0, 5 ) === '<div>' && substr( $newContent, -6 ) === '</div>' ) {
			$newContent = substr( $newContent, 5, -6 );
		}

		return $newContent;
	}
	
	/**
	 * Replaces image URLs within an attribute value.
	 */
	public function bliz_cdn_replace_attribute_value( $attr_value ) {
		// Split multiple URLs if necessary (e.g., in srcset or data-srcset)
		$urls = explode( ',', $attr_value );
		$new_urls = array();
	
		foreach ( $urls as $url_part ) {
			$url_part = trim( $url_part );
			// For srcset-like attributes, the URL may have a descriptor
			$parts = preg_split( '/\s+/', $url_part, 2 );
			$url = $parts[0];
			$descriptor = isset( $parts[1] ) ? $parts[1] : '';
	
			$new_url = $this->bliz_cdn_imageurl_replace( $url );
			$new_urls[] = trim( $new_url . ' ' . $descriptor );
		}
	
		return implode( ', ', $new_urls );
	}
	
	/**
	 * Replaces image URLs within a style attribute.
	 */
	public function bliz_cdn_replace_style_attribute( $style_value ) {
		// Use regex to find background-image URLs
		$pattern = '/background(?:-image)?\s*:\s*url\((\'|")?(.*?)\1?\)/i';
	
		$new_style = preg_replace_callback( $pattern, function( $matches ) {
			$url = $matches[2];
			$new_url = $this->bliz_cdn_imageurl_replace( $url );
			return str_replace( $url, $new_url, $matches[0] );
		}, $style_value );
	
		return $new_style;
	}

	/**
	 * Filters HTML to replace image URLs with CDN URLs using DOMDocument
	 */
	public function bliz_cdn_filter_html( $html ) {
		return $this->bliz_cdn_filter_content( $html );
	}

	/**
	 * Filters the srcset attribute to replace image URLs with CDN URLs
	 */
	public function bliz_cdn_replace_srcset_attribute( $srcset ) {
		$images = explode( ',', $srcset );
		$new_images = array();

		foreach ( $images as $image ) {
			$parts = preg_split( '/\s+/', trim( $image ), 2 );
			$url = $parts[0];
			$descriptor = isset( $parts[1] ) ? $parts[1] : '';

			// Use bliz_cdn_imageurl_replace
			$new_url = $this->bliz_cdn_imageurl_replace( $url );
			$new_images[] = trim( $new_url . ' ' . $descriptor );
		}

		return implode( ', ', $new_images );
	}

	/**
	 * Filters the srcset attribute to replace image URLs with CDN URLs
	 */
	public function bliz_cdn_filter_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		foreach ( $sources as $key => $source ) {
			$new_url = $this->bliz_cdn_imageurl_replace( $source['url'] );
			$sources[ $key ]['url'] = $new_url;
		}
		return $sources;
	}

	/**
	 * Replaces image URLs to use the CDN
	 */
	public function bliz_cdn_imageurl_replace( $url ) {
		// Store the original URL
		$original_url = $url;
		
		// Handle protocol-relative URLs
		if ( strpos( $url, '//' ) === 0 ) {
			$url = ( $this->is_ssl ? 'https:' : 'http:' ) . $url;
		}
		
		// Use get_option('home') to get the base URL, including subdirectory but excluding language paths and other paths customized by plugins
		$home_url_original = get_option( 'home' );

		// Handle relative URLs (starting with '/')
		if ( strpos( $url, '/' ) === 0 ) {
			// Prepend the home URL for processing
			$url = $home_url_original . $url;
		} elseif ( ! preg_match( '/^https?:\/\//i', $url ) ) {
			// Handle URLs without scheme but not starting with '/'
			$url = ( $this->is_ssl ? 'https://' : 'http://' ) . ltrim( $url, '/\\' );
		}

		// Parse the URL
		$parsed_url = wp_parse_url( $url );

		// Validate the image URL
		if ( ! $this->bliz_cdn_validate_image_url( $parsed_url ) ) {
			// Return the original URL without modification
			return $original_url;
		}

		// Ensure the host and path are set
		$host = isset( $parsed_url['host'] ) ? $parsed_url['host'] : '';
		$path = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '';

		// Extract file extension
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		$path = rawurldecode( $path );
		$filename = basename( $path );
		$dirname = dirname( $path );

		$pattern = '/^(.+)-(\d+)x(\d+)\.' . preg_quote( $extension, '/' ) . '$/i';
		if ( preg_match( $pattern, $filename, $matches ) ) {
			// Extract base filename, width, and height from the filename suffix
			$base_filename = $matches[1];
			$width = $matches[2];
			$height = $matches[3];

			// Before reconstructing the original path, check if this filename is actually the original image
			// Cache attachment IDs to avoid redundant queries
			static $attachment_id_cache = array();
			if ( isset( $attachment_id_cache[ $url ] ) ) {
				$attachment_id = $attachment_id_cache[ $url ];
			} else {
				$attachment_id = attachment_url_to_postid( $url );
				$attachment_id_cache[ $url ] = $attachment_id;
			}

			if ( $attachment_id ) {
				$metadata = wp_get_attachment_metadata( $attachment_id );
				if ( $metadata && isset( $metadata['file'] ) ) {
					// Get the original filename from metadata
					$original_file = basename( $metadata['file'] );
					if ( $original_file === $filename ) {
						// The filename is the original image, do not modify
						$original_path = $path;
						$width = null;
						$height = null;
					} else {
						// Reconstruct the path without size suffix
						$original_path = trailingslashit( $dirname ) . $base_filename . '.' . $extension;
					}
				} else {
					// Could not get metadata, proceed as before
					$original_path = trailingslashit( $dirname ) . $base_filename . '.' . $extension;
				}
			} else {
				// Could not get attachment ID, proceed as before
				$original_path = trailingslashit( $dirname ) . $base_filename . '.' . $extension;
			}
		} else {
			// No size suffix found; use the original path
			$original_path = $path;
			$width = null;
			$height = null;
		}

		// Get the CDN domain
		$cdn_domain = $this->get_cdn_domain();

		if ( ! $cdn_domain ) {
			// If CDN domain is not available, return original URL
			return $url;
		}

		// Build the CDN URL including the original host and the original path
		$cdn_url = 'https://i' . $this->bliz_cdn_get_server_num( $url ) . '.' . $cdn_domain . '/' . $host . $original_path;

		// Build the query parameters
		$query_params = array();

		// If width and height are available, add resize parameter
		if ( $width > 0 && $height > 0 ) {
			$query_params['resize'] = $width . ',' . $height;
		}

		// If the site is using SSL, add ssl=1
		if ( $this->is_ssl ) {
			$query_params['ssl'] = '1';
		}

		// Append any existing query parameters from the original URL
		if ( isset( $parsed_url['query'] ) && ! empty( $parsed_url['query'] ) ) {
			parse_str( $parsed_url['query'], $original_query_params );
			$query_params = array_merge( $original_query_params, $query_params );
		}

		// Build the query string
		if ( ! empty( $query_params ) ) {
			$cdn_url .= '?' . http_build_query( $query_params, '', '&', PHP_QUERY_RFC3986 );
		}

		return $cdn_url;
	}

	/**
	 * Validates the image URL before replacing
	 */
	public function bliz_cdn_validate_image_url( $parsed_url ) {
		$supported_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'webp' );

		if ( ! isset( $parsed_url['path'] ) ) {
			return false;
		}

		$extension = pathinfo( $parsed_url['path'], PATHINFO_EXTENSION );

		// Check if the extension is supported
		if ( ! in_array( strtolower( $extension ), $supported_extensions ) ) {
			return false;
		}

		// Get the image's host
		$image_host = isset( $parsed_url['host'] ) ? strtolower( $parsed_url['host'] ) : '';

		// Remove 'www.' prefix from image host
		$image_host = preg_replace( '/^www\./', '', $image_host );
		
		// If host is empty (relative URL), assume site host
		if ( empty( $image_host ) ) {
			$image_host = $this->site_host;
		}

		// Check if the hosts match
		if ( $image_host !== $this->site_host ) {
			return false;
		}

		return true;
	}

	/**
	 * Gets the CDN server number for sharding
	 */
	public function bliz_cdn_get_server_num( $url ) {
		// Generate a consistent server number based on the domain and path

		// Hash based only on the domain to ensure consistency across paths of the same domain.
		$parsed_url = wp_parse_url( $url );
		$domain = isset( $parsed_url['host'] ) ? $parsed_url['host'] : '';

		// Use the domain's hash to calculate the server number
		$hash = crc32( $domain );
		$server_num = abs( $hash ) % 4; // Assuming 4 servers: i0, i1, i2, i3
	
		return $server_num;
	}

	/**
	 * Adds DNS prefetch resource hints for the CDN domains.
	 */
	public function bliz_cdn_dns_prefetch( $hints, $relation_type ) {
		if ( 'dns-prefetch' === $relation_type ) {
			$cdn_domain = $this->get_cdn_domain();
			if ( ! $cdn_domain ) {
				// If CDN domain is not available, do not add hints
				return $hints;
			}

			// CDN domains to prefetch
			$cdn_domains = array(
				'i0.' . $cdn_domain,
				'i1.' . $cdn_domain,
				'i2.' . $cdn_domain,
				'i3.' . $cdn_domain,
			);

			// Add CDN domains to the hints array if not already present
			foreach ( $cdn_domains as $cdn_domain_prefixed ) {
				$cdn_domain_prefixed = '//' . $cdn_domain_prefixed;
				if ( ! in_array( $cdn_domain_prefixed, $hints ) ) {
					$hints[] = $cdn_domain_prefixed;
				}
			}
		}

		return $hints;
	}
	//

	/**
	 * Registered Events
	 * These are when the purge is triggered
	 *
	 * @since 1.0
	 * @access protected
	 */
	protected function blizgetRegisterEvents() {

		// Define registered purge events
		$actions = array(
			'switch_theme',							// After a theme is changed
			'autoptimize_action_cachepurged',		// Compatibility with Autoptimize plugin
			'save_post',							// Save a post
			'deleted_post',							// Delete a post
			'trashed_post',							// Empty trashed post
			'edit_post',							// Edit a post
			'delete_attachment',					// Delete an attachment
			'publish_future_post',					// When a scheduled post is published
			'woocommerce_update_product',			// When a WooCommerce product is updated
		);

		// Send back the actions array, filtered
		// @param array $actions the actions that trigger the purge event
		return apply_filters( 'ccache_http_purge_events', $actions );
	}

	/**
	 * Events that have no post IDs
	 * These are when a full purge is triggered
	 *
	 * @since 3.9
	 * @access protected
	 */
	protected function getNoIDEvents() {

		// Define registered purge events
		$actions = array(
			'switch_theme',						// After a theme is changed
			'autoptimize_action_cachepurged',	// Compatibility with Autoptimize plugin
			'customize_save_after',				// After saving changes in the Customizer
			'acf/save_post',					// Advanced Custom Fields
			'gform_after_save_form',			// Gravity Forms
			'rank_math/after_save_settings',	// Rank Math SEO
			'as3cf_after_upload_attachment',	// WP Offload Media
		);

		// Send back the actions array, filtered
		// DEVELOPERS: USE THIS SPARINGLY!
		return apply_filters( 'ccache_http_purge_events_full', $actions );
	}

	/**
	 * Execute Purge
	 * Run the purge command for the URLs. Calls $this->purgeUrl for each URL
	 *
	 * @since 1.0
	 * @access protected
	 */
	public function blizexecutePurge() {
		$purgeUrls = array_unique( $this->purgeUrls );

		// Get correct HTTP protocol from Blizhost CloudCache
		$http_proto = $this->is_ssl ? "https://" : "http://";

		if ( $this->do_full_purge ) {
			// Send the full purge URL in an array
			$this->blizpurgeUrl( array( $http_proto . $this->site_host . '/.*' ) ); // Clears the main domain
		} else {
			if ( ! empty( $purgeUrls ) ) {
				// Send all URLs in a single request
				$this->blizpurgeUrl( $purgeUrls );
			}
		}
	}

	/**
	 * Purge URL
	 * Parse the URL for proxy servers
	 *
	 * @since 1.0
	 * @param string $url The URL to be purged
	 * @access protected
	 */
	protected function blizpurgeUrl( $urls ) {
		// Ensure $urls is an array
		if ( ! is_array( $urls ) ) {
			$urls = array( $urls );
		}

		// Do not send requests if the site is not hosted at Blizhost
		if ( stripos( $this->server_hostname, 'blizhost' ) === false ) {
			return false;
		}

		// Obtain the hash using the new method
		$hash = $this->get_hash();

		if ( ! $hash ) {
			// If no hash is available, do not proceed
			return false;
		}

		// Get plugin version
		$plugin_version = $this->p_version;

		// Determine the directory based on the execution context
		$dir = ( $this->server_sapi === 'cli' ) ? ( isset( $_SERVER['SCRIPT_FILENAME'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_FILENAME'] ) ) : '' ) : ( isset( $_SERVER['DOCUMENT_ROOT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ) : '' );

		$dir_exp = explode( "/", $dir );

		// Ensure that the expected indices exist
		$user = isset( $dir_exp[2] ) ? $dir_exp[2] : '';

		$blizpurgeme_list = array();

		foreach ( $urls as $url ) {
			$p = wp_parse_url( $url );
			$host = isset( $p['host'] ) ? $p['host'] : $this->site_host;

			$path = isset( $p['path'] ) ? $p['path'] : '';

			$purge_method = 'default';

			// Check if the path contains '.*', indicating a regex
			if ( strpos( $path, '.*' ) !== false ) {
				$purge_method = 'regex';
			}

			$blizpurgeme = $path;

			// If there are query parameters, add them back to the URL
			if ( ! empty( $p['query'] ) ) {
				$blizpurgeme .= '?' . $p['query'];
			}

			// Collect each URL with its method
			$blizpurgeme_list[] = array(
				'url' => $blizpurgeme,
				'method' => $purge_method,
			);
		}

		// Encode the URLs array in JSON, ensuring proper encoding of special characters
		$urls_json = wp_json_encode( $blizpurgeme_list );

		// Blizhost cleanup CloudCache after checking if the hash is correct
		$response = wp_remote_request( 'https://cloudcache-api.blizhost.com/purge/', array(
			'method' => 'POST',
			'timeout' => 15,
			'sslverify' => false,
			'body' => array(
				'urls'				=> $urls_json,
				'user'				=> $user,
				'host'				=> $host,
				'key'				=> $hash,
				'server'			=> $this->server_hostname,
				'phpsapi'			=> $this->server_sapi,
				'plugin_version'	=> $plugin_version,
			),
		) );

		do_action( 'after_purge_url', $urls, $blizpurgeme_list, $response );
	}

	/**
	 * Purge - No IDs
	 * Flush the whole cache
	 *
	 * @since 3.9
	 * @access private
	 */
	public function purgeNoID( ...$args ) {
		$listofurls = array();

		array_push( $listofurls, self::the_home_url() . '/.*' );

		// Now flush all the URLs we've collected provided the array isn't empty
		if ( ! empty( $listofurls ) ) {
			foreach ( $listofurls as $url ) {
				array_push( $this->purgeUrls, $url );
			}
		}
	}

	/**
	 * Purge Post Status
	 * Handles the transition_post_status event and clears the cache of the specific post
	 *
	 * @since 4.0.6
	 * @access public
	 */
	public function blizpurgePostStatus( $new_status, $old_status, $post ) {
		if ( $new_status != $old_status ) {
			$this->blizpurgePost( $post->ID );
		}
	}

	/**
	 * Purge Post
	 * Flush the post
	 *
	 * @since 1.0
	 * @param int $postId The ID of the post to be purged
	 * @access public
	 */
	public function blizpurgePost( $postId ) {

		// Checks if the post has already been processed
		if ( in_array( $postId, $this->processedPosts ) ) {
			return;
		}
		// Marks the post as processed
		$this->processedPosts[] = $postId;

		// If this is a valid post we want to purge the post,
		// the home page, and any associated tags and categories

		$validPostStatus = array( "publish", "trash" );
		$thisPostStatus  = get_post_status( $postId );

		// Array to collect all our URLs
		$listofurls = array();

		$permalink = get_permalink( $postId );
		if ( $permalink && in_array( $thisPostStatus, $validPostStatus ) ) {
			// If this is a post with a permalink AND it's published or trashed,
			// we're going to add several things to flush.

			// Category purge based on Donnacha's work in WP Super Cache
			$categories = get_the_category( $postId );
			if ( $categories ) {
				foreach ( $categories as $cat ) {
					array_push( $listofurls, get_category_link( $cat->term_id ) );
				}
			}
			// Tag purge based on Donnacha's work in WP Super Cache
			$tags = get_the_tags( $postId );
			if ( $tags ) {
				foreach ( $tags as $tag ) {
					array_push( $listofurls, get_tag_link( $tag->term_id ) );
				}
			}

			// Author URL
			array_push( $listofurls,
				get_author_posts_url( get_post_field( 'post_author', $postId ) ),
				get_author_feed_link( get_post_field( 'post_author', $postId ) )
			);

			// Archives and their feeds
			if ( get_post_type_archive_link( get_post_type( $postId ) ) == true ) {
				array_push( $listofurls,
					get_post_type_archive_link( get_post_type( $postId ) ),
					get_post_type_archive_feed_link( get_post_type( $postId ) )
				);
			}

			// Post URL
			array_push( $listofurls, $permalink );

			// Also clean URL for trashed post
			if ( $thisPostStatus == "trash" ) {
				$trashpost = $permalink;
				$trashpost = str_replace( "__trashed", "", $trashpost );
				array_push( $listofurls, $trashpost, $trashpost . 'feed/' );
			}

			// Add in AMP permalink if Automattic's AMP is installed
			if ( function_exists( 'amp_get_permalink' ) ) {
				array_push( $listofurls, amp_get_permalink( $postId ) );
			}

			// Regular AMP URL for posts
			array_push( $listofurls, $permalink . 'amp/' );

			// Feeds
			array_push( $listofurls,
				get_bloginfo_rss( 'rdf_url' ),
				get_bloginfo_rss( 'rss_url' ),
				get_bloginfo_rss( 'rss2_url' ),
				get_bloginfo_rss( 'atom_url' ),
				get_bloginfo_rss( 'comments_rss2_url' ),
				get_post_comments_feed_link( $postId )
			);

			// Sitemaps
			array_push( $listofurls, home_url() . '/.*sitemap.*' );

			// Personalized feeds and within categories
			array_push( $listofurls, home_url() . '/.*/feed/.*' );

			// Pagination
			array_push( $listofurls, home_url() . '/page/.*' );
			array_push( $listofurls, home_url() . '/.*/page/.*' );

			// Home Page and (if used) posts page
			array_push( $listofurls, self::the_home_url() . '/' );
			if ( get_option( 'show_on_front' ) == 'page' ) {
				// Ensure we have a page_for_posts setting to avoid empty URL
				if ( get_option( 'page_for_posts' ) ) {
					array_push( $listofurls, get_permalink( get_option( 'page_for_posts' ) ) );
				}
			}
		} else {
			// We're not sure how we got here, but bail instead of processing anything else.
			return;
		}

		// Now flush all the URLs we've collected provided the array isn't empty
		if ( ! empty( $listofurls ) ) {
			foreach ( $listofurls as $url ) {
				array_push( $this->purgeUrls, $url );
			}
		}

		// Filter to add or remove URLs to the array of purged URLs
		// @param array $purgeUrls the urls (paths) to be purged
		// @param int $postId the id of the new/edited post
		$this->purgeUrls = apply_filters( 'bliz_purge_urls', $this->purgeUrls, $postId );
	}

	/**
	* Handle the cache purge request via URL
	*/
	public function handle_fallback_purge_cache_request() {
		if ( current_user_can( 'manage_options' ) ) {
			$this->do_full_purge = true;
		}
	}

	/**
	 * AJAX Handler for Purge All
	 */
	public function bliz_purge_all_ajax_handler() {
		// Verify nonce for security
		check_ajax_referer( 'bliz_purge_all_nonce', 'nonce' );

		// Check user permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'You do not have sufficient permissions to access this action.', 'blizhost-cache-purge' ) );
		}

		// Perform the purge
		$this->do_full_purge = true;

		// Prepare the notice HTML
		$message = __( 'All CloudCache has been purged!', 'blizhost-cache-purge' );
		$notice  = '<div id="message" class="notice notice-success is-dismissible"><p><strong>' . $message . '</strong></p></div>';

		// Send success response with notice HTML
		wp_send_json_success( array( 'notice' => $notice ) );
	}

	/**
	 * Retrieve the hash value according to the specified rules.
	 *
	 * @return string|false The hash value or false if not available.
	 */
	protected function get_hash() {
		if ( $this->server_sapi !== 'cli' ) {
			// Not CLI, use $_SERVER['HTTP_X_PURGE_KEY']
			if ( isset( $_SERVER['HTTP_X_PURGE_KEY'] ) ) {
				$hash = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_PURGE_KEY'] ) );
				return $hash;
			} else {
				return false;
			}
		} else {
			// In CLI mode, attempt to get hash from transient
			$hash = get_transient( 'bliz_hash_transient' );
			if ( false === $hash ) {
				// Transient has expired or not set, make a POST request to generate and store the hash
				$request_url = home_url( '/' ); // Target the home URL

				// Generate a nonce
				$nonce = wp_create_nonce( 'generate_hash_action' );

				$response = wp_remote_post( $request_url, array(
					'body' => array(
						'generate_hash' => '1',
						'nonce' => $nonce,
					),
					'timeout' => 15,
					'sslverify' => false,
				) );

				if ( is_wp_error( $response ) ) {
					// Handle error
					return false;
				}

				// Now attempt to get the hash from the transient again
				$hash = get_transient( 'bliz_hash_transient' );
			}
			// Return the hash from transient or false if still not set
			return $hash ? $hash : false;
		}
	}

	/**
	 * Retrieve the CDN domain according to the specified rules.
	 *
	 * @return string|false The CDN domain or false if not available.
	 */
	protected function get_cdn_domain() {
		if ( $this->server_sapi !== 'cli' ) {
			// Not CLI, use $_SERVER['HTTP_X_CDN_DOMAIN']
			if ( isset( $_SERVER['HTTP_X_CDN_DOMAIN'] ) ) {
				$cdn_domain = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_CDN_DOMAIN'] ) );
				return $cdn_domain;
			} else {
				return false;
			}
		} else {
			// In CLI mode, attempt to get CDN domain from transient
			$cdn_domain = get_transient( 'bliz_domain_transient' );
			if ( false === $cdn_domain ) {
				// Transient has expired or not set, make a POST request to generate and store the CDN domain
				$request_url = home_url( '/' ); // Target the home URL

				// Generate a nonce
				$nonce = wp_create_nonce( 'generate_cdn_domain_action' );

				$response = wp_remote_post( $request_url, array(
					'body' => array(
						'generate_cdn_domain' => '1',
						'nonce' => $nonce,
					),
					'timeout' => 15,
					'sslverify' => false,
				) );

				if ( is_wp_error( $response ) ) {
					// Handle error
					return false;
				}

				// Now attempt to get the CDN domain from the transient again
				$cdn_domain = get_transient( 'bliz_domain_transient' );
			}
			// Return the CDN domain from transient or false if still not set
			return $cdn_domain ? $cdn_domain : false;
		}
	}

	/**
	 * Handle the generate_hash request to store the hash in transient and database.
	 */
	public function handle_generate_hash() {
		if ( isset( $_POST['generate_hash'] ) ) {
			// Verify nonce
			if ( isset( $_POST['nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'generate_hash_action' ) ) {
				$current_domain = parse_url( home_url(), PHP_URL_HOST );

				// Fetch the hash value from $_SERVER
				if ( isset( $_SERVER['HTTP_X_PURGE_KEY'] ) ) {
					$hash = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_PURGE_KEY'] ) );
					// Store in transient and options table
					set_transient( 'bliz_hash_transient', $hash, DAY_IN_SECONDS );
					update_option( 'bliz_hash', array( 'hash' => $hash, 'domain' => $current_domain, 'timestamp' => time() ) );
				}
			}
			// Nonce verification failed or not set; do not process the request
			// Do not output anything
			exit;
		}
	}

	/**
	 * Handle the generate_cdn_domain request to store the CDN domain in transient and database.
	 */
	public function handle_generate_cdn_domain() {
		if ( isset( $_POST['generate_cdn_domain'] ) ) {
			// Verify nonce
			if ( isset( $_POST['nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'generate_cdn_domain_action' ) ) {
				$current_domain = parse_url( home_url(), PHP_URL_HOST );

				// Fetch the CDN domain value from $_SERVER
				if ( isset( $_SERVER['HTTP_X_CDN_DOMAIN'] ) ) {
					$cdn_domain = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_CDN_DOMAIN'] ) );
					// Store in transient and options table
					set_transient( 'bliz_domain_transient', $cdn_domain, DAY_IN_SECONDS );
					update_option( 'bliz_domain', array( 'domain' => $cdn_domain, 'site_domain' => $current_domain, 'timestamp' => time() ) );
				}
			}
			// Nonce verification failed or not set; do not process the request
			// Do not output anything
			exit;
		}
	}

}

$purger = new BlizCloudCachePurger();

/**
 * Purge CloudCache via WP-CLI
 *
 * @since 3.8
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	include( 'wp-cli.php' );
}
