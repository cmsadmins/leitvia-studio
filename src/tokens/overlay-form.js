// Schedule of a token set as a time-limited overlay: start and end in the time zone of the site, saved in UTC.
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { date, dateI18n, getDate, getSettings } from '@wordpress/date';
import { Button, Flex, Modal, TextControl } from '@wordpress/components';
import { notify } from '../admin/notify';
import { Findings, errorFindings } from './common';

export const OVERLAY = '_leitvia_tokens_overlay';
const LOCAL = 'Y-m-d\\TH:i';
const HOUR = 3600_000;
const HINT_ID = 'leitvia-studio-tokens-overlay-hint';
const REASON_ID = 'leitvia-studio-tokens-overlay-reason';

const toLocal = ( utc ) => ( utc ? date( LOCAL, utc ) : '' );
const toUtc = ( local ) => getDate( local ).toISOString();

/**
 * How a local time of the site maps to UTC around a clock change.
 *
 * @param {string} local Date-time input value in the site time zone.
 * @return {''|'missing'|'twice'} 'missing' when the clocks skip it, 'twice' when they pass it twice.
 */
function clockChange( local ) {
	const time = getDate( local ).getTime();
	if ( date( LOCAL, time ) !== local ) {
		return 'missing';
	}
	return date( LOCAL, time - HOUR ) === local ||
		date( LOCAL, time + HOUR ) === local
		? 'twice'
		: '';
}

/**
 * Time zone of the site for labels: its name, or the UTC offset when the site sets only an offset.
 */
function zone() {
	const { string, offset } = getSettings().timezone;
	return (
		string ||
		sprintf(
			/* translators: %s: UTC offset in hours, for example +2. */
			__( 'UTC%s', 'leitvia-studio' ),
			( offset < 0 ? '' : '+' ) + offset
		)
	);
}

/**
 * Period and state of an overlay as text, for example "Oct 17, 2026 8:00 am to Oct 24, 2026 8:00 am, Running".
 *
 * @param {Object|null} overlay Meta value with start and end in UTC.
 * @param {boolean}     valid   False when the set fails the checks; such a set is never applied.
 * @return {string} Text, '' without an overlay.
 */
export function overlaySummary( overlay, valid = true ) {
	if ( ! overlay?.start || ! overlay?.end ) {
		return '';
	}
	const now = Date.now();
	let state = __( 'Running', 'leitvia-studio' );
	if ( now >= Date.parse( overlay.end ) ) {
		state = __( 'Ended', 'leitvia-studio' );
	} else if ( false === valid ) {
		state = __( 'Not applied (fails checks)', 'leitvia-studio' );
	} else if ( now < Date.parse( overlay.start ) ) {
		state = __( 'Scheduled', 'leitvia-studio' );
	}
	const format = getSettings().formats.datetime;
	return sprintf(
		/* translators: 1: start date and time, 2: end date and time, 3: state (Scheduled, Running, Ended or Not applied). */
		__( '%1$s to %2$s, %3$s', 'leitvia-studio' ),
		dateI18n( format, overlay.start ),
		dateI18n( format, overlay.end ),
		state
	);
}

