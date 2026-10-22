// Lab panel "Checks": token lint findings and an axe run in every style variation, viewport and state.
import { __, _n, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';
import { useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Button, Flex, Notice } from '@wordpress/components';
import { VIEWPORTS, previewUrl, settings, stateLabel } from './common';
import { originalRef, useStates } from './states-panel';
import { notify } from '../admin/notify';

// Limits of the schema of _leitvia_lab_checks.axe.
const MAX_RUNS = 200;
const MAX_VIOLATIONS = 100;
const MAX_NODES = 20;
const TIMEOUT = 20000;

/**
 * Loads one preview with axe=1 into a hidden frame and waits for its report.
 *
 * @param {HTMLElement} container Element that holds the frame.
 * @param {string}      src       Preview URL.
 * @param {number}      width     Viewport width in px.
 * @return {Promise<{violations?: Array, error?: string}>} Violations, or the error of a failed or timed-out run.
 */
function runAxe( container, src, width ) {
	return new Promise( ( resolve ) => {
		const origin = new URL( src, window.location.href ).origin;
		const frame = document.createElement( 'iframe' );
		const onMessage = ( event ) => {
			if (
				event.origin === origin &&
				event.source === frame.contentWindow &&
				'leitvia-studio:axe' === event.data?.type
			) {
				done(
					Array.isArray( event.data.violations )
						? { violations: event.data.violations }
						: { error: String( event.data.error ?? '' ) }
				);
			}
		};
		const done = ( result ) => {
			window.clearTimeout( timer );
			window.removeEventListener( 'message', onMessage );
			frame.remove();
			resolve( result );
		};
		const timer = window.setTimeout(
			() =>
				done( {
					error: __(
						'The check did not finish in time.',
						'leitvia-studio'
					),
				} ),
			TIMEOUT
		);
		window.addEventListener( 'message', onMessage );
		// Kept out of view, the tab order and the accessibility tree, but laid out at its real width for axe.
		frame.className = 'leitvia-studio-axe-frame';
		frame.width = String( width );
		frame.height = '900';
		frame.tabIndex = -1;
		frame.setAttribute( 'aria-hidden', 'true' );
		frame.title = __( 'Accessibility check', 'leitvia-studio' );
		frame.src = src;
		container.appendChild( frame );
	} );
}

const cap = ( violations ) =>
	violations.slice( 0, MAX_VIOLATIONS ).map( ( violation ) => ( {
		id: String( violation.id ).slice( 0, 100 ),
		impact: violation.impact ?? null,
		help: String( violation.help ).slice( 0, 500 ),
		nodes: violation.nodes
			.slice( 0, MAX_NODES )
			.map( ( node ) => String( node ).slice( 0, 500 ) ),
	} ) );

/**
 * Number of violations in a stored result.
 *
 * @param {Object} axe Violations per combination.
 * @return {number} Number of violations.
 */
export const countIssues = ( axe ) =>
	Object.values( axe ).reduce( ( sum, list ) => sum + list.length, 0 );

/**
 * Whether the pattern or one of its states changed after the last accessibility check.
 *
 * @param {Object} item Lab item with checks and modified.
 * @return {boolean} Outdated.
 */
export const isOutdated = ( item ) =>
	!! item?.checks?.axe_checked_at &&
	!! item.modified &&
	new Date( item.modified ) > new Date( item.checks.axe_checked_at );

function TokenFindings( { tokens } ) {
	if ( ! tokens ) {
		return (
			<p>
				{ __(
					'The design tokens are not checked yet.',
					'leitvia-studio'
				) }
			</p>
		);
	}
	if ( ! tokens.count ) {
		return <p>{ __( 'No raw values found.', 'leitvia-studio' ) }</p>;
	}
	return (
		<>
			<p>
				{ sprintf(
					/* translators: %d: number of findings. */
					_n(
						'%d raw value where a design token belongs:',
						'%d raw values where design tokens belong:',
						tokens.count,
						'leitvia-studio'
					),
					tokens.count
				) }
			</p>
			<ul className="leitvia-studio-check-list">
				{ tokens.findings.map( ( finding, index ) => (
					<li key={ index }>
						<code>{ finding.rule }</code>:{ ' ' }
						<code>{ finding.match }</code>
					</li>
				) ) }
			</ul>
		</>
	);
}

function Results( { rows } ) {
	return (
		<table className="widefat striped leitvia-studio-checks-table">
			<caption>
				{ __(
					'Accessibility violations per combination',
					'leitvia-studio'
				) }
			</caption>
			<thead>
				<tr>
					<th scope="col">{ __( 'Pattern', 'leitvia-studio' ) }</th>
					<th scope="col">
						{ __( 'Style variation', 'leitvia-studio' ) }
					</th>
					<th scope="col">{ __( 'Viewport', 'leitvia-studio' ) }</th>
					<th scope="col">
						{ __( 'Violations', 'leitvia-studio' ) }
					</th>
				</tr>
			</thead>
			<tbody>
				{ rows.map( ( row ) => (
					<tr key={ row.key }>
						<td>
							{ 'original' === row.state
								? __( 'Original', 'leitvia-studio' )
								: stateLabel( row.state ) }
						</td>
						<td>
							{ settings.variations.find(
								( variation ) => variation.slug === row.style
							)?.title ?? __( 'Default', 'leitvia-studio' ) }
						</td>
						<td>
							{ sprintf(
								/* translators: %d: width in pixels. */
								__( '%d px', 'leitvia-studio' ),
								row.width
							) }
						</td>
						<td>
							{ row.error &&
								sprintf(
									/* translators: %s: error message. */
									__(
										'The check failed: %s',
										'leitvia-studio'
									),
									row.error
								) }
							{ row.violations?.length === 0 &&
								__( 'No issues', 'leitvia-studio' ) }
							{ !! row.violations?.length && (
								<ul className="leitvia-studio-check-list">
									{ row.violations.map( ( violation ) => (
										<li key={ violation.id }>
											<code>{ violation.id }</code>{ ' ' }
											{ sprintf(
												/* translators: 1: rule description, 2: impact such as serious, 3: number of elements. */
												_n(
													'%1$s (impact: %2$s, %3$d element)',
													'%1$s (impact: %2$s, %3$d elements)',
													violation.nodes.length,
													'leitvia-studio'
												),
												violation.help,
												violation.impact ??
													__(
														'unknown',
														'leitvia-studio'
													),
												violation.nodes.length
											) }
										</li>
									) ) }
								</ul>
							) }
						</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

/**
 * Table rows from stored results, keyed "<style>|<viewport>|<state>".
 *
 * @param {Object} axe Violations per combination.
 * @return {Array} Rows.
 */
const storedRows = ( axe ) =>
	Object.entries( axe ).map( ( [ key, violations ] ) => {
		const [ style, width, state ] = key.split( '|' );
		return {
			key,
			style: 'default' === style ? '' : style,
			width: Number( width ),
			state: state ?? 'original',
			violations,
		};
	} );

export default function ChecksPanel( { pattern, block, onChange } ) {
	const ref = originalRef( pattern, block );
	const [ states ] = useStates( ref );
	const [ rows, setRows ] = useState( null );
	const [ progress, setProgress ] = useState( '' );
	const [ isRunning, setIsRunning ] = useState( false );
	const container = useRef();
	const id = block?.id;
	const stored = pattern.checks?.axe;

	const run = async () => {
		const targets = [
			{ ref: pattern.ref, state: 'original' },
			...( states ?? [] ).map( ( item ) => ( {
				ref: item.ref,
				state: item.state,
			} ) ),
		];
		const combinations = targets
			.flatMap( ( target ) =>
				[ '', ...settings.variations.map( ( v ) => v.slug ) ].flatMap(
					( style ) =>
						VIEWPORTS.map( ( width ) => ( {
							...target,
							style,
							width,
							key: `${ style || 'default' }|${ width }|${
								target.state
							}`,
						} ) )
				)
			)
			.slice( 0, MAX_RUNS );

		setIsRunning( true );
		setRows( null );
		speak(
			sprintf(
				/* translators: %d: number of checks. */
				__(
					'Accessibility check started: %d combinations.',
					'leitvia-studio'
				),
				combinations.length
			)
		);
		const results = [];
		for ( const [ index, combination ] of combinations.entries() ) {
			setProgress(
				sprintf(
					/* translators: 1: number of the current check, 2: number of checks. */
					__( 'Checking %1$d of %2$d', 'leitvia-studio' ),
					index + 1,
					combinations.length
				)
			);
			const result = await runAxe(
				container.current,
				previewUrl( combination.ref, combination.style, { axe: 1 } ),
				combination.width
			);
			results.push( {
				...combination,
				violations: result.violations ? cap( result.violations ) : null,
				error: result.error,
			} );
		}
		setRows( results );

		const finished = results.filter( ( row ) => row.violations );
		const axe = Object.fromEntries(
			finished.map( ( row ) => [ row.key, row.violations ] )
		);
		const issues = countIssues( axe );
		// An own pattern keeps the results of itself and its states; a theme pattern has no post, so each of its
		// states keeps its own results. Nothing is stored for a pattern none of whose pages reported, so an old
		// result is not replaced by an empty one.
		const targetsToSave = id
			? [ { id, rows: finished } ]
			: ( states ?? [] ).map( ( item ) => ( {
					id: Number( item.ref.slice( 6 ) ),
					rows: finished.filter( ( row ) => row.ref === item.ref ),
				} ) );
		const summary = sprintf(
			/* translators: %d: number of violations. */
			_n(
				'Accessibility check finished: %d issue.',
				'Accessibility check finished: %d issues.',
				issues,
				'leitvia-studio'
			),
			issues
		);
		try {
			for ( const target of targetsToSave.filter(
				( item ) => item.rows.length
			) ) {
				await apiFetch( {
					path: `/leitvia-studio/v1/patterns/${ target.id }/checks`,
					method: 'POST',
					data: {
						axe: Object.fromEntries(
							target.rows.map( ( row ) => [
								row.key,
								row.violations,
							] )
						),
					},
				} );
			}
			onChange();
		} catch ( error ) {
			notify(
				'error',
				error.message ??
					__( 'The results could not be saved.', 'leitvia-studio' )
			);
		}
		setProgress( summary );
		speak( summary );
		setIsRunning( false );
	};

	const shown = rows ?? ( stored ? storedRows( stored ) : null );

	return (
		<Flex direction="column" justify="flex-start" gap={ 4 } align="stretch">
			<h4>{ __( 'Design tokens', 'leitvia-studio' ) }</h4>
			<TokenFindings tokens={ pattern.checks?.tokens } />
			<h4>{ __( 'Accessibility', 'leitvia-studio' ) }</h4>
			<p className="description">
				{ __(
					'Runs axe in every style variation, viewport and state. The result is for information; the binding check is the automated test suite.',
					'leitvia-studio'
				) }
			</p>
			{ ! id && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'Results of a theme pattern itself are shown but not stored; the results of its states are stored with the states.',
						'leitvia-studio'
					) }
				</Notice>
			) }
			{ ! rows && isOutdated( pattern ) && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Outdated: the pattern or one of its states changed after the last check. Run the check again.',
						'leitvia-studio'
					) }
				</Notice>
			) }
			<div>
				<Button
					__next40pxDefaultSize
					variant="secondary"
					isBusy={ isRunning }
					disabled={ isRunning || null === states }
					accessibleWhenDisabled
					onClick={ run }
				>
					{ __( 'Run accessibility check', 'leitvia-studio' ) }
				</Button>
			</div>
			{ /* Visible progress; start and end are announced through speak(), not every step. */ }
			<p className="leitvia-studio-checks-progress">{ progress }</p>
			<div ref={ container } />
			{ shown && <Results rows={ shown } /> }
		</Flex>
	);
}
