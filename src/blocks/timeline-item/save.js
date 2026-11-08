import {
	RichText,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';

// A valid datetime value for a timeline: year, year and month, or a full date.
export const DATE = /^\d{4}(-\d{2}(-\d{2})?)?$/;

// With a date the label is a time element with its datetime, without one a free label such as "First journey" in a
// span: a time element must hold a date.
export const dateTag = ( date ) => ( DATE.test( date ) ? 'time' : 'span' );

export default function save( {
	attributes: { date, dateLabel, heading, level },
} ) {
	return (
		<li { ...useBlockProps.save() }>
			<RichText.Content
				tagName={ dateTag( date ) }
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
}
