// Inbox detail: one change package with the diff of each operation, a checkbox per open operation, and the buttons to
// apply the selected operations, reject the package or roll it back. Status messages go to the live region.
import { __, _n, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	CheckboxControl,
	Flex,
	Notice,
	Spinner,
} from '@wordpress/components';
import { notify } from '../notify';
import {
	AREAS,
	KINDS,
	LEVELS,
	changeLabel,
	checksText,
	findingText,
	formatDate,
	inboxUrl,
	operationStatus,
	stateLabel,
	verifyText,
} from './common';

const path = ( id, action = '' ) =>
	`/leitvia-studio/v1/packages/${ id }${ action ? '/' + action : '' }`;

const selectable = ( item ) =>
	'open' === item.status || 'conflict' === item.status;

/**
 * Sentence about a target someone changed: who saved it last and both hashes in plain text.
 *
 * @param {string} who    Display name of the last editor, may be empty.
 * @param {string} when   What the change came after.
 * @param {string} before Hash the operation expects.
 * @param {string} now    Current hash.
 * @return {string} Text.
 */
function conflictText( who, when, before, now ) {
	const changed = who
		? sprintf(
				/* translators: 1: name of a person, 2: for example "after the agent read it". */
				__( 'Changed by %1$s %2$s.', 'leitvia-studio' ),
				who,
				when
			)
		: sprintf(
				/* translators: %s: for example "after the agent read it". */
				__( 'Changed %s.', 'leitvia-studio' ),
				when
			);
	return sprintf(
		/* translators: 1: sentence about the change, 2: hash expected, 3: current hash. */
		__( '%1$s Expected hash: %2$s. Current hash: %3$s.', 'leitvia-studio' ),
		changed,
		before,
		now || __( 'none, the target is gone', 'leitvia-studio' )
	);
}

