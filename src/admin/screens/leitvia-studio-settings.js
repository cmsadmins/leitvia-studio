// Settings screen: the option leitvia_studio_settings on DataForm in three tabs (General, Privacy, Design and search),
// saved at once through /wp/v2/settings with one button. Each field has one plain sentence; what an expert or the
// privacy policy needs sits in a closed details block below the fields of its tab. The add-on list is on General.
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { DataForm, DataViews } from '@wordpress/dataviews/wp';
import { Button, Flex } from '@wordpress/components';
import { errorText } from '../common';
import Loading from '../loading';
import More from '../more';
import { notify } from '../notify';
import Tabs from '../tabs';
import { settings } from '../../lab/common';

export const title = __( 'Settings', 'leitvia-studio' );

export const intro = __(
	'Choose how Studio works on this site and which outside services the browsers of your visitors may contact.',
	'leitvia-studio'
);

// Product name from Admin::enqueue(), filter leitvia_studio_brand.
const BRAND = settings.brand ?? 'Leitvia Studio';
const OPTION = 'leitvia_studio_settings';

const FIELDS = [
	{
		id: 'hide_product_notes',
		label: __( 'Hide product notes on Studio screens', 'leitvia-studio' ),
		description: __(
			'Hides the notes for every user; each user can also dismiss them just for themselves.',
			'leitvia-studio'
		),
		type: 'boolean',
		Edit: 'toggle',
	},
	{
		id: 'delete_data_on_uninstall',
		label: __(
			'Delete all Studio data when the plugin is deleted',
			'leitvia-studio'
		),
		description: __(
			'Removes token sets, the content model and other Studio data together with the plugin; your pages stay.',
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
		description: __(
			'Off by default. When on, Gravatar, a service of Automattic Inc. in the USA, receives the IP address of every visitor, which under the GDPR usually needs their consent.',
			'leitvia-studio'
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
			'Videos and posts from sites such as YouTube load only after the visitor agrees.',
			'leitvia-studio'
		),
		type: 'boolean',
		Edit: 'toggle',
	},
	{
		id: 'emoji_script',
		label: __( 'Load the emoji script of WordPress', 'leitvia-studio' ),
		description: __(
			"Off by default. When on, older browsers load emoji images from WordPress.org, which receives the visitor's IP address.",
			'leitvia-studio'
		),
		type: 'boolean',
		Edit: 'toggle',
	},
	{
		id: 'map_tile_url',
		label: __( 'Map tile server', 'leitvia-studio' ),
		description: __(
			'Where the Map block loads its map images from; maps appear only after the visitor agrees.',
			'leitvia-studio'
		),
		type: 'text',
	},
	{
		id: 'guard_site_editor_contrast',
		label: __(
			'Block saving palettes that fail the contrast pairs in the Site Editor',
			'leitvia-studio'
		),
		description: __(
			'Stops saving colors in the Site Editor when text on them would be hard to read.',
			'leitvia-studio'
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
		description: __(
			'Helps search engines show the author and the date of your posts.',
			'leitvia-studio'
		),
		type: 'boolean',
		Edit: 'toggle',
	},
];

const formOf = ( ids ) => ( { layout: { type: 'regular' }, fields: ids } );

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
		<section aria-label={ __( 'Extensions', 'leitvia-studio' ) }>
			<p>
				{ __(
					'Add-ons that extend Studio, and whether this Studio version loads them.',
					'leitvia-studio'
				) }
			</p>
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

/**
 * Background of one setting in a details block: its name in bold, then the full text.
 *
 * @param {Object} props
 * @param {string} props.label Name of the setting.
 * @param {string} props.text  Text.
 */
function Background( { label, text } ) {
	return (
		<p>
			<strong>{ label }</strong> { text }
		</p>
	);
}

function General( { form } ) {
	return (
		<>
			{ form( [ 'hide_product_notes' ] ) }
			<ShowNotesAgain />
			{ form( [ 'delete_data_on_uninstall' ] ) }
			<More summary={ __( 'What is deleted', 'leitvia-studio' ) }>
				<p>
					{ __(
						'Also deletes token sets with their revisions, the Lab details of patterns, the usage index, the content model saved here and profile picture assignments. Studio settings are deleted in any case. Never deleted: patterns, global styles, profile pictures in the media library, content created with the content model and site details.',
						'leitvia-studio'
					) }
				</p>
			</More>
			<More summary={ __( 'Extensions', 'leitvia-studio' ) }>
				<Extensions />
			</More>
		</>
	);
}

function Privacy( { form } ) {
	return (
		<>
			{ form( [
				'allow_gravatar',
				'consent_embeds',
				'emoji_script',
				'map_tile_url',
			] ) }
			<More
				summary={ __(
					'Who receives which data, for your privacy policy',
					'leitvia-studio'
				) }
			>
				<Background
					label={ __( 'Gravatar:', 'leitvia-studio' ) }
					text={ sprintf(
						/* translators: %s: product name. */
						__(
							"When on, every visitor's browser loads profile pictures from Gravatar, a service of Automattic Inc. in the USA, for everyone without a local picture, including people who leave comments. Gravatar receives the visitor's IP address and a hash of the author's email address on every page view. Under the GDPR you need a legal basis for this, usually consent, and an entry in your privacy policy. %s does not ask visitors for consent. Local pictures from the Media Library are always used first.",
							'leitvia-studio'
						),
						BRAND
					) }
				/>
				<Background
					label={ __( 'Embedded content:', 'leitvia-studio' ) }
					text={ __(
						'Videos, posts and other embeds from providers such as YouTube or X show a placeholder until the visitor loads them. Nothing is requested from the provider before that. Visitors can always allow a provider and revoke it again with the Consent Settings block.',
						'leitvia-studio'
					) }
				/>
				<Background
					label={ __( 'Emoji script:', 'leitvia-studio' ) }
					text={ __(
						"When on, browsers without their own emoji font load emoji images from s.w.org, a server of WordPress.org, which receives the visitor's IP address. Feeds and emails then also link emoji images from there. Current browsers show emoji without it.",
						'leitvia-studio'
					) }
				/>
				<Background
					label={ __( 'Map tile server:', 'leitvia-studio' ) }
					text={ __(
						'Address of the map images for the Map block, starting with https:// and containing {z}, {x} and {y}. The default is the tile server of OpenStreetMap. Maps load only after the visitor agrees; check the terms of use and the privacy policy of the server you enter.',
						'leitvia-studio'
					) }
				/>
			</More>
		</>
	);
}

function DesignAndSearch( { form } ) {
	return (
		<>
			{ form( [ 'guard_site_editor_contrast', 'news_schema' ] ) }
			<More>
				<Background
					label={ __(
						'Contrast in the Site Editor:',
						'leitvia-studio'
					) }
					text={ __(
						'Uses the contrast pairs of the active theme. Token sets in Studio are always checked.',
						'leitvia-studio'
					) }
				/>
				<Background
					label={ __( 'Structured data:', 'leitvia-studio' ) }
					text={ sprintf(
						/* translators: %s: product name. */
						__(
							'Prints JSON-LD for search engines: the article with author and publisher on posts, the author profile on author pages. %s skips it while an SEO plugin is active, so that plugin stays the only source.',
							'leitvia-studio'
						),
						BRAND
					) }
				/>
			</More>
		</>
	);
}

const TABS = [
	{ name: 'general', title: __( 'General', 'leitvia-studio' ), Tab: General },
	{ name: 'privacy', title: __( 'Privacy', 'leitvia-studio' ), Tab: Privacy },
	{
		name: 'design',
		title: __( 'Design and search', 'leitvia-studio' ),
		Tab: DesignAndSearch,
	},
];

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

	// One option for all tabs: every tab edits the same data, so the button saves what any tab changed.
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
				errorText(
					error,
					__( 'The settings could not be saved.', 'leitvia-studio' )
				)
			);
		} finally {
			setIsSaving( false );
		}
	};

	const form = ( ids ) => (
		<DataForm
			data={ data }
			fields={ FIELDS }
			form={ formOf( ids ) }
			onChange={ ( edits ) =>
				setData( ( current ) => ( { ...current, ...edits } ) )
			}
		/>
	);

	if ( ! data ) {
		return <Loading />;
	}
	return (
		<form
			className="leitvia-studio-settings leitvia-studio-settings__form"
			onSubmit={ save }
		>
			<Flex
				direction="column"
				justify="flex-start"
				gap={ 6 }
				align="stretch"
			>
				<Tabs tabs={ TABS }>
					{ ( { Tab } ) => (
						<Flex
							direction="column"
							justify="flex-start"
							gap={ 6 }
							align="stretch"
						>
							<Tab form={ form } />
						</Flex>
					) }
				</Tabs>
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
			</Flex>
		</form>
	);
}
