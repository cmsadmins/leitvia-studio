// Lab panel "Variants": the variants of a wp_block pattern and a form to add one.
import { __ } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { Button, TextControl, Flex } from '@wordpress/components';
import Loading from '../admin/loading';
import { labUrl, statusLabel } from './common';
import { notify } from '../admin/notify';

export default function VariantsPanel( { block, onChange } ) {
	const original =
		block && ! block.meta._leitvia_lab_variant_of ? block.id : 0;
	const [ variants, setVariants ] = useState( null );
	const [ title, setTitle ] = useState( '' );
	const [ isSaving, setIsSaving ] = useState( false );

	const load = useCallback( () => {
		if ( original ) {
			apiFetch( {
				path: addQueryArgs( '/leitvia-studio/v1/patterns', {
					variant_of: original,
					per_page: 100,
				} ),
			} )
				.then( setVariants )
				.catch( () => setVariants( [] ) );
		}
	}, [ original ] );
	useEffect( load, [ load ] );

	if ( ! block ) {
		return (
			<p>
				{ __(
					'Variants are made from your own patterns.',
					'leitvia-studio'
				) }
			</p>
		);
	}
	if ( ! original ) {
		return (
			<p>
				{ __( 'This pattern is a variant.', 'leitvia-studio' ) }{ ' ' }
				<a
					href={ labUrl(
						`block:${ block.meta._leitvia_lab_variant_of }`
					) }
				>
					{ __( 'Open the original', 'leitvia-studio' ) }
				</a>
			</p>
		);
	}

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
							({ statusLabel( variant.status ) })
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
