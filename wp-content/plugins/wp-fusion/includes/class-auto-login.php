<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WPF_Auto_Login {

	/**
	 * Auto login user
	 */

	public $auto_login_user;

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   3.12
	 */

	public function __construct() {

		// Track URL session logins
		add_action( 'init', array( $this, 'start_auto_login' ), 1 );
		add_filter( 'wpf_end_auto_login', array( $this, 'maybe_end' ), 10, 2 );
		add_filter( 'wpf_skip_auto_login', array( $this, 'maybe_skip' ), 10, 2 );
		add_action( 'wp_logout', array( $this, 'end_auto_login' ) );
		add_action( 'wp_login', array( $this, 'end_auto_login' ) );
		add_action( 'set_logged_in_cookie', array( $this, 'end_auto_login' ) );

		// Session cleanup cron
		add_action( 'clear_auto_login_metadata', array( $this, 'clear_auto_login_metadata' ) );

		add_action( 'wp_loaded', array( $this, 'maybe_doing_it_wrong' ) );

	}

	/**
	 * Gets contact ID from URL
	 *
	 * @access public
	 * @return string Contact ID
	 */

	public function get_contact_id_from_url() {

		$contact_id = false;

		$alt_query_var = apply_filters( 'wpf_auto_login_query_var', false );

		if ( isset( $_GET['cid'] ) ) {

			$contact_id = sanitize_text_field( $_GET['cid'] );

		} elseif ( $contact_id == false && $alt_query_var != false && isset( $_GET[ $alt_query_var ] ) ) {

			$contact_id = sanitize_text_field( $_GET[ $alt_query_var ] );

		}

		$contact_id = apply_filters( 'wpf_auto_login_contact_id', $contact_id );

		return $contact_id;

	}


	/**
	 * Starts a session if contact ID is passed in URL
	 *
	 * @access public
	 * @return void
	 */

	public function start_auto_login( $contact_id = false ) {

		if ( wpf_is_user_logged_in() ) {
			return;
		}

		if ( false == $contact_id && false == wp_fusion()->settings->get( 'auto_login' ) && false == wp_fusion()->settings->get( 'auto_login_forms' ) ) {
			return;
		}

		$contact_data = false;

		// Try finding a contact ID in the URL
		if ( false == $contact_id ) {
			$contact_id = $this->get_contact_id_from_url();
		}

		if ( empty( $contact_id ) && empty( $_COOKIE['wpf_contact'] ) ) {
			return;
		}

		if ( ! empty( $_COOKIE['wpf_contact'] ) ) {
			$contact_data = json_decode( stripslashes( $_COOKIE['wpf_contact'] ), true );
		}

		// Allow permanently ending the session
		if ( isset( $contact_data ) && true === apply_filters( 'wpf_end_auto_login', false, $contact_data ) ) {
			$this->end_auto_login();
			return;
		}

		// If CID has changed, start a new session
		if ( ! empty( $contact_data ) && ! empty( $contact_id ) && $contact_id != $contact_data['contact_id'] ) {
			$this->end_auto_login();
			$contact_data = array();
		}

		// Start the auto login

		define( 'DOING_WPF_AUTO_LOGIN', true );

		if ( empty( $contact_data ) && isset( $contact_id ) ) {

			// Do first time autologin

			$user_id = $this->create_temp_user( $contact_id );

			if ( is_wp_error( $user_id ) ) {
				return false;
			}

			$contact_data = array(
				'contact_id' => $contact_id,
				'user_id'    => $user_id,
			);

		} elseif ( is_array( $contact_data ) ) {

			// If data already exists, make sure the user hasn't expired

			$contact_id = get_user_meta( $contact_data['user_id'], wp_fusion()->crm->slug . '_contact_id', true );

			if ( empty( $contact_id ) || $contact_id != $contact_data['contact_id'] ) {

				$user_id = $this->create_temp_user( $contact_data['contact_id'] );

				if ( is_wp_error( $user_id ) ) {
					return false;
				}

				$contact_data['user_id'] = $user_id;

			} else {

				// If the temp user already exists but ?cid= is in the URL, update their tags anyway

				wp_fusion()->user->get_tags( $contact_data['user_id'], true, false );

			}
		}

		$this->auto_login_user = $contact_data;

		// Allow temporarily skipping the session on a single page
		if ( false !== $contact_data && true === apply_filters( 'wpf_skip_auto_login', false, $contact_data ) ) {
			return;
		}

		// Set the user in the cache
		$user              = new stdClass();
		$user->ID          = $contact_data['user_id'];
		$user->user_email  = get_user_meta( $contact_data['user_id'], 'user_email', true );
		$user->first_name  = get_user_meta( $contact_data['user_id'], 'first_name', true );
		$user->last_name   = get_user_meta( $contact_data['user_id'], 'last_name', true );
		$user->user_status = 0;

		if ( wp_fusion()->settings->get( 'auto_login_current_user' ) == true ) {
			global $current_user;
			$current_user = $user;
		}

		wp_cache_set( $contact_data['user_id'], $user, 'users' );

		// Hide admin bar
		add_filter( 'show_admin_bar', '__return_false' );

		// Disable comments
		add_filter( 'comments_open', array( wp_fusion()->access, 'turn_off_comments' ), 10, 2 );

		do_action( 'wpf_started_auto_login', $contact_data['user_id'], $contact_id );

	}

	/**
	 * Permanently ends the auto login session in certain scenarios
	 *
	 * @access public
	 * @return void
	 */

	public function maybe_end( $end, $contact_data ) {

		if ( isset( $_GET['wpf-end-auto-login'] ) ) {
			return true;
		}

		$request_uris = array(
			'login',
			'register',
			'order-received',
			'purchase-confirmation',
		);

		$request_uris = apply_filters( 'wpf_end_auto_login_request_uris', $request_uris );

		foreach ( $request_uris as $uri ) {

			if ( strpos( $_SERVER['REQUEST_URI'], $uri ) !== false ) {
				$end = true;
			}
		}

		// Check transient
		$transient = get_transient( 'wpf_end_auto_login_' . $contact_data['contact_id'] );

		if ( true == $transient ) {

			$end = true;
			delete_transient( 'wpf_end_auto_login_' . $contact_data['contact_id'] );

		}

		return $end;

	}

	/**
	 * Skips the auto login session in certain scenarios
	 *
	 * @access public
	 * @return void
	 */

	public function maybe_skip( $skip, $contact_data ) {

		$request_uris = apply_filters( 'wpf_skip_auto_login_request_uris', array() );

		foreach ( $request_uris as $uri ) {

			if ( strpos( $_SERVER['REQUEST_URI'], $uri ) !== false ) {
				$skip = true;
			}
		}

		return $skip;

	}

	/**
	 * Creates a temporary user for auto login sessions
	 *
	 * @access public
	 * @return int Temporary user ID
	 */

	public function create_temp_user( $contact_id ) {

		$user_tags = wp_fusion()->crm->get_tags( $contact_id );

		if ( is_wp_error( $user_tags ) ) {
			return $user_tags;
		}

		// Set the random number based on the CID
		$user_id = rand( 100000000, 1000000000 );

		update_user_meta( $user_id, wp_fusion()->crm->slug . '_tags', $user_tags );
		update_user_meta( $user_id, wp_fusion()->crm->slug . '_contact_id', $contact_id );

		$contact_data = array(
			'contact_id' => $contact_id,
			'user_id'    => $user_id,
		);

		$cookie_expiration = apply_filters( 'wpf_auto_login_cookie_expiration', DAY_IN_SECONDS * 180 );

		setcookie( 'wpf_contact', json_encode( $contact_data ), time() + $cookie_expiration, COOKIEPATH, COOKIE_DOMAIN );

		// Load meta data
		wp_fusion()->user->pull_user_meta( $user_id );

		// Schedule cleanup after one day
		wp_schedule_single_event( time() + 86400, 'clear_auto_login_metadata', array( $user_id ) );

		return $user_id;

	}


	/**
	 * Ends session on user login or logout
	 *
	 * @access public
	 * @return void
	 */

	public function end_auto_login() {

		if ( ! empty( $_COOKIE['wpf_contact'] ) ) {

			$contact_data = json_decode( stripslashes( $_COOKIE['wpf_contact'] ), true );

			$this->clear_auto_login_metadata( $contact_data['user_id'] );
			$this->auto_login_user = false;

			if ( ! headers_sent() ) {

				// Clear the cookie if headers haven't been sent yet
				setcookie( 'wpf_contact', false, time() - ( 15 * 60 ), COOKIEPATH, COOKIE_DOMAIN );

				wp_destroy_current_session();
				wp_clear_auth_cookie();

			} elseif ( wpf_is_user_logged_in() ) {

				// If headers have been sent, set a transient to clear the cookie on next load
				set_transient( 'wpf_end_auto_login_' . $contact_data['contact_id'], true, 60 * 60 );

			}
		}

	}

	/**
	 * Clear orphaned metadata for auto-login users
	 *
	 * @access public
	 * @return void
	 */

	public function clear_auto_login_metadata( $user_id ) {

		global $wpdb;
		$meta = $wpdb->get_col( $wpdb->prepare( "SELECT umeta_id FROM $wpdb->usermeta WHERE user_id = %d", $user_id ) );

		foreach ( $meta as $mid ) {
			delete_metadata_by_mid( 'user', $mid );
		}

	}

	/**
	 * Display a warning if auto login links are used by a logged in admin
	 *
	 * @access public
	 * @return mixed HTML message
	 */

	public function maybe_doing_it_wrong() {

		if ( is_admin() ) {
			return;
		}

		if ( false == wp_fusion()->settings->get( 'auto_login' ) && false == wp_fusion()->settings->get( 'auto_login_forms' ) ) {
			return;
		}

		if ( ! empty( $this->get_contact_id_from_url() ) && current_user_can( 'manage_options' ) ) {

			echo '<div style="padding: 20px; border: 4px solid #ff0000; text-align: center;">';

			echo '<strong>' . __( 'Heads up: It looks like you\'re using a WP Fusion auto-login link, but you\'re already logged into the site, so nothing will happen. Always test auto-login links in a private browser tab.', 'wp-fusion' ) . '</strong><br /><br />';

			echo '<em>(' . __( 'This message is only shown to admins and won\'t be visible to regular users.', 'wp-fusion' ) . ')</em>';

			echo '</div>';

		}

	}

}
