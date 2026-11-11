import { __, _n, sprintf } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	PanelBody,
	RangeControl,
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import useSiteLocale from '../pricing/locale';

// Same markup as Rating::render(); the canvas shows what the page shows, without the Review node.
export default function Edit( { attributes, setAttributes } ) {
	const { label, reviewBody, reviewAuthor, itemName, itemType } = attributes;
	const max = Math.min( 10, Math.max( 1, attributes.max ) );
	const rating = Math.round( Math.min( max, attributes.rating ) * 2 ) / 2;
	// Figures in the language of the site, as Rating::render() prints them, not in the one of the admin.
	const locale = useSiteLocale();
	const format = ( n ) => n.toLocaleString( locale );
	const set = ( key ) => ( next ) => setAttributes( { [ key ]: next } );
	const types = [
		[ '', __( 'None, no structured data', 'leitvia-studio' ) ],
		[ 'Product', __( 'Product', 'leitvia-studio' ) ],
		[ 'Book', __( 'Book', 'leitvia-studio' ) ],
		[ 'Course', __( 'Course', 'leitvia-studio' ) ],
		[ 'Recipe', __( 'Recipe', 'leitvia-studio' ) ],
		[ 'SoftwareApplication', __( 'Software', 'leitvia-studio' ) ],
		[ 'LocalBusiness', __( 'Local business', 'leitvia-studio' ) ],
		[ 'Movie', __( 'Movie', 'leitvia-studio' ) ],
		[ 'Event', __( 'Event', 'leitvia-studio' ) ],
		[ 'CreativeWorkSeason', __( 'Season of a series', 'leitvia-studio' ) ],
	];
	const heading = label || itemName;
	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Rating', 'leitvia-studio' ) }>
					<RangeControl
						__next40pxDefaultSize
						label={ __( 'Rating', 'leitvia-studio' ) }
						value={ rating }
						onChange={ ( next ) =>
							setAttributes( { rating: next ?? 0 } )
						}
						min={ 0 }
						max={ max }
						step={ 0.5 }
					/>
					<RangeControl
						__next40pxDefaultSize
						label={ __( 'Number of stars', 'leitvia-studio' ) }
						value={ max }
						onChange={ ( next ) =>
							setAttributes( {
								max: next ?? 5,
								rating: Math.min( rating, next ?? 5 ),
							} )
						}
						min={ 1 }
						max={ 10 }
					/>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Label', 'leitvia-studio' ) }
						help={ __(
							'Shown above the stars, for example what was rated.',
							'leitvia-studio'
						) }
						value={ label }
						onChange={ set( 'label' ) }
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Review', 'leitvia-studio' ) }
					initialOpen={ false }
				>
					<TextareaControl
						label={ __( 'Review text', 'leitvia-studio' ) }
						value={ reviewBody }
						onChange={ set( 'reviewBody' ) }
					/>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Author', 'leitvia-studio' ) }
						value={ reviewAuthor }
						onChange={ set( 'reviewAuthor' ) }
					/>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Reviewed item', 'leitvia-studio' ) }
						value={ itemName }
						onChange={ set( 'itemName' ) }
					/>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Type of item', 'leitvia-studio' ) }
						help={
							'LocalBusiness' === itemType
								? __(
										'Google shows no review stars for reviews of your own business on your own site.',
										'leitvia-studio'
									)
								: __(
										'With an item and its type the page carries a Review for search engines.',
										'leitvia-studio'
									)
						}
						value={ itemType }
						options={ types.map( ( [ value, text ] ) => ( {
							value,
							label: text,
						} ) ) }
						onChange={ set( 'itemType' ) }
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...useBlockProps() }>
				{ heading && (
					<p className="wp-block-leitvia-rating__label">
						{ heading }
					</p>
				) }
				<p className="wp-block-leitvia-rating__score">
					<span
						className="wp-block-leitvia-rating__stars"
						style={ {
							'--leitvia-rating-max': max,
							'--leitvia-rating': `${ ( rating / max ) * 100 }%`,
						} }
						aria-hidden="true"
					/>{ ' ' }
					<span className="wp-block-leitvia-rating__text">
						{ sprintf(
							/* translators: 1: rating, for example 4.5. 2: number of stars, for example 5. */
							_n(
								'%1$s out of %2$s star',
								'%1$s out of %2$s stars',
								max,
								'leitvia-studio'
							),
							format( rating ),
							format( max )
						) }
					</span>
				</p>
				{ reviewBody && (
					<p className="wp-block-leitvia-rating__body">
						{ reviewBody }
					</p>
				) }
				{ reviewAuthor && (
					<p className="wp-block-leitvia-rating__author">
						{ reviewAuthor }
					</p>
				) }
			</div>
		</>
	);
}
