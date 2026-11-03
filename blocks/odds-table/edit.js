/**
 * Editor UI of the odds table block.
 */
import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	Button,
	Notice,
	PanelBody,
	Placeholder,
	SelectControl,
	Spinner,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { renderMessage, renderTable } from './table';

const NAMESPACE = '/odds-comparison/v1';

/** Texts used by the shared table renderer. */
const strings = () => ( {
	loading: __( 'Loading odds…', 'odds-comparison' ),
	error: __( 'Odds are temporarily unavailable.', 'odds-comparison' ),
	updated: __( 'Updated', 'odds-comparison' ),
	finished: __(
		'This event has finished. Odds are no longer updated.',
		'odds-comparison'
	),
	bet: __( 'Bet', 'odds-comparison' ),
	format: __( 'Odds format', 'odds-comparison' ),
	noOdds: __( 'No odds available.', 'odds-comparison' ),
	ago: __( 'ago', 'odds-comparison' ),
	justNow: __( 'just now', 'odds-comparison' ),
	sec: __( 's', 'odds-comparison' ),
	min: __( 'min', 'odds-comparison' ),
	hour: __( 'h', 'odds-comparison' ),
	best: __( 'Best odds', 'odds-comparison' ),
} );

/**
 * GET a plugin REST route and track its loading state.
 *
 * @param {string}  route   Route without namespace, e.g. "sports".
 * @param {Object}  params  Query parameters.
 * @param {boolean} enabled Whether to fetch.
 * @return {{loading: boolean, data: (Object|Array|null), error: string}} Request state.
 */
function useRest( route, params, enabled ) {
	const [ state, setState ] = useState( {
		loading: false,
		data: null,
		error: '',
	} );
	const key = enabled ? `${ route }?${ new URLSearchParams( params ) }` : '';

	useEffect( () => {
		if ( ! key ) {
			setState( { loading: false, data: null, error: '' } );
			return undefined;
		}

		let cancelled = false;
		setState( ( previous ) => ( {
			...previous,
			loading: true,
			error: '',
		} ) );

		apiFetch( { path: `${ NAMESPACE }/${ key }` } )
			.then(
				( data ) =>
					! cancelled &&
					setState( { loading: false, data, error: '' } )
			)
			.catch(
				( error ) =>
					! cancelled &&
					setState( {
						loading: false,
						data: null,
						error:
							error.message ||
							__( 'Request failed.', 'odds-comparison' ),
					} )
			);

		return () => {
			cancelled = true;
		};
	}, [ key ] );

	return state;
}

/**
 * Odds preview request that waits while the server is still fetching (HTTP 202).
 *
 * @param {Object}  params  Query parameters.
 * @param {boolean} enabled Whether to fetch.
 * @return {{loading: boolean, data: (Object|null), error: string}} Request state.
 */
function useOdds( params, enabled ) {
	const [ state, setState ] = useState( {
		loading: false,
		data: null,
		error: '',
	} );
	const key = enabled ? new URLSearchParams( params ).toString() : '';

	useEffect( () => {
		if ( ! key ) {
			setState( { loading: false, data: null, error: '' } );
			return undefined;
		}

		let cancelled = false;
		let timer = 0;
		setState( { loading: true, data: null, error: '' } );

		const load = ( attempt ) => {
			apiFetch( { path: `${ NAMESPACE }/odds?${ key }` } )
				.then( ( data ) => {
					if ( cancelled ) {
						return;
					}
					if ( data.status === 'pending' && attempt < 10 ) {
						timer = window.setTimeout(
							() => load( attempt + 1 ),
							Math.max( 1, data.retryAfter || 2 ) * 1000
						);
						return;
					}
					setState( {
						loading: false,
						data: data.status === 'pending' ? null : data,
						error: '',
					} );
				} )
				.catch(
					( error ) =>
						! cancelled &&
						setState( {
							loading: false,
							data: null,
							error:
								error.message ||
								__( 'Request failed.', 'odds-comparison' ),
						} )
				);
		};
		load( 0 );

		return () => {
			cancelled = true;
			window.clearTimeout( timer );
		};
	}, [ key ] );

	return state;
}

