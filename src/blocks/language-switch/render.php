<?php
/**
 * Front end of leitvia/language-switch, see class-easy-language.php.
 *
 * @package LeitviaStudio
 *
 * @var WP_Block $block Block instance.
 */

defined( 'ABSPATH' ) || exit;

echo LeitviaStudio\Easy_Language\Easy_Language::render_switch( $block ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in render_switch().
