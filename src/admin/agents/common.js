// Labels of the autonomy settings, shared by the Agents screen and the Inbox.
import { __ } from '@wordpress/i18n';

export const LEVELS = {
	locked: __( 'Locked', 'leitvia-studio' ),
	suggest: __( 'Suggest', 'leitvia-studio' ),
	draft: __( 'Draft', 'leitvia-studio' ),
	autonomous: __( 'Autonomous', 'leitvia-studio' ),
};

export const AREAS = {
	content: __( 'Content', 'leitvia-studio' ),
	corrections: __( 'Corrections', 'leitvia-studio' ),
	structure: __( 'Structure', 'leitvia-studio' ),
	design: __( 'Design', 'leitvia-studio' ),
	admin: __( 'Administration', 'leitvia-studio' ),
	products: __( 'Products', 'leitvia-studio' ),
};

export const KINDS = {
	prices: __( 'Prices', 'leitvia-studio' ),
	legal: __( 'Legal texts', 'leitvia-studio' ),
	slugs: __( 'Slugs', 'leitvia-studio' ),
	organization: __( 'Organization data', 'leitvia-studio' ),
	tax: __( 'Taxes', 'leitvia-studio' ),
	shipping: __( 'Shipping', 'leitvia-studio' ),
};