const options = ( items, placeholder, label = ( item ) => item.name ) => [
	{ value: '', label: placeholder },
	...( items || [] ).map( ( item ) => ( {
		value: item.id,
		label: label( item ),
	} ) ),
];

const eventLabel = ( event ) => {
	if ( ! event.startsAt ) {
		return event.name;
	}

	return `${ event.name } — ${ new Date( event.startsAt * 1000 ).toLocaleString( [], { dateStyle: 'medium', timeStyle: 'short' } ) }`;
};

// Source / sport / event / market pickers.
function Picker( {
	attributes,
	setAttributes,
	sources,
	sports,
	events,
	markets,
} ) {
	const { source, sport, eventId, market } = attributes;

	return (
		<>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Source', 'odds-comparison' ) }
				value={ source }
				options={ options(
					sources.data,
					__( 'Choose a source', 'odds-comparison' ),
					( s ) => s.label
				) }
				onChange={ ( value ) =>
					setAttributes( {
						source: value,
						sport: '',
						eventId: '',
						eventName: '',
						eventPath: '',
						eventStarts: 0,
						market: '',
						bookmakers: [],
					} )
				}
			/>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Sport', 'odds-comparison' ) }
				value={ sport }
				disabled={ ! source || sports.loading }
				options={ options(
					sports.data,
					sports.loading
						? __( 'Loading…', 'odds-comparison' )
						: __( 'Choose a sport', 'odds-comparison' )
				) }
				onChange={ ( value ) =>
					setAttributes( {
						sport: value,
						eventId: '',
						eventName: '',
						eventPath: '',
						eventStarts: 0,
						market: '',
					} )
				}
			/>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Event', 'odds-comparison' ) }
				value={ eventId }
				disabled={ ! sport || events.loading }
				options={ options(
					events.data,
					events.loading
						? __( 'Loading…', 'odds-comparison' )
						: __( 'Choose an event', 'odds-comparison' ),
					eventLabel
				) }
				onChange={ ( value ) => {
					const chosen = ( events.data || [] ).find(
						( e ) => e.id === value
					);
					setAttributes( {
						eventId: value,
						eventName: chosen ? chosen.name : '',
						eventPath: chosen ? chosen.url : '',
						eventStarts:
							chosen && chosen.startsAt ? chosen.startsAt : 0,
						market: '',
					} );
				} }
			/>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Market', 'odds-comparison' ) }
				value={ market }
				disabled={ ! eventId || markets.loading }
				options={ options(
					markets.data,
					markets.loading
						? __( 'Loading…', 'odds-comparison' )
						: __( 'Choose a market', 'odds-comparison' )
				) }
				onChange={ ( value ) => setAttributes( { market: value } ) }
			/>
		</>
	);
}

// Add / remove bookmakers of the comparison.
function BookmakerList( { attributes, setAttributes, bookmakers } ) {
	const selected = attributes.bookmakers;
	const all = bookmakers.data || [];
	const nameOf = ( id ) =>
		( all.find( ( b ) => b.id === id ) || { name: id } ).name;
	const remaining = all.filter( ( b ) => ! selected.includes( b.id ) );

	return (
		<>
			<p className="oddscmp-editor__hint">
				{ selected.length
					? __(
							'Only these bookmakers are compared.',
							'odds-comparison'
						)
					: __(
							'No bookmaker chosen: all available bookmakers are compared.',
							'odds-comparison'
						) }
			</p>

			<ul className="oddscmp-editor__list">
				{ selected.map( ( id ) => (
					<li key={ id }>
						<span>{ nameOf( id ) }</span>
						<Button
							variant="tertiary"
							isDestructive
							size="small"
							onClick={ () =>
								setAttributes( {
									bookmakers: selected.filter(
										( x ) => x !== id
									),
								} )
							}
						>
							{ __( 'Remove', 'odds-comparison' ) }
						</Button>
					</li>
				) ) }
			</ul>

			{ bookmakers.loading && <Spinner /> }
			{ ! bookmakers.loading && remaining.length > 0 && (
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Add a bookmaker', 'odds-comparison' ) }
					value=""
					options={ options(
						remaining,
						__( 'Choose…', 'odds-comparison' )
					) }
					onChange={ ( value ) =>
						value &&
						setAttributes( { bookmakers: [ ...selected, value ] } )
					}
				/>
			) }
			{ ! bookmakers.loading && all.length === 0 && attributes.source && (
				<p className="oddscmp-editor__hint">
					{ __(
						'No bookmakers detected for this source yet. Open Odds Comparison → Bookmakers and click “Detect bookmakers”.',
						'odds-comparison'
					) }
				</p>
			) }
		</>
	);
}

