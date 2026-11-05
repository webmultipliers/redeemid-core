// Lighthouse budgets (mobile, simulated throttling) for the pages RedeemID
// shapes most: the locked post a guest meets and the member blocks page.
// tests/Browser/weight.spec.js budgets RedeemID's own files. CI's
// "Performance" job runs both against the seeded WordPress
// (tests/Integration/seed.php).
const fs = require( 'node:fs' );

const seed = JSON.parse( fs.readFileSync( process.env.REDEEMID_LEAK_SEED || '/tmp/redeemid-leak.json', 'utf8' ) );

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
			assertions: {
				'categories:performance': [ 'error', { minScore: 0.9 } ],
				'largest-contentful-paint': [ 'error', { maxNumericValue: 2500 } ],
				'cumulative-layout-shift': [ 'error', { maxNumericValue: 0.1 } ],
				'total-blocking-time': [ 'error', { maxNumericValue: 200 } ],
				'server-response-time': [ 'error', { maxNumericValue: 600 } ],
			},
		},
		upload: {
			target: 'filesystem',
			outputDir: '.lighthouseci',
		},
	},
};
