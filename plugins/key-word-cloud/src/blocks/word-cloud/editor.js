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
	var components = wp.components;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var ServerSideRender = wp.serverSideRender;

	var LANGUAGES = [
		{ label: __( 'Saved setting', 'key-word-cloud' ), value: '' },
		{ label: __( 'English', 'key-word-cloud' ), value: 'en' },
		{ label: __( 'Korean', 'key-word-cloud' ), value: 'ko' },
		{ label: __( 'Both', 'key-word-cloud' ), value: 'both' }
	];

	var SHAPES = [
		{ label: __( 'Saved setting', 'key-word-cloud' ), value: '' },
		{ label: __( 'Ellipse', 'key-word-cloud' ), value: 'ellipse' },
		{ label: __( 'Block', 'key-word-cloud' ), value: 'block' }
	];

	var FONTS = [
		{ label: __( 'Saved setting', 'key-word-cloud' ), value: '' },
		{ label: __( 'Rounded', 'key-word-cloud' ), value: 'rounded' },
		{ label: __( 'Sans', 'key-word-cloud' ), value: 'sans' },
		{ label: __( 'Serif', 'key-word-cloud' ), value: 'serif' },
		{ label: __( 'Monospace', 'key-word-cloud' ), value: 'mono' },
		{ label: __( 'Theme font', 'key-word-cloud' ), value: 'theme' },
		{ label: __( 'Written below', 'key-word-cloud' ), value: 'custom' }
	];

	var COLOR_MODES = [
		{ label: __( 'Saved setting', 'key-word-cloud' ), value: '' },
		{ label: __( 'Several colours', 'key-word-cloud' ), value: 'palette' },
		{ label: __( 'One-hue gradient', 'key-word-cloud' ), value: 'gradient' }
	];

	var LINKS = [
		{ label: __( 'Saved setting', 'key-word-cloud' ), value: '' },
		{ label: __( 'Search results for the topic', 'key-word-cloud' ), value: 'search' },
		{ label: __( 'No link', 'key-word-cloud' ), value: 'none' }
	];

	/** 한 attribute 만 바꾸는 setter */
	function setter( props, key ) {
		return function ( value ) {
			var patch = {};
			patch[ key ] = value;
			props.setAttributes( patch );
		};
	}

	function select( props, key, label, options ) {
		return el( components.SelectControl, {
			key: key,
			label: label,
			value: props.attributes[ key ],
			options: options,
			onChange: setter( props, key )
		} );
	}

	function text( props, key, label, help ) {
		return el( components.TextControl, {
			key: key,
			label: label,
			help: help,
			value: props.attributes[ key ],
			placeholder: __( 'Saved setting', 'key-word-cloud' ),
			onChange: setter( props, key )
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
				__( 'The uploaded topics carry no fields yet. Label them with tools/label_fields.py.', 'key-word-cloud' ) );
		}

		var value = String( props.attributes.fields || '' );
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
					set( next.join( ',' ) );
				}
			} ) );
		} );

		rows.push( el( 'p', { key: 'help', className: 'components-base-control__help' },
			__( 'Only topics in the ticked fields are drawn. Tick none to use the saved setting.', 'key-word-cloud' ) ) );
		return el( 'div', { key: 'fields' }, rows );
	}

	/**
	 * Three groups, and they are the editor's own tabs rather than tabs of our making:
	 * Content sits in Settings, Appearance in Styles, and the data knobs in Advanced.
	 * Cache is neither what is drawn nor how it looks, so a two-way split would have
	 * to put it somewhere it does not belong.
	 */
	function inspector( props ) {
		return [
			el( InspectorControls, { key: 'content' }, [
				panel( __( 'Topics', 'key-word-cloud' ), true, [
					select( props, 'language', __( 'Language', 'key-word-cloud' ), LANGUAGES ),
					text( props, 'min_posts', __( 'Least posts', 'key-word-cloud' ), __( 'A topic drawn from fewer posts than this is left out.', 'key-word-cloud' ) ),
					text( props, 'max', __( 'Topics to draw', 'key-word-cloud' ) )
				] ),
				panel( __( 'Fields', 'key-word-cloud' ), true, [ fieldChecks( props ) ] ),
				panel( __( 'Behaviour', 'key-word-cloud' ), false, [
					select( props, 'link', __( 'Clicking a topic', 'key-word-cloud' ), LINKS )
				] )
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
