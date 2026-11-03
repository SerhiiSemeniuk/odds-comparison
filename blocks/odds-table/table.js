/**
 * Odds table renderer shared by the editor preview and the front-end script.
 *
 * It builds the DOM with createElement/textContent only (never innerHTML), so
 * data from the REST API can not inject markup.
 */

const FORMAT_KEY = 'oddscmp_format';

/**
 * Create an element.
 *
 * @param {string}                  tag      Tag name.
 * @param {Object}                  attrs    Attributes; `class` and `text` are special.
 * @param {Array<Node|string|null>} children Child nodes or text.
 * @return {HTMLElement} The element.
 */
const el = ( tag, attrs = {}, children = [] ) => {
	const node = document.createElement( tag );

	Object.entries( attrs ).forEach( ( [ name, value ] ) => {
		if ( value === null || value === undefined || value === false ) {
			return;
		}
		if ( name === 'class' ) {
			node.className = value;
		} else if ( name === 'text' ) {
			node.textContent = value;
		} else {
			node.setAttribute( name, value === true ? '' : String( value ) );
		}
	} );

	children.forEach( ( child ) => {
		if ( child !== null && child !== undefined ) {
			node.append( child );
		}
	} );

	return node;
};

/**
 * Only plain web links are ever rendered as hrefs.
 *
 * @param {string} url Candidate URL.
 * @return {string} The URL, or an empty string when it is not http(s).
 */
