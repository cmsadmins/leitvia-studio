// Agents screen: agent users with their application passwords (shown once), revoking access, the state of the MCP
// Adapter, and the autonomy settings of the gateway, saved through /leitvia-studio/v1/gateway/settings.
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { useCopyToClipboard } from '@wordpress/compose';
import { DataForm } from '@wordpress/dataviews/wp';
import {
	Button,
	CheckboxControl,
	Flex,
	Modal,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	VisuallyHidden,
} from '@wordpress/components';
import { notify } from '../notify';
import { AREAS, KINDS, LEVELS, formatDate } from '../inbox/common';

const ACCESS = '/leitvia-studio/v1/gateway/access';
const SETTINGS = '/leitvia-studio/v1/gateway/settings';

const errorText = ( error, fallback ) => error?.message ?? fallback;

// Product name and agent role from Admin::enqueue(), filter leitvia_studio_brand.
const BRAND = window.leitviaStudioAdmin?.brand ?? 'Leitvia Studio';
const AGENT_ROLE = window.leitviaStudioAdmin?.agentRole ?? 'Leitvia Agent';

function McpNotice( { mcp } ) {
	let text;
	if ( ! mcp.active ) {
		text = sprintf(
			/* translators: %s: product name. */
			__(
				'The MCP Adapter is not active. Agents can use the abilities over the REST API. For MCP clients, install the MCP Adapter plugin yourself; %s does not install plugins.',
				'leitvia-studio'
			),
			BRAND
		);
	} else if ( ! mcp.compatible ) {
		text = sprintf(
			/* translators: 1: installed version, 2: product name, 3: required version. */
			__(
				'The MCP Adapter %1$s is active, but %2$s needs version %3$s. Update or install the matching version.',
				'leitvia-studio'
			),
			mcp.version,
			BRAND,
			mcp.required
		);
	} else {
		text = sprintf(
			/* translators: 1: installed version, 2: product name. */
			__(
				'The MCP Adapter %1$s is active and matches this version of %2$s.',
				'leitvia-studio'
			),
			mcp.version,
			BRAND
		);
	}
	return (
		<Notice
			status={ mcp.compatible ? 'success' : 'info' }
			isDismissible={ false }
		>
			<p>{ text }</p>
			<p>
				<a href={ mcp.url }>
					{ __( 'MCP Adapter on WordPress.org', 'leitvia-studio' ) }
				</a>
			</p>
		</Notice>
	);
}

