import { RichText, useBlockProps } from '@wordpress/block-editor';

/**
 * Link to a point on openstreetmap.org, as Map::link() builds it on the server.
 *
 * @param {number} lat  Latitude.
 * @param {number} lng  Longitude.
 * @param {number} zoom Zoom level.
 * @return {string} URL.
 */
export const osmLink = ( lat, lng, zoom ) => {
	const round = ( value ) => Math.round( value * 1e6 ) / 1e6;
	return `https://www.openstreetmap.org/?mlat=${ round( lat ) }&mlon=${ round(
		lng
	) }#map=${ zoom }/${ round( lat ) }/${ round( lng ) }`;
};

// The saved HTML requests nothing: an optional heading and the places with their addresses and a link each. The
// server adds the map behind the consent placeholder (includes/blocks/class-map.php).
export default function save( {
	attributes: { heading, markers, zoom, height, linkText },
} ) {
	return (
		<div
			{ ...useBlockProps.save( {
				className: `is-height-${ height }`,
			} ) }
		>
			{ ! RichText.isEmpty( heading ) && (
				<RichText.Content
					tagName="h2"
					className="wp-block-leitvia-map__heading"
					value={ heading }
				/>
			) }
			{ markers.length > 0 && (
				<ul className="wp-block-leitvia-map__places">
					{ markers.map( ( marker, index ) => (
						<li key={ index }>
							<strong>{ marker.title }</strong>
							{ marker.address && (
								<span>{ marker.address }</span>
							) }
							<a href={ osmLink( marker.lat, marker.lng, zoom ) }>
								{ linkText }
							</a>
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}
