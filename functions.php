<?php
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

if ( !function_exists('db_dump') ) :
/**
 * Dump the WordPress database to a file.
 *
 * @param  string $dump_file Path to the file where to dump.
 * @return mixed             Returns the time taken to execute on success, WP_Error instance on failure.
 */
function db_dump( $dump_file ) {
	global $wpdb;

	$timer_start = microtime( true );

	$handle = fopen( $dump_file, 'wb' );

	if ( ! $handle )
		return new WP_Error( 'db_dump', 'Could not open ' . $dump_file . ' for writing.' );

	fwrite( $handle, "/**\n" );
	fwrite( $handle, " * SQL Dump created with Backup for WordPress\n" );
	fwrite( $handle, " *\n" );
	fwrite( $handle, " * http://hel.io/wordpress/backup\n" );
	fwrite( $handle, " */\n\n" );

	fwrite( $handle, "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n" );
	fwrite( $handle, "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n" );
	fwrite( $handle, "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n" );
	fwrite( $handle, "/*!40101 SET NAMES " . DB_CHARSET . " */;\n" );
	fwrite( $handle, "/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;\n" );
	fwrite( $handle, "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n" );
	fwrite( $handle, "/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;\n\n" );

	$tables = $wpdb->get_results( "SHOW TABLES", ARRAY_A );

	if ( empty( $tables ) )
		return new WP_Error( 'db_dump', "There are no tables in the database." );

	foreach ( $tables as $table_array ) {
		$table = current( $table_array );
		$create = $wpdb->get_var( "SHOW CREATE TABLE " . $table, 1 );
		$myisam = strpos( $create, 'MyISAM' );

		fwrite( $handle, "/* Dump of table `" . $table . "`\n" );
		fwrite( $handle, " * ------------------------------------------------------------*/\n\n" );

		fwrite( $handle, "DROP TABLE IF EXISTS `" . $table . "`;\n\n" . $create . ";\n\n" );

		$data = $wpdb->get_results("SELECT * FROM `" . $table . "` LIMIT 1000", ARRAY_A );
		if ( ! empty( $data ) ) {
			fwrite( $handle, "LOCK TABLES `" . $table . "` WRITE;\n" );
			if ( false !== $myisam )
				fwrite( $handle, "/*!40000 ALTER TABLE `".$table."` DISABLE KEYS */;\n\n" );

			$offset = 0;
			do {
				foreach ( $data as $entry ) {
					foreach ( $entry as $key => $value ) {
						if ( NULL === $value )
							$entry[$key] = "NULL";
						elseif ( "" === $value || false === $value )
							$entry[$key] = "''";
						elseif ( !is_numeric( $value ) )
							$entry[$key] = "'" . mysql_real_escape_string($value) . "'";
					}
					fwrite( $handle, "INSERT INTO `" . $table . "` ( " . implode( ", ", array_keys( $entry ) ) . " ) VALUES ( " . implode( ", ", $entry ) . " );\n" );
				}

				$offset += 1000;
				$data = $wpdb->get_results("SELECT * FROM `" . $table . "` LIMIT " . $offset . ",1000", ARRAY_A );
			} while ( ! empty( $data ) );

			if ( false !== $myisam )
				fwrite( $handle, "\n/*!40000 ALTER TABLE `".$table."` ENABLE KEYS */;" );
			fwrite( $handle, "\nUNLOCK TABLES;\n\n" );
		}
	}


	fwrite( $handle, "/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;\n" );
	fwrite( $handle, "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n" );
	fwrite( $handle, "/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;\n" );
	fwrite( $handle, "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n" );
	fwrite( $handle, "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n" );
	fwrite( $handle, "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n" );

	fclose( $handle );
	return ( microtime( true ) - $timer_start );
}
endif;

if ( !function_exists('zip') ) :
/**
 * Create a Zip archive from a file or directory.
 *
 * Uses ZipArchive with PclZip as a fallback.
 *
 * @param  array  $sources     List of paths to files and directories to be archived.
 * @param  string $destination Destination file where the archive will be stored.
 * @param  array  $exclude     Directories and/or files to exclude from archive, defaults to empty array.
 * @return mixed               Returns the time taken to execute on success or an instance of WP_Error on failure.
 */
function zip( $sources, $destination, $exclude = array() ) {
	$timer_start = microtime( true );
	if ( class_exists( 'ZipArchive' ) )
		$c = _zip_create_ziparchive( $sources, $destination, $exclude );
	else
		$c = _zip_create_pclzip( $sources, $destination, $exclude );
	if ( is_wp_error( $c ) )
		return $c;
	$zip = array(
		'count' => $c,
		'time'  => microtime( true ) - $timer_start
	);
	return $zip;
}
endif;

