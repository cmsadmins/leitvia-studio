import { __ } from '@wordpress/i18n';
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, useInnerBlocksProps } from '@wordpress/block-editor';
import metadata from './block.json';
import './style.scss';

const TEMPLATE = [
	[
		'core/list',
		{},
		[
			[
				'core/list-item',
				{ content: __( 'First item', 'leitvia-studio' ) },
			],
			[
				'core/list-item',
				{ content: __( 'Second item', 'leitvia-studio' ) },
			],
			[
				'core/list-item',
				{ content: __( 'Third item', 'leitvia-studio' ) },
			],
		],
	],
];

// The saved list stays still. The view module starts the motion only without reduced motion, shows the button (its
// label comes from the server state, Marquee::state()) and adds an inert, hidden copy of the track for the loop.
function save() {
	return (
		<div
			{ ...useBlockProps.save( {
				'data-wp-interactive': 'leitvia/marquee',
				'data-wp-context': JSON.stringify( {
					running: false,
					paused: false,
				} ),
				'data-wp-init': 'callbacks.init',
				'data-wp-class--is-running': 'context.running',
				'data-wp-class--is-paused': 'context.paused',
			} ) }
		>
			<button
				type="button"
				className="wp-block-leitvia-marquee__toggle wp-element-button"
				hidden
				data-wp-bind--hidden="!context.running"
				data-wp-on--click="actions.toggle"
				data-wp-text="state.label"
			/>
			<div className="wp-block-leitvia-marquee__viewport">
				<div
					{ ...useInnerBlocksProps.save( {
						className: 'wp-block-leitvia-marquee__track',
					} ) }
				/>
			</div>
		</div>
	);
}

registerBlockType( metadata.name, {
	edit: function Edit() {
		return (
			<div { ...useBlockProps() }>
				<div className="wp-block-leitvia-marquee__viewport">
					<div
						{ ...useInnerBlocksProps(
							{ className: 'wp-block-leitvia-marquee__track' },
							{ template: TEMPLATE }
						) }
					/>
				</div>
			</div>
		);
	},
	save,
} );
