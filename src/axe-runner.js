// Runs axe-core in a Lab preview (Preview loads it with axe=1 only) and reports the violations to the Lab frame.
// Same WCAG tags as the Playwright suite in tests/e2e/a11y.spec.mjs. A failed run is reported at once, so the Lab
// does not wait for its time limit.
import axe from 'axe-core';

const parentOrigin = window.location.origin;

window.addEventListener( 'load', async () => {
	try {
		const result = await axe.run( document, {
			runOnly: {
				type: 'tag',
				values: [
					'wcag2a',
					'wcag2aa',
					'wcag21a',
					'wcag21aa',
					'wcag22aa',
				],
			},
		} );
		window.parent.postMessage(
			{
				type: 'leitvia-studio:axe',
				violations: result.violations.map( ( v ) => ( {
					id: v.id,
					impact: v.impact,
					help: v.help,
					nodes: v.nodes.map( ( n ) => n.target.join( ' ' ) ),
				} ) ),
			},
			parentOrigin
		);
	} catch ( error ) {
		window.parent.postMessage(
			{
				type: 'leitvia-studio:axe',
				error: String( error?.message ?? error ),
			},
			parentOrigin
		);
	}
} );
