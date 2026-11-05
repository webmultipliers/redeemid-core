// Accessibility checks against a running WordPress. CI's "Accessibility" job
// starts one; locally, point REDEEMID_LEAK_URL and REDEEMID_LEAK_SEED at yours.
const { defineConfig } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: 'tests/Browser',
	// PHP's built-in server answers one request at a time.
	workers: 1,
	forbidOnly: !! process.env.CI,
	reporter: process.env.CI ? [ [ 'github' ], [ 'list' ] ] : 'list',
	use: {
		baseURL: process.env.REDEEMID_LEAK_URL || 'http://127.0.0.1:8080',
	},
} );
