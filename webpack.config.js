const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	output: {
		...defaultConfig.output,
		clean: {
			// Keep plugin-owned static assets alongside generated build files.
			keep: /^(css|fonts|images)\//,
		},
	},
};
