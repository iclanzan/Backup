<?php
$screen = get_current_screen();

$screen->add_help_tab( array(
	'id'      => 'overview-backup-help',
	'title'   => __( 'Overview', $this->text_domain ),
	'content' =>
		'<h3>' . __( 'Backup for WordPress', $this->text_domain ) . '</h3>' .
		'<p>' . __( 'Regularly backing up a website is one of the most important duties of a webmaster and its value is only truly appreciated when things go horribly wrong (hacked website, hardware failure, software errors).', $this->text_domain ) . '</p>' .
		'<p>' . __( 'WordPress is a wonderful platform to build not just blogs, but also rich, powerful websites and web apps. Backing up a WordPress website was never the easiest of tasks but it has become quite effortless with the help of the Backup plugin.', $this->text_domain ) . '</p>' .
		'<h3>' . __( 'Backup features', $this->text_domain ) . '</h3>' .
		'<p>' . __( 'Here are some of the features of the Backup plugin:', $this->text_domain ) . '</p>' .
		'<ul>' .
			'<li>' . __( 'Back up any or all of your site\'s directories and files.', $this->text_domain ) . '</li>' .
			'<li>' . __( 'Fine-tune the contents of the backup archive by including and excluding specific files and folders.', $this->text_domain ) . '</li>' .
			'<li>' . __( 'Add a database dump to the backup archive.', $this->text_domain ) . '</li>' .
			'<li>' . __( 'Can back up locally and on Google Drive.', $this->text_domain ) . '</li>' .
			'<li>' . __( 'Supports automatic resuming of uploads to Google Drive.', $this->text_domain ) . '</li>' .
		'</ul>' .
		'<p>' . __('Rumor has it that support for uploading backups to other popular services is coming.', $this->text_domain) . '</p>'
) );

$screen->add_help_tab( array(
	'id'      => 'authorization-backup-help',
	'title'   => __( 'Authorization', $this->text_domain ),
	'content' =>
		'<p>' . sprintf(
			__( 'You can create a %1$s in the API Access section of your %2$s if you don\'t have one. A %3$s will also be generated for you as long as you select %4$s as the application type.', $this->text_domain ),
			'<strong>' . __( 'Client ID', $this->text_domain ) . '</strong>',
			'<a href="https://code.google.com/apis/console/" target="_blank">Google APIs Console</a>',
			'<strong>' . __( 'Client secret', $this->text_domain ) . '</strong>',
			'<em>' . __( 'Web Application', $this->text_domain ) . '</em>'
		) . ' ' .
		sprintf( __( 'Make sure to add %s to the list of authorized redirect URIs.', $this->text_domain ),
			'<kbd>' . $this->redirect_uri . '</kbd>'
		) . '</p>' .
		'<p>' . sprintf( __( 'If you want to use a %1$s that was previously authorized to use your Google Drive you can click on %2$s next to the %3$s button to reveal an input where you can manually enter the refresh token. Keep in mind that you still need to register the redirect URI.', $this->text_domain ),
			'<strong>' . __( 'Client ID', $this->text_domain ) . '</strong>',
			'<em>' . __( 'More', $this->text_domain ) . '</em>',
			'<em>' . __( 'Authorize', $this->text_domain ) . '</em>'
		) . '</p>'
) );

$screen->add_help_tab( array(
	'id'      => 'settings-backup-help',
	'title'   => __( 'Backup settings', $this->text_domain ),
	'content' =>
		'<p><strong>' . __( 'Backup title', $this->text_domain ) . '</strong> - ' .
			__( 'This represents the name that you want to give to your backups. The ID of the backup will be appended to this title in order to have unique names.', $this->text_domain ) .
		'</p>' .
		'<p><strong>' . __( 'Local folder path', $this->text_domain ) . '</strong> - ' .
			__('This is the path to the local filesystem directory where the plugin will store local backups, logs and other files it creates. The path has to be given absolute or relative to the WordPress root directory. Make sure the path you specify can be created by the plugin, otherwise you have to manually create it and set the right permissions for the plugin to write to it.', $this->text_domain ) .
		'</p>' .
		'<p><strong>' . __( 'Drive folder ID', $this->text_domain ) . '</strong> - ' .
			sprintf(
				__( 'This is the resource ID of the Google Drive folder where backups will be uploaded. To get a folder\'s ID navigate to that folder in Google Drive and copy the ID from your browser\'s address bar. It is the part that comes after %s.', $this->text_domain ),
				 '<kbd>#folders/</kbd>'
				) .
		'</p>' .
		'<p><strong>' . __( 'Store a maximum of', $this->text_domain ) . '</strong> - ' .
			__('You can choose to store as many backups as you want both locally and on Google Drive given you have enough free space. Once the maximum number of backups is reached, the oldest backups will get purged when creating new ones.', $this->text_domain ) .
		'</p>' .
		'<p><strong>' . __( 'What to back up', $this->text_domain ) . '</strong> - ' .
			__( 'By default the plugin backs up the content, uploads and plugins folders as well as the database. You can also select to back up the entire WordPress installation directory if you like.', $this->text_domain ) .
		'</p>' .
		'<p>' .
			__( 'On a default WordPress install the uploads and plugins folders are found inside the content folder, but they can be set up to be anywhere. Also the entire content directory can live outside the WordPress root.', $this->text_domain ) .
		'</p>' .
		'<p><strong>' . __( 'When to back up', $this->text_domain ) . '</strong> - ' .
			sprintf(
				__( 'Selecting a backup frequency other than %s will schedule backups to be performed using the WordPress cron. ', $this->text_domain ),
				'<em>' . __( 'never', $this->text_domain ) . '</em>'
			) .
			sprintf(
				__( 'If you want to do backups using a UNIX cron, you should leave %1$s selected and call the URI %2$s from a cron job.', $this->text_domain ),
				'<em>' . __( 'never', $this->text_domain ) . '</em>', '<kbd>' . home_url( '?backup' ) . '</kbd>'
			) .
		'</p>'
) );