const safeUrl = ( url ) => ( /^https?:\/\//i.test( url || '' ) ? url : '' );

/** Read the visitor's last chosen odds format. */
export const loadFormat = () => {
	try {
		return window.localStorage.getItem( FORMAT_KEY ) || '';
	} catch {
		return '';
	}
};

/**
 * Remember the visitor's odds format (ignored when storage is blocked).
 *
 * @param {string} format Format key.
 */
export const saveFormat = ( format ) => {
	try {
		window.localStorage.setItem( FORMAT_KEY, format );
	} catch {
		// Storage can be unavailable (private mode); the choice then lasts for this page only.
	}
};

/**
 * "3 min ago" style label for a unix timestamp.
 *
 * @param {number} timestamp Unix time in seconds.
 * @param {Object} t         Translated strings.
 * @return {string} The label.
 */
const ago = ( timestamp, t ) => {
	const seconds = Math.max( 0, Math.round( Date.now() / 1000 - timestamp ) );

	if ( seconds < 20 ) {
		return t.justNow;
	}
	if ( seconds < 90 ) {
		return `${ seconds } ${ t.sec } ${ t.ago }`;
	}
	if ( seconds < 5400 ) {
		return `${ Math.round( seconds / 60 ) } ${ t.min } ${ t.ago }`;
	}

	return `${ Math.round( seconds / 3600 ) } ${ t.hour } ${ t.ago }`;
};

/**
 * Render (or re-render) an odds table.
 *
 * @param {HTMLElement} root    Container to fill.
 * @param {Object}      data    Response of the table/odds endpoint.
 * @param {Object}      options format, highlight, showSwitch, i18n (strings), onFormat(format).
 */
export function renderTable( root, data, options ) {
	const { format, highlight, showSwitch, i18n: t, onFormat } = options;
	const link = data.link || {};
	const parts = [];

	// Heading line: event, market, freshness.
	parts.push(
		el( 'div', { class: 'oddscmp__head' }, [
			el( 'div', {}, [
				el( 'div', { class: 'oddscmp__event', text: data.event.name } ),
				el( 'div', {
					class: 'oddscmp__market',
					text: data.market.name,
				} ),
			] ),
			el( 'div', {
				class: 'oddscmp__updated',
				text: `${ t.updated } ${ ago( data.updated, t ) }`,
			} ),
		] )
	);

	if ( data.status === 'finished' ) {
		parts.push(
			el( 'p', {
				class: 'oddscmp__notice oddscmp__notice--info',
				role: 'status',
				text: t.finished,
			} )
		);
	} else if ( data.status === 'stale' && data.notice ) {
		parts.push(
			el( 'p', {
				class: 'oddscmp__notice oddscmp__notice--warn',
				role: 'status',
				text: data.notice,
			} )
		);
	}

	// Odds format switcher.
	const formats = Object.entries( data.formats || {} );
	if ( showSwitch && formats.length > 1 ) {
		parts.push(
			el(
				'div',
				{
					class: 'oddscmp__switch',
					role: 'group',
					'aria-label': t.format,
				},
				formats.map( ( [ key, label ] ) => {
					const button = el( 'button', {
						type: 'button',
						class: 'oddscmp__switch-btn',
						'aria-pressed': key === format ? 'true' : 'false',
						text: label,
					} );
					button.addEventListener(
						'click',
						() => onFormat && onFormat( key )
					);

					return button;
				} )
			)
		);
	}

	if ( ! data.bookmakers.length || ! data.outcomes.length ) {
		parts.push( el( 'p', { class: 'oddscmp__notice', text: t.noOdds } ) );
		root.replaceChildren( ...parts );
		return;
	}

	// Table.
	const head = el( 'tr', {}, [
		el( 'th', {
			scope: 'col',
			class: 'oddscmp__corner',
			text: data.market.name,
		} ),
		...data.bookmakers.map( ( b ) =>
			el( 'th', { scope: 'col', class: 'oddscmp__bk', text: b.name } )
		),
	] );

	const rows = data.outcomes.map( ( outcome ) =>
		el( 'tr', {}, [
			el( 'th', {
				scope: 'row',
				class: 'oddscmp__outcome',
				text: outcome.name,
			} ),
			...outcome.cells.map( ( cell, index ) => {
				const bookmaker = data.bookmakers[ index ];
				const price = cell.formats ? cell.formats[ format ] : null;
				const isBest = highlight && cell.best;
				const content = price
					? [
							price,
							isBest
								? el( 'span', {
										class: 'screen-reader-text',
										text: ` (${ t.best })`,
									} )
								: null,
						]
					: [ '–' ];
				const href = safeUrl( bookmaker.url );
				const inner =
					price && href
						? el(
								'a',
								{
									href,
									rel:
										link.rel ||
										'sponsored nofollow noopener',
									target: link.target || '_blank',
								},
								content
							)
						: el( 'span', {}, content );

				return el(
					'td',
					{
						class: `oddscmp__price${ isBest ? ' is-best' : '' }${ price ? '' : ' is-empty' }`,
					},
					[ inner ]
				);
			} ),
		] )
	);

	const hasLinks = data.bookmakers.some( ( b ) => safeUrl( b.url ) );
	const foot = hasLinks
		? el( 'tfoot', {}, [
				el( 'tr', {}, [
					el( 'td', { class: 'oddscmp__corner' } ),
					...data.bookmakers.map( ( b ) => {
						const href = safeUrl( b.url );

						return el( 'td', { class: 'oddscmp__cta' }, [
							href
								? el( 'a', {
										class: 'oddscmp__bet',
										href,
										rel:
											link.rel ||
											'sponsored nofollow noopener',
										target: link.target || '_blank',
										'aria-label': `${ t.bet } — ${ b.name }`,
										text: t.bet,
									} )
								: null,
						] );
					} ),
				] ),
			] )
		: null;

	parts.push(
		el(
			'div',
			{
				class: 'oddscmp__scroll',
				tabindex: '0',
				role: 'region',
				'aria-label': `${ data.event.name } — ${ data.market.name }`,
			},
			[
				el( 'table', { class: 'oddscmp__table' }, [
					el( 'thead', {}, [ head ] ),
					el( 'tbody', {}, rows ),
					foot,
				] ),
			]
		)
	);

	root.replaceChildren( ...parts );
}

/**
 * Show a message instead of a table.
 *
 * @param {HTMLElement} root Container to fill.
 * @param {string}      text Message.
 * @param {string}      kind Notice style: info or warn.
 */
export function renderMessage( root, text, kind = 'info' ) {
	root.replaceChildren(
		el( 'p', {
			class: `oddscmp__notice oddscmp__notice--${ kind }`,
			role: 'status',
			text,
		} )
	);
}

/**
 * Show the loading indicator.
 *
 * @param {HTMLElement} root Container to fill.
 * @param {string}      text Label next to the spinner.
 */
export function renderLoading( root, text ) {
	root.replaceChildren(
		el(
			'div',
			{ class: 'oddscmp__status', role: 'status', 'aria-live': 'polite' },
			[
				el( 'span', {
					class: 'oddscmp__spinner',
					'aria-hidden': 'true',
				} ),
				text,
			]
		)
	);
}
