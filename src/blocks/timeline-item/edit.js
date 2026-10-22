import { __ } from '@wordpress/i18n';
import {
	BlockControls,
	HeadingLevelDropdown,
	InspectorControls,
	RichText,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import { DATE } from './save';

const TEMPLATE = [
	[
		'core/paragraph',
		{ placeholder: __( 'What happened?', 'leitvia-studio' ) },
	],
];

export default function Edit( {
	attributes: { date, dateLabel, heading, level },
	setAttributes,
} ) {
	const invalid = '' !== date && ! DATE.test( date );
	return (
		<>
			<BlockControls group="block">
				<HeadingLevelDropdown
					value={ level }
					options={ [ 2, 3, 4, 5, 6 ] }
					onChange={ ( value ) => setAttributes( { level: value } ) }
				/>
			</BlockControls>
			<InspectorControls>
				<PanelBody title={ __( 'Date', 'leitvia-studio' ) }>
					<TextControl
						__next40pxDefaultSize
						label={ __(
							'Machine-readable date',
							'leitvia-studio'
						) }
						help={
							invalid
								? __(
										'Use YYYY, YYYY-MM or YYYY-MM-DD. The date is not saved until it has this form.',
										'leitvia-studio'
									)
								: __(
										'YYYY, YYYY-MM or YYYY-MM-DD, for search engines and assistive technology.',
										'leitvia-studio'
									)
						}
						value={ date }
						onChange={ ( value ) =>
							setAttributes( { date: value.trim() } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<li { ...useBlockProps() }>
				<RichText
					tagName="time"
					className="wp-block-leitvia-timeline-item__date"
					aria-label={ __( 'Date as shown', 'leitvia-studio' ) }
					placeholder={ __( 'Date', 'leitvia-studio' ) }
					value={ dateLabel }
					onChange={ ( value ) =>
						setAttributes( { dateLabel: value } )
					}
					allowedFormats={ [] }
				/>
				<RichText
					tagName={ `h${ level }` }
					className="wp-block-leitvia-timeline-item__heading"
					aria-label={ __( 'Heading', 'leitvia-studio' ) }
					placeholder={ __( 'Heading', 'leitvia-studio' ) }
					value={ heading }
					onChange={ ( value ) =>
						setAttributes( { heading: value } )
					}
				/>
				<div
					{ ...useInnerBlocksProps(
						{
							className:
								'wp-block-leitvia-timeline-item__content',
						},
						{ template: TEMPLATE }
					) }
				/>
			</li>
		</>
	);
}
