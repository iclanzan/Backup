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

/**
 * Google Docs class
 * 
 * Implements communication with Google Docs via the Google Documents List v3 API.
 *
 * Currently uploading, resuming and deleting resources is implemented as well as retrieving quotas.
 * 
 * @uses  WP_Error for storing error messages.
 */
class GDocs {
	
	/**
	 * Stores the API version.
	 * 
	 * @var string
	 * @access private
	 */
	private $gdata_version;


	/**
	 * Stores the base URL for the API requests.
	 * 
	 * @var string
	 * @access private
	 */
	private $base_url;

	/**
	 * Stores the URL to the metadata feed.
	 * @var string
	 */
	private $metadata_url;

	/**
	 * Stores the token needed to access the API.
	 * @var string
	 * @access private
	 */
	private $token;

	/**
	 * Stores feeds to avoid requesting them again for successive use.
	 * @var array
	 */
	private $cache = array();

	/**
	 * Files are uploadded in chunks of this size in bytes .
	 * @var integer
	 * @access private
	 */
	private $chunk_size;

	/**
	 * Stores the MIME type of the file that is uploading
	 * 
	 * @var string
	 * @access private
	 */
	private $upload_file_type;

	/**
	 * Stores information about uploads that might need to be resumed.
	 * 
	 * @var array
	 * @access private
	 */
	private $resume_list = array();

	/**
	 * Stores the ID of the item that will be resumed
	 * 
	 * @var array
	 * @access private
	 */
	private $resume_item_id;

	/**
	 * Stores the maximum number of resume attempts
	 * 
	 * @var integer
	 * @access private
	 */
	private $max_resume_attempts;

	/**
	 * Stores the number of seconds the upload process is allowed to run
	 * 
	 * @var integer
	 * @access private
	 */
	private $time_limit;

	/**
	 * Stores a timer for upload processes
	 * 
	 * @var array
	 */
	private $timer;

	/**
	 * Constructor - Sets the access token.
	 * 
	 * @param  string $token Access token
	 */
	function __construct( $token ) {
		$this->token = $token;
		$this->gdata_version = '3.0';
		$this->base_url = 'https://docs.google.com/feeds/default/private/full/';
		$this->metadata_url = 'https://docs.google.com/feeds/metadata/default';
		$this->chunk_size = 524288; // 512 KiB
		$this->time_limit = 120; // 2 minutes
		$this->max_resume_attempts = 5;
		$this->resume_list = get_option( 'gdocs_resume' );
		$this->timer = array(
			'start' => 0,
			'stop'  => 0,
			'delta' => 0,
			'cycle' => 0
		);
	}

	/**
	 * Sets an option.
	 *
	 * @access public
	 * @param string $option The option to set.
	 * @param mixed  $value  The value to set the option to.
	 */
	public function set_option( $option, $value ) {
		switch ( $option ) {
			case 'chunk_size':
				if ( floatval($value) >= 0.5 ) {
					$this->chunk_size = floatval($value) * 1024 * 1024; // Transform from MiB to bytes
					return true;
				}
				break;	
			case 'time_limit':
				if ( intval($value) >= 5 ) {
					$this->time_limit = intval($value);
					return true;
				}
				break;
			case 'max_resume_attempts':
				$this->max_resume_attempts = intval($value);
				return true;	
		}
		return false;
	}

	/**
	 * Gets an option.
	 *
	 * @access public
	 * @param string $option The option to get.
	 */
	public function get_option( $option ) {
		switch ( $option ) {
			case 'chunk_size':
				return $this->chunk_size;
			case 'time_limit':
				return $this->time_limit;
			case 'max_resume_attempts':
				return $this->max_resume_attempts;
		}
		return false;
	}

	/**
	 * This function makes all the requests to the API.
	 *
	 * @uses   wp_remote_request
	 * @access private
	 * @param  string $url     The URL where the request is sent.
	 * @param  string $method  The HTTP request method, defaults to 'GET'.
	 * @param  array  $headers Headers to be sent.
	 * @param  string $body    The body of the request.
	 * @return mixed           Returns an array containing the response on success or an instance of WP_Error on failure.
	 */
	private function request( $url, $method = 'GET', $headers = array(), $body = NULL ) {
		$args = array( 
			'method' => $method,
			'httpversion' => '1.1',
			'redirection' => 0,
			'headers' => array( 
				'Authorization' => 'Bearer ' . $this->token,
				'GData-Version' => $this->gdata_version
			)
		);
		if ( ! empty( $headers ) )
			$args['headers'] = array_merge( $args['headers'], $headers );
		if ( ! empty( $body ) )
			$args['body'] = $body;

		return wp_remote_request( $url, $args );
	}

