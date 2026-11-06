// axe-core WCAG 2.2 A/AA checks on everything RedeemID prints: the locked
// post, the member blocks, search, and every RedeemID admin screen. Front-end
// pages run once per reader state, because each state renders different markup.
// Only RedeemID's own markup is checked; the theme and the WordPress admin
// around it are not ours (the site and demo themes check themselves).
// Needs the seeded WordPress from CI (tests/Integration/seed.php).
const fs = require( 'node:fs' );
const { test, expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

const seed = JSON.parse( fs.readFileSync( process.env.REDEEMID_LEAK_SEED || '/tmp/redeemid-leak.json', 'utf8' ) );
const tags = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ];
// Every block and panel RedeemID prints carries a redeemid class.
const ours = '[class*="redeemid"]';

const pages = {
	'locked post with a break': seed.posts.break.link,
	'locked post without a break': seed.posts.no_break.link,
	'member blocks': seed.pages.blocks.link,
	search: '/?s=LEAKTEST',
};

const readers = {
	guest: '',
	'signed-in reader': seed.cookies.reader,
	'linked member without the tag': seed.cookies.linked,
	'gold member': seed.cookies.gold,
};

function report( violations ) {
	return violations
		.map( ( v ) => `${ v.id } (${ v.impact }): ${ v.help }\n  ${ v.nodes.map( ( n ) => `${ n.target.join( ' ' ) }: ${ n.failureSummary.split( '\n' ).slice( 1 ).join( ' ' ).trim() }` ).join( '\n  ' ) }\n  ${ v.helpUrl }` )
		.join( '\n\n' );
}

async function audit( page, include ) {
	// Blockstudio islands fill in after load; wait for them.
	await page.waitForLoadState( 'networkidle' );
	await expect( page.locator( include ).first() ).toBeAttached();
	const { violations } = await new AxeBuilder( { page } ).withTags( tags ).include( include ).analyze();
	// Soft, so one run reports every page that fails.
	expect.soft( report( violations ) ).toBe( '' );
}

for ( const [ reader, cookie ] of Object.entries( readers ) ) {
	test.describe( reader, () => {
		test.beforeEach( async ( { context, baseURL } ) => {
			if ( cookie ) {
				const [ name, ...value ] = cookie.split( '=' );
				await context.addCookies( [ { name, value: value.join( '=' ), url: baseURL } ] );
			}
		} );

		for ( const [ name, path ] of Object.entries( pages ) ) {
			test( name, async ( { page } ) => {
				const response = await page.goto( path );
				expect( response.status() ).toBeLessThan( 500 );
				await audit( page, ours );
			} );
		}
	} );
}

test.describe( 'admin', () => {
	test.beforeEach( async ( { page } ) => {
		await page.goto( '/wp-login.php' );
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'admin' );
		await Promise.all( [ page.waitForURL( /wp-admin/ ), page.click( '#wp-submit' ) ] );
	} );

	// Every screen under the RedeemID menu, and every tab on those screens, so a
	// new screen is checked without editing this file.
	test( 'every RedeemID screen', async ( { page } ) => {
		// One test walks every screen and tab (about 25), so the default 30 s is too short.
		test.setTimeout( 180_000 );
		await page.goto( '/wp-admin/admin.php?page=redeemid' );
		const screens = await page.locator( '#toplevel_page_redeemid .wp-submenu a' ).evaluateAll( ( links ) => links.map( ( a ) => a.href ) );
		expect( screens.length ).toBeGreaterThan( 0 );

		const seen = new Set();
		const queue = [ ...screens ];

		while ( queue.length ) {
			const url = queue.shift();

			if ( seen.has( url ) ) {
				continue;
			}

			seen.add( url );

			await test.step( new URL( url ).search, async () => {
				await page.goto( url );
				const tabs = await page.locator( '#wpbody-content .nav-tab-wrapper a' ).evaluateAll( ( links ) => links.map( ( a ) => a.href ) );
				queue.push( ...tabs );
				await audit( page, '.wrap.redeemid' );
			} );
		}
	} );
} );
