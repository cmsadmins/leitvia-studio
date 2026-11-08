import {
	RichText,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';
import metadata from './block.json';
import { DATE } from './save';

// Version 0.1.0 always saved the label as a time element, also without a date. Without a date it migrates to a span.
const v1 = {
	attributes: {
		...metadata.attributes,
		dateLabel: { ...metadata.attributes.dateLabel, selector: 'time' },
	},
	supports: metadata.supports,
	save( { attributes: { date, dateLabel, heading, level } } ) {
		return (
			<li { ...useBlockProps.save() }>
				<RichText.Content
					tagName="time"
					className="wp-block-leitvia-timeline-item__date"
					dateTime={ DATE.test( date ) ? date : undefined }
					value={ dateLabel }
				/>
				<RichText.Content
					tagName={ `h${ level }` }
					className="wp-block-leitvia-timeline-item__heading"
					value={ heading }
				/>
				<div
					{ ...useInnerBlocksProps.save( {
						className: 'wp-block-leitvia-timeline-item__content',
					} ) }
				/>
			</li>
		);
	},
};

export default [ v1 ];
