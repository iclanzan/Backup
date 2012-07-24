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

/**
 * Google OAuth2 class
 *
 * This class handles operations realted to Google's OAuth2 service.
 *
 * @uses  WP_Error for storing error messages.
 */
class GOAuth {

	/**
	 * Stores the base url to Google's OAuth2 server.
	 *
	 * @var string
	 * @access  private
	 */
	private $base_url = 'https://accounts.google.com/o/oauth2/';

	/**
	 * Stores the Client Id.
	 *
	 * @var string
	 * @access  private
	 */
	private $client_id;

	/**
	 * Stores the Client Secret.
	 *
	 * @var string
	 * @access  private
	 */
	private $client_secret;

	/**
	 * Stores the redirect URI.
	 *
	 * @var string
	 * @access  private
	 */
	private $redirect_uri;

	/**
	 * Stores the refresh token.
	 *
	 * @var string
	 * @access private
	 */
	private $refresh_token;

	/**
	 * Stores the access token.
	 *
	 * @var string
	 * @access private
	 */
	private $access_token;

	/**
	 * Stores the number of seconds to wait for a response before timing out.
	 *
	 * @var integer
	 * @access private
	 */
	private $request_timeout;

	/**
	 * Stores whether or not to verify host SSL certificate.
	 *
	 * @var boolean
	 * @access private
	 */
	private $ssl_verify;

	/**
	 * Constructor - Assigns values to some properties.
	 *
	 * @param array $args Optional. The list of options and values to set
	 */
	function __construct( $args = array() ) {
		$default_args = array(
			'client_id'       => '',
			'client_secret'   => '',
			'redirect_uri'    => '',
			'refresh_token'   => '',
			'request_timeout' => 5,
			'ssl_verify'      => true
		);
		$this->set_options( array_merge( $default_args, $args ) );
	}

	/**
	 * Sets multiple options at once.
	 *
	 * @access public
	 * @param  array    $args List of options and values to set
	 * @return boolean        Returns TRUE on success, FALSE on failure.
	 */
	public function set_options( $args ) {
		if ( ! is_array( $args ) )
			return false;
		foreach ( $args as $option => $value )
			$this->set_option( $option, $value );
	}

	/**
	 * Sets an option.
	 *
	 * @access public
	 * @param  string $option The option to set.
	 * @param  mixed  $value  The value to set the option to.
	 * @return boolean        Returns TRUE on success, FALSE on failure.
	 */
	public function set_option( $option, $value ) {
		switch ( $option ) {
			case 'ssl_verify':
				$this->ssl_verify = ( bool ) $value;
				return;
			case 'request_timeout':
				if ( intval( $value ) > 0 )
					$this->request_timeout = intval( $value );
				return;
			default:
					$this->$option = ( string ) $value;
					return;
		}
	}

	/**
	 * Requests authorization from Google's OAuth2 server to access services for a user.
	 *
	 * @access public
	 * @param  array   $scope           Array of API URLs to services where access is wanted.
	 * @param  string  $state           A string that is passed back from Google.
	 * @param  boolean $approval_prompt Indicates whether to force prompting for approval (TRUE) or not (FALSE). Defaults to FALSE.
	 * @return NULL
	 */
	public function request_authorization( $scope = array() , $state = '', $approval_prompt = false ) {
		$params = array(
			'response_type' => 'code',
			'client_id' => $this->client_id,
			'redirect_uri' => $this->redirect_uri,
			'scope' => implode( ' ', $scope ),
			'access_type' => 'offline',
		);
		if ( ! empty( $state ) )
			$params['state'] = $state;
		if ( $approval_prompt )
			$params['approval_prompt'] = $force;

		header( 'Location: ' . $this->base_url . 'auth?' . http_build_query( $params ) );
	}

