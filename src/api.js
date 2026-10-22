// Public JS API of Leitvia Studio, handle leitvia-studio-api, global window.leitviaStudio.
import { applyFilters } from '@wordpress/hooks';

const panels = new Map();

/**
 * Register a React component as a panel in the Lab detail view. The PHP lab_panels entry decides who sees it.
 *
 * @param {string}                                                 name      Name of the PHP lab_panels entry.
 * @param {import('react').ComponentType<Record<string, unknown>>} Component React component.
 */
export function registerLabPanel( name, Component ) {
	if ( panels.has( name ) ) {
		throw new Error( `Lab panel "${ name }" is already registered` );
	}
	panels.set( name, Component );
}

/**
 * All registered Lab panels, filterable with leitvia-studio.labPanels.
 *
 * @return {Map<string, import('react').ComponentType<Record<string, unknown>>>} Panels by name.
 */
export function getLabPanels() {
	return applyFilters( 'leitvia-studio.labPanels', new Map( panels ) );
}

window.leitviaStudio = Object.freeze( { registerLabPanel, getLabPanels } );
