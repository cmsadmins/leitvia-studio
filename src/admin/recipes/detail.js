// Recipe detail: source, status, the plan from the recipe file and the Apply button with a confirmation dialog. The
// dialog names what applying replaces on this site, from a dry run of the recipe. Applying runs the ability
// leitvia/apply-recipe through the Abilities API, the same path an agent takes; for a person the change package is
// applied at once (input settle) and can be rolled back in the Inbox (answer field applied); a package applied in part
// (answer field partial) is listed with its errors. Progress and result go to the live region. Steps and codes of
// add-ons are named by the filter
// leitvia_studio_recipe_step_labels (window.leitviaStudioAdmin.recipeLabels).
import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { Button, Notice, Spinner } from '@wordpress/components';
import Breadcrumbs from '../breadcrumbs';
import { errorText } from '../common';
import ConfirmDialog from '../confirm-dialog';
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
	blockSettings: __( 'Site setting', 'leitvia-studio' ),
	blockEntries: __( 'Sample entry', 'leitvia-studio' ),
};

const STEP_STATUSES = {
	live: __( 'Live', 'leitvia-studio' ),
	draft: __( 'Draft', 'leitvia-studio' ),
	package: __( 'Waits in the Inbox', 'leitvia-studio' ),
	unchanged: __( 'Unchanged', 'leitvia-studio' ),
	exists: __( 'Kept as it is', 'leitvia-studio' ),
	waiting: __( 'Waiting', 'leitvia-studio' ),
	unavailable: __( 'Not available', 'leitvia-studio' ),
	rejected: __( 'Rejected', 'leitvia-studio' ),
	new: __( 'New', 'leitvia-studio' ),
	replace: __( 'Replaced', 'leitvia-studio' ),
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
	model_pending: __(
		'Waits until the content types are set up.',
		'leitvia-studio'
	),
	relation_pending: __( 'Waits for an entry it links to.', 'leitvia-studio' ),
	terms_pending: __(
		'Waits for its terms, which a person who may manage them adds.',
		'leitvia-studio'
	),
	building_block_missing: __(
		'The building block is not available on this site.',
		'leitvia-studio'
	),
};

// What a dry run reports as replaced, by part of the recipe.
const REPLACED = {
	design: __( 'Design: colors and fonts', 'leitvia-studio' ),
	header: __( 'Header', 'leitvia-studio' ),
	footer: __( 'Footer', 'leitvia-studio' ),
	navigation: __( 'Navigation', 'leitvia-studio' ),
	frontPage: __( 'Front page', 'leitvia-studio' ),
	options: __( 'Comment and privacy settings', 'leitvia-studio' ),
};

const ADDON_LABELS = window.leitviaStudioAdmin?.recipeLabels ?? {};

const stepLabel = ( name ) =>
	STEPS[ name ] ?? ADDON_LABELS.steps?.[ name ] ?? name;

const codeLabel = ( code ) =>
	CODES[ code ] ?? ADDON_LABELS.codes?.[ code ] ?? code;

const targetText = ( target ) =>
	Object.values( target ?? {} )
		.filter( ( value ) => 'string' === typeof value && value )
		.join( ', ' );

const RECIPES = [ __( 'Recipes', 'leitvia-studio' ), recipesUrl() ];

function Facts( { facts } ) {
	return (
		<dl className="leitvia-studio-facts">
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
			{ result.applied?.length > 0 && (
				<p>
					<a href={ inboxUrl( result.applied.at( -1 ) ) }>
						{ __(
							'Roll the changes back in the Inbox',
							'leitvia-studio'
						) }
					</a>
				</p>
			) }
			{ result.package > 0 && (
				<p>
					<a href={ inboxUrl( result.package ) }>
						{ __(
							'Review the changes in the Inbox',
							'leitvia-studio'
						) }
					</a>
				</p>
			) }
			{ result.partial?.map( ( { package: id, errors } ) => (
				<div key={ id }>
					<p>
						{ __(
							'Some changes could not be applied. They wait in the Inbox:',
							'leitvia-studio'
						) }{ ' ' }
						<a href={ inboxUrl( id ) }>
							{ __(
								'Review the rest in the Inbox',
								'leitvia-studio'
							) }
						</a>
					</p>
					<ul>
						{ errors.map( ( error, index ) => (
							<li key={ index }>
								{ error.message || codeLabel( error.code ) }
							</li>
						) ) }
					</ul>
				</div>
			) ) }
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
							<td>{ stepLabel( step.step ) }</td>
							<td>{ targetText( step.target ) }</td>
							<td>
								{ STEP_STATUSES[ step.status ] ?? step.status }
							</td>
							<td>{ step.message || codeLabel( step.code ) }</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</section>
	);
}