	/**
	 * Returns the feed from a URL.
	 * 
	 * @access public
	 * @param  string $url The feed URL.
	 * @return mixed       Returns the feed as an instance of SimpleXMLElement on success or an instance of WP_Error on failure.
	 */
	public function get_feed( $url ) {
		if ( ! isset( $this->cache[$url] ) ) {
			$result = $this->cache_feed( $url );
			if ( is_wp_error( $result ) )
				return $result;
		}

		return $this->cache[$url];
	}

	/**
	 * Requests a feed and adds it to cache.
	 * 
	 * @access private
	 * @param  string $url The feed URL.
	 * @return mixed       Returns TRUE on success or an instance of WP_Error on failure.
	 */
	private function cache_feed( $url ) {
		$result = $this->request( $url );

	    if ( is_wp_error( $result ) )
	    	return $result;

    	if ( $result['response']['code'] == '200' ) {
    		$feed = @simplexml_load_string( $result['body'] );
    		if ( $feed === false )
    			return new WP_Error( 'invalid_data', "Could not create SimpleXMLElement from '" . $result['body'] . "'." );

    		$this->cache[$url] = $feed;
    		return true;	       
    	}
	    return new WP_Error( 'bad_response', "Received response code '" . $result['response']['code'] . " " . $result['response']['message'] . "' while trying to get '" . $url . "'. Response body: " . $result['body'] );

	}

	/**
	 * Deletes a resource from Google Docs.
	 *
	 * @access public
	 * @param  string $id Gdata Id of the resource to be deleted.
	 * @return mixed      Returns TRUE on success, an instance of WP_Error on failure.
	 */
	public function delete_resource( $id ) {
	    $headers = array( 'If-Match' => '*' );

	    $result = $this->request( $this->base_url . $id . '?delete=true', 'DELETE', $headers );
	    if ( is_wp_error( $result ) )
	    	return $result;

	    if ( $result['response']['code'] == '200' )
	        return true;
	    return new WP_Error( 'bad_response', "Received response code '" . $result['response']['code'] . " " . $result['response']['message'] . "' while trying to delete resource '" . $id . "'. The resource might not have been deleted." );
	}

	/**
	 * Get the resumable-create-media link needed to upload files.
	 *
	 * @access private
	 * @param  string $parent The Id of the folder where the upload is to be made. Default is empty string.
	 * @return mixed          Returns a link on success, instance of WP_Error on failure. 
	 */
	private function get_resumable_create_media_link( $parent = '' ) {
	    $url = $this->base_url;
	    if ( $parent )
	        $url .= $parent;

		$feed = $this->get_feed( $url );

		if ( is_wp_error( $feed ) )
			return $feed;
	    	
    	foreach ( $feed->link as $link )
            if ( $link['rel'] == 'http://schemas.google.com/g/2005#resumable-create-media' )
                return ( string ) $link['href'];
        return new WP_Error( 'not_found', "The 'resumable_create_media_link' was not found in feed." );    
	}

	/**
	 * Get used quota in bytes.
	 * 
	 * @access public
	 * @return mixed  Returns the number of bytes used in Google Docs on success or an instance of WP_Error on failure.
	 */
	public function get_quota_used() {
		$feed = $this->get_feed( $this->metadata_url );
		if ( is_wp_error( $feed ) )
			return $feed;
		return ( string ) $feed->children( "http://schemas.google.com/g/2005" )->quotaBytesUsed;
	}

	/**
	 * Get total quota in bytes.
	 * 
	 * @access public
	 * @return string|WP_Error Returns the total quota in bytes in Google Docs on success or an instance of WP_Error on failure.
	 */
	public function get_quota_total() {
		$feed = $this->get_feed( $this->metadata_url );
		if ( is_wp_error( $feed ) )
			return $feed;
		return ( string ) $feed->children( "http://schemas.google.com/g/2005" )->quotaBytesTotal;
	}

