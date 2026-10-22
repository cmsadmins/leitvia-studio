// Tokens screen: the list of token sets, or the editor of one set with ?set=<id>.
import { __ } from '@wordpress/i18n';
import { getQueryArg } from '@wordpress/url';
import List from '../../tokens/list';
import Editor from '../../tokens/editor';
import '../../tokens/style.scss';

export const title = __( 'Tokens', 'leitvia-studio' );

export default function Tokens() {
	const id = Number( getQueryArg( window.location.href, 'set' ) );
	return id > 0 ? <Editor id={ id } /> : <List />;
}
