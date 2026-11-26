// Agents screen in three tabs: Overview (the status of the connection at a glance and the agent users with their
// application passwords, shown once), Inbox (the changes agents propose, or one of them with ?package=<id>) and
// Autonomy and checks (the checks before and after every write and the autonomy of the gateway). Access comes from
// /leitvia-studio/v1/gateway/access, settings are saved through /leitvia-studio/v1/gateway/settings; each form sends
// only its own keys, so one form never discards what the other is editing. Users without manage_options see the
// Inbox only.
import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { useCopyToClipboard } from '@wordpress/compose';
import { getQueryArg } from '@wordpress/url';
import { DataForm } from '@wordpress/dataviews/wp';
import {
	Button,
	CheckboxControl,
	Flex,
	SelectControl,
	TextControl,
	VisuallyHidden,
} from '@wordpress/components';
import { errorText } from '../common';
import ConfirmDialog from '../confirm-dialog';
import Loading from '../loading';
import More from '../more';
import { notify } from '../notify';
import StatusList from '../status-list';
import Tabs from '../tabs';
import InboxDetail from '../inbox/detail';
import InboxList from '../inbox/list';
import { formatDate } from '../inbox/common';
import { AREAS, KINDS, LEVELS } from './common';

const ACCESS = '/leitvia-studio/v1/gateway/access';
const SETTINGS = '/leitvia-studio/v1/gateway/settings';
const CONNECTIONS = `${ ACCESS }/connections`;

// Product name and agent role from Admin::enqueue(), filter leitvia_studio_brand.
const AGENT_ROLE = window.leitviaStudioAdmin?.agentRole ?? 'Leitvia Agent';
const CAN_MANAGE = window.leitviaStudioAdmin?.canManage ?? true;

const VERIFY_RESULTS = {
	ok: __( 'passed', 'leitvia-studio' ),
	failed: __( 'failed', 'leitvia-studio' ),
	unverifiable: __( 'could not be checked', 'leitvia-studio' ),
};

function connection( agents ) {
	const ready = agents.filter( ( agent ) => agent.passwords > 0 );
	const used = ready
		.filter( ( agent ) => agent.last_used )
		.sort( ( a, b ) => b.last_used.localeCompare( a.last_used ) );
	if ( used.length ) {
		return {
			state: 'ok',
			title: __( 'Connected', 'leitvia-studio' ),
			text: sprintf(
				/* translators: 1: agent name, 2: date and time. */
				__( 'Last call by %1$s on %2$s.', 'leitvia-studio' ),
				used[ 0 ].name,
				formatDate( used[ 0 ].last_used )
			),
		};
	}
	if ( ready.length ) {
		return {
			state: 'warning',
			title: __( 'Ready, not used yet', 'leitvia-studio' ),
			text: sprintf(
				/* translators: %s: agent name. */
				__(
					'%s has a password but has not called this site yet.',
					'leitvia-studio'
				),
				ready[ 0 ].name
			),
		};
	}
	return {
		state: 'warning',
		title: __( 'Not connected', 'leitvia-studio' ),
		text: __(
			'No agent has a password. Create an agent below.',
			'leitvia-studio'
		),
	};
}

function serverRow( server ) {
	return {
		state: 'ok',
		title: __( 'Built in', 'leitvia-studio' ),
		text: sprintf(
			/* translators: 1: address of the MCP server, 2: protocol revisions. */
			__(
				'AI apps connect directly at %1$s (MCP %2$s).',
				'leitvia-studio'
			),
			server.url,
			server.protocols.join( ', ' )
		),
	};
}

