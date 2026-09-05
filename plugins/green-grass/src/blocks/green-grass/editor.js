/**
 * Green Grass block, editor side.
 *
 * Plain JavaScript on purpose. There is no build step in this repository, so
 * no JSX and no import statements: everything comes off the wp global that
 * WordPress already loads.
 *
 * The block is server rendered. Every attribute is a string and an empty
 * string means "use the value saved on the settings screen", so the block does
 * not carry a second copy of the defaults. Each control therefore offers the
 * saved value as its first choice and names it, rather than saying "default":
 * the reason to open this sidebar is to find out what the calendar will do.
 */

( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element ) {
		// 조용히 넘기면 편집 화면에서 block 이 사라진 이유를 알 수 없다.
		window.console && window.console.error( '[green-grass] wp.blocks is missing; block not registered' );
		return;
	}

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var components = wp.components;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var ServerSideRender = wp.serverSideRender;

	var ORIENTATIONS = [
		{ label: __( 'Horizontal', 'green-grass' ), value: 'horizontal' },
		{ label: __( 'Vertical', 'green-grass' ), value: 'vertical' }
	];

	var PERIODS = [
		{ label: __( 'Back from today', 'green-grass' ), value: 'months' },
		{ label: __( 'Between two dates', 'green-grass' ), value: 'dates' }
	];

	var WEEK_STARTS = [
		{ label: __( 'Sunday', 'green-grass' ), value: 'sunday' },
		{ label: __( 'Monday', 'green-grass' ), value: 'monday' }
	];

	var PALETTES = [
		{ label: __( "GitHub's green", 'green-grass' ), value: 'github' },
		{ label: __( 'A colour of my own', 'green-grass' ), value: 'custom' }
	];

	var SCALES = [
		{ label: __( 'Quartiles of the counts', 'green-grass' ), value: 'quantile' },
		{ label: __( 'Even steps up to the busiest day', 'green-grass' ), value: 'linear' }
	];

	var LINKS = [
		{ label: __( "Open that day's list", 'green-grass' ), value: 'archive' },
		{ label: __( 'Go nowhere', 'green-grass' ), value: 'none' }
	];

	var SWITCHES = [
		{ key: 'show_months', label: __( 'Month names', 'green-grass' ) },
		{ key: 'show_days', label: __( 'Weekday names', 'green-grass' ) },
		{ key: 'show_legend', label: __( 'Less–More key', 'green-grass' ) },
		{ key: 'show_total', label: __( 'The line with the total', 'green-grass' ) }
	];

	/** What block.php handed over: the settings screen's values and the site's own lists. */
	function carried() {
		return window.GG_BLOCK || {};
	}

	function setting( key ) {
		var settings = carried().settings;
		return settings && settings[ key ] !== undefined ? String( settings[ key ] ) : '';
	}

	/** The saved value's own label, for the first entry of a select or radio. */
	function settingLabel( key, options ) {
		var value = setting( key );
		for ( var i = 0; i < options.length; i++ ) {
			if ( options[ i ].value === value ) {
				return options[ i ].label;
			}
		}
		return value;
	}

	/** 한 attribute 만 바꾸는 setter */
	function setter( props, key ) {
		return function ( value ) {
			var patch = {};
			patch[ key ] = ( undefined === value || null === value ) ? '' : String( value );
			props.setAttributes( patch );
		};
	}

	function firstChoice( key, options ) {
		/* translators: the value the settings screen holds for this field */
		return { label: sprintf( __( 'Setting: %s', 'green-grass' ), settingLabel( key, options ) ), value: '' };
	}

	/**
	 * A select is a drawer you have to open before it says anything. With four
	 * answers or fewer the radios cost the same room and show all of them.
	 */
	function radio( props, key, label, options ) {
		return el( components.RadioControl, {
			key: key,
			label: label,
			selected: props.attributes[ key ],
			options: [ firstChoice( key, options ) ].concat( options ),
			onChange: setter( props, key )
		} );
	}

	function text( props, key, label, help, type ) {
		return el( components.TextControl, {
			key: key,
			label: label,
			value: props.attributes[ key ],
			type: type || 'text',
			/* translators: the value the settings screen holds for this field */
			placeholder: sprintf( __( 'Setting: %s', 'green-grass' ), setting( key ) ),
			help: help,
			onChange: setter( props, key )
		} );
	}

	/**
	 * A number with a reset. Emptying it goes back to the settings screen rather
	 * than to zero, which is why the reset writes '' and not the smallest value.
	 */
	function range( props, key, label, min, max, help ) {
		var current = props.attributes[ key ];
		return el( components.RangeControl, {
			key: key,
			label: label,
			value: ( '' === current ) ? Number( setting( key ) ) : Number( current ),
			min: min,
			max: max,
			step: 1,
			allowReset: true,
			help: help,
			onChange: setter( props, key )
		} );
	}

	/**
	 * Three answers in one attribute: '' follows the settings screen, '1' shows it,
	 * '0' hides it. A plain toggle only has two, so unticking would have to mean
	 * one of them and the other would be unreachable.
	 */
	function switches( props ) {
		return SWITCHES.map( function ( item ) {
			var current = props.attributes[ item.key ];
			var on = ( '' === current ) ? ( '1' === setting( item.key ) ) : ( '1' === current );
			return el( 'div', { key: item.key, style: { marginBottom: '4px' } }, [
				el( components.CheckboxControl, {
					key: 'box',
					label: item.label,
					checked: on,
					onChange: function ( next ) {
						setter( props, item.key )( next ? '1' : '0' );
					}
				} )
			] );
		} );
	}

	/**
	 * Which post types are counted. Same rule as everywhere else: an empty
	 * attribute follows the settings screen, so the boxes show what that ticks,
	 * and unticking them all goes back to following it.
	 */
	function postTypeChecks( props ) {
		var types = carried().postTypes;
		if ( ! Array.isArray( types ) || ! types.length ) {
			return el( 'p', { key: 'none', className: 'components-base-control__help' },
				__( 'The list of post types did not arrive.', 'green-grass' ) );
		}

		var raw = String( props.attributes.post_types || '' );
		var chosen = ( '' === raw ? String( setting( 'post_types' ) || '' ) : raw ).split( ',' ).filter( Boolean );
		var set = setter( props, 'post_types' );

		var rows = types.map( function ( type ) {
			return el( components.CheckboxControl, {
				key: type.name,
				label: type.label,
				checked: chosen.indexOf( type.name ) !== -1,
				onChange: function ( on ) {
					var next = chosen.filter( function ( name ) {
						return name !== type.name;
					} );
					if ( on ) {
						next.push( type.name );
					}
					// Nobody wants a calendar of no post types at all, so emptying
					// the list goes back to following the settings screen.
					set( next.join( ',' ) );
				}
			} );
		} );

		rows.push( el( 'p', { key: 'help', className: 'components-base-control__help' },
			__( 'Only published entries are counted. Untick them all to follow the settings screen.', 'green-grass' ) ) );
		return el( 'div', { key: 'post-types' }, rows );
	}

	function panel( title, initialOpen, children ) {
		return el( components.PanelBody, { title: title, initialOpen: initialOpen }, children );
	}

	/** Which source is in force right now — the attribute, or the setting behind it. */
	function activeSource( props ) {
		var chosen = String( props.attributes.source || '' );
		return ( '' === chosen ) ? setting( 'source' ) : chosen;
	}

	/** Same question for the period, which decides whether the date fields matter. */
	function activePeriod( props ) {
		var chosen = String( props.attributes.period || '' );
		var period = ( '' === chosen ) ? setting( 'period' ) : chosen;
		// from/to 를 적어 두면 숏코드와 마찬가지로 그 날짜를 쓴다.
		if ( '' === chosen && ( props.attributes.from || props.attributes.to ) ) {
			return 'dates';
		}
		return period;
	}

	function sourceOptions() {
		var sources = carried().sources;
		return Array.isArray( sources ) ? sources.map( function ( source ) {
			return { label: source.label, value: source.name };
		} ) : [];
	}

	/**
	 * Three groups, and they are the editor's own tabs rather than tabs of our
	 * making: what is counted sits in Settings, how it looks in Styles, and the
	 * cache in Advanced.
	 */
	function inspector( props ) {
		var source = activeSource( props );
		var dates = ( 'dates' === activePeriod( props ) );

		var counting = [ radio( props, 'source', __( 'Count', 'green-grass' ), sourceOptions() ) ];
		if ( 'github' === source ) {
			counting.push( text( props, 'user', __( 'GitHub account', 'green-grass' ),
				__( 'A public profile. No token is needed.', 'green-grass' ) ) );
		}
		if ( 'posts' === source ) {
			counting.push( postTypeChecks( props ) );
		}

		var when = [ radio( props, 'period', __( 'Stretch of days', 'green-grass' ), PERIODS ) ];
		if ( dates ) {
			when.push( text( props, 'from', __( 'First day', 'green-grass' ), '', 'date' ) );
			when.push( text( props, 'to', __( 'Last day', 'green-grass' ),
				__( 'Leave empty to run up to today.', 'green-grass' ), 'date' ) );
		} else {
			when.push( range( props, 'months', __( 'Months back from today', 'green-grass' ), 1, 60,
				__( '12 is exactly one year up to today.', 'green-grass' ) ) );
		}
		when.push( radio( props, 'week_start', __( 'A week starts on', 'green-grass' ), WEEK_STARTS ) );

		var colour = [ radio( props, 'palette', __( 'Colour', 'green-grass' ), PALETTES ) ];
		if ( 'custom' === ( ( '' === props.attributes.palette ) ? setting( 'palette' ) : props.attributes.palette ) ) {
			colour.push( text( props, 'color', __( 'Darkest square', 'green-grass' ), '#rrggbb' ) );
		}
		colour.push( text( props, 'empty', __( 'Empty square', 'green-grass' ), '#rrggbb' ) );
		colour.push( radio( props, 'scale', __( 'Shades follow', 'green-grass' ), SCALES ) );

		return [
			el( InspectorControls, { key: 'content' }, [
				panel( __( 'What is counted', 'green-grass' ), true, counting ),
				panel( __( 'Which days', 'green-grass' ), true, when ),
				panel( __( 'Clicking a square', 'green-grass' ), false, [
					radio( props, 'link', __( 'A square', 'green-grass' ), LINKS )
				] )
			] ),
			el( InspectorControls, { key: 'appearance', group: 'styles' }, [
				panel( __( 'Layout', 'green-grass' ), true, [
					radio( props, 'orientation', __( 'Weeks run', 'green-grass' ), ORIENTATIONS ),
					range( props, 'cell', __( 'Square size in px', 'green-grass' ),
						Number( carried().minCell ) || 6, Number( carried().maxCell ) || 40 ),
					range( props, 'gap', __( 'Space between squares', 'green-grass' ), 0, 12 ),
					range( props, 'radius', __( 'Corner radius', 'green-grass' ), 0, 20 )
				] ),
				panel( __( 'Colour', 'green-grass' ), false, colour ),
				panel( __( 'Alongside the grid', 'green-grass' ), false, switches( props ) )
			] ),
			el( InspectorControls, { key: 'data', group: 'advanced' }, [
				text( props, 'cache', __( 'Cache seconds', 'green-grass' ),
					__( '0 skips the cache. Only the GitHub source is cached.', 'green-grass' ) )
			] )
		];
	}

	wp.blocks.registerBlockType( 'green-grass/green-grass', {
		edit: function ( props ) {
			var blockProps = wp.blockEditor.useBlockProps ? wp.blockEditor.useBlockProps() : {};

			// 편집 화면의 미리보기는 서버가 그린다. 규칙이 PHP 한 곳에만 있게 하려는 것이다.
			var preview = ServerSideRender
				? el( ServerSideRender, {
					key: 'preview',
					block: 'green-grass/green-grass',
					attributes: props.attributes
				} )
				: el( 'p', { key: 'preview' }, __( 'Preview needs the wp-server-side-render script.', 'green-grass' ) );

			return el( 'div', blockProps, inspector( props ).concat( [ preview ] ) );
		},

		// 서버가 그리므로 저장할 마크업이 없다.
		save: function () {
			return null;
		}
	} );
} )( window.wp );
