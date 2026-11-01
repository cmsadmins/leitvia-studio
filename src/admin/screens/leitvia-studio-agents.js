// Agents screen: the status and access of agents, the Inbox of their changes, and the autonomy settings of the gateway.
import { __ } from '@wordpress/i18n';
import AgentsScreen from '../agents/screen';
import '../inbox/style.scss';

export const title = __( 'Agents', 'leitvia-studio' );

export const intro = __(
	'Give AI agents access to this site, decide what they may change on their own, and review what they propose in the Inbox.',
	'leitvia-studio'
);

export default AgentsScreen;
