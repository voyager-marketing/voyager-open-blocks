/**
 * wp-scripts' default ESLint config, plus two repo facts:
 *
 * - @wordpress/* packages imported by blocks are not npm dependencies. The
 *   build marks them external and WordPress supplies them at runtime.
 * - scripts/ are command-line tools, so they print with console.
 */
const defaults = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...defaults,
	{
		files: [ 'src/**' ],
		settings: {
			'import/core-modules': [
				'@wordpress/block-editor',
				'@wordpress/blocks',
				'@wordpress/components',
				'@wordpress/i18n',
			],
		},
	},
	{
		files: [ 'scripts/**' ],
		rules: { 'no-console': 'off' },
	},
];
