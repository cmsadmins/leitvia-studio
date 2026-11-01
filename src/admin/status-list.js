// Status rows at the top of a screen: an icon, the state in words and one sentence each. The word is always there, so
// the state never depends on the colour or the icon (WCAG 1.4.1).
import { __, _x, sprintf } from '@wordpress/i18n';
import { Dashicon, VisuallyHidden } from '@wordpress/components';

const STATES = {
	ok: { icon: 'yes-alt', label: __( 'OK', 'leitvia-studio' ) },
	warning: { icon: 'warning', label: __( 'Check', 'leitvia-studio' ) },
	error: { icon: 'dismiss', label: __( 'Blocked', 'leitvia-studio' ) },
	info: {
		icon: 'info',
		label: _x( 'Note', 'state of a status row', 'leitvia-studio' ),
	},
};

/**
 * @param {Object}                                                                                                                     props
 * @param {Array<{name: string, state: 'ok'|'warning'|'error'|'info', title: string, text: string, link?: string, linkText?: string}>} props.rows Rows.
 */
export default function StatusList( { rows } ) {
	return (
		<ul className="leitvia-studio-status">
			{ rows.map( ( row ) => (
				<li
					key={ row.name }
					className={ `leitvia-studio-status__row is-${ row.state }` }
				>
					<Dashicon icon={ STATES[ row.state ].icon } />
					<span>
						<strong>
							{ sprintf(
								/* translators: 1: name of the row, 2: its state. */
								__( '%1$s: %2$s', 'leitvia-studio' ),
								row.name,
								row.title
							) }
						</strong>
						<VisuallyHidden>
							{ ' ' }
							({ STATES[ row.state ].label })
						</VisuallyHidden>
						<br />
						{ row.text }
						{ row.link && (
							<>
								{ ' ' }
								<a href={ row.link }>{ row.linkText }</a>
							</>
						) }
					</span>
				</li>
			) ) }
		</ul>
	);
}
