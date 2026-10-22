import { useBlockProps } from '@wordpress/block-editor';
import { ratio } from '../pricing/savings.mjs';

// A valid datetime value for the source date: a full date.
export const DATE = /^\d{4}-\d{2}-\d{2}$/;

/**
 * Header and body cells with the computed column appended. `computed` holds the column indexes a and b, the label,
 * decimals, unit and the site locale; without a label there is no computed column.
 *
 * @param {Object}     attributes          Block attributes.
 * @param {string[]}   attributes.columns  Column names.
 * @param {string[][]} attributes.rows     Cell texts per row.
 * @param {Object}     attributes.computed Computed column settings.
 * @return {{head: string[], body: string[][]}} Cell texts.
 */
export function cells( { columns, rows, computed } ) {
	if ( ! computed?.label ) {
		return { head: columns, body: rows };
	}
	return {
		head: [ ...columns, computed.label ],
		body: rows.map( ( row ) => [
			...row,
			ratio( row[ computed.a ] ?? '', row[ computed.b ] ?? '', computed ),
		] ),
	};
}

// Each cell repeats its column name as visually hidden text, which the card view on small screens shows and keeps in
// the accessibility tree even when a browser drops the table semantics of cells with display: block.
const Label = ( { text } ) =>
	text ? (
		<span className="wp-block-leitvia-comparison-table__label">
			{ text }:{ ' ' }
		</span>
	) : null;

export default function save( { attributes } ) {
	const { caption, highlightRow, source, sourceDate, labels } = attributes;
	// Set by the editor in the site editor's language when the block is inserted.
	const { source: prefix = 'Source:', asOf = 'as of' } = labels ?? {};
	const { head, body } = cells( attributes );
	return (
		<figure
			{ ...useBlockProps.save() }
			tabIndex="0"
			role={ caption ? 'region' : undefined }
			aria-label={ caption || undefined }
		>
			<table>
				{ caption && <caption>{ caption }</caption> }
				<thead>
					<tr>
						{ head.map( ( text, index ) => (
							<th key={ index } scope="col">
								{ text }
							</th>
						) ) }
					</tr>
				</thead>
				<tbody>
					{ body.map( ( row, r ) => (
						<tr
							key={ r }
							className={
								r === highlightRow
									? 'is-highlighted'
									: undefined
							}
						>
							{ row.map( ( text, c ) => {
								const Cell = 0 === c ? 'th' : 'td';
								return (
									<Cell
										key={ c }
										scope={ 0 === c ? 'row' : undefined }
										data-label={ head[ c ] || undefined }
									>
										<Label text={ head[ c ] } />
										{ text }
									</Cell>
								);
							} ) }
						</tr>
					) ) }
				</tbody>
			</table>
			{ source && (
				<figcaption className="wp-block-leitvia-comparison-table__source">
					{ `${ prefix } ${ source }` }
					{ DATE.test( sourceDate ) && (
						<>
							{ `, ${ asOf } ` }
							<time dateTime={ sourceDate }>{ sourceDate }</time>
						</>
					) }
				</figcaption>
			) }
		</figure>
	);
}
