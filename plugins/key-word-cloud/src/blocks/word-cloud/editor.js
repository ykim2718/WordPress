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

	var RANKINGS = [
		{ label: __( 'Saved setting', 'key-word-cloud' ), value: '' },
		{ label: __( 'TF-IDF (distinctive words)', 'key-word-cloud' ), value: 'tfidf' },
		{ label: __( 'Occurrences (frequent words)', 'key-word-cloud' ), value: 'count' }
	];

	var SOURCES = [
		{ label: __( 'Saved setting', 'key-word-cloud' ), value: '' },
		{ label: __( 'Content', 'key-word-cloud' ), value: 'content' },
		{ label: __( 'Excerpt', 'key-word-cloud' ), value: 'excerpt' }
	];

	var LINKS = [
		{ label: __( 'Saved setting', 'key-word-cloud' ), value: '' },
		{ label: __( 'Search results for the word', 'key-word-cloud' ), value: 'search' },
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

	function inspector( props ) {
		return el( InspectorControls, { key: 'inspector' }, [
			panel( __( 'Source', 'key-word-cloud' ), true, [
				select( props, 'ranking', __( 'How words are chosen', 'key-word-cloud' ), RANKINGS ),
				select( props, 'source', __( 'Text source', 'key-word-cloud' ), SOURCES ),
				text( props, 'post_type', __( 'Post types', 'key-word-cloud' ), __( 'Comma separated. Public post types only.', 'key-word-cloud' ) ),
				text( props, 'category', __( 'Category slug', 'key-word-cloud' ) ),
				text( props, 'tag', __( 'Tag slug', 'key-word-cloud' ) ),
				text( props, 'limit', __( 'Posts to scan', 'key-word-cloud' ), __( '1 to 5000, newest first.', 'key-word-cloud' ) )
			] ),
			panel( __( 'Words', 'key-word-cloud' ), false, [
				text( props, 'max', __( 'Words to draw', 'key-word-cloud' ) ),
				text( props, 'min_count', __( 'Least occurrences', 'key-word-cloud' ) ),
				text( props, 'min_docs_pct', __( 'TF-IDF: least posts (%)', 'key-word-cloud' ), __( 'A word must appear in this share of the scanned posts. 0 removes the floor.', 'key-word-cloud' ) ),
				text( props, 'min_len', __( 'Least characters', 'key-word-cloud' ) )
			] ),
			panel( __( 'Size and colour', 'key-word-cloud' ), false, [
				text( props, 'min_size', __( 'Smallest size in px', 'key-word-cloud' ) ),
				text( props, 'max_size', __( 'Largest size in px', 'key-word-cloud' ) ),
				text( props, 'color_start', __( 'Colour of rare words', 'key-word-cloud' ), '#rrggbb' ),
				text( props, 'color_end', __( 'Colour of common words', 'key-word-cloud' ), '#rrggbb' ),
				select( props, 'link', __( 'Clicking a word', 'key-word-cloud' ), LINKS )
			] ),
			panel( __( 'Cache', 'key-word-cloud' ), false, [
				text( props, 'cache', __( 'Cache seconds', 'key-word-cloud' ), __( '0 skips the cache.', 'key-word-cloud' ) )
			] )
		] );
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

			return el( 'div', blockProps, [ inspector( props ), preview ] );
		},

		// 서버가 그리므로 저장할 마크업이 없다.
		save: function () {
			return null;
		}
	} );
} )( window.wp );
