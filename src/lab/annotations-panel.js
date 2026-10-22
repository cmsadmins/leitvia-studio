// Lab panel "Annotations": notes on named blocks of a wp_block pattern and a preview with numbered marks. The list is
// the accessible form of the marks, which the preview hides from assistive technology.
import { __, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { speak } from '@wordpress/a11y';
import {
	Button,
	Flex,
	SelectControl,
	Spinner,
	TextareaControl,
} from '@wordpress/components';
import PreviewFrame from './preview-frame';
import { notify } from '../admin/notify';

export default function AnnotationsPanel( { pattern, block } ) {
	const id = block?.id;
	const [ data, setData ] = useState( null );
	const [ entries, setEntries ] = useState( [] );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ version, setVersion ] = useState( 0 );
	// Where focus goes after the list changed: { index, to: 'select' | 'legend' }, or 'add' for the add button.
	const [ focusTarget, setFocusTarget ] = useState( null );
	const listRef = useRef();
	const addRef = useRef();

	useEffect( () => {
		if ( ! focusTarget ) {
			return;
		}
		const entry =
			listRef.current?.children[ focusTarget.index ?? -1 ] ?? null;
		const element =
			'add' === focusTarget
				? addRef.current
				: entry?.querySelector(
						'select' === focusTarget.to ? 'select' : 'legend'
					);
		element?.focus();
		setFocusTarget( null );
	}, [ focusTarget ] );

	const load = useCallback( () => {
		if ( id ) {
			apiFetch( {
				path: `/leitvia-studio/v1/patterns/${ id }/annotations`,
			} )
				.then( ( result ) => {
					setData( result );
					setEntries(
						result.annotations.map( ( { target, text } ) => ( {
							target,
							text,
						} ) )
					);
				} )
				.catch( ( error ) =>
					notify(
						'error',
						error.message ??
							__(
								'The annotations could not be loaded.',
								'leitvia-studio'
							)
					)
				);
		}
	}, [ id ] );
	useEffect( load, [ load ] );

	if ( ! block ) {
		return (
			<p>
				{ __(
					'Annotations are added to your own patterns.',
					'leitvia-studio'
				) }
			</p>
		);
	}
	if ( ! data ) {
		return <Spinner />;
	}

	const update = ( index, key ) => ( value ) =>
		setEntries(
			entries.map( ( entry, i ) =>
				i === index ? { ...entry, [ key ]: value } : entry
			)
		);

	const save = async () => {
		setIsSaving( true );
		try {
			await apiFetch( {
				path: `/wp/v2/blocks/${ id }`,
				method: 'POST',
				data: {
					meta: {
						_leitvia_lab_annotations: entries.filter(
							( entry ) => entry.target
						),
					},
				},
			} );
			notify( 'success', __( 'Annotations saved.', 'leitvia-studio' ) );
			setVersion( version + 1 );
			load();
		} catch ( error ) {
			notify(
				'error',
				error.message ??
					__(
						'The annotations could not be saved.',
						'leitvia-studio'
					)
			);
		} finally {
			setIsSaving( false );
		}
	};

	return (
		<Flex direction="column" justify="flex-start" gap={ 4 } align="stretch">
			{ ! data.names.length && (
				<p>
					{ __(
						'Name blocks in the editor with "Rename" to annotate them.',
						'leitvia-studio'
					) }
				</p>
			) }
			{ ! entries.length && (
				<p>
					{ __(
						'This pattern has no annotations yet.',
						'leitvia-studio'
					) }
				</p>
			) }
			{ !! entries.length && (
				<ol className="leitvia-studio-annotations" ref={ listRef }>
					{ entries.map( ( entry, index ) => {
						const missing =
							entry.target &&
							! data.names.includes( entry.target );
						return (
							<li key={ index }>
								<fieldset className="leitvia-studio-annotations__entry">
									<legend tabIndex={ -1 }>
										{ sprintf(
											/* translators: %d: number of the annotation. */
											__(
												'Annotation %d',
												'leitvia-studio'
											),
											index + 1
										) }
									</legend>
									<Flex
										direction="column"
										justify="flex-start"
										gap={ 3 }
										align="stretch"
									>
										<SelectControl
											__next40pxDefaultSize
											__nextHasNoMarginBottom
											label={ __(
												'Block',
												'leitvia-studio'
											) }
											value={ entry.target }
											options={ [
												{
													value: '',
													label: __(
														'Select a named block',
														'leitvia-studio'
													),
												},
												...data.names.map(
													( name ) => ( {
														value: name,
														label: name,
													} )
												),
												...( missing
													? [
															{
																value: entry.target,
																label: sprintf(
																	/* translators: %s: block name. */
																	__(
																		'%s (no longer in the pattern)',
																		'leitvia-studio'
																	),
																	entry.target
																),
															},
														]
													: [] ),
											] }
											help={
												missing
													? __(
															'The pattern has no block with this name any more.',
															'leitvia-studio'
														)
													: undefined
											}
											onChange={ update(
												index,
												'target'
											) }
										/>
										<TextareaControl
											__nextHasNoMarginBottom
											label={ __(
												'Note',
												'leitvia-studio'
											) }
											value={ entry.text }
											onChange={ update( index, 'text' ) }
										/>
										<Button
											__next40pxDefaultSize
											variant="secondary"
											isDestructive
											onClick={ () => {
												setEntries(
													entries.filter(
														( _, i ) => i !== index
													)
												);
												speak(
													sprintf(
														/* translators: %d: number of the annotation. */
														__(
															'Annotation %d removed.',
															'leitvia-studio'
														),
														index + 1
													)
												);
												// The entry that moves up into this place, or the add button after the last one.
												setFocusTarget(
													index < entries.length - 1
														? {
																index,
																to: 'legend',
															}
														: 'add'
												);
											} }
										>
											{ sprintf(
												/* translators: %d: number of the annotation. */
												__(
													'Remove annotation %d',
													'leitvia-studio'
												),
												index + 1
											) }
										</Button>
									</Flex>
								</fieldset>
							</li>
						);
					} ) }
				</ol>
			) }
			<Flex justify="flex-start" wrap>
				<Button
					ref={ addRef }
					__next40pxDefaultSize
					variant="secondary"
					onClick={ () => {
						setEntries( [ ...entries, { target: '', text: '' } ] );
						setFocusTarget( {
							index: entries.length,
							to: 'select',
						} );
					} }
				>
					{ __( 'Add annotation', 'leitvia-studio' ) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="primary"
					onClick={ save }
					isBusy={ isSaving }
					disabled={ isSaving }
					accessibleWhenDisabled
				>
					{ __( 'Save annotations', 'leitvia-studio' ) }
				</Button>
			</Flex>
			<h4 className="leitvia-studio-annotations__heading">
				{ __( 'Preview with annotations', 'leitvia-studio' ) }
			</h4>
			<PreviewFrame
				key={ version }
				patternRef={ pattern.ref }
				width={ 1440 }
				annotations
				title={ sprintf(
					/* translators: %s: pattern title. */
					__( 'Preview of %s with annotations', 'leitvia-studio' ),
					pattern.title
				) }
			/>
		</Flex>
	);
}
