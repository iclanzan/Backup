<?php
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
    along with Foobar.  If not, see http://www.gnu.org/licenses/gpl.html.
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
    else {

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
        else {
            foreach ( $tables as $table_array ) {
                $table = array_shift( array_values( $table_array ) );
                $create = $wpdb->get_var( "SHOW CREATE TABLE " . $table, 1 );
                
                fwrite( $handle, "/* Dump of table `" . $table . "`\n" );
                fwrite( $handle, " * ------------------------------------------------------------*/\n\n" );
                
                fwrite( $handle, "DROP TABLE IF EXISTS `" . $table . "`;\n\n" . $create . ";\n\n" );

                $data = $wpdb->get_results("SELECT * FROM `" . $table . "`", ARRAY_A );

                if ( ! empty( $data ) ) {
                    fwrite( $handle, "LOCK TABLES `" . $table . "` WRITE;\n" );
                    if ( false !== strpos( $create, 'MyISAM' ) )
                        fwrite( $handle, "/*!40000 ALTER TABLE `".$table."` DISABLE KEYS */;\n\n" );
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
                    if ( false !== strpos( $create, 'MyISAM' ) )
                        fwrite( $handle, "\n/*!40000 ALTER TABLE `".$table."` ENABLE KEYS */;" );
                    fwrite( $handle, "\nUNLOCK TABLES;\n\n" );
                }
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
    if ( class_exists('ZipArchive') )
        $zip = _zip_create_ziparchive($sources, $destination, $exclude);
    else
        $zip = _zip_create_pclzip($sources, $destination, $exclude);
    if ( is_wp_error($zip) )
        return $zip;
    return ( microtime( true ) - $timer_start );
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

    foreach ( $sources as $source )
        if ( @is_dir($source) ) {
            $files = directory_list($source, true, $exclude);
            foreach ( $files as $file )
                if ( @is_dir($file) )
                    $zip->addEmptyDir(str_replace(parent_dir($source) . '/', '', $file . '/'));
                elseif ( @is_file($file) )
                    $zip->addFile($file, str_replace(parent_dir($source) . '/', '', $file));
        }
        elseif ( @is_file($source) )
            $zip->addFile($source, basename($source)); 

    $zip_result = $zip->close();

    if ( ! $zip_result )
        return new WP_Error( 'zip', "Could not properly close archive '" . $destination . "'." );
    return true;
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
 * @return mixed               Returns TRUE on success or an instance of WP_Error on failure.]              [description]
 */
function _zip_create_pclzip( $sources, $destination, $exclude = array() ) {
    require_once(ABSPATH . 'wp-admin/includes/class-pclzip.php');
    $zip = new PclZip($destination);
    foreach ( $sources as $source )
        if ( @is_dir($source) ) {
            $files = directory_list($source, true, $exclude);
            $res = $zip->add($files, PCLZIP_OPT_REMOVE_PATH, parent_dir($source));
            if ( 0 == $res )
                return new WP_Error('pclzip', $zip->errorInfo(true));
        }
        elseif ( @is_file($source) ) {
            $res = $zip->add($source, parent_dir($source));
            if ( 0 == $res )
                return new WP_Error('pclzip', $zip->errorInfo(true));
        }
    return true;      
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
function directory_list($base_path, $recursive = true, $exclude = array()) {
    $base_path = trailingslashit( str_replace( '\\', '/', $base_path ));

    if ( empty( $base_path ) )
        return new WP_Error( 'empty_argument', "The directory path argument cannot be empty." );
    if ( !@is_dir( $base_path ) )
        return new WP_Error( 'invalid_argument', $base_path . " is not a directory." );

    $result_list = array();

    if (!$folder_handle = @opendir($base_path)) {
        return new WP_Error( 'opendir_error', "Could not open directory at: '" . $base_path . "'." );
    }
    else {
        while ( false !== ( $filename = readdir( $folder_handle ) ) ) {
            if ( !in_array( $filename, array( ".", ".." )) && !in_array( $filename, $exclude ) && !in_array( $base_path . $filename, $exclude ) ) {
                if ( @is_dir( $base_path . $filename . "/" ) ) {
                    $result_list[] = $base_path . $filename;
                    if( $recursive ) {
                        $temp_list = directory_list( $base_path . $filename . "/", $recursive, $exclude);
                        if ( is_wp_error( $temp_list ) )
                            return $temp_list;
                        if ( ! empty( $temp_list ) )
                            $result_list = array_merge( $result_list, $temp_list );
                    }
                } else {
                    $result_list[] = $base_path . $filename;
                }
            }
        }
        @closedir($folder_handle);
        
        return $result_list;
    }
}
endif;

if ( !function_exists('relative_path') ) :
/**
 * Get relative path from absolute path
 * 
 * Function taken from http://php.net/manual/en/function.realpath.php user contributions.
 * 
 * @param  string $from Base absolute path from which to work out the relative path.
 * @param  string $to   Absolute path which will be made relative. 
 * @return string       Returns a relative path.
 */
function relative_path($from, $to) {
    $from = str_replace('\\', '/', $from);
    $arFrom = explode('/', rtrim($from, '/'));
    $to = str_replace('\\', '/', $to);
    $arTo = explode('/', rtrim($to, '/'));
    while ( count($arFrom) && count($arTo) && ($arFrom[0] == $arTo[0]) ) {
        array_shift($arFrom);
        array_shift($arTo);
    }
    return str_pad("", count($arFrom) * 3, '..'.'/').implode('/', $arTo);
}
endif;

if ( !function_exists('is_subdir') ) :
/**
 * Finds out if a path is a subdirectory of another path.
 *
 * @param  string  $dir The absolute path that might be a subdirectory.
 * @param  string  $of  The absolute path to check against.
 * @return boolean      Returns TRUE if the path is a subdirectory, FALSE otherwise.
 */
function is_subdir( $dir, $of ) {
    if( !path_is_absolute($dir) || !path_is_absolute($of) )
        return false;
    if ( 0 === strpos($dir, $of) )
        return true;
    return false;
}
endif;

if ( !function_exists('absolute_path') ) :
/**
 * Transforms path to absolute filesystem path with forward slashes. 
 * 
 * @param  string $path Path to filter.
 * @param  string $base Absolute path to which to resolve a relative path.
 * @return mixed        Returns the filtered path on success or FALSE on failure
 */
function absolute_path( $path, $base ) {
    $path = str_replace('\\', '/', $path);
    if ( path_is_absolute($path) ) // if $path is already absolute we have nothing more to do
        return $path;

    if ( ! path_is_absolute($base) ) // $base needs to be an absolute path
        return false;
    $base = trailingslashit(str_replace('\\', '/', $base));

    if ( strstr($path, '/') ) {
        $first_two = substr($path, 0, 2);
        if ( './' == $first_two )
            $path = $base . substr($path, 2);
        elseif ( '..' == $first_two ) {
            $path = $base . $path;
            $pattern = '#\w+/\.\./#';
            while( preg_match($pattern, $path) )
                $path = preg_replace($pattern, '', $path);
        }
        else
            $path = $base . $path;

    }
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

if ( !function_exists('get_tail') ) :
/**
 * Get the last lines of a file.
 * 
 * @param  string  $file        File to be read.
 * @param  integer $line_count  The number of lines to return.
 * @return array                Returns an array containing the last lines of the file.
 */
function get_tail($file, $line_count = 10) {
    if ($line_count < 1 || !@is_file($file) || !$fh = fopen($file,'r'))
        return false;

    $p = -2;
    $lines = array();

    for ( $i = 1; $i <= $line_count; $i++ ) {
        $c = "";
        while ( "\n" != $c ) {
            if ( -1 == fseek($fh, $p, SEEK_END) )
                break 2;
            $c = fgetc($fh);
            $p--;
        }
        array_push($lines, fgets($fh));
        $c = '';
    }
    fclose($fh);
    return array_reverse($lines);
}
endif;

if ( !function_exists('get_first_line') ) :
/**
 * Get the first line of a file.
 * 
 * @param  string $file Path to a file.
 * @return mixed        Returns the trimmed first line of a file on success or FALSE on failure.
 */
function get_first_line( $file ) {
    $handle = @fopen($file, "rb");
    if ( $handle ) {
        $line = fgets($handle);
        if ( $line ) {
            fclose($handle);
            return trim($line);
        }
    }
    return false;
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
