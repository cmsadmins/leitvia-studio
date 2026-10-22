// Declaration tab: the entries from code read-only, the screen's own entries in the CodeMirror JSON editor of core.
import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Button, Flex } from '@wordpress/components';
import { notify } from '../admin/notify';

const SECTIONS = [ 'postTypes', 'taxonomies', 'relations', 'settings' ];

/**
 * Entries of one source, without the source mark.
 *
 * @param {Object} model  Declaration from GET /model, every entry marked with source.
 * @param {string} source 'code' or 'ui'.
 * @return {Object} Declaration.
 */
function part( model, source ) {
	const decl = { version: 1 };
	for ( const section of SECTIONS ) {
		const entries = Object.entries( model[ section ] ?? {} )
			.filter( ( [ , entry ] ) => entry.source === source )
			.map( ( [ key, { source: omit, ...entry } ] ) => [ key, entry ] );
		if ( entries.length ) {
			decl[ section ] = Object.fromEntries( entries );
		}
	}
	return decl;
}

/**
 * Text offset of a JSON pointer, as far as its keys are found in order.
 *
 * @param {string} text JSON text.
 * @param {string} path JSON pointer, for example /postTypes/event/fields.
 * @return {number} Offset.
 */
export function offsetOf( text, path ) {
	let offset = 0;
	for ( const segment of path.split( '/' ).filter( Boolean ) ) {
		const pattern = new RegExp(
			JSON.stringify( segment ).replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) +
				'\\s*:',
			'g'
		);
		pattern.lastIndex = offset;
		const match = pattern.exec( text );
		if ( ! match ) {
			break;
		}
		offset = match.index + 1;
	}
	return offset;
}

const HELP_ID = 'leitvia-studio-model-json-help';
const ERRORS_ID = 'leitvia-studio-model-errors';

/**
 * JSON editor of the declaration.
 *
 * @param {Object}                     props         Props.
 * @param {Object}                     props.model   Declaration from GET /model.
 * @param {(response: Object) => void} props.onSaved Called with the response of a successful save.
 * @param {Object}                     props.draft   Ref that keeps unsaved text across tab changes; null when nothing is unsaved.
 */