function Diff( { item } ) {
	if ( item.diff_error ) {
		return (
			<p>
				{ __(
					'The changes of this operation cannot be shown. Use the preview.',
					'leitvia-studio'
				) }
			</p>
		);
	}
	if ( ! item.diff?.length ) {
		return null;
	}
	return (
		<div className="leitvia-studio-inbox__diff">
			<table className="widefat striped">
				<caption>
					{ sprintf(
						/* translators: %d: operation number. */
						__( 'Changes of operation %d', 'leitvia-studio' ),
						item.id
					) }
				</caption>
				<thead>
					<tr>
						<th scope="col">
							{ __( 'Change', 'leitvia-studio' ) }
						</th>
						<th scope="col">
							{ __( 'Block or field', 'leitvia-studio' ) }
						</th>
						<th scope="col">
							{ __( 'Before', 'leitvia-studio' ) }
						</th>
						<th scope="col">{ __( 'After', 'leitvia-studio' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ item.diff.map( ( change, index ) => (
						<tr key={ index }>
							<td>
								{ changeLabel( change.change ) }
								{ change.attrs_changed?.length > 0 && (
									<>
										<br />
										{ sprintf(
											/* translators: %s: list of attribute names. */
											__(
												'Attributes: %s',
												'leitvia-studio'
											),
											change.attrs_changed.join( ', ' )
										) }
									</>
								) }
							</td>
							<td>
								<code>{ change.id }</code>
							</td>
							<td>{ change.before_text }</td>
							<td>{ change.after_text }</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}

function Operation( { item, checked, onToggle } ) {
	const heading = `leitvia-studio-op-${ item.id }`;
	const facts = [
		sprintf(
			/* translators: %s: status of the operation. */
			__( 'Status: %s', 'leitvia-studio' ),
			operationStatus( item.status )
		),
		sprintf(
			/* translators: %s: area such as Content. */
			__( 'Area: %s', 'leitvia-studio' ),
			AREAS[ item.area ] ?? item.area
		),
		sprintf(
			/* translators: %s: autonomy level such as Draft. */
			__( 'Autonomy level: %s', 'leitvia-studio' ),
			LEVELS[ item.level ] ?? item.level
		),
	];
	if ( item.kinds?.length ) {
		facts.push(
			sprintf(
				/* translators: %s: list of content kinds such as Prices. */
				__( 'Content kinds: %s', 'leitvia-studio' ),
				item.kinds.map( ( kind ) => KINDS[ kind ] ?? kind ).join( ', ' )
			)
		);
	}
	return (
		<section
			className="leitvia-studio-inbox__operation"
			aria-labelledby={ heading }
		>
			<h4 id={ heading }>
				{ sprintf(
					/* translators: 1: operation number, 2: ability name. */
					__( 'Operation %1$d: %2$s', 'leitvia-studio' ),
					item.id,
					item.ability
				) }
			</h4>
			{ selectable( item ) && (
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ sprintf(
						/* translators: %d: operation number. */
						__( 'Apply operation %d', 'leitvia-studio' ),
						item.id
					) }
					checked={ checked }
					onChange={ onToggle }
				/>
			) }
			<p>{ facts.join( '. ' ) }.</p>
			{ item.conflict && (
				<Notice status="warning" isDismissible={ false }>
					{ conflictText(
						item.modified_by,
						__( 'after the agent read it', 'leitvia-studio' ),
						item.conflict.base_hash,
						item.conflict.current_hash
					) }
				</Notice>
			) }
			{ item.error && (
				<Notice status="error" isDismissible={ false }>
					{ item.error.message }
				</Notice>
			) }
			{ item.findings?.length > 0 && (
				<>
					<h5>{ __( 'Check findings', 'leitvia-studio' ) }</h5>
					<ul>
						{ item.findings.map( ( finding, index ) => (
							<li key={ index }>{ findingText( finding ) }</li>
						) ) }
					</ul>
				</>
			) }
			<Diff item={ item } />
		</section>
	);
}

export default function InboxDetail( { id } ) {
	const [ pkg, setPackage ] = useState( null );
	const [ selected, setSelected ] = useState( [] );
	const [ busy, setBusy ] = useState( '' );
	const [ rollbackConflicts, setRollbackConflicts ] = useState( [] );

	const load = useCallback(
		() =>
			apiFetch( { path: path( id ) } )
				.then( ( data ) => {
					setPackage( data );
					setSelected(
						data.items
							.filter( selectable )
							.map( ( item ) => item.id )
					);
				} )
				.catch( ( error ) => {
					setPackage( false );
					notify(
						'error',
						error.message ??
							__(
								'The change package could not be loaded.',
								'leitvia-studio'
							)
					);
				} ),
		[ id ]
	);

	useEffect( () => {
		load();
	}, [ load ] );

	if ( null === pkg ) {
		return <Spinner />;
	}
	const back = (
		<p>
			<a href={ inboxUrl() }>
				{ __( 'Back to the inbox', 'leitvia-studio' ) }
			</a>
		</p>
	);
	if ( ! pkg ) {
		return back;
	}

	const run = async ( action, data, done ) => {
		setBusy( action );
		setRollbackConflicts( [] );
		try {
			const result = await apiFetch( {
				path: path( id, action ),
				method: 'POST',
				data,
			} );
			await load();
			notify(
				result.conflicts?.length || result.errors?.length
					? 'error'
					: 'success',
				done( result )
			);
		} catch ( error ) {
			setRollbackConflicts( error.data?.conflicts ?? [] );
			notify(
				'error',
				error.message ??
					__( 'The step could not be completed.', 'leitvia-studio' )
			);
		} finally {
			setBusy( '' );
		}
	};

	const apply = ( event ) => {
		event.preventDefault();
		if ( ! selected.length ) {
			notify(
				'error',
				__( 'Select at least one operation.', 'leitvia-studio' )
			);
			return;
		}
		run( 'apply', { operations: selected }, ( report ) => {
			const parts = [
				sprintf(
					/* translators: %d: number of operations. */
					_n(
						'%d operation applied.',
						'%d operations applied.',
						report.applied.length,
						'leitvia-studio'
					),
					report.applied.length
				),
			];
			if ( report.conflicts.length ) {
				parts.push(
					sprintf(
						/* translators: %d: number of operations. */
						_n(
							'%d operation has a conflict.',
							'%d operations have a conflict.',
							report.conflicts.length,
							'leitvia-studio'
						),
						report.conflicts.length
					)
				);
			}
			if ( report.errors.length ) {
				parts.push(
					sprintf(
						/* translators: %d: number of operations. */
						_n(
							'%d operation was refused.',
							'%d operations were refused.',
							report.errors.length,
							'leitvia-studio'
						),
						report.errors.length
					)
				);
			}
			return parts.join( ' ' );
		} );
	};

	const open = pkg.items.filter( selectable );
	const canDecide = [ 'open', 'partial' ].includes( pkg.state );
	const canRollBack = [ 'applied', 'partial' ].includes( pkg.state );
	const facts = [
		[ __( 'Status', 'leitvia-studio' ), stateLabel( pkg.state ) ],
		[ __( 'Agent', 'leitvia-studio' ), pkg.agent ],
		[ __( 'Created', 'leitvia-studio' ), formatDate( pkg.created ) ],
		[ __( 'Check result', 'leitvia-studio' ), checksText( pkg.checks ) ],
		[
			__( 'Verify after writing', 'leitvia-studio' ),
			verifyText( pkg.verify ),
		],
	];

	return (
		<div className="leitvia-studio-inbox">
			{ back }
			<h2>{ pkg.title }</h2>
			<dl className="leitvia-studio-inbox__facts">
				{ facts.map( ( [ term, value ] ) => (
					<div key={ term }>
						<dt>{ term }</dt>
						<dd>{ value }</dd>
					</div>
				) ) }
			</dl>
			{ pkg.preview && (
				<p>
					<a
						href={ pkg.preview }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ __(
							'Preview the changes (opens in a new tab)',
							'leitvia-studio'
						) }
					</a>
				</p>
			) }
			{ pkg.verify?.findings?.length > 0 && (
				<>
					<h3>
						{ __( 'Findings after writing', 'leitvia-studio' ) }
					</h3>
					<ul>
						{ pkg.verify.findings.map( ( finding, index ) => (
							<li key={ index }>{ findingText( finding ) }</li>
						) ) }
					</ul>
				</>
			) }
			{ rollbackConflicts.length > 0 && (
				<Notice status="warning" isDismissible={ false }>
					<ul>
						{ rollbackConflicts.map( ( conflict ) => (
							<li key={ conflict.op }>
								{ sprintf(
									/* translators: 1: operation number, 2: sentence about the change. */
									__(
										'Operation %1$d: %2$s',
										'leitvia-studio'
									),
									conflict.op,
									conflictText(
										pkg.items.find(
											( item ) => item.id === conflict.op
										)?.modified_by,
										__(
											'after the package was applied',
											'leitvia-studio'
										),
										conflict.applied_hash,
										conflict.current_hash
									)
								) }
							</li>
						) ) }
					</ul>
				</Notice>
			) }
			<form onSubmit={ apply }>
				<h3>{ __( 'Operations', 'leitvia-studio' ) }</h3>
				{ pkg.items.map( ( item ) => (
					<Operation
						key={ item.id }
						item={ item }
						checked={ selected.includes( item.id ) }
						onToggle={ ( on ) =>
							setSelected( ( current ) =>
								on
									? [ ...current, item.id ]
									: current.filter(
											( value ) => value !== item.id
										)
							)
						}
					/>
				) ) }
				<Flex justify="flex-start" wrap>
					{ canDecide && open.length > 0 && (
						<Button
							__next40pxDefaultSize
							type="submit"
							variant="primary"
							isBusy={ 'apply' === busy }
							disabled={ '' !== busy }
							accessibleWhenDisabled
						>
							{ __( 'Apply selected', 'leitvia-studio' ) }
						</Button>
					) }
					{ canDecide && (
						<Button
							__next40pxDefaultSize
							variant="secondary"
							isBusy={ 'reject' === busy }
							disabled={ '' !== busy }
							accessibleWhenDisabled
							onClick={ () =>
								run( 'reject', {}, () =>
									__(
										'Change package rejected.',
										'leitvia-studio'
									)
								)
							}
						>
							{ __( 'Reject', 'leitvia-studio' ) }
						</Button>
					) }
					{ canRollBack && (
						<Button
							__next40pxDefaultSize
							variant="secondary"
							isBusy={ 'rollback' === busy }
							disabled={ '' !== busy }
							accessibleWhenDisabled
							onClick={ () =>
								run( 'rollback', {}, () =>
									__(
										'Change package rolled back.',
										'leitvia-studio'
									)
								)
							}
						>
							{ __( 'Roll back', 'leitvia-studio' ) }
						</Button>
					) }
				</Flex>
			</form>
		</div>
	);
}
