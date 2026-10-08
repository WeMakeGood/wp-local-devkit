<?php
/**
 * `wp devkit config`
 *
 * @package WP_Local_Devkit
 */

namespace WP_Local_Devkit;

use WP_CLI;

class Config_Command {

	/**
	 * Show the resolved config and whether this site counts as local.
	 *
	 * ## EXAMPLES
	 *
	 *     wp devkit config
	 */
	public function __invoke( $args, $assoc_args ) {
		$config = Config::get();
		$host   = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		WP_CLI::log( 'Site:       ' . home_url() );
		WP_CLI::log( 'Config:     ' . $config['source'] );
		WP_CLI::log( 'Site root:  ' . $config['site_root'] );
		WP_CLI::log( 'Local:      ' . ( $this->is_local() ? "yes ({$host})" : "NO ({$host}): every command will refuse" ) );
		WP_CLI::log( 'Hosts:      ' . implode( ', ', $config['hosts'] ) );
		WP_CLI::log( 'Test email: ' . implode( ', ', array_map( function ( $d ) { return '@' . ltrim( $d, '@' ); }, $config['email_domains'] ) ) );
		WP_CLI::log( 'Snapshots:  ' . $config['snapshots'] . " (keep {$config['keep']})" );
		WP_CLI::log( 'Fixtures:   ' . ( $config['fixtures'] ?: '(none)' ) );
	}

	private function is_local(): bool {
		try {
			// require_local() exits on failure; WP_CLI's exit is catchable as ExitException.
			Config::require_local();
			return true;
		} catch ( \WP_CLI\ExitException $e ) {
			return false;
		}
	}
}