if ( !function_exists('_zip_create_ziparchive') ) :
/**
 * Create a zip archive using the ZipArchive class
 *
 * You should not use this function directly. Use the 'zip' function above.
 *
 * @param  array  $sources     List of paths to files and directories to be archived.
 * @param  string $destination Destination file where the archive will be stored.
 * @param  array  $exclude     Directories and/or files to exclude from archive, defaults to empty array.
 * @return mixed               Returns TRUE on success or an instance of WP_Error on failure.
 */
function _zip_create_ziparchive( $sources, $destination, $exclude = array() ) {
	$zip = new ZipArchive();
	if ( $res = $zip->open( $destination, ZIPARCHIVE::CREATE ) != true )
		return new WP_Error( 'ziparchive', $res );

	foreach ( $sources as $source ) {
		if ( ! @is_readable( $source ) )
			continue;
		if ( @is_dir( $source ) ) {
			$files = directory_list( $source, true, $exclude );
			if ( is_wp_error( $files ) ) {
				$zip->unchangeAll();
				if ( file_exists( $destination ) )
					delete_path( $destination );
				return $files;
			}
			foreach ( $files as $file ) {
				if ( ! @is_readable( $file ) )
					continue;
				if ( @is_dir( $file ) )
					$zip->addEmptyDir( str_replace( parent_dir( $source ) . '/', '', $file . '/' ) );
				elseif ( @is_file( $file ) )
					$zip->addFile( $file, str_replace( parent_dir( $source ) . '/', '', $file ) );
			}
		}
		elseif ( @is_file($source) )
			$zip->addFile( $source, basename( $source ) );
	}
	$num_files = $zip->numFiles;
	$zip_result = $zip->close();

	if ( ! $zip_result )
		return new WP_Error( 'zip', "Could not properly close archive '" . $destination . "'." );
	return $num_files;
}
endif;

if ( !function_exists('_zip_create_pclzip') ) :
/**
 * Create a zip archive using the PclZip class
 *
 * You should not use this function directly. Use the 'zip' function above.
 *
 * @param  array  $sources     List of paths to files and directories to be archived.
 * @param  string $destination Destination file where the archive will be stored.
 * @param  array  $exclude     Directories and/or files to exclude from archive, defaults to empty array.
 * @return mixed               Returns TRUE on success or an instance of WP_Error on failure.
 */
function _zip_create_pclzip( $sources, $destination, $exclude = array() ) {
	require_once( ABSPATH . 'wp-admin/includes/class-pclzip.php' );
	$zip = new PclZip( $destination );
	foreach ( $sources as $source ) {
		if ( ! @is_readable( $source ) )
			continue;
		if ( @is_dir( $source ) ) {
			$files = directory_list( $source, true, $exclude );
			if ( is_wp_error( $files ) ) {
				delete_path( $destination );
				return $files;
			}
			$res = $zip->add( $files, PCLZIP_OPT_REMOVE_PATH, parent_dir( $source ) );
			if ( 0 == $res )
				return new WP_Error( 'pclzip', $zip->errorInfo( true ) );
		}
		elseif ( @is_file( $source ) ) {
			$res = $zip->add( $source, PCLZIP_OPT_REMOVE_PATH, parent_dir( $source ) );
			if ( 0 == $res )
				return new WP_Error( 'pclzip', $zip->errorInfo( true ) );
		}
	}
	$prop = $zip->properties();
	return $prop['nb'];
}
endif;

if ( !function_exists('parent_dir') ) :
/**
 * Works out the parent directory of a path.
 *
 * @param  string $dir The directory to get the parent of.
 * @return string      Returns the parent directory or the input path if no parent can be determined.
 */
function parent_dir( $dir ) {
	$dir = rtrim($dir, '/');
	$pos = strrpos($dir, '/');
	if ( false === $pos )
		return $dir;
	return substr($dir, 0, $pos);
}
endif;

if ( !function_exists('directory_list') ) :
/**
 * Get an array containing all files and directories at a file system path.
 *
 * @param  string  $base_path Absolute or relative path to the base directory.
 * @param  array   $exclude   Array of files and directories to exclude.
 * @param  boolean $recursive Descend into subdirectories? Defaults to TRUE.
 * @return mixed              Array of all files and folders inside the base path or an instance of WP_Error.
 */
function directory_list( $base_path, $recursive = true, $exclude = array() ) {
	$base_path = trailingslashit( str_replace( '\\', '/', $base_path ));

	if ( empty( $base_path ) )
		return new WP_Error( 'empty_argument', "The directory path argument cannot be empty." );
	if ( !@is_dir( $base_path ) )
		return new WP_Error( 'invalid_argument', $base_path . " is not a directory." );

	$result_list = array();

	if ( !$folder_handle = @opendir( $base_path ) )
		return new WP_Error( 'opendir_error', "Could not open directory at: '" . $base_path . "'." );

	while ( false !== ( $filename = readdir( $folder_handle ) ) ) {
		if ( ! @is_readable( $base_path . $filename ) )
			continue;
		if ( in_array( $filename, array( ".", ".." ) ) || in_array( $filename, $exclude ) || in_array( $base_path . $filename, $exclude ) )
			continue;
		if ( @is_dir( $base_path . $filename . "/" ) ) {
			$temp_list = directory_list( $base_path . $filename . "/", $recursive, $exclude );
			if ( is_wp_error( $temp_list ) )
					return $temp_list;
			if ( empty( $temp_list ) ) {
				$result_list[] = $base_path . $filename;
				continue;
			}
			if( $recursive )
				$result_list = array_merge( $result_list, $temp_list );
		} else {
			$result_list[] = $base_path . $filename;
		}
	}
	@closedir( $folder_handle );

	return $result_list;
}
endif;

