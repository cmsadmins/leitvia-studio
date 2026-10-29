// Adds the direct access guard of WordPress to build/blocks-manifest.php, which `wp-scripts build --blocks-manifest`
// writes without one (Plugin Check: missing_direct_file_access_protection). Run from the plugin folder after the build.
import { readFileSync, writeFileSync } from 'node:fs';

const file = 'build/blocks-manifest.php';
const guard = "defined( 'ABSPATH' ) || exit;\n";
const php = readFileSync( file, 'utf8' );
if ( ! php.includes( guard ) ) {
	writeFileSync( file, php.replace( /^<\?php\n/, `<?php\n${ guard }` ) );
}
if ( ! readFileSync( file, 'utf8' ).startsWith( `<?php\n${ guard }` ) ) {
	throw new Error( `${ file }: could not add the ABSPATH guard` );
}