	/**
	 * Function to upload a file to Google Docs.
	 * 
	 * @uses   wp_check_filetype
	 * @access public
	 * @param  string  $file   Path to the file that is to be uploaded.
	 * @param  string  $title  Title to be given to the file.
	 * @param  string  $type   MIME type of the file to be uploaded.
	 * @param  string  $parent ID of the folder in which to upload the file.
	 * @return mixed           Returns Google Docs resource ID on success, an instance of WP_Error on failure.
	 */
	public function upload_file( $file, $title, $parent = '', $type = '' ) {

		if ( ! @is_file($file) )
			return new WP_Error('not_file', "The path '" . $file . "' does not point to a file.");

		// If a mime type wasn't passed try to guess it from the extension based on the WordPress allowed mime types
		if ( empty( $type ) ) {
			$check = wp_check_filetype( $file );
			$this->upload_file_type = $type = $check['type'];
		}

	    $size = filesize( $file );

	    $body = '<?xml version=\'1.0\' encoding=\'UTF-8\'?><entry xmlns="http://www.w3.org/2005/Atom" xmlns:docs="http://schemas.google.com/docs/2007"><category scheme="http://schemas.google.com/g/2005#kind" term="http://schemas.google.com/docs/2007#file"/><title>' . $title . '</title></entry>';
	    
	    $headers = array(
	        'Content-Type' => 'application/atom+xml',
	        'X-Upload-Content-Type' => $type,
	        'X-Upload-Content-Length' => (string) $size
	    );

	    $url = $this->get_resumable_create_media_link( $parent );
	    
	    if ( is_wp_error( $url ) )
	    	return $url;

	    $url .= '?convert=false'; // needed to upload a file
	    
	    $result = $this->request( $url, 'POST', $headers, $body );

	    if ( is_wp_error( $result ) )
	    	return $result;

	    if ( $result['response']['code'] != '200' )
	    	return new WP_Error( 'bad_response', "Received response code '" . $result['response']['code'] . " " . $result['response']['message'] . "' while trying to get '" . $url . "'." );

	    $this->resume_list[$file] = array(
    		'title'    => $title,
    		'path'     => $file,
    		'size'     => $size,
    		'location' => $result['headers']['location'],
    		'attempt'  => 0,
    		'used'     => true
        );
        update_option('gdocs_resume', $this->resume_list);

        // Start timer
		$this->timer['start'] = microtime( true );

		// Set time limit
		set_time_limit($this->time_limit);

        return $this->upload_chunks( $file, 0 );          
	}


	/**
	 * Resume an interrupted upload.
	 * 
	 * @access public
	 * @return mixed   Returns Google Docs resource ID on success, an instance of WP_Error on failure.
	 */
	public function resume_upload() {
		$id = $this->get_resume_item_id(); error_log($id."\n".var_export($this->resume_list,true));
		if( !$id )
			return new WP_Error("no_items", "There are no uploads that need to be resumed.");
		if ( ! @is_file($this->resume_list[$id]['path']) ) {
			unset($this->resume_list[$id]);
			update_option('gdocs_resume', $this->resume_list);
			return new WP_Error('not_file', "The path '" . $this->resume_list[$id]['path'] . "' does not point to a file. Upload has been canceled.");
		}

		// Mark resumable item as being in use to prevent concurrent GDocs instances from using it. 
		$this->resume_list[$id]['used'] = true;
		$this->resume_list[$id]['attempt']++;
		update_option( 'gdocs_resume', $this->resume_list );

		$headers = array( 'Content-Range' => 'bytes */' . $this->resume_list[$id]['size'] );
		$result = $this->request( $this->resume_list[$id]['location'], 'PUT', $headers );
		if( is_wp_error( $result ) )
			return $result;
		if ( $result['response']['code'] != '308' )
			return new WP_Error('bad_response', "Received response code '" . $result['response']['code'] . " " . $result['response']['message'] . "' while trying to resume the upload of file '" . $this->resume_list[$id]['title'] . "'.");
		if( isset( $result['headers']['location'] ) )
			$this->resume_list[$id]['location'] = $result['headers']['location'];
		$pointer = intval(substr( $result['headers']['range'], strpos( $result['headers']['range'], '-' ) + 1 )) + 1;

		// Start timer
		$this->timer['start'] = microtime( true );

		// Set time limit
		set_time_limit($this->time_limit);

		return $this->upload_chunks($id, $pointer);
	}

	/**
	 * Get the ID of the first item found that needs to be resumed.
	 * 
	 * @access private
	 * @return mixed   Returns a string representing the ID of the item that needs to be resumed or FALSE if there are no items.
	 */
	private function get_resume_item_id() {
		if ( empty($this->resume_item_id) ) {
			if ( !empty($this->resume_list) )
				foreach ( $this->resume_list as $id => $item )
					if ( ! $item['used'] ) {
						$this->resume_item_id = $id;
						return $id;
					}
			return false;
		}
		return $this->resume_item_id;
	}