function adapterRow( mcp ) {
	if ( mcp.compatible ) {
		return {
			state: 'ok',
			title: sprintf(
				/* translators: %s: installed version. */
				__( 'Active, version %s', 'leitvia-studio' ),
				mcp.version
			),
			text: __(
				'Its default server offers the same abilities with the same checks.',
				'leitvia-studio'
			),
		};
	}
	return {
		state: 'info',
		title: mcp.active
			? sprintf(
					/* translators: %s: installed version. */
					__( 'Version %s, not tested', 'leitvia-studio' ),
					mcp.version
				)
			: __( 'Not needed', 'leitvia-studio' ),
		text: __(
			'Optional. Agents use the built-in server; the plugin adds the default MCP server of WordPress.',
			'leitvia-studio'
		),
	};
}

function checksRow( access, settings ) {
	const before = sprintf(
		/* translators: %d: number of checks. */
		_n(
			'%d check runs before every write.',
			'%d checks run before every write.',
			access.checks.length,
			'leitvia-studio'
		),
		access.checks.length
	);
	if ( ! settings.verify ) {
		return {
			state: 'warning',
			title: __( 'Only before writing', 'leitvia-studio' ),
			text: `${ before } ${ __(
				'The check after writing is off, so a broken page stays live.',
				'leitvia-studio'
			) }`,
		};
	}
	const last = access.verify.last;
	const failed = last && 'failed' === last.result;
	return {
		state: failed ? 'error' : 'ok',
		title: __( 'Before and after writing', 'leitvia-studio' ),
		text: last
			? `${ before } ${ sprintf(
					/* translators: 1: result such as passed, 2: date and time. */
					__(
						'Last check after writing %1$s on %2$s.',
						'leitvia-studio'
					),
					VERIFY_RESULTS[ last.result ] ?? last.result,
					formatDate( last.created_at )
				) }${
					'rolled_back' === last.action
						? ` ${ __(
								'The change was rolled back.',
								'leitvia-studio'
							) }`
						: ''
				}`
			: `${ before } ${ __(
					'No page has been checked after writing yet.',
					'leitvia-studio'
				) }`,
	};
}

