<?php
/**
 * Resolves the site's devkit config and enforces the local-only guards.
 *
 * Config is an optional, untracked `devkit.php` that returns an array. It is
 * looked for in the site root, ABOVE the web root (for LocalWP,
 * `~/Local Sites/<site>/devkit.php`, next to `app/`), never inside it: a PHP
 * file in the web root can be requested over HTTP and travels with syncs and
 * deploys.
 *
 * Guards are configurable but can't be switched off: an empty host or email
 * list is an error, not "allow everything".
 *
 * @package WP_Local_Devkit
 */

namespace WP_Local_Devkit;

use WP_CLI;

class Config {

	const FILE = 'devkit.php';

	const DEFAULTS = [
		// Host suffixes that count as local. `localhost` matches exactly.
		'hosts'          => [ '.local', '.test', '.localhost', 'localhost' ],
		// Only users whose email ends in one of these get login cookies.
		'email_domains'  => [ 'example.com' ],
		// Snapshot folder; relative paths resolve from the site root.
		'snapshots'      => 'backups',
		// `snapshot prune` keeps this many devkit snapshots.
		'keep'           => 20,
		// Fixture file (relative to the site root) for `wp devkit fixtures`.
		'fixtures'       => null,
		// Max lifetime of a minted login, in minutes.
		'max_minutes'    => 60,
	];

	/** @var array|null */
	private static $resolved = null;

	/**
	 * The site root: the directory above the web root. LocalWP's web root is
	 * `<site>/app/public`, so its site root is two levels up.
	 */
	public static function site_root(): string {
		$web = rtrim( ABSPATH, '/' );
		if ( '/app/public' === substr( $web, -11 ) ) {
			return dirname( $web, 2 );
		}
		return dirname( $web );
	}

	public static function get(): array {
		if ( null !== self::$resolved ) {
			return self::$resolved;
		}

		$config = self::DEFAULTS;
		$file   = self::site_root() . '/' . self::FILE;
		$source = 'defaults';

		if ( is_readable( $file ) ) {
			$custom = include $file;
			if ( ! is_array( $custom ) ) {
				WP_CLI::error( "{$file} must return an array." );
			}
			$unknown = array_diff( array_keys( $custom ), array_keys( self::DEFAULTS ) );
			if ( $unknown ) {
				WP_CLI::error( "Unknown key(s) in {$file}: " . implode( ', ', $unknown ) );
			}
			$config = array_merge( $config, $custom );
			$source = $file;
		}

		foreach ( [ 'hosts', 'email_domains' ] as $key ) {
			if ( ! is_array( $config[ $key ] ) || ! array_filter( $config[ $key ], 'strlen' ) ) {
				WP_CLI::error( "'{$key}' can't be empty: it's a guard, not an option." );
			}
		}

		$config['snapshots'] = self::absolute( (string) $config['snapshots'] );
		if ( null !== $config['fixtures'] ) {
			$config['fixtures'] = self::absolute( (string) $config['fixtures'] );
		}
		$config['keep']        = max( 1, (int) $config['keep'] );
		$config['max_minutes'] = max( 1, (int) $config['max_minutes'] );
		$config['source']      = $source;
		$config['site_root']   = self::site_root();

		if ( 0 === strpos( rtrim( $config['snapshots'], '/' ) . '/', rtrim( ABSPATH, '/' ) . '/' ) ) {
			WP_CLI::error( "Snapshot folder {$config['snapshots']} is inside the web root. Snapshots hold user data; keep them outside it." );
		}

		return self::$resolved = $config;
	}

	/**
	 * Stop unless the site's host is a local dev host.
	 */
	public static function require_local(): void {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		foreach ( self::get()['hosts'] as $allowed ) {
			$allowed = strtolower( $allowed );
			if ( $host === $allowed || ( '.' === $allowed[0] && substr( $host, -strlen( $allowed ) ) === $allowed ) ) {
				return;
			}
		}
		WP_CLI::error( "Refusing: '{$host}' isn't a local dev host (" . implode( ', ', self::get()['hosts'] ) . ').' );
	}

	/**
	 * Whether an email is a test address.
	 */
	public static function is_test_email( string $email ): bool {
		$email = strtolower( $email );
		foreach ( self::get()['email_domains'] as $domain ) {
			if ( '@' . strtolower( ltrim( $domain, '@' ) ) === substr( $email, -( strlen( ltrim( $domain, '@' ) ) + 1 ) ) ) {
				return true;
			}
		}
		return false;
	}

	private static function absolute( string $path ): string {
		return '/' === substr( $path, 0, 1 ) ? $path : self::site_root() . '/' . $path;
	}
}
