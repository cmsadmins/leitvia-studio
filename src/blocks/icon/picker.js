// Icon set and picker of the icon blocks (leitvia/icon, leitvia/icon-list, leitvia/icon-list-item). The set is the
// same file the server draws from (assets/icons/lucide.json, Icon::svg()) plus the icons of the filter
// leitvia_studio_icons, which the server hands over as window.leitviaStudioIcons (Icon::editor_data()); a bundled name
// always wins. The picker is a radio group, so it takes one Tab stop, the arrow keys move through the icons and each
// icon has its name as label.
import { __, _n, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { useInstanceId } from '@wordpress/compose';
import { SearchControl } from '@wordpress/components';
import ICONS from '../../../assets/icons/lucide.json';

const TITLES = {
	accessibility: __( 'Accessibility', 'leitvia-studio' ),
	activity: __( 'Activity', 'leitvia-studio' ),
	'arrow-right': __( 'Arrow right', 'leitvia-studio' ),
	'at-sign': __( 'At sign', 'leitvia-studio' ),
	award: __( 'Award', 'leitvia-studio' ),
	'badge-check': __( 'Verified badge', 'leitvia-studio' ),
	'book-open': __( 'Open book', 'leitvia-studio' ),
	briefcase: __( 'Briefcase', 'leitvia-studio' ),
	'building-2': __( 'Office building', 'leitvia-studio' ),
	'cake-slice': __( 'Slice of cake', 'leitvia-studio' ),
	calendar: __( 'Calendar', 'leitvia-studio' ),
	camera: __( 'Camera', 'leitvia-studio' ),
	car: __( 'Car', 'leitvia-studio' ),
	'chart-bar': __( 'Bar chart', 'leitvia-studio' ),
	check: __( 'Check mark', 'leitvia-studio' ),
	'chef-hat': __( 'Chef hat', 'leitvia-studio' ),
	'chevron-right': __( 'Chevron right', 'leitvia-studio' ),
	'circle-check': __( 'Check mark in circle', 'leitvia-studio' ),
	'circle-help': __( 'Question mark', 'leitvia-studio' ),
	'circle-x': __( 'Cross in circle', 'leitvia-studio' ),
	'clipboard-check': __( 'Checked clipboard', 'leitvia-studio' ),
	clock: __( 'Clock', 'leitvia-studio' ),
	cloud: __( 'Cloud', 'leitvia-studio' ),
	code: __( 'Code', 'leitvia-studio' ),
	coffee: __( 'Coffee', 'leitvia-studio' ),
	'credit-card': __( 'Credit card', 'leitvia-studio' ),
	database: __( 'Database', 'leitvia-studio' ),
	download: __( 'Download', 'leitvia-studio' ),
	drill: __( 'Drill', 'leitvia-studio' ),
	ear: __( 'Ear', 'leitvia-studio' ),
	euro: __( 'Euro', 'leitvia-studio' ),
	'external-link': __( 'External link', 'leitvia-studio' ),
	eye: __( 'Eye', 'leitvia-studio' ),
	'file-text': __( 'Document', 'leitvia-studio' ),
	flag: __( 'Flag', 'leitvia-studio' ),
	footprints: __( 'Footprints', 'leitvia-studio' ),
	gavel: __( 'Gavel', 'leitvia-studio' ),
	gift: __( 'Gift', 'leitvia-studio' ),
	globe: __( 'Globe', 'leitvia-studio' ),
	'graduation-cap': __( 'Graduation cap', 'leitvia-studio' ),
	hammer: __( 'Hammer', 'leitvia-studio' ),
	hand: __( 'Hand', 'leitvia-studio' ),
	handshake: __( 'Handshake', 'leitvia-studio' ),
	'hard-hat': __( 'Hard hat', 'leitvia-studio' ),
	headphones: __( 'Headphones', 'leitvia-studio' ),
	heart: __( 'Heart', 'leitvia-studio' ),
	'heart-pulse': __( 'Heart with pulse', 'leitvia-studio' ),
	house: __( 'House', 'leitvia-studio' ),
	'id-card': __( 'ID card', 'leitvia-studio' ),
	image: __( 'Image', 'leitvia-studio' ),
	info: __( 'Information', 'leitvia-studio' ),
	landmark: __( 'Public building', 'leitvia-studio' ),
	languages: __( 'Languages', 'leitvia-studio' ),
	leaf: __( 'Leaf', 'leitvia-studio' ),
	lightbulb: __( 'Light bulb', 'leitvia-studio' ),
	link: __( 'Link', 'leitvia-studio' ),
	lock: __( 'Lock', 'leitvia-studio' ),
	mail: __( 'Email', 'leitvia-studio' ),
	'map-pin': __( 'Map pin', 'leitvia-studio' ),
	'message-circle': __( 'Speech bubble', 'leitvia-studio' ),
	mic: __( 'Microphone', 'leitvia-studio' ),
	minus: __( 'Minus', 'leitvia-studio' ),
	monitor: __( 'Monitor', 'leitvia-studio' ),
	moon: __( 'Moon', 'leitvia-studio' ),
	newspaper: __( 'Newspaper', 'leitvia-studio' ),
	package: __( 'Parcel', 'leitvia-studio' ),
	paintbrush: __( 'Paintbrush', 'leitvia-studio' ),
	'party-popper': __( 'Party popper', 'leitvia-studio' ),
	percent: __( 'Percent', 'leitvia-studio' ),
	phone: __( 'Phone', 'leitvia-studio' ),
	pill: __( 'Pill', 'leitvia-studio' ),
	pizza: __( 'Pizza', 'leitvia-studio' ),
	plug: __( 'Plug', 'leitvia-studio' ),
	plus: __( 'Plus', 'leitvia-studio' ),
	quote: __( 'Quote', 'leitvia-studio' ),
	receipt: __( 'Receipt', 'leitvia-studio' ),
	recycle: __( 'Recycling', 'leitvia-studio' ),
	rocket: __( 'Rocket', 'leitvia-studio' ),
	ruler: __( 'Ruler', 'leitvia-studio' ),
	scale: __( 'Scales', 'leitvia-studio' ),
	search: __( 'Search', 'leitvia-studio' ),
	send: __( 'Send', 'leitvia-studio' ),
	server: __( 'Server', 'leitvia-studio' ),
	settings: __( 'Settings', 'leitvia-studio' ),
	'shield-check': __( 'Shield with check mark', 'leitvia-studio' ),
	ship: __( 'Ship', 'leitvia-studio' ),
	'shopping-bag': __( 'Shopping bag', 'leitvia-studio' ),
	'shopping-cart': __( 'Shopping cart', 'leitvia-studio' ),
	smartphone: __( 'Smartphone', 'leitvia-studio' ),
	sparkles: __( 'Sparkles', 'leitvia-studio' ),
	sprout: __( 'Sprout', 'leitvia-studio' ),
	stamp: __( 'Stamp', 'leitvia-studio' ),
	star: __( 'Star', 'leitvia-studio' ),
	stethoscope: __( 'Stethoscope', 'leitvia-studio' ),
	store: __( 'Shop', 'leitvia-studio' ),
	sun: __( 'Sun', 'leitvia-studio' ),
	syringe: __( 'Syringe', 'leitvia-studio' ),
	tag: __( 'Price tag', 'leitvia-studio' ),
	target: __( 'Target', 'leitvia-studio' ),
	terminal: __( 'Terminal', 'leitvia-studio' ),
	'thumbs-up': __( 'Thumbs up', 'leitvia-studio' ),
	'triangle-alert': __( 'Warning', 'leitvia-studio' ),
	trophy: __( 'Trophy', 'leitvia-studio' ),
	truck: __( 'Truck', 'leitvia-studio' ),
	user: __( 'Person', 'leitvia-studio' ),
	users: __( 'People', 'leitvia-studio' ),
	utensils: __( 'Knife and fork', 'leitvia-studio' ),
	video: __( 'Video', 'leitvia-studio' ),
	wallet: __( 'Wallet', 'leitvia-studio' ),
	wifi: __( 'Wi-Fi', 'leitvia-studio' ),
	wine: __( 'Wine glass', 'leitvia-studio' ),
	wrench: __( 'Wrench', 'leitvia-studio' ),
	x: __( 'Cross', 'leitvia-studio' ),
	zap: __( 'Lightning', 'leitvia-studio' ),
};

// Icons of the site by name: { svg, viewBox, paint }. Read when used, so the order of the scripts does not matter.
const custom = () =>
	typeof window !== 'undefined' && window.leitviaStudioIcons
		? window.leitviaStudioIcons
		: {};

// Own keys only, so a name such as constructor never reaches the prototype.
const own = ( set, name ) => Object.hasOwn( set, name );

const icon = ( name ) => {
	if ( own( ICONS, name ) ) {
		return { svg: ICONS[ name ], viewBox: '0 0 24 24', paint: 'stroke' };
	}
	return own( custom(), name ) ? custom()[ name ] : undefined;
};

// Titles of the icons of the site are their names, hyphens as spaces.
const title = ( name ) =>
	own( TITLES, name ) ? TITLES[ name ] : name.replace( /-/g, ' ' );

const round = ( value ) => String( Math.round( value * 1e4 ) / 1e4 );
// Width of an icon 24 high, as Icon::width(): wide icons keep their proportion.
const width = ( viewBox ) => {
	const [ , , w, h ] = viewBox.split( ' ' ).map( Number );
	return w > 0 && h > 0 ? round( ( 24 * w ) / h ) : '24';
};
// Stroke attributes like Icon::svg(): width 2 on a grid 24 high, in proportion on another viewBox.
const stroke = ( viewBox ) => ( {
	fill: 'none',
	stroke: 'currentColor',
	strokeWidth: round(
		( 2 * ( Number( viewBox.split( ' ' )[ 3 ] ) || 24 ) ) / 24
	),
	strokeLinecap: 'round',
	strokeLinejoin: 'round',
} );

/**
 * An icon of the set as decorative SVG, drawn like Icon::svg() on the server. The markup comes from the bundled file
 * or from the server, which only hands over icons an add-on cleaned.
 *
 * @param {Object} props
 * @param {string} props.name Icon name.
 */
export function Svg( { name } ) {
	const found = icon( name );
	return found ? (
		<svg
			className={ `leitvia-icon leitvia-icon--${ name }` }
			viewBox={ found.viewBox }
			width={ width( found.viewBox ) }
			height="24"
			{ ...( found.paint === 'fill'
				? { fill: 'currentColor' }
				: stroke( found.viewBox ) ) }
			aria-hidden="true"
			focusable="false"
			dangerouslySetInnerHTML={ { __html: found.svg } }
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
	const names = [
		...Object.keys( ICONS ),
		...Object.keys( custom() ).filter( ( name ) => ! own( ICONS, name ) ),
	];
	const shown = names.filter(
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
