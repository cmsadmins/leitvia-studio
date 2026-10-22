<?php
/**
 * Server rendering of leitvia/load-more.
 *
 * @package LeitviaStudio
 *
 * @var array<string, mixed> $attributes
 * @var WP_Block             $block
 */

defined( 'ABSPATH' ) || exit;

echo \LeitviaStudio\Blocks\Query_Filter::render_load_more( $attributes, $block ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_load_more().
