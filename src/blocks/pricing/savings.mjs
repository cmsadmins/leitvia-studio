// Pure number helpers for the pricing and comparison table blocks. The editor calls them when an attribute changes and
// stores the result as text, so the saved HTML never depends on the browser that renders it.

/**
 * Rounds to cents. Number.EPSILON keeps 1.005 from rounding down.
 *
 * @param {number} value Amount.
 * @return {number} Amount in whole cents.
 */
const cents = ( value ) => Math.round( ( value + Number.EPSILON ) * 100 ) / 100;

/**
 * Formats an amount in the site language: whole amounts without decimals, others with two.
 *
 * @param {number} value    Amount.
 * @param {string} currency ISO 4217 code, for example EUR.
 * @param {string} locale   BCP 47 tag of the site language, for example de-DE.
 * @return {string} Formatted amount, for example "9,90 €".
 */
export function formatMoney( value, currency, locale ) {
	return new Intl.NumberFormat( locale, {
		style: 'currency',
		currency,
		trailingZeroDisplay: 'stripIfInteger',
	} ).format( cents( value ) );
}

/**
 * Both prices of a plan and the saving of the yearly price against twelve monthly prices.
 *
 * @param {Object} plan          Plan.
 * @param {number} plan.monthly  Price per month.
 * @param {number} plan.yearly   Price per year.
 * @param {string} plan.currency ISO 4217 code.
 * @param {string} plan.locale   BCP 47 tag.
 * @return {{monthly: string, yearly: string, savings: string}} Formatted amounts; savings is empty without a saving.
 */
export function prices( { monthly, yearly, currency, locale } ) {
	const saving = cents( monthly * 12 - yearly );
	return {
		monthly: formatMoney( monthly, currency, locale ),
		yearly: formatMoney( yearly, currency, locale ),
		savings: saving > 0 ? formatMoney( saving, currency, locale ) : '',
	};
}

/**
 * Reads a number from a table cell such as "€1,200.50" or "1.200,50 €" in the site language.
 *
 * @param {string} text   Cell text.
 * @param {string} locale BCP 47 tag.
 * @return {number} The number, NaN if the cell holds none.
 */
export function parseNumber( text, locale ) {
	const decimal = new Intl.NumberFormat( locale )
		.formatToParts( 1.1 )
		.find( ( part ) => 'decimal' === part.type ).value;
	const plain = String( text )
		.replace(
			new RegExp( `[^0-9\-${ decimal.replace( /[.]/, '\.' ) }]`, 'g' ),
			''
		)
		.replace( decimal, '.' );
	return '' === plain ? NaN : Number( plain );
}

/**
 * Column A divided by column B, rounded, in the site language, with the unit after a space.
 *
 * @param {string} a                Cell of column A.
 * @param {string} b                Cell of column B.
 * @param {Object} options          Options.
 * @param {number} options.decimals Decimal places.
 * @param {string} options.unit     Unit, may be empty.
 * @param {string} options.locale   BCP 47 tag.
 * @return {string} The quotient, or an en dash if a cell holds no number or B is 0.
 */
export function ratio( a, b, { decimals = 2, unit = '', locale } ) {
	const quotient = parseNumber( a, locale ) / parseNumber( b, locale );
	if ( ! Number.isFinite( quotient ) ) {
		return '–';
	}
	const text = new Intl.NumberFormat( locale, {
		minimumFractionDigits: decimals,
		maximumFractionDigits: decimals,
	} ).format( quotient );
	return unit ? `${ text } ${ unit }` : text;
}
