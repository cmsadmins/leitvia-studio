// Shared parts of the Tokens screen: URLs, role groups, the findings list, the activation dialog and the file
// download.
import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Notice } from '@wordpress/components';
import { screenUrl } from '../admin/common';
import ConfirmDialog from '../admin/confirm-dialog';
import { notify } from '../admin/notify';

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
	screenUrl( 'leitvia-studio-tokens', { set: id } );

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
 * Activates a set; the response findings with severity warning are announced, errors come back as findings.
 *
 * @param {number} id Token set ID.
 * @return {Promise<Array|null>} Findings of a refused activation, or null.
 */
async function activate( id ) {
	try {
		const response = await apiFetch( {
			path: `/leitvia-studio/v1/token-sets/${ id }/activate`,
			method: 'POST',
		} );
		notify( 'success', __( 'Token set activated.', 'leitvia-studio' ) );
		const warnings = ( response.findings ?? [] ).filter(
			( finding ) => 'warning' === finding.severity
		);
		if ( warnings.length ) {
			notify(
				'warning',
				warnings.map( ( finding ) => finding.message ).join( ' ' )
			);
		}
		return null;
	} catch ( error ) {
		return errorFindings( error );
	}
}

/**
 * Activation confirmation: what the set replaces, findings of a refused activation. Inside the modal of the DataViews
 * action by default, in its own modal with `modal`.
 *
 * @param {Object}     props
 * @param {Array}      props.items      The set, as DataViews hands it over.
 * @param {() => void} props.closeModal Closes the dialog.
 * @param {Object}     props.roles      Contract roles.
 * @param {() => void} props.onDone     Called after the activation.
 * @param {boolean}    props.modal      Whether the dialog opens its own modal.
 */
export function ActivateModal( {
	items,
	closeModal,
	roles,
	onDone,
	modal = false,
} ) {
	const [ item ] = items;
	const [ findings, setFindings ] = useState( null );
	const [ isBusy, setIsBusy ] = useState( false );
	const lists = GROUPS.filter( ( group ) => roles[ group.id ]?.length );
	const confirm = async () => {
		setIsBusy( true );
		const result = await activate( item.id );
		setIsBusy( false );
		if ( result ) {
			setFindings( result );
			return;
		}
		closeModal();
		onDone();
	};
	return (
		<ConfirmDialog
			modal={ modal }
			title={ __( 'Activate token set', 'leitvia-studio' ) }
			confirmLabel={ __( 'Activate', 'leitvia-studio' ) }
			isBusy={ isBusy }
			onCancel={ closeModal }
			onConfirm={ confirm }
		>
			{ findings && (
				<Findings
					heading={ __(
						'The token set was not activated',
						'leitvia-studio'
					) }
					findings={ findings }
				/>
			) }
			<p>
				{ sprintf(
					/* translators: %s: token set title. */
					__(
						'Activating “%s” replaces these values on the whole site:',
						'leitvia-studio'
					),
					item.title
				) }
			</p>
			<ul className="leitvia-studio-tokens__list">
				{ lists.map( ( group ) => (
					<li key={ group.id }>
						{ sprintf(
							/* translators: 1: preset list, 2: number of values. */
							_n(
								'%1$s: %2$d value',
								'%1$s: %2$d values',
								roles[ group.id ].length,
								'leitvia-studio'
							),
							group.label,
							roles[ group.id ].length
						) }
					</li>
				) ) }
			</ul>
			<p>
				{ __(
					'Everything else stays as it is, and the earlier values stay in the style revisions.',
					'leitvia-studio'
				) }
			</p>
		</ConfirmDialog>
	);
}

/**
 * Activation confirmation outside the DataViews actions, for Apply again in the list and for the editor.
 *
 * @param {Object}     props
 * @param {Object}     props.item    Set with id and title.
 * @param {Object}     props.roles   Contract roles.
 * @param {() => void} props.onClose Closes the dialog.
 * @param {() => void} props.onDone  Called after the activation.
 */
export function ActivateDialog( { item, roles, onClose, onDone } ) {
	return (
		<ActivateModal
			modal
			items={ [ item ] }
			closeModal={ onClose }
			roles={ roles }
			onDone={ onDone }
		/>
	);
}

/**
 * Downloads the export of a set as a file. The REST nonce rides along with apiFetch, so the file comes as a blob
 * and leaves through an object URL; the name comes from Content-Disposition.
 *
 * @param {number} id     Token set ID.
 * @param {string} target Sync target that exports the file.
 */
export async function downloadSet( id, target = 'leitvia/download' ) {
	let response;
	try {
		response = await apiFetch( {
			path: `/leitvia-studio/v1/token-sets/${ id }/export?download=1&target=${ encodeURIComponent(
				target
			) }`,
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