export default function OverlayForm( { id, initial, valid } ) {
	const [ overlay, setOverlay ] = useState( initial );
	const [ start, setStart ] = useState( toLocal( overlay?.start ) );
	const [ end, setEnd ] = useState( toLocal( overlay?.end ) );
	const [ findings, setFindings ] = useState( null );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ isConfirming, setIsConfirming ] = useState( false );
	const summary = overlaySummary( overlay, valid );

	const changes = [
		[ start, clockChange( start ) ],
		[ end, clockChange( end ) ],
	].filter( ( [ value, change ] ) => value && change );
	let reason = '';
	if ( ! start || ! end ) {
		reason = __( 'Enter a start and an end.', 'leitvia-studio' );
	} else if ( changes.some( ( [ , change ] ) => 'missing' === change ) ) {
		reason = __(
			'A time you entered does not exist in the site time zone because the clocks change then. Choose another time.',
			'leitvia-studio'
		);
	} else if ( end <= start ) {
		reason = __( 'The end must be after the start.', 'leitvia-studio' );
	}
	const twice = changes.find( ( [ , change ] ) => 'twice' === change );

	const save = async ( value ) => {
		setIsBusy( true );
		setFindings( null );
		try {
			const post = await apiFetch( {
				path: `/wp/v2/leitvia-tokens/${ id }`,
				method: 'POST',
				data: { meta: { [ OVERLAY ]: value } },
			} );
			setOverlay( post.meta[ OVERLAY ] );
			notify(
				'success',
				value
					? __( 'Overlay scheduled.', 'leitvia-studio' )
					: __( 'Overlay removed.', 'leitvia-studio' )
			);
			if ( ! value ) {
				setStart( '' );
				setEnd( '' );
			}
		} catch ( error ) {
			setFindings( errorFindings( error ) );
		} finally {
			setIsBusy( false );
		}
	};

	const submit = ( event ) => {
		event.preventDefault();
		if ( ! reason ) {
			save( { start: toUtc( start ), end: toUtc( end ) } );
		}
	};

	const label = ( text ) =>
		sprintf(
			/* translators: 1: Start or End, 2: time zone of the site, for example Europe/Berlin. */
			__( '%1$s (site time zone: %2$s)', 'leitvia-studio' ),
			text,
			zone()
		);
	const describedBy = reason ? `${ HINT_ID } ${ REASON_ID }` : HINT_ID;

	return (
		<section
			aria-labelledby="leitvia-studio-tokens-overlay"
			className="leitvia-studio-tokens__section"
		>
			<h2 id="leitvia-studio-tokens-overlay">
				{ __( 'Schedule as overlay', 'leitvia-studio' ) }
			</h2>
			<p>
				{ __(
					'Between start and end the front end shows this set over the base styles, for a season or a campaign. The global styles stay unchanged, and the overlay ends on its own.',
					'leitvia-studio'
				) }
			</p>
			<p>
				{ summary
					? sprintf(
							/* translators: %s: period and state of the overlay. */
							__( 'Overlay: %s', 'leitvia-studio' ),
							summary
						)
					: __( 'This set is not scheduled.', 'leitvia-studio' ) }
			</p>
			<form onSubmit={ submit } noValidate>
				<Flex
					direction="column"
					justify="flex-start"
					gap={ 4 }
					align="stretch"
				>
					{ findings && (
						<Findings
							heading={ __(
								'The overlay was not saved',
								'leitvia-studio'
							) }
							findings={ findings }
						/>
					) }
					<Flex justify="flex-start" gap={ 4 } wrap>
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							type="datetime-local"
							label={ label( __( 'Start', 'leitvia-studio' ) ) }
							value={ start }
							onChange={ setStart }
							aria-describedby={ describedBy }
						/>
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							type="datetime-local"
							label={ label( __( 'End', 'leitvia-studio' ) ) }
							value={ end }
							onChange={ setEnd }
							aria-describedby={ describedBy }
						/>
					</Flex>
					<p
						id={ HINT_ID }
						className="components-base-control__help leitvia-studio-tokens__help"
					>
						{ __(
							'Page caches that follow the s-maxage header switch on time; caches that ignore it switch once their stored copy expires.',
							'leitvia-studio'
						) }
						{ twice &&
							' ' +
								sprintf(
									/* translators: %s: date and time in the site time zone. */
									__(
										'%s occurs twice in the site time zone because the clocks change then; the earlier one is used.',
										'leitvia-studio'
									),
									twice[ 0 ].replace( 'T', ' ' )
								) }
					</p>
					<Flex justify="flex-start" align="center" gap={ 4 } wrap>
						<Button
							__next40pxDefaultSize
							type="submit"
							variant="secondary"
							isBusy={ isBusy }
							disabled={ !! reason || isBusy }
							accessibleWhenDisabled
							aria-describedby={ reason ? REASON_ID : undefined }
						>
							{ __( 'Save schedule', 'leitvia-studio' ) }
						</Button>
						{ summary && (
							<Button
								__next40pxDefaultSize
								variant="tertiary"
								isDestructive
								disabled={ isBusy }
								accessibleWhenDisabled
								onClick={ () => setIsConfirming( true ) }
							>
								{ __( 'Remove schedule', 'leitvia-studio' ) }
							</Button>
						) }
						{ /* Always rendered, so the live region announces when saving gets blocked or free again. */ }
						<p
							id={ REASON_ID }
							className="leitvia-studio-tokens__reason"
							aria-live="polite"
						>
							{ reason }
						</p>
					</Flex>
				</Flex>
			</form>
			{ isConfirming && (
				<Modal
					title={ __( 'Remove schedule', 'leitvia-studio' ) }
					onRequestClose={ () => setIsConfirming( false ) }
					size="small"
				>
					<Flex
						direction="column"
						justify="flex-start"
						gap={ 4 }
						align="stretch"
					>
						<p>
							{ __(
								'Remove the overlay schedule of this set? A running overlay ends at once.',
								'leitvia-studio'
							) }
						</p>
						<Flex justify="flex-end">
							<Button
								__next40pxDefaultSize
								variant="tertiary"
								onClick={ () => setIsConfirming( false ) }
							>
								{ __( 'Cancel', 'leitvia-studio' ) }
							</Button>
							<Button
								__next40pxDefaultSize
								variant="primary"
								isDestructive
								onClick={ () => {
									setIsConfirming( false );
									save( null );
								} }
							>
								{ __( 'Remove', 'leitvia-studio' ) }
							</Button>
						</Flex>
					</Flex>
				</Modal>
			) }
		</section>
	);
}
