// Content model declaration to DataForm fields and form. Plain module without @wordpress imports, so node:test loads
// it without a build. Items are records with a meta object: the edited post in the panel, a child post in the
// repeater dialog. Edit names "image" and "relation" are replaced by the controls of src/editor/model-panel.js.

// Declared field type: DataForm type and edit control.
const TYPES = {
	text: [ 'text', 'text' ],
	textarea: [ 'text', 'textarea' ],
	email: [ 'email', 'email' ],
	url: [ 'url', 'url' ],
	integer: [ 'integer', 'integer' ],
	number: [ 'number', 'number' ],
	boolean: [ 'boolean', 'toggle' ],
	select: [ 'text', 'select' ],
	date: [ 'date', 'date' ],
	datetime: [ 'datetime', 'datetime' ],
	time: [ 'text', 'time' ],
	image: [ 'integer', 'image' ],
};

const NUMERIC = [ 'integer', 'number' ];
const TEXT = [ 'text', 'textarea' ];

/**
 * Declaration of a post type or of a repeater, found by its post type key.
 *
 * @param {Object} declaration Content model declaration (at least postTypes and relations).
 * @param {string} postType    Post type key.
 * @return {Object} Declaration with fields, or an empty object.
 */
function find( declaration, postType ) {
	for ( const [ key, type ] of Object.entries(
		declaration.postTypes ?? {}
	) ) {
		if ( key === postType ) {
			return type;
		}
		const repeater = Object.values( type.repeaters ?? {} ).find(
			( item ) => item.postType === postType
		);
		if ( repeater ) {
			return repeater;
		}
	}
	return {};
}

// Reads and writes meta[ key ]; an emptied value is sent as null, which deletes the meta value.
const metaAccess = ( key, empty ) => ( {
	getValue: ( { item } ) => item?.meta?.[ key ] ?? empty,
	setValue: ( { value } ) => ( {
		meta: { [ key ]: value === '' || value === undefined ? null : value },
	} ),
} );

/**
 * Validation rules of a declared field, without the ones it does not set.
 *
 * @param {Object} field Field declaration.
 * @return {Object} DataForm isValid rules.
 */
function rules( field ) {
	const result = {
		required: field.required || undefined,
		// DataForm checks values against the options by default and fails on an empty optional select; the select
		// control only offers the options, the REST schema refuses others.
		elements: field.options ? false : undefined,
		pattern: field.pattern,
	};
	if ( NUMERIC.includes( field.type ) ) {
		result.min = field.min;
		result.max = field.max;
	}
	if ( TEXT.includes( field.type ) ) {
		result.maxLength = field.maxLength;
	}
	return Object.fromEntries(
		Object.entries( result ).filter(
			( [ , value ] ) => value !== undefined
		)
	);
}

/**
 * DataForm fields of a post type or repeater: its declared fields, then the relations that start at it.
 *
 * @param {Object} declaration Content model declaration.
 * @param {string} postType    Post type key.
 * @return {Object[]} DataForm fields.
 */
export function toFields( declaration, postType ) {
	const fields = Object.entries(
		find( declaration, postType ).fields ?? {}
	).map( ( [ key, field ] ) => {
		const [ type, Edit ] = TYPES[ field.type ] ?? TYPES.text;
		return {
			id: key,
			label: field.label,
			description: field.description,
			type,
			Edit,
			elements: field.options
				? Object.entries( field.options ).map(
						( [ value, label ] ) => ( { value, label } )
					)
				: undefined,
			isValid: rules( field ),
			...metaAccess( key, '' ),
		};
	} );
	const relations = Object.entries( declaration.relations ?? {} )
		.filter( ( [ , relation ] ) => relation.from === postType )
		.map( ( [ key, relation ] ) => ( {
			id: key,
			label: relation.label,
			description: relation.description,
			// No DataForm type: the array type expects strings, relations store post IDs.
			Edit: 'relation',
			relation: { to: relation.to, multiple: !! relation.multiple },
			...metaAccess( key, [] ),
		} ) );
	return [ ...fields, ...relations ];
}

/**
 * DataForm form: every field in declaration order, panel layout. Image fields render inline, because the media
 * library would close the dropdown of a panel row.
 *
 * @param {Object} declaration Content model declaration.
 * @param {string} postType    Post type key.
 * @return {Object} DataForm form.
 */
export function toForm( declaration, postType ) {
	return {
		layout: { type: 'panel' },
		fields: toFields( declaration, postType ).map( ( field ) =>
			'image' === field.Edit
				? { id: field.id, layout: { type: 'regular' } }
				: field.id
		),
	};
}
