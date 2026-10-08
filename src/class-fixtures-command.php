<?php
/**
 * `wp devkit fixtures`: run the site's fixture file.
 *
 * The fixture file is plain PHP that creates (or repairs) the site's test
 * users and content. Write it idempotent, so it can be re-run after every
 * sync from live wipes the database. It runs with WordPress loaded, after the
 * local-host guard, and gets a `$devkit_test_email( $local_part )` helper
 * that builds an address on the first test email domain.
 *
 * @package WP_Local_Devkit
 */

namespace WP_Local_Devkit;

use WP_CLI;

class Fixtures_Command {

	/**
	 * Create or repair this site's test users and content.
	 *
	 * ## EXAMPLES
	 *
	 *     wp devkit fixtures
	 */
	public function __invoke( $args, $assoc_args ) {
		Config::require_local();
		$file = Config::get()['fixtures'];

		if ( ! $file ) {
			WP_CLI::error( "No fixture file configured. Set 'fixtures' in " . Config::site_root() . '/' . Config::FILE . '.' );
		}
		if ( ! is_readable( $file ) ) {
			WP_CLI::error( "Fixture file not found: {$file}" );
		}
		if ( 0 === strpos( $file, rtrim( ABSPATH, '/' ) . '/' ) ) {
			WP_CLI::error( "Fixture file {$file} is inside the web root. Keep it in the site root." );
		}

		$domain            = ltrim( Config::get()['email_domains'][0], '@' );
		$devkit_test_email = function ( string $local_part ) use ( $domain ): string {
			return $local_part . '@' . $domain;
		};

		WP_CLI::log( "Running {$file}" );
		( function () use ( $file, $devkit_test_email ) {
			include $file;
		} )();
	}
}
