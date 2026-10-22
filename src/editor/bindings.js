// Editor side of the block bindings sources leitvia/meta, leitvia/setting and leitvia/relation (server:
// includes/model/class-bindings.php, which also sends label and usesContext). window.leitviaStudioBindings holds the
// declared fields by post type, the relations and the bindable site settings with their public values.
import { __, sprintf } from '@wordpress/i18n';
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import { useRegistry } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
import { getBlockType, registerBlockBindingsSource } from '@wordpress/blocks';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as coreStore } from '@wordpress/core-data';
import { store as editorStore } from '@wordpress/editor';
import { store as noticesStore } from '@wordpress/notices';
import { dateI18n, getSettings, gmdateI18n } from '@wordpress/date';

const SETTING_PREFIX = 'leitvia_site_';
// Field types whose value can be typed into a block; the others are edited in the model panel.
const EDITABLE = [ 'text', 'textarea', 'integer', 'number', 'select' ];
const HAS_ZONE = /(Z|[+-]\d{2}:?\d{2})$/;

// Text as HTML: the browser escapes it.
function escapeHTML( text ) {
	const node = document.createElement( 'div' );
	node.textContent = text;
	return node.innerHTML;
}

const toText = ( html ) =>
	new window.DOMParser().parseFromString( html, 'text/html' ).body
		.textContent;

// Text of rich text with its line breaks: <br> becomes a new line in textarea fields, a space elsewhere.
const richToText = ( html, multiline ) =>
	toText( html.replace( /<br\s*\/?>/gi, multiline ? '\n' : ' ' ) );

// Whether the attribute holds HTML (paragraph content, button text); its values are escaped.
const isHtml = ( blockName, attribute ) =>
	[ 'html', 'rich-text' ].includes(
		getBlockType( blockName )?.attributes?.[ attribute ]?.source
	);

// Stored dates are wall times of the site; formatting them as UTC keeps the wall time in any browser time zone.
function formatDate( pattern, value ) {
	return HAS_ZONE.test( value )
		? dateI18n( pattern, value )
		: gmdateI18n( pattern, `${ value }Z` );
}

/**
 * Display text of a stored value, like the server: dates in the site format, select options as labels, booleans as
 * Yes or No.
 *
 * @param {Object}                field Field declaration.
 * @param {string|number|boolean} value Stored value.
 * @return {string|undefined} Text, undefined when empty.
 */
function format( field, value ) {
	if ( value === undefined || value === null || value === '' ) {
		return undefined;
	}
	const { formats } = getSettings();
	switch ( field.type ) {
		case 'date':
			return formatDate( formats.date, `${ value }T00:00:00` );
		case 'datetime':
			return formatDate( `${ formats.date } ${ formats.time }`, value );
		case 'time':
			return formatDate( formats.time, `1970-01-01T${ value }` );
		case 'select':
			return field.options?.[ value ];
		case 'boolean':
			return value
				? __( 'Yes', 'leitvia-studio' )
				: __( 'No', 'leitvia-studio' );
		default:
			return String( value );
	}
}

/**
 * Stored value of typed text, undefined when it does not fit the field (the edit is dropped).
 *
 * @param {Object}        field    Field declaration.
 * @param {string|Object} newValue Attribute value, HTML or rich text data.
 * @return {string|number|null|undefined} Meta or option value.
 */
function parse( field, newValue ) {
	const text = richToText(
		String( newValue ?? '' ),
		field.type === 'textarea'
	).trim();
	if ( text === '' ) {
		return null;
	}
	switch ( field.type ) {
		case 'integer':
		case 'number': {
			const number = Number( text.replace( ',', '.' ) );
			if (
				! Number.isFinite( number ) ||
				( field.type === 'integer' && ! Number.isInteger( number ) )
			) {
				return undefined;
			}
			return number;
		}
		case 'select':
			return Object.keys( field.options ?? {} ).find(
				( key ) => key === text || field.options[ key ] === text
			);
		default:
			return text;
	}
}

/**
 * Bindings sources of the content model.
 *
 * @param {Object} data window.leitviaStudioBindings.
 * @return {Object[]} Source definitions for registerBlockBindingsSource().
 */
