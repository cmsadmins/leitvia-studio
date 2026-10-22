// Lab detail: preview per style variation and viewport, lab metadata and the registered Lab panels.
import { __, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import {
	Flex,
	SelectControl,
	TabPanel,
	// The segmented control core uses for viewport switches; it has no stable export yet.
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import Loading from '../admin/loading';
import PreviewFrame from './preview-frame';
import MetaForm from './meta-form';
import { VIEWPORTS, labUrl, settings, statusLabel } from './common';
import { notify } from '../admin/notify';

/**
 * Lab item of a reference and, for wp_block patterns, the post with its lab meta.
 *
 * @param {string} ref Pattern reference.
 */
function usePattern( ref ) {
	const [ state, setState ] = useState( {
		item: null,
		block: null,
		isLoading: true,
	} );
	const load = useCallback( async () => {
		const id = /^block:(\d+)$/.exec( ref )?.[ 1 ];
		try {
			const [ items, block ] = await Promise.all( [
				apiFetch( {
					path: addQueryArgs( '/leitvia-studio/v1/patterns', {
						search: ref,
						per_page: 100,
					} ),
				} ),
				id
					? apiFetch( { path: `/wp/v2/blocks/${ id }?context=edit` } )
					: null,
			] );
			// Variants are not in the pattern list; their header comes from the post.
			const item =
				items.find( ( entry ) => entry.ref === ref ) ??
				( block && {
					ref,
					title: block.title.raw,
					source: 'user',
					status: block.meta._leitvia_lab_status,
					effective_status: block.meta._leitvia_lab_status,
					checks: block.meta._leitvia_lab_checks,
					modified: `${ block.modified_gmt }Z`,
					edit_url: '',
				} );
			setState( { item, block, isLoading: false } );
		} catch ( error ) {
			setState( { item: null, block: null, isLoading: false } );
			notify(
				'error',
				error.message ??
					__( 'The pattern could not be loaded.', 'leitvia-studio' )
			);
		}
	}, [ ref ] );
	useEffect( () => {
		load();
	}, [ load ] );
	return { ...state, reload: load };
}

function Panels( { item, block, reload } ) {
	const components = window.leitviaStudio.getLabPanels();
	const tabs = settings.labPanels
		.filter( ( panel ) => components.has( panel.name ) )
		.map( ( panel ) => ( { name: panel.name, title: panel.label } ) );
	if ( ! tabs.length ) {
		return null;
	}
	return (
		<TabPanel className="leitvia-studio-lab-panels" tabs={ tabs }>
			{ ( tab ) => {
				const Panel = components.get( tab.name );
				return (
					<div className="leitvia-studio-lab-panels__content">
						<Panel
							pattern={ item }
							block={ block }
							onChange={ reload }
						/>
					</div>
				);
			} }
		</TabPanel>
	);
}

export default function Detail( { patternRef } ) {
	const { item, block, isLoading, reload } = usePattern( patternRef );
	const [ style, setStyle ] = useState( '' );
	const [ width, setWidth ] = useState( 1440 );
	const title = item?.title;

	useEffect( () => {
		if ( ! title ) {
			return;
		}
		const original = document.title;
		document.title = sprintf(
			/* translators: 1: pattern title, 2: title of the admin page. */
			__( '%1$s ‹ %2$s', 'leitvia-studio' ),
			title,
			original
		);
		return () => {
			document.title = original;
		};
	}, [ title ] );

	if ( isLoading ) {
		return <Loading />;
	}
	const back = (
		<a href={ labUrl() }>
			{ __( 'Back to all patterns', 'leitvia-studio' ) }
		</a>
	);
	if ( ! item ) {
		return (
			<Flex
				direction="column"
				justify="flex-start"
				gap={ 4 }
				align="flex-start"
			>
				<p>
					{ __(
						'This pattern does not exist or you may not view it.',
						'leitvia-studio'
					) }
				</p>
				{ back }
			</Flex>
		);
	}
	const styleTitle =
		settings.variations.find( ( variation ) => variation.slug === style )
			?.title ?? __( 'Default', 'leitvia-studio' );

	return (
		<Flex
			direction="column"
			justify="flex-start"
			gap={ 6 }
			align="stretch"
			className="leitvia-studio-detail"
		>
			<Flex
				direction="column"
				justify="flex-start"
				gap={ 2 }
				align="stretch"
			>
				{ back }
				<h2 className="leitvia-studio-detail__title">
					{ item.title || item.ref }
				</h2>
				<p className="leitvia-studio-detail__status">
					{ sprintf(
						/* translators: 1: own status, 2: effective status. */
						__(
							'Status: %1$s. Effective status: %2$s.',
							'leitvia-studio'
						),
						statusLabel( item.status ),
						statusLabel( item.effective_status )
					) }
				</p>
			</Flex>
			<div className="leitvia-studio-detail__layout">
				<Flex
					direction="column"
					justify="flex-start"
					gap={ 4 }
					align="stretch"
					className="leitvia-studio-detail__main"
				>
					<h3>{ __( 'Preview', 'leitvia-studio' ) }</h3>
					<Flex
						gap={ 4 }
						align="flex-end"
						justify="flex-start"
						wrap
						className="leitvia-studio-detail__controls"
					>
						<SelectControl
							id="leitvia-studio-style-variation"
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Style variation', 'leitvia-studio' ) }
							value={ style }
							options={ [
								{
									value: '',
									label: __( 'Default', 'leitvia-studio' ),
								},
								...settings.variations.map( ( variation ) => ( {
									value: variation.slug,
									label: variation.title,
								} ) ),
							] }
							onChange={ setStyle }
						/>
						<ToggleGroupControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							isBlock
							label={ __( 'Viewport', 'leitvia-studio' ) }
							value={ String( width ) }
							onChange={ ( value ) =>
								setWidth( Number( value ) )
							}
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
					</Flex>
					<PreviewFrame
						patternRef={ item.ref }
						style={ style }
						width={ width }
						title={ sprintf(
							/* translators: 1: pattern title, 2: style variation, 3: width in pixels. */
							__(
								'Preview of %1$s in %2$s at %3$d px',
								'leitvia-studio'
							),
							item.title,
							styleTitle,
							width
						) }
					/>
					<Panels item={ item } block={ block } reload={ reload } />
				</Flex>
				<Flex
					direction="column"
					justify="flex-start"
					gap={ 4 }
					align="stretch"
					className="leitvia-studio-detail__side"
				>
					<h3>{ __( 'Details', 'leitvia-studio' ) }</h3>
					<MetaForm
						item={ item }
						block={ block }
						onSaved={ reload }
					/>
				</Flex>
			</div>
		</Flex>
	);
}
