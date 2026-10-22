<?php
/**
 * Server rendering of leitvia/query-filter.
 *
 * @package LeitviaStudio
 *
 * @var array<string, mixed> $attributes
 * @var WP_Block             $block
 */

defined( 'ABSPATH' ) || exit;

echo \LeitviaStudio\Blocks\Query_Filter::render( $attributes, $block ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render().
