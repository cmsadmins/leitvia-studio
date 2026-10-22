// Site settings tab: the declared settings on DataForm, stored through /wp/v2/settings as leitvia_site_<key>.
import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { DataForm } from '@wordpress/dataviews/wp';
import { Button, Flex } from '@wordpress/components';
import { notify } from '../admin/notify';
import Loading from '../admin/loading';

const PREFIX = 'leitvia_site_';

// Declared field type: DataForm type and edit control.
const TYPES = {
	text: [ 'text' ],
	textarea: [ 'text', 'textarea' ],
	email: [ 'email' ],
	url: [ 'url' ],
	integer: [ 'integer' ],
	number: [ 'number' ],
	boolean: [ 'boolean', 'toggle' ],
	select: [ 'text' ],
	date: [ 'date' ],
	datetime: [ 'datetime' ],
	time: [ 'text', 'time' ],
	image: [ 'integer' ],
};

function toField( id, setting ) {
	const [ type, Edit ] = TYPES[ setting.type ] ?? [ 'text' ];
	return {
		id,
		label: setting.label,
		description: setting.description,
		type,
		Edit,
		elements: setting.options
			? Object.entries( setting.options ).map( ( [ value, label ] ) => ( {
					value,
					label,
				} ) )
			: undefined,
	};
}

const NONE = {};

export default function SettingsForm( { settings = NONE } ) {
	const keys = Object.keys( settings );
	const [ data, setData ] = useState( null );
	const [ stored, setStored ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	useEffect( () => {
		apiFetch( { path: '/wp/v2/settings' } )
			.then( ( all ) => {
				const values = Object.fromEntries(
					Object.keys( settings ).map( ( key ) => [
						key,
						all[ PREFIX + key ] ?? '',
					] )
				);
				setStored( values );
				setData( values );
			} )
			.catch( ( error ) => notify( 'error', error.message ) );
	}, [ settings ] );

	if ( ! keys.length ) {
		return (
			<p>
				{ __(
					'No site settings are declared. Add them under "settings" on the Declaration tab.',
					'leitvia-studio'
				) }
			</p>
		);
	}
	if ( ! data ) {
		return <Loading />;
	}

	const save = async () => {
		setBusy( true );
		try {
			// Only changed values; an emptied value removes the option. Core refuses null for options never stored.
			await apiFetch( {
				path: '/wp/v2/settings',
				method: 'POST',
				data: Object.fromEntries(
					keys
						.filter( ( key ) => data[ key ] !== stored[ key ] )
						.map( ( key ) => [
							PREFIX + key,
							'' === data[ key ] ? null : data[ key ],
						] )
				),
			} );
			setStored( data );
			notify( 'success', __( 'Site settings saved.', 'leitvia-studio' ) );
		} catch ( error ) {
			notify(
				'error',
				error.message ??
					__(
						'The site settings could not be saved.',
						'leitvia-studio'
					)
			);
		} finally {
			setBusy( false );
		}
	};

	return (
		<Flex
			direction="column"
			justify="flex-start"
			gap={ 4 }
			align="stretch"
			className="leitvia-studio-model__settings"
		>
			<DataForm
				data={ data }
				fields={ keys.map( ( key ) =>
					toField( key, settings[ key ] )
				) }
				form={ {
					layout: { type: 'regular', labelPosition: 'top' },
					fields: keys,
				} }
				onChange={ ( edits ) =>
					setData( ( current ) => ( { ...current, ...edits } ) )
				}
			/>
			<Flex justify="flex-start">
				<Button
					__next40pxDefaultSize
					variant="primary"
					onClick={ save }
					isBusy={ busy }
					disabled={ busy }
					accessibleWhenDisabled
				>
					{ __( 'Save settings', 'leitvia-studio' ) }
				</Button>
			</Flex>
		</Flex>
	);
}
