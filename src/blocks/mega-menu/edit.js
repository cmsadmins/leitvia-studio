import { __ } from '@wordpress/i18n';
import {
	InspectorControls,
	RichText,
	useBlockProps,
} from '@wordpress/block-editor';
import {
	ExternalLink,
	PanelBody,
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { addQueryArgs } from '@wordpress/url';

export default function Edit( {
	attributes: { label, url, slug, description },
	setAttributes,
} ) {
	const parts = useSelect(
		( select ) =>
			(
				select( coreStore ).getEntityRecords(
					'postType',
					'wp_template_part',
					{ per_page: -1 }
				) ?? []
			).filter( ( part ) => 'mega-menu' === part.area ),
		[]
	);
	const part = parts.find( ( item ) => item.slug === slug );
	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Settings', 'leitvia-studio' ) }>
					<TextControl
						__next40pxDefaultSize
						type="url"
						label={ __( 'Overview link', 'leitvia-studio' ) }
						help={ __(
							'Without JavaScript the item links here, so choose a page that lists what the panel shows.',
							'leitvia-studio'
						) }
						value={ url }
						onChange={ ( value ) =>
							setAttributes( { url: value } )
						}
					/>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Panel', 'leitvia-studio' ) }
						help={ __(
							'A template part in the area Mega menu.',
							'leitvia-studio'
						) }
						value={ slug }
						options={ [
							{
								value: '',
								label: __( 'None', 'leitvia-studio' ),
							},
							...parts.map( ( item ) => ( {
								value: item.slug,
								label: item.title?.rendered || item.slug,
							} ) ),
						] }
						onChange={ ( value ) =>
							setAttributes( { slug: value } )
						}
					/>
					{ part && (
						<ExternalLink
							href={ addQueryArgs( 'site-editor.php', {
								p: `/wp_template_part/${ part.id }`,
								canvas: 'edit',
							} ) }
						>
							{ __( 'Edit panel', 'leitvia-studio' ) }
						</ExternalLink>
					) }
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Description', 'leitvia-studio' ) }
						value={ description }
						onChange={ ( value ) =>
							setAttributes( { description: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<li
				{ ...useBlockProps( {
					className: 'wp-block-navigation-item',
				} ) }
			>
				<RichText
					tagName="span"
					className="wp-block-navigation-item__content wp-block-navigation-item__label"
					aria-label={ __( 'Menu item text', 'leitvia-studio' ) }
					placeholder={ __( 'Add text…', 'leitvia-studio' ) }
					value={ label }
					onChange={ ( value ) => setAttributes( { label: value } ) }
					allowedFormats={ [] }
					withoutInteractiveFormatting
				/>
			</li>
		</>
	);
}
