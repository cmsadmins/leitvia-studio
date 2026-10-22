// Labels and texts shared by the inbox list and the detail view of a change package.
import { __, _n, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

export const STATES = [
	{ value: 'open', label: __( 'Open', 'leitvia-studio' ) },
	{ value: 'partial', label: __( 'Partly applied', 'leitvia-studio' ) },
	{ value: 'applied', label: __( 'Applied', 'leitvia-studio' ) },
	{ value: 'rejected', label: __( 'Rejected', 'leitvia-studio' ) },
	{ value: 'rolled_back', label: __( 'Rolled back', 'leitvia-studio' ) },
];

const OPERATION_STATUSES = {
	open: __( 'Open', 'leitvia-studio' ),
	conflict: __( 'Conflict', 'leitvia-studio' ),
	applied: __( 'Applied', 'leitvia-studio' ),
	rolled_back: __( 'Rolled back', 'leitvia-studio' ),
};

export const LEVELS = {
	locked: __( 'Locked', 'leitvia-studio' ),
	suggest: __( 'Suggest', 'leitvia-studio' ),
	draft: __( 'Draft', 'leitvia-studio' ),
	autonomous: __( 'Autonomous', 'leitvia-studio' ),
};

export const AREAS = {
	content: __( 'Content', 'leitvia-studio' ),
	corrections: __( 'Corrections', 'leitvia-studio' ),
	structure: __( 'Structure', 'leitvia-studio' ),
	design: __( 'Design', 'leitvia-studio' ),
	admin: __( 'Administration', 'leitvia-studio' ),
	products: __( 'Products', 'leitvia-studio' ),
};

export const KINDS = {
	prices: __( 'Prices', 'leitvia-studio' ),
	legal: __( 'Legal texts', 'leitvia-studio' ),
	slugs: __( 'Slugs', 'leitvia-studio' ),
	organization: __( 'Organization data', 'leitvia-studio' ),
	tax: __( 'Taxes', 'leitvia-studio' ),
	shipping: __( 'Shipping', 'leitvia-studio' ),
};

const CHANGES = {
	added: __( 'Added', 'leitvia-studio' ),
	removed: __( 'Removed', 'leitvia-studio' ),
	moved: __( 'Moved', 'leitvia-studio' ),
	changed: __( 'Changed', 'leitvia-studio' ),
};

export const stateLabel = ( value ) =>
	STATES.find( ( state ) => state.value === value )?.label ?? value;
export const operationStatus = ( value ) =>
	OPERATION_STATUSES[ value ] ?? value;
export const changeLabel = ( value ) => CHANGES[ value ] ?? value;

export const inboxUrl = ( id ) =>
	addQueryArgs( 'admin.php', {
		page: 'leitvia-studio-inbox',
		...( id ? { package: id } : {} ),
	} );

/**
 * Date and time of a UTC timestamp from the database in the language of the admin.
 *
 * @param {string} value Y-m-d H:i:s in UTC.
 * @return {string} Text.
 */
export function formatDate( value ) {
	if ( ! value ) {
		return '';
	}
	return new Date( value.replace( ' ', 'T' ) + 'Z' ).toLocaleString(
		document.documentElement.lang || undefined
	);
}

/**
 * Result of the checks before writing: number of findings and the lowest composition score.
 *
 * @param {{findings: number, score: ?number}} checks Checks of a package summary.
 * @return {string} Text.
 */
export function checksText( checks ) {
	const parts = [
		checks?.findings
			? sprintf(
					/* translators: %d: number of check findings. */
					_n(
						'%d finding',
						'%d findings',
						checks.findings,
						'leitvia-studio'
					),
					checks.findings
				)
			: __( 'No findings', 'leitvia-studio' ),
	];
	if ( null !== checks?.score && undefined !== checks?.score ) {
		parts.push(
			sprintf(
				/* translators: %d: composition score from 0 to 100. */
				__( 'composition score %d of 100', 'leitvia-studio' ),
				checks.score
			)
		);
	}
	return parts.join( ', ' );
}

/**
 * Result of the check after writing (meta _leitvia_package_verify): status, what happened, new and known findings.
 *
 * @param {?Object} report Verify report or null.
 * @return {string} Text.
 */
export function verifyText( report ) {
	if ( ! report ) {
		return __( 'No report', 'leitvia-studio' );
	}
	const status =
		{
			ok: __( 'Passed', 'leitvia-studio' ),
			failed: __( 'Failed', 'leitvia-studio' ),
			unverifiable: __( 'Not verifiable', 'leitvia-studio' ),
		}[ report.status ] ?? report.status;
	const parts = [ status ];
	if ( 'rolled_back' === report.action ) {
		parts.push( __( 'rolled back automatically', 'leitvia-studio' ) );
	}
	const found = report.findings?.length ?? 0;
	if ( found ) {
		parts.push(
			sprintf(
				/* translators: %d: number of findings the change brought. */
				_n(
					'%d new finding',
					'%d new findings',
					found,
					'leitvia-studio'
				),
				found
			)
		);
	}
	const known = report.known?.length ?? 0;
	if ( known ) {
		parts.push(
			sprintf(
				/* translators: %d: number of findings the page had before the change. */
				_n(
					'%d known finding',
					'%d known findings',
					known,
					'leitvia-studio'
				),
				known
			)
		);
	}
	return parts.join( ', ' );
}

/**
 * Short text of a finding: its message, else its code.
 *
 * @param {Object} finding Finding of a check.
 * @return {string} Text.
 */
export const findingText = ( finding ) =>
	finding.message || finding.code || finding.rule || '';
