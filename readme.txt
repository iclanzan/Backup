=== Backup ===
Contributors: hel.io
Donate link: http://hel.io/donate/
Tags: backup, back up, Google Drive, Drive backup, WordPress backup
Requires at least: 3.4
Tested up to: 3.4.1
Stable tag: 2.1
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl.html

Make backups of your Wordpress site to Google Drive.

== Description ==

Version 2.0 is out and it's full of improvements and new features!

If you use this plugin and find it useful please consider [donating](http://hel.io/donate/ "Make a donation for your favorite WordPress plugin."). I have invested (and continue to do so) a lot of time and effort into making this a useful and polished product even though at the moment I have no source of income. Even a small contribution helps a lot.

Backup is a plugin that provides backup capabilities for Wordpress. Backups are `zip` archives created locally and uploaded to a folder of your choosing on Google Drive.

You are in total control of what files and directories get backed up.

== Installation ==

The plugin requires WordPress 3.4 and is installed like any other plugin.

1. Upload the plugin to the `/wp-contents/plugins/` folder.
2. Activate the plugin from the 'Plugins' menu in WordPress.
3. Configure the plugin by following the instructions on the `Backup` settings page.

If you need support configuring the plugin click on the `help` button on the top right of the settings page.

== Frequently Asked Questions ==

= Do you plan to support backing up to other services? =

I am thinking of adding more services and will probably do so depending on user demand.

= Does the plugin work with versions of WordPress below 3.4? =

Apart from not being able to purge backups from Google Drive, Backup should work well with WordPress versions 3.0 and up. I do recommend upgrading to WordPress 3.4 though.

== Screenshots ==

1. Screenshot of the Backup settings page.

== Changelog ==

= 2.1 =
* Added field to enter specific paths to include in the backup.
* Added ability to specify the day of the week and and the time when to schedule the first backup.
* Outputting progress when doing manual backup.
* Now generating separate log files for each backup.
* Added a unique token to the manual backup URI so that backups can't be deployed by anyone.
* Displaying user information for the currently authorized account. This is useful in case of multiple Google accounts when the user doesn't remember which account was authorized.
* Added option to change the request timeout value.
* Added option to disable specific HTTP transports.
* Added option to disable SSL verification against a certificate.
* Added Russian localization.
* The plugin now properly handles updates. This means you won't have to reactivate the plugin after each update.
* You can now set a time limit of 0 which will cause the backup process to run as much as it needs to.
* Made the local backup directory web inaccessible.
* Added a 'Need help?' button next to the title of the Settings page which opens the context help. Hopefuly people will stop asking questions that are already covered there.
* Fixed a bug that was causing archives to have duplicate files when using PclZip.

= 2.0.1 =
* Fixed database dump not getting added to the backup archive in some circumstances.
* Fixed not setting the time limit and chunk size when resuming uploads.
* Fixed local backups not being deleted after a failed upload.
* Fixed some bugs in the GDocs class and optimized chunk reading.
* Now logging upload speeds.
* Other minor bug fixes.

= 2.0 =
* Rewrote 95% of the plugin to make it more compatible with older PHP versions, more portable and cleaner. It now uses classes and functions already found in WordPress where possible.
* Interrupted backup uploads to Google Drive will resume automatically on the next WordPress load.
* Added internationalization support. If anyone wishes to translate the plugin feel free to do so.
* Revamped the settings page. You can now choose between one and two column layout. Added meta boxes that can be hidden, shown or closed individually as well as moved between columns.
* Added contextual help on the settings page.
* Added ability to select which WordPress directories to backup.
* Added ability to exclude specific files or directories from being backed up.
* Added option not to backup the database.
* Displaying used quota and total quota on the settings page.
* Changed the manual backup URI so that it now works for WordPress installations where pretty permalinks are disabled.
* Optimized memory usage.
* Added PclZip as a fallback for creating archives.
* Can now configure chunk sizes for Google Drive uploads.
* Added option to set time limit when uploading to Google Drive.
* You can now view the log file directly inside the settings page.

= 1.1.5 =
* You can now chose not to upload backups to Google Drive by entering `0` in the appropriate field on the settings page.
* Resumable create media link is no longer hard coded.

= 1.1.3 =
* Fixed some issues created by the `1.1.2` update.

= 1.1.2 =
* Added the ability to store a different number of backups locally then on Google Drive.
* On deactivation the plugin deletes all traces of itself (backups stored locally, options) and revokes access to the Google Account.
* Fixed some more frequency issues.

= 1.1.1 =
* Fixed monthly backup frequency.

= 1.1 =
* Added ability to backup database. Database dumps are saved to a `sql` file in the `wp-content` folder and added to the backup archive.
* Added a page ( `/backup/` ) which can be used to trigger manual backups or used in cron jobs.
* Added ability to store a maximum of `n` backups.
* Displaying dates and times of last performed backups and next scheduled backups on the settings page as well as a link to download the most recent backup and the URL for doing manual backups (and cron jobs).
* Created a separate log file to log every action and error specific to the plugin.
* Cleaned the code up a bit and added DocBlock.

= 1.0 =
* Initial release.
