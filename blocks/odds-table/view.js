/**
 * Front-end script of the odds table block (loaded as a script module, and only
 * on pages that contain the block).
 *
 * The server renders a loading placeholder with a signed configuration; this
 * script asks the REST API for the odds and draws the table. A 202 answer means
 * another request is fetching right now, so it asks again shortly.
 */
import {
	loadFormat,
	renderLoading,
	renderMessage,
	renderTable,
	saveFormat,
} from './table';

const MAX_ATTEMPTS = 10;

function init( root ) {
	if ( root.dataset.oddscmpReady ) {
		return;
	}
	root.dataset.oddscmpReady = '1';

	const t = JSON.parse( root.dataset.i18n || '{}' );
	const url = new URL( root.dataset.endpoint, window.location.href );
	url.searchParams.set( 'token', root.dataset.token );
	url.searchParams.set( 'sig', root.dataset.sig );

	let data = null;
	let format = loadFormat() || root.dataset.format;

	const draw = () => {
		if ( ! data ) {
			return;
		}
		// Fall back to the site default if the remembered format no longer exists.
		if ( ! data.formats[ format ] ) {
			format =
				root.dataset.format in data.formats
					? root.dataset.format
					: Object.keys( data.formats )[ 0 ];
		}
		renderTable( root, data, {
			format,
			highlight: root.dataset.highlight === '1',
			showSwitch: root.dataset.switch === '1',
			i18n: t,
			onFormat: ( next ) => {
				format = next;
				saveFormat( next );
				draw();
			},
		} );
	};

	const load = async ( attempt = 0 ) => {
		try {
			const response = await fetch( url );

			if ( response.status === 202 && attempt < MAX_ATTEMPTS ) {
				const { retryAfter = 2 } = await response.json();
				window.setTimeout(
					() => load( attempt + 1 ),
					Math.max( 1, retryAfter ) * 1000
				);

				return;
			}

			if ( ! response.ok ) {
				throw new Error( `HTTP ${ response.status }` );
			}

			data = await response.json();
			if ( data.status === 'pending' ) {
				throw new Error( 'Still loading' );
			}
			draw();
		} catch {
			renderMessage( root, t.error, 'warn' );
		}
	};

	renderLoading( root, t.loading || '' );
	load();
}

const start = () =>
	document.querySelectorAll( '[data-oddscmp]' ).forEach( init );

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', start );
} else {
	start();
}