function Status( { access, settings } ) {
	const rows = [
		{
			name: __( 'Connection', 'leitvia-studio' ),
			...connection( access.agents ),
		},
		{
			name: __( 'Application passwords', 'leitvia-studio' ),
			...( access.passwords
				? {
						state: 'ok',
						title: __( 'Available', 'leitvia-studio' ),
						text: __(
							'Agents sign in with their own password.',
							'leitvia-studio'
						),
					}
				: {
						state: 'error',
						title: __( 'Not available', 'leitvia-studio' ),
						text: __(
							'They need HTTPS, and no plugin may turn them off.',
							'leitvia-studio'
						),
					} ),
		},
		{
			name: __( 'Checks', 'leitvia-studio' ),
			...checksRow( access, settings ),
		},
		{
			name: __( 'Direct connection for AI apps', 'leitvia-studio' ),
			...serverRow( access.server ),
		},
		{
			name: __( 'MCP Adapter', 'leitvia-studio' ),
			...adapterRow( access.mcp ),
			link: access.mcp.url,
			linkText: __( 'MCP Adapter on WordPress.org', 'leitvia-studio' ),
		},
	];
	return (
		<section aria-labelledby="leitvia-studio-status">
			<h2 id="leitvia-studio-status">
				{ __( 'Status', 'leitvia-studio' ) }
			</h2>
			<StatusList rows={ rows } />
			<More summary={ __( 'How agents connect', 'leitvia-studio' ) }>
				<p>
					{ sprintf(
						/* translators: %s: address of the MCP server. */
						__(
							'Agents call the REST API of WordPress (/wp-json/) with their user name and an application password, and use the abilities of the Abilities API. MCP clients connect to the built-in server at %s. Every call is checked against the autonomy settings and written to the audit log.',
							'leitvia-studio'
						),
						access.server.url
					) }
				</p>
			</More>
		</section>
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

function Access( { access, reload } ) {
	const [ name, setName ] = useState( '' );
	const [ secret, setSecret ] = useState( null );
	const [ revoking, setRevoking ] = useState( null );
	const [ busy, setBusy ] = useState( false );

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
			reload();
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
			reload();
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
			reload();
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

	return (
		<section aria-labelledby="leitvia-studio-access">
			<h2 id="leitvia-studio-access">
				{ __( 'Agents', 'leitvia-studio' ) }
			</h2>
			<p>
				{ __(
					'An agent works on behalf of this site with its own application password, which you can revoke at any time. It never changes plugins, themes or users, and its changes to settings always wait for a person in the Inbox.',
					'leitvia-studio'
				) }
			</p>
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
							<VisuallyHidden>
								{ __( 'Agent users', 'leitvia-studio' ) }
							</VisuallyHidden>
						</caption>
						<thead>
							<tr>
								<th scope="col">
									{ __( 'Agent', 'leitvia-studio' ) }
								</th>
								<th scope="col">
									{ __( 'Access', 'leitvia-studio' ) }
								</th>
								<th scope="col">
									{ __( 'Actions', 'leitvia-studio' ) }
								</th>
							</tr>
						</thead>
						<tbody>
							{ access.agents.map( ( agent ) => (
								<tr key={ agent.id }>
									<th scope="row">
										{ agent.name }
										<br />
										<code>{ agent.login }</code>
									</th>
									<td>
										{ ! agent.passwords &&
											__(
												'No password, no access',
												'leitvia-studio'
											) }
										{ agent.passwords > 0 &&
											( agent.last_used
												? sprintf(
														/* translators: %s: date and time. */
														__(
															'Last call %s',
															'leitvia-studio'
														),
														formatDate(
															agent.last_used
														)
													)
												: __(
														'Password set, never used',
														'leitvia-studio'
													) ) }
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
													variant="tertiary"
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
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'New agent', 'leitvia-studio' ) }
					help={ sprintf(
						/* translators: %s: name of the agent role. */
						__(
							'Name of the tool or person, for example Claude. Creates a user with the role %s and a password.',
							'leitvia-studio'
						),
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
				<ConfirmDialog
					title={ sprintf(
						/* translators: %s: agent name. */
						__( 'Revoke access of %s?', 'leitvia-studio' ),
						revoking.name
					) }
					confirmLabel={ __( 'Revoke access', 'leitvia-studio' ) }
					isDestructive
					isBusy={ busy }
					onCancel={ () => setRevoking( null ) }
					onConfirm={ revoke }
				>
					<p>
						{ __(
							'All application passwords of this agent stop working at once. The user and its content stay; a new password gives access again.',
							'leitvia-studio'
						) }
					</p>
				</ConfirmDialog>
			) }
		</section>
	);
}

// Saves only the keys of one form and hands the saved settings to the screen.
function useSave( onSaved, success ) {
	const [ saving, setSaving ] = useState( false );
	const save = async ( data ) => {
		setSaving( true );
		try {
			onSaved(
				await apiFetch( { path: SETTINGS, method: 'POST', data } )
			);
			notify( 'success', success );
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
	return [ saving, save ];
}

const CHECK_FIELDS = [
	{
		id: 'verify',
		label: __( 'Verify after writing', 'leitvia-studio' ),
		description: __(
			'Opens every changed page afterwards and undoes a change an agent made on its own if the page broke.',
			'leitvia-studio'
		),
		type: 'boolean',
		Edit: 'toggle',
	},
	{
		id: 'min_composition_score',
		label: __( 'Minimum layout score', 'leitvia-studio' ),
		description: __(
			'From 0 to 100; a change that makes the layout of a page worse than this waits in the Inbox for you.',
			'leitvia-studio'
		),
		type: 'integer',
	},
];

const LIMIT_FIELDS = [
	{
		id: 'rate_per_minute',
		label: __( 'Changes per minute and agent', 'leitvia-studio' ),
		type: 'integer',
	},
	{
		id: 'read_rate_per_minute',
		label: __( 'Reads per minute and agent', 'leitvia-studio' ),
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

const formOf = ( fields ) => ( {
	layout: { type: 'regular' },
	fields: fields.map( ( field ) => field.id ),
} );

const pick = ( data, fields ) =>
	Object.fromEntries(
		fields.map( ( field ) => [ field.id, data[ field.id ] ] )
	);

const SCOPE_LABELS = {
	'leitvia:read': __( 'read', 'leitvia-studio' ),
	'leitvia:write': __( 'write', 'leitvia-studio' ),
	'leitvia:approve': __( 'approve changes', 'leitvia-studio' ),
};

const OAUTH_FIELDS = [
	{
		id: 'oauth',
		label: __( 'Connect over OAuth', 'leitvia-studio' ),
		description: __(
			'Clients such as claude.ai, Claude Desktop or ChatGPT connect after you agree on the page "Connect agent". Needs HTTPS.',
			'leitvia-studio'
		),
		type: 'boolean',
		Edit: 'toggle',
	},
	{
		id: 'oauth_dcr',
		label: __( 'Allow dynamic client registration', 'leitvia-studio' ),
		description: __(
			'For older clients without a client document (RFC 7591, deprecated in MCP). New clients name themselves with a client document.',
			'leitvia-studio'
		),
		type: 'boolean',
		Edit: 'toggle',
	},
];

// Whether clients reach the OAuth metadata of the site, asked once the screen shows; the server keeps the answer 12
// hours. Blocked: the nginx directives that let the well-known paths through, to copy.
function Discovery() {
	const [ discovery, setDiscovery ] = useState( null );
	const [ copied, setCopied ] = useState( false );
	const copy = useCopyToClipboard( discovery?.directives ?? '', () => {
		setCopied( true );
		notify( 'success', __( 'Directives copied.', 'leitvia-studio' ) );
	} );
	useEffect( () => {
		apiFetch( { path: `${ ACCESS }/discovery` } )
			.then( ( response ) => setDiscovery( response.discovery ) )
			.catch( () => {} );
	}, [] );
	if ( ! discovery || discovery.ok ) {
		return null;
	}
	return (
		<>
			<StatusList
				rows={ [
					{
						name: __( 'OAuth discovery', 'leitvia-studio' ),
						state: 'error',
						title: __( 'Blocked', 'leitvia-studio' ),
						text: sprintf(
							/* translators: %d: HTTP status code, 0 when the site did not answer. */
							__(
								'Clients find no sign-in: the site answers %d at /.well-known/. An nginx rule such as location ~ /\\. stops these paths before WordPress runs. Add the directives below to the site, in ISPConfig under Website, Options, nginx Directives.',
								'leitvia-studio'
							),
							discovery.status
						),
						link: discovery.help,
						linkText: __(
							'Troubleshooting in the documentation',
							'leitvia-studio'
						),
					},
				] }
			/>
			<pre className="leitvia-studio-agents__directives">
				<code>{ discovery.directives }</code>
			</pre>
			<Button __next40pxDefaultSize variant="secondary" ref={ copy }>
				{ copied
					? __( 'Copied', 'leitvia-studio' )
					: __( 'Copy directives', 'leitvia-studio' ) }
			</Button>
		</>
	);
}

function Connections( { access, gateway, reload, onSaved } ) {
	const [ closing, setClosing ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ data, setData ] = useState( () =>
		pick( gateway.settings, OAUTH_FIELDS )
	);
	const [ saving, save ] = useSave(
		( response ) => {
			setData( pick( response.settings, OAUTH_FIELDS ) );
			onSaved( response );
			reload();
		},
		__( 'Connection settings saved.', 'leitvia-studio' )
	);

	const disconnect = async () => {
		setBusy( true );
		try {
			await apiFetch( {
				path: `${ CONNECTIONS }/${ closing.id }`,
				method: 'DELETE',
			} );
			notify(
				'success',
				sprintf(
					/* translators: %s: name of the client, for example Claude. */
					__( 'Connection of %s closed.', 'leitvia-studio' ),
					closing.client
				)
			);
			setClosing( null );
			reload();
		} catch ( error ) {
			notify(
				'error',
				errorText(
					error,
					__(
						'The connection could not be closed.',
						'leitvia-studio'
					)
				)
			);
		} finally {
			setBusy( false );
		}
	};

	return (
		<section aria-labelledby="leitvia-studio-connections">
			<h2 id="leitvia-studio-connections">
				{ __( 'Connections', 'leitvia-studio' ) }
			</h2>
			<p>
				{ __(
					'Clients connected over OAuth. Each one works as the user shown, with the rights it was given; closing a connection stops its tokens at once.',
					'leitvia-studio'
				) }
			</p>
			{ ! access.oauth.available && (
				<p>
					{ __(
						'OAuth needs HTTPS on this site. Until then agents connect with an application password.',
						'leitvia-studio'
					) }
				</p>
			) }
			{ access.oauth.active && <Discovery /> }
			{ access.connections.length ? (
				<div className="leitvia-studio-agents__table">
					<table className="widefat striped">
						<caption>
							<VisuallyHidden>
								{ __( 'OAuth connections', 'leitvia-studio' ) }
							</VisuallyHidden>
						</caption>
						<thead>
							<tr>
								<th scope="col">
									{ __( 'Client', 'leitvia-studio' ) }
								</th>
								<th scope="col">
									{ __( 'Works as', 'leitvia-studio' ) }
								</th>
								<th scope="col">
									{ __( 'Last call', 'leitvia-studio' ) }
								</th>
								<th scope="col">
									{ __( 'Actions', 'leitvia-studio' ) }
								</th>
							</tr>
						</thead>
						<tbody>
							{ access.connections.map( ( item ) => (
								<tr key={ item.id }>
									<th scope="row">
										{ item.client }
										{ item.host && (
											<>
												<br />
												<code>{ item.host }</code>
											</>
										) }
									</th>
									<td>
										{ item.user.name }
										<br />
										{ item.scopes
											.map(
												( scope ) =>
													SCOPE_LABELS[ scope ] ??
													scope
											)
											.join( ', ' ) }
									</td>
									<td>
										{ item.last_used
											? sprintf(
													/* translators: 1: date and time, 2: shortened IP address. */
													__(
														'%1$s from %2$s',
														'leitvia-studio'
													),
													formatDate(
														item.last_used
													),
													item.ip
												)
											: __(
													'Not used yet',
													'leitvia-studio'
												) }
									</td>
									<td>
										<Button
											__next40pxDefaultSize
											variant="tertiary"
											isDestructive
											onClick={ () => setClosing( item ) }
										>
											{ __(
												'Close connection',
												'leitvia-studio'
											) }
											<VisuallyHidden>
												{ ' ' }
												{ item.client }
											</VisuallyHidden>
										</Button>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) : (
				<p>{ __( 'No connections yet.', 'leitvia-studio' ) }</p>
			) }
			<form
				className="leitvia-studio-agents__panel"
				aria-label={ __( 'OAuth settings', 'leitvia-studio' ) }
				onSubmit={ ( event ) => {
					event.preventDefault();
					save( data );
				} }
			>
				<DataForm
					data={ data }
					fields={ OAUTH_FIELDS }
					form={ formOf( OAUTH_FIELDS ) }
					onChange={ ( edits ) =>
						setData( ( current ) => ( { ...current, ...edits } ) )
					}
				/>
				<Button
					__next40pxDefaultSize
					type="submit"
					variant="secondary"
					isBusy={ saving }
					disabled={ saving }
					accessibleWhenDisabled
				>
					{ __( 'Save connection settings', 'leitvia-studio' ) }
				</Button>
			</form>
			{ closing && (
				<ConfirmDialog
					title={ sprintf(
						/* translators: %s: name of the client. */
						__( 'Close the connection of %s?', 'leitvia-studio' ),
						closing.client
					) }
					confirmLabel={ __( 'Close connection', 'leitvia-studio' ) }
					isDestructive
					isBusy={ busy }
					onCancel={ () => setClosing( null ) }
					onConfirm={ disconnect }
				>
					<p>
						{ __(
							'Its access and refresh tokens stop working at once. The client can connect again only after you agree once more.',
							'leitvia-studio'
						) }
					</p>
				</ConfirmDialog>
			) }
		</section>
	);
}

function Checks( { access, gateway, onSaved } ) {
	const [ data, setData ] = useState( () =>
		pick( gateway.settings, CHECK_FIELDS )
	);
	const [ saving, save ] = useSave(
		( response ) => {
			setData( pick( response.settings, CHECK_FIELDS ) );
			onSaved( response );
		},
		__( 'Checks saved.', 'leitvia-studio' )
	);
	const counts = access.verify.counts;
	return (
		<form
			className="leitvia-studio-agents__panel"
			aria-labelledby="leitvia-studio-checks"
			onSubmit={ ( event ) => {
				event.preventDefault();
				save( data );
			} }
		>
			<h2 id="leitvia-studio-checks">
				{ __( 'Checks', 'leitvia-studio' ) }
			</h2>
			<div>
				<h3>{ __( 'Before every write', 'leitvia-studio' ) }</h3>
				<p>
					{ __(
						'Always on. An error stops the change and tells the agent how to fix it; a warning sends the change to the Inbox.',
						'leitvia-studio'
					) }
				</p>
				<ul className="leitvia-studio-agents__checks">
					{ access.checks.map( ( label ) => (
						<li key={ label }>{ label }</li>
					) ) }
				</ul>
			</div>
			<div>
				<h3>{ __( 'After every write', 'leitvia-studio' ) }</h3>
				<p>
					{ sprintf(
						/* translators: 1, 2, 3: numbers of checked pages. */
						__(
							'Last 30 days: %1$d passed, %2$d failed, %3$d could not be checked.',
							'leitvia-studio'
						),
						counts.ok ?? 0,
						counts.failed ?? 0,
						counts.unverifiable ?? 0
					) }
				</p>
			</div>
			<DataForm
				data={ data }
				fields={ CHECK_FIELDS }
				form={ formOf( CHECK_FIELDS ) }
				onChange={ ( edits ) =>
					setData( ( current ) => ( { ...current, ...edits } ) )
				}
			/>
			<More>
				<p>
					{ __(
						'Verify after writing loads each changed page as a visitor sees it and checks the HTTP status, PHP messages, one h1, landmarks, raw block markup and contrast. A failed autonomous change is rolled back, any other one gets a note in the Inbox.',
						'leitvia-studio'
					) }
				</p>
				<p>
					{ __(
						'The layout score is the composition score of the page: an autonomous change that leaves a page below the minimum and below its previous score goes to the Inbox instead.',
						'leitvia-studio'
					) }
				</p>
			</More>
			<Button
				__next40pxDefaultSize
				type="submit"
				variant="primary"
				isBusy={ saving }
				disabled={ saving }
				accessibleWhenDisabled
			>
				{ __( 'Save checks', 'leitvia-studio' ) }
			</Button>
		</form>
	);
}

const AUTONOMY_KEYS = [
	'profile',
	'overrides',
	...LIMIT_FIELDS.map( ( f ) => f.id ),
];

function Autonomy( { gateway, onSaved } ) {
	const [ data, setData ] = useState( gateway.settings );
	const [ confirm, setConfirm ] = useState( false );
	const [ saving, save ] = useSave(
		( response ) => {
			setData( response.settings );
			setConfirm( false );
			onSaved( response );
		},
		__( 'Autonomy settings saved.', 'leitvia-studio' )
	);

	const full = 'full' === data.profile;
	const toFull = full && 'full' !== gateway.settings.profile;
	const profileLevel = ( area ) =>
		full ? 'autonomous' : gateway.defaults[ area ];
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

	const submit = ( event ) => {
		event.preventDefault();
		const settings = Object.fromEntries(
			AUTONOMY_KEYS.map( ( key ) => [ key, data[ key ] ] )
		);
		if ( ! full ) {
			settings.overrides = { ...settings.overrides, kinds: [] };
		}
		save( { ...settings, confirm } );
	};

	return (
		<form
			className="leitvia-studio-agents__panel"
			aria-labelledby="leitvia-studio-autonomy"
			onSubmit={ submit }
		>
			<h2 id="leitvia-studio-autonomy">
				{ __( 'Autonomy', 'leitvia-studio' ) }
			</h2>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Profile', 'leitvia-studio' ) }
				help={ __(
					'Standard: agents fix small errors on their own, write content as drafts and propose design changes. Fully autonomous: agents change and publish content, structure and design without review, but settings, the content model, the active token set and the global styles still wait for a person.',
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
						{ gateway.areas.map( ( area ) => (
							<tr key={ area }>
								<th scope="row" id={ `leitvia-area-${ area }` }>
									{ AREAS[ area ] ?? area }
								</th>
								<td>
									{ 'admin' === area ? (
										__(
											'A person decides.',
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
											{ gateway.levels.map( ( level ) => (
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
			<p>
				{ __(
					'Whatever the level, agents never change plugins, themes or users, and changes to settings, the content model, the active token set or the global styles wait in the Inbox until a person applies them.',
					'leitvia-studio'
				) }
			</p>
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
					{ gateway.kinds.map( ( kind ) => (
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
			<details className="leitvia-studio-agents__limits">
				<summary>{ __( 'Limits', 'leitvia-studio' ) }</summary>
				<DataForm
					data={ data }
					fields={ LIMIT_FIELDS }
					form={ formOf( LIMIT_FIELDS ) }
					onChange={ ( edits ) =>
						setData( ( current ) => ( { ...current, ...edits } ) )
					}
				/>
			</details>
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
		</form>
	);
}

function Inbox() {
	const id = Number( getQueryArg( window.location.href, 'package' ) );
	return id > 0 ? <InboxDetail id={ id } /> : <InboxList />;
}

const TABS = [
	CAN_MANAGE && {
		name: 'overview',
		title: __( 'Overview', 'leitvia-studio' ),
	},
	{ name: 'inbox', title: __( 'Inbox', 'leitvia-studio' ) },
	CAN_MANAGE && {
		name: 'autonomy',
		title: __( 'Autonomy and checks', 'leitvia-studio' ),
	},
].filter( Boolean );

export default function AgentsScreen() {
	const [ access, setAccess ] = useState( null );
	const [ gateway, setGateway ] = useState( null );

	const reload = () =>
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
		if ( ! CAN_MANAGE ) {
			return;
		}
		reload();
		apiFetch( { path: SETTINGS } )
			.then( setGateway )
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

	const content = ( name ) => {
		if ( 'inbox' === name ) {
			return <Inbox />;
		}
		if ( ! access || ! gateway ) {
			return <Loading />;
		}
		return 'overview' === name ? (
			<>
				<Status access={ access } settings={ gateway.settings } />
				<Access access={ access } reload={ reload } />
				<Connections
					access={ access }
					gateway={ gateway }
					reload={ reload }
					onSaved={ setGateway }
				/>
			</>
		) : (
			<>
				<Checks
					access={ access }
					gateway={ gateway }
					onSaved={ setGateway }
				/>
				<Autonomy gateway={ gateway } onSaved={ setGateway } />
			</>
		);
	};

	return (
		<Tabs tabs={ TABS }>
			{ ( tab ) => (
				<Flex
					direction="column"
					justify="flex-start"
					gap={ 8 }
					align="stretch"
					className="leitvia-studio-agents"
				>
					{ content( tab.name ) }
				</Flex>
			) }
		</Tabs>
	);
}
