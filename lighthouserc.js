// Lighthouse budgets (mobile, simulated throttling) for the pages RedeemID
// shapes most: the locked post a guest meets and the member blocks page.
// tests/Browser/weight.spec.js budgets RedeemID's own files. CI's
// "Performance" job runs both against the seeded WordPress
// (tests/Integration/seed.php).
const fs = require( 'node:fs' );

const seed = JSON.parse( fs.readFileSync( process.env.REDEEMID_LEAK_SEED || '/tmp/redeemid-leak.json', 'utf8' ) );

const escape = ( url ) => url.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) + '$';

const budget = ( lcp ) => ( {
	'categories:performance': [ 'error', { minScore: 0.9 } ],
	'largest-contentful-paint': [ 'error', { maxNumericValue: lcp } ],
	'cumulative-layout-shift': [ 'error', { maxNumericValue: 0.1 } ],
	'total-blocking-time': [ 'error', { maxNumericValue: 200 } ],
	'server-response-time': [ 'error', { maxNumericValue: 600 } ],
} );

module.exports = {
	ci: {
		collect: {
			url: [ seed.posts.break.link, seed.pages.blocks.link ],
			// The median of three runs, so one slow request doesn't fail the job.
			numberOfRuns: 3,
			settings: {
				onlyCategories: [ 'performance' ],
				chromeFlags: '--no-sandbox --headless=new',
			},
		},
		assert: {
			assertMatrix: [
				{ matchingUrlPattern: escape( seed.posts.break.link ), assertions: budget( 2500 ) },
				// Every member block on one page: each brings its own stylesheet
				// (Blockstudio enqueues one file per block), so eleven
				// render-blocking requests hold LCP back on a throttled phone.
				// No real page carries them all; this guards the trend.
				{ matchingUrlPattern: escape( seed.pages.blocks.link ), assertions: budget( 3000 ) },
			],
		},
		upload: {
			target: 'filesystem',
			outputDir: '.lighthouseci',
		},
	},
};
