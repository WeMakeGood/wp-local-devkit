<?php
/**
 * `wp devkit snapshot create|list|restore|prune`: database snapshots before
 * changing a site, and the standard way back.
 *
 * Snapshots are `wp db export` files named `YYYY-MM-DD-HHMMSS-<label>.sql`,
 * kept outside the web root (they hold user data) and written 0600. Only files
 * with that name pattern are listed or pruned, so hand-made backups in the
 * same folder are never touched.
 *
 * A restore replaces the WHOLE database: every change since the snapshot is
 * lost, including other people's and other sessions'. It always takes a
 * pre-restore snapshot first, so a restore can itself be undone.
 *
 * @package WP_Local_Devkit
 */

namespace WP_Local_Devkit;

use WP_CLI;

class Snapshot_Command {

	const PATTERN = '/^\d{4}-\d{2}-\d{2}-\d{6}-[a-z0-9-]+\.sql$/';

	/**
	 * Export the database to a new snapshot.
	 *
	 * ## OPTIONS
	 *
	 * [<label>]
	 * : What the snapshot is before, e.g. "header-redesign".
	 * ---
	 * default: snapshot
	 * ---
	 *
	 * [--porcelain]
	 * : Print only the file path.
	 *
	 * ## EXAMPLES
	 *
	 *     wp devkit snapshot create header-redesign
	 */
	public function create( $args, $assoc_args ) {
		Config::require_local();
		$file = $this->export( $args[0] ?? 'snapshot' );

		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'porcelain' ) ) {
			WP_CLI::line( $file );
			return;
		}
		WP_CLI::success( 'Snapshot: ' . $file . ' (' . size_format( filesize( $file ) ) . ')' );
	}

	/**
	 * List snapshots, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json, csv, ids (file names).
	 * ---
	 * default: table
	 * ---
	 *
	 * @subcommand list
	 */
	public function list_( $args, $assoc_args ) {
		Config::require_local();
		$rows = array_map(
			function ( $path ) {
				return [
					'file'     => basename( $path ),
					'size'     => size_format( filesize( $path ) ),
					'modified' => wp_date( 'Y-m-d H:i:s', filemtime( $path ) ),
				];
			},
			$this->snapshots()
		);

		if ( 'ids' === $assoc_args['format'] ) {
			WP_CLI::line( implode( ' ', array_column( $rows, 'file' ) ) );
			return;
		}
		WP_CLI\Utils\format_items( $assoc_args['format'], $rows, [ 'file', 'size', 'modified' ] );
	}

	/**
	 * Replace the database with a snapshot. Takes a pre-restore snapshot first.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Snapshot file name (from `list`) or path.
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp devkit snapshot restore 2026-10-08-143000-header-redesign.sql
	 */
	public function restore( $args, $assoc_args ) {
		Config::require_local();
		$file = $args[0];
		if ( false === strpos( $file, '/' ) ) {
			$file = Config::get()['snapshots'] . '/' . $file;
		}
		if ( ! is_readable( $file ) ) {
			WP_CLI::error( "Snapshot not found: {$file}" );
		}

		WP_CLI::warning( 'This replaces the WHOLE database. Every change since ' . basename( $file ) . ' is lost, including other people\'s and other sessions\'.' );
		WP_CLI::confirm( 'Restore ' . basename( $file ) . '?', $assoc_args );

		$safety = $this->export( 'pre-restore' );
		WP_CLI::log( 'Pre-restore snapshot: ' . $safety );

		$result = WP_CLI::runcommand( 'db import ' . escapeshellarg( $file ), [ 'return' => 'all', 'exit_error' => false, 'launch' => true ] );
		if ( 0 !== $result->return_code ) {
			WP_CLI::error( "Import failed; the database may be partly restored. Undo with: wp devkit snapshot restore " . basename( $safety ) . "\n" . $result->stderr );
		}
		WP_CLI::success( 'Restored ' . basename( $file ) . '. Undo with: wp devkit snapshot restore ' . basename( $safety ) );
	}

	/**
	 * Delete the oldest devkit snapshots beyond the keep count.
	 *
	 * ## OPTIONS
	 *
	 * [--keep=<n>]
	 * : How many to keep. Defaults to the config's keep.
	 *
	 * [--dry-run]
	 * : Show what would be deleted.
	 */
	public function prune( $args, $assoc_args ) {
		Config::require_local();
		$keep   = max( 1, (int) ( $assoc_args['keep'] ?? Config::get()['keep'] ) );
		$old    = array_slice( $this->snapshots(), $keep );
		$dryrun = WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run' );

		foreach ( $old as $path ) {
			if ( ! $dryrun ) {
				unlink( $path );
			}
			WP_CLI::log( ( $dryrun ? 'Would delete ' : 'Deleted ' ) . basename( $path ) );
		}
		WP_CLI::success( count( $old ) . ( $dryrun ? ' would be deleted' : ' deleted' ) . "; keeping {$keep}." );
	}

	private function export( string $label ): string {
		$dir = Config::get()['snapshots'];
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			WP_CLI::error( "Can't create snapshot folder {$dir}" );
		}

		$label = trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $label ) ), '-' ) ?: 'snapshot';
		$file  = $dir . '/' . wp_date( 'Y-m-d-His' ) . '-' . $label . '.sql';

		$result = WP_CLI::runcommand( 'db export ' . escapeshellarg( $file ), [ 'return' => 'all', 'exit_error' => false, 'launch' => true ] );
		if ( 0 !== $result->return_code || ! is_file( $file ) ) {
			WP_CLI::error( "Export failed:\n" . $result->stderr );
		}
		chmod( $file, 0600 );

		return $file;
	}

	/** @return string[] Paths, newest first. */
	private function snapshots(): array {
		$dir = Config::get()['snapshots'];
		$all = is_dir( $dir ) ? scandir( $dir ) : [];
		$all = array_values( array_filter( $all, function ( $f ) { return (bool) preg_match( self::PATTERN, $f ); } ) );
		rsort( $all );

		return array_map( function ( $f ) use ( $dir ) { return $dir . '/' . $f; }, $all );
	}
}
