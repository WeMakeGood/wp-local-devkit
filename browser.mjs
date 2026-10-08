// Playwright helper: a browser context logged in as a TEST user, via
// `wp devkit login mint`. Uses a globally installed Playwright
// (`npm i -g playwright && npx playwright install chromium`).
//
//   import { loggedIn } from '<path to wp-local-devkit>/browser.mjs';
//   const session = await loggedIn('test_member', { wpPath: '/path/to/site/app/public' });
//   try {
//     const page = await session.context.newPage();
//     await page.goto(`${session.site}/some-page/`, { waitUntil: 'networkidle' });
//   } finally {
//     await session.close();   // closes the browser and ends the WP session
//   }
//
// Pass null as the user for a logged-out context. wpPath defaults to
// $WP_PATH, then the current directory.

import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { join } from 'node:path';

const globalRoot = execFileSync('npm', ['root', '-g']).toString().trim();
const { chromium } = createRequire(join(globalRoot, 'noop.js'))('playwright');

export async function loggedIn(user, { wpPath = process.env.WP_PATH || process.cwd(), minutes = 15, viewport = { width: 1280, height: 900 } } = {}) {
	const wp = (...args) => execFileSync('wp', args, { cwd: wpPath }).toString().trim();
	const site = wp('option', 'get', 'home').replace(/\/$/, '');

	const browser = await chromium.launch();
	// Local dev sites usually have self-signed certificates.
	const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport });

	if (user) {
		const minted = JSON.parse(wp('devkit', 'login', 'mint', String(user), `--minutes=${minutes}`));
		await context.addCookies(minted.cookies.map((c) => ({
			name: c.name, value: c.value, url: site, httpOnly: true, secure: site.startsWith('https:'), expires: minted.expires,
		})));
	}

	return {
		browser,
		context,
		site,
		async close() {
			await browser.close();
			if (user) {
				wp('devkit', 'login', 'end', String(user));
			}
		},
	};
}
