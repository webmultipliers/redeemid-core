// Budgets for the files RedeemID itself sends to a reader's browser, per page
// and reader state. Lighthouse (lighthouserc.js) times the whole page; this
// checks only what the plugin adds, so a theme or WordPress update can't hide
// a heavier plugin. Raise a budget on purpose, in the same change that needs it.
const fs = require( 'node:fs' );
const { test, expect } = require( '@playwright/test' );

const seed = JSON.parse( fs.readFileSync( process.env.REDEEMID_LEAK_SEED || '/tmp/redeemid-leak.json', 'utf8' ) );

// Bytes over the wire (no compression on PHP's built-in server) and requests.
// Today (0.0.9) the locked post sends script 8.4 KB, stylesheet 1 KB, island
// fragments 8 KB in 3 requests. The member blocks page carries every member
// block at once, one stylesheet per block (Blockstudio's way), so it sends
// script 19.3 KB, stylesheet 10.5 KB in 17 requests.
const pages = {
	'locked post': {
		path: seed.posts.break.link,
		budget: { script: 16 * 1024, stylesheet: 8 * 1024, fetch: 24 * 1024, requests: 6 },
	},
	'member blocks': {
		path: seed.pages.blocks.link,
		budget: { script: 24 * 1024, stylesheet: 14 * 1024, fetch: 24 * 1024, requests: 20 },
	},
};

const readers = {
	guest: '',
	'gold member': seed.cookies.gold,
};

for ( const [ reader, cookie ] of Object.entries( readers ) ) {
	for ( const [ name, { path, budget } ] of Object.entries( pages ) ) {
		test( `${ reader } › ${ name }`, async ( { page, context, baseURL } ) => {
			if ( cookie ) {
				const [ key, ...value ] = cookie.split( '=' );
				await context.addCookies( [ { name: key, value: value.join( '=' ), url: baseURL } ] );
			}

			const ours = [];
			page.on( 'requestfinished', ( request ) => {
				// The plugin's own files, and island fragments it asks Blockstudio for.
				if ( /\/plugins\/redeemid\/|blockstudio/.test( request.url() ) ) {
					ours.push( { request, sizes: request.sizes() } );
				}
			} );

			await page.goto( path );
			await page.waitForLoadState( 'networkidle' );

			const totals = { script: 0, stylesheet: 0, fetch: 0, requests: ours.length };
			const lines = [];

			for ( const { request, sizes } of ours ) {
				const { responseBodySize, responseHeadersSize } = await sizes;
				const bytes = responseBodySize + responseHeadersSize;
				const type = request.resourceType();

				if ( type in totals ) {
					totals[ type ] += bytes;
				}

				lines.push( `${ type } ${ bytes } ${ request.url() }` );
			}

			// One line per page in the CI log, to see the trend.
			// eslint-disable-next-line no-console
			console.log( `${ reader } › ${ name }: ${ JSON.stringify( totals ) }` );
			const over = Object.entries( budget ).filter( ( [ key, max ] ) => totals[ key ] > max );
			expect( over.map( ( [ key, max ] ) => `${ key }: ${ totals[ key ] } > ${ max }` ), lines.join( '\n' ) ).toEqual( [] );
		} );
	}
}
