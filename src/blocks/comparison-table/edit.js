import { __, sprintf } from '@wordpress/i18n';
import { useEffect } from '@wordpress/element';
import {
	BlockControls,
	InspectorControls,
	PlainText,
	useBlockProps,
} from '@wordpress/block-editor';
import {
	PanelBody,
	RangeControl,
	SelectControl,
	TextControl,
	ToolbarButton,
	ToolbarGroup,
} from '@wordpress/components';
import useSiteLocale from '../pricing/locale';
import { cells, DATE } from './save';

export default function Edit( { attributes, setAttributes } ) {
	const {
		caption,
		columns,
		rows,
		highlightRow,
		computed,
		source,
		sourceDate,
		labels,
	} = attributes;
	const locale = useSiteLocale();
	useEffect( () => {
		if ( undefined === labels ) {
			setAttributes( {
				labels: {
					source: __( 'Source:', 'leitvia-studio' ),
					asOf: __( 'as of', 'leitvia-studio' ),
				},
			} );
		}
		// The computed column formats its figures in the site language.
		if ( computed?.label && computed.locale !== locale ) {
			setAttributes( { computed: { ...computed, locale } } );
		}
	} );
	const { head, body } = cells( attributes );
	const setCell = ( r, c, value ) =>
		setAttributes( {
			rows: rows.map( ( row, i ) =>
				i === r
					? row.map( ( cell, j ) => ( j === c ? value : cell ) )
					: row
			),
		} );
	const setComputed = ( change ) =>
		setAttributes( {
			computed: {
				decimals: 2,
				unit: '',
				a: 1,
				b: 2,
				...computed,
				...change,
				locale,
			},
		} );
	const numbered = ( text, label, index ) =>
		text ||
		sprintf(
			/* translators: 1: "Column" or "Row", 2: its number. */
			__( '%1$s %2$d', 'leitvia-studio' ),
			label,
			index + 1
		);
	const columnOptions = columns.map( ( text, index ) => ( {
		value: index,
		label: numbered( text, __( 'Column', 'leitvia-studio' ), index ),
	} ) );
	const dateInvalid = '' !== sourceDate && ! DATE.test( sourceDate );
	return (
		<>
			<BlockControls>
				<ToolbarGroup>
					<ToolbarButton
						onClick={ () =>
							setAttributes( {
								rows: [ ...rows, columns.map( () => '' ) ],
							} )
						}
					>
						{ __( 'Add row', 'leitvia-studio' ) }
					</ToolbarButton>
					<ToolbarButton
						disabled={ rows.length < 2 }
						onClick={ () =>
							setAttributes( { rows: rows.slice( 0, -1 ) } )
						}
					>
						{ __( 'Remove last row', 'leitvia-studio' ) }
					</ToolbarButton>
					<ToolbarButton
						onClick={ () =>
							setAttributes( {
								columns: [ ...columns, '' ],
								rows: rows.map( ( row ) => [ ...row, '' ] ),
							} )
						}
					>
						{ __( 'Add column', 'leitvia-studio' ) }
					</ToolbarButton>
					<ToolbarButton
						disabled={ columns.length < 3 }
						onClick={ () =>
							setAttributes( {
								columns: columns.slice( 0, -1 ),
								rows: rows.map( ( row ) => row.slice( 0, -1 ) ),
							} )
						}
					>
						{ __( 'Remove last column', 'leitvia-studio' ) }
					</ToolbarButton>
				</ToolbarGroup>
			</BlockControls>
			<InspectorControls>
				<PanelBody title={ __( 'Table', 'leitvia-studio' ) }>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Caption', 'leitvia-studio' ) }
						help={ __(
							'Names the table for screen readers and as a scroll region.',
							'leitvia-studio'
						) }
						value={ caption }
						onChange={ ( value ) =>
							setAttributes( { caption: value } )
						}
					/>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Highlighted row', 'leitvia-studio' ) }
						value={ highlightRow }
						options={ [
							{
								value: -1,
								label: __( 'None', 'leitvia-studio' ),
							},
							...rows.map( ( row, index ) => ( {
								value: index,
								label: numbered(
									row[ 0 ],
									__( 'Row', 'leitvia-studio' ),
									index
								),
							} ) ),
						] }
						onChange={ ( value ) =>
							setAttributes( { highlightRow: Number( value ) } )
						}
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Computed column', 'leitvia-studio' ) }
					initialOpen={ false }
				>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Column name', 'leitvia-studio' ) }
						help={ __(
							'Leave empty for no computed column. Its values are saved as text.',
							'leitvia-studio'
						) }
						value={ computed?.label ?? '' }
						onChange={ ( value ) =>
							setComputed( { label: value } )
						}
					/>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Divide column', 'leitvia-studio' ) }
						value={ computed?.a ?? 1 }
						options={ columnOptions }
						onChange={ ( value ) =>
							setComputed( { a: Number( value ) } )
						}
					/>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'By column', 'leitvia-studio' ) }
						value={ computed?.b ?? 2 }
						options={ columnOptions }
						onChange={ ( value ) =>
							setComputed( { b: Number( value ) } )
						}
					/>
					<RangeControl
						__next40pxDefaultSize
						label={ __( 'Decimal places', 'leitvia-studio' ) }
						min={ 0 }
						max={ 4 }
						value={ computed?.decimals ?? 2 }
						onChange={ ( value ) =>
							setComputed( { decimals: value ?? 2 } )
						}
					/>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Unit', 'leitvia-studio' ) }
						value={ computed?.unit ?? '' }
						onChange={ ( value ) => setComputed( { unit: value } ) }
					/>
				</PanelBody>
				<PanelBody title={ __( 'Source', 'leitvia-studio' ) }>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Source', 'leitvia-studio' ) }
						value={ source }
						onChange={ ( value ) =>
							setAttributes( { source: value } )
						}
					/>
					<TextControl
						__next40pxDefaultSize
						type="date"
						label={ __( 'As of', 'leitvia-studio' ) }
						help={
							dateInvalid
								? __( 'Use YYYY-MM-DD.', 'leitvia-studio' )
								: undefined
						}
						value={ sourceDate }
						onChange={ ( value ) =>
							setAttributes( { sourceDate: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<figure { ...useBlockProps() }>
				<table>
					{ caption && <caption>{ caption }</caption> }
					<thead>
						<tr>
							{ head.map( ( text, c ) => (
								<th key={ c } scope="col">
									{ c < columns.length ? (
										<PlainText
											aria-label={ numbered(
												'',
												__(
													'Column name',
													'leitvia-studio'
												),
												c
											) }
											placeholder={ __(
												'Column',
												'leitvia-studio'
											) }
											value={ text }
											onChange={ ( value ) =>
												setAttributes( {
													columns: columns.map(
														( name, i ) =>
															i === c
																? value
																: name
													),
												} )
											}
										/>
									) : (
										text
									) }
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
											scope={
												0 === c ? 'row' : undefined
											}
										>
											{ c < columns.length ? (
												<PlainText
													aria-label={ numbered(
														'',
														head[ c ] ||
															__(
																'Cell',
																'leitvia-studio'
															),
														r
													) }
													value={ text }
													onChange={ ( value ) =>
														setCell( r, c, value )
													}
												/>
											) : (
												text
											) }
										</Cell>
									);
								} ) }
							</tr>
						) ) }
					</tbody>
				</table>
			</figure>
		</>
	);
}
