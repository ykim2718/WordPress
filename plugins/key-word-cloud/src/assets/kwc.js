/**
 * The refresh button on the cloud.
 *
 * Asks the site to fetch the published topics now instead of waiting for the
 * daily schedule, then reloads so the new cloud is what the reader sees. The
 * button is only printed for users who may edit posts, and the REST route
 * checks that capability again, so this script is a convenience and not the
 * guard.
 */

( function () {
	'use strict';

	function label( button, text, state ) {
		button.textContent = text;
		if ( state ) {
			button.setAttribute( 'data-state', state );
		} else {
			button.removeAttribute( 'data-state' );
		}
	}

	function refresh( button ) {
		var settings = window.KWC_REFRESH;
		if ( ! settings || ! settings.url ) {
			// 조용히 아무 일도 안 하면 눌러도 왜 안 되는지 알 수 없다.
			window.console && window.console.error( '[key-word-cloud] KWC_REFRESH is missing; the button cannot call the site' );
			label( button, '설정 없음', 'failed' );
			return;
		}

		var original = button.textContent;
		button.disabled = true;
		label( button, '받는 중…' );

		window.fetch( settings.url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': settings.nonce }
		} ).then( function ( response ) {
			return response.json().then( function ( body ) {
				return { ok: response.ok, status: response.status, body: body };
			} );
		} ).then( function ( result ) {
			if ( ! result.ok ) {
				throw new Error( ( result.body && result.body.message ) || ( 'HTTP ' + result.status ) );
			}
			label( button, '갱신됨 ' + result.body.stored );
			window.location.reload();
		} ).catch( function ( error ) {
			window.console && window.console.error( '[key-word-cloud] refresh failed: ' + error.message );
			label( button, '실패', 'failed' );
			button.title = error.message;
			button.disabled = false;
			window.setTimeout( function () {
				label( button, original );
			}, 4000 );
		} );
	}

	/**
	 * 낱말을 줄로 묶어 타원 모양으로 앉힌다.
	 *
	 * CSS 의 shape-outside 로는 안 된다. 그것은 상자 높이가 미리 정해져 있어야 하는데,
	 * 높이는 글이 몇 줄이 되느냐에 달려 있고, 칸이 좁으면 긴 구절이 들어갈 자리가 위아래
	 * 어디에도 없어 타원 밖으로 밀려난다. 그래서 여기서 직접 줄을 나눈다.
	 *
	 * 줄 k 의 허용 폭은 타원의 가로 반지름을 따른다.
	 *   allowed(k) = W * sqrt(1 - (2(k+0.5)/n - 1)^2)
	 * 가장 긴 낱말보다 좁아지지는 않게 막아, 어떤 항목도 버려지지 않는다.
	 */
	function layoutEllipse( cloud ) {
		var words = cloud.__kwcWords;
		if ( ! words ) {
			words = Array.prototype.slice.call( cloud.querySelectorAll( '.kwc-word' ) );
			cloud.__kwcWords = words;
		}
		if ( ! words.length ) {
			return;
		}

		// 재기 전에 지난 줄을 풀어 원래 낱말만 남긴다.
		while ( cloud.firstChild ) {
			cloud.removeChild( cloud.firstChild );
		}
		for ( var i = 0; i < words.length; i++ ) {
			cloud.appendChild( words[ i ] );
		}

		var full = cloud.clientWidth;
		if ( ! full ) {
			return;
		}

		var widths = words.map( function ( word ) {
			return word.getBoundingClientRect().width;
		} );
		var total = widths.reduce( function ( sum, w ) {
			return sum + w;
		}, 0 );
		var gap = 12;

		// 줄 수를 늘려 가며 전부 담기는 첫 값을 쓴다. 타원은 rectangle 보다 좁으므로
		// rectangle 기준 줄 수에서 시작한다.
		var rows = Math.max( 1, Math.ceil( ( total + gap * words.length ) / full ) );
		var placed = null;
		for ( var attempt = 0; attempt < 30 && ! placed; attempt++ ) {
			placed = place( words, widths, rows, full, gap );
			if ( ! placed ) {
				rows++;
			}
		}
		if ( ! placed ) {
			// 담지 못했으면 손대지 않고 그대로 둔다. 낱말을 잃는 것보다 낫다.
			window.console && window.console.warn( '[key-word-cloud] could not lay out the ellipse; left as a block' );
			return;
		}

		for ( var r = 0; r < placed.length; r++ ) {
			var row = document.createElement( 'div' );
			row.className = 'kwc-row';
			for ( var j = 0; j < placed[ r ].length; j++ ) {
				row.appendChild( placed[ r ][ j ] );
			}
			cloud.appendChild( row );
		}
	}

	/**
	 * 낱말을 rows 줄에 욕심껏 담는다. 다 담지 못하면 null.
	 */
	function place( words, widths, rows, full, gap ) {
		var out = [];
		var index = 0;
		for ( var k = 0; k < rows; k++ ) {
			var t = 2 * ( k + 0.5 ) / rows - 1;              // -1 .. 1
			var allowed = full * Math.sqrt( Math.max( 0, 1 - t * t ) );

			// 줄마다 적어도 하나는 담는다. 그래야 허용 폭보다 긴 구절도 버려지지 않는다.
			var line = [];
			var used = 0;
			while ( index < words.length ) {
				var next = used + widths[ index ] + ( line.length ? gap : 0 );
				if ( line.length && next > allowed ) {
					break;
				}
				line.push( words[ index ] );
				used = next;
				index++;
			}
			out.push( line );
		}
		return ( index >= words.length ) ? out : null;
	}

	function layoutAll() {
		var clouds = document.querySelectorAll( '.kwc-cloud--ellipse' );
		for ( var i = 0; i < clouds.length; i++ ) {
			layoutEllipse( clouds[ i ] );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', layoutAll );
	} else {
		layoutAll();
	}
	// 글꼴이 늦게 오면 글자 폭이 달라져 다시 재야 한다.
	if ( document.fonts && document.fonts.ready ) {
		document.fonts.ready.then( layoutAll );
	}
	var resizeTimer = null;
	window.addEventListener( 'resize', function () {
		window.clearTimeout( resizeTimer );
		resizeTimer = window.setTimeout( layoutAll, 150 );
	} );

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '.kwc-refresh' ) : null;
		if ( ! button ) {
			return;
		}
		event.preventDefault();
		refresh( button );
	} );
} )();