	/**
	 * Requests a refresh token from Google's OAuth2 server.
	 *
	 * @uses  wp_remote_post
	 * @access public
	 * @param  string $code Authorization code received from Google. If empty the method will try to get it from $_GET['code'].
	 * @return mixed        Returns a refresh token on success or an instance of WP_Error on failure.
	 */
	public function request_refresh_token( $code = '' ) {
		if ( $code == '' )
			$code = $_GET['code'];

		$args = array(
			'timeout' => $this->request_timeout,
			'ssl_verify' => $this->ssl_verify,
			'body' => array(
				'code' => $code,
				'client_id' => $this->client_id,
				'client_secret' => $this->client_secret,
				'redirect_uri' => $this->redirect_uri,
				'grant_type' => 'authorization_code'
			)
		);

		$result = wp_remote_post( $this->base_url . 'token', $args );

		if ( is_wp_error( $result ) )
			return $result;
		else {
			if ( $result['response']['code'] == '200' )	{
				$result = json_decode( $result['body'], true );
				if ( isset($result['refresh_token']) ) {
					$this->refresh_token = $result['refresh_token'];
					$this->access_token = $result['access_token'];
					return $result['refresh_token'];
				}
				return new WP_Error('no_refresh_token', "Did not receive a refresh token.");
			}
			else
				return new WP_Error( 'bad_response', 'The server returned code ' . $result['response']['code'] . ' ' . $result['response']['message'] . ' while trying to obtain a refresh token.' );
		}
	}

	/**
	 * Requests and returns an access token from Google's OAuth2 server.
	 *
	 * @uses  wp_remote_post
	 * @access private
	 * @return mixed   Returns a new access token on success or an instance of WP_Error on failure.
	 */
	private function request_access_token() {
		$args = array(
			'timeout' => $this->request_timeout,
			'ssl_verify' => $this->ssl_verify,
			'body' => array(
				'refresh_token' => $this->refresh_token,
				'client_id' => $this->client_id,
				'client_secret' => $this->client_secret,
				'grant_type' => 'refresh_token'
			)
		);

		$result = wp_remote_post( $this->base_url . 'token', $args );

		if( is_wp_error( $result ) )
			return $result;
		else {
			if ( $result['response']['code'] == '200' )	{
				$result = json_decode( $result['body'], true );
				$this->access_token = $result['access_token'];
				return $result['access_token'];
			}
			else
				return new WP_Error('bad_response', 'The server returned code ' . $result['response']['code'] . ' ' . $result['response']['message'] . ' while trying to obtain an access token.');
		}
	}

	/**
	 * Returns the access token.
	 *
	 * @access public
	 * @return mixed  Returns the access token on success, an instance of WP_Error on failure.
	 */
	public function get_access_token() {
		if ( empty( $this->access_token ) )
			if ( empty( $this->refresh_token ) )
				return new WP_Error( 'invalid_operation', 'You need a refresh token in order to request an access token.' );
			else
				return $this->request_access_token();
		else
			return $this->access_token;
	}

	/**
	 * Revoke a refresh token.
	 *
	 * @uses  wp_remote_get
	 * @access public
	 * @return mixed Returns TRUE on success, an instance of WP_Error on failure.
	 */
	public function revoke_refresh_token() {
		if ( ! empty( $this->refresh_token ) ) {
			$result = wp_remote_get( $this->base_url . 'revoke?token=' . $this->refresh_token );
			if ( is_wp_error( $result ) )
				return $result;
			else {
				if ( $result['response']['code'] == '200' )	{
					$this->refresh_token = '';
					return true;
				}
				return new WP_Error("bad_response", "The server returned code " . $result['response']['code'] . " " . $result['response']['message'] . " while trying to revoke the refresh token.");
			}
		}
		return new WP_Error( 'invalid_operation', 'There is no refresh token to revoke.' );
	}

	/**
	 * Checks whether the refresh token is set or not.
	 *
	 * @access public
	 * @return boolean Returns TRUE if the refresh token is set, FALSE otherwise.
	 */
	public function is_authorized() {
		return (
			!empty( $this->refresh_token ) &&
			!empty( $this->client_id ) &&
			!empty( $this->client_secret )
		);
	}
}
