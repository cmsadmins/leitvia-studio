// Way back from a detail view to its list, the same on every screen: the list as a link, then the current item.
import { __ } from '@wordpress/i18n';

/**
 * @param {Object}           props
 * @param {[string, string]} props.parent  Label and URL of the list.
 * @param {string}           props.current Name of the item on this view.
 */
export default function Breadcrumbs( { parent: [ label, url ], current } ) {
	return (
		<nav
			className="leitvia-studio-breadcrumbs"
			aria-label={ __( 'Breadcrumbs', 'leitvia-studio' ) }
		>
			<ol>
				<li>
					<a href={ url }>{ label }</a>
				</li>
				<li>
					<span aria-current="page">{ current }</span>
				</li>
			</ol>
		</nav>
	);
}
