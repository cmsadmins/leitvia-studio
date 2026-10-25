// Recipe detail: source, status, the plan from the recipe file and the Apply button with a confirmation dialog. Applying
// runs the ability leitvia/apply-recipe through the Abilities API, the same path an agent takes: autonomy and change
// package decide what goes live. Progress and result go to the live region.
import { __, _n, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Button, Flex, Modal, Notice, Spinner } from '@wordpress/components';
import { notify } from '../notify';
import { inboxUrl } from '../inbox/common';
import { recipeTitle, recipesUrl, sourceLabel, statusLabel } from './common';

const RUN = '/wp-abilities/v1/abilities/leitvia/apply-recipe/run';

const STEPS = {
	contentModel: __( 'Content model', 'leitvia-studio' ),
	tokenSet: __( 'Token set', 'leitvia-studio' ),
	variation: __( 'Style variation', 'leitvia-studio' ),
	templateParts: __( 'Header and footer', 'leitvia-studio' ),
	pages: __( 'Page', 'leitvia-studio' ),
	navigation: __( 'Navigation', 'leitvia-studio' ),
	reading: __( 'Reading settings', 'leitvia-studio' ),
	connect: __( 'Page setting', 'leitvia-studio' ),
	options: __( 'Setting', 'leitvia-studio' ),
};

const STEP_STATUSES = {
	live: __( 'Live', 'leitvia-studio' ),
	draft: __( 'Draft', 'leitvia-studio' ),
	package: __( 'In the change package', 'leitvia-studio' ),
	unchanged: __( 'Unchanged', 'leitvia-studio' ),
	exists: __( 'Kept as it is', 'leitvia-studio' ),
	waiting: __( 'Waiting', 'leitvia-studio' ),
	unavailable: __( 'Not available', 'leitvia-studio' ),
	rejected: __( 'Rejected', 'leitvia-studio' ),
};

const CODES = {
	parent_missing: __(
		'The parent page is not on the site yet.',
		'leitvia-studio'
	),
	page_missing: __( 'The page is not on the site yet.', 'leitvia-studio' ),
	ability_missing: __(
		'This step is not available on this site.',
		'leitvia-studio'
	),
};

const targetText = ( target ) =>
	Object.values( target ?? {} )
		.filter( ( value ) => 'string' === typeof value && value )
		.join( ', ' );

function Breadcrumbs( { current } ) {
	return (
		<nav
			className="leitvia-studio-recipes__breadcrumbs"
			aria-label={ __( 'Breadcrumbs', 'leitvia-studio' ) }
		>
			<ol>
				<li>
					<a href={ recipesUrl() }>
						{ __( 'Recipes', 'leitvia-studio' ) }
					</a>
				</li>
				<li>
					<span aria-current="page">{ current }</span>
				</li>
			</ol>
		</nav>
	);
}

function Facts( { facts } ) {
	return (
		<dl className="leitvia-studio-recipes__facts">
			{ facts
				.filter( ( [ , value ] ) => value )
				.map( ( [ term, value ] ) => (
					<div key={ term }>
						<dt>{ term }</dt>
						<dd>{ value }</dd>
					</div>
				) ) }
		</dl>
	);
}

