import { __ } from '@wordpress/i18n';
import { useBlockProps } from '@wordpress/block-editor';

// A preview only: the front end links the published counterpart of the shown page, chosen in the "Easy language"
// panel of the page, and shows nothing without one.
export default function Edit() {
	return (
		<p { ...useBlockProps() }>
			<span>{ __( 'Easy language', 'leitvia-studio' ) }</span>{ ' ' }
			<small>
				{ __(
					'(links the version in easy language or the original; hidden while there is none)',
					'leitvia-studio'
				) }
			</small>
		</p>
	);
}