/**
 * Sentence on a finished run: how many steps went live and how many wait in the change package of the Inbox.
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
					'%d step waits in the Inbox.',
					'%d steps wait in the Inbox.',
					waiting,
					'leitvia-studio'
				),
				waiting
			)
		);
	}
	return parts.join( ' ' );
}

/**
 * What applying the recipe replaces on this site, from its dry run: a list of parts, or that nothing is replaced.
 *
 * @param {Object}        props
 * @param {Object|null}   props.preview  Answer of the dry run, null while it loads.
 * @param {string}        props.error    Message when the dry run failed.
 * @param {Array<string>} props.replaces Parts the dry run replaces.
 */
function Replaces( { preview, error, replaces } ) {
	if ( error ) {
		return (
			<p>
				{ __(
					'It could not be checked what the recipe replaces. Applying it can replace the design, header, footer, navigation and front page.',
					'leitvia-studio'
				) }
			</p>
		);
	}
	if ( ! preview ) {
		return (
			<p>
				<Spinner />
				{ __(
					'Checking what the recipe changes on this site.',
					'leitvia-studio'
				) }
			</p>
		);
	}
	if ( ! replaces.length ) {
		return (
			<p>
				{ __(
					'Nothing on the site is replaced. The recipe adds what is missing.',
					'leitvia-studio'
				) }
			</p>
		);
	}
	return (
		<>
			<p>
				{ __(
					'Applying the recipe replaces these parts of your site:',
					'leitvia-studio'
				) }
			</p>
			<ul>
				{ replaces.map( ( part ) => (
					<li key={ part }>{ REPLACED[ part ] ?? part }</li>
				) ) }
			</ul>
		</>
	);
}

export default function RecipeDetail( { recipe } ) {
	const [ confirming, setConfirming ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ result, setResult ] = useState( null );
	const [ preview, setPreview ] = useState( null );
	const [ previewError, setPreviewError ] = useState( '' );

	// Each time the dialog opens, the dry run says what applying replaces now.
	useEffect( () => {
		if ( ! confirming || ! recipe ) {
			return;
		}
		let current = true;
		apiFetch( {
			path: addQueryArgs( '/leitvia-studio/v1/recipes', {
				recipe: recipe.name,
				dryRun: true,
			} ),
		} )
			.then( ( items ) => {
				if ( current ) {
					setPreview( items?.[ 0 ]?.dryRun ?? null );
					setPreviewError( items?.[ 0 ]?.dryRun ? '' : 'missing' );
				}
			} )
			.catch( ( error ) => {
				if ( current ) {
					setPreviewError( errorText( error, 'failed' ) );
				}
			} );
		return () => {
			current = false;
		};
	}, [ confirming, recipe ] );

	if ( ! recipe ) {
		return (
			<div className="leitvia-studio-recipes">
				<Breadcrumbs
					parent={ RECIPES }
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
				data: { input: { recipe: recipe.name, settle: true } },
			} );
			setResult( answer );
			notify(
				answer.partial?.length ||
					answer.steps.some( ( step ) => 'rejected' === step.status )
					? 'error'
					: 'success',
				summary( answer )
			);
		} catch ( error ) {
			notify(
				'error',
				errorText(
					error,
					__( 'The recipe could not be applied.', 'leitvia-studio' )
				)
			);
		} finally {
			setBusy( false );
		}
	};

	return (
		<div className="leitvia-studio-recipes">
			<Breadcrumbs parent={ RECIPES } current={ title } />
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
					onClick={ () => {
						// Cleared with the opening render, so the dialog never shows the last dry run as current.
						setPreview( null );
						setPreviewError( '' );
						setConfirming( true );
					} }
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
				<ConfirmDialog
					title={ sprintf(
						/* translators: %s: recipe title. */
						__( 'Apply the recipe %s?', 'leitvia-studio' ),
						title
					) }
					confirmLabel={ __( 'Apply', 'leitvia-studio' ) }
					onCancel={ () => setConfirming( false ) }
					onConfirm={ apply }
					isBusy={ ! preview && ! previewError }
				>
					<div aria-live="polite">
						<Replaces
							preview={ preview }
							error={ previewError }
							replaces={ preview?.replaces ?? [] }
						/>
					</div>
					<p>
						{ __(
							'The recipe applies at once; you can roll it back in the Inbox. Existing pages are kept, and the recipe can run again.',
							'leitvia-studio'
						) }
					</p>
				</ConfirmDialog>
			) }
		</div>
	);
}
