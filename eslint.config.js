// WordPress JavaScript coding standards (@wordpress/eslint-plugin), the JS
// side of the WordPress-VIP-Go PHPCS rules. Run with: npm run lint:js
const wordpress = require( '@wordpress/eslint-plugin' );
const globals = require( 'globals' );

module.exports = [
	{
		ignores: [ 'vendor/**', 'blocks/*/_dist/**', 'test-results/**', 'playwright-report/**', '.lighthouseci/**' ],
	},
	...wordpress.configs[ 'recommended-with-formatting' ],
	{
		languageOptions: { globals: globals.browser },
	},
	{
		files: [ '*.config.js', 'lighthouserc.js', 'tests/**' ],
		languageOptions: { globals: globals.node },
	},
];
