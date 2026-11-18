<?php
/**
 * Stand-in for the class GFAPI of Gravity Forms, which is commercial and not in the repository: the one method Studio
 * calls, for PHPStan (scanFiles) and for tests/php/test-gravity-forms.php, which sets the forms it returns.
 *
 * @package LeitviaStudio
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Squiz.Commenting, Generic.Commenting, Generic.CodeAnalysis.UnusedFunctionParameter

class GFAPI {

	/** @var array<int, array<string, mixed>> */
	public static array $forms = array();

	/** @return array<int, array<string, mixed>> */
	public static function get_forms( $active = true, $trash = false, $sort_column = 'id', $sort_dir = 'ASC' ): array {
		return self::$forms;
	}
}
