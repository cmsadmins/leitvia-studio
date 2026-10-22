// Announces how many features the plan filter leaves visible. The filter itself is CSS (style.scss).
for ( const group of document.querySelectorAll(
	'.wp-block-leitvia-feature-group'
) ) {
	const status = group.querySelector(
		'.wp-block-leitvia-feature-group__status'
	);
	group
		.querySelector( '.wp-block-leitvia-feature-group__filter' )
		?.addEventListener( 'change', () => {
			const shown = [
				...group.querySelectorAll( '.wp-block-leitvia-feature-item' ),
			].filter( ( item ) => item.checkVisibility() ).length;
			status.textContent = (
				1 === shown ? status.dataset.one : status.dataset.other
			).replace( '%d', shown );
		} );
}
