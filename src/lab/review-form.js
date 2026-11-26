// Approval of a registered pattern without a contract entry (child theme, theme or another plugin): a person allows
// agents to use it and may add its composition data, stored by Studio per pattern (Foreign_Patterns::save()).
import { __ } from '@wordpress/i18n';
import { useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	CheckboxControl,
	Flex,
	SelectControl,
} from '@wordpress/components';
import { settings } from './common';
import { notify } from '../admin/notify';

const KEYS = [
	[ 'role', __( 'Role', 'leitvia-studio' ) ],
	[ 'tone', __( 'Tone', 'leitvia-studio' ) ],
	[ 'layout', __( 'Layout', 'leitvia-studio' ) ],
	[ 'density', __( 'Density', 'leitvia-studio' ) ],
	[ 'weight', __( 'Weight', 'leitvia-studio' ) ],
	[ 'width', __( 'Width', 'leitvia-studio' ) ],
];

export default function ReviewForm( { item, onSaved } ) {
	const choices = settings.patternReview ?? { composition: {}, kinds: [] };
	const [ composition, setComposition ] = useState( {
		...( item.review?.composition ?? {} ),
	} );
	const [ provides, setProvides ] = useState( item.review?.provides ?? [] );
	const [ isSaving, setIsSaving ] = useState( false );
	const heading = useRef( null );
	const filled = KEYS.filter( ( [ key ] ) => composition[ key ] ).length;
	const partial = filled > 0 && filled < KEYS.length;

	const save = async ( status ) => {
		setIsSaving( true );
		try {
			await apiFetch( {
				path: '/leitvia-studio/v1/patterns/reviews',
				method: 'POST',
				data: {
					slug: item.ref.replace( /^pattern:/, '' ),
					status,
					composition: filled === KEYS.length ? composition : {},
					provides,
				},
			} );
			notify(
				'success',
				'approved' === status
					? __( 'Agents may use this pattern now.', 'leitvia-studio' )
					: __(
							'Agents may no longer use this pattern.',
							'leitvia-studio'
						)
			);
			if ( 'unreviewed' === status ) {
				// The withdraw button disappears; keep the focus in the form.
				heading.current?.focus();
			}
			onSaved();
		} catch ( error ) {
			notify(
				'error',
				error.message ??
					__( 'The review could not be saved.', 'leitvia-studio' )
			);
		} finally {
			setIsSaving( false );
		}
	};

	return (
		<Flex
			direction="column"
			justify="flex-start"
			gap={ 3 }
			align="stretch"
			className="leitvia-studio-review"
		>
			<h3 ref={ heading } tabIndex={ -1 }>
				{ __( 'Approval for agents', 'leitvia-studio' ) }
			</h3>
			<p>
				{ __(
					'This pattern comes from the theme, the child theme or another plugin and has no entry in the design contract. Agents put it on pages only after you approve it. Composition data lets the composition check know it; set all six values or none.',
					'leitvia-studio'
				) }
			</p>
			{ KEYS.map( ( [ key, label ] ) => (
				<SelectControl
					key={ key }
					id={ `leitvia-studio-review-${ key }` }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ label }
					value={ composition[ key ] ?? '' }
					options={ [
						{ value: '', label: __( 'Not set', 'leitvia-studio' ) },
						...( choices.composition[ key ] ?? [] ).map(
							( value ) => ( { value, label: value } )
						),
					] }
					onChange={ ( value ) =>
						setComposition( { ...composition, [ key ]: value } )
					}
				/>
			) ) }
			{ choices.kinds.length > 0 && (
				<fieldset>
					<legend>
						{ __( 'Section kinds it provides', 'leitvia-studio' ) }
					</legend>
					{ choices.kinds.map( ( kind ) => (
						<CheckboxControl
							key={ kind }
							__nextHasNoMarginBottom
							label={ kind }
							checked={ provides.includes( kind ) }
							onChange={ ( checked ) =>
								setProvides(
									checked
										? [ ...provides, kind ]
										: provides.filter(
												( entry ) => entry !== kind
											)
								)
							}
						/>
					) ) }
				</fieldset>
			) }
			<p role="status" id="leitvia-studio-review-hint">
				{ partial &&
					__(
						'Set all six composition values, or none.',
						'leitvia-studio'
					) }
			</p>
			<Flex justify="flex-start">
				<Button
					__next40pxDefaultSize
					variant="primary"
					isBusy={ isSaving }
					disabled={ isSaving || partial }
					accessibleWhenDisabled
					aria-describedby="leitvia-studio-review-hint"
					onClick={ () => save( 'approved' ) }
				>
					{ item.review
						? __( 'Save approval', 'leitvia-studio' )
						: __( 'Approve for agents', 'leitvia-studio' ) }
				</Button>
				{ item.review && (
					<Button
						__next40pxDefaultSize
						variant="secondary"
						disabled={ isSaving }
						accessibleWhenDisabled
						onClick={ () => save( 'unreviewed' ) }
					>
						{ __( 'Withdraw approval', 'leitvia-studio' ) }
					</Button>
				) }
			</Flex>
		</Flex>
	);
}
