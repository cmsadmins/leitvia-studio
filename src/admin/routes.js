// Screens of the Studio menu. Each file in ./screens/ is one screen, named after its page slug (leitvia-studio.js is
// the Lab), and exports `title` and the screen component as default. A new screen needs no change here; webpack splits
// each screen into its own chunk, so a page loads only its own screen.
const screens = require.context(
	'./screens',
	false,
	/^\.\/[a-z0-9-]+\.js$/,
	'lazy'
);

/**
 * Loads the screen module of a page slug.
 *
 * @param {string} slug Page slug from data-screen.
 * @return {Promise<{ title: string, default: import('react').ComponentType }|null>} Module or null.
 */
export function loadScreen( slug ) {
	const key = `./${ slug }.js`;
	return screens.keys().includes( key )
		? screens( key )
		: Promise.resolve( null );
}
