// Settings screen: the option leitvia_studio_settings on DataForm, saved through /wp/v2/settings, and the add-on list.
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { DataForm, DataViews } from '@wordpress/dataviews/wp';
import { Button, Flex, Spinner } from '@wordpress/components';
import { notify } from '../notify';
import { settings } from '../../lab/common';

export const title = __( 'Settings', 'leitvia-studio' );

// Product name from Admin::enqueue(), filter leitvia_studio_brand.
const BRAND = settings.brand ?? 'Leitvia Studio';
const OPTION = 'leitvia_studio_settings';

const FIELDS = [
	{
		id: 'hide_product_notes',
		label: __( 'Hide product notes on Studio screens', 'leitvia-studio' ),
		description: __(
			'Applies to every user. Each user can also dismiss the notes for themselves and show them again below.',
			'leitvia-studio'
		),
		type: 'boolean',
		Edit: 'toggle',
	},
	{
		id: 'guard_site_editor_contrast',
		label: __(
			'Block saving palettes that fail the contrast pairs in the Site Editor',
			'leitvia-studio'
		),
		description: __(
			'Uses the contrast pairs of the active theme. Token sets in Studio are always checked.',
			'leitvia-studio'
		),
		type: 'boolean',
		Edit: 'toggle',
	},
	{
		id: 'allow_gravatar',
		label: __(
			'Allow Gravatar for users and commenters without a local picture',
			'leitvia-studio'
		),
		description: sprintf(
			/* translators: %s: product name. */
			__(
				"Off by default. When on, every visitor's browser loads profile pictures from Gravatar, a service of Automattic Inc. in the USA, for everyone without a local picture, including people who leave comments. Gravatar receives the visitor's IP address and a hash of the author's email address on every page view. Under the GDPR you need a legal basis for this, usually consent, and an entry in your privacy policy. %s does not ask visitors for consent. Local pictures from the Media Library are always used first.",
				'leitvia-studio'
			),
			BRAND
		),
		type: 'boolean',
		Edit: 'toggle',
	},
	{
		id: 'news_schema',
		label: __(
			'Add structured data to posts and author pages',
			'leitvia-studio'
		),
		description: sprintf(
			/* translators: %s: product name. */
			__(
				'Prints JSON-LD for search engines: the article with author and publisher on posts, the author profile on author pages. %s skips it while an SEO plugin is active, so that plugin stays the only source.',
				'leitvia-studio'
			),
			BRAND
		),
		type: 'boolean',
		Edit: 'toggle',
	},
	{
		id: 'consent_embeds',
		label: __(
			'Ask visitors before loading embedded content from other sites',
			'leitvia-studio'
		),
		description: __(
			'Videos, posts and other embeds from providers such as YouTube or X show a placeholder until the visitor loads them. Nothing is requested from the provider before that. Visitors can always allow a provider and revoke it again with the Consent Settings block.',
			'leitvia-studio'
		),
		type: 'boolean',
		Edit: 'toggle',
	},
	{
		id: 'emoji_script',
		label: __( 'Load the emoji script of WordPress', 'leitvia-studio' ),
		description: __(
			"Off by default. When on, browsers without their own emoji font load emoji images from s.w.org, a server of WordPress.org, which receives the visitor's IP address. Feeds and emails then also link emoji images from there. Current browsers show emoji without it.",
			'leitvia-studio'
		),
		type: 'boolean',
		Edit: 'toggle',
	},
	{
		id: 'map_tile_url',
		label: __( 'Map tile server', 'leitvia-studio' ),
		description: __(
			'Address of the map images for the Map block, starting with https:// and containing {z}, {x} and {y}. The default is the tile server of OpenStreetMap. Maps load only after the visitor agrees; check the terms of use and the privacy policy of the server you enter.',
			'leitvia-studio'
		),
		type: 'text',
	},
	{
		id: 'delete_data_on_uninstall',
		label: __(
			'Delete all Studio data when the plugin is deleted',
			'leitvia-studio'
		),
		description: __(
			'Also deletes token sets with their revisions, the Lab details of patterns, the usage index, the content model saved here and profile picture assignments. Studio settings are deleted in any case. Never deleted: patterns, global styles, profile pictures in the media library, content created with the content model and site settings.',
			'leitvia-studio'
		),
		type: 'boolean',
		Edit: 'toggle',
	},
];

const FORM = {
	layout: { type: 'regular' },
	fields: FIELDS.map( ( field ) => field.id ),
};

