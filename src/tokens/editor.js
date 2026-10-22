// Editor of one token set: role values per group, live contrast check, preview at theme patterns, primitives.
import { __, _n, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { DataForm } from '@wordpress/dataviews/wp';
import {
	Button,
	Flex,
	Notice,
	SelectControl,
	TextControl,
	// The segmented control core uses for viewport switches; it has no stable export yet.
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import Loading from '../admin/loading';
import { notify } from '../admin/notify';
import PreviewFrame from '../lab/preview-frame';
import ContrastTable, { contrastRows } from './contrast-table';
import { ActivateDialog } from './list';
import OverlayForm, { OVERLAY } from './overlay-form';
import { Findings, GROUPS, META, errorFindings, tokensUrl } from './common';

const HEX = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i;
const ALIAS = /^\{([^{}]+)\}$/;
const DIMENSION = /^(-?\d*\.?\d+)(px|rem)$/;
const EXT = 'com.leitvia';
const VIEWPORTS = [ 390, 782, 1440 ];
const PREVIEW_AREAS = [
	[ 'hero', __( 'Hero', 'leitvia-studio' ) ],
	[ 'content', __( 'Content', 'leitvia-studio' ) ],
	[ 'cta', __( 'Call to action', 'leitvia-studio' ) ],
];

const fieldKey = ( group, slug ) => `${ group }:${ slug }`;
const fieldId = ( slug ) => `leitvia-studio-token-color-${ slug }`;
const longHex = ( hex ) =>
	4 === hex.length
		? `#${ [ ...hex.slice( 1 ) ].map( ( c ) => c + c ).join( '' ) }`.toLowerCase()
		: hex.toLowerCase();

/**
 * Hex color of a DTCG color value: "hex" wins over "components", like Dtcg::hex().
 *
 * @param {string|Object} value DTCG color value.
 * @return {string} #rrggbb or ''.
 */
function hexOf( value ) {
	if ( typeof value === 'string' ) {
		return HEX.test( value ) ? longHex( value ) : '';
	}
	if ( HEX.test( value?.hex ?? '' ) && 7 === value.hex.length ) {
		return value.hex.toLowerCase();
	}
	const components = value?.components;
	if ( ! Array.isArray( components ) || 3 !== components.length ) {
		return '';
	}
	return (
		'#' +
		components
			.map( ( c ) =>
				Math.round( Number( c ) * 255 )
					.toString( 16 )
					.padStart( 2, '0' )
			)
			.join( '' )
	);
}

/**
 * Follows an alias chain like Dtcg::alias(), at most 10 steps.
 *
 * @param {string|Object} value Token value, an alias or a literal.
 * @param {Object}        doc   Token document.
 * @return {string|Object|undefined} Resolved value, undefined for a broken alias.
 */
function resolve( value, doc ) {
	for ( let depth = 0; depth < 10; depth++ ) {
		const match = typeof value === 'string' && ALIAS.exec( value );
		if ( ! match ) {
			return value;
		}
		value = match[ 1 ]
			.split( '.' )
			.reduce( ( node, part ) => node?.[ part ], doc )?.$value;
	}
	return undefined;
}

/**
 * Tokens outside the role groups (primitives), with their path and type; types inherit from the parent group.
 *
 * @param {Object} doc Token document.
 * @return {Array<{path: string, type: string, value: Object}>} Primitives.
 */
function primitives( doc ) {
	const roleGroups = GROUPS.map( ( group ) => group.id );
	const found = [];
	const walk = ( node, path, type ) => {
		if ( ! node || typeof node !== 'object' || Array.isArray( node ) ) {
			return;
		}
		const ownType = node.$type ?? type;
		if ( '$value' in node ) {
			found.push( { path, type: ownType ?? '', value: node.$value } );
			return;
		}
		for ( const [ name, child ] of Object.entries( node ) ) {
			if ( ! name.startsWith( '$' ) ) {
				walk( child, path ? `${ path }.${ name }` : name, ownType );
			}
		}
	};
	for ( const [ name, child ] of Object.entries( doc ) ) {
		if ( ! name.startsWith( '$' ) && ! roleGroups.includes( name ) ) {
			walk( child, name, undefined );
		}
	}
	return found;
}

/**
 * Form values of the roles: colors as #rrggbb or alias, every other role as its CSS value from the compiled
 * settings of the check route (the document itself when it does not compile).
 *
 * @param {Object}      doc      Token document.
 * @param {Object|null} settings Compiled settings.
 * @param {Object}      roles    Contract roles.
 */
function formValues( doc, settings, roles ) {
	const values = {};
	for ( const group of GROUPS ) {
		const presets =
			settings?.[ group.list[ 0 ] ]?.[ group.list[ 1 ] ]?.theme ?? [];
		for ( const { slug } of roles[ group.id ] ?? [] ) {
			const token = doc[ group.id ]?.[ slug ];
			let value = presets.find( ( p ) => p.slug === slug )?.[ group.key ];
			if ( 'color' === group.id ) {
				value =
					typeof token?.$value === 'string' &&
					ALIAS.test( token.$value )
						? token.$value
						: ( value ?? hexOf( token?.$value ) );
			}
			values[ fieldKey( group.id, slug ) ] =
				value ?? token?.$extensions?.[ EXT ]?.css ?? '';
		}
	}
	return values;
}

/**
 * Token of a role after an edit. Colors become sRGB values with hex, plain px/rem lengths become dimensions, every
 * other value goes into the Leitvia css extension, which the compiler prefers (Dtcg::preset_value()).
 *
 * @param {Object} group Role group.
 * @param {Object} token Token before the edit, may be undefined.
 * @param {string} value Form value.
 */
function toToken( group, token, value ) {
	const type = { color: 'color', fontFamily: 'fontFamily', shadow: 'shadow' }[
		group.id
	];
	const next = {
		$type: type ?? 'dimension',
		...structuredClone( token ?? {} ),
	};
	const ext = { ...( next.$extensions?.[ EXT ] ?? {} ) };
	if ( 'color' === group.id ) {
		if ( ALIAS.test( value ) ) {
			next.$value = value;
		} else {
			const hex = longHex( value );
			next.$value = {
				colorSpace: 'srgb',
				components: [ 1, 3, 5 ].map(
					( i ) =>
						Math.round(
							( parseInt( hex.slice( i, i + 2 ), 16 ) / 255 ) *
								1000
						) / 1000
				),
				hex,
			};
		}
		return next;
	}
	const dimension = ! type && DIMENSION.exec( value.trim() );
	if ( dimension ) {
		next.$value = { value: Number( dimension[ 1 ] ), unit: dimension[ 2 ] };
		delete ext.css;
	} else {
		ext.css = value.trim();
	}
	next.$extensions = { ...next.$extensions, [ EXT ]: ext };
	if ( ! Object.keys( ext ).length ) {
		delete next.$extensions[ EXT ];
	}
	return next;
}

function ColorEdit( { data, field, onChange, aliases } ) {
	const value = data[ field.id ] ?? '';
	const isAlias = ALIAS.test( value );
	const slug = field.id.split( ':' )[ 1 ];
	const id = fieldId( slug );
	const descriptionId = `${ id }-description`;
	const set = ( next ) => onChange( { [ field.id ]: next } );
	return (
		<Flex direction="column" justify="flex-start" gap={ 2 } align="stretch">
			{ aliases.length > 0 && (
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					// Links from the contrast table and the findings lead here while the role refers to a primitive.
					id={ isAlias ? id : undefined }
					label={ sprintf(
						/* translators: %s: color role. */
						__( 'Value of %s', 'leitvia-studio' ),
						field.label
					) }
					value={ isAlias ? value : '' }
					options={ [
						{
							value: '',
							label: __( 'Own color', 'leitvia-studio' ),
						},
						...aliases.map( ( alias ) => ( {
							value: `{${ alias.path }}`,
							label: sprintf(
								/* translators: 1: primitive token path, 2: hex color. */
								__( 'Primitive %1$s (%2$s)', 'leitvia-studio' ),
								alias.path,
								alias.hex
							),
						} ) ),
					] }
					onChange={ ( next ) =>
						set(
							next ||
								aliases.find(
									( a ) => `{${ a.path }}` === value
								)?.hex ||
								'#000000'
						)
					}
				/>
			) }
			{ ! isAlias && (
				<Flex justify="flex-start" align="flex-end" gap={ 2 }>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						className="leitvia-studio-tokens__hex"
						id={ id }
						label={ field.label }
						value={ value }
						pattern="^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$"
						onChange={ set }
						aria-describedby={ descriptionId }
						aria-invalid={ ! HEX.test( value ) }
						spellCheck={ false }
						autoComplete="off"
					/>
					<input
						type="color"
						className="leitvia-studio-tokens__swatch"
						aria-label={ sprintf(
							/* translators: %s: color role. */
							__( 'Pick %s', 'leitvia-studio' ),
							field.label
						) }
						aria-describedby={ descriptionId }
						value={
							HEX.test( value ) ? longHex( value ) : '#000000'
						}
						onChange={ ( event ) => set( event.target.value ) }
					/>
				</Flex>
			) }
			<p
				id={ descriptionId }
				className="components-base-control__help leitvia-studio-tokens__help"
			>
				{ field.description }
			</p>
		</Flex>
	);
}

function Preview( { setId, patterns, version } ) {
	const [ width, setWidth ] = useState( 1440 );
	return (
		<section
			aria-labelledby="leitvia-studio-tokens-preview"
			className="leitvia-studio-tokens__section"
		>
			<h2 id="leitvia-studio-tokens-preview">
				{ __( 'Preview', 'leitvia-studio' ) }
			</h2>
			<p>
				{ __(
					'Shows the saved state of the set; save to see changes here.',
					'leitvia-studio'
				) }
			</p>
			<ToggleGroupControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				isBlock
				label={ __( 'Viewport', 'leitvia-studio' ) }
				value={ String( width ) }
				onChange={ ( value ) => setWidth( Number( value ) ) }
				className="leitvia-studio-tokens__viewport"
			>
				{ VIEWPORTS.map( ( value ) => (
					<ToggleGroupControlOption
						key={ value }
						value={ String( value ) }
						label={ sprintf(
							/* translators: %d: width in pixels. */
							__( '%d px', 'leitvia-studio' ),
							value
						) }
					/>
				) ) }
			</ToggleGroupControl>
			{ patterns.map( ( { area, label, pattern } ) => (
				<Flex
					key={ area }
					direction="column"
					justify="flex-start"
					gap={ 2 }
					align="stretch"
				>
					<h3>{ label }</h3>
					<PreviewFrame
						key={ version }
						patternRef={ `pattern:${ pattern.slug }` }
						set={ setId }
						width={ width }
						title={ sprintf(
							/* translators: 1: pattern title, 2: width in pixels. */
							__(
								'Preview of %1$s with this token set at %2$d px',
								'leitvia-studio'
							),
							pattern.title,
							width
						) }
					/>
				</Flex>
			) ) }
		</section>
	);
}

function Primitives( { list } ) {
	return (
		<section
			aria-labelledby="leitvia-studio-tokens-primitives"
			className="leitvia-studio-tokens__section"
		>
			<h2 id="leitvia-studio-tokens-primitives">
				{ __( 'Primitives', 'leitvia-studio' ) }
			</h2>
			<p>
				{ __(
					'Base values the roles can refer to. They are read only here; change them in the file and import it again.',
					'leitvia-studio'
				) }
			</p>
			{ list.length ? (
				<table className="widefat striped">
					<thead>
						<tr>
							<th scope="col">
								{ __( 'Token', 'leitvia-studio' ) }
							</th>
							<th scope="col">
								{ __( 'Type', 'leitvia-studio' ) }
							</th>
							<th scope="col">
								{ __( 'Value', 'leitvia-studio' ) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ list.map( ( item ) => (
							<tr key={ item.path }>
								<th scope="row">{ item.path }</th>
								<td>{ item.type }</td>
								<td>
									<code>
										{ 'color' === item.type &&
										hexOf( item.value )
											? hexOf( item.value )
											: JSON.stringify( item.value ) }
									</code>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) : (
				<p>{ __( 'This set has no primitives.', 'leitvia-studio' ) }</p>
			) }
		</section>
	);
}

/**
 * Set, its check result and the contract.
 *
 * @param {number} id Token set ID.
 */
function useSet( id ) {
	const [ state, setState ] = useState( null );
	const load = useCallback( async () => {
		try {
			const [ post, check, contract ] = await Promise.all( [
				apiFetch( {
					path: `/wp/v2/leitvia-tokens/${ id }?context=edit`,
				} ),
				apiFetch( {
					path: `/leitvia-studio/v1/token-sets/${ id }/check`,
				} ),
				apiFetch( { path: '/leitvia-studio/v1/contract' } ),
			] );
			setState( {
				title: post.title.raw,
				doc: JSON.parse( post.meta[ META ] || '{}' ),
				overlay: post.meta[ OVERLAY ],
				check,
				contract: contract.contract,
				active: contract.active,
				drift: contract.drift,
			} );
		} catch ( error ) {
			setState( { error } );
		}
	}, [ id ] );
	useEffect( () => {
		load();
	}, [ load ] );
	return [ state, load ];
}

export default function Editor( { id } ) {
	const [ state, reload ] = useSet( id );
	const [ title, setTitle ] = useState( '' );
	const [ values, setValues ] = useState( {} );
	const [ initial, setInitial ] = useState( {} );
	const [ findings, setFindings ] = useState( null );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ version, setVersion ] = useState( 0 );
	const [ isActivating, setIsActivating ] = useState( false );

	// Leaving with unsaved changes asks first.
	const isDirty =
		!! state?.doc &&
		( title !== state.title ||
			Object.keys( initial ).some(
				( key ) => values[ key ] !== initial[ key ]
			) );
	useEffect( () => {
		if ( ! isDirty ) {
			return;
		}
		const warn = ( event ) => {
			event.preventDefault();
			event.returnValue = '';
		};
		window.addEventListener( 'beforeunload', warn );
		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ isDirty ] );

	const roles = state?.contract?.roles;
	useEffect( () => {
		if ( state?.doc && roles ) {
			const next = formValues( state.doc, state.check.settings, roles );
			setTitle( state.title );
			setValues( next );
			setInitial( next );
		}
	}, [ state, roles ] );

	const colorPrimitives = useMemo(
		() =>
			state?.doc
				? primitives( state.doc )
						.filter( ( item ) => 'color' === item.type )
						.map( ( item ) => ( {
							path: item.path,
							hex: hexOf( resolve( item.value, state.doc ) ),
						} ) )
						.filter( ( item ) => item.hex )
				: [],
		[ state ]
	);

	const fields = useMemo(
		() =>
			GROUPS.flatMap( ( group ) =>
				( roles?.[ group.id ] ?? [] ).map( ( role ) => ( {
					id: fieldKey( group.id, role.slug ),
					label:
						state.doc[ group.id ]?.[ role.slug ]?.$extensions?.[
							EXT
						]?.name ?? role.slug,
					description: role.purpose,
					type: 'text',
					...( 'color' === group.id
						? {
								Edit: ( props ) => (
									<ColorEdit
										{ ...props }
										aliases={ colorPrimitives }
									/>
								),
							}
						: {} ),
				} ) )
			),
		[ roles, state, colorPrimitives ]
	);

	const form = useMemo(
		() => ( {
			layout: { type: 'card' },
			fields: GROUPS.filter(
				( group ) => roles?.[ group.id ]?.length
			).map( ( group ) => ( {
				id: group.id,
				label: group.label,
				children: roles[ group.id ].map( ( role ) =>
					fieldKey( group.id, role.slug )
				),
				layout: {
					type: 'card',
					isCollapsible: 'color' !== group.id,
					isOpened: 'color' === group.id,
				},
			} ) ),
		} ),
		[ roles ]
	);

	const rows = useMemo( () => {
		if ( ! roles || ! state?.doc ) {
			return [];
		}
		const palette = ( roles.color ?? [] ).map( ( { slug } ) => {
			const value = values[ fieldKey( 'color', slug ) ] ?? '';
			return {
				slug,
				color: ALIAS.test( value )
					? hexOf( resolve( value, state.doc ) )
					: value,
			};
		} );
		return contrastRows( palette, state.contract.pairs ?? [] );
	}, [ roles, state, values ] );

	// The form waits for its values, so the contrast table never shows a check of empty colors.
	if ( null === state || ( roles && ! Object.keys( initial ).length ) ) {
		return <Loading />;
	}
	const back = (
		<a href={ tokensUrl() }>
			{ __( 'Back to all token sets', 'leitvia-studio' ) }
		</a>
	);
	if ( state.error || ! roles ) {
		return (
			<Flex
				direction="column"
				justify="flex-start"
				gap={ 4 }
				align="flex-start"
			>
				<Notice status="error" isDismissible={ false }>
					{ state.error?.message ??
						__(
							'The active theme has no valid contract.',
							'leitvia-studio'
						) }
				</Notice>
				{ back }
			</Flex>
		);
	}

	const failing = rows.filter( ( row ) => 'fail' === row.result );
	const invalidColors = ( roles.color ?? [] )
		.map( ( { slug } ) => slug )
		.filter( ( slug ) => {
			const value = values[ fieldKey( 'color', slug ) ] ?? '';
			return ! HEX.test( value ) && ! ALIAS.test( value );
		} );
	const labelOf = ( slug ) =>
		fields.find( ( field ) => field.id === fieldKey( 'color', slug ) )
			?.label ?? slug;
	let reason = '';
	if ( invalidColors.length ) {
		reason = sprintf(
			/* translators: %s: list of color roles. */
			__(
				'Saving is blocked: enter colors as #rgb or #rrggbb (%s).',
				'leitvia-studio'
			),
			invalidColors.map( labelOf ).join( ', ' )
		);
	} else if ( failing.length ) {
		reason = sprintf(
			/* translators: 1: number of failing contrast pairs, 2: list of pairs. */
			_n(
				'Saving is blocked: %1$d contrast pair fails (%2$s).',
				'Saving is blocked: %1$d contrast pairs fail (%2$s).',
				failing.length,
				'leitvia-studio'
			),
			failing.length,
			failing
				.map( ( row ) =>
					sprintf(
						/* translators: 1: foreground color role, 2: background color role. */
						__( '%1$s on %2$s', 'leitvia-studio' ),
						labelOf( row.fg ),
						labelOf( row.bg )
					)
				)
				.join( ', ' )
		);
	}
	const warnings = state.check.findings.filter(
		( finding ) => 'warning' === finding.severity
	);
	const stored = state.check.findings.filter(
		( finding ) => 'error' === finding.severity
	);
	const patterns = PREVIEW_AREAS.map( ( [ area, label ] ) => ( {
		area,
		label,
		pattern: ( state.contract.patterns ?? [] ).find(
			( pattern ) => pattern.area === area
		),
	} ) ).filter( ( entry ) => entry.pattern );

	const save = async ( event ) => {
		event.preventDefault();
		if ( reason ) {
			return;
		}
		const doc = structuredClone( state.doc );
		for ( const group of GROUPS ) {
			for ( const { slug } of roles[ group.id ] ?? [] ) {
				const key = fieldKey( group.id, slug );
				if ( values[ key ] !== initial[ key ] ) {
					doc[ group.id ] = {
						...doc[ group.id ],
						[ slug ]: toToken(
							group,
							doc[ group.id ]?.[ slug ],
							values[ key ]
						),
					};
				}
			}
		}
		setIsSaving( true );
		setFindings( null );
		try {
			await apiFetch( {
				path: `/wp/v2/leitvia-tokens/${ id }`,
				method: 'POST',
				data: { title, meta: { [ META ]: JSON.stringify( doc ) } },
			} );
			notify( 'success', __( 'Token set saved.', 'leitvia-studio' ) );
			setVersion( ( current ) => current + 1 );
			await reload();
		} catch ( error ) {
			setFindings( errorFindings( error ) );
		} finally {
			setIsSaving( false );
		}
	};

	return (
		<Flex
			direction="column"
			justify="flex-start"
			gap={ 6 }
			align="stretch"
			className="leitvia-studio-tokens leitvia-studio-tokens__editor"
		>
			{ back }
			<form onSubmit={ save } noValidate>
				<Flex
					direction="column"
					justify="flex-start"
					gap={ 6 }
					align="stretch"
				>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Title', 'leitvia-studio' ) }
						value={ title }
						onChange={ setTitle }
					/>
					{ state.drift && id === state.active && (
						<Notice
							status="warning"
							isDismissible={ false }
							actions={ [
								{
									label: __(
										'Activate again',
										'leitvia-studio'
									),
									onClick: () => setIsActivating( true ),
									variant: 'secondary',
								},
							] }
						>
							{ __(
								'This is the active set, and its saved state is not applied to the global styles yet.',
								'leitvia-studio'
							) }
						</Notice>
					) }
					{ stored.length > 0 && ! findings && (
						<Notice status="warning" isDismissible={ false }>
							{ sprintf(
								/* translators: %s: findings. */
								__(
									'The saved state does not pass the checks: %s',
									'leitvia-studio'
								),
								stored.map( ( f ) => f.message ).join( ' ' )
							) }
						</Notice>
					) }
					{ warnings.length > 0 && (
						<Notice status="warning" isDismissible={ false }>
							<ul className="leitvia-studio-tokens__list">
								{ warnings.map( ( finding, index ) => (
									<li key={ index }>{ finding.message }</li>
								) ) }
							</ul>
						</Notice>
					) }
					{ findings && (
						<Findings
							heading={ __(
								'The token set was not saved',
								'leitvia-studio'
							) }
							findings={ findings }
							linkTo={ ( finding ) =>
								finding.data?.fg && fieldId( finding.data.fg )
							}
						/>
					) }
					<DataForm
						data={ values }
						fields={ fields }
						form={ form }
						onChange={ ( edits ) =>
							setValues( ( current ) => ( {
								...current,
								...edits,
							} ) )
						}
					/>
					<section
						aria-labelledby="leitvia-studio-tokens-contrast"
						className="leitvia-studio-tokens__section"
					>
						<h2 id="leitvia-studio-tokens-contrast">
							{ __( 'Contrast', 'leitvia-studio' ) }
						</h2>
						<ContrastTable
							rows={ rows }
							fieldId={ fieldId }
							labelledBy="leitvia-studio-tokens-contrast"
						/>
					</section>
					<Flex justify="flex-start" align="center" gap={ 4 } wrap>
						<Button
							__next40pxDefaultSize
							type="submit"
							variant="primary"
							isBusy={ isSaving }
							disabled={ !! reason || isSaving }
							accessibleWhenDisabled
							aria-describedby={
								reason
									? 'leitvia-studio-tokens-reason'
									: undefined
							}
						>
							{ __( 'Save token set', 'leitvia-studio' ) }
						</Button>
						{ /* Always rendered, so the live region announces when saving gets blocked or free again. */ }
						<p
							id="leitvia-studio-tokens-reason"
							className="leitvia-studio-tokens__reason"
							aria-live="polite"
						>
							{ reason }
						</p>
					</Flex>
				</Flex>
			</form>
			<OverlayForm
				id={ id }
				initial={ state.overlay }
				valid={ state.check.valid }
			/>
			<Preview setId={ id } patterns={ patterns } version={ version } />
			<Primitives list={ primitives( state.doc ) } />
			{ isActivating && (
				<ActivateDialog
					item={ { id, title: state.title } }
					roles={ roles }
					onClose={ () => setIsActivating( false ) }
					onDone={ reload }
				/>
			) }
		</Flex>
	);
}
