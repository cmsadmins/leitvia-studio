<?php
/**
 * Date and time format after the site language.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Date_Formats;

/**
 * Date_Formats: the German formats after a switch from English, a format someone chose stays, a second switch moves
 * what Studio set, unknown languages leave the site alone.
 */
class Test_Date_Formats extends WP_UnitTestCase {

	/**
	 * An English site with the defaults of WordPress that Studio handled already.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( 'WPLANG', '' );
		update_option( 'date_format', 'F j, Y' );
		update_option( 'time_format', 'g:i a' );
		delete_option( Date_Formats::OPTION );
		Date_Formats::maybe_apply();
	}

	/**
	 * German after English: j. F Y and H:i; nothing changes while the language stays.
	 */
	public function test_german_after_english(): void {
		$this->assertSame( 'F j, Y', get_option( 'date_format' ) );
		update_option( 'WPLANG', 'de_DE' );
		Date_Formats::maybe_apply();
		$this->assertSame( 'j. F Y', get_option( 'date_format' ) );
		$this->assertSame( 'H:i', get_option( 'time_format' ) );

		update_option( 'date_format', 'd.m.Y' );
		Date_Formats::maybe_apply();
		$this->assertSame( 'd.m.Y', get_option( 'date_format' ) );
	}

	/**
	 * A format someone set stays on a switch; the one Studio set follows the language.
	 */
	public function test_chosen_format_stays(): void {
		update_option( 'date_format', 'Y-m-d' );
		update_option( 'WPLANG', 'de_DE' );
		Date_Formats::maybe_apply();
		$this->assertSame( 'Y-m-d', get_option( 'date_format' ) );
		$this->assertSame( 'H:i', get_option( 'time_format' ) );

		update_option( 'WPLANG', 'en_GB' );
		Date_Formats::maybe_apply();
		$this->assertSame( 'Y-m-d', get_option( 'date_format' ) );
		$this->assertSame( 'H:i', get_option( 'time_format' ) );

		update_option( 'WPLANG', '' );
		Date_Formats::maybe_apply();
		$this->assertSame( 'g:i a', get_option( 'time_format' ) );
	}

	/**
	 * A language without a table entry and without language files keeps the formats; the filter can name some.
	 */
	public function test_unknown_language_and_filter(): void {
		$this->assertNull( Date_Formats::formats( 'xx_XX' ) );
		update_option( 'WPLANG', 'xx_XX' );
		Date_Formats::maybe_apply();
		$this->assertSame( 'F j, Y', get_option( 'date_format' ) );

		$filter = static fn( $formats, string $locale ) => 'xx_XX' === $locale ? array(
			'date' => 'j/n/Y',
			'time' => 'G:i',
		) : $formats;
		add_filter( 'leitvia_studio_date_formats', $filter, 10, 2 );
		$this->assertSame( 'j/n/Y', Date_Formats::formats( 'xx_XX' )['date'] );
		remove_filter( 'leitvia_studio_date_formats', $filter );
	}
}
