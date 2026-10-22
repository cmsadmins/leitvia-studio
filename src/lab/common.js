// Labels and URLs shared by the Lab views.
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

export const STATUSES = [
	{ value: 'draft', label: __( 'Draft', 'leitvia-studio' ) },
	{ value: 'review', label: __( 'In review', 'leitvia-studio' ) },
	{ value: 'approved', label: __( 'Approved', 'leitvia-studio' ) },
	{ value: 'deprecated', label: __( 'Deprecated', 'leitvia-studio' ) },
];

export const statusLabel = ( value ) =>
	STATUSES.find( ( status ) => status.value === value )?.label ?? value;

// Pattern states in the order the States panel offers them; labels match Pattern_Meta::state_label().
export const STATES = [
	{ value: 'long', label: __( 'Long text', 'leitvia-studio' ) },
	{ value: 'empty', label: __( 'Empty', 'leitvia-studio' ) },
	{ value: 'error', label: __( 'Error', 'leitvia-studio' ) },
	{ value: 'disabled', label: __( 'Disabled', 'leitvia-studio' ) },
];

export const stateLabel = ( value ) =>
	STATES.find( ( state ) => state.value === value )?.label ?? value;

// Viewport widths of the Lab previews and checks, in px.
export const VIEWPORTS = [ 390, 782, 1440 ];

export const settings = window.leitviaStudioAdmin ?? {
	variations: [],
	labPanels: [],
	previewUrl: '',
	userId: 0,
};

/**
 * URL of the Lab, or of the detail view of one pattern.
 *
 * @param {string} [ref] Pattern reference.
 * @return {string} URL.
 */
export const labUrl = ( ref ) =>
	addQueryArgs(
		'admin.php',
		ref
			? { page: 'leitvia-studio', pattern: ref }
			: { page: 'leitvia-studio' }
	);

/**
 * URL of the front-end preview.
 *
 * @param {string} ref   Pattern reference.
 * @param {string} style Variation slug, '' for the theme default.
 * @param {Object} args  More query arguments, for example { axe: 1 } or { set: 12 }.
 * @return {string} URL.
 */
export const previewUrl = ( ref, style = '', args = {} ) =>
	addQueryArgs( settings.previewUrl, {
		ref,
		...( style ? { style } : {} ),
		...args,
	} );
