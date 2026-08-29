/**
 * Key Word Cloud block, editor side.
 *
 * Plain JavaScript on purpose. There is no build step in this repository, so
 * no JSX and no import statements: everything comes off the wp global that
 * WordPress already loads.
 *
 * The block is server rendered. Every attribute is a string and an empty
 * string means "use the value saved on the settings screen", so the block does
 * not carry a second copy of the defaults.
 */

( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element ) {
		// 조용히 넘기면 편집 화면에서 block 이 사라진 이유를 알 수 없다.
		window.console && window.console.error( '[key-word-cloud] wp.blocks is missing; block not registered' );
		return;
	}

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var components = wp.components;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var ServerSideRender = wp.serverSideRender;

	var LANGUAGES = [
		{ label: __( 'English', 'key-word-cloud' ), value: 'en' },
		{ label: __( 'Korean', 'key-word-cloud' ), value: 'ko' },
		{ label: __( 'Both', 'key-word-cloud' ), value: 'both' }
	];

	var SHAPES = [
		{ label: __( 'Ellipse', 'key-word-cloud' ), value: 'ellipse' },
		{ label: __( 'Block', 'key-word-cloud' ), value: 'block' }
	];

	var FONTS = [
		{ label: __( 'Rounded', 'key-word-cloud' ), value: 'rounded' },
		{ label: __( 'Sans', 'key-word-cloud' ), value: 'sans' },
		{ label: __( 'Serif', 'key-word-cloud' ), value: 'serif' },
		{ label: __( 'Monospace', 'key-word-cloud' ), value: 'mono' },
		{ label: __( 'Theme font', 'key-word-cloud' ), value: 'theme' },
		{ label: __( 'Written below', 'key-word-cloud' ), value: 'custom' }
	];

	var COLOR_MODES = [
		{ label: __( 'Several colours', 'key-word-cloud' ), value: 'palette' },
		{ label: __( 'One-hue gradient', 'key-word-cloud' ), value: 'gradient' }
	];

	/**
	 * What the settings screen holds, keyed by the block's own attribute names,
	 * handed over by block.php. An empty field means "use this", so the field
	 * shows the value itself rather than the words "saved setting": the point of
	 * looking at the sidebar is to learn what the cloud will do.
	 */
	function carried() {
		return window.KWC_BLOCK || {};
	}

	function setting( key ) {
		var settings = carried().settings;
		return settings && settings[ key ] !== undefined ? String( settings[ key ] ) : '';
	}

	/** The saved value's own label, for the first entry of a select. */
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
			patch[ key ] = value;
			props.setAttributes( patch );
		};
	}

	/**
	 * A select is a drawer you have to open before it says anything. With four
	 * answers or fewer the radios cost the same room and show all of them.
	 */
	function radio( props, key, label, options ) {
		/* translators: the value the settings screen holds for this field */
		var first = { label: sprintf( __( 'Setting: %s', 'key-word-cloud' ), settingLabel( key, options ) ), value: '' };
		return el( components.RadioControl, {
			key: key,
			label: label,
			selected: props.attributes[ key ],
			options: [ first ].concat( options ),
			onChange: setter( props, key )
		} );
	}

	function select( props, key, label, options ) {
		/* translators: the value the settings screen holds for this field */
		var first = { label: sprintf( __( 'Setting: %s', 'key-word-cloud' ), settingLabel( key, options ) ), value: '' };
		return el( components.SelectControl, {
			key: key,
			label: label,
			value: props.attributes[ key ],
			options: [ first ].concat( options ),
			onChange: setter( props, key )
		} );
	}

	function text( props, key, label, help ) {
		return el( components.TextControl, {
			key: key,
			label: label,
			help: help,
			value: props.attributes[ key ],
			placeholder: setting( key ),
			onChange: setter( props, key )
		} );
	}

	/**
	 * A count picked on a slider rather than typed.
	 *
	 * The slider always stands somewhere, so an empty attribute shows the saved
	 * setting's own number and reset puts it back to empty. `most` comes from the
	 * uploaded topics, not from a number written here: a ceiling of 20 would put
	 * values out of reach once the site grows and leave dead travel while it is
	 * small.
	 */
	function range( props, key, label, most, help ) {
		var saved = parseInt( setting( key ), 10 );
		var raw = props.attributes[ key ];
		var value = ( '' === raw ) ? saved : parseInt( raw, 10 );
		if ( isNaN( value ) ) {
			value = 1;
		}
		// wp_localize_script sends every number as a string; compare them as numbers.
		var ceiling = parseInt( most, 10 );
		// A slider whose ends meet cannot be moved, so it always reaches what it shows.
		var top = Math.max( 2, isNaN( ceiling ) ? 1 : ceiling, value, isNaN( saved ) ? 1 : saved );
		return el( components.RangeControl, {
			key: key,
			label: label,
			help: help,
			value: value,
			min: 1,
			max: top,
			step: 1,
			allowReset: true,
			onChange: function ( next ) {
				setter( props, key )( ( undefined === next || null === next ) ? '' : String( next ) );
			}
		} );
	}

	function panel( title, initialOpen, children ) {
		return el( components.PanelBody, { title: title, initialOpen: initialOpen }, children );
	}

	/**
	 * The fields the uploaded topics actually carry, handed over by block.php.
	 * The list is not hard coded here: add a field to the pipeline and it shows up.
	 */
	function knownFields() {
		var carried = window.KWC_BLOCK && window.KWC_BLOCK.fields;
		return Array.isArray( carried ) ? carried : [];
	}

	/**
	 * Field picker. The one attribute holds all three answers:
	 * '' nothing ticked -> the saved setting, '*' -> every field, 'a,b' -> only those.
	 */
	function fieldChecks( props ) {
		var fields = knownFields();
		if ( ! fields.length ) {
			return el( 'p', { key: 'no-fields', className: 'components-base-control__help' },
				__( 'The topics on this site carry no fields yet, so there is nothing to tick. Run tools/label_fields.py over them, publish the result, and fetch it from the Key Word Cloud settings screen.', 'key-word-cloud' ) );
		}

		var raw = String( props.attributes.fields || '' );
		// An empty attribute follows the settings screen, so show what that ticks
		// rather than nothing. Every other field in this sidebar reads that way.
		var value = ( '' === raw ) ? setting( 'fields' ) : raw;
		var all = '*' === value;
		var chosen = ( all || '' === value ) ? [] : value.split( ',' );
		var set = setter( props, 'fields' );

		var rows = [ el( components.CheckboxControl, {
			key: '*',
			label: __( 'All fields', 'key-word-cloud' ),
			checked: all,
			onChange: function ( on ) {
				set( on ? '*' : '' );
			}
		} ) ];

		fields.forEach( function ( field ) {
			rows.push( el( components.CheckboxControl, {
				key: field.name,
				label: field.name + ' (' + field.count + ')',
				checked: chosen.indexOf( field.name ) !== -1,
				onChange: function ( on ) {
					// Ticking one field means "these fields", so it clears All fields.
					var next = chosen.filter( function ( name ) {
						return name !== field.name;
					} );
					if ( on ) {
						next.push( field.name );
					}
					// Nobody wants a cloud of no fields at all, so emptying the list
					// goes back to following the settings screen.
					set( next.join( ',' ) );
				}
			} ) );
		} );

		rows.push( el( 'p', { key: 'help', className: 'components-base-control__help' },
			__( 'Only topics in the ticked fields are drawn. Untick them all to follow the settings screen. A field showing 0 has no topics in it yet.', 'key-word-cloud' ) ) );
		return el( 'div', { key: 'fields' }, rows );
	}

	/**
	 * Three groups, and they are the editor's own tabs rather than tabs of our making:
	 * Content sits in Settings, Appearance in Styles, and the data knobs in Advanced.
	 * Cache is neither what is drawn nor how it looks, so a two-way split would have
	 * to put it somewhere it does not belong.
	 */
	/**
	 * Which of the site's own writing the topics are counted against. Same rule as
	 * the fields: an empty attribute follows the settings screen, so the boxes show
	 * what that ticks, and unticking them all goes back to following it.
	 */
	function sourceChecks( props ) {
		var sources = carried().sources;
		if ( ! Array.isArray( sources ) || ! sources.length ) {
			return el( 'p', { key: 'no-sources', className: 'components-base-control__help' },
				__( 'The list of places to search did not arrive.', 'key-word-cloud' ) );
		}

		var raw = String( props.attributes.sources || '' );
		var chosen = ( '' === raw ) ? String( setting( 'sources' ) || '' ).split( ',' ) : raw.split( ',' );
		var set = setter( props, 'sources' );

		var rows = sources.map( function ( source ) {
			return el( components.CheckboxControl, {
				key: source.name,
				label: source.label,
				checked: chosen.indexOf( source.name ) !== -1,
				onChange: function ( on ) {
					var next = chosen.filter( function ( name ) {
						return name !== source.name;
					} );
					if ( on ) {
						next.push( source.name );
					}
					set( next.join( ',' ) );
				}
			} );
		} );

		rows.push( el( 'p', { key: 'help', className: 'components-base-control__help' },
			__( 'The ticked places are searched now, so a topic is as large as the writing that still holds it. Tick none to use the number the pipeline sent.', 'key-word-cloud' ) ) );
		return el( 'div', { key: 'sources' }, rows );
	}

	function inspector( props ) {
		return [
			el( InspectorControls, { key: 'content' }, [
				panel( __( 'Topics', 'key-word-cloud' ), true, [
					radio( props, 'language', __( 'Language', 'key-word-cloud' ), LANGUAGES ),
					range( props, 'min_posts', __( 'Least post count', 'key-word-cloud' ),
						carried().mostPosts, __( 'A topic drawn from fewer posts than this is left out.', 'key-word-cloud' ) ),
					range( props, 'max', __( 'Topics to draw', 'key-word-cloud' ),
						carried().topics, __( 'The most covered topics, up to this many.', 'key-word-cloud' ) )
				] ),
				panel( __( 'Fields', 'key-word-cloud' ), true, [ fieldChecks( props ) ] ),
				panel( __( 'Where to look', 'key-word-cloud' ), true, [ sourceChecks( props ) ] )
			] ),
			el( InspectorControls, { key: 'appearance', group: 'styles' }, [
				panel( __( 'Shape and size', 'key-word-cloud' ), true, [
					select( props, 'shape', __( 'Shape', 'key-word-cloud' ), SHAPES ),
					text( props, 'ratio', __( 'Width : height', 'key-word-cloud' ), __( 'The ellipse aims for this. In a narrow column the text shrinks to keep it.', 'key-word-cloud' ) ),
					text( props, 'width', __( 'Width in px', 'key-word-cloud' ), __( '0 uses the column width. Never wider than the column.', 'key-word-cloud' ) ),
					text( props, 'height', __( 'Height in px', 'key-word-cloud' ), __( '0 lets the ratio decide. A height overrides the ratio.', 'key-word-cloud' ) )
				] ),
				panel( __( 'Text', 'key-word-cloud' ), false, [
					select( props, 'font', __( 'Font', 'key-word-cloud' ), FONTS ),
					text( props, 'font_custom', __( 'Font family', 'key-word-cloud' ), __( 'Used only when the font is set to Written below.', 'key-word-cloud' ) ),
					text( props, 'min_size', __( 'Smallest size in px', 'key-word-cloud' ) ),
					text( props, 'max_size', __( 'Largest size in px', 'key-word-cloud' ) )
				] ),
				panel( __( 'Colour', 'key-word-cloud' ), false, [
					select( props, 'color_mode', __( 'Colour', 'key-word-cloud' ), COLOR_MODES ),
					text( props, 'color_start', __( 'Colour of the smallest', 'key-word-cloud' ), '#rrggbb' ),
					text( props, 'color_end', __( 'Colour of the largest', 'key-word-cloud' ), '#rrggbb' )
				] )
			] ),
			el( InspectorControls, { key: 'data', group: 'advanced' }, [
				text( props, 'cache', __( 'Cache seconds', 'key-word-cloud' ), __( '0 skips the cache.', 'key-word-cloud' ) )
			] )
		];
	}

	wp.blocks.registerBlockType( 'key-word-cloud/word-cloud', {
		edit: function ( props ) {
			var blockProps = wp.blockEditor.useBlockProps ? wp.blockEditor.useBlockProps() : {};

			// 편집 화면의 미리보기는 서버가 그린다. 규칙이 PHP 한 곳에만 있게 하려는 것이다.
			var preview = ServerSideRender
				? el( ServerSideRender, {
					key: 'preview',
					block: 'key-word-cloud/word-cloud',
					attributes: props.attributes
				} )
				: el( 'p', { key: 'preview' }, __( 'Preview needs the wp-server-side-render script.', 'key-word-cloud' ) );

			return el( 'div', blockProps, inspector( props ).concat( [ preview ] ) );
		},

		// 서버가 그리므로 저장할 마크업이 없다.
		save: function () {
			return null;
		}
	} );
} )( window.wp );