if ( !function_exists( 'relative_path' ) ) :
/**
 * Get relative path from absolute path
 *
 * Function taken from http://php.net/manual/en/function.realpath.php user contributions.
 *
 * @param  string $from Base absolute path from which to work out the relative path.
 * @param  string $to   Absolute path which will be made relative.
 * @return string       Returns a relative path.
 */
function relative_path( $from, $to ) {
	$from = str_replace( '\\', '/', $from );
	$arFrom = explode( '/', rtrim( $from, '/' ) );
	$to = str_replace( '\\', '/', $to );
	$arTo = explode( '/', rtrim( $to, '/' ) );
	while ( count( $arFrom ) && count( $arTo ) && $arFrom[0] == $arTo[0] ) {
		array_shift( $arFrom );
		array_shift( $arTo );
	}
	return str_pad( "", count( $arFrom ) * 3, '..' . '/' ) . implode( '/', $arTo );
}
endif;

if ( !function_exists( 'is_subdir' ) ) :
/**
 * Finds out if a path is a subdirectory of another path.
 *
 * @param  string  $dir The absolute path that might be a subdirectory.
 * @param  string  $of  The absolute path to check against.
 * @return boolean      Returns TRUE if the path is a subdirectory, FALSE otherwise.
 */
function is_subdir( $dir, $of ) {
	if ( !@is_dir( $dir ) || !@is_dir( $of ) )
		return false;
	if( !path_is_absolute( $dir ) || !path_is_absolute( $of ) )
		return false;
	if ( 0 === strpos( $dir, $of ) )
		return true;
	return false;
}
endif;

if ( !function_exists( 'absolute_path' ) ) :
/**
 * Transforms path to absolute filesystem path with forward slashes.
 *
 * @param  string $path Path to filter.
 * @param  string $base Absolute path to which to resolve a relative path.
 * @return mixed        Returns the filtered path on success or FALSE on failure
 */
function absolute_path( $path, $base ) {
	$path = path_join( $base, $path );
	$path = str_replace( '\\', '/', $path );
	$path = str_replace( array( '/./', '//' ), '/', $path );

	$pattern = '#\w+/\.\./#';
	while( preg_match($pattern, $path) )
		$path = preg_replace( $pattern, '', $path );

	return $path;
}
endif;

if ( !function_exists('delete_path') ) :
/**
 * Delete filesystem files and directories.
 *
 * Code taken from WP_Filesystem_Direct class.
 *
 * @param  string  $file      Path to the file or directory to delete.
 * @param  boolean $recursive Descend into subdirectories? Defaults to FALSE.
 * @return boolean            Returns TRUE on success, FALSE on failure.
 */
function delete_path($file, $recursive = false) {
	if ( empty($file) ) //Some filesystems report this as /, which can cause non-expected recursive deletion of all files in the filesystem.
		return false;
	$file = str_replace('\\', '/', $file); //for win32, occasional problems deleting files otherwise

	if ( @is_file($file) )
		return @unlink($file);
	if ( ! $recursive && @is_dir($file) )
		return @rmdir($file);

	//At this point its a folder, and we're in recursive mode
	$file = trailingslashit($file);
	$filelist = directory_list($file, true);

	$retval = true;
	if ( is_array($filelist) ) //false if no files, So check first.
		foreach ($filelist as $filename)
			if ( ! delete_path($filename, $recursive) )
				$retval = false;

	if ( file_exists($file) && ! @rmdir($file) )
		$retval = false;
	return $retval;
}
endif;

if ( !function_exists('is_gdocs') ) :
/**
 * Checks if a variable is an instance of GDocs.
 *
 * @param  mixed   $thing Variable to check.
 * @return boolean        Returns TRUE if the variable is an instance of GDocs, FALSE otherwise.
 */
function is_gdocs( $thing ) {
	if ( is_object( $thing ) && is_a( $thing, 'GDocs' ) )
		return true;
	return false;
}
endif;

if ( !function_exists('is_goauth') ) :
/**
 * Checks if a variable is an instance of GOAuth.
 *
 * @param  mixed   $thing Variable to check.
 * @return boolean        Returns TRUE if the variable is an instance of GOAuth, FALSE otherwise.
 */
function is_goauth( $thing ) {
	if ( is_object( $thing ) && is_a( $thing, 'GOAuth' ) )
		return true;
	return false;
}
endif;
