import { createRoot } from '@wordpress/element';

import App from './components/App';
import './style.scss';

const rootElement = document.getElementById( 'edd-composer-admin' );

if ( rootElement ) {
	createRoot( rootElement ).render( <App /> );
}
