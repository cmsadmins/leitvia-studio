// Every file directly in src/ is one entry point (src/admin.js becomes build/admin.js and build/admin.asset.php);
// modules live in subfolders. A new entry needs no change here or in package.json.
//
// Blocks: every src/blocks/<name>/block.json adds its own entries (editorScript, viewScript, style and so on), found
// by @wordpress/scripts 36.1.0. `--experimental-modules` (WP_EXPERIMENTAL_MODULES) makes the default config an array
// [ scriptConfig, moduleConfig ]: the second builds the viewScriptModule files of block.json as ES modules. Without
// the flag the default is the script config object alone. `--blocks-manifest` (WP_BLOCKS_MANIFEST) writes
// build/blocks-manifest.php after every emit, one entry per folder name, for wp_register_block_metadata_collection().
// The copy plugin of the default puts block.json next to the built files in build/blocks/<name>/; a second one copies
// catalog.json (usage rules of the block catalog, includes/blocks/class-catalog.php) the same way.
const { readdirSync } = require( 'node:fs' );
const CopyWebpackPlugin = require( 'copy-webpack-plugin' );
const { join } = require( 'node:path' );
const MiniCSSExtractPlugin = require( 'mini-css-extract-plugin' );
const rtlcss = require( 'rtlcss' );
const webpack = require( 'webpack' );
const defaults = require( '@wordpress/scripts/config/webpack.config' );

const [ defaultConfig, ...moduleConfigs ] = [].concat( defaults );
const { splitChunks } = defaultConfig.optimization;

/**
 * Writes <file>-rtl.css next to every CSS file, also next to the hashed CSS of lazy chunks, so src/admin.js can swap
 * the suffix at runtime. The plugin of @wordpress/scripts names the RTL file after the chunk and drops the hash.
 */
class RtlCssPlugin {
	apply( compiler ) {
		compiler.hooks.compilation.tap( 'LeitviaStudioRtlCss', ( compilation ) => {
			compilation.hooks.processAssets.tap( { name: 'LeitviaStudioRtlCss', stage: compilation.PROCESS_ASSETS_STAGE_OPTIMIZE }, () => {
				for ( const chunk of compilation.chunks ) {
					for ( const file of [ ...chunk.files ].filter( ( name ) => name.endsWith( '.css' ) ) ) {
						const rtl = file.replace( /\.css$/, '-rtl.css' );
						compilation.emitAsset( rtl, new webpack.sources.RawSource( rtlcss.process( compilation.assets[ file ].source().toString() ) ) );
						chunk.files.add( rtl );
					}
				}
			} );
		} );
	}
}

const scriptConfig = {
	...defaultConfig,
	entry: () => ( {
		...defaultConfig.entry(),
		...Object.fromEntries(
			readdirSync( join( __dirname, 'src' ) )
				.filter( ( file ) => file.endsWith( '.js' ) )
				.map( ( file ) => [ file.slice( 0, -3 ), join( __dirname, 'src', file ) ] )
		),
	} ),
	plugins: [
		...defaultConfig.plugins.filter( ( plugin ) => ! [ 'MiniCssExtractPlugin', 'RtlCssPlugin' ].includes( plugin.constructor.name ) ),
		// Entry CSS keeps its name (enqueued from PHP with a version); CSS of lazy chunks carries its content hash.
		new MiniCSSExtractPlugin( { filename: '[name].css', chunkFilename: '[id].[contenthash:8].css' } ),
		new RtlCssPlugin(),
		new CopyWebpackPlugin( {
			patterns: [ { from: 'blocks/*/catalog.json', context: join( __dirname, 'src' ) } ],
		} ),
	],
	optimization: {
		...defaultConfig.optimization,
		splitChunks: {
			...splitChunks,
			cacheGroups: {
				...splitChunks.cacheGroups,
				// The default names the style-<entry>.css file after the chunk, and lazy screen chunks
				// (src/admin/routes.js) have no name. Their style.scss stays in the chunk's own CSS file, which
				// webpack loads together with the chunk.
				style: { ...splitChunks.cacheGroups.style, chunks: 'initial' },
			},
		},
	},
};

// The script modules @leitvia/consent (includes/consent/class-consent.php) and @leitvia/code-copy
// (includes/blocks/class-code-copy.php) belong to no block.
// No split chunks: a lazy chunk keeps its dependencies, so Leaflet lands in build/blocks/map/leaflet.js (named in
// src/blocks/map/view.js) instead of a numbered vendor chunk in build/ next to the chunks of the script build.
const [ moduleConfig ] = moduleConfigs;
const consentModule = {
	...moduleConfig,
	entry: () => ( {
		...moduleConfig.entry(),
		'consent/view': join( __dirname, 'src/consent/view.js' ),
		'code-copy/view': join( __dirname, 'src/code-copy/view.js' ),
	} ),
	optimization: { ...moduleConfig.optimization, splitChunks: false },
};

module.exports = [ scriptConfig, consentModule ];
