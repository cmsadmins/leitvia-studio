// Test content of a pattern, a section of the Lab panel "Variants": long text, empty, error and disabled copies,
// checked in the Lab like the original. The REST routes still call them states.
import { __, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { Button, Flex } from '@wordpress/components';
import Loading from '../admin/loading';
import More from '../admin/more';
import { STATES, labUrl, stateLabel } from './common';
import { notify } from '../admin/notify';

/**
 * States of a pattern, loaded from GET /leitvia-studio/v1/patterns/states.
 *
 * @param {string} ref Reference of the original, '' when the pattern is a state itself.
 */
export function useStates( ref ) {
	const [ states, setStates ] = useState( null );
	const load = useCallback( () => {
		if ( ! ref ) {
			setStates( [] );
			return;
		}
		apiFetch( {
			path: addQueryArgs( '/leitvia-studio/v1/patterns/states', { ref } ),
		} )
			.then( setStates )
			.catch( () => setStates( [] ) );
	}, [ ref ] );
	useEffect( load, [ load ] );
	return [ states, load ];
}

/**
 * Reference of the original of a pattern, '' for a state or variant.
 *
 * @param {Object}      pattern Lab item.
 * @param {Object|null} block   wp_block post with its lab meta, null for theme patterns.
 * @return {string} Reference.
 */
export const originalRef = ( pattern, block ) =>
	block &&
	( block.meta._leitvia_lab_variant_of ||
		block.meta._leitvia_lab_source_pattern )
		? ''
		: pattern.ref;

/**
 * Where a variant or test content copy comes from, with a link to the original.
 *
 * @param {Object} props
 * @param {Object} props.block wp_block post of the copy.
 */
export function OriginalLink( { block } ) {
	const source = block.meta._leitvia_lab_variant_of
		? `block:${ block.meta._leitvia_lab_variant_of }`
		: `pattern:${ block.meta._leitvia_lab_source_pattern }`;
	return (
		<p>
			{ block.meta._leitvia_lab_state
				? sprintf(
						/* translators: %s: label of the test content such as Long text. */
						__(
							'This pattern is the test content "%s" of another pattern.',
							'leitvia-studio'
						),
						stateLabel( block.meta._leitvia_lab_state )
					)
				: __( 'This pattern is a variant.', 'leitvia-studio' ) }{ ' ' }
			<a href={ labUrl( source ) }>
				{ __( 'Open the original', 'leitvia-studio' ) }
			</a>
		</p>
	);
}

/**
 * Test content of an original pattern and buttons for the missing kinds.
 *
 * @param {Object} props
 * @param {string} props.patternRef Reference of the original.
 */
export function TestContent( { patternRef: ref } ) {
	const [ states, reload ] = useStates( ref );
	const [ adding, setAdding ] = useState( '' );

	if ( null === states ) {
		return <Loading />;
	}

	const add = async ( state ) => {
		setAdding( state );
		try {
			await apiFetch( {
				path: '/leitvia-studio/v1/patterns/states',
				method: 'POST',
				data: { ref, state },
			} );
			notify(
				'success',
				sprintf(
					/* translators: %s: label of the test content such as Long text. */
					__( 'Test content "%s" added.', 'leitvia-studio' ),
					stateLabel( state )
				)
			);
			reload();
		} catch ( error ) {
			notify(
				'error',
				error.message ??
					__(
						'The test content could not be added.',
						'leitvia-studio'
					)
			);
		} finally {
			setAdding( '' );
		}
	};

	const missing = STATES.filter(
		( state ) => ! states.some( ( item ) => item.state === state.value )
	);
	const buttonLabels = {
		long: __( 'Add long text', 'leitvia-studio' ),
		empty: __( 'Add empty content', 'leitvia-studio' ),
		error: __( 'Add error', 'leitvia-studio' ),
		disabled: __( 'Add disabled controls', 'leitvia-studio' ),
	};

	return (
		<Flex direction="column" justify="flex-start" gap={ 4 } align="stretch">
			<p>
				{ __(
					'Test content shows how the pattern copes with long text, missing content, an error and disabled controls. The accessibility check covers it too.',
					'leitvia-studio'
				) }
			</p>
			{ states.length ? (
				<ul className="leitvia-studio-states">
					{ states.map( ( item ) => (
						<li key={ item.ref }>
							{ stateLabel( item.state ) }:{ ' ' }
							<a href={ labUrl( item.ref ) }>
								{ __( 'Preview', 'leitvia-studio' ) }
								<span className="screen-reader-text">
									{ ' ' + item.title }
								</span>
							</a>
							{ item.edit_url && (
								<>
									{ ' ' }
									<a href={ item.edit_url }>
										{ __(
											'Edit in editor',
											'leitvia-studio'
										) }
										<span className="screen-reader-text">
											{ ' ' + item.title }
										</span>
									</a>
								</>
							) }
						</li>
					) ) }
				</ul>
			) : (
				<p>
					{ __(
						'This pattern has no test content yet.',
						'leitvia-studio'
					) }
				</p>
			) }
			{ !! missing.length && (
				<Flex justify="flex-start" wrap>
					{ missing.map( ( state ) => (
						<Button
							key={ state.value }
							__next40pxDefaultSize
							variant="secondary"
							isBusy={ adding === state.value }
							disabled={ !! adding }
							accessibleWhenDisabled
							onClick={ () => add( state.value ) }
						>
							{ buttonLabels[ state.value ] }
						</Button>
					) ) }
				</Flex>
			) }
			<More>
				<p>
					{ __(
						'Long text and empty content are made from the pattern. Error and disabled controls start as copies: edit them in the editor, for example with a danger notice or disabled buttons. Each copy is a pattern of its own that stays out of the inserter.',
						'leitvia-studio'
					) }
				</p>
			</More>
		</Flex>
	);
}
