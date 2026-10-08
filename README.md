# wp-local-devkit

WP-CLI commands for working on **local** WordPress sites, built for people and AI coding agents who build and test custom plugins and themes:

- **Test as a user.** Mint short-lived login cookies for a test account, then load the front end or wp-admin as that user with curl or Playwright. No passwords, and no token login in site code.
- **Fixtures.** Re-create a site's test users and content with one command after a sync from live wipes them.
- **Snapshots.** Export the database before changing a site, and restore it as the standard way back.

Every command refuses to run unless the site's host is a local dev host.

## Install

Once per machine:

```bash
wp package install https://github.com/WeMakeGood/wp-local-devkit.git
```

For the Playwright helper, also: `npm i -g playwright && npx playwright install chromium`.

## Safety model

- **Local hosts only.** Every command checks `home_url()` against allowed host suffixes (default `.local`, `.test`, `.localhost`, `localhost`) and refuses anywhere else.
- **Test users only.** Login cookies are minted only for users whose email is on a test domain (default `example.com`). Real people, admins included, are refused.
- **Guards can be changed, not disabled.** An empty host or email list is an error.
- **Shell access is the key.** Minting needs WP-CLI on the machine, which already allows anything, so it adds no new way in. A URL token or a plugin would work on every copy of the site, including live after a sync or deploy; that's why this is a WP-CLI package and not code inside the site.
- **Nothing in the web root.** The per-site config, fixtures, and snapshots live in the site root, above the web root. Snapshot and fixture paths inside the web root are refused, because files there can be requested over HTTP and travel with syncs.
- **Snapshots hold user data.** They're written `0600`. Keep the folder out of cloud-synced drives and repos.

## Per-site config (optional)

An untracked `devkit.php` in the **site root**: the directory above the web root. For LocalWP that's `~/Local Sites/<site>/devkit.php`, next to `app/`. Everything has a default; `wp devkit config` shows what resolved.

```php
<?php
return [
	'hosts'         => [ '.local', '.test', '.localhost', 'localhost' ],
	'email_domains' => [ 'example.com' ],
	'snapshots'     => 'backups',              // relative to the site root
	'keep'          => 20,                     // snapshot prune keeps this many
	'fixtures'      => 'devkit-fixtures.php',  // relative to the site root
	'max_minutes'   => 60,                     // longest allowed login
];
```

## Commands

Run from the site's web root (or pass `--path`).

| Command | Does |
|---|---|
| `wp devkit config` | Show the resolved config and whether the site counts as local |
| `wp devkit login mint <user> [--minutes=15] [--format=json\|header]` | Login cookies for a test user (login, email or ID). `json` for browsers, `header` for curl |
| `wp devkit login end <user>` | End all of that user's sessions (invalidates minted cookies) |
| `wp devkit fixtures` | Run the site's fixture file |
| `wp devkit snapshot create [<label>] [--porcelain]` | Export the database to `YYYY-MM-DD-HHMMSS-<label>.sql` |
| `wp devkit snapshot list` | Snapshots, newest first |
| `wp devkit snapshot restore <file> [--yes]` | Replace the database with a snapshot, after taking a `pre-restore` snapshot |
| `wp devkit snapshot prune [--keep=N] [--dry-run]` | Delete the oldest snapshots beyond the keep count |

Only files with the snapshot name pattern are listed or pruned; hand-made backups in the same folder are left alone.

### curl

```bash
H=$(wp devkit login mint test_member --format=header)
curl -sk -H "$H" https://mysite.local/members/
wp devkit login end test_member
```

### Playwright

```js
import { loggedIn } from '/path/to/wp-local-devkit/browser.mjs';

const session = await loggedIn('test_member', { wpPath: '/path/to/site/app/public' }); // null = logged out
try {
	const page = await session.context.newPage();
	await page.goto(`${session.site}/members/`, { waitUntil: 'networkidle' });
	await page.screenshot({ path: 'members.png', fullPage: true });
} finally {
	await session.close(); // closes the browser and ends the WordPress session
}
```

With a package install, the helper is at `~/.wp-cli/packages/vendor/wemakegood/wp-local-devkit/browser.mjs`.

### Fixtures

The fixture file is plain PHP that runs with WordPress loaded, after the host guard. Make it idempotent: look things up before creating them, so it can be re-run any time. It gets `$devkit_test_email( 'test-member' )`, which builds an address on the first test domain.

```php
<?php
// ~/Local Sites/<site>/devkit-fixtures.php
foreach ( [ 'test_member' => 'subscriber', 'test_editor' => 'editor' ] as $login => $role ) {
	if ( ! get_user_by( 'login', $login ) ) {
		wp_insert_user( [
			'user_login' => $login,
			'user_email' => $devkit_test_email( str_replace( '_', '-', $login ) ),
			'user_pass'  => wp_generate_password( 32 ), // never used: log in with `login mint`
			'role'       => $role,
		] );
		WP_CLI::log( "Created {$login}" );
	}
}
```

Avoid anything with side effects outside the site in fixtures: invitation emails, form submissions that post to a CRM, payment calls.

## Snapshots as the standard revert

A suggested working rule for people and agents:

1. Before the first change to a site's database in a working session, `wp devkit snapshot create <what-you-are-about-to-do>`, and note the file name wherever the team tracks work.
2. To undo, prefer a targeted fix first (a post revision, one option, one record).
3. Restore a snapshot only when that isn't enough, and only after checking that no one else changed the site since. **A restore replaces the whole database**: everyone's changes since the snapshot are lost. The automatic `pre-restore` snapshot is how you undo a restore.
4. `wp devkit snapshot prune` now and then.

## License

GPL-2.0-or-later. See `LICENSE`.
