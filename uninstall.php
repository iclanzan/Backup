<?php

if ( !defined( 'WP_UNINSTALL_PLUGIN' ) )
	exit;

// Load helper functions
require_once( 'functions.php' );

// Unschedule events.
if ( wp_next_scheduled( 'backup_schedule' ) ) {
	wp_clear_scheduled_hook( 'backup_schedule' );
}

// Delete options.
delete_option( 'backup_options' );

$user_id  = get_current_user_id();
$pagehook = get_plugin_page_hookname( 'backup', 'options-general.php' );

// Delete all files created by the plugin.
if ( defined( 'BACKUP_LOCAL_FOLDER' ) )
	$folder = BACKUP_LOCAL_FOLDER;
else {
	$options = get_option( 'backup_options' );
	$folder = $options['local_folder'];
}
$folder = absolute_path( $folder, ABSPATH );
if ( @file_exists( $folder . '/.backup' ) )
	delete_path( $folder, true );