// Spinner with a text for screen readers.
import { __ } from '@wordpress/i18n';
import { Spinner, VisuallyHidden } from '@wordpress/components';

export default function Loading() {
	return (
		<div role="status">
			<Spinner />
			<VisuallyHidden>
				{ __( 'Loading', 'leitvia-studio' ) }
			</VisuallyHidden>
		</div>
	);
}