function Plan( { recipe } ) {
	const { plan } = recipe;
	if ( ! plan ) {
		return null;
	}
	const parts = plan.templateParts ?? {};
	const list = ( values ) => values.join( ', ' );
	return (
		<section aria-labelledby="leitvia-studio-recipes-plan">
			<h3 id="leitvia-studio-recipes-plan">
				{ __( 'Plan', 'leitvia-studio' ) }
			</h3>
			<p>
				{ __(
					'What the recipe sets up. Pages that exist, and header, footer, navigation or style changed since a recipe wrote them, are kept.',
					'leitvia-studio'
				) }
				{ 'core' !== recipe.source &&
					' ' +
						__(
							'The add-on can add further steps, such as sample content and images.',
							'leitvia-studio'
						) }
			</p>
			<Facts
				facts={ [
					[
						__( 'Content types', 'leitvia-studio' ),
						list( plan.contentModel ),
					],
					[ __( 'Token set', 'leitvia-studio' ), plan.tokenSet ],
					[
						__( 'Style variation', 'leitvia-studio' ),
						plan.variation,
					],
					[ __( 'Header', 'leitvia-studio' ), parts.header ],
					[ __( 'Footer', 'leitvia-studio' ), parts.footer ],
					[
						__( 'Navigation', 'leitvia-studio' ),
						list( plan.navigation ),
					],
					[
						__( 'Footer navigation', 'leitvia-studio' ),
						list( plan.footerNavigation ),
					],
					[
						__( 'Settings', 'leitvia-studio' ),
						list( plan.settings ),
					],
				] }
			/>
			<table className="widefat striped">
				<caption>{ __( 'Pages', 'leitvia-studio' ) }</caption>
				<thead>
					<tr>
						<th scope="col">{ __( 'Title', 'leitvia-studio' ) }</th>
						<th scope="col">{ __( 'Slug', 'leitvia-studio' ) }</th>
						<th scope="col">
							{ __( 'Parent page', 'leitvia-studio' ) }
						</th>
						<th scope="col">
							{ __( 'Sections', 'leitvia-studio' ) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ plan.pages.map( ( page ) => (
						<tr key={ page.slug }>
							<td>{ page.title }</td>
							<td>
								<code>{ page.slug }</code>
							</td>
							<td>{ page.parent }</td>
							<td>{ page.sections }</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</section>
	);
}

function Result( { result } ) {
	return (
		<section aria-labelledby="leitvia-studio-recipes-result">
			<h3 id="leitvia-studio-recipes-result">
				{ __( 'Result', 'leitvia-studio' ) }
			</h3>
			{ result.package > 0 && (
				<p>
					<a href={ inboxUrl( result.package ) }>
						{ __(
							'Review the change package in the inbox',
							'leitvia-studio'
						) }
					</a>
				</p>
			) }
			<table className="widefat striped">
				<caption>{ __( 'Steps', 'leitvia-studio' ) }</caption>
				<thead>
					<tr>
						<th scope="col">{ __( 'Step', 'leitvia-studio' ) }</th>
						<th scope="col">
							{ __( 'Target', 'leitvia-studio' ) }
						</th>
						<th scope="col">
							{ __( 'Status', 'leitvia-studio' ) }
						</th>
						<th scope="col">{ __( 'Note', 'leitvia-studio' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ result.steps.map( ( step, index ) => (
						<tr key={ index }>
							<td>{ STEPS[ step.step ] ?? step.step }</td>
							<td>{ targetText( step.target ) }</td>
							<td>
								{ STEP_STATUSES[ step.status ] ?? step.status }
							</td>
							<td>
								{ step.message ||
									CODES[ step.code ] ||
									step.code }
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</section>
	);
}

/**
 * Sentence on a finished run: how many steps went live and how many wait in the change package.
 *
 * @param {Object} result Answer of leitvia/apply-recipe.
 * @return {string} Text.
 */
function summary( result ) {
	const count = ( statuses ) =>
		result.steps.filter( ( step ) => statuses.includes( step.status ) )
			.length;
	const live = count( [ 'live', 'draft' ] );
	const waiting = count( [ 'package' ] );
	const parts = [
		sprintf(
			/* translators: %d: number of steps. */
			_n(
				'Recipe applied: %d step written.',
				'Recipe applied: %d steps written.',
				live,
				'leitvia-studio'
			),
			live
		),
	];
	if ( waiting ) {
		parts.push(
			sprintf(
				/* translators: %d: number of steps. */
				_n(
					'%d step waits in the change package.',
					'%d steps wait in the change package.',
					waiting,
					'leitvia-studio'
				),
				waiting
			)
		);
	}
	return parts.join( ' ' );
}

export default function RecipeDetail( { recipe } ) {
	const [ confirming, setConfirming ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ result, setResult ] = useState( null );

	if ( ! recipe ) {
		return (
			<div className="leitvia-studio-recipes">
				<Breadcrumbs
					current={ __( 'Unknown recipe', 'leitvia-studio' ) }
				/>
				<p>
					{ __(
						'There is no recipe with this name.',
						'leitvia-studio'
					) }
				</p>
			</div>
		);
	}

	const title = recipeTitle( recipe );
	const apply = async () => {
		setConfirming( false );
		setBusy( true );
		setResult( null );
		try {
			const answer = await apiFetch( {
				path: RUN,
				method: 'POST',
				data: { input: { recipe: recipe.name } },
			} );
			setResult( answer );
			notify(
				answer.steps.some( ( step ) => 'rejected' === step.status )
					? 'error'
					: 'success',
				summary( answer )
			);
		} catch ( error ) {
			notify(
				'error',
				error.message ??
					__( 'The recipe could not be applied.', 'leitvia-studio' )
			);
		} finally {
			setBusy( false );
		}
	};

	return (
		<div className="leitvia-studio-recipes">
			<Breadcrumbs current={ title } />
			<h2>{ title }</h2>
			<p>{ recipe.description }</p>
			<Facts
				facts={ [
					[ __( 'Source', 'leitvia-studio' ), sourceLabel( recipe ) ],
					[ __( 'Status', 'leitvia-studio' ), statusLabel( recipe ) ],
					[
						__( 'Requires', 'leitvia-studio' ),
						recipe.requires.join( ', ' ),
					],
				] }
			/>
			{ ! recipe.available && (
				<Notice status="warning" isDismissible={ false }>
					{ recipe.reason }
				</Notice>
			) }
			<Plan recipe={ recipe } />
			<p>
				<Button
					__next40pxDefaultSize
					variant="primary"
					isBusy={ busy }
					disabled={ busy || ! recipe.available }
					accessibleWhenDisabled
					onClick={ () => setConfirming( true ) }
				>
					{ __( 'Apply recipe', 'leitvia-studio' ) }
				</Button>
			</p>
			<div role="status">
				{ busy && (
					<p>
						<Spinner />
						{ __( 'Applying the recipe.', 'leitvia-studio' ) }
					</p>
				) }
			</div>
			{ result && <Result result={ result } /> }
			{ confirming && (
				<Modal
					title={ sprintf(
						/* translators: %s: recipe title. */
						__( 'Apply the recipe %s?', 'leitvia-studio' ),
						title
					) }
					onRequestClose={ () => setConfirming( false ) }
					size="medium"
				>
					<p>
						{ __(
							'Each part runs with the autonomy level of its area. What may not go live waits in one change package in the inbox. Pages that exist are kept, and the recipe can run again.',
							'leitvia-studio'
						) }
					</p>
					<Flex justify="flex-end">
						<Button
							__next40pxDefaultSize
							variant="tertiary"
							onClick={ () => setConfirming( false ) }
						>
							{ __( 'Cancel', 'leitvia-studio' ) }
						</Button>
						<Button
							__next40pxDefaultSize
							variant="primary"
							onClick={ apply }
						>
							{ __( 'Apply', 'leitvia-studio' ) }
						</Button>
					</Flex>
				</Modal>
			) }
		</div>
	);
}
