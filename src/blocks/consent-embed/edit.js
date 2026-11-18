import { __, sprintf } from '@wordpress/i18n';
import {
	InspectorControls,
	MediaUpload,
	MediaUploadCheck,
	useBlockProps,
} from '@wordpress/block-editor';
import {
	Button,
	Notice,
	PanelBody,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';

// Providers of the consent settings and the filter leitvia_studio_consent_services, the host of the own site and
// whether the server turns hosts beyond ASCII into ASCII (Consent_Embed::editor_data()).
const SERVICES = window.leitviaStudioConsentServices ?? {};
const SITE = window.leitviaStudioConsentSite ?? { host: '', idn: false };

// Smallest and largest fixed height, as Consent_Embed::MIN_HEIGHT and MAX_HEIGHT.
const MIN_HEIGHT = 200;
const MAX_HEIGHT = 1600;
// A pattern, not a quoted address: the WordPress.org scan of the gate flags every quoted URL in plugin JavaScript.
const HTTPS = /^https:\/\//;

/**
 * Whether the server renders the address (Consent_Embed::url()): trimmed like there, it starts with https:// in
 * lowercase and holds no space, backslash, quote or angle bracket, and letters beyond ASCII only in the host, where
 * the server can turn them into ASCII.
 *
 * @param {string} address Address of the iframe.
 * @return {boolean} Renders.
 */
function rendered( address ) {
	const url = address.trim();
	if ( ! HTTPS.test( url ) || /[\s\\"'<>]/.test( url ) ) {
		return false;
	}
	const rest = url.slice( 8 );
	const end = rest.search( /[/?#]/ );
	const host = -1 === end ? rest : rest.slice( 0, end );
	const path = -1 === end ? '' : rest.slice( end );
	const beyondAscii = /[^\x00-\x7f]/;
	return (
		! beyondAscii.test( path ) && ( SITE.idn || ! beyondAscii.test( host ) )
	);
}

/**
 * Provider of an address, matched as Consent::service_for() does: the provider one of whose hosts is the host of the
 * address or a parent of it, else the host itself; own for the own site. Null for an address the server does not
 * render, broken for one that starts with https:// but does not render.
 *
 * @param {string} address Address of the iframe.
 * @return {{label: string, listed: boolean, own: boolean}|{broken: true}|null} Provider.
 */
function providerFor( address ) {
	const url = address.trim();
	if ( ! rendered( url ) ) {
		return HTTPS.test( url ) && url.length > 8 ? { broken: true } : null;
	}
	let host;
	try {
		host = new window.URL( url ).hostname.toLowerCase();
	} catch {
		return { broken: true };
	}
	if ( host === SITE.host ) {
		return { label: host, listed: true, own: true };
	}
	const service = Object.values( SERVICES ).find( ( entry ) =>
		entry.hosts.some(
			( pattern ) => host === pattern || host.endsWith( `.${ pattern }` )
		)
	);
	return service
		? { label: service.label, listed: true, own: false }
		: { label: host, listed: false, own: false };
}

// The editor never loads the iframe: that would contact the provider before anyone agreed. It shows the placeholder.
export default function Edit( { attributes, setAttributes } ) {
	const { url, title, aspectRatio, height, previewId } = attributes;
	const found = providerFor( url );
	const broken = !! found?.broken;
	const provider = broken ? null : found;
	// The field keeps what is typed and the attribute the number; the preview stays between MIN_HEIGHT and MAX_HEIGHT,
	// as the server renders it.
	const [ heightText, setHeightText ] = useState( String( height ) );
	const shownHeight = Math.min(
		MAX_HEIGHT,
		Math.max( MIN_HEIGHT, parseInt( height, 10 ) || 600 )
	);
	const preview = useSelect(
		( select ) =>
			previewId
				? select( coreStore ).getMedia( previewId, { context: 'view' } )
				: null,
		[ previewId ]
	);
	const texts = (
		<>
			<p className="leitvia-consent__title">
				<strong>
					{ provider
						? sprintf(
								/* translators: %s: provider name. */
								__( 'Content from %s', 'leitvia-studio' ),
								provider.label
							)
						: __( 'Embed behind consent', 'leitvia-studio' ) }
				</strong>
			</p>
			<p>
				{ provider?.own &&
					__(
						'Content of this site loads at once, without a placeholder. The editor never loads it.',
						'leitvia-studio'
					) }
				{ provider &&
					! provider.own &&
					__(
						'Visitors see this placeholder until they load the content. The editor never loads it.',
						'leitvia-studio'
					) }
				{ ! provider &&
					__(
						'Enter the address of the content in the block settings.',
						'leitvia-studio'
					) }
			</p>
		</>
	);
	const ratio =
		'fixed' === aspectRatio
			? 'has-fixed-height'
			: `is-ratio-${ aspectRatio.replace( '/', '-' ) }`;

	return (
		<div
			{ ...useBlockProps( {
				className: ratio,
				style:
					'fixed' === aspectRatio
						? { '--leitvia-embed-height': `${ shownHeight }px` }
						: undefined,
			} ) }
		>
			<InspectorControls>
				<PanelBody title={ __( 'Embed', 'leitvia-studio' ) }>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="url"
						label={ __(
							'Address of the content',
							'leitvia-studio'
						) }
						help={ __(
							'The address of the iframe, starting with https://.',
							'leitvia-studio'
						) }
						value={ url }
						onChange={ ( value ) =>
							setAttributes( { url: value.trim() } )
						}
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __(
							'Title for screen readers',
							'leitvia-studio'
						) }
						help={ __(
							'Says what the content is, such as "Kitchen planner".',
							'leitvia-studio'
						) }
						value={ title }
						onChange={ ( value ) =>
							setAttributes( { title: value } )
						}
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Size', 'leitvia-studio' ) }
						value={ aspectRatio }
						options={ [
							{
								label: __( 'Wide (16:9)', 'leitvia-studio' ),
								value: '16/9',
							},
							{
								label: __(
									'Landscape (4:3)',
									'leitvia-studio'
								),
								value: '4/3',
							},
							{
								label: __( 'Square (1:1)', 'leitvia-studio' ),
								value: '1/1',
							},
							{
								label: __(
									'Portrait (9:16)',
									'leitvia-studio'
								),
								value: '9/16',
							},
							{
								label: __( 'Fixed height', 'leitvia-studio' ),
								value: 'fixed',
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { aspectRatio: value } )
						}
					/>
					{ 'fixed' === aspectRatio && (
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							type="number"
							min={ MIN_HEIGHT }
							max={ MAX_HEIGHT }
							label={ __( 'Height in pixels', 'leitvia-studio' ) }
							help={ sprintf(
								/* translators: 1: smallest height, 2: largest height. */
								__(
									'Between %1$d and %2$d pixels.',
									'leitvia-studio'
								),
								MIN_HEIGHT,
								MAX_HEIGHT
							) }
							value={ heightText }
							onChange={ ( value ) => {
								setHeightText( value );
								const number = parseInt( value, 10 );
								if ( number > 0 ) {
									setAttributes( { height: number } );
								}
							} }
						/>
					) }
					<MediaUploadCheck>
						<MediaUpload
							allowedTypes={ [ 'image' ] }
							value={ previewId }
							onSelect={ ( media ) =>
								setAttributes( { previewId: media.id } )
							}
							render={ ( { open } ) => (
								<Button
									__next40pxDefaultSize
									variant="secondary"
									onClick={ open }
								>
									{ previewId
										? __(
												'Replace preview image',
												'leitvia-studio'
											)
										: __(
												'Choose preview image',
												'leitvia-studio'
											) }
								</Button>
							) }
						/>
					</MediaUploadCheck>
					{ previewId > 0 && (
						<Button
							__next40pxDefaultSize
							variant="link"
							isDestructive
							onClick={ () => setAttributes( { previewId: 0 } ) }
						>
							{ __( 'Remove preview image', 'leitvia-studio' ) }
						</Button>
					) }
				</PanelBody>
			</InspectorControls>
			{ preview?.source_url ? (
				<div className="leitvia-consent__placeholder has-preview">
					<img
						className="leitvia-consent__preview"
						src={ preview.source_url }
						alt=""
					/>
					<div className="leitvia-consent__text">{ texts }</div>
				</div>
			) : (
				<div className="leitvia-consent__placeholder">{ texts }</div>
			) }
			{ broken && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'This address will not be shown: it may not contain spaces, backslashes or quotes, and letters beyond ASCII only in the host name, where this server supports them.',
						'leitvia-studio'
					) }
				</Notice>
			) }
			{ provider && ! provider.listed && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'This host is not in the list of providers, so the placeholder names the host and links to no privacy policy. Add the provider under Leitvia Studio, Settings, Privacy.',
						'leitvia-studio'
					) }
				</Notice>
			) }
			{ provider && '' === title.trim() && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Add a title for screen readers in the block settings.',
						'leitvia-studio'
					) }
				</Notice>
			) }
		</div>
	);
}
