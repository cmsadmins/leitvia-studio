<?php
/**
 * Rating: text, clamping, stars hidden from assistive technology, Review node only with item and type.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Blocks\Rating;
use LeitviaStudio\Blocks\Schema_Nodes;

/**
 * Renders the block through do_blocks() and Rating::render().
 */
class Test_Rating extends WP_UnitTestCase {

	/**
	 * Forgets the nodes of the test.
	 */
	public function tear_down(): void {
		Schema_Nodes::reset();
		parent::tear_down();
	}

	/**
	 * The value is text, the stars are hidden and sized by custom properties.
	 */
	public function test_renders_the_value_as_text_and_hides_the_stars(): void {
		$html = do_blocks( '<!-- wp:leitvia/rating {"rating":4.5,"label":"Service"} /-->' );
		$this->assertStringContainsString( 'class="wp-block-leitvia-rating"', $html );
		$this->assertStringContainsString( '<p class="wp-block-leitvia-rating__label">Service</p>', $html );
		$this->assertStringContainsString( '<span class="wp-block-leitvia-rating__text">4.5 out of 5 stars</span>', $html );
		$this->assertStringContainsString( 'style="--leitvia-rating-max:5;--leitvia-rating:90%" aria-hidden="true"', $html );
		$this->assertNull( Schema_Nodes::current() );
	}

	/**
	 * Ratings stay between 0 and the number of stars, in steps of a half.
	 */
	public function test_clamps_and_rounds_to_half_stars(): void {
		$this->assertStringContainsString( '5 out of 5 stars', Rating::render( array( 'rating' => 7 ) ) );
		$this->assertStringContainsString( '3.5 out of 5 stars', Rating::render( array( 'rating' => 3.3 ) ) );
		$this->assertStringContainsString( '0 out of 5 stars', Rating::render( array( 'rating' => -2 ) ) );
		$this->assertStringContainsString(
			'1 out of 1 star',
			Rating::render(
				array(
					'rating' => 1,
					'max'    => 0,
				)
			)
		);
		$this->assertStringContainsString(
			'--leitvia-rating-max:10;--leitvia-rating:30%',
			Rating::render(
				array(
					'rating' => 3,
					'max'    => 10,
				)
			)
		);
	}

	/**
	 * No Review without item name, without type or with a type outside the list.
	 */
	public function test_no_review_without_item_and_valid_type(): void {
		Rating::render(
			array(
				'rating'   => 4,
				'itemName' => 'Course',
			)
		);
		Rating::render(
			array(
				'rating'   => 4,
				'itemType' => 'Course',
			)
		);
		Rating::render(
			array(
				'rating'   => 4,
				'itemName' => 'Acme',
				'itemType' => 'Organization',
			)
		);
		Rating::render(
			array(
				'rating'   => 4,
				'itemName' => 'Course',
				'itemType' => 'Course',
			)
		);
		$this->assertNull( Schema_Nodes::current() );
	}

	/**
	 * A Review node with item, rating, author and text; the page shows the same, escaped.
	 */
	public function test_adds_a_review_node(): void {
		$html = Rating::render(
			array(
				'rating'       => 4.5,
				'itemName'     => 'Web <b>course</b>',
				'itemType'     => 'Course',
				'reviewAuthor' => 'Alex',
				'reviewBody'   => 'Good </script> course',
			)
		);
		$this->assertSame(
			array(
				'@type'        => 'Review',
				'itemReviewed' => array(
					'@type' => 'Course',
					'name'  => 'Web course',
				),
				'reviewRating' => array(
					'@type'       => 'Rating',
					'ratingValue' => 4.5,
					'bestRating'  => 5,
					'worstRating' => 0,
				),
				'author'       => array(
					'@type' => 'Person',
					'name'  => 'Alex',
				),
				'reviewBody'   => 'Good  course',
			),
			Schema_Nodes::current()['@graph'][0]
		);
		// The item name stands in for a missing label, so the reviewed item is visible on the page.
		$this->assertStringContainsString( '<p class="wp-block-leitvia-rating__label">Web &lt;b&gt;course&lt;/b&gt;</p>', $html );
		$this->assertStringContainsString( '<p class="wp-block-leitvia-rating__body">Good &lt;/script&gt; course</p>', $html );
		$this->assertStringContainsString( '<p class="wp-block-leitvia-rating__author">Alex</p>', $html );
	}

	/**
	 * An SEO plugin prints its own structured data.
	 */
	public function test_no_review_with_an_seo_plugin(): void {
		add_filter( 'leitvia_studio_seo_plugin_active', '__return_true' );
		Rating::render(
			array(
				'rating'   => 4,
				'itemName' => 'Book',
				'itemType' => 'Book',
			)
		);
		$this->assertNull( Schema_Nodes::current() );
		remove_all_filters( 'leitvia_studio_seo_plugin_active' );
	}
}