export default function JsonEditor( { model, onSaved, draft } ) {
	const textarea = useRef();
	const editor = useRef( null );
	const codeBlock = useRef();
	const validateButton = useRef();
	const errorsHeading = useRef();
	const [ errors, setErrors ] = useState( [] );
	// Counts failed checks; each one moves the focus to the error heading once the list is rendered.
	const [ failures, setFailures ] = useState( 0 );
	const [ busy, setBusy ] = useState( false );
	const code = useMemo( () => part( model, 'code' ), [ model ] );
	const hasCode = Object.keys( code ).length > 1;
	const [ initial ] = useState(
		() => draft.current ?? JSON.stringify( part( model, 'ui' ), null, '\t' )
	);
	const describedBy = errors.length ? `${ HELP_ID } ${ ERRORS_ID }` : HELP_ID;

	useEffect( () => {
		const settings = window.leitviaStudioCodeEditor;
		if ( ! settings || ! window.wp?.codeEditor ) {
			return;
		}
		// Escape and then Tab or Shift+Tab leave the editor (core's tabbing), to the buttons or to what comes before.
		const instance = window.wp.codeEditor.initialize( textarea.current, {
			...settings,
			codemirror: {
				...settings.codemirror,
				// The contenteditable input is the one screen readers read.
				inputStyle: 'contenteditable',
			},
			onTabNext: () => validateButton.current.focus(),
			onTabPrevious: () =>
				(
					codeBlock.current ??
					document.querySelector(
						'#leitvia-studio-root [role="tab"][aria-selected="true"]'
					)
				)?.focus(),
		} );
		const input = instance.codemirror.getInputField();
		input.setAttribute( 'role', 'textbox' );
		input.setAttribute( 'aria-multiline', 'true' );
		input.setAttribute(
			'aria-labelledby',
			'leitvia-studio-model-json-label'
		);
		instance.codemirror.on( 'change', ( cm ) => {
			draft.current = cm.getValue();
		} );
		editor.current = instance.codemirror;
		return () => {
			editor.current = null;
			instance.codemirror.toTextArea();
		};
	}, [ draft ] );

	// The editor input names the help text and, after a failed check, the error list.
	useEffect( () => {
		const input = editor.current?.getInputField();
		if ( input ) {
			input.setAttribute( 'aria-describedby', describedBy );
			input.setAttribute(
				'aria-invalid',
				errors.length ? 'true' : 'false'
			);
		}
	}, [ describedBy, errors.length ] );

	const text = () =>
		editor.current ? editor.current.getValue() : textarea.current.value;

	const goTo = ( path ) => {
		const offset = offsetOf( text(), path );
		if ( editor.current ) {
			editor.current.setCursor( editor.current.posFromIndex( offset ) );
			editor.current.focus();
		} else {
			textarea.current.setSelectionRange( offset, offset );
			textarea.current.focus();
		}
	};

	// After a failed check the focus moves to the error heading, so keyboard users land on the list. In an effect, not
	// in the next animation frame: a busy browser can paint before React renders the list, and the heading is missing.
	useEffect( () => {
		if ( failures ) {
			errorsHeading.current?.focus();
		}
	}, [ failures ] );

	const fail = ( list, message ) => {
		setErrors( list );
		setFailures( ( count ) => count + 1 );
		notify( 'error', message );
	};

	const send = async ( path, method, onSuccess ) => {
		let decl;
		try {
			decl = JSON.parse( text() );
		} catch ( error ) {
			const message = sprintf(
				/* translators: %s: error message of the JSON parser. */
				__( 'The declaration is not valid JSON: %s', 'leitvia-studio' ),
				error.message
			);
			fail( [ { path: '', message } ], message );
			return;
		}
		setBusy( true );
		try {
			const response = await apiFetch( { path, method, data: decl } );
			setErrors( [] );
			onSuccess( response );
		} catch ( error ) {
			const list = error.data?.errors ?? [
				{ path: '', message: error.message },
			];
			fail(
				list,
				sprintf(
					/* translators: %d: number of errors. */
					_n(
						'The declaration has %d error.',
						'The declaration has %d errors.',
						list.length,
						'leitvia-studio'
					),
					list.length
				)
			);
		} finally {
			setBusy( false );
		}
	};

	const validate = () =>
		send( '/leitvia-studio/v1/model/validate', 'POST', () =>
			notify(
				'success',
				__( 'The declaration is valid.', 'leitvia-studio' )
			)
		);

	const save = () =>
		send( '/leitvia-studio/v1/model', 'PUT', ( response ) => {
			draft.current = null;
			notify(
				'success',
				__(
					'Content model saved. New post types appear in the menu after the next page load.',
					'leitvia-studio'
				)
			);
			onSaved( response );
		} );

	return (
		<Flex
			direction="column"
			justify="flex-start"
			gap={ 4 }
			align="stretch"
			className="leitvia-studio-model__declaration"
		>
			{ hasCode && (
				<section aria-labelledby="leitvia-studio-model-code">
					<h2 id="leitvia-studio-model-code">
						{ __(
							'Declared in code (read-only)',
							'leitvia-studio'
						) }
					</h2>
					<pre
						ref={ codeBlock }
						className="leitvia-studio-model__code"
						// Scrollable regions must be reachable with the keyboard.
						tabIndex={ 0 }
					>
						{ JSON.stringify( code, null, '\t' ) }
					</pre>
				</section>
			) }
			<div>
				<label
					id="leitvia-studio-model-json-label"
					htmlFor="leitvia-studio-model-json"
					className="leitvia-studio-model__label"
				>
					{ __( 'Declaration (JSON)', 'leitvia-studio' ) }
				</label>
				<p id={ HELP_ID } className="description">
					{ __(
						'Post types, taxonomies, relations and site settings of this site. In the editor, the Tab key inserts a tab; press Escape and then Tab to leave it.',
						'leitvia-studio'
					) }
				</p>
				<textarea
					ref={ textarea }
					id="leitvia-studio-model-json"
					className="large-text code"
					rows={ 24 }
					defaultValue={ initial }
					onChange={ ( event ) => {
						draft.current = event.target.value;
					} }
					aria-describedby={ describedBy }
					aria-invalid={ errors.length > 0 }
				/>
			</div>
			<Flex justify="flex-start">
				<Button
					ref={ validateButton }
					__next40pxDefaultSize
					variant="secondary"
					onClick={ validate }
					disabled={ busy }
					accessibleWhenDisabled
				>
					{ __( 'Validate', 'leitvia-studio' ) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="primary"
					onClick={ save }
					isBusy={ busy }
					disabled={ busy }
					accessibleWhenDisabled
				>
					{ __( 'Save', 'leitvia-studio' ) }
				</Button>
			</Flex>
			{ errors.length > 0 && (
				<section
					id={ ERRORS_ID }
					aria-labelledby={ `${ ERRORS_ID }-heading` }
				>
					<h2
						id={ `${ ERRORS_ID }-heading` }
						ref={ errorsHeading }
						tabIndex={ -1 }
					>
						{ __( 'Errors', 'leitvia-studio' ) }
					</h2>
					<ul className="leitvia-studio-model__errors">
						{ errors.map( ( error, index ) => (
							<li key={ index }>
								<Button
									variant="link"
									onClick={ () => goTo( error.path ) }
								>
									{ error.path ||
										__( 'Declaration', 'leitvia-studio' ) }
								</Button>
								{ ': ' }
								{ error.message }
							</li>
						) ) }
					</ul>
				</section>
			) }
		</Flex>
	);
}