$screen->add_help_tab( array(
	'id'      => 'advanced-backup-help',
	'title'   => __( 'Advanced settings', $this->text_domain ),
	'content' =>
		'<h3>' . __( 'Backup options', $this->text_domain ) . '</h3>' .
		'<p><strong>' . __( 'Exclude list', $this->text_domain ) . '</strong> - ' .
			sprintf(
				__( 'This is a comma separated list of files and paths to exclude from backups. Paths can be absolute or relative to the WordPress root directory. Please note that in order to exclude a directory named %1$s that is a subdirectory of the WordPress root directory you would have to input %2$s otherwise all files and directories named %1$s will be excluded.', $this->text_domain ),
				'<kbd>example</kbd>', '<kbd>./example</kbd>'
			) .
		'</p>' .
		'<p><strong>' . __( 'Include list', $this->text_domain ) . '</strong> - ' .
			__( 'This is a comma separated list of paths to include in backups. Paths can be absolute or relative to the WordPress root directory.', $this->text_domain ) .
		'</p>' .

		'<h3>' . __( 'Notification options', $this->text_domain ) . '</h3>' .
		'<p><strong>' . __( 'When Backup fails', $this->text_domain ) . '</strong> - ' .
			__( 'You can opt to receive email notifications when Backup fails.', $this->text_domain ) .
		'</p>' .

		'<h3>' . __( 'Upload options', $this->text_domain ) . '</h3>' .
		'<p><strong>' . __( 'Chunk size', $this->text_domain ) . '</strong> - ' .
			__( 'Files are split and uploaded to Google Drive in chunks of this size. Only a size that is a multiple of 0.5 MB (512 KB) is valid. I only recommend setting this to a higher value if you have a fast upload speed but take note that the PHP will use that much more memory.', $this->text_domain ) .
		'</p>' .
		'<p><strong>' . __( 'Time limit', $this->text_domain ) . '</strong> - ' .
			__('If possible this will be set as the time limit for uploading a file to Google Drive. When reaching this limit, the upload stops and an upload resume is scheduled.', $this->text_domain) .
		'</p>' .
		'<p><strong>' . __( 'Retry failed backups', $this->text_domain ) . '</strong> - ' .
			__( 'The plugin will retry to back up after a failed backup attempt this many times before giving up.', $this->text_domain ) .
		'</p>' .

		'<h3>' . __( 'HTTP options', $this->text_domain ) . '</h3>' .
		'<p><strong>' . __( 'Request timeout', $this->text_domain ) . '</strong> - ' .
			__( 'Set this to the number of seconds the HTTP transport should wait for a response before timing out. Note that if your upload speed very slow you might need to set this to a higher value.', $this->text_domain ) .
		'</p>' .
		'<p><strong>' . __( 'SSL verification', $this->text_domain ) . '</strong> - ' .
			__( 'Although not recommended, this option allows you to disable the SSL certificate verification.', $this->text_domain ) .
		'</p>' .
		'<p><strong>' . __( 'Enabled transports', $this->text_domain ) . '</strong> - ' .
			__( 'If having trouble with HTTP requests, disabling one or more of the transports might help. At least one transport must remain enabled.', $this->text_domain) .
		'</p>'
) );

$screen->set_help_sidebar(
	'<p><strong>' . __( 'For more information', $this->text_domain ) . '</strong></p>' .
	'<p><a href="http://hel.io/wordpress/backup/">' . __( 'Plugin homepage', $this->text_domain ) . '</a></p>' .
	'<p><a href="http://wordpress.org/extend/plugins/backup/">' . __( 'Plugin page on WordPress.org', $this->text_domain ) . '</a></p>' .
	'<p></p><p>' .
		sprintf(
			__(
				'If you find this plugin useful and want to support its development please consider %smaking a donation%s.',
				$this->text_domain
			),
			'<a href="http://hel.io/donate/">', '</a>'
		) .
	'</p>'
);