// Live contrast check of the color roles against the pairs of the contract, the same rules the server applies.
import { __, sprintf } from '@wordpress/i18n';
import { checkPalette } from '../lib/contrast.mjs';

// Ratios are rounded down like the server findings, so 4.496 never shows as 4.50.
const floor = ( ratio ) => Math.floor( ratio * 100 ) / 100;

/**
 * Every pair with its ratio and result. checkPalette() returns failing pairs only; with an unreachable minimum every
 * pair comes back with its ratio, and the result is judged here with the real minimum.
 *
 * @param {Array<{slug: string, color: string}>} palette Palette presets.
 * @param {Array<Object>}                        pairs   Contract pairs.
 * @return {Array<Object>} Pairs with ratio, ringRatio and result 'pass', 'fail' or 'unchecked'.
 */
export function contrastRows( palette, pairs ) {
	return checkPalette(
		palette,
		pairs.map( ( pair ) => ( { ...pair, min: Infinity } ) )
	).map( ( row, index ) => {
		const pair = pairs[ index ];
		let result = 'fail';
		if ( null === row.ratio ) {
			result = 'unchecked';
		} else if (
			row.ratio >= pair.min &&
			! ( undefined !== row.ringRatio && row.ringRatio < 3 )
		) {
			result = 'pass';
		}
		return { ...row, min: pair.min, result };
	} );
}

const RESULTS = {
	pass: __( 'Pass', 'leitvia-studio' ),
	fail: __( 'Fail', 'leitvia-studio' ),
	unchecked: __( 'Not checked', 'leitvia-studio' ),
};

/**
 * @param {Object}                   props
 * @param {Array}                    props.rows       Rows from contrastRows().
 * @param {(slug: string) => string} props.fieldId    ID of the input of a color role, for links from failing rows.
 * @param {string}                   props.labelledBy ID of the heading that names the table.
 */
export default function ContrastTable( { rows, fieldId, labelledBy } ) {
	return (
		<table
			className="widefat striped leitvia-studio-tokens__contrast"
			aria-labelledby={ labelledBy }
		>
			<thead>
				<tr>
					<th scope="col">{ __( 'Pair', 'leitvia-studio' ) }</th>
					<th scope="col">{ __( 'Ratio', 'leitvia-studio' ) }</th>
					<th scope="col">{ __( 'Minimum', 'leitvia-studio' ) }</th>
					<th scope="col">{ __( 'Result', 'leitvia-studio' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ rows.map( ( row ) => {
					const pair = row.ring
						? sprintf(
								/* translators: 1: foreground role, 2: background role, 3: inner focus ring role. */
								__(
									'%1$s on %2$s (inner ring %3$s)',
									'leitvia-studio'
								),
								row.fg,
								row.bg,
								row.ring
							)
						: sprintf(
								/* translators: 1: foreground role, 2: background role. */
								__( '%1$s on %2$s', 'leitvia-studio' ),
								row.fg,
								row.bg
							);
					return (
						<tr key={ `${ row.fg }/${ row.bg }` }>
							<th scope="row">
								{ 'fail' === row.result ? (
									<a href={ `#${ fieldId( row.fg ) }` }>
										{ pair }
									</a>
								) : (
									pair
								) }
							</th>
							<td>
								{ null === row.ratio
									? __( 'n/a', 'leitvia-studio' )
									: sprintf(
											/* translators: %s: contrast ratio. */
											__( '%s:1', 'leitvia-studio' ),
											floor( row.ratio ).toFixed( 2 )
										) }
							</td>
							<td>
								{ sprintf(
									/* translators: %s: required contrast ratio. */
									__( '%s:1', 'leitvia-studio' ),
									row.min
								) }
							</td>
							<td>{ RESULTS[ row.result ] }</td>
						</tr>
					);
				} ) }
			</tbody>
		</table>
	);
}