const EXTENSION_FIELDS = [
	{
		id: 'label',
		label: __( 'Name', 'leitvia-studio' ),
		enableHiding: false,
	},
	{ id: 'version', label: __( 'Version', 'leitvia-studio' ) },
	{
		id: 'requires_studio',
		label: __( 'Requires Studio', 'leitvia-studio' ),
	},
	{
		id: 'status',
		label: __( 'Status', 'leitvia-studio' ),
		getValue: ( { item } ) => {
			if ( item.compatible ) {
				return __( 'Active', 'leitvia-studio' );
			}
			const reason =
				'requires_api' === item.reason
					? __(
							'needs a different Extension API version',
							'leitvia-studio'
						)
					: __( 'needs a newer Studio version', 'leitvia-studio' );
			/* translators: %s: reason why the add-on is not loaded. */
			return sprintf( __( 'Not loaded: %s', 'leitvia-studio' ), reason );
		},
	},
];

const EXTENSION_VIEW = {
	type: 'table',
	page: 1,
	perPage: 50,
	titleField: 'label',
	fields: [ 'version', 'requires_studio', 'status' ],
	layout: {},
};

function Extensions() {
	const [ items, setItems ] = useState( null );
	const [ view, setView ] = useState( EXTENSION_VIEW );

	useEffect( () => {
		apiFetch( { path: '/leitvia-studio/v1/extensions' } )
			.then( ( response ) => setItems( response.extensions ) )
			.catch( () => setItems( [] ) );
	}, [] );

	return (
		<section aria-labelledby="leitvia-studio-extensions">
			<h2 id="leitvia-studio-extensions">
				{ __( 'Extensions', 'leitvia-studio' ) }
			</h2>
			<DataViews
				data={ items ?? [] }
				fields={ EXTENSION_FIELDS }
				view={ view }
				onChangeView={ setView }
				getItemId={ ( item ) => item.slug }
				isLoading={ null === items }
				paginationInfo={ {
					totalItems: items?.length ?? 0,
					totalPages: 1,
				} }
				defaultLayouts={ { table: {} } }
				search={ false }
			>
				<DataViews.Layout />
			</DataViews>
		</section>
	);
}

// Dismissing is per user (Admin::render_slot()); this brings the notes back for the current user.
function ShowNotesAgain() {
	const [ dismissed, setDismissed ] = useState(
		Boolean( settings.notesDismissed )
	);
	if ( ! dismissed ) {
		return null;
	}
	const show = () =>
		apiFetch( {
			path: '/leitvia-studio/v1/notes/dismiss',
			method: 'DELETE',
		} )
			.then( () => {
				setDismissed( false );
				notify(
					'success',
					__(
						'Product notes are shown again on Studio screens.',
						'leitvia-studio'
					)
				);
			} )
			.catch( () =>
				notify(
					'error',
					__(
						'The product notes could not be shown again.',
						'leitvia-studio'
					)
				)
			);
	return (
		<Flex justify="flex-start">
			<Button __next40pxDefaultSize variant="secondary" onClick={ show }>
				{ __( 'Show product notes again', 'leitvia-studio' ) }
			</Button>
		</Flex>
	);
}

export default function Settings() {
	const [ data, setData ] = useState( null );
	const [ isSaving, setIsSaving ] = useState( false );

	useEffect( () => {
		apiFetch( { path: `/wp/v2/settings?_fields=${ OPTION }` } )
			.then( ( response ) => setData( response[ OPTION ] ) )
			.catch( () =>
				notify(
					'error',
					__( 'The settings could not be loaded.', 'leitvia-studio' )
				)
			);
	}, [] );

	const save = async ( event ) => {
		event.preventDefault();
		setIsSaving( true );
		try {
			const response = await apiFetch( {
				path: '/wp/v2/settings',
				method: 'POST',
				data: { [ OPTION ]: data },
			} );
			setData( response[ OPTION ] );
			notify( 'success', __( 'Settings saved.', 'leitvia-studio' ) );
		} catch ( error ) {
			notify(
				'error',
				error.message ??
					__( 'The settings could not be saved.', 'leitvia-studio' )
			);
		} finally {
			setIsSaving( false );
		}
	};

	return (
		<Flex
			direction="column"
			justify="flex-start"
			gap={ 8 }
			align="stretch"
			className="leitvia-studio-settings"
		>
			<form className="leitvia-studio-settings__form" onSubmit={ save }>
				<h2>{ __( 'General', 'leitvia-studio' ) }</h2>
				{ data ? (
					<Flex
						direction="column"
						justify="flex-start"
						gap={ 6 }
						align="stretch"
					>
						<DataForm
							data={ data }
							fields={ FIELDS }
							form={ FORM }
							onChange={ ( edits ) =>
								setData( ( current ) => ( {
									...current,
									...edits,
								} ) )
							}
						/>
						<Flex justify="flex-start">
							<Button
								__next40pxDefaultSize
								type="submit"
								variant="primary"
								isBusy={ isSaving }
								disabled={ isSaving }
								accessibleWhenDisabled
							>
								{ __( 'Save settings', 'leitvia-studio' ) }
							</Button>
						</Flex>
						<ShowNotesAgain />
					</Flex>
				) : (
					<Spinner />
				) }
			</form>
			<Extensions />
		</Flex>
	);
}
