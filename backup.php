<?php
/*
Plugin Name: Backup
Version: 2.2
Plugin URI: http://hel.io/wordpress/backup/
Description: Back up your WordPress website to Google Drive.
Author: Sorin Iclanzan
Author URI: http://hel.io/
License: GPL3
Text Domain: backup
Domain Path: /languages
*/

/*
	Copyright 2012 Sorin Iclanzan  (email : sorin@hel.io)

	This file is part of Backup.

	Backup is free software: you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	Backup is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with Backup. If not, see http://www.gnu.org/licenses/gpl.html.
*/

// Only load the plugin if needed.
if ( is_admin() || defined( 'DOING_CRON' ) || isset( $_GET['doing_wp_cron'] ) || isset( $_GET['backup'] ) ) {

// Load required classes.
if ( ! class_exists( 'GOAuth' ) )
	require_once( 'class-goauth.php' );
if ( ! class_exists( 'GDocs' ) )
	require_once( 'class-gdocs.php' );

// Load helper functions
require_once( 'functions.php' );

/**
 * Backup for WordPress class.
 *
 * Implements backup functionality in WordPress. Currently supports
 * backing up on the local filesystem and on Google Drive.
 *
 * @uses WP_Error for storing error messages.
 * @uses GOAuth   for Google OAuth2 authorization.
 * @uses GData    to upload backups to Google Drive (Docs).
 */
class Backup {

	/**
	 * Stores the plugin version.
	 *
	 * @var string
	 * @access private
	 */
	private $version;

	/**
	 * Stores the plugin base filesystem directory
	 *
	 * @var string
	 * @access private
	 */
	private $plugin_dir;

	/**
	 * Stores the unique text domain used for I18n
	 *
	 * @var string
	 * @access private
	 */
	private $text_domain;

	/**
	 * Stores plugin options.
	 *
	 * Options are automatically updated in the database when the destructor is called.
	 *
	 * @var array
	 * @access private
	 */
	private $options;

	/**
	 * Stores custom schedule intervals to use with WP_Cron.
	 *
	 * @var array
	 * @access private
	 */
	private $schedules;

	/**
	 * Stores the redirect URI needed by GOAuth.
	 *
	 * @var string
	 * @access private
	 */
	private $redirect_uri;

	/**
	 * Stores an instance of GDocs.
	 *
	 * @var GDocs
	 * @access private
	 */
	private $gdocs;

	/**
	 * Stores an instance of GOAuth.
	 *
	 * @var GOAuth
	 * @access private
	 */
	private $goauth;

	/**
	 * Stores messages that need to be displayed on the option page.
	 *
	 * @var array
	 * @access private
	 */
	private $messages = array();

	/**
	 * Stores the absolute path to the directory this plugin will use to store files.
	 *
	 * @var string
	 * @access private
	 */
	private $local_folder;

	/**
	 * Stores the absolute path and file name where database dumps are saved.
	 *
	 * @var string
	 * @access private
	 */
	private $dump_file;

	/**
	 * Stores the absolute path to the log file.
	 *
	 * @var string
	 * @access private
	 */
	private $log_file;

	/**
	 * Stores a list of paths to directories and files that are available for backup.
	 *
	 * @var array
	 * @access private
	 */
	private $sources;

	/**
	 * Stores paths that are to be excluded when backing up.
	 *
	 * @var array
	 * @access private
	 */
	private $exclude = array();

	/**
	 * Stores a list of URIs representing the scope required by GOAuth.
	 *
	 * @var array
	 * @access private
	 */
	private $scope;

	/**
	 * Stores the timestamp at the time of the execution.
	 *
	 * @var integer
	 * @access private
	 */
	private $time;

	/**
	 * Stores the identifier of the plugin options page.
	 *
	 * @var string
	 * @access private
	 */
	private $pagehook;

	/**
	 * Stores the ID of the current user
	 *
	 * @var integer
	 * @access private
	 */
	private $user_id;

	/**
	 * Stores the list of HTTP transports supported by WordPress
	 *
	 * @var array
	 * @access private
	 */
	private $http_transports;

	/**
	 * Constructor
	 *
	 * This loads most of what's needed to run the plugin and sets actions and filters.
	 *
	 * @global float $timestart Seconds from when script started
	 */
	function __construct() {
		global $timestart;

		$this->version = '2.2';
		$this->time = intval( $timestart );
		$this->plugin_dir = dirname( plugin_basename( __FILE__ ) );
		$this->text_domain = 'backup';

		// Enable internationalization
		load_plugin_textdomain( $this->text_domain, false, $this->plugin_dir . '/languages' );

		$this->scope = array(
			'https://www.googleapis.com/auth/drive.file',
			'https://www.googleapis.com/auth/userinfo.profile',
			'https://www.googleapis.com/auth/userinfo.email',
			'https://docs.google.com/feeds/',
			'https://docs.googleusercontent.com/',
			'https://spreadsheets.google.com/feeds/'
		);

		$this->http_transports = array( 'curl', 'streams', 'fsockopen' );

		$this->schedules = array(
			'weekly' => array(
				'interval' => 604800,
				'display' => __( 'Weekly', $this->text_domain )
			),
			'monthly' => array(
				'interval' => 2592000,
				'display' => __( 'Monthly', $this->text_domain )
			)
		);
		$this->redirect_uri = admin_url( 'options-general.php?page=backup&action=auth' );

		// Get options if they exist, else set defaults
		if ( ! $this->options = get_option( 'backup_options' ) ) {
			$this->options = array(
				'plugin_version'      => $this->version,
				'backup_token'        => '',
				'refresh_token'       => '',
				'backup_title'        => get_bloginfo( 'name' ),
				'local_folder'        => '',
				'drive_folder'        => '',
				'backup_frequency'    => 'never',
				'source_list'         => array( 'database', 'content', 'uploads', 'plugins' ),
				'exclude_list'        => array( '.svn', '.git', '.DS_Store' ),
				'include_list'        => array(),
				'backup_list'         => array(),
				'client_id'           => '',
				'client_secret'       => '',
				'local_number'        => 1,
				'drive_number'        => 10,
				'quota_total'         => '',
				'quota_used'          => '',
				'chunk_size'          => 1, // MB
				'time_limit'          => 600, // seconds
				'backup_attempts'     => 3,
				'request_timeout'     => 60, // seconds
				'enabled_transports'  => $this->http_transports,
				'ssl_verify'          => true,
				'email_notify'        => false,
				'user_info'           => array()
			);
		}
		else
			if (
				!isset( $this->options['plugin_version'] ) ||
				version_compare( $this->version, $this->options['plugin_version'], '>' )
			)
				add_action( 'init', array( &$this, 'upgrade' ), 1 );

		// Allow some options to be overwritten from the config file.
		if ( defined( 'BACKUP_REFRESH_TOKEN' ) ) $this->options['refresh_token'] = BACKUP_REFRESH_TOKEN;
		if ( defined( 'BACKUP_DRIVE_FOLDER' ) )  $this->options['drive_folder']  = BACKUP_DRIVE_FOLDER;
		if ( defined( 'BACKUP_CLIENT_ID' ) )     $this->options['client_id']     = BACKUP_CLIENT_ID;
		if ( defined( 'BACKUP_CLIENT_SECRET' ) ) $this->options['client_secret'] = BACKUP_CLIENT_SECRET;
		if ( defined( 'BACKUP_LOCAL_FOLDER' ) )  $this->options['local_folder']  = BACKUP_LOCAL_FOLDER;

		$this->local_folder = absolute_path( $this->options['local_folder'], ABSPATH );

		if ( defined( 'BACKUP_LOCAL_FOLDER' ) )
			$this->create_dir( $this->local_folder );

		$this->dump_file = $this->local_folder . '/dump.sql';
		$upload_dir = wp_upload_dir();

		$this->sources = array(
			'database'  => array( 'title' => __( 'Database',  $this->text_domain ), 'path' => $this->dump_file ),
			'content'   => array( 'title' => __( 'Content',   $this->text_domain ), 'path' => WP_CONTENT_DIR ),
			'uploads'   => array( 'title' => __( 'Uploads',   $this->text_domain ), 'path' => $upload_dir['basedir'] ),
			'plugins'   => array( 'title' => __( 'Plugins',   $this->text_domain ), 'path' => WP_PLUGIN_DIR ),
			'wordpress' => array( 'title' => __( 'WordPress', $this->text_domain ), 'path' => ABSPATH )
		);

		$this->exclude[] = $this->local_folder;

		register_activation_hook( __FILE__, array( &$this, 'activate' ) );

		// Add custom cron intervals
		add_filter( 'cron_schedules', array( &$this, 'cron_add_intervals' ) );

		// Link to the settings page from the plugins page
		add_filter( 'plugin_action_links', array( &$this, 'action_links' ), 10, 2 );

		// Disable unwanted HTTP transports.
		if ( isset( $this->options['enabled_transports'] ) )
			foreach ( $this->http_transports as $t )
				if ( !in_array( $t, $this->options['enabled_transports'] ) )
					add_filter( 'use_' . $t . '_transport', '__return_false' );

		// Add 'Backup' to the Settings admin menu; save default metabox layout in the database.
		add_action( 'admin_menu', array( &$this, 'backup_menu' ) );

		// Handle Google OAuth2.
		if ( $this->is_auth() )
			add_action( 'init', array( &$this, 'auth' ) );

		// Display persistent error notifications if we have any.
		if ( isset( $this->options['messages']['error'] ) )
			add_action( 'admin_notices', array( &$this, 'error_notice' ), 3 );

		// Print admin notices.
		add_action( 'admin_notices', array( &$this, 'print_notices' ), 2 );

		// Do backup on schedule.
		add_action( 'backup_schedule', array( &$this, 'do_backup' ) );

		// Retry to backup after a failed attempt.
		add_action( 'backup_retry', array( &$this, 'retry_backup' ) );

		// Do stuff just before the end of script execution.
		add_action( 'shutdown', array( &$this, 'shutdown' ) );

		// Prepare GOAuth object.
		$this->goauth = new GOAuth( array(
			'client_id'       => $this->options['client_id'],
			'client_secret'   => $this->options['client_secret'],
			'redirect_uri'    => $this->redirect_uri,
			'refresh_token'   => $this->options['refresh_token'],
			'request_timeout' => $this->options['request_timeout'],
			'ssl_verify'      => $this->options['ssl_verify']
		) );

		// If we're doing backup work set the environment accordingly.
		if ( $this->doing_backup() ) {
			if ( get_transient( 'backup_lock' ) )
				exit; // Exit if another backup process is running.
			set_transient( 'backup_lock', $this->time, 60 * 60 * 24 );
			add_action( 'shutdown', array( &$this, 'unlock' ) );

			// Enable manual backup URI.
			add_action( 'template_redirect', array( &$this, 'manual_backup' ) );

			@ini_set( 'safe_mode', 0 ); // Try to disable safe mode.
			set_time_limit( $this->options['time_limit'] ); // Set the time limit.
			// We might need a lot of memory for backing up.
			@ini_set( 'memory_limit', apply_filters( 'admin_memory_limit', WP_MAX_MEMORY_LIMIT ) );
			ignore_user_abort( true ); // Allow the script to run after the user closes the window.
			// All this is needed in order that every echo gets sent to the browser.
			@ini_set( 'zlib.output_compression', 0 );
			@ini_set( 'implicit_flush', 1 );
			wp_ob_end_flush_all();
			ob_implicit_flush();
		}
	}

	/**
	 * This is run when you activate the plugin, checking for compatibility, adding the default options to the database.
	 *
	 * @global string $wp_version Used to check against the required WordPress version.
	 */
	public function activate() {
		global $wp_version;
		// Check for compatibility
		try {
			// check OpenSSL
			if ( !function_exists( 'openssl_open' ) ) {
				throw new Exception(
					__( 'Please enable OpenSSL in PHP. Backup needs it to communicate with Google Drive.', $this->text_domain )
				);
			}

			// check SimpleXMLElement
			if ( !class_exists( 'SimpleXMLElement' ) ) {
				throw new Exception(
					__( 'Please enable SimpleXMLElement in PHP. Backup could not be activated.', $this->text_domain )
				);
			}

			// check WordPress version
			if ( version_compare( $wp_version, '3.4', '<' ) ) {
			  throw new Exception( __( 'Backup requires WordPress 3.4 or higher!', $this->text_domain ) );
			}
		}
		catch ( Exception $e ) {
			deactivate_plugins( $this->plugin_dir . '/backup.php', true );
			echo '<div id="message" class="error">' . $e->getMessage() . '</div>';
			trigger_error( 'Could not activate Backup.', E_USER_ERROR );
			return;
		}

		// Set the backup token here, because in the constructor 'wp_generate_password()' is not defined.
		$this->options['backup_token'] = wp_generate_password( 12, false );
		if ( empty( $this->options['local_folder'] ) ) {
			$this->local_folder = WP_CONTENT_DIR . '/backup-' . $this->options['backup_token'];
			$this->options['local_folder'] = relative_path( ABSPATH, $this->local_folder );
		}

		if ( $this->goauth->is_authorized() )
			$this->set_user_info();

		// Add the default options to the database, without letting WP autoload them
		add_option( 'backup_options', $this->options, '', 'no' );

		$this->pagehook = get_plugin_page_hookname( 'backup', 'options-general.php' );

		if ( ! $this->user_id )
			$this->user_id = get_current_user_id();

		// Set the default order of the metaboxes.
		if ( ! get_user_meta( $this->user_id, "meta-box-order_".$this->pagehook, true ) ) {
			$meta_value = array(
				'side' => 'metabox-authorization,metabox-status',
				'normal' => 'metabox-advanced',
				'advanced' => 'metabox-logfile',
			);
			update_user_meta( $this->user_id, "meta-box-order_".$this->pagehook, $meta_value );
		}

		// Set the default closed metaboxes.
		if ( ! get_user_meta( $this->user_id, "closedpostboxes_".$this->pagehook, true ) ) {
			$meta_value = array( 'metabox-advanced' );
			update_user_meta( $this->user_id, "closedpostboxes_".$this->pagehook, $meta_value );
		}

		// Set the default hidden metaboxes.
		if ( ! get_user_meta( $this->user_id, "metaboxhidden_".$this->pagehook, true ) ) {
			$meta_value = array( 'metabox-logfile' );
			update_user_meta( $this->user_id, "metaboxhidden_".$this->pagehook, $meta_value );
		}

		// try to create the default backup folder files
		$this->create_dir( $this->local_folder );
	}

	/**
	 * This function handles upgrading the plugin to a new version.
	 * It gets triggered when the plugin version is different than the one stored in the database.
	 */
	function upgrade() {
		if (
			!isset( $this->options['plugin_version'] ) ||
			version_compare( $this->options['plugin_version'], '2.1', '<' )
		) {
			$this->options['backup_token']        = wp_generate_password( 12, false );
			$this->options['backup_title']        = get_bloginfo( 'name' );
			$this->options['include_list']        = array();
			$this->options['request_timeout']     = 60;
			$this->options['backup_attempts']     = 3;
			$this->options['enabled_transports']  = $this->http_transports;
			$this->options['ssl_verify']          = true;
			$this->options['email_notify']        = false;
			$this->options['user_info']           = array();
			$this->options['backup_list']         = array();

			if ( $this->goauth->is_authorized() )
				$this->set_user_info();

			// Delete the old backup folder if it's the default
			if ( WP_CONTENT_DIR . '/backup' == $this->local_folder ) {
				delete_path( $this->local_folder );
				$this->options['local_files'] = array();

				// and create a new one based on the random token.
				$this->local_folder = WP_CONTENT_DIR . '/backup-' . $this->options['backup_token'];
				$this->options['local_folder'] = relative_path( ABSPATH, $this->local_folder );
				if ( wp_mkdir_p( $this->local_folder ) ) {
					if ( !@is_file( $this->local_folder . "/.htaccess" ) )
						file_put_contents( $this->local_folder . "/.htaccess", "Order allow,deny\nDeny from all" );
				}
			}
			else {
				file_put_contents( $this->local_folder . "/.htaccess", "Order allow,deny\nDeny from all" );
				delete_path( $this->local_folder . '/backup.log' );
			}

			// Use the new backup_list array to store backup data.
			if ( isset( $this->options['backup_list'] ) )
				return;
			$local_list = array_reverse( $this->options['local_files'] );
			$drive_list = array_reverse( $this->options['drive_files'] );
			$count = count( $drive_list );
			if ( count( $local_list ) > $count )
				$count = count( $local_list );
			for ( $i = 0; $i < $count; $i++ ) {
				$element = array();
				$element['status'] = 1;
				if ( isset( $local_list[$i] ) )
					$element['file_path'] = $local_list[$i];
				if ( isset( $drive_list[$i] ) )
					$element['drive_id'] = $drive_list[$i];
				if ( 0 == $i )
					$element['timestamp'] = $this->options['last_backup'];
				$this->options['backup_list'] = array_merge( array( (string) $i => $element ), $this->options['backup_list'] );
			}
			unset(
				$this->options['local_files'],
				$this->options['drive_files'],
				$this->options['last_backup'],
				$this->options['resume_attempts']
			);
		}

		// Save the new plugin version
		$this->options['plugin_version'] = $this->version;
	}

	/**
	 * Filter - Add custom schedule intervals.
	 *
	 * @param  array $schedules The array of defined intervals.
	 * @return array            Returns the array of defined intervals after adding the custom ones.
	 */
	function cron_add_intervals( $schedules ) {
		return array_merge( $schedules, $this->schedules );
	}

	/**
	 * Filter - Adds a 'Settings' action link on the plugins page.
	 *
	 * @param  array  $links The list of links.
	 * @param  string $file  The plugin file to check.
	 * @return array         Returns the list of links with the custom link added.
	 */
	function action_links( $links, $file ) {
		if ( $file != plugin_basename( __FILE__ ) )
			return $links;

		$settings_link = sprintf( '<a href="options-general.php?page=backup">%s</a>',
			__( 'Settings', $this->text_domain )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Action - Adds options page in the admin menu.
	 */
	function backup_menu() {
		$this->pagehook = add_options_page(
			__( 'Backup Settings', $this->text_domain ),
			__( 'Backup', $this->text_domain ),
			'manage_options', 'backup',
			array( &$this, 'options_page' )
		);
		// Hook to update options
		add_action( 'load-'.$this->pagehook, array( &$this, 'options_update' ) );
		// Hook to add metaboxes, context help and screen options
		add_action( 'load-'.$this->pagehook, array( &$this, 'on_load_options_page' ) );
	}

	/**
	 * Action - Adds meta boxes and checks if the local folder is writable.
	 */
	function on_load_options_page() {
		// These scripts are needed for metaboxes to function
		wp_enqueue_script( 'common' );
		wp_enqueue_script( 'wp-lists' );
		wp_enqueue_script( 'postbox' );

		add_action( 'admin_print_footer_scripts', array( &$this, 'print_footer_scripts' ) );
		add_action( 'admin_print_styles', array( &$this, 'print_styles' ) );

		// Add the metaboxes
		add_meta_box( 'metabox-authorization',
			__( 'Authorization', $this->text_domain ),
			array( &$this, 'metabox_authorization_content' ),
			$this->pagehook, 'side', 'core'
		);
		add_meta_box( 'metabox-status',
			__( 'Status', $this->text_domain ),
			array( &$this, 'metabox_status_content' ),
			$this->pagehook, 'side', 'core'
		);
		add_meta_box( 'metabox-advanced',
			__( 'Advanced', $this->text_domain ),
			array( &$this, 'metabox_advanced_content' ),
			$this->pagehook, 'normal', 'core'
		);
		add_meta_box( 'metabox-logfile',
			__( 'Log File', $this->text_domain ),
			array( &$this, 'metabox_logfile_content' ),
			$this->pagehook, 'advanced', 'core'
		);

		// Add help tabs and help sidebar
		include( 'context-help.php' );

		add_screen_option( 'layout_columns', array( 'max' => 2, 'default' => 2 ) );

		// Check if the local folder is writable
		if ( ( !@is_dir( $this->local_folder ) && !$this->create_dir( $this->local_folder ) ) ||
			!@is_writable( $this->local_folder ) )
			$this->messages['error'][] = sprintf(
				__( "The local path %s is not writable. Please change the permissions or choose another directory.",
					$this->text_domain
				), '<kbd>' . esc_html( $this->local_folder ) . '</kbd>'
			);
	}

	/**
	 * Display the options page.
	 *
	 * @global integer   $screen_layout_columns The number of of columns of the current screen
	 * @global object    $wp_locale
	 */
	function options_page() {
		global $screen_layout_columns, $wp_locale;
		require_once( 'page-options.php' );
	}

	function print_styles() {
		?><style>
			#metabox-status > .inside {
				padding: 0;
			}
			td > .feature-filter > .feature-group > li {
				width: 120px;
			}
		</style><?php
	}

	function print_footer_scripts() {
		?><script type="text/javascript">
	//<![CDATA[
	jQuery(document).ready( function($) {
		$('.if-js-closed').removeClass('if-js-closed').addClass('closed');
		postboxes.add_postbox_toggles('<?php echo $this->pagehook; ?>');
		var val = "";
		$('#backup_frequency').change(function(){
			val = $("#backup_frequency option:selected").val();
			switch(val) {
				case "never":
					$("#start_wrap").addClass("hide-if-js");
					break;
				default:
					$("#start_wrap").removeClass("hide-if-js");
			}
		});
		$('#need-help-link').click(function(e){e.preventDefault();$('#contextual-help-link').trigger('click')});
		$('.show-para').click(function(e){e.preventDefault();$(this).hide();$(this).parent().siblings('.para').slideDown("fast");});
		$('.click-select').click(function(){(this).select()});
	});
	//]]>
</script><?php
	}

	/**
	 * Render Authorization meta box.
	 */
	function metabox_authorization_content( $data ) {
		if ( !$this->goauth->is_authorized() ) { ?>
		<form action="<?php echo $this->redirect_uri; ?>" method="post">
			<p><?php _e('Before backups can be uploaded to Google Drive, you need to authorize the plugin and give it permission to make changes on your behalf.', $this->text_domain ); ?></p>
			<p>
				<label for="client_id"><?php _e( 'Client ID', $this->text_domain ); ?></label>
				<input id="client_id" name="client_id" type='text' <?php __checked_selected_helper( defined( 'BACKUP_CLIENT_ID' ), true, true, 'readonly' ); ?> class="large-text" value='<?php echo esc_html( $this->options['client_id'] ); ?>' />
			</p>
			<p>
				<label for="client_secret"><?php _e( 'Client secret', $this->text_domain ); ?>
				<input id="client_secret" name='client_secret' type='text' <?php __checked_selected_helper( defined( 'BACKUP_CLIENT_SECRET' ), true, true, 'readonly' ); ?> class="large-text" value='<?php echo esc_html( $this->options['client_secret'] ); ?>' />
			</p>
			<p class="para hide-if-js">
				<label for="refresh_token"><?php _e( 'Refresh token', $this->text_domain ); ?>
				<input id="refresh_token" name='refresh_token' type='text' <?php __checked_selected_helper( defined( 'BACKUP_REFRESH_TOKEN' ), true, true, 'readonly' ); ?> class="large-text" value='<?php echo esc_html( $this->options['refresh_token'] ); ?>' />
			</p>
			<p>
				<input name="authorize" type="submit" class="button-secondary" value="<?php _e( 'Authorize', $this->text_domain ) ?>" /> <a href="#refresh_token" class="show-para hide-if-no-js"><?php _e( 'More', $this->text_domain ); ?></a>
			</p>
		</form>
		<?php } else { ?>
		<?php if ( !empty( $this->options['user_info'] ) ) { ?>
			<div id="dashboard_right_now" class="column-username">
				<?php if ( $this->options['user_info']['picture'] ) { ?>
				<img src="<?php echo $this->options['user_info']['picture']; ?>" width="50" heigth="50" />
				<?php } else {
					$default = get_option( 'avatar_default' );
					if ( empty( $default ) )
						$default = 'mystery';
					echo get_avatar( $this->options['user_info']['email'], 50, $default );
				}?>
				<strong><?php echo $this->options['user_info']['name']; ?></strong><br />
				<?php echo $this->options['user_info']['email']; ?><br />
				<span class="approved"><?php _e( "Authorized", $this->text_domain ); ?></span>
			</div>
		<?php }
		?>
		<p><?php _e( 'Authorization to use Google Drive has been granted.', $this->text_domain ); ?></p>
		<p class="para hide-if-js"><input type="text" readonly="readonly" class="click-select large-text" value="<?php echo esc_html( $this->options['refresh_token'] ); ?>" /></p>
		<p><?php
			if ( defined( 'BACKUP_REFRESH_TOKEN' ) ) {
				?><a class="button disabled"><?php
			} else {
				?><a href="<?php echo $this->redirect_uri; ?>&state=revoke" class="button-secondary"><?php
			}
			?><?php _e( 'Revoke access', $this->text_domain ); ?></a> <a href="#token-select" class="show-para hide-if-no-js"><?php _e( 'Show token', $this->text_domain ); ?></a></p><?php
		}
	}

	/**
	 * Render Status meta box.
	 */
	function metabox_status_content( $data ) {
		$datetime_format = get_option( 'date_format' ) . " " . get_option( 'time_format' );
		echo '<div class="misc-pub-section">' . __( 'Current date and time:', $this->text_domain ) . '<br/><strong>' .
			date_i18n( $datetime_format, $this->time ) .
		'</strong></div>' .
		'<div class="misc-pub-section">' . __( 'Most recent backup:', $this->text_domain ) . '<br/><strong>';
			if ( $backup = $this->get_last_backup( true ) )
				echo date_i18n( $datetime_format, $backup['timestamp'] );
			else
				_e( 'never', $this->text_domain );
		echo '</strong></div>' .
		'<div class="misc-pub-section">' . __( 'Next scheduled backup:', $this->text_domain ) . '<br/><strong>';
			if ( $next = wp_next_scheduled( 'backup_schedule' ) )
				echo date_i18n( $datetime_format, $next );
			else
				_e( 'never', $this->text_domain );
		echo '</strong></div>';
		if ( $this->options['quota_used'] ) {
			echo '<div class="misc-pub-section">' . __( 'Google Drive quota:', $this->text_domain ) . '<br/><strong>';
			printf( __( '%s of %s used', $this->text_domain ),
				size_format( $this->options['quota_used'], 2 ),
				size_format( $this->options['quota_total'], 2 )
			);
			echo '</strong></div>';
		}
		?>
		<div class="misc-pub-section misc-pub-section-last"><?php _e( 'Manual backup:', $this->text_domain ) ?>
			<p class="para hide-if-js">
				<input type="text" readonly="readonly" class="click-select large-text" value="<?php echo home_url( '?backup=' . $this->options['backup_token'] ); ?>" />
			</p>
			<div id="waiting">
				<a href="<?php echo home_url( '?backup=' . $this->options['backup_token'] ); ?>" class="button-secondary" target="_blank">
					<?php _e( 'Back up now', $this->text_domain ) ?></a>
				<a href="#waiting" class="show-para hide-if-no-js">
					<?php _e( 'Show URI', $this->text_domain ) ?>
				</a>
			</div>
		</div><?php
	}

	/**
	 * Render Advanced meta box.
	 */
	function metabox_advanced_content( $data ) {
		$names = array_keys( $this->sources );
		echo '<div id="the-comment-list">' .
			'<div class="comment-item">' .
				'<h4>' . __( 'Backup options', $this->text_domain ) . '</h4>' .
				'<table class="form-table">' .
					'<tbody>' .
						'<tr valign="top">' .
							'<th scope="row"><label for="exclude">' . __( 'Exclude list', $this->text_domain ) . '</label></th>' .
							'<td><input id="exclude" name="exclude" type="text" class="regular-text code" placeholder="' .
							__( 'Comma separated paths to exclude.', $this->text_domain ) . '" value="' .
							esc_html( implode( ', ', $this->options['exclude_list'] ) ) . '" /></td>' .
						'</tr>' .
						'<tr valign="top">' .
							'<th scope="row"><label for="include">' . __( 'Include list', $this->text_domain ) . '</label></th>' .
							'<td><input id="include" name="include" type="text" class="regular-text code" placeholder="' .
							__( 'Comma separated paths to include.', $this->text_domain ) . '" value="' .
							esc_html( implode( ', ', $this->options['include_list'] ) ) . '" /></td>' .
						'</tr>' .
					'</tbody>' .
				'</table>' .
			'</div>' .
			'<div class="comment-item">' .
				'<h4>' . __( 'Notification options', $this->text_domain ) . '</h4>' .
				'<table class="form-table">' .
					'<tbody>' .
						'<tr valign="top">' .
							'<th scope="row">' . __( 'When Backup fails', $this->text_domain ) . '</th>' .
							'<td><label for="email_notify"><input id="email_notify" name="email_notify" type="checkbox" value="" ' .
							checked( true, $this->options['email_notify'], false ) . ' /> ' .
							__( 'send me an email notification.', $this->text_domain ) . '</label></td>' .
						'</tr>' .
					'</tbody>' .
				'</table>' .
			'</div>' .
			'<div class="comment-item">' .
				'<h4>' . __( 'Upload options', $this->text_domain ) . '</h4>' .
				'<table class="form-table">' .
					'<tbody>' .
						'<tr valign="top">' .
							'<th scope="row"><label for="chunk_size">' . __( 'Chunk size', $this->text_domain ) . '</label></th>' .
							'<td><input id="chunk_size" name="chunk_size" class="small-text" type="number" min="0.5" step="0.5" value="' .
							floatval( $this->options['chunk_size'] ) . '" /> <span>' .
							__( 'MB', $this->text_domain ) . '</span></td>' .
						'</tr>' .
						'<tr valign="top">' .
							'<th scope="row"><label for="time_limit">' . __( 'Time limit', $this->text_domain ) . '</label></th>' .
							'<td><input id="time_limit" name="time_limit" class="small-text" type="number" min="0" step="1" value="' .
							intval( $this->options['time_limit'] ) . '" /> <span>' .
							__( 'seconds', $this->text_domain ) . '</span></td>' .
						'</tr>' .
						'<tr valign="top">' .
							'<th scope="row"><label for="backup_attempts">' .
							__( 'Retry failed backups', $this->text_domain ) . '</label></th>' .
							'<td><input id="backup_attempts" name="backup_attempts" class="small-text" type="number" min="0" step="1" value="' .
							intval( $this->options['backup_attempts'] ) . '" /> <span>' .
							__( 'times', $this->text_domain ) . '</span></td>' .
						'</tr>' .
					'</tbody>' .
				'</table>' .
			'</div>' .
			'<div class="comment-item">' .
				'<h4>' . __( 'HTTP options', $this->text_domain ) . '</h4>' .
				'<table class="form-table">' .
					'<tbody>' .
						'<tr valign="top">' .
							'<th scope="row"><label for="request_timeout">' .
							__( 'Request timeout', $this->text_domain ) . '</label></th>' .
							'<td><input id="request_timeout" name="request_timeout" class="small-text" type="number" min="0" step="1" value="' .
							intval( $this->options['request_timeout'] ) . '" /> <span>' .
							__( 'seconds', $this->text_domain ) . '</span></td>' .
						'</tr>' .
						'<tr valign="top">' .
							'<th scope="row">' . __( 'SSL verification', $this->text_domain ) . '</th>' .
							'<td><label for="ssl_verify"><input id="ssl_verify" name="ssl_verify" type="checkbox" value="" ' .
							checked( true, $this->options['ssl_verify'], false ) . ' /> ' .
							__( 'Enable SSL verification.', $this->text_domain ) . '</label></td>' .
						'</tr>' .
						'<tr valign="top">' .
							'<th scope="row">' . __( 'Enabled transports', $this->text_domain ) . '</th>' .
							'<td>' .
								'<div class="feature-filter">' .
									'<ol class="feature-group">';
										foreach ( $this->http_transports as $transport )
											echo '<li><label for="transport_' . $transport . '"><input id="transport_' .
												$transport . '" name="transports[]" type="checkbox" value="' . $transport . '" ' .
												checked( true, in_array( $transport, $this->options['enabled_transports'] ), false ) . ' /> ' .
												$transport . '</label></li>';
		echo				  '</ol>' .
										'<div class="clear">' .
									'</div>' .
								'</td>' .
							'</tr>' .
						'</tbody>' .
					'</table>' .
				'</div>' .
			'</div>';

	}

	/**
	 * Render Log file meta box.
	 */
	function metabox_logfile_content( $data ) {
		$last_backup = $this->get_last_backup();
		$lines = @file( $last_backup['log'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( !empty( $lines ) ) {
			$header = array_shift( $lines );
			$header = substr( $header, 9 );
			$header = explode( "\t", $header );
			foreach ( $lines as $i => $l ) {
				$lines[$i] = explode( "\t", $l );
			}

			echo '<h4 class="column-posts">' . __( 'Log entries for the most recent backup attempt', $this->text_domain ) .
				'</h4><table class="widefat fixed"><thead><tr>';
			foreach ( $header as $i => $h )
				echo '<th ' . ( ( 3 == $i )? '' : 'class="column-rel"' ) . '>' . esc_html( ucfirst( $h ) ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $lines as $line ) {
				echo '<tr>';
				foreach ( $line as $l )
					echo '<td class="code">' . esc_html( $l ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		else
			echo '<p>' . __( 'There is no log file to display.', $this->text_domain ) . '</p>';
	}

	/**
	 * Validates and sanitizes user submitted options and saves them.
	 */
	function options_update() {
		if ( isset( $_GET['action'] ) && 'update' == $_GET['action'] ) {
			check_admin_referer( 'backup_options' );

			// Validate and save chunk size.
			$chunk_size = floatval( $_POST['chunk_size'] );
			if ( 0 < $chunk_size && 0 == ($chunk_size * 10) % 5 )
				$this->options['chunk_size'] = $chunk_size;
			else
				$this->messages['error'][] = __( 'The chunk size must be a multiple of 0.5 MB.', $this->text_domain );

			// Save time limit.
			$this->options['time_limit'] = absint( $_POST['time_limit'] );

			// Save max resume attempts.
			$this->options['backup_attempts'] = absint( $_POST['backup_attempts'] );

			// Validate and save local and drive numbers.
			$local_number = absint( $_POST['local_number'] );
			if ( isset( $_POST['drive_number'] ) ) {
				$drive_number = absint( $_POST['drive_number'] );
				if ( 0 == $local_number && 0 == $drive_number )
					$this->messages['error'][] = __(
						'You need to store at least one local or Drive backup.', $this->text_domain
					);
				else {
					$this->options['drive_number'] = $drive_number;
					$this->options['local_number'] = $local_number;
				}
			}
			else
				if ( 0 >= $local_number )
					$this->messages['error'][] = __( 'You need to store at least one local backup.', $this->text_domain );
				else
					$this->options['local_number'] = $local_number;

			// Handle local folder change.
			if (
				!defined( 'BACKUP_LOCAL_FOLDER' ) && isset( $_POST['local_folder'] ) &&
				$_POST['local_folder'] != $this->options['local_folder']
			) {
				$path = absolute_path( $_POST['local_folder'], ABSPATH );
				if ( ! @file_exists( $path ) || @is_file( path_join( $path, '.backup' ) ) ) {
					if ( !$this->create_dir( $path ) )
						$this->messages['error'][] = sprintf(
							__( "Could not create directory %s. You might want to create it manually and set the right permissions.",
								$this->text_domain ), '<kbd>' . esc_html( $path ) . '</kbd>'
						);
					else {
						$this->options['local_folder'] = $_POST['local_folder'];
						$this->local_folder = $path;
					}
				}
				else
					$this->messages['error'][] = sprintf(
						__( "The directory %s already exists.", $this->text_domain ), '<kbd>' . esc_html( $path ) . '</kbd>'
					);
			}

			// Handle transports.
			if ( !isset( $_POST['transports'] ) )
				$this->messages['error'][] = __( 'You cannot have all HTTP transports disabled.', $this->text_domain );
			else
				$this->options['enabled_transports'] = $_POST['transports'];

			// Handle sources and include list.
			if ( !isset( $_POST['sources'] ) && empty( $_POST['include'] ) )
				$this->messages['error'][] = __( 'Please make sure you select something to back up.', $this->text_domain );
			else {
				if ( isset( $_POST['sources'] ) )
					$this->options['source_list'] = $_POST['sources'];
				else
					$this->options['source_list'] = array();
				if ( !empty( $_POST['include'] ) ) {
					$this->options['include_list'] = explode( ',', $_POST['include'] );
					foreach ( $this->options['include_list'] as $i => $v )
						$this->options['include_list'][$i] = trim( $v );
				}
				else
					$this->options['include_list'] = array();
			}

			// Handle exlclude list.
			if ( ! empty( $_POST['exclude'] ) ) {
				$this->options['exclude_list'] = explode( ',', $_POST['exclude'] );
				foreach ( $this->options['exclude_list'] as $i => $v )
					$this->options['exclude_list'][$i] = trim( $v );
			}
			else
				$this->options['exclude_list'] = array();

			// Save Drive folder.
			if ( !defined( 'BACKUP_DRIVE_FOLDER' ) && isset( $_POST['drive_folder'] ) )
				$this->options['drive_folder'] = $_POST['drive_folder'];

			// Save backup title.
			if ( empty( $_POST['backup_title'] ) )
				$this->messages['error'][] = __( 'You need to give backups a title.', $this->text_domain );
			else
				$this->options['backup_title'] = $_POST['backup_title'];

			// Save SSL verify.
			if ( isset( $_POST['ssl_verify'] ) )
				$this->options['ssl_verify'] = true;
			else
				$this->options['ssl_verify'] = false;

			// Save email notify.
			if ( isset( $_POST['email_notify'] ) )
				$this->options['email_notify'] = true;
			else
				$this->options['email_notify'] = false;

			// Save request timeout.
			$this->options['request_timeout'] = absint( $_POST['request_timeout'] );

			// If we have any error messages to display don't go any further with the function execution.
			if ( empty( $this->messages['error'] ) )
				$this->messages['updated'][] = __( 'All changes were saved successfully.', $this->text_domain );
			else
				return;

			// Handle scheduling.
			if (
				$this->options['backup_frequency'] != $_POST['backup_frequency'] ||
				( $_POST['start_hour'] != 0 || $_POST['start_minute'] != 0 )
			) {
				// If we have already scheduled a backup before, clear it first.
				if ( wp_next_scheduled( 'backup_schedule' ) ) {
					wp_clear_scheduled_hook( 'backup_schedule' );
				}

				// Schedule backup if frequency is something else than never.
				if ( 'never' != $_POST['backup_frequency'] ) {
					// This should not be translated!
					$weekday = array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' );
					$time = strtotime( "this " . $weekday[intval( $_POST['start_day'] )] . " " .
						intval( $_POST['start_hour'] ) . ":" . intval( $_POST['start_minute'] ), $this->time );
					wp_schedule_event( $time, $_POST['backup_frequency'], 'backup_schedule' );
				}

				$this->options['backup_frequency'] = $_POST['backup_frequency'];
			}
		}
	}

	/**
	 * Find whether this is a backup process.
	 *
	 * @return boolean Are we backing up?
	 */
	private function doing_backup() {
		return defined( 'DOING_CRON' ) || isset( $_GET['doing_wp_cron'] ) ||
			( isset( $_GET['backup'] ) && $this->options['backup_token'] == $_GET['backup'] );
	}

	/**
	 * Function to initiate backup if the appropriate page is requested.
	 * It hooks to 'template_redirect'.
	 */
	function manual_backup() {
		if ( isset( $_GET['backup'] ) && $this->options['backup_token'] == $_GET['backup'] ) {
			echo '<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" dir="ltr" lang="en-US">
	<head>
		<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
		<title>' . __( 'Backup Process', $this->text_domain ) . '</title>
		<link rel="stylesheet" id="install-css"  href="' . site_url( 'wp-admin/css/install.css' ) . '" type="text/css" media="all" />
		<style>p{font-family:monospace;border-radius:3px;padding:3px;margin:6px 0}.warning{background:#ffec8b;border:1px solid #fc0}.error{background:#ffa0a0;border:1px solid #f04040}#progress{width:400px;height:30px;margin:5px auto;border:1px solid #dfdfdf;background:#f9f9f9;padding:1px;overflow:hidden}span{background:#fc0;display:inline-block;height:30px}</style>
	</head>
	<body><h1 id="logo">' . __( 'Backup Process', $this->text_domain ) . '</h1>';
			if ( isset( $_GET['retry'] ) )
				$this->retry_backup();
			else
				$this->do_backup();
			echo '</body></html>';
			exit;
		}
	}

	/**
	 * Initiates the backup procedure.
	 *
	 * @global string  $wp_version WordPress version
	 */
	public function do_backup( $id = '' ) {
		global $wp_version;

		$used_memory = memory_get_usage( true ); // Get the memory usage before we do anything.

		if ( $id ) {
			$this->log_file = $this->options['backup_list'][$id]['log'];
		}
		else {
			$id = base_convert( $this->time, 10, 36 );
			$this->log_file = $this->local_folder . '/' . $id . '.log';
			file_put_contents( $this->log_file, "#Fields:\tdate\ttime\ttype\tmessage\n" );

			$this->options['backup_list'][$id] = array(
				'timestamp' => $this->time,
				'title'     => $this->options['backup_title'] . ' (' . $id . ')',
				'file_path' => '',
				'drive_id'  => '',
				'log'       => $this->log_file,
				'attempt'   => 0,
			);

			// Save info about our new backup now, so we can have access to the log file even if MySQL connection is stolen.
			update_option( 'backup_options', $this->options );

			// Log environment information.
			if ( ! $z = phpversion( 'zip' ) )
				$z = 'false';
			$c = '';
			if ( in_array( 'curl', $this->options['enabled_transports'] ) && function_exists( 'curl_version' ) ) {
				$c = curl_version();
				$c = '; CURL ' . $c['version'];
			}
			$env = "Environment: Backup " . $this->version .
							  "; WordPress " . $wp_version .
							  "; PHP " . phpversion() .
							  "; SAPI " . php_sapi_name() .
							  "; OS " . PHP_OS .
							  "; ZIP " . $z .
							  $c;
			if ( (bool) ini_get( 'safe_mode' ) )
				$env .= "; Safe mode ON";
			$env .= "; Time limit " . ini_get( 'max_execution_time' ) . "s" .
					"; Memory limit " . ini_get( 'memory_limit' );

			$this->log( "NOTICE", $env );
		}

		// We can't hook to WP's shudown hook because we need to pass the $id.
		register_shutdown_function( array( &$this, 'handle_timeout' ), $id );

		$file_name = sanitize_file_name( $this->options['backup_list'][$id]['title'] ) . '.zip';
		$file_path = $this->local_folder . '/' . $file_name;

		if ( @is_file( $file_path ) )
			$this->options['backup_list'][$id]['file_path'] = $file_path;

		if ( empty( $this->options['backup_list'][$id]['file_path'] ) ) {
			// Check if the backup folder is writable
			if ( ( !@is_dir( $this->local_folder ) && !$this->create_dir( $this->local_folder ) ) ||
				!@is_writable( $this->local_folder ) ) {
				$this->log( 'ERROR', sprintf(
					__( "The directory '%s' does not exist or is not writable.", $this->text_domain ),
					esc_html( $this->local_folder )
				));
				$this->reschedule_backup( $id );
			}

			// Create database dump sql file.
			if ( in_array( 'database', $this->options['source_list'] ) ) {
				$this->log( 'NOTICE', __( 'Attempting to dump database.', $this->text_domain ) );

				if ( is_wp_error( $dump_time = db_dump( $this->dump_file ) ) ) {
					$this->log_wp_error( $dump_time );
					$this->reschedule_backup( $id );
				}

				$this->log( 'NOTICE', sprintf(
						__( 'The database dump was completed successfully in %s seconds.', $this->text_domain ),
						number_format_i18n( $dump_time, 3 )
				) );
			}

			// Merge the default exclude list with the user provided one and make them absolute paths.
			$exclude = array_merge( $this->options['exclude_list'], $this->exclude );
			foreach ( $exclude as $i => $path )
				if ( false !== strpos( $path, '/' ) || false !== strpos( $path, "\\" ) )
					$exclude[$i] = absolute_path( $path, ABSPATH );

			// Create the source list from the user selected sources.
			$sources = array();
			foreach ( $this->options['source_list'] as $source )
				$sources[] = $this->sources[$source]['path'];

			// Remove subdirectories from the sources.
			$count = count( $sources );
			for ( $i = 0; $i < $count; $i++ )
				for ( $j = 0; $j < $count; $j++ )
					if ( $j != $i && isset( $sources[$i] ) && isset( $sources[$j] ) &&
						is_subdir( $sources[$j], $sources[$i] ) && $this->sources['database']['path'] != $sources[$j] )
						unset( $sources[$j] );

			// Transform include paths to absolute paths.
			$include = $this->options['include_list'];
			foreach ( $include as $i => $path )
				$include[$i] = absolute_path( $path, ABSPATH );

			// Merge the include list and the sources
			$sources = array_merge( $sources, $include );

			// Create archive from the sources list.
			$this->log( 'NOTICE', sprintf(
				__( "Attempting to create archive '%s'.", $this->text_domain ),
				esc_html( $file_name )
			) );

			if ( ! phpversion( 'zip' ) )
				define( 'PCLZIP_TEMPORARY_DIR', $this->local_folder );
			if ( is_wp_error( $zip = zip( $sources, $file_path, $exclude ) ) ) {
				$this->log_wp_error( $zip );
				delete_path( $this->dump_file );
				$this->reschedule_backup( $id );
			}
			delete_path( $this->dump_file );
			$this->log( 'NOTICE', sprintf(
				__( 'Successfully archived %1$s files in %2$s seconds. Archive file size is %3$s.', $this->text_domain ),
				number_format_i18n( $zip['count'] ),
				number_format_i18n( $zip['time'], 3 ),
				size_format( filesize( $file_path ), 2 )
			) );
			$this->options['backup_list'][$id]['file_path'] = $file_path;
		}

		if ( $this->options['drive_number'] > 0 && $this->goauth->is_authorized() ) {
			if ( is_wp_error( $e = $this->need_gdocs() ) ) {
				$this->log_wp_error( $e );
				$this->reschedule_backup( $id );
			}
			if ( empty( $this->options['backup_list'][$id]['location'] ) ) {
				$this->log( 'NOTICE', __( "Attempting to upload archive to Google Drive.", $this->text_domain ) );
				$location = $this->gdocs->prepare_upload(
					$this->options['backup_list'][$id]['file_path'],
					$this->options['backup_list'][$id]['title'],
					$this->options['drive_folder']
				);
			}
			else {
				$this->log( 'NOTICE', __( 'Attempting to resume upload.', $this->text_domain ) );
				$location = $this->gdocs->resume_upload(
					$this->options['backup_list'][$id]['file_path'],
					$this->options['backup_list'][$id]['location']
				);
			}
			if ( is_wp_error( $location ) ) {
				$this->log_wp_error( $location );
				$this->reschedule_backup( $id );
			}
			if ( is_string( $location ) ) {
				$res = $location;
				$this->log( 'NOTICE', sprintf(
					__( "Uploading file with title '%s'.", $this->text_domain ),
					esc_html( $this->options['backup_list'][$id]['title'] )
				) );
				$d = 0;
				echo '<div id="progress">';
				do {
					$this->options['backup_list'][$id]['location'] = $res;
					$res = $this->gdocs->upload_chunk();
					$p = $this->gdocs->get_upload_percentage();
					if ( $p - $d >= 1 ) {
						$b = intval( $p - $d );
						echo '<span style="width:' . $b . '%"></span>';
						$d += $b;
					}
					$this->options['backup_list'][$id]['percentage'] = $p;
					$this->options['backup_list'][$id]['speed'] = $this->gdocs->get_upload_speed();
				} while ( is_string( $res ) );
				echo '</div>';

				if ( is_wp_error( $res ) ) {
					$this->log_wp_error( $res );
					$this->reschedule_backup( $id );
				}

				$this->log( 'NOTICE', sprintf(
					__(
						'The file was successfully uploaded to Google Drive in %1$s seconds at an upload speed of %2$s/s.',
						$this->text_domain
					),
					number_format_i18n( $this->gdocs->time_taken(), 3 ),
					size_format( $this->gdocs->get_upload_speed() )
				) );
				unset( $this->options['backup_list'][$id]['location'], $this->options['backup_list'][$id]['attempt'] );
			}
			elseif ( true === $location )
				$this->log( 'WARNING', sprintf(
					__( "The file '%s' is already uploaded.", $this->text_domain ),
					esc_html( $this->options['backup_list'][$id]['file_path'] )
				) );
			$this->options['backup_list'][$id]['drive_id'] = $this->gdocs->get_file_id();
			unset( $this->options['backup_list'][$id]['percentage'], $this->options['backup_list'][$id]['speed'] );
			$this->update_quota();
			if ( empty( $this->options['user_info'] ) )
				$this->set_user_info();
		}
		$this->options['backup_list'][$id]['status'] = 1;
		$this->purge_backups();

		$this->log( 'NOTICE', sprintf(
			__( 'Backup process completed in %1$s seconds.' .
				' Initial PHP memory usage was %2$s and the backup process used another %3$s of RAM.', $this->text_domain ),
			number_format_i18n( microtime( true ) - $this->time, 3 ),
			size_format( $used_memory, 2 ),
			size_format( memory_get_peak_usage( true ) - $used_memory, 2 )
		) );
	}

	/**
	 * Finds the ID of a backup that has previously failed but might still have a
	 * chanse to complete ( has a status code of 0 ) and then calls the backup process.
	 */
	function retry_backup() {
		$id = '';
		foreach ( array_reverse( $this->options['backup_list'] ) as $key => $candidate )
			if ( !$candidate['status'] ) {
				$id = $key;
				break;
			}
		if ( $id )
			$this->do_backup( $id );
	}

	/**
	 * Reschedules a backup if it wasn't attempted too many times and then exits.
	 *
	 * @param  string  $id                The ID of the backup
	 * @param  boolean $increment_attempt Optional. Should we increment the attempt?
	 */
	private function reschedule_backup( $id, $increment_attempt = true ) {
		if ( $increment_attempt )
			$this->options['backup_list'][$id]['attempt']++;
		if ( $this->options['backup_list'][$id]['attempt'] >= $this->options['backup_attempts'] ) {
			$this->options['backup_list'][$id]['status'] = -1;
			$this->options['messages']['error'][] = sprintf(
				__( 'The backup process failed on %1$s at %2$s. Please check the log file for more info.', $this->text_domain ),
				date_i18n( get_option( 'date_format' ), $this->time ),
				date_i18n( get_option( 'time_format' ), $this->time )
			);
			if ( $this->options['email_notify'] )
				$this->mail( $id );
		}
		else {
			$this->options['backup_list'][$id]['status'] = 0;
			wp_schedule_single_event( $this->time, 'backup_retry' );
		}
		exit;
	}

	/**
	 * Purge backups.
	 *
	 * The function purges all failed and unfinished backups if there are backups more recent then them.
	 * Deletes local files and Google Drive files if there are more than the wanted number.
	 * If both the local and Drive files have been removed then the log file is deleted as well as
	 * all accounts of that backup.
	 */
	private function purge_backups() {
		$count_local = 0;
		$count_drive = 0;
		$found = false;
		foreach ( array_reverse( $this->options['backup_list'] ) as $key => $backup ) {
			if ( 1 == $backup['status'] ) {
				if ( !empty( $backup['file_path'] ) )
					$count_local++;
				if ( !empty( $backup['drive_id'] ) )
					$count_drive++;
				$found = true;
			}
			elseif ( $found ) {
				unset( $this->options['backup_list'][$key] );
				if ( !empty( $backup['file_path'] ) )
					delete_path( $backup['file_path'] );
				if ( is_gdocs( $this->gdocs ) && !empty( $backup['drive_id'] ) )
					$this->gdocs->delete_resource( $backup['drive_id'] );
				delete_path( $backup['log'] );
				continue;
			}
			if ( $count_local > $this->options['local_number'] ) {
				delete_path( $this->options['backup_list'][$key]['file_path'] );
				$this->options['backup_list'][$key]['file_path'] = '';
			}
			if ( is_gdocs( $this->gdocs ) && $count_drive > $this->options['drive_number'] ) {
				$this->gdocs->delete_resource( $this->options['backup_list'][$key]['drive_id'] );
				$this->options['backup_list'][$key]['drive_id'] = '';
			}
			if (
				empty( $this->options['backup_list'][$key]['file_path'] ) &&
				empty( $this->options['backup_list'][$key]['drive_id'] )
			) {
				delete_path( $this->options['backup_list'][$key]['log'] );
				unset( $this->options['backup_list'][$key] );
			}
		}
	}

	private function get_last_backup( $completed = false ) {
		$backup = end( $this->options['backup_list'] );
		if ( !$backup )
			return false;
		if ( $completed && 1 != $backup['status'] )
			while ( $backup && 1 != $backup['status'] )
				$backup = prev( $this->options['backup_list'] );
		if ( !$backup )
			return false;
		return $backup;
	}

	/**
	 * Makes sure that we have an instance of GDocs.
	 *
	 * @return mixed Returns TRUE if we have an instance of GDocs or one has been created, a WP_Error on failure.
	 */
	private function need_gdocs() {
		if ( ! is_gdocs( $this->gdocs ) ) {
			if ( ! $this->goauth->is_authorized() )
				return new WP_Error( "not_authorized", "Account is not authorized." );

			$access_token = $this->goauth->get_access_token();
			if ( is_wp_error( $access_token ) ) {
				return $access_token;
			}

			$this->gdocs = new GDocs( $access_token );
			$this->gdocs->set_option( 'chunk_size', $this->options['chunk_size'] );
			$this->gdocs->set_option( 'time_limit', $this->options['time_limit'] );
			$this->gdocs->set_option( 'request_timeout', $this->options['request_timeout'] );
			$this->gdocs->set_option( 'max_resume_attempts', $this->options['backup_attempts'] );
		}
		return true;
	}

	/**
	 * Updates used and total quota.
	 */
	private function update_quota() {
		if ( is_wp_error( $e = $this->need_gdocs() ) ) {
			$this->log_wp_error( $e );
			return;
		}

		$quota_used = $this->gdocs->get_quota_used();
		if ( is_wp_error( $quota_used ) )
			$this->log_wp_error( $quota_used );
		else
			$this->options['quota_used'] = $quota_used;

		$quota_total = $this->gdocs->get_quota_total();
		if ( is_wp_error( $quota_total ) )
			$this->log_wp_error( $quota_total );
		else
			$this->options['quota_total'] = $quota_total;
	}

	/**
	 * Renders admin notices.
	 */
	function print_notices() {
		$ret = '';
		foreach ( $this->messages as $type => $messages ) {
			$ret .= '<div class="' . $type . '">';
			foreach ( $messages as $message )
				$ret .= '<p>' . $message . '</p>';
			$ret .= '</div>';
		}
		if ( $ret )
			echo $ret;
	}

	/**
	 * Renders persistent admin error notices
	 */
	function error_notice() {
		$msg = '<h3>Backup failed!</h3>';
		foreach ( $this->options['messages']['error'] as $m )
			$msg .= '<p>' . $m . '</p>';
		if ( isset( $_GET['page'] ) && 'backup' == $_GET['page'] )
			unset( $this->options['messages']['error'] );
		else
			$msg .= '<p>' . sprintf( __( 'Visit the %s page to hide this notification.', $this->text_domain ),
				'<a href="' . admin_url( "options-general.php?page=backup" ) . '">' .
				__( 'Backup Settings', $this->text_domain ) . '</a>' ) . '</p>';
		echo '<div class="error">' . $msg . '</div>';
	}

	/**
	 * Checks if the authorization/authentication page is requested.
	 *
	 * @return boolean Returns TRUE if the authorization page is requested, FALSE otherwise.
	 */
	function is_auth() {
		return (
			isset( $_GET['page'] ) &&
			'backup' == $_GET['page'] &&
			isset( $_GET['action'] ) &&
			'auth' == $_GET['action']
		);
	}

	/**
	 * Handles Google OAuth2 requests
	 */
	function auth() {
		if ( isset( $_GET['state'] ) ) {
			if ( 'token' == $_GET['state'] ) {
				$refresh_token = $this->goauth->request_refresh_token();
				if ( is_wp_error( $refresh_token ) ) {
					$this->messages['error'][] = __( 'Authorization failed!', $this->text_domain );
					$this->messages['error'][] = $refresh_token->get_error_message();
					return;
				}
				$this->options['refresh_token'] = $refresh_token;
				$this->messages['updated'][] = __( 'Authorization was successful.', $this->text_domain );

				// Authorization was successful, so update quota.
				$this->update_quota();
				// Request and set user_info
				$this->set_user_info();
			}
			elseif ( 'revoke' == $_GET['state'] && !defined( 'BACKUP_REFRESH_TOKEN' ) ) {
				$result = $this->goauth->revoke_refresh_token();
				if ( is_wp_error( $result ) ) {
					$this->messages['error'][] = __( 'Could not revoke authorization!', $this->text_domain );
					$this->messages['error'][] = $result->get_error_message();
					return;
				}
				$this->options['refresh_token'] = '';
				$this->options['quota_total'] = '';
				$this->options['quota_used'] = '';
				$this->options['user_info'] = array();
				$this->messages['updated'][] = __( 'Authorization has been revoked.', $this->text_domain );
			}
			return;
		}
		if ( !isset( $_POST['client_id'] ) || !isset( $_POST['client_secret'] ) ) {
			$this->messages['error'][] = __(
				"You need to specify a 'Client ID' and a 'Client secret' in order to authorize the Backup plugin.",
				$this->text_domain
			);
			return;
		}
		if ( !defined( 'BACKUP_CLIENT_ID' ) )
			$this->options['client_id'] = $_POST['client_id'];
		if ( !defined( 'BACKUP_CLIENT_SECRET' ) )
			$this->options['client_secret'] = $_POST['client_secret'];
		$this->goauth->set_options( array(
			'client_id'     => $this->options['client_id'],
			'client_secret' => $this->options['client_secret'],
			'redirect_uri'  => $this->redirect_uri
		) );
		if ( empty( $_POST['refresh_token'] ) ) {
			$this->goauth->request_authorization( $this->scope, 'token' );
			exit;
		}
		if ( !defined( 'BACKUP_REFRESH_TOKEN' ) ) {
			$this->options['refresh_token'] = $_POST['refresh_token'];
			$this->goauth->set_options( array( 'refresh_token' => $this->options['refresh_token'] ) );
		}
	}

	/**
	 * Requests and saves user info (name, email, picture) from Google's userinfo service.
	 */
	function set_user_info() {
		$token = $this->goauth->get_access_token();
		if ( is_wp_error( $token ) )
			return;
		$result = wp_remote_get( 'https://www.googleapis.com/oauth2/v1/userinfo?access_token=' . $token );
		if ( is_wp_error( $result ) )
			return;
		if ( '200' != $result['response']['code'] )
			return;
		$result = json_decode( $result['body'], true );
		$this->options['user_info'] = array(
			'email'   => $result['email'],
			'name'    => $result['name'],
			'picture' => $result['picture']
		);
	}

	private function mail( $id ) {
		$sitename = strtolower( $_SERVER['SERVER_NAME'] );
		if ( substr( $sitename, 0, 4 ) == 'www.' )
			$sitename = substr( $sitename, 4 );

		$log = @file_get_contents( $this->options['backup_list'][$id]['log'] );
		$log = substr( $log, strpos( $log, "\n" ) + 1 );

		$to = get_site_option( 'admin_email' );
		$subject = __( 'Backup failed!', $this->text_domain );
		$body = sprintf( __( 'On %1$s at %2$s the backup process failed after %3$s attempts.', $this->text_domain ),
			date_i18n( get_option( 'date_format' ), $this->time ),
			date_i18n( get_option( 'time_format' ), $this->time ),
			$this->options['backup_list'][$id]['attempt']
		) . ' ' . __( 'Following is the content of the log file for the failed backup.', $this->text_domain ) .
		"\r\n\r\n" . $log . "\r\n\r\nBackup for WordPress\r\n";
		$headers = 'From: "Backup WordPress" <backup@' . $sitename . '>' . "\r\n";
		@wp_mail( $to, $subject, $body, $headers );
	}

	function unlock() {
		delete_transient( 'backup_lock' );
	}

	/**
	 * This function saves the options in the database.
	 *
	 * It gets executed at the end of script execution, even if exit is called.
	 */
	function shutdown() {
		if ( false !== get_option( 'backup_options' ) )
			update_option( 'backup_options', $this->options );
	}

	/**
	 * This function runs at shutdown even if the script times out, so we can let the user know what the problem is.
	 *
	 * @param integer $id The ID of the current backup
	 */
	function handle_timeout( $id ) {
		// Check if the archive wasn't created
		if ( empty( $this->options['backup_list'][$id]['file_path'] ) ) {
			$this->log( "ERROR",
				__( 'Did not finish creating the backup archive. Try increasing the time limit.', $this->text_domain ) );
			$this->reschedule_backup( $id );
		}

		// Check if we have an incomplete upload
		else if ( isset( $this->options['backup_list'][$id]['percentage'] ) ) {
			echo '</div>'; // close the #progress div;
			$msg = __( 'The upload process timed out but can be resumed.', $this->text_domain ).
			sprintf(
				__( ' Managed to upload %s%% of the file.', $this->text_domain ),
				round( $this->options['backup_list'][$id]['percentage'], 2 )
			).
			sprintf(
				__( ' The upload speed was %s/s.', $this->text_domain ),
				size_format( $this->options['backup_list'][$id]['speed'] )
			);
			$this->log( "WARNING", $msg );
			$this->reschedule_backup( $id, false );
		}
	}

	/**
	 * Create the backup directory and initial files.
	 *
	 * @param string $dir
	 */
	function create_dir( $dir ) {
		if ( wp_mkdir_p( $dir ) ) {
			if (!@is_file( $dir . "/.backup" ) )
				touch( $dir . "/.backup" );
			if ( !@is_file( $dir . "/.htaccess" ) )
				file_put_contents( $dir . "/.htaccess", "Order allow,deny\nDeny from all" );
			return true;
		}
		return false;
	}

	/**
	 * Sends the first error message from a WP_Error to be written to log.
	 * @param  WP_Error $wp_error Instance of WP_Error.
	 * @return boolean            Returns TRUE on success, FALSE on failure.
	 */
	function log_wp_error( $wp_error ) {
		if ( is_wp_error( $wp_error ) ) {
			return $this->log( 'ERROR', $wp_error->get_error_message() );
		}
		return false;
	}

	/**
	 * Custom logging function for the backup plugin.
	 *
	 * @param  string $type    Type of message that we are logging. Should be 'NOTICE', 'WARNING' or 'ERROR'.
	 * @param  string $message The message we are logging.
	 * @return boolean         Returns TRUE on success, FALSE on failure.
	 */
	function log( $type, $message ) {
		$part = '<p';
		if ( 'WARNING' == $type )
			$part .= ' class="warning">';
		elseif ( 'ERROR' == $type )
			$part .= ' class="error">';
		else
			$part .= '>';
		$part .= esc_html( $message ) . '</p>';
		echo $part;
		return error_log( date_i18n( "Y-m-d\tH:i:s" ) . "\t" . $type . "\t" . $message . "\n", 3, $this->log_file );
	}
}

$backup = new Backup();

} //end if
