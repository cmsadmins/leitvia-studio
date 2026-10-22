import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	CheckboxControl,
	FormTokenField,
	PanelBody,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';

// The server decides which facets are allowed (includes/blocks/class-query-filter.php): public taxonomies and
// registered meta keys of the types string, integer and boolean. Other keys stay in the attributes but render nothing.
export default function Edit( {
	attributes: { label, facets },
	setAttributes,
	context: { query },
} ) {
	const postType = query?.postType || 'post';
	const taxonomies = useSelect(
		( select ) =>
			(
				select( coreStore ).getTaxonomies( {
					type: postType,
					per_page: -1,
				} ) || []
			).filter( ( taxonomy ) => taxonomy.visibility?.public ),
		[ postType ]
	);
	const names = Object.fromEntries(
		taxonomies.map( ( taxonomy ) => [ taxonomy.slug, taxonomy.name ] )
	);
	const keys = facets.map( ( facet ) => facet.key );

	const toggle = ( key, on ) =>
		setAttributes( {
			facets: on
				? [ ...facets, { key, control: 'checkbox' } ]
				: facets.filter( ( facet ) => facet.key !== key ),
		} );
	const setMetaKeys = ( tokens ) =>
		setAttributes( {
			facets: [
				...facets.filter( ( facet ) => names[ facet.key ] ),
				...tokens.map( ( key ) => ( {
					key,
					control:
						facets.find( ( facet ) => facet.key === key )
							?.control || 'checkbox',
				} ) ),
			],
		} );
	const setControl = ( key, control ) =>
		setAttributes( {
			facets: facets.map( ( facet ) =>
				facet.key === key ? { ...facet, control } : facet
			),
		} );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Filter', 'leitvia-studio' ) }>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Name', 'leitvia-studio' ) }
						help={ __(
							'Names the filter form for screen readers, for example "Filter articles".',
							'leitvia-studio'
						) }
						value={ label }
						onChange={ ( value ) =>
							setAttributes( { label: value } )
						}
					/>
					{ taxonomies.map( ( taxonomy ) => (
						<CheckboxControl
							key={ taxonomy.slug }
							label={ taxonomy.name }
							checked={ keys.includes( taxonomy.slug ) }
							onChange={ ( on ) => toggle( taxonomy.slug, on ) }
						/>
					) ) }
					<FormTokenField
						__next40pxDefaultSize
						label={ __( 'Custom fields', 'leitvia-studio' ) }
						value={ keys.filter( ( key ) => ! names[ key ] ) }
						onChange={ setMetaKeys }
						__experimentalShowHowTo={ false }
					/>
					<p className="components-base-control__help">
						{ __(
							'Meta keys registered for this post type and shown in the REST API, of the type text, number or yes/no.',
							'leitvia-studio'
						) }
					</p>
					{ facets.map( ( facet ) => (
						<SelectControl
							__next40pxDefaultSize
							key={ facet.key }
							label={ names[ facet.key ] || facet.key }
							value={ facet.control }
							options={ [
								{
									label: __( 'Checkboxes', 'leitvia-studio' ),
									value: 'checkbox',
								},
								{
									label: __( 'Dropdown', 'leitvia-studio' ),
									value: 'select',
								},
							] }
							onChange={ ( control ) =>
								setControl( facet.key, control )
							}
						/>
					) ) }
				</PanelBody>
			</InspectorControls>
			<div { ...useBlockProps() }>
				<form>
					{ facets.map( ( facet ) => (
						<fieldset key={ facet.key } disabled>
							<legend>{ names[ facet.key ] || facet.key }</legend>
						</fieldset>
					) ) }
					<p className="wp-block-leitvia-query-filter__actions">
						<span className="wp-element-button">
							{ __( 'Apply filters', 'leitvia-studio' ) }
						</span>
					</p>
				</form>
			</div>
		</>
	);
}
