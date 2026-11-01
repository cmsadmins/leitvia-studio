// Background for experts below a plain-language help text: closed by default, so a screen reads in one sentence per
// field and the details stay one click away.
import { __ } from '@wordpress/i18n';

export default function More( { summary, children } ) {
	return (
		<details className="leitvia-studio-more">
			<summary>{ summary ?? __( 'Details', 'leitvia-studio' ) }</summary>
			<div className="leitvia-studio-more__body">{ children }</div>
		</details>
	);
}
