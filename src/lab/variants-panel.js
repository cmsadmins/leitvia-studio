// Lab panel "Variants": the variants of a wp_block pattern with a form to add one, and the test content of any
// original pattern (states-panel.js).
import { __ } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { Button, TextControl, Flex } from '@wordpress/components';
import Loading from '../admin/loading';
import { labUrl, statusLabel } from './common';
import { OriginalLink, TestContent, originalRef } from './states-panel';
import { notify } from '../admin/notify';

export default function VariantsPanel( { pattern, block, onChange } ) {
	const ref = originalRef( pattern, block );
	if ( ! ref ) {
		return <OriginalLink block={ block } />;
	}
	return (
		<Flex direction="column" justify="flex-start" gap={ 6 } align="stretch">
			{ block && (
				<section>
					<h4>{ __( 'Variants', 'leitvia-studio' ) }</h4>
					<Variants original={ block.id } onChange={ onChange } />
				</section>
			) }
			<section>
				<h4>{ __( 'Test content', 'leitvia-studio' ) }</h4>
				<TestContent patternRef={ ref } />
			</section>
		</Flex>
	);
}

function Variants( { original, onChange } ) {
	const [ variants, setVariants ] = useState( null );
	const [ title, setTitle ] = useState( '' );
	const [ isSaving, setIsSaving ] = useState( false );

	const load = useCallback( () => {
		if ( original ) {
			apiFetch( {
				path: addQueryArgs( '/leitvia-studio/v1/patterns', {
					variant_of: original,
					orderby: 'title',
					order: 'asc',
					per_page: 100,
				} ),
			} )
				.then( setVariants )
				.catch( () => setVariants( [] ) );
		}
	}, [ original ] );
	useEffect( load, [ load ] );

	const add = async ( event ) => {
		event.preventDefault();
		setIsSaving( true );
		try {
			await apiFetch( {
				path: `/leitvia-studio/v1/patterns/${ original }/variants`,
				method: 'POST',
				data: { title },
			} );
			setTitle( '' );
			notify( 'success', __( 'Variant added.', 'leitvia-studio' ) );
			load();
			onChange();
		} catch ( error ) {
			notify(
				'error',
				error.message ??
					__( 'The variant could not be added.', 'leitvia-studio' )
			);
		} finally {
			setIsSaving( false );
		}
	};

	return (
		<Flex direction="column" justify="flex-start" gap={ 4 } align="stretch">
			{ null === variants && <Loading /> }
			{ variants?.length === 0 && (
				<p>
					{ __(
						'This pattern has no variants yet.',
						'leitvia-studio'
					) }
				</p>
			) }
			{ !! variants?.length && (
				<ul className="leitvia-studio-variants">
					{ variants.map( ( variant ) => (
						<li key={ variant.ref }>
							<a href={ labUrl( variant.ref ) }>
								{ variant.title }
							</a>{ ' ' }
							(
							{ statusLabel(
								variant.status,
								variant.status_label
							) }
							)
							{ variant.edit_url && (
								<>
									{ ' ' }
									<a href={ variant.edit_url }>
										{ __(
											'Edit in editor',
											'leitvia-studio'
										) }
									</a>
								</>
							) }
						</li>
					) ) }
				</ul>
			) }
			<form onSubmit={ add }>
				<Flex align="flex-end" justify="flex-start" wrap>
					<TextControl
						id="leitvia-studio-variant-title"
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __(
							'Title of the new variant',
							'leitvia-studio'
						) }
						help={ __(
							'Leave empty to use the title of the pattern.',
							'leitvia-studio'
						) }
						value={ title }
						onChange={ setTitle }
					/>
					<Button
						__next40pxDefaultSize
						variant="secondary"
						type="submit"
						isBusy={ isSaving }
						disabled={ isSaving }
						accessibleWhenDisabled
					>
						{ __( 'Add variant', 'leitvia-studio' ) }
					</Button>
				</Flex>
			</form>
		</Flex>
	);
}