	/**
	 * Get the item which is to be resumed.
	 * 
	 * @access public
	 * @return mixed  Returns an array with information about the item to be resumed or FALSE if there is no resumable item.
	 */
	public function get_resume_item() {
		return $this->resume_list[$this->get_resume_item_id()];
	}

	/**
	 * Recursively upload all chunks of a file.
	 * 
	 * @access private
	 * @param  string  $id      ID of the file to upload, as found in $this->resume_list.
	 * @param  integer $pointer Byte where to start the upload from.
	 * @return mixed            Returns Google Docs resource ID on success, an instance of WP_Error on failure.
	 */
	private function upload_chunks( $id, $pointer ) {
		$cycle_start = microtime(true);
		$chunk = @file_get_contents( $this->resume_list[$id]['path'], false, NULL, $pointer, $this->chunk_size );
        if ( $chunk === false )
        	return new WP_Error( 'read_error', "Failed to read from file '" . $this->resume_list[$id]['path'] . "'." );

        $chunk_size = strlen( $chunk );
        $bytes = 'bytes ' . (string)$pointer . '-' . (string)($pointer + $chunk_size - 1) . '/' . (string)$this->resume_list[$id]['size'];

        $headers = array( 'Content-Range' => $bytes );

        $result = $this->request( $this->resume_list[$id]['location'], 'PUT', $headers, $chunk );

        if ( is_wp_error( $result ) )
        	return $result;

        if ( $result['response']['code'] == '308' ) {
        	if ( isset( $result['headers']['range'] ) )
        		$pointer = intval(substr( $result['headers']['range'], strpos( $result['headers']['range'], '-' ) + 1 )) + 1;
        	else
        		$pointer += $chunk_size;
        	if ( isset( $result['headers']['location'] ) )
        		$this->resume_list[$id]['location'] = $result['headers']['location'];	
        	$this->timer['cycle'] = microtime(true) - $cycle_start;
        	if ( $this->approaching_timeout() ) {
        		$this->resume_list[$id]['used'] = false;
        		update_option('gdocs_resume', $this->resume_list);
        		return new WP_Error('resumable', "The upload process timed out but can be resumed.");
        	}	
        	else {
        		unset($chunk); // We need to unset this otherwise it will be kept in memory until the upload finishes.
        		return $this->upload_chunks( $id, $pointer );
        	}	
        }
        if ( $result['response']['code'] == '201' ) {
        	$feed = @simplexml_load_string( $result['body'] );
			if ( $feed === false )
				return new WP_Error( 'invalid_data', "Could not create SimpleXMLElement from '" . $result['body'] . "'." );

			// Stop timer
			$this->timer['stop'] = microtime(true);
			$this->timer['delta'] = $this->timer['stop'] - $this->timer['start'];
				
			unset($this->resume_item_id);
			unset( $this->resume_list[$id] );
			update_option( 'gdocs_resume', $this->resume_list );

			return substr( ( string ) $feed->children( "http://schemas.google.com/g/2005" )->resourceId, 5 );
        }

        // If we got to this point it means the upload wasn't successful.

        // Give up if we tried to resume too many times
        if ( $this->resume_list[$id]['attempt'] >= 5 ) {
        	$temp = $this->resume_list[$id];
        	unset( $this->resume_list[$id] );
			update_option( 'gdocs_resume', $this->resume_list );
			return new WP_Error( 'fail', "The upload of file '" . $temp['path'] . "' failed after trying " . $temp['attempt'] . " times." );
		}

		// Mark resumable upload as not being used
		$this->resume_list[$id]['used'] = false;
		update_option( 'gdocs_resume', $this->resume_list );
	    return new WP_Error( 'resumable', "Received response code '" . $result['response']['code'] . " " . $result['response']['message'] . "' while trying to upload a chunk of file '" . $this->resume_list[$id]['title'] . "'. The upload might be resumable." );
	}

	/**
	 * Checks if the script is nearing max execution time.
	 * 
	 * @return boolean Returns TRUE if nearing max execution time, FALSE otherwise.
	 */
	private function approaching_timeout() {
		if ( $time_limit = ini_get('max_execution_time') )
			return ( $time_limit - (microtime(true) - $this->timer['start']) < $this->timer['cycle'] );
		return false;
	}

	/**
	 * Returns the time taken for an upload to complete
	 * 
	 * @return float The number of seconds accurate to the microsecond it took for the upload to complete
	 */
	public function time_taken() {
		return $this->timer['delta'];
	}
}
