import { __, sprintf } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { CheckboxControl, PanelBody } from '@wordpress/components';

const LEVELS = [ 2, 3, 4, 5, 6 ];

export default function Edit( { attributes: { levels }, setAttributes } ) {
	const toggle = ( level, on ) =>
		setAttributes( {
			levels: LEVELS.filter( ( l ) =>
				l === level ? on : levels.includes( l )
			),
		} );
	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Headings', 'leitvia-studio' ) }>
					{ LEVELS.map( ( level ) => (
						<CheckboxControl
							key={ level }
							label={ sprintf(
								/* translators: %d: heading level, for example 2. */
								__( 'Heading %d', 'leitvia-studio' ),
								level
							) }
							checked={ levels.includes( level ) }
							onChange={ ( on ) => toggle( level, on ) }
						/>
					) ) }
				</PanelBody>
			</InspectorControls>
			<nav
				{ ...useBlockProps() }
				aria-label={ __( 'On this page', 'leitvia-studio' ) }
			>
				<p className="wp-block-leitvia-table-of-contents__title">
					{ __( 'On this page', 'leitvia-studio' ) }
				</p>
				<p>
					{ __(
						'Lists the headings of this page as links when the page is viewed. It shows nothing with fewer than two headings.',
						'leitvia-studio'
					) }
				</p>
			</nav>
		</>
	);
}
