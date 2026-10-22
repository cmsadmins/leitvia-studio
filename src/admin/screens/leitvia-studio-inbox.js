// Inbox screen: the change packages of agents, or one package with ?package=<id>.
import { __ } from '@wordpress/i18n';
import { getQueryArg } from '@wordpress/url';
import InboxList from '../inbox/list';
import InboxDetail from '../inbox/detail';
import '../inbox/style.scss';

export const title = __( 'Inbox', 'leitvia-studio' );

export default function Inbox() {
	const id = Number( getQueryArg( window.location.href, 'package' ) );
	return id > 0 ? <InboxDetail id={ id } /> : <InboxList />;
}
