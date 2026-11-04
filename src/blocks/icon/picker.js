// Icon set and picker of the icon blocks (leitvia/icon, leitvia/icon-list, leitvia/icon-list-item). The set is the
// same file the server draws from (assets/icons/lucide.json, Icon::svg()); the picker is a radio group, so it takes one
// Tab stop, the arrow keys move through the icons and each icon has its name as label.
import { __, _n, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { useInstanceId } from '@wordpress/compose';
import { SearchControl } from '@wordpress/components';
import ICONS from '../../../assets/icons/lucide.json';

const TITLES = {
	accessibility: __( 'Accessibility', 'leitvia-studio' ),
	'arrow-right': __( 'Arrow right', 'leitvia-studio' ),
	'at-sign': __( 'At sign', 'leitvia-studio' ),
	award: __( 'Award', 'leitvia-studio' ),
	'badge-check': __( 'Verified badge', 'leitvia-studio' ),
	briefcase: __( 'Briefcase', 'leitvia-studio' ),
	'building-2': __( 'Office building', 'leitvia-studio' ),
	calendar: __( 'Calendar', 'leitvia-studio' ),
	camera: __( 'Camera', 'leitvia-studio' ),
	car: __( 'Car', 'leitvia-studio' ),
	'chart-bar': __( 'Bar chart', 'leitvia-studio' ),
	check: __( 'Check mark', 'leitvia-studio' ),
	'chevron-right': __( 'Chevron right', 'leitvia-studio' ),
	'circle-check': __( 'Check mark in circle', 'leitvia-studio' ),
	'circle-help': __( 'Question mark', 'leitvia-studio' ),
	'circle-x': __( 'Cross in circle', 'leitvia-studio' ),
	'clipboard-check': __( 'Checked clipboard', 'leitvia-studio' ),
	clock: __( 'Clock', 'leitvia-studio' ),
	coffee: __( 'Coffee', 'leitvia-studio' ),
	'credit-card': __( 'Credit card', 'leitvia-studio' ),
	download: __( 'Download', 'leitvia-studio' ),
	euro: __( 'Euro', 'leitvia-studio' ),
	'external-link': __( 'External link', 'leitvia-studio' ),
	eye: __( 'Eye', 'leitvia-studio' ),
	'file-text': __( 'Document', 'leitvia-studio' ),
	gift: __( 'Gift', 'leitvia-studio' ),
	globe: __( 'Globe', 'leitvia-studio' ),
	'graduation-cap': __( 'Graduation cap', 'leitvia-studio' ),
	hammer: __( 'Hammer', 'leitvia-studio' ),
	handshake: __( 'Handshake', 'leitvia-studio' ),
	headphones: __( 'Headphones', 'leitvia-studio' ),
	heart: __( 'Heart', 'leitvia-studio' ),
	house: __( 'House', 'leitvia-studio' ),
	info: __( 'Information', 'leitvia-studio' ),
	landmark: __( 'Public building', 'leitvia-studio' ),
	leaf: __( 'Leaf', 'leitvia-studio' ),
	lightbulb: __( 'Light bulb', 'leitvia-studio' ),
	link: __( 'Link', 'leitvia-studio' ),
	lock: __( 'Lock', 'leitvia-studio' ),
	mail: __( 'Email', 'leitvia-studio' ),
	'map-pin': __( 'Map pin', 'leitvia-studio' ),
	'message-circle': __( 'Speech bubble', 'leitvia-studio' ),
	minus: __( 'Minus', 'leitvia-studio' ),
	monitor: __( 'Monitor', 'leitvia-studio' ),
	moon: __( 'Moon', 'leitvia-studio' ),
	package: __( 'Parcel', 'leitvia-studio' ),
	percent: __( 'Percent', 'leitvia-studio' ),
	phone: __( 'Phone', 'leitvia-studio' ),
	plus: __( 'Plus', 'leitvia-studio' ),
	quote: __( 'Quote', 'leitvia-studio' ),
	recycle: __( 'Recycling', 'leitvia-studio' ),
	rocket: __( 'Rocket', 'leitvia-studio' ),
	scale: __( 'Scales', 'leitvia-studio' ),
	search: __( 'Search', 'leitvia-studio' ),
	send: __( 'Send', 'leitvia-studio' ),
	settings: __( 'Settings', 'leitvia-studio' ),
	'shield-check': __( 'Shield with check mark', 'leitvia-studio' ),
	'shopping-cart': __( 'Shopping cart', 'leitvia-studio' ),
	smartphone: __( 'Smartphone', 'leitvia-studio' ),
	sparkles: __( 'Sparkles', 'leitvia-studio' ),
	sprout: __( 'Sprout', 'leitvia-studio' ),
	star: __( 'Star', 'leitvia-studio' ),
	stethoscope: __( 'Stethoscope', 'leitvia-studio' ),
	sun: __( 'Sun', 'leitvia-studio' ),
	target: __( 'Target', 'leitvia-studio' ),
	'thumbs-up': __( 'Thumbs up', 'leitvia-studio' ),
	'triangle-alert': __( 'Warning', 'leitvia-studio' ),
	truck: __( 'Truck', 'leitvia-studio' ),
	user: __( 'Person', 'leitvia-studio' ),
	users: __( 'People', 'leitvia-studio' ),
	utensils: __( 'Knife and fork', 'leitvia-studio' ),
	wallet: __( 'Wallet', 'leitvia-studio' ),
	wifi: __( 'Wi-Fi', 'leitvia-studio' ),
	wrench: __( 'Wrench', 'leitvia-studio' ),
	x: __( 'Cross', 'leitvia-studio' ),
	zap: __( 'Lightning', 'leitvia-studio' ),
};

const title = ( name ) => TITLES[ name ] ?? name;

/**
 * An icon of the set as decorative SVG, drawn like Icon::svg() on the server. The markup comes from the bundled file.
 *
 * @param {Object} props
 * @param {string} props.name Icon name.
 */
export function Svg( { name } ) {
	return ICONS[ name ] ? (
		<svg
			className="leitvia-icon"
			viewBox="0 0 24 24"
			width="24"
			height="24"
			fill="none"
			stroke="currentColor"
			strokeWidth="2"
			strokeLinecap="round"
			strokeLinejoin="round"
			aria-hidden="true"
			focusable="false"
			dangerouslySetInnerHTML={ { __html: ICONS[ name ] } }
		/>
	) : null;
}

/**
 * Searchable grid of radio buttons, one per icon.
 *
 * @param {Object}                 props
 * @param {string}                 props.label    Legend of the group.
 * @param {string}                 props.value    Selected icon, undefined for none.
 * @param {(name: string) => void} props.onChange Called with the chosen name.
 */
export function IconPicker( { label, value, onChange } ) {
	const [ search, setSearch ] = useState( '' );
	const group = useInstanceId( IconPicker, 'leitvia-icon-picker' );
	const query = search.trim().toLowerCase();
	const shown = Object.keys( ICONS ).filter(
		( name ) =>
			! query ||
			name.includes( query ) ||
			title( name ).toLowerCase().includes( query )
	);
	return (
		<fieldset className="leitvia-icon-picker">
			<legend>{ label }</legend>
			<SearchControl
				__nextHasNoMarginBottom
				label={ __( 'Search icons', 'leitvia-studio' ) }
				value={ search }
				onChange={ setSearch }
			/>
			<p className="leitvia-icon-picker__status" role="status">
				{ query &&
					sprintf(
						/* translators: %d: number of icons that match the search. */
						_n(
							'%d icon found',
							'%d icons found',
							shown.length,
							'leitvia-studio'
						),
						shown.length
					) }
			</p>
			<div className="leitvia-icon-picker__grid">
				{ shown.map( ( name ) => (
					// eslint-disable-next-line jsx-a11y/label-has-associated-control -- the input is nested in the label.
					<label key={ name } className="leitvia-icon-picker__option">
						<input
							type="radio"
							name={ group }
							value={ name }
							checked={ value === name }
							onChange={ () => onChange( name ) }
						/>
						<Svg name={ name } />
						<span>{ title( name ) }</span>
					</label>
				) ) }
			</div>
		</fieldset>
	);
}