// The plain text of a new application password, shown once; the server keeps only its hash.
function OneTimePassword( { secret, onDone } ) {
	const heading = useRef();
	const [ copied, setCopied ] = useState( false );
	const copy = useCopyToClipboard( secret.password, () => {
		setCopied( true );
		notify( 'success', __( 'Password copied.', 'leitvia-studio' ) );
	} );
	useEffect( () => heading.current?.focus(), [] );
	return (
		<section
			className="leitvia-studio-agents__secret"
			aria-labelledby="leitvia-studio-secret"
		>
			<h3 id="leitvia-studio-secret" tabIndex={ -1 } ref={ heading }>
				{ sprintf(
					/* translators: %s: agent name. */
					__( 'Application password for %s', 'leitvia-studio' ),
					secret.agent.name
				) }
			</h3>
			<p>
				{ __(
					'Copy the password now. It is shown only once; afterwards only a new password helps.',
					'leitvia-studio'
				) }
			</p>
			<p>
				{ __( 'User name:', 'leitvia-studio' ) }{ ' ' }
				<code>{ secret.agent.login }</code>
			</p>
			<p>
				{ __( 'Password:', 'leitvia-studio' ) }{ ' ' }
				<code className="leitvia-studio-agents__password">
					{ secret.password }
				</code>
			</p>
			<Flex justify="flex-start" wrap>
				<Button __next40pxDefaultSize variant="primary" ref={ copy }>
					{ copied
						? __( 'Copied', 'leitvia-studio' )
						: __( 'Copy password', 'leitvia-studio' ) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="secondary"
					onClick={ onDone }
				>
					{ __( 'Done', 'leitvia-studio' ) }
				</Button>
			</Flex>
		</section>
	);
}

function RevokeDialog( { agent, onCancel, onConfirm, isBusy } ) {
	return (
		<Modal
			title={ sprintf(
				/* translators: %s: agent name. */
				__( 'Revoke access of %s?', 'leitvia-studio' ),
				agent.name
			) }
			onRequestClose={ onCancel }
			size="medium"
		>
			<p>
				{ __(
					'All application passwords of this agent stop working at once. The user and its content stay; a new password gives access again.',
					'leitvia-studio'
				) }
			</p>
			<Flex justify="flex-end">
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={ onCancel }
				>
					{ __( 'Cancel', 'leitvia-studio' ) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="primary"
					isDestructive
					isBusy={ isBusy }
					disabled={ isBusy }
					accessibleWhenDisabled
					onClick={ onConfirm }
				>
					{ __( 'Revoke access', 'leitvia-studio' ) }
				</Button>
			</Flex>
		</Modal>
	);
}

function Access() {
	const [ access, setAccess ] = useState( null );
	const [ name, setName ] = useState( '' );
	const [ secret, setSecret ] = useState( null );
	const [ revoking, setRevoking ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const load = () =>
		apiFetch( { path: ACCESS } )
			.then( setAccess )
			.catch( ( error ) =>
				notify(
					'error',
					errorText(
						error,
						__(
							'The agents could not be loaded.',
							'leitvia-studio'
						)
					)
				)
			);
	useEffect( () => {
		load();
	}, [] );

	const create = async ( event ) => {
		event.preventDefault();
		setBusy( true );
		try {
			const result = await apiFetch( {
				path: ACCESS,
				method: 'POST',
				data: { name },
			} );
			setName( '' );
			setSecret( result );
			notify(
				'success',
				sprintf(
					/* translators: %s: agent name. */
					__( 'Agent %s created.', 'leitvia-studio' ),
					result.agent.name
				)
			);
			load();
		} catch ( error ) {
			notify(
				'error',
				errorText(
					error,
					__( 'The agent could not be created.', 'leitvia-studio' )
				)
			);
		} finally {
			setBusy( false );
		}
	};

	const newPassword = async ( agent ) => {
		try {
			setSecret(
				await apiFetch( {
					path: `${ ACCESS }/${ agent.id }/password`,
					method: 'POST',
				} )
			);
			load();
		} catch ( error ) {
			notify(
				'error',
				errorText(
					error,
					__( 'The password could not be created.', 'leitvia-studio' )
				)
			);
		}
	};

	const revoke = async () => {
		setBusy( true );
		try {
			await apiFetch( {
				path: `${ ACCESS }/${ revoking.id }`,
				method: 'DELETE',
			} );
			notify(
				'success',
				sprintf(
					/* translators: %s: agent name. */
					__( 'Access of %s revoked.', 'leitvia-studio' ),
					revoking.name
				)
			);
			setRevoking( null );
			load();
		} catch ( error ) {
			notify(
				'error',
				errorText(
					error,
					__( 'The access could not be revoked.', 'leitvia-studio' )
				)
			);
		} finally {
			setBusy( false );
		}
	};

	if ( ! access ) {
		return <Spinner />;
	}
	return (
		<section aria-labelledby="leitvia-studio-access">
			<h2 id="leitvia-studio-access">
				{ __( 'Access', 'leitvia-studio' ) }
			</h2>
			<McpNotice mcp={ access.mcp } />
			{ ! access.passwords && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Application passwords are not available on this site. They need HTTPS, and no plugin may turn them off.',
						'leitvia-studio'
					) }
				</Notice>
			) }
			{ secret && (
				<OneTimePassword
					secret={ secret }
					onDone={ () => setSecret( null ) }
				/>
			) }
			{ access.agents.length ? (
				<div className="leitvia-studio-agents__table">
					<table className="widefat striped">
						<caption>
							{ __( 'Agent users', 'leitvia-studio' ) }
						</caption>
						<thead>
							<tr>
								<th scope="col">
									{ __( 'Name', 'leitvia-studio' ) }
								</th>
								<th scope="col">
									{ __( 'User name', 'leitvia-studio' ) }
								</th>
								<th scope="col">
									{ __(
										'Application passwords',
										'leitvia-studio'
									) }
								</th>
								<th scope="col">
									{ __( 'Last used', 'leitvia-studio' ) }
								</th>
								<th scope="col">
									{ __( 'Actions', 'leitvia-studio' ) }
								</th>
							</tr>
						</thead>
						<tbody>
							{ access.agents.map( ( agent ) => (
								<tr key={ agent.id }>
									<th scope="row">{ agent.name }</th>
									<td>{ agent.login }</td>
									<td>{ agent.passwords }</td>
									<td>
										{ formatDate( agent.last_used ) ||
											__( 'Never', 'leitvia-studio' ) }
									</td>
									<td>
										<Flex justify="flex-start" wrap>
											<Button
												__next40pxDefaultSize
												variant="secondary"
												onClick={ () =>
													newPassword( agent )
												}
												disabled={ ! access.passwords }
												accessibleWhenDisabled
											>
												{ __(
													'New password',
													'leitvia-studio'
												) }
												<VisuallyHidden>
													{ ' ' }
													{ agent.name }
												</VisuallyHidden>
											</Button>
											{ agent.passwords > 0 && (
												<Button
													__next40pxDefaultSize
													variant="secondary"
													isDestructive
													onClick={ () =>
														setRevoking( agent )
													}
												>
													{ __(
														'Revoke access',
														'leitvia-studio'
													) }
													<VisuallyHidden>
														{ ' ' }
														{ agent.name }
													</VisuallyHidden>
												</Button>
											) }
										</Flex>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) : (
				<p>{ __( 'No agents yet.', 'leitvia-studio' ) }</p>
			) }
			<form className="leitvia-studio-agents__create" onSubmit={ create }>
				<h3>{ __( 'Create agent', 'leitvia-studio' ) }</h3>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Agent name', 'leitvia-studio' ) }
					help={ sprintf(
						/* translators: 1: product name, 2: name of the agent role. */
						__(
							'For example the tool or person the agent works for. %1$s creates a user with the role %2$s and an application password.',
							'leitvia-studio'
						),
						BRAND,
						AGENT_ROLE
					) }
					value={ name }
					onChange={ setName }
					required
				/>
				<Button
					__next40pxDefaultSize
					type="submit"
					variant="primary"
					isBusy={ busy && ! revoking }
					disabled={ busy || ! access.passwords }
					accessibleWhenDisabled
				>
					{ __( 'Create agent', 'leitvia-studio' ) }
				</Button>
			</form>
			{ revoking && (
				<RevokeDialog
					agent={ revoking }
					isBusy={ busy }
					onCancel={ () => setRevoking( null ) }
					onConfirm={ revoke }
				/>
			) }
		</section>
	);
}

const LIMIT_FIELDS = [
	{
		id: 'verify',
		label: __( 'Verify after writing', 'leitvia-studio' ),
		description: __(
			'Loads each page an agent changed live from this site and checks it; a failed autonomous change is rolled back.',
			'leitvia-studio'
		),
		type: 'boolean',
		Edit: 'toggle',
	},
	{
		id: 'min_composition_score',
		label: __( 'Minimum composition score', 'leitvia-studio' ),
		description: __(
			'From 0 to 100. An autonomous change that leaves a page below it and below its previous score becomes a change package.',
			'leitvia-studio'
		),
		type: 'integer',
	},
	{
		id: 'rate_per_minute',
		label: __(
			'Rate limit (calls per minute and agent)',
			'leitvia-studio'
		),
		type: 'integer',
	},
	{
		id: 'retention_days',
		label: __( 'Keep audit log and snapshots (days)', 'leitvia-studio' ),
		type: 'integer',
	},
	{
		id: 'max_table_mb',
		label: __( 'Largest size per table (MB)', 'leitvia-studio' ),
		type: 'integer',
	},
];

const LIMIT_FORM = {
	layout: { type: 'regular' },
	fields: LIMIT_FIELDS.map( ( field ) => field.id ),
};

function Autonomy() {
	const [ schema, setSchema ] = useState( null );
	const [ data, setData ] = useState( null );
	const [ confirm, setConfirm ] = useState( false );
	const [ saving, setSaving ] = useState( false );

	const take = ( response ) => {
		setSchema( response );
		setData( response.settings );
		setConfirm( false );
	};
	useEffect( () => {
		apiFetch( { path: SETTINGS } )
			.then( take )
			.catch( ( error ) =>
				notify(
					'error',
					errorText(
						error,
						__(
							'The settings could not be loaded.',
							'leitvia-studio'
						)
					)
				)
			);
	}, [] );

	if ( ! data ) {
		return <Spinner />;
	}
	const full = 'full' === data.profile;
	const toFull = full && 'full' !== schema.settings.profile;
	const profileLevel = ( area ) =>
		full ? 'autonomous' : schema.defaults[ area ];
	const setArea = ( area, level ) => {
		const areas = { ...data.overrides.areas };
		if ( level ) {
			areas[ area ] = level;
		} else {
			delete areas[ area ];
		}
		setData( { ...data, overrides: { ...data.overrides, areas } } );
	};
	const setKind = ( kind, on ) => {
		const kinds = data.overrides.kinds.filter( ( item ) => item !== kind );
		setData( {
			...data,
			overrides: {
				...data.overrides,
				kinds: on ? [ ...kinds, kind ] : kinds,
			},
		} );
	};

	const save = async ( event ) => {
		event.preventDefault();
		setSaving( true );
		const settings = full
			? data
			: { ...data, overrides: { ...data.overrides, kinds: [] } };
		try {
			take(
				await apiFetch( {
					path: SETTINGS,
					method: 'POST',
					data: { ...settings, confirm },
				} )
			);
			notify(
				'success',
				__( 'Autonomy settings saved.', 'leitvia-studio' )
			);
		} catch ( error ) {
			notify(
				'error',
				errorText(
					error,
					__( 'The settings could not be saved.', 'leitvia-studio' )
				)
			);
		} finally {
			setSaving( false );
		}
	};

	return (
		<form
			className="leitvia-studio-agents__autonomy"
			aria-labelledby="leitvia-studio-autonomy"
			onSubmit={ save }
		>
			<h2 id="leitvia-studio-autonomy">
				{ __( 'Autonomy', 'leitvia-studio' ) }
			</h2>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Profile', 'leitvia-studio' ) }
				help={ __(
					'Standard: agents fix small errors on their own, write content as drafts and propose design changes. Fully autonomous: agents change and publish every area but administration without review.',
					'leitvia-studio'
				) }
				value={ data.profile }
				options={ [
					{
						value: 'standard',
						label: __( 'Standard', 'leitvia-studio' ),
					},
					{
						value: 'full',
						label: __( 'Fully autonomous', 'leitvia-studio' ),
					},
				] }
				onChange={ ( profile ) => setData( { ...data, profile } ) }
			/>
			{ toFull && (
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ __(
						'I understand that agents then change and publish content without review.',
						'leitvia-studio'
					) }
					checked={ confirm }
					onChange={ setConfirm }
					required
				/>
			) }
			<div className="leitvia-studio-agents__table">
				<table className="widefat striped">
					<caption>
						{ __( 'Autonomy level per area', 'leitvia-studio' ) }
					</caption>
					<thead>
						<tr>
							<th scope="col">
								{ __( 'Area', 'leitvia-studio' ) }
							</th>
							<th scope="col">
								{ __( 'Level', 'leitvia-studio' ) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ schema.areas.map( ( area ) => (
							<tr key={ area }>
								<th scope="row" id={ `leitvia-area-${ area }` }>
									{ AREAS[ area ] ?? area }
								</th>
								<td>
									{ 'admin' === area ? (
										__(
											'Locked. Agents never change plugins, users or settings.',
											'leitvia-studio'
										)
									) : (
										<select
											aria-labelledby={ `leitvia-area-${ area }` }
											value={
												data.overrides.areas[ area ] ??
												''
											}
											onChange={ ( event ) =>
												setArea(
													area,
													event.target.value
												)
											}
										>
											<option value="">
												{ sprintf(
													/* translators: %s: autonomy level such as Draft. */
													__(
														'Profile default (%s)',
														'leitvia-studio'
													),
													LEVELS[
														profileLevel( area )
													]
												) }
											</option>
											{ schema.levels.map( ( level ) => (
												<option
													key={ level }
													value={ level }
												>
													{ LEVELS[ level ] ?? level }
												</option>
											) ) }
										</select>
									) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
			{ full && (
				<fieldset>
					<legend>
						{ __(
							'Content kinds agents may change autonomously',
							'leitvia-studio'
						) }
					</legend>
					<p>
						{ __(
							'Without a tick, changes to this kind stay a suggestion for a person to decide.',
							'leitvia-studio'
						) }
					</p>
					{ schema.kinds.map( ( kind ) => (
						<CheckboxControl
							key={ kind }
							__nextHasNoMarginBottom
							label={ KINDS[ kind ] ?? kind }
							checked={ data.overrides.kinds.includes( kind ) }
							onChange={ ( on ) => setKind( kind, on ) }
						/>
					) ) }
				</fieldset>
			) }
			<DataForm
				data={ data }
				fields={ LIMIT_FIELDS }
				form={ LIMIT_FORM }
				onChange={ ( edits ) =>
					setData( ( current ) => ( { ...current, ...edits } ) )
				}
			/>
			<Flex justify="flex-start">
				<Button
					__next40pxDefaultSize
					type="submit"
					variant="primary"
					isBusy={ saving }
					disabled={ saving }
					accessibleWhenDisabled
				>
					{ __( 'Save autonomy settings', 'leitvia-studio' ) }
				</Button>
			</Flex>
		</form>
	);
}

export default function AgentsScreen() {
	return (
		<Flex
			direction="column"
			justify="flex-start"
			gap={ 8 }
			align="stretch"
			className="leitvia-studio-agents"
		>
			<Access />
			<Autonomy />
		</Flex>
	);
}