export function sources( data ) {
	const postTypes = data.postTypes ?? {};
	const relations = data.relations ?? {};
	const settings = data.settings ?? {};

	// Fields of the post type in context; without one (a template), the fields of every type. A key that several
	// types declare is labelled with their names.
	const fieldsFor = ( postType ) => {
		if ( postType ) {
			return postTypes[ postType ]?.fields ?? {};
		}
		const all = {};
		for ( const type of Object.values( postTypes ) ) {
			for ( const [ key, field ] of Object.entries( type.fields ) ) {
				all[ key ] = all[ key ]
					? {
							...all[ key ],
							types: [ ...all[ key ].types, type.label ],
						}
					: { ...field, types: [ type.label ] };
			}
		}
		for ( const field of Object.values( all ) ) {
			if ( field.types.length > 1 ) {
				field.label = sprintf(
					/* translators: 1: field label, for example Title. 2: post type labels, for example Event, Person. */
					__( '%1$s (%2$s)', 'leitvia-studio' ),
					field.label,
					field.types.join( ', ' )
				);
			}
		}
		return all;
	};

	// Value of a field for the attribute: image fields give URL, alt text or ID of the attachment.
	const fieldValue = ( select, field, value, attribute ) => {
		if ( field.type !== 'image' ) {
			return format( field, value );
		}
		if ( attribute === 'id' ) {
			return value || undefined;
		}
		const media = value
			? select( coreStore ).getEntityRecord(
					'postType',
					'attachment',
					value
				)
			: undefined;
		return (
			( attribute === 'alt' ? media?.alt_text : media?.source_url ) ||
			undefined
		);
	};

	// Escapes text for HTML attributes, with <br> for the line breaks of textarea fields; without a value, HTML
	// attributes show the label instead of nothing.
	const output = ( blockName, attribute, text, label, multiline = false ) => {
		const html = isHtml( blockName, attribute );
		if ( text === undefined ) {
			return html ? escapeHTML( label ) : undefined;
		}
		if ( ! html ) {
			return text;
		}
		const escaped = escapeHTML( String( text ) );
		return multiline ? escaped.replace( /\n/g, '<br>' ) : escaped;
	};

	// Stored values of edited bindings. A select value that matches no option is dropped; the notice waits until no
	// option label starts with the typed text, so it does not repeat on every keystroke while a label is typed.
	const edits = ( dispatch, fields, bindings, name ) => {
		const result = {};
		for ( const { args, newValue } of Object.values( bindings ) ) {
			const field = fields[ args?.key ];
			const value = field ? parse( field, newValue ) : undefined;
			if ( value !== undefined ) {
				result[ name( args.key ) ] = value;
			} else if (
				field?.type === 'select' &&
				! Object.values( field.options ?? {} ).some( ( label ) =>
					label.toLowerCase().startsWith(
						richToText( String( newValue ?? '' ), false )
							.trim()
							.toLowerCase()
					)
				)
			) {
				dispatch( noticesStore ).createErrorNotice(
					sprintf(
						/* translators: %s: option labels, for example Beginner, Advanced. */
						__( 'Choose one of: %s.', 'leitvia-studio' ),
						Object.values( field.options ?? {} ).join( ', ' )
					),
					{ type: 'snackbar', id: 'leitvia-studio-binding-select' }
				);
			}
		}
		return result;
	};

	const blockName = ( select, clientId ) =>
		select( blockEditorStore ).getBlockName( clientId );

	const meta = {
		name: 'leitvia/meta',
		getValues( { select, context, clientId, bindings } ) {
			const fields = fieldsFor( context?.postType );
			const values = context?.postId
				? ( select( coreStore ).getEditedEntityRecord(
						'postType',
						context.postType,
						context.postId
					)?.meta ?? {} )
				: {};
			const name = blockName( select, clientId );
			const result = {};
			for ( const [ attribute, { args } ] of Object.entries(
				bindings
			) ) {
				const field = fields[ args?.key ];
				result[ attribute ] = field
					? output(
							name,
							attribute,
							fieldValue(
								select,
								field,
								values[ args.key ],
								attribute
							),
							field.label,
							field.type === 'textarea'
						)
					: args?.key;
			}
			return result;
		},
		setValues( { dispatch, context, bindings } ) {
			const values = edits(
				dispatch,
				fieldsFor( context?.postType ),
				bindings,
				( key ) => key
			);
			if ( Object.keys( values ).length ) {
				dispatch( coreStore ).editEntityRecord(
					'postType',
					context.postType,
					context.postId,
					{ meta: values }
				);
			}
		},
		canUserEditValue( { select, context, args } ) {
			const field = postTypes[ context?.postType ]?.fields?.[ args?.key ];
			// Not in query loops, not next to the custom fields meta box, which would overwrite the value on save.
			if (
				! field ||
				! EDITABLE.includes( field.type ) ||
				! context?.postId ||
				context?.query ||
				context?.queryId ||
				select( editorStore ).getEditorSettings().enableCustomFields
			) {
				return false;
			}
			return !! select( coreStore ).canUser( 'update', {
				kind: 'postType',
				name: context.postType,
				id: context.postId,
			} );
		},
		fieldLabel: ( args, postType ) =>
			fieldsFor( postTypes[ postType ] ? postType : undefined )[
				args.key
			]?.label,
		getFieldsList( { context } ) {
			return Object.entries( fieldsFor( context?.postType ) ).map(
				( [ key, field ] ) => ( {
					label: field.label,
					type: 'string',
					args: { key },
				} )
			);
		},
	};

	// Users who may change settings read and write the edited site entity; everyone else sees the public value.
	const canManage = ( select ) =>
		!! select( coreStore ).canUser( 'update', {
			kind: 'root',
			name: 'site',
		} );

	const setting = {
		name: 'leitvia/setting',
		getValues( { select, clientId, bindings } ) {
			const site = canManage( select )
				? select( coreStore ).getEditedEntityRecord( 'root', 'site' )
				: undefined;
			const name = blockName( select, clientId );
			const result = {};
			for ( const [ attribute, { args } ] of Object.entries(
				bindings
			) ) {
				const field = settings[ args?.key ];
				const value = site
					? site[ SETTING_PREFIX + args.key ]
					: field?.value;
				result[ attribute ] = field
					? output(
							name,
							attribute,
							fieldValue( select, field, value, attribute ),
							field.label,
							field.type === 'textarea'
						)
					: args?.key;
			}
			return result;
		},
		setValues( { dispatch, bindings } ) {
			const options = edits(
				dispatch,
				settings,
				bindings,
				( key ) => SETTING_PREFIX + key
			);
			if ( Object.keys( options ).length ) {
				dispatch( coreStore ).editEntityRecord(
					'root',
					'site',
					undefined,
					options
				);
			}
		},
		canUserEditValue( { select, args } ) {
			return (
				EDITABLE.includes( settings[ args?.key ]?.type ) &&
				canManage( select )
			);
		},
		fieldLabel: ( args ) => settings[ args.key ]?.label,
		getFieldsList() {
			return Object.entries( settings ).map( ( [ key, field ] ) => ( {
				label: field.label,
				type: 'string',
				args: { key },
			} ) );
		},
	};

	// Fields a relation offers: title, permalink, excerpt and the declared fields of the target type.
	const relationFields = ( relation ) => ( {
		title: { label: __( 'Title', 'leitvia-studio' ) },
		url: { label: __( 'Link', 'leitvia-studio' ) },
		excerpt: { label: __( 'Excerpt', 'leitvia-studio' ) },
		...postTypes[ relation.to ]?.fields,
	} );

	const relationLabel = ( relation, field ) =>
		sprintf(
			/* translators: 1: relation label, for example Speakers. 2: field label, for example Title. */
			__( '%1$s: %2$s', 'leitvia-studio' ),
			relation.label,
			field.label
		);

	const relation = {
		name: 'leitvia/relation',
		getValues( { select, context, clientId, bindings } ) {
			const name = blockName( select, clientId );
			const ids = context?.postId
				? ( select( coreStore ).getEditedEntityRecord(
						'postType',
						context.postType,
						context.postId
					)?.meta ?? {} )
				: {};
			const result = {};
			for ( const [ attribute, { args } ] of Object.entries(
				bindings
			) ) {
				const declared = relations[ args?.relation ];
				const key = args?.field ?? 'title';
				const field = declared && relationFields( declared )[ key ];
				if ( ! field ) {
					result[ attribute ] = args?.relation;
					continue;
				}
				const id = ( ids[ args.relation ] ?? [] )[ args.index ?? 0 ];
				// View context: users who may not read the target type get nothing and see the label.
				const target = id
					? select( coreStore ).getEntityRecord(
							'postType',
							declared.to,
							id,
							{ context: 'view' }
						)
					: undefined;
				let text;
				if ( target && key === 'title' ) {
					text = toText( target.title?.rendered ?? '' );
				} else if ( target && key === 'url' ) {
					text = target.link;
				} else if ( target && key === 'excerpt' ) {
					text = toText( target.excerpt?.rendered ?? '' ).trim();
				} else if ( target ) {
					text = fieldValue(
						select,
						field,
						target.meta?.[ key ],
						attribute
					);
				}
				result[ attribute ] = output(
					name,
					attribute,
					text || undefined,
					relationLabel( declared, field ),
					field.type === 'textarea'
				);
			}
			return result;
		},
		canUserEditValue: () => false,
		fieldLabel( args ) {
			const declared = relations[ args.relation ];
			const field =
				declared && relationFields( declared )[ args.field ?? 'title' ];
			return field ? relationLabel( declared, field ) : undefined;
		},
		getFieldsList( { context } ) {
			return Object.entries( relations )
				.filter(
					( [ , item ] ) =>
						! context?.postType || item.from === context.postType
				)
				.flatMap( ( [ key, item ] ) =>
					Object.entries( relationFields( item ) ).map(
						( [ field, declared ] ) => ( {
							label: relationLabel( item, declared ),
							type: 'string',
							args: { relation: key, field },
						} )
					)
				);
		},
	};

	return [ meta, setting, relation ];
}

