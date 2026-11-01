// TabPanel of @wordpress/components whose selected tab lives in ?tab= of the URL, so a reload or a link from another
// screen opens the same tab. The URL changes with history.replaceState, without a new history entry. A tab change keeps
// only page and tab, so a detail such as ?package= of the Inbox does not open again on the way back.
import { TabPanel } from '@wordpress/components';
import { addQueryArgs, getQueryArg } from '@wordpress/url';

/**
 * @param {Object}                                     props
 * @param {Array<{name: string}>}                      props.tabs      Tabs as TabPanel takes them.
 * @param {string}                                     props.className Class of the TabPanel.
 * @param {(tab: Object) => import('react').ReactNode} props.children  Content of the selected tab.
 */
export default function Tabs( { tabs, className, children } ) {
	const current = getQueryArg( window.location.href, 'tab' );
	return (
		<TabPanel
			className={ [ 'leitvia-studio-tabs', className ]
				.filter( Boolean )
				.join( ' ' ) }
			tabs={ tabs }
			initialTabName={
				tabs.some( ( tab ) => tab.name === current )
					? current
					: undefined
			}
			onSelect={ ( name ) => {
				// TabPanel also calls this on mount; the URL of the open tab (with its ?package=) stays as it is.
				if ( name === getQueryArg( window.location.href, 'tab' ) ) {
					return;
				}
				window.history.replaceState(
					window.history.state,
					'',
					addQueryArgs( window.location.pathname, {
						page: getQueryArg( window.location.href, 'page' ),
						tab: name,
					} )
				);
			} }
		>
			{ children }
		</TabPanel>
	);
}
