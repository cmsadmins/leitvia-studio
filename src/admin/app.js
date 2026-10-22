// App shell: page heading, status messages and the screen of the current page.
import { NoticeList, SnackbarList } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';
import { store as noticesStore } from '@wordpress/notices';
import Loading from './loading';
import { loadScreen } from './routes';

function Notices() {
	const notices = useSelect(
		( select ) => select( noticesStore ).getNotices(),
		[]
	);
	const { removeNotice } = useDispatch( noticesStore );
	return (
		<>
			<NoticeList
				notices={ notices.filter(
					( notice ) => 'snackbar' !== notice.type
				) }
				onRemove={ removeNotice }
			/>
			<SnackbarList
				className="leitvia-studio__snackbars"
				notices={ notices.filter(
					( notice ) => 'snackbar' === notice.type
				) }
				onRemove={ removeNotice }
			/>
		</>
	);
}

export default function App( { screen } ) {
	const [ module, setModule ] = useState();
	useEffect( () => {
		loadScreen( screen ).then( setModule );
	}, [ screen ] );
	if ( undefined === module ) {
		return <Loading />;
	}
	if ( ! module ) {
		return null;
	}
	const Screen = module.default;
	return (
		<>
			<h1 className="wp-heading-inline">{ module.title }</h1>
			<hr className="wp-header-end" />
			<Notices />
			<Screen />
		</>
	);
}