/**
 * Registers the sources and stores the field label as saved content of an empty bound text attribute, because core
 * keeps the saved content when the source is gone (Studio deactivated) and does not save the bound value itself.
 *
 * @param {Object} data window.leitviaStudioBindings.
 */
export function registerBindings( data ) {
	const list = sources( data );
	list.forEach( ( source ) => registerBlockBindingsSource( source ) );
	const byName = Object.fromEntries(
		list.map( ( source ) => [ source.name, source ] )
	);

	// Writes the field labels into empty bound text attributes of one block; renders nothing.
	function Fallback( { clientId, name, bindings } ) {
		const registry = useRegistry();
		useEffect( () => {
			const saved = registry
				.select( blockEditorStore )
				.getBlockAttributes( clientId );
			const postType = registry
				.select( editorStore )
				.getCurrentPostType();
			const fallback = {};
			for ( const [ attribute, binding ] of Object.entries( bindings ) ) {
				const source = byName[ binding?.source ];
				if (
					! source ||
					! isHtml( name, attribute ) ||
					String( saved?.[ attribute ] ?? '' ) !== ''
				) {
					continue;
				}
				const label = source.fieldLabel( binding.args ?? {}, postType );
				if ( label ) {
					fallback[ attribute ] = escapeHTML( label );
				}
			}
			if ( Object.keys( fallback ).length ) {
				const dispatch = registry.dispatch( blockEditorStore );
				dispatch.__unstableMarkNextChangeAsNotPersistent();
				dispatch.updateBlockAttributes( clientId, fallback );
			}
		}, [ bindings, clientId, name, registry ] );
		return null;
	}

	const withFallback = createHigherOrderComponent(
		( BlockEdit ) => ( props ) => {
			const bindings = props.attributes?.metadata?.bindings;
			// One tree with or without bindings, so adding a binding does not remount the block.
			return (
				<>
					{ bindings && (
						<Fallback
							clientId={ props.clientId }
							name={ props.name }
							bindings={ bindings }
						/>
					) }
					<BlockEdit { ...props } />
				</>
			);
		},
		'withLeitviaBindingsFallback'
	);
	addFilter(
		'editor.BlockEdit',
		'leitvia-studio/bindings-fallback',
		withFallback
	);
}
