<?php
/**
 * Front end of leitvia/table-of-contents, see class-table-of-contents.php.
 *
 * @package LeitviaStudio
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var WP_Block             $block      Block instance.
 */

defined( 'ABSPATH' ) || exit;

echo LeitviaStudio\Blocks\Table_Of_Contents::render( $attributes, $block ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in render().
