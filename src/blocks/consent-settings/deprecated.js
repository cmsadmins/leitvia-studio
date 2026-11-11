import { RichText, useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';

// Version 0.1.0 saved the texts in the language of the editor. They match where that language is the one of the
// current editor, and migrate to the English texts that Consent::filter_settings_block() translates.
const v1 = {
	attributes: metadata.attributes,
	supports: metadata.supports,
	save( { attributes } ) {
		// translators: %s: provider name, for example YouTube.
		const revoked = __( '%s is no longer allowed.', 'leitvia-studio' );
		return (
			<div
				{ ...useBlockProps.save() }
				data-wp-interactive="leitvia/consent"
				data-wp-init="callbacks.list"
				data-wp-context={ JSON.stringify( { revoked } ) }
			>
				<RichText.Content
					tagName="p"
					className="wp-block-leitvia-consent-settings__text"
					value={ attributes.text }
				/>
				<div className="wp-block-leitvia-consent-settings__state">
					<p data-wp-bind--hidden="!state.noScript">
						{ __(
							'Without JavaScript, this site stores no consent: content from other providers stays a link.',
							'leitvia-studio'
						) }
					</p>
					<div hidden data-wp-bind--hidden="state.noScript">
						<p data-wp-bind--hidden="state.hasList">
							{ __(
								'You have not allowed any provider on this site in this browser.',
								'leitvia-studio'
							) }
						</p>
						<ul
							className="wp-block-leitvia-consent-settings__list"
							hidden
							data-wp-bind--hidden="!state.hasList"
						>
							<template
								data-wp-each="state.list"
								data-wp-each-key="context.item.key"
							>
								<li>
									<span data-wp-text="context.item.label"></span>{ ' ' }
									<button
										type="button"
										className="wp-element-button"
										data-wp-on--click="actions.revoke"
									>
										{ __( 'Revoke', 'leitvia-studio' ) }{ ' ' }
										<span
											className="screen-reader-text"
											data-wp-text="context.item.label"
										></span>
									</button>
								</li>
							</template>
						</ul>
						<p role="status" data-wp-text="state.status"></p>
					</div>
				</div>
			</div>
		);
	},
};

export default [ v1 ];
