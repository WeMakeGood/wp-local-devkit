<?php
/**
 * `wp devkit login mint|end`: short-lived login cookies for TEST users.
 *
 * Lets a script or AI agent load pages and wp-admin as a specific user (curl,
 * Playwright) without a password. Minting needs shell access to the site,
 * which already allows anything, so it adds no new way in. Deliberately not a
 * URL token or plugin code: those would work on any copy of the site,
 * including live.
 *
 * @package WP_Local_Devkit
 */

namespace WP_Local_Devkit;

use WP_CLI;
use WP_Session_Tokens;

class Login_Command {

	/**
	 * Mint login cookies for a test user.
	 *
	 * Creates a real WordPress session that expires on its own; end it sooner
	 * with `wp devkit login end`.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : Login, email or ID of a user with a test email address.
	 *
	 * [--minutes=<minutes>]
	 * : Lifetime. Capped by the config's max_minutes.
	 * ---
	 * default: 15
	 * ---
	 *
	 * [--format=<format>]
	 * : json: cookies for a browser. header: a Cookie header for curl.
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - header
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp devkit login mint test_member
	 *     curl -sk -H "$(wp devkit login mint test_member --format=header)" https://site.local/
	 */
	public function mint( $args, $assoc_args ) {
		Config::require_local();
		$user    = $this->test_user( $args[0] );
		$minutes = max( 1, min( Config::get()['max_minutes'], (int) $assoc_args['minutes'] ) );
		$expires = time() + $minutes * MINUTE_IN_SECONDS;

		// One session token for both cookies, as wp_set_auth_cookie() does.
		$token = WP_Session_Tokens::get_instance( $user->ID )->create( $expires );
		// wp-admin over HTTPS checks the secure auth cookie, plain HTTP the
		// auth cookie; the front end checks the logged-in cookie.
		$cookies = [
			[ 'name' => SECURE_AUTH_COOKIE, 'value' => wp_generate_auth_cookie( $user->ID, $expires, 'secure_auth', $token ) ],
			[ 'name' => AUTH_COOKIE, 'value' => wp_generate_auth_cookie( $user->ID, $expires, 'auth', $token ) ],
			[ 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( $user->ID, $expires, 'logged_in', $token ) ],
		];

		if ( 'header' === $assoc_args['format'] ) {
			WP_CLI::line( 'Cookie: ' . implode( '; ', array_map( function ( $c ) { return $c['name'] . '=' . $c['value']; }, $cookies ) ) );
			return;
		}

		WP_CLI::line( wp_json_encode( [
			'site'    => home_url(),
			'user_id' => $user->ID,
			'expires' => $expires,
			'cookies' => $cookies,
		] ) );
	}

	/**
	 * End every session of a test user, invalidating minted cookies.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : Login, email or ID of a user with a test email address.
	 *
	 * ## EXAMPLES
	 *
	 *     wp devkit login end test_member
	 */
	public function end( $args, $assoc_args ) {
		Config::require_local();
		$user = $this->test_user( $args[0] );
		WP_Session_Tokens::get_instance( $user->ID )->destroy_all();
		WP_CLI::success( "Ended all sessions for {$user->user_login}." );
	}

	private function test_user( string $ref ): \WP_User {
		$user = is_numeric( $ref ) ? get_user_by( 'id', (int) $ref ) : ( get_user_by( 'login', $ref ) ?: get_user_by( 'email', $ref ) );
		if ( ! $user ) {
			WP_CLI::error( "No user '{$ref}'." );
		}
		if ( ! Config::is_test_email( $user->user_email ) ) {
			WP_CLI::error( "Refusing: {$user->user_login} doesn't have a test email (" . implode( ', ', Config::get()['email_domains'] ) . ').' );
		}
		return $user;
	}
}
