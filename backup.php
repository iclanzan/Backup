<?php
/*
Plugin Name: Backup
Version: 2.1
Plugin URI: http://hel.io/wordpress/backup/
Description: Backup your WordPress website to Google Drive.
Author: Sorin Iclanzan
Author URI: http://hel.io/
License: GPL3
Text Domain: backup
Domain Path: /languages
*/

/*  Copyright 2012 Sorin Iclanzan  (email : sorin@iclanzan.com)

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
if ( is_admin() || defined('DOING_CRON') || isset($_GET['doing_wp_cron']) || isset($_GET['backup']) || isset($_GET['resume_backup']) ) {

// Load required classes.
if ( ! class_exists('GOAuth') )
    require_once('class-goauth.php');
if ( ! class_exists('GDocs') )
    require_once('class-gdocs.php');

// Load helper functions
require_once('functions.php');

/**
 * Backup for WordPress class.
 *
 * Implements backup functionality in WordPress. Currenly supports
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
     * Stores the log file basename
     *
     * @var string
     * @access private
     */
    private $log_filename;

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

    function __construct() {
        $this->version = '2.1';
        $this->time = current_time('timestamp');
        $this->plugin_dir = dirname(plugin_basename(__FILE__));
        $this->text_domain = 'backup';
        $this->local_folder = WP_CONTENT_DIR . '/backup';

        // Enable internationalization
        load_plugin_textdomain($this->text_domain, false, $this->plugin_dir . '/languages' );

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
                'display' => __('Weekly', $this->text_domain)
            ),
            'monthly' => array(
                'interval' => 2592000,
                'display' => __('Monthly', $this->text_domain)
            )
        );
        $this->redirect_uri = admin_url('options-general.php?page=backup&action=auth');

        // Get options if they exist, else set default
        if ( ! $this->options = get_option('backup_options') ) {
            $this->options = array(
                'plugin_version'      => $this->version,
                'backup_token'        => '',
                'refresh_token'       => '',
                'local_folder'        => relative_path(ABSPATH, $this->local_folder),
                'drive_folder'        => '',
                'backup_frequency'    => 'never',
                'source_list'         => array( 'database', 'content', 'uploads', 'plugins' ),
                'exclude_list'        => array( '.svn', '.git', '.DS_Store' ),
                'include_list'        => array(),
                'client_id'           => '',
                'client_secret'       => '',
                'last_backup'         => '',
                'local_number'        => 10,
                'drive_number'        => 10,
                'local_files'         => array(),
                'drive_files'         => array(),
                'quota_total'         => '',
                'quota_used'          => '',
                'chunk_size'          => 0.5, // MiB
                'time_limit'          => 120, // seconds
                'request_timeout'     => 5, // seconds
                'enabled_transports'  => $this->http_transports,
                'ssl_verify'          => true,
                'user_info'           => array()
            );
        }
        else
            if ( !isset( $this->options['plugin_version'] ) || $this->version > $this->options['plugin_version'] )
                add_action('init', array(&$this, 'upgrade'));

        $this->local_folder = absolute_path($this->options['local_folder'], ABSPATH);
        $this->dump_file = $this->local_folder . '/dump.sql';
        $upload_dir = wp_upload_dir();

        $this->sources = array(
            'database'  => array( 'title' => __('Database',  $this->text_domain), 'path' => $this->dump_file ),
            'content'   => array( 'title' => __('Content',   $this->text_domain), 'path' => WP_CONTENT_DIR ),
            'uploads'   => array( 'title' => __('Uploads',   $this->text_domain), 'path' => $upload_dir['basedir'] ),
            'plugins'   => array( 'title' => __('Plugins',   $this->text_domain), 'path' => WP_PLUGIN_DIR ),
            'wordpress' => array( 'title' => __('WordPress', $this->text_domain), 'path' => ABSPATH )
        );

        $this->log_filename = 'backup.log';
        $this->log_file = $this->local_folder . '/' . $this->log_filename;
        $this->exclude[] = $this->local_folder;

        register_activation_hook(__FILE__, array(&$this, 'activate'));
        register_deactivation_hook(__FILE__, array(&$this, 'deactivate'));

        // Add custom cron intervals
        add_filter('cron_schedules', array(&$this, 'cron_add_intervals'));

        // Set the screen layout to use 2 columns
        add_filter('screen_layout_columns', array(&$this, 'on_screen_layout_columns'), 10, 2);

        // Link to the settings page from the plugins pange
        add_filter('plugin_action_links', array(&$this, 'action_links'), 10, 2);

        // Disable unwanted HTTP transports.
        foreach ( $this->http_transports as $t )
            if ( !in_array( $t, $this->options['enabled_transports'] ) )
                add_filter( 'use_' . $t . '_transport', '__return_false' );

        // Add 'Backup' to the Settings admin menu; save default metabox layout in the database
        add_action('admin_menu', array(&$this, 'backup_menu'));

        // Handle Google OAuth2
        if ( $this->is_auth() )
            add_action('init', array(&$this, 'auth'));

        // Enable manual backup URI
        add_action('template_redirect', array(&$this, 'manual_backup'));

        // Do backup on schedule
        add_action('backup_schedule', array(&$this, 'do_backup'));

        // Resume backup on schedule
        add_action('backup_resume', array(&$this, 'backup_resume'));

        $this->goauth = new GOAuth($this->options['client_id'], $this->options['client_secret'], $this->redirect_uri, $this->options['refresh_token']);

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
            if(!function_exists('openssl_open')) {
                throw new Exception(__('Please enable OpenSSL in PHP. Backup needs it to communicate with Google Drive.', $this->text_domain));
            }

            // check SimpleXMLElement
            if(!class_exists('SimpleXMLElement')) {
                throw new Exception(__('Please enable SimpleXMLElement in PHP. Backup could not be activated.', $this->text_domain));
            }

            // check Wordpress version
            if(version_compare($wp_version, '3.0', '<')) {
              throw new Exception(__('Backup requires WordPress 3.0 or higher!', $this->text_domain));
            }
        }
        catch(Exception $e) {
            deactivate_plugins($this->plugin_dir . '/backup.php', true);
            echo '<div id="message" class="error">' . $e->getMessage() . '</div>';
            trigger_error('Could not activate Backup.', E_USER_ERROR);
            return;
        }

        // Set the backup token here, because in the constructor 'wp_generate_password()' is not defined.
        $this->options['backup_token'] = wp_generate_password( 12, false );

        // Add the default options to the database, without letting WP autoload them
        add_option('backup_options', $this->options, '', 'no');

        // We call this here just to get the page hook
        $this->pagehook = add_options_page(__('Backup Settings', $this->text_domain), __('Backup', $this->text_domain), 'manage_options', 'backup', array(&$this, 'options_page'));

        if ( ! $this->user_id )
            $this->user_id = get_current_user_id();

        // Set the default order of the metaboxes.
        if ( ! get_user_meta($this->user_id, "meta-box-order_".$this->pagehook, true) ) {
            $meta_value = array(
                'side' => 'metabox-authorization,metabox-status',
                'normal' => 'metabox-advanced',
                'advanced' => 'metabox-logfile',
            );
            update_user_meta($this->user_id, "meta-box-order_".$this->pagehook, $meta_value);
        }

        // Set the default closed metaboxes.
        if ( ! get_user_meta($this->user_id, "closedpostboxes_".$this->pagehook, true) ) {
            $meta_value = array('metabox-advanced');
            update_user_meta($this->user_id, "closedpostboxes_".$this->pagehook, $meta_value);
        }

        // Set the default hidden metaboxes.
        if ( ! get_user_meta($this->user_id, "metaboxhidden_".$this->pagehook, true) ) {
            $meta_value = array('metabox-logfile');
            update_user_meta($this->user_id, "metaboxhidden_".$this->pagehook, $meta_value);
        }

        // Set the default number of columns.
        if ( ! get_user_meta($this->user_id, "screen_layout_".$this->pagehook, true) ) {
            update_user_meta($this->user_id, "screen_layout_".$this->pagehook, 2);
        }

        // try to create the default backup folder and backup log
        if ( !@is_dir($this->local_folder) )
            if ( wp_mkdir_p($this->local_folder) )
                if ( !@is_file($this->log_file) )
                    file_put_contents($this->log_file, "#Fields:\tdate\ttime\ttype\tmessage\n");

    }

    /**
     * Backup Deactivation.
     *
     * This function is called whenever the plugin is being deactivated and removes
     * all files and directories it created as well as the options stored in the database.
     * It also revokes access to the Google Account associated with it and removes all scheduled events created.
     */
    public function deactivate() {
        // Revoke Google OAuth2 authorization.
        if ( $this->goauth->is_authorized() )
            $this->goauth->revoke_refresh_token($this->options['refresh_token']);

        // Unschedule events.
        if ( wp_next_scheduled('backup_schedule') ) {
            wp_clear_scheduled_hook('backup_schedule');
        }

        // Delete options.
        delete_option('backup_options');

        if ( ! $this->user_id )
            $this->user_id = get_current_user_id();

        // Remove options page user meta.
        delete_user_meta($this->user_id, "meta-box-order_".$this->pagehook);
        delete_user_meta($this->user_id, "closedpostboxes_".$this->pagehook);
        delete_user_meta($this->user_id, "metaboxhidden_".$this->pagehook);
        delete_user_meta($this->user_id, "screen_layout_".$this->pagehook);

        // Delete all files created by the plugin.
        delete_path($this->local_folder, true);
    }

    /**
     * This function handles upgrading the plugin to a new version.
     * It gets triggered when the plugin version is different than the one stored in the database.
     */
    function upgrade() {
        if ( !isset( $this->options['plugin_version'] ) || $this->options['plugin_version'] < $this->version ) {
            $this->options['backup_token']        = wp_generate_password( 12, false );
            $this->options['include_list']        = array();
            $this->options['request_timeout']     = 5;
            $this->options['enabled_transports']  = $this->http_transports;
            $this->options['ssl_verify']          = true;
            $this->options['plugin_version']      = $this->version;
            $this->options['user_info']           = array();
        }
        update_option( 'backup_options', $this->options );
    }

    /**
     * Filter - Add custom schedule intervals.
     *
     * @param  array $schedules The array of defined intervals.
     * @return array            Returns the array of defined intervals after adding the custom ones.
     */
    function cron_add_intervals( $schedules ) {
        return array_merge($schedules, $this->schedules);
    }

    /**
     * Filter - Adds a 'Settings' action link on the plugins page.
     *
     * @param  array  $links The list of links.
     * @param  string $file  The plugin file to check.
     * @return array         Returns the list of links with the custom link added.
     */
    function action_links( $links, $file ) {
        if ( $file != plugin_basename(__FILE__))
            return $links;

        $settings_link = sprintf( '<a href="options-general.php?page=backup">%s</a>', __( 'Settings', $this->text_domain ) );

        array_unshift($links, $settings_link);

        return $links;
    }

    /**
     * This tells WordPress we support 2 columns on the options page.
     *
     * @param  array  $columns The array of columns.
     * @param  string $screen  The ID of the current screen.
     * @return array           Returns the array of columns.
     */
    function on_screen_layout_columns( $columns, $screen ) {
        if ($screen == $this->pagehook) {
            $columns[$this->pagehook] = 2;
        }
        return $columns;
    }

    /**
     * Action - Adds options page in the admin menu.
     */
    function backup_menu() {
        $this->pagehook = add_options_page(__('Backup Settings', $this->text_domain), __('Backup', $this->text_domain), 'manage_options', 'backup', array(&$this, 'options_page'));
        // Hook to update options
        add_action('load-'.$this->pagehook, array(&$this, 'options_update'));
        // Hook to add metaboxes
        add_action('load-'.$this->pagehook, array(&$this, 'on_load_options_page'));
    }

    /**
     * Action - Adds meta boxes and checks if the local folder is writable.
     */
    function on_load_options_page() {
        // These scripts are needed for metaboxes to function
        wp_enqueue_script('common');
        wp_enqueue_script('wp-lists');
        wp_enqueue_script('postbox');

        // Add the metaboxes
        add_meta_box('metabox-authorization', __('Authorization', $this->text_domain), array(&$this, 'metabox_authorization_content'), $this->pagehook, 'side', 'core');
        add_meta_box('metabox-status', __('Status', $this->text_domain), array(&$this, 'metabox_status_content'), $this->pagehook, 'side', 'core');
        add_meta_box('metabox-advanced', __('Advanced', $this->text_domain), array(&$this, 'metabox_advanced_content'), $this->pagehook, 'normal', 'core');
        add_meta_box('metabox-logfile', __('Log File', $this->text_domain), array(&$this, 'metabox_logfile_content'), $this->pagehook, 'advanced', 'core');

        // Add help tabs and help sidebar
        $screen = get_current_screen();
        $screen->add_help_tab(array(
            'id'      => 'overview-backup-help', // This should be unique for the screen.
            'title'   => __('Overview', $this->text_domain),
            'content' => '<h3>' . __('Backup for WordPress', $this->text_domain) . '</h3><p>' . __('Regularly backing up a website is one of the most important duties of a webmaster and its value is only truly appreciated when things go horribly wrong (hacked website, hardware failure, software errors).', $this->text_domain) . '</p>' .
                         '<p>' . __('WordPress is a wonderful platform to build not just blogs, but also rich, powerful websites and web apps. Backing up a WordPress website was never the easiest of tasks but it has become quite effortless with the help of the Backup plugin.', $this->text_domain) . '</p>' .
                         '<h3>' . __('Backup features', $this->text_domain) . '</h3><p>' . __('Here are some of the features of the Backup plugin:', $this->text_domain) . '</p>' .
                         '<ul><li>' . __('Backup any or all of your site\'s directories and files.', $this->text_domain) . '</li>' .
                         '<li>' . __('Ability to fine-tune the contents of the backup archive by excluding specific files and folders.', $this->text_domain) . '</li>' .
                         '<li>' . __('Create a database dump and add it to the backup.', $this->text_domain) . '</li>' .
                         '<li>' . __('It can back up locally and on Google Drive.', $this->text_domain) . '</li>' .
                         '<li>' . __('Supports automatic resuming of uploads to Google Drive.', $this->text_domain) . '</li></ul>' .
                         '<p>' . __('Rumor has it that support for uploading backups to other popular services is coming.', $this->text_domain) . '</p>'
        ) );
        $screen->add_help_tab(array(
            'id'      => 'authorization-backup-help', // This should be unique for the screen.
            'title'   => __('Authorization', $this->text_domain),
            'content' => '<p>' . sprintf(__('You can create a %1$sClient ID%2$s in the API Access section of your %3$s if you don\'t have one. A %1$sClient secret%2$s will also be generated for you as long as you select %4$sWeb Application%5$s as the application type.', $this->text_domain), '<strong>', '</strong>', '<a href="https://code.google.com/apis/console/" target="_blank">Google APIs Console</a>', '<em>', '</em>') . '</p>' .
                         '<p>' . sprintf(__('Make sure to add %s as the authorized redirect URI when asked.', $this->text_domain), '<kbd>' . $this->redirect_uri . '</kbd>') . '</p>'
        ) );
        $screen->add_help_tab(array(
            'id'      => 'settings-backup-help', // This should be unique for the screen.
            'title'   => __('Backup settings', $this->text_domain),
            'content' => '<p><strong>' . __('Local folder path', $this->text_domain) . '</strong> - ' . __('This is the path to the local filesystem directory where the plugin will store local backups, logs and other files it creates. The path has to be given absolute or relative to the WordPress root directory. Make sure the path you specify can be created by the plugin, otherwise you have to manually create it and set the right permissions for the plugin to write to it.', $this->text_domain) . '</p>' .
                         '<p><strong>' . __('Drive folder ID', $this->text_domain) . '</strong> - ' . sprintf(__('This is the resource ID of the Google Drive folder where backups will be uploaded. To get a folder\'s ID navigate to that folder in Google Drive and copy the ID from your browser\'s address bar. It is the part that comes after %s.', $this->text_domain), '<kbd>#folders/</kbd>' ) . '</p>' .
                         '<p><strong>' . __('Store a maximum of', $this->text_domain) . '</strong> - ' . __('You can choose to store as many backups as you want both locally and on Google Drive given you have enough free space. Once the maximum number of backups is reached, the oldest backups will get purged when creating new ones.', $this->text_domain) . '</p>' .
                         '<p><strong>' . __('When to back up', $this->text_domain) . '</strong> - ' . __('Selecting a backup frequency other than \'never\' will schedule backups to be performed using the WordPress cron. ', $this->text_domain) . sprintf(__('If you want to do backups using a real cron job, you should leave \'never\' selected and use the URI %s to set up the cron job.', $this->text_domain), '<kbd>' . home_url('?backup') . '</kbd>') . '</p>'
        ) );
        $screen->add_help_tab(array(
            'id'      => 'advanced-backup-help', // This should be unique for the screen.
            'title'   => __('Advanced settings', $this->text_domain),
            'content' => '<h3>' . __('Backup options', $this->text_domain) . '</h3>' .
                         '<p><strong>' . __('What to back up', $this->text_domain) . '</strong> - ' . __('By default the plugin backs up the content, uploads and plugins folders as well as the database. You can also select to back up the entire WordPress installation directory if you like.', $this->text_domain) . '</p>' .
                         '<p>' . __('On a default WordPress install the uploads and plugins folders are found inside the content folder, but they can be set up to be anywhere. Also the entire content directory can live outside the WordPress root.', $this->text_domain) . '</p>' .
                         '<p><strong>' . __('Exclude list', $this->text_domain) . '</strong> - ' . sprintf(__('This is a comma separated list of files and paths to exclude from backups. Paths can be absolute or relative to the WordPress root directory. Please note that in order to exclude a directory named %1$s that is a subdirectory of the WordPress root directory you would have to input %2$s otherwise all files and directories named %1$s will be excluded.', $this->text_domain), '<kbd>example</kbd>', '<kbd>./example</kbd>') . '</p>' .
                         '<p><strong>' . __('Include list', $this->text_domain) . '</strong> - ' . __('This is a comma separated list of paths to include in backups. Paths can be absolute or relative to the WordPress root directory.', $this->text_domain) . '</p>' .
                         '<h3>' . __('Upload options', $this->text_domain) . '</h3>' .
                         '<p><strong>' . __('Chunk size', $this->text_domain) . '</strong> - ' . __('Files are split and uploaded to Google Drive in chunks of this size. Only a size that is a multiple of 0.5 MB (512 KB) is valid. I only recommend setting this to a higher value if you have a fast upload speed but take note that the PHP will use that much more memory.', $this->text_domain) . '</p>' .
                         '<p><strong>' . __('Time limit', $this->text_domain) . '</strong> - ' . __('If possible this will be set as the time limit for uploading a file to Google Drive. Just before reaching this limit, the upload stops and an upload resume is scheduled.', $this->text_domain) . '</p>' .
                         '<h3>' . __('HTTP options', $this->text_domain) . '</h3>' .
                         '<p><strong>' . __('Request timeout', $this->text_domain) . '</strong> - ' . __('Set this to the number of seconds the HTTP transport should wait for a response before timing out.', $this->text_domain) . '</p>' .
                         '<p><strong>' . __('SSL verification', $this->text_domain) . '</strong> - ' . __('Although not recommended, this option allows you to disable the host\'s SSL certificate verification.', $this->text_domain) . '</p>' .
                         '<p><strong>' . __('Enabled transports', $this->text_domain) . '</strong> - ' . __('If having trouble with HTTP requests, disabling one or more of the transports might help. At least one transport must remain enabled.', $this->text_domain) . '</p>'
        ) );

        $screen->set_help_sidebar(
            '<p><strong>' . __('For more information', $this->text_domain) . '</strong></p>' .
            '<p><a href="http://hel.io/wordpress/backup/">' . __('Plugin homepage', $this->text_domain) . '</a></p>' .
            '<p><a href="http://wordpress.org/extend/plugins/backup/">' . __('Plugin page on WordPress.org', $this->text_domain) . '</a></p>' .
            '<p></p><p>' . sprintf(__('If you find this plugin useful and want to support its development please consider %smaking a donation%s.', $this->text_domain), '<a href="http://hel.io/donate/">', '</a>') . '</p>'
        );

        // Check if the local folder is writable
        if ( !@is_writable($this->local_folder) )
            $this->messages['error'][] = sprintf(__("The local path '%s' is not writable. Please change the permissions or choose another directory.", $this->text_domain), $this->local_folder);
    }

    /**
     * Display options page.
     */
    function options_page() {
        global $screen_layout_columns;
        require_once('page-options.php');
    }

    /**
     * Render Authorization meta box.
     */
    function metabox_authorization_content( $data ) {
        if ( !$this->goauth->is_authorized() ) { ?>
        <form action="<?php echo $this->redirect_uri; ?>" method="post">
            <p><?php _e('Before backups can be uploaded to Google Drive, you need to authorize the plugin and give it permission to make changes on your behalf.', $this->text_domain); ?></p>
            <p>
                <label for="client_id"><?php _e('Client ID', $this->text_domain); ?></label>
                <input id="client_id" name="client_id" type='text' style="width: 99%" value='<?php echo esc_html($this->options['client_id']); ?>' />
            </p>
                <label for="client_secret"><?php _e('Client secret', $this->text_domain); ?>
                <input id="client_secret" name='client_secret' type='text' style="width: 99%" value='<?php echo esc_html($this->options['client_secret']); ?>' />
            </p>
            <p>
                <input name="authorize" type="submit" class="button-secondary" value="<?php _e('Authorize', $this->text_domain) ?>" />
            </p>
        </form>
        <?php } else { ?>
        <?php if ( !empty( $this->options['user_info'] ) ) { ?>
            <div id="dashboard_right_now" class="column-username">
                <img src="<?php echo $this->options['user_info']['picture']; ?>" width="50" heigth="50" />
                <strong><?php echo $this->options['user_info']['name']; ?></strong><br />
                <?php echo $this->options['user_info']['email']; ?><br />
                <span class="approved"><?php _e( "Authorized", $this->text_domain ); ?></span>
            </div>
        <?php } ?>
        <p><?php _e('Authorization to use Google Drive has been granted. You can revoke it at any time by clicking the button below.', $this->text_domain); ?></p>
        <p><a href="<?php echo $this->redirect_uri; ?>&state=revoke" class="button-secondary"><?php _e('Revoke authorization', $this->text_domain); ?></a></p><?php
        }
    }

    /**
     * Render Status meta box.
     */
    function metabox_status_content( $data ) {
        $datetime_format = get_option( 'date_format' ) . " " . get_option( 'time_format' );
        echo '<div class="misc-pub-section">' . __('Current date and time:', $this->text_domain) . '<br/><strong>' .
            date_i18n( $datetime_format, $this->time ) .
        '</strong></div>' .
        '<div class="misc-pub-section">' . __('Most recent backup:', $this->text_domain) . '<br/><strong>';
            if ( $this->options['last_backup'] )
                echo date_i18n( $datetime_format, $this->options['last_backup'] );
            else
                _e('never', $this->text_domain);
        echo '</strong></div>' .
        '<div class="misc-pub-section">' . __('Next scheduled backup:', $this->text_domain) . '<br/><strong>';
            if ( $next = wp_next_scheduled('backup_schedule'))
                echo date_i18n( $datetime_format, $next );
            else
                _e('never', $this->text_domain);
        echo '</strong></div>';
        if ( $this->options['quota_used'] ) {
            echo '<div class="misc-pub-section">' . __('Google Drive quota:', $this->text_domain) . '<br/><strong>';
            printf(__('%s of %s used', $this->text_domain), size_format($this->options['quota_used']), size_format($this->options['quota_total'] ));
            echo '</strong></div>';
        }
        echo '<div class="misc-pub-section misc-pub-section-last">' . __('Manual backup URL:', $this->text_domain) . '<br/><kbd>' . home_url( '?backup=' . $this->options['backup_token'] ) . '</kbd></div><div class="clear"></div>';
    }

    /**
     * Render Advanced meta box.
     */
    function metabox_advanced_content( $data ) {
        $names = array_keys($this->sources);
        echo '<div id="the-comment-list">' .
                '<div class="comment-item">' .
                    '<h4>' . __("Backup options", $this->text_domain) . '</h4>' .
                    '<table class="form-table">' .
                        '<tbody>' .
                            '<tr valign="top">' .
                                '<th scope="row">' . __("What to back up", $this->text_domain) . '</th>' .
                                '<td>' .
                                    '<div class="feature-filter">' .
                                        '<ol class="feature-group">';
        foreach ( $this->sources as $name => $source )
            echo                            '<li><label for="source_' . $name . '" title="' . $source['path'] . '"><input id="source_' . $name . '" name="sources[]" type="checkbox" value="' . $name . '" ' . checked( true, in_array( $name, $this->options['source_list'] ), false ) . ' /> ' . $source['title'] . '</label></li>';
        echo                            '</ol>' .
                                        '<div class="clear">' .
                                    '</div>' .
                                '</td>' .
                            '</tr>' .
                            '<tr valign="top">' .
                                '<th scope="row"><label for="exclude">' . __('Exclude list', $this->text_domain) . '</label></th>' .
                                '<td><input id="exclude" name="exclude" type="text" class="regular-text code" placeholder="' . __('Comma separated paths to exclude.', $this->text_domain) . '" value="' . esc_html(implode(', ', $this->options['exclude_list'])) . '" /></td>' .
                            '</tr>' .
                            '<tr valign="top">' .
                                '<th scope="row"><label for="include">' . __('Include list', $this->text_domain) . '</label></th>' .
                                '<td><input id="include" name="include" type="text" class="regular-text code" placeholder="' . __('Comma separated paths to include.', $this->text_domain) . '" value="' . esc_html(implode(', ', $this->options['include_list'])) . '" /></td>' .
                            '</tr>' .
                        '</tbody>' .
                    '</table>' .
                '</div>' .
                '<div class="comment-item">' .
                    '<h4>' . __('Upload options', $this->text_domain) . '</h4>' .
                    '<table class="form-table">' .
                        '<tbody>' .
                            '<tr valign="top">' .
                                '<th scope="row"><label for="chunk_size">' . __('Chunk size', $this->text_domain) . '</label></th>' .
                                '<td><input id="chunk_size" name="chunk_size" class="small-text" type="number" min="0.5" step="0.5" value="' . floatval($this->options['chunk_size']) . '" /> <span>' . __("MB", $this->text_domain) . '</span></td>' .
                            '</tr>' .
                            '<tr valign="top">' .
                                '<th scope="row"><label for="time_limit">' . __('Time limit', $this->text_domain) . '</label></th>' .
                                '<td><input id="time_limit" name="time_limit" class="small-text" type="number" min="0" step="1" value="' . intval($this->options['time_limit']) . '" /> <span>' . __("seconds", $this->text_domain) . '</span></td>' .
                            '</tr>' .
                        '</tbody>' .
                    '</table>' .
                '</div>' .
                '<div class="comment-item">' .
                    '<h4>' . __('HTTP options', $this->text_domain) . '</h4>' .
                    '<table class="form-table">' .
                        '<tbody>' .
                            '<tr valign="top">' .
                                '<th scope="row"><label for="request_timeout">' . __('Request timeout', $this->text_domain) . '</label></th>' .
                                '<td><input id="request_timeout" name="request_timeout" class="small-text" type="number" min="1" step="1" value="' . intval($this->options['request_timeout']) . '" /> <span>' . __("seconds", $this->text_domain) . '</span></td>' .
                            '</tr>' .
                            '<tr valign="top">' .
                                '<th scope="row">' . __('SSL verification', $this->text_domain) . '</th>' .
                                '<td><label for="ssl_verify"><input id="ssl_verify" name="ssl_verify" type="checkbox" value="" ' . checked( true, $this->options['ssl_verify'], false ) . ' /> ' . __("Enable SSL verification.", $this->text_domain) . '</label></td>' .
                            '</tr>' .
                            '<tr valign="top">' .
                                '<th scope="row">' . __('Enabled transports', $this->text_domain) . '</th>' .
                                '<td>' .
                                    '<div class="feature-filter">' .
                                        '<ol class="feature-group">';
                                        foreach ( $this->http_transports as $transport )
            echo                            '<li><label for="transport_' . $transport . '"><input id="transport_' . $transport . '" name="transports[]" type="checkbox" value="' . $transport . '" ' . checked( true, in_array( $transport, $this->options['enabled_transports'] ), false ) . ' /> ' . $transport . '</label></li>';
        echo                            '</ol>' .
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
        $lines = get_tail($this->log_file);
        if ( !empty($lines) && '#' == substr($lines[0], 0, 1) )
            array_shift($lines);
        if ( !empty($lines) ) {
            $header = get_first_line($this->log_file);
            $header = substr($header, 9);
            $header = explode("\t", $header);
            foreach ( $lines as $i => $l ) {
                $lines[$i] = explode("\t", $l);
            }

            echo '<table class="widefat fixed"><thead><tr>';
            foreach ( $header as $i => $h )
                echo '<th ' . (( 3 == $i )? '' : 'class="column-rel"') . '>' . esc_html(ucfirst($h)) . '</th>';
            echo '</tr></thead><tbody>';
            foreach ( $lines as $line ) {
                echo '<tr>';
                foreach ( $line as $l )
                    echo '<td class="code">' . esc_html($l) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        else
            echo '<p>' . __("There is no log file to display or the file is empty.", $this->text_domain) . '</p>';
    }

    /**
     * Validates and sanitizes user submitted options and saves them.
     */
    function options_update() {
        if ( isset($_GET['action']) && 'update' == $_GET['action'] ) {
            check_admin_referer('backup_options');

            // If we dont have a valid recurrence frequency stop function execution.
            if ( !in_array($_POST['backup_frequency'], array_keys(wp_get_schedules()) )  && $_POST['backup_frequency'] != 'never' )
                wp_die(__('You were caught trying to do an illegal operation.', $this->text_domain), __('Illegal operation', $this->text_domain));

            // If we have sources that we haven't defined stop function execution.
            if ( isset($_POST['sources']) ) {
              if ( array_diff( $_POST['sources'], array_keys( $this->sources ) ) )
                wp_die(__('You were caught trying to do an illegal operation.', $this->text_domain), __('Illegal operation', $this->text_domain));
                $this->options['source_list'] = $_POST['sources'];
            }

            // Validate and save chunk size.
            if ( isset($_POST['chunk_size']) ) {
                $chunk_size = floatval($_POST['chunk_size']);
                if ( 0 < $chunk_size && 0 == ($chunk_size * 10) % 5 )
                    $this->options['chunk_size'] = $chunk_size;
                else
                    $this->messages['error'][] = __('The chunk size must be a multiple of 0.5 MB.', $this->text_domain);
            }

            // Validate and save time limit.
            if ( isset($_POST['time_limit']) ) {
                $time_limit = intval($_POST['time_limit']);
                if ( $time_limit >= 5 )
                    $this->options['time_limit'] = $time_limit;
                else
                    $this->messages['error'][] = __('The upload time limit must be at least 5 seconds.', $this->text_domain);
            }

            // Validate and save local and drive numbers.
            if ( isset($_POST['local_number']) ) {
                $local_number = intval($_POST['local_number']);
                if ( $local_number < 0 )
                    $this->messages['error'][] = __('The number of local backups to store must be a positive integer.', $this->text_domain);
                if ( isset($_POST['drive_number']) ) {
                    $drive_number = intval($_POST['drive_number']);
                    if ( $drive_number < 0 )
                        $this->messages['error'][] = __('The number of Drive backups to store must be a positive integer.', $this->text_domain);
                    elseif ( 0 == $local_number && 0 == $drive_number )
                        $this->messages['error'][] = __('You need to store at least one local or Drive backup.', $this->text_domain);
                    else {
                        $this->options['drive_number'] = $drive_number;
                        $this->options['local_number'] = $local_number;
                    }
                }
                else
                    if ( 0 >= $local_number )
                        $this->messages['error'][] = __('You need to store at least one local backup.', $this->text_domain);
                    else
                        $this->options['local_number'] = $local_number;
            }

            // Handle local folder change.
            if ( isset($_POST['local_folder']) && $_POST['local_folder'] != $this->options['local_folder'] ) {
                $path = absolute_path($_POST['local_folder'], ABSPATH);
                if ( !wp_mkdir_p($path) )
                    $this->messages['error'][] = sprintf(__('Could not create directory %s. You might want to create it manually and set the right permissions. ', $this->text_domain), '<kbd>' . $path . '</kbd>');
                elseif ( !@is_file($path . '/' . $this->log_filename) && false === file_put_contents($path . '/' . $this->log_filename, "#Fields:\tdate\ttime\ttype\tmessage\n") )
                    $this->messages['error'][] = __("Could not create log file. Please check permissions.", $this->text_domain);
                else {
                    $this->options['local_folder'] = $_POST['local_folder'];
                    $this->local_path = $path;
                }
            }

            // Handle transports
            if ( !isset( $_POST['transports'] ) )
                $this->messages['error'][] = __( "You cannot have all HTTP transports disabled.", $this->text_domain );
            else
                $this->options['enabled_transports'] = $_POST['transports'];

            // Handle exlclude list.
            if ( isset($_POST['exclude']) ) {
                $this->options['exclude_list'] = explode(',', $_POST['exclude']);
                foreach ( $this->options['exclude_list'] as $i => $v )
                    $this->options['exclude_list'][$i] = trim($v);
            }

            // Handle include list.
            if ( isset( $_POST['include'] ) ) {
                if ( !empty( $_POST['include'] ) ) {
                    $this->options['include_list'] = explode(',', $_POST['include']);
                    foreach ( $this->options['include_list'] as $i => $v )
                        $this->options['include_list'][$i] = trim($v);
                }
                else
                    $this->options['include_list'] = array();
            }

            // If we have any error messages to display don't go any further with the function execution.
            if ( empty($this->messages['error']) )
                $this->messages['updated'][] = __('All changes were saved successfully.', $this->text_domain);
            else
                return;

            if ( isset($_POST['drive_folder']) )
                $this->options['drive_folder'] = $_POST['drive_folder'];

            if ( isset( $_POST['ssl_verify'] ) )
                $this->options['ssl_verify'] = true;
            else
                $this->options['ssl_verify'] = false;

            $this->options['request_timeout'] = intval( $_POST['request_timeout'] );

            // Handle scheduling.
            if ( $this->options['backup_frequency'] != $_POST['backup_frequency'] || ( $_POST['start_hour'] != 0 || $_POST['start_minute'] != 0 ) ) {
                // If we have already scheduled a backup before, clear it first.
                if ( wp_next_scheduled('backup_schedule') ) {
                    wp_clear_scheduled_hook('backup_schedule');
                }

                // Schedule backup if frequency is something else than never.
                if ( 'never' != $_POST['backup_frequency'] ) {
                    // This should not be translated!
                    $weekday = array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' );
                    $time = strtotime( "this " . $weekday[intval( $_POST['start_day'] )] . " " . intval( $_POST['start_hour'] ) . ":" . intval( $_POST['start_minute'] ), $this->time );
                    wp_schedule_event($time, $_POST['backup_frequency'], 'backup_schedule');
                }

                $this->options['backup_frequency'] = $_POST['backup_frequency'];
            }

            // Updating options in the database.
            update_option('backup_options', $this->options);
        }
    }

    /**
     * Function to initiate backup if the appropriate page is requested.
     * It hooks to 'template_redirect'.
     */
    function manual_backup() {
        if ( isset($_GET['backup']) && $this->options['backup_token'] == $_GET['backup'] ) {
            echo '<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" dir="ltr" lang="en-US"><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8" /><title>Backup Process</title><link rel="stylesheet" id="install-css"  href="http://songpane.com/wp-admin/css/install.css" type="text/css" media="all" /></head><body><style>p{font-family:monospace;border-radius:3px;padding:3px;margin:6px 0}.warning{background:#ffec8b;border:1px solid #fc0}.error{background:#ffa0a0;border:1px solid #f04040}#progress{width:400px;height:30px;margin:5px auto;border:1px solid #dfdfdf;background:#f9f9f9;padding:1px;overflow:hidden}span{background:#fc0;display:inline-block;height:30px}</style><h1 id="logo">Backup Process</h1>';
            if ( isset( $_GET['resume'] ) )
                $this->backup_resume();
            else
                $this->do_backup();
            echo '</body></html>';
            exit;
        }
    }

    function always_flush() {
        if( !isset( $this->$flushed ) ) {
            // All this is needed in order that every echo gets sent to the browser.
            // Code taken from php.net user contributed notes.
            @apache_setenv('no-gzip', 1);
            @ini_set('zlib.output_compression', 0);
            @ini_set('implicit_flush', 1);
            wp_ob_end_flush_all();
            ob_implicit_flush(1);
            $this->flushed = true;
        }
    }

    /**
     * Initiates the backup procedure.
     */
    public function do_backup() {
        $this->log_file = $this->local_folder . '/' . $this->time . '.log';
        $this->always_flush();

        // Check if the backup folder is writable
        if ( !( @is_writable($this->local_folder) ) ) {
            $this->log('ERROR', "The directory '" . $this->local_folder . "' does not exist or it is not writable.");
            exit;
        }

        // Measure the time this function takes to complete.
        $start = microtime(true);
        // Get the memory usage before we do anything.
        $initial_memory = memory_get_usage(true);
        // We might need a lot of memory for this
        @ini_set('memory_limit', apply_filters('admin_memory_limit', WP_MAX_MEMORY_LIMIT));
        // Set the time limit. It might be needed for the archive creation process.
        @set_time_limit($this->options['time_limit']);

        $file_name = $this->time . '.zip';
        $file_path = $this->local_folder . '/' . $file_name;

        // Create database dump sql file.
        if ( in_array('database', $this->options['source_list']) ) {
            $this->log('NOTICE', "Attempting to dump database.");
            $dump_time = db_dump($this->dump_file);

            if ( is_wp_error($dump_time) ) {
                $this->log_wp_error($dump_time);
                exit;
            }

            $this->log('NOTICE', "The database dump was completed successfully in " . round($dump_time, 2) . " seconds.");
        }

        $exclude = array_merge($this->options['exclude_list'], $this->exclude);
        foreach ( $exclude as $i => $path )
            if ( false !== strpos( $path, '/' ) || false !== strpos( $path, "\\" ) )
                $exclude[$i] = absolute_path($path, ABSPATH);

        $sources = array();
        foreach ( $this->options['source_list'] as $source )
            $sources[] = $this->sources[$source]['path'];

        // Remove subdirectories.
        $count = count($sources);
        for ( $i = 0; $i < $count; $i++ )
            for ( $j = 0; $j < $count; $j++ )
                if ( $j != $i && isset($sources[$i]) && isset($sources[$j]) &&
                    is_subdir($sources[$j], $sources[$i]) && $this->sources['database']['path'] != $sources[$j] )
                    unset($sources[$j]);

        $include = $this->options['include_list'];
        foreach ( $include as $i => $path )
            $include[$i] = absolute_path( $path, ABSPATH );

        $sources = array_merge( $sources, $include );

        // Create archive from all enabled sources.
        $this->log('NOTICE', "Attempting to create archive '" . $file_path . "'.");
        $zip_time = zip($sources, $file_path, $exclude);

        if ( is_wp_error($zip_time) ) {
            $this->log_wp_error($zip_time);
            exit;
        }

        $this->log('NOTICE', 'Archive created successfully in ' . round($zip_time, 2) . ' seconds. Archive file size is ' . size_format( filesize( $file_path ) ) . '.');
        delete_path($this->dump_file);

        if ( $this->options['drive_number'] > 0 && $this->goauth->is_authorized() ) {
            if ( is_wp_error( $e = $this->need_gdocs() ) ) {
                delete_path( $file_path );
                $this->log_wp_error( $e );
                exit;
            }
            $this->log('NOTICE', 'Attempting to upload archive to Google Drive.');
            if ( is_wp_error( $e = $this->gdocs->prepare_upload( $file_path, $file_name, $this->options['drive_folder'] ) ) ) {
                $this->log_wp_error($e);
                delete_path( $file_path );
                exit;
            }
            $this->backup_resume();
        }
        else {
            $this->options['local_files'][] = $file_path;
            $this->options['last_backup'] = $this->time;
            $this->purge_local_files();
            // Update options in the database.
            update_option('backup_options', $this->options);
        }

        // Get memory peak usage.
        $peak_memory = memory_get_peak_usage(true);
        $this->log('NOTICE', 'Backup process completed in ' . round(microtime(true) - $start, 2) . ' seconds. Initial PHP memory usage was ' . size_format( $initial_memory, 2 ) . ' and the backup process used another ' . size_format( $peak_memory - $initial_memory, 2 ) .' of RAM.');
    }

    /**
     * Resumes an interrupted backup upload.
     */
    public function backup_resume() {
        $this->always_flush();
        if ( is_wp_error( $e = $this->need_gdocs()) ) {
            $this->log_wp_error( $e );
            exit;
        }

        $file = $this->gdocs->get_resume_item();
        if ( false === $file ) {
            $this->log("WARNING", "There is no upload to resume.");
            exit;
        }

        $this->log_file = $this->local_folder . '/' . substr( $file['path'], -14, 10 ) . '.log';

        $id = $this->gdocs->resume_upload();
        if ( true === $id ) {
            $this->log('NOTICE', "Uploading file '" . $file['title'] . "'.");
            $d = 0;
            echo '<div id="progress">';
            do {
                if ( is_string( $id = $this->gdocs->upload_chunk() ) )
                    $p = 100;
                else
                    $p = $this->gdocs->get_upload_percentage();
                if ( $p - $d >= 1 ) {
                    $b = intval($p - $d);
                    echo '<span style="width:' . $b . '%"></span>';
                    $d += $b;
                }
            } while ( true === $id );
            echo '</div>';
        }

        if ( is_wp_error($id) ) {
            if ( $this->gdocs->is_resumable() ) {
                $mess = $id->get_error_message();
                if ( $percent = $this->gdocs->get_upload_percentage() )
                    $mess .= ' Managed to upload ' . round( $percent, 2 ) . '% of the file.';
                if ( $speed = size_format( $this->gdocs->get_upload_speed() ) )
                    $mess .= ' The upload speed was ' . $speed . '/s.';
                $this->log("WARNING", $mess );
                wp_schedule_single_event($this->time, 'backup_resume');
                exit;
            }

            $this->log_wp_error($id);
            delete_path( $file['path'] );
            exit;
        }

        $this->log('NOTICE', "Archive '" . $file['title'] . "' was successfully uploaded to Google Drive in " . round($this->gdocs->time_taken(), 2) . " seconds at an upload speed of " . size_format( $this->gdocs->get_upload_speed() ) . "/s.");
        $this->options['local_files'][] = $file['path'];
        $this->options['drive_files'][] = $id;
        $this->options['last_backup'] = substr($file['title'], 0, strpos($file['title'], '.')); // take the time from the title
        // Update quotas if uploading to Google Drive was successful.
        $this->update_quota();
        $this->purge_local_files();
        $this->purge_drive_files();
        // Updating options in the database.
        update_option('backup_options', $this->options);
    }

    private function need_gdocs() {
        if ( ! is_gdocs($this->gdocs) ) {
            if ( ! $this->goauth->is_authorized() )
                return new WP_Error( "not_authorized", "Account is not authorized." );

            $access_token = $this->goauth->get_access_token();
            if ( is_wp_error($access_token) ) {
                return $access_token;
            }

            $this->gdocs = new GDocs($access_token);
            $this->gdocs->set_option('chunk_size', $this->options['chunk_size']);
            $this->gdocs->set_option('time_limit', $this->options['time_limit']);
            $this->gdocs->set_option('request_timeout', $this->options['request_timeout']);
        }
        return true;
    }

    /**
     * Purge Google Drive backup files.
     */
    private function purge_drive_files() {
        if ( is_gdocs($this->gdocs) )
            while ( count($this->options['drive_files']) > $this->options['drive_number'] ) {
                $result = $this->gdocs->delete_resource($r = array_shift($this->options['drive_files']));
                if ( is_wp_error($result) )
                    $this->log_wp_error($result);
                else
                    $this->log('NOTICE', "Deleted Google Drive file '" . $r . "'.");
            }
        return new WP_Error('missing_gdocs', "An instance of GDocs is needed to delete Google Drive resources.");
    }

    /**
     * Purge local filesystem backup files.
     */
    private function purge_local_files() {
        while ( count($this->options['local_files']) > $this->options['local_number'] )
            if ( delete_path($f = array_shift($this->options['local_files'])) ) {
                $this->log('NOTICE', "Purged backup file '" . $f . "'.");
                delete_path( $g = substr( $f, 0, strlen( $f ) - 4 ) . '.log' );
            }
            else
                $this->log('WARNING', "Could not delete file '" . $f . "'.");
    }

    /**
     * Updates used and total quota.
     */
    private function update_quota() {
        if ( is_gdocs($this->gdocs) ) {
            $quota_used = $this->gdocs->get_quota_used();
            if ( is_wp_error($quota_used) )
                $this->log_wp_error($quota_used);
            else
                $this->options['quota_used'] = $quota_used;

            $quota_total = $this->gdocs->get_quota_total();
            if ( is_wp_error($quota_total) )
                $this->log_wp_error($quota_total);
            else
                $this->options['quota_total'] = $quota_total;
        }
        else return new WP_Error('missing_gdocs', "An instance of GDocs is needed to update quota usage.");
    }

    /**
     * Renders messages.
     */
    private function get_messages_html() {
        $ret = '';
        foreach ( array_keys($this->messages) as $type ) {
            $ret .= '<div class="' . $type . '">';
            foreach ( $this->messages[$type] as $message )
                $ret .= '<p>' . $message . '</p>';
            $ret .= '</div>';
        }
        return $ret;
    }

    /**
     * Checks if the authorization/authentication page is requested.
     *
     * @return boolean Returns TRUE if the authorization page is requested, FALSE otherwise.
     */
    function is_auth() {
        return ( isset( $_GET['page'] ) && 'backup' == $_GET['page'] && isset( $_GET['action'] ) && 'auth' == $_GET['action']);
    }

    /**
     * Handles Google OAuth2 requests
     */
    function auth() {
        if ( isset($_GET['state']) ) {
            if ( 'token' == $_GET['state'] ) {
                $refresh_token = $this->goauth->request_refresh_token();
                if ( is_wp_error($refresh_token) ) {
                    $this->messages['error'][] = __("Authorization failed!", $this->text_domain);
                    $this->messages['error'][] = $refresh_token->get_error_message();
                }
                else {
                    $this->options['refresh_token'] = $refresh_token;
                    $this->messages['updated'][] = __("Authorization was successful.", $this->text_domain);

                    // Authorization was successful, so create an instance of GDocs and update quota.
                    $this->gdocs = new GDocs($this->goauth->get_access_token());
                    $result = $this->update_quota();
                    // Request and set user_info
                    $this->set_user_info();
                    update_option('backup_options', $this->options);
                }
            }
            elseif ( 'revoke' == $_GET['state'] ) {
                $result = $this->goauth->revoke_refresh_token();
                if ( is_wp_error($result) ) {
                    $this->messages['error'][] = __("Could not revoke authorization!", $this->text_domain);
                    $this->messages['error'][] = $result->get_error_message();
                }
                else {
                    $this->options['refresh_token'] = '';
                    update_option('backup_options', $this->options);
                    $this->messages['updated'][] = __("Authorization has been revoked.", $this->text_domain);
                }
            }
        }
        else {
            if ( !isset($_POST['client_id']) || !isset($_POST['client_secret']) )
                $this->messages['error'][] = __("You need to specify a 'Client ID' and a 'Client secret' in order to authorize the Backup plugin.", $this->text_domain);
            else {
                $this->options['client_id'] = $_POST['client_id'];
                $this->options['client_secret'] = $_POST['client_secret'];
                update_option('backup_options', $this->options);
                $this->goauth = new GOAuth($this->options['client_id'], $this->options['client_secret'], $this->redirect_uri);
                $res = $this->goauth->request_authorization($this->scope, 'token');
                if ( is_wp_error($res) )
                    $this->messages['error'][] = $res->get_error_message();
                exit;
            }
        }
    }

    function set_user_info() {
        if ( is_wp_error( $result = wp_remote_get( 'https://www.googleapis.com/oauth2/v1/userinfo?access_token=' . $this->goauth->get_access_token() ) ) )
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

    /**
     * Sends the first error message from a WP_Error to be written to log.
     * @param  WP_Error $wp_error Instance of WP_Error.
     * @return boolean            Returns TRUE on success, FALSE on failure.
     */
    function log_wp_error( $wp_error ) {
        if ( is_wp_error($wp_error) ) {
            return $this->log('ERROR', $wp_error->get_error_message() . ' (' . $wp_error->get_error_code() . ')');
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
        $part .= $message . '</p>';
        echo $part;
        return error_log(date("Y-m-d\tH:i:s") . "\t" . $type . "\t" . $message . "\n", 3, $this->log_file);
    }
}

$backup = new Backup();

} //end if
