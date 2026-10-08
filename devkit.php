<?php
/**
 * wp-local-devkit: WP-CLI commands for working on LOCAL WordPress sites.
 *
 *   wp devkit config                      What this site resolved to
 *   wp devkit login mint|end <user>       Short-lived login cookies for a test user
 *   wp devkit fixtures                    Run this site's fixture file
 *   wp devkit snapshot create|list|restore|prune
 *
 * Every command refuses to run unless the site's host is a local dev host.
 * See README.md.
 *
 * @package WP_Local_Devkit
 */

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

require_once __DIR__ . '/src/class-config.php';
require_once __DIR__ . '/src/class-config-command.php';
require_once __DIR__ . '/src/class-login-command.php';
require_once __DIR__ . '/src/class-fixtures-command.php';
require_once __DIR__ . '/src/class-snapshot-command.php';

WP_CLI::add_command( 'devkit config', 'WP_Local_Devkit\\Config_Command' );
WP_CLI::add_command( 'devkit login', 'WP_Local_Devkit\\Login_Command' );
WP_CLI::add_command( 'devkit fixtures', 'WP_Local_Devkit\\Fixtures_Command' );
WP_CLI::add_command( 'devkit snapshot', 'WP_Local_Devkit\\Snapshot_Command' );
