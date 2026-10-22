// Shared parts of the Tokens screen: URLs, role groups, the findings list and the file download.
import { __ } from '@wordpress/i18n';
import { useEffect, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { Notice } from '@wordpress/components';

/**
 * Role groups of the contract with the theme.json preset list and value key each one compiles to (Dtcg::GROUPS).
 */
export const GROUPS = [
	{
		id: 'color',
		label: __( 'Colors', 'leitvia-studio' ),
		list: [ 'color', 'palette' ],
		key: 'color',
	},
	{
		id: 'fontSize',
		label: __( 'Font sizes', 'leitvia-studio' ),
		list: [ 'typography', 'fontSizes' ],
		key: 'size',
	},
	{
		id: 'fontFamily',
		label: __( 'Font families', 'leitvia-studio' ),
		list: [ 'typography', 'fontFamilies' ],
		key: 'fontFamily',
	},
	{
		id: 'spacing',
		label: __( 'Spacing sizes', 'leitvia-studio' ),
		list: [ 'spacing', 'spacingSizes' ],
		key: 'size',
	},
	{
		id: 'radius',
		label: __( 'Radius sizes', 'leitvia-studio' ),
		list: [ 'border', 'radiusSizes' ],
		key: 'size',
	},
	{
		id: 'shadow',
		label: __( 'Shadows', 'leitvia-studio' ),
		list: [ 'shadow', 'presets' ],
		key: 'shadow',
	},
];

export const META = '_leitvia_tokens_dtcg';

/**
 * URL of the token set list, or of the editor of one set.
 *
 * @param {number} [id] Token set ID.
 * @return {string} URL.
 */
export const tokensUrl = ( id ) =>
	addQueryArgs(
		'admin.php',
		id
			? { page: 'leitvia-studio-tokens', set: id }
			: { page: 'leitvia-studio-tokens' }
	);

/**
 * Findings of a failed request: data.findings of a 422, otherwise the message of the error as one finding.
 *
 * @param {Object} error Error thrown by apiFetch.
 * @return {Array<{message: string, data?: Object}>} Findings.
 */
export const errorFindings = ( error ) =>
	error?.data?.findings?.length
		? error.data.findings
		: [
				{
					message:
						error?.message ??
						__( 'The request failed.', 'leitvia-studio' ),
				},
			];

/**
 * Error list for a failed save, import or activation. The heading takes the focus when the list appears, and the
 * Notice announces heading and messages in the assertive live region.
 *
 * @param {Object}                      props
 * @param {string}                      props.heading  Heading of the list.
 * @param {Array}                       props.findings Findings with message and optional data.fg.
 * @param {(finding: Object) => string} props.linkTo   Optional, returns the ID of the field a finding belongs to.
 */
export function Findings( { heading, findings, linkTo } ) {
	const ref = useRef();
	useEffect( () => {
		ref.current?.focus();
	}, [ findings ] );
	return (
		<Notice
			status="error"
			isDismissible={ false }
			className="leitvia-studio-tokens__findings"
		>
			<h3 ref={ ref } tabIndex={ -1 }>
				{ heading }
			</h3>
			<ul>
				{ findings.map( ( finding, index ) => {
					const target = linkTo?.( finding );
					return (
						<li key={ index }>
							{ target ? (
								<a href={ `#${ target }` }>
									{ finding.message }
								</a>
							) : (
								finding.message
							) }
						</li>
					);
				} ) }
			</ul>
		</Notice>
	);
}

/**
 * Downloads the export of a set as a file. The REST nonce rides along with apiFetch, so the file comes as a blob
 * and leaves through an object URL; the name comes from Content-Disposition.
 *
 * @param {number} id Token set ID.
 */
export async function downloadSet( id ) {
	let response;
	try {
		response = await apiFetch( {
			path: `/leitvia-studio/v1/token-sets/${ id }/export?download=1`,
			parse: false,
		} );
	} catch ( error ) {
		// With parse: false a failed request rejects with the Response itself.
		throw typeof error?.json === 'function'
			? await error.json().catch( () => ( {} ) )
			: error;
	}
	const name =
		/filename="([^"]+)"/.exec(
			response.headers.get( 'Content-Disposition' ) ?? ''
		)?.[ 1 ] ?? `token-set-${ id }.tokens.json`;
	const url = URL.createObjectURL( await response.blob() );
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = name;
	document.body.append( link );
	link.click();
	link.remove();
	// Some browsers start the download after the click returns; the URL lives on for a second.
	setTimeout( () => URL.revokeObjectURL( url ), 1000 );
}