export default function Edit( { attributes, setAttributes } ) {
	const {
		source,
		sport,
		eventId,
		eventName,
		eventPath,
		eventStarts,
		market,
		bookmakers: chosen,
		highlightBest,
		showFormatSwitch,
	} = attributes;
	const blockProps = useBlockProps( {
		className: 'oddscmp oddscmp--editor',
	} );
	const preview = useRef( null );
	const [ format, setFormat ] = useState( '' );

	const sources = useRest( 'sources', {}, true );
	const sports = useRest( 'sports', { source }, !! source );
	const events = useRest( 'events', { source, sport }, !! sport );
	const eventParams = {
		source,
		sport,
		event: eventId,
		name: eventName,
		path: eventPath,
		starts: eventStarts,
	};
	const markets = useRest( 'markets', eventParams, !! eventId );
	const bookmakers = useRest( 'bookmakers', { source }, !! source );

	// Default to the active source on a fresh block.
	useEffect( () => {
		if ( ! source && sources.data && sources.data.length ) {
			setAttributes( {
				source: (
					sources.data.find( ( s ) => s.active ) || sources.data[ 0 ]
				).id,
			} );
		}
	}, [ source, sources.data, setAttributes ] );

	const complete = !! ( source && sport && eventId && market );
	// The bookmakers list is sent comma-separated; the REST route parses it into an array.
	const odds = useOdds(
		{ ...eventParams, market, bookmakers: chosen },
		complete
	);

	// Draw the preview with the same renderer visitors get.
	useEffect( () => {
		const node = preview.current;
		if ( ! node ) {
			return;
		}
		if ( odds.error ) {
			renderMessage( node, odds.error, 'warn' );
		} else if ( odds.data ) {
			const current = odds.data.formats[ format ]
				? format
				: Object.keys( odds.data.formats )[ 0 ];
			renderTable( node, odds.data, {
				format: current,
				highlight: highlightBest,
				showSwitch: showFormatSwitch,
				i18n: strings(),
				onFormat: setFormat,
			} );
		}
	}, [ odds.data, odds.error, format, highlightBest, showFormatSwitch ] );

	const pickerProps = {
		attributes,
		setAttributes,
		sources,
		sports,
		events,
		markets,
	};
	const failure = [ sources, sports, events, markets ].find(
		( request ) => request.error
	);

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Event', 'odds-comparison' ) }>
					<Picker { ...pickerProps } />
				</PanelBody>
				<PanelBody
					title={ __( 'Bookmakers', 'odds-comparison' ) }
					initialOpen
				>
					<BookmakerList
						attributes={ attributes }
						setAttributes={ setAttributes }
						bookmakers={ bookmakers }
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Display', 'odds-comparison' ) }
					initialOpen={ false }
				>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Highlight the best odds',
							'odds-comparison'
						) }
						checked={ highlightBest }
						onChange={ ( value ) =>
							setAttributes( { highlightBest: value } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Show the odds format switcher',
							'odds-comparison'
						) }
						checked={ showFormatSwitch }
						onChange={ ( value ) =>
							setAttributes( { showFormatSwitch: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				{ failure && (
					<Notice status="warning" isDismissible={ false }>
						{ failure.error }
					</Notice>
				) }

				{ ! complete && (
					<Placeholder
						icon="chart-bar"
						label={ __( 'Odds Comparison', 'odds-comparison' ) }
						instructions={ __(
							'Choose an event and a market to compare odds.',
							'odds-comparison'
						) }
					>
						<div className="oddscmp-editor__picker">
							<Picker { ...pickerProps } />
						</div>
					</Placeholder>
				) }

				{ complete && odds.loading && (
					<div className="oddscmp__status" role="status">
						<Spinner /> { __( 'Loading odds…', 'odds-comparison' ) }
					</div>
				) }

				{ complete && <div ref={ preview } /> }
			</div>
		</>
	);
}
