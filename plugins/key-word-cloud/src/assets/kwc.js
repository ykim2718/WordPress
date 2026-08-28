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
			label( button, 'Not configured', 'failed' );
			return;
		}

		var original = button.textContent;
		button.disabled = true;
		label( button, 'Fetching…' );

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
			label( button, 'Stored ' + result.body.stored );
			window.location.reload();
		} ).catch( function ( error ) {
			window.console && window.console.error( '[key-word-cloud] refresh failed: ' + error.message );
			label( button, 'Failed', 'failed' );
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

		// 폭부터 본다. 줄을 풀어 놓고 나서 빠져나가면 낱말이 줄 없이 흩어진 채 남는다.
		// 화면에 아직 놓이지 않았거나 낱말 하나도 못 담을 만큼 좁으면 손대지 않는다.
		var full = cloud.clientWidth;
		if ( full < 40 ) {
			return;
		}

		// 지난번과 같은 폭이면 다시 앉힐 것이 없다. 이 덕분에 자주 불러도 값이 싸고,
		// 폭이 달라졌으면 무엇이 알려 주었든 상관없이 스스로 고쳐 앉는다.
		if ( cloud.__kwcWidth === full ) {
			return;
		}
		cloud.__kwcWidth = full;

		// 지난 줄을 풀어 원래 낱말만 남기고 다시 잰다.
		while ( cloud.firstChild ) {
			cloud.removeChild( cloud.firstChild );
		}
		for ( var i = 0; i < words.length; i++ ) {
			cloud.appendChild( words[ i ] );
		}

		// 크기는 배율 1 에서 재고, 다른 배율의 폭은 비례로 계산한다. 글자 폭은 글자
		// 크기에 비례하므로 배율마다 DOM 을 다시 쓸 필요가 없다.
		cloud.style.setProperty( '--kwc-scale', 1 );
		var base = words.map( function ( word ) {
			var box = word.getBoundingClientRect();
			return { w: box.width, h: parseFloat( window.getComputedStyle( word ).fontSize ) };
		} );
		var gap = 12;

		// 목표. 높이를 px 로 받았으면 그것을 맞추고, 없으면 가로세로 비를 맞춘다.
		// 둘 다 좇으면 서로 어긋나므로 높이가 있으면 비는 쓰지 않는다.
		var wantHeight = parseFloat( cloud.getAttribute( 'data-height' ) );
		var wantRatio = parseFloat( cloud.getAttribute( 'data-ratio' ) );
		if ( ! ( wantRatio > 0 ) ) {
			wantRatio = 2;
		}

		// 배율을 무한정 낮추면 글씨가 읽히지 않으므로 0.55 에서 멈춘다.
		var chosen = null;
		var scales = [ 1, 0.92, 0.84, 0.76, 0.68, 0.62, 0.55 ];
		for ( var s = 0; s < scales.length; s++ ) {
			var trial = fitAtScale( words, base, scales[ s ], full, gap );
			if ( ! trial ) {
				continue;
			}
			chosen = trial;
			var reached = ( wantHeight > 0 ) ? ( trial.height <= wantHeight ) : ( trial.ratio >= wantRatio );
			if ( reached ) {
				break;   // 목표에 닿았다. 더 줄일 이유가 없다.
			}
		}
		if ( chosen && wantHeight > 0 && chosen.height > wantHeight ) {
			// 0.55 까지 줄여도 안 들어간다. 감추지 않고 알린다.
			window.console && window.console.warn(
				'[key-word-cloud] cannot fit ' + words.length + ' topics in ' + wantHeight
				+ 'px; it needs about ' + Math.round( chosen.height ) + 'px. Draw fewer topics or allow more height.'
			);
		}
		if ( ! chosen ) {
			cloud.classList.remove( 'kwc-cloud--rows' );
			window.console && window.console.warn( '[key-word-cloud] could not lay out the ellipse; left as a block' );
			return;
		}

		cloud.style.setProperty( '--kwc-scale', chosen.scale );
		var placed = chosen.placed;
		// 줄이 실제로 생긴 뒤에만 세로 쌓기를 켠다.
		cloud.classList.add( 'kwc-cloud--rows' );

		for ( var r = 0; r < placed.length; r++ ) {
			if ( ! placed[ r ].length ) {
				continue;   // 빈 줄은 만들지 않는다. 자리만 벌어진다.
			}
			var row = document.createElement( 'div' );
			row.className = 'kwc-row';
			for ( var j = 0; j < placed[ r ].length; j++ ) {
				row.appendChild( placed[ r ][ j ] );
			}
			cloud.appendChild( row );
		}
	}

	/**
	 * 한 배율에서 줄을 나누고, 그때의 가로세로 비를 낸다.
	 *
	 * 높이는 줄마다 가장 큰 글자를 기준으로 어림한다. DOM 을 다시 쓰지 않고 배율을
	 * 견주기 위한 것이고, 실제 배치는 고른 배율로 한 번만 한다.
	 *
	 * @param {Array}  words 낱말 요소들.
	 * @param {Array}  base  배율 1 에서 잰 {w, h}.
	 * @param {number} scale 시험할 배율.
	 * @param {number} full  칸 너비.
	 * @param {number} gap   낱말 사이 간격.
	 * @return {Object|null} {scale, placed, ratio} 또는 담지 못하면 null.
	 */
	function fitAtScale( words, base, scale, full, gap ) {
		var widths = base.map( function ( item ) {
			return item.w * scale;
		} );
		var total = widths.reduce( function ( sum, w ) {
			return sum + w;
		}, 0 );

		var rows = Math.max( 1, Math.ceil( ( total + gap * words.length ) / full ) );
		var placed = null;
		for ( var attempt = 0; attempt < 40 && ! placed; attempt++ ) {
			placed = place( words, widths, rows, full, gap );
			if ( ! placed ) {
				rows++;
			}
		}
		if ( ! placed ) {
			return null;
		}

		var height = 0;
		for ( var r = 0; r < placed.length; r++ ) {
			if ( ! placed[ r ].length ) {
				continue;
			}
			var tallest = 0;
			for ( var j = 0; j < placed[ r ].length; j++ ) {
				var at = words.indexOf( placed[ r ][ j ] );
				tallest = Math.max( tallest, base[ at ].h * scale );
			}
			height += tallest * 1.35;
		}
		return {
			scale: scale,
			placed: placed,
			height: height,
			ratio: height > 0 ? ( full / height ) : 0,
		};
	}

	/**
	 * 가운데부터 바깥으로 번갈아 가는 차례를 만든다. n=5 이면 [2, 1, 3, 0, 4].
	 *
	 * 큰 글자를 가운데 줄에 먼저 넣으려는 것이다.
	 */
	function centreOutward( n ) {
		var order = [];
		var mid = Math.floor( ( n - 1 ) / 2 );
		for ( var step = 0; order.length < n; step++ ) {
			var down = mid - step;
			var up = mid + step + ( n % 2 ? 1 : 0 );
			if ( 0 === step ) {
				order.push( mid );
				continue;
			}
			if ( down >= 0 ) {
				order.push( down );
			}
			if ( up < n && up !== down ) {
				order.push( up );
			}
			if ( down < 0 && up >= n ) {
				break;
			}
		}
		return order;
	}

	/**
	 * 한 줄 안에서도 큰 것이 가운데 오도록 다시 늘어놓는다.
	 *
	 * 들어온 것은 큰 것부터다. 번갈아 두 무더기에 담고 한쪽을 뒤집어 이으면
	 * 작은 것 - 큰 것 - 작은 것이 된다.
	 *
	 * @param {Array} line 큰 것부터 놓인 낱말들.
	 * @return {Array}
	 */
	function centreHeaviest( line ) {
		var left = [];
		var right = [];
		for ( var i = 0; i < line.length; i++ ) {
			if ( 0 === i % 2 ) {
				left.push( line[ i ] );
			} else {
				right.push( line[ i ] );
			}
		}
		return left.reverse().concat( right );
	}

	/**
	 * 낱말을 rows 줄에 담는다. 다 담지 못하면 null.
	 *
	 * words 는 글 수가 많은 것부터 와야 한다. 가운데 줄부터 채우므로 큰 글자가
	 * 가운데로 모이고, 남는 작은 것이 위아래 바깥 줄로 간다.
	 */
	function place( words, widths, rows, full, gap ) {
		var out = new Array( rows );
		for ( var i = 0; i < rows; i++ ) {
			out[ i ] = [];
		}

		var index = 0;
		var order = centreOutward( rows );
		for ( var o = 0; o < order.length; o++ ) {
			var k = order[ o ];
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
			out[ k ] = centreHeaviest( line );
		}
		return ( index >= words.length ) ? out : null;
	}

	/**
	 * 이 페이지와 같은 출처의 iframe 문서까지 모은다.
	 *
	 * 블록 편집기의 캔버스는 iframe 이다. 그 안의 구름도 앉혀야 편집 화면과 실제 화면이
	 * 같아진다. 다른 출처의 iframe 은 읽을 수 없으므로 조용히 건너뛴다.
	 */
	function documents() {
		var docs = [ document ];
		var frames = document.querySelectorAll( 'iframe' );
		for ( var i = 0; i < frames.length; i++ ) {
			var inner = null;
			try {
				inner = frames[ i ].contentDocument;
			} catch ( error ) {
				inner = null;   // cross-origin. 읽을 수 없는 것이 정상이다.
			}
			if ( inner && inner.body ) {
				docs.push( inner );
			}
		}
		return docs;
	}

	function layoutAll() {
		var docs = documents();
		for ( var d = 0; d < docs.length; d++ ) {
			var clouds = docs[ d ].querySelectorAll( '.kwc-cloud--ellipse' );
			for ( var i = 0; i < clouds.length; i++ ) {
				layoutEllipse( clouds[ i ] );
			}
		}
	}

	/**
	 * 구름이 나중에 들어오는 경우를 잡는다.
	 *
	 * 편집기의 미리보기는 서버에서 받아 와 나중에 붙고, 설정을 바꿀 때마다 통째로
	 * 다시 붙는다. 그때마다 새 요소이므로 폭 기억이 없어 다시 앉는다.
	 */
	var watched = [];
	var watcher = null;
	var pending = null;

	function onChange() {
		window.clearTimeout( pending );
		pending = window.setTimeout( function () {
			layoutAll();
			watchForClouds();   // 그 사이에 생긴 문서에도 붙는다
		}, 100 );
	}

	function watchForClouds() {
		if ( ! window.MutationObserver ) {
			return;
		}
		if ( ! watcher ) {
			watcher = new window.MutationObserver( onChange );
		}
		// 편집 캔버스 iframe 은 page load 뒤에 생긴다. 한 번 붙이고 끝내면 그 안의
		// 변화를 놓쳐, 설정을 바꿔 다시 그려진 구름이 줄 없이 남는다.
		var docs = documents();
		for ( var d = 0; d < docs.length; d++ ) {
			if ( watched.indexOf( docs[ d ] ) < 0 ) {
				watcher.observe( docs[ d ].body, { childList: true, subtree: true } );
				watched.push( docs[ d ] );
			}
		}
	}

	function start() {
		layoutAll();
		watchForClouds();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
	// 편집기의 캔버스 iframe 은 늦게 붙는다. 붙은 뒤 그 안도 감시해야 한다.
	window.addEventListener( 'load', watchForClouds );
	// 글꼴이 늦게 오면 글자 폭이 달라져 다시 재야 한다.
	if ( document.fonts && document.fonts.ready ) {
		document.fonts.ready.then( layoutAll );
	}
	// 그림이나 늦게 붙는 것이 자리를 밀어 칸 너비가 바뀌었을 수 있다.
	window.addEventListener( 'load', layoutAll );
	var resizeTimer = null;
	window.addEventListener( 'resize', function () {
		window.clearTimeout( resizeTimer );
		resizeTimer = window.setTimeout( layoutAll, 150 );
	} );

	// 구름이 숨어 있다가 나타나거나 칸 너비가 바뀌면 다시 앉힌다. window resize 만으로는
	// 접힌 영역 안에서 펼쳐지는 경우를 놓친다.
	if ( window.ResizeObserver ) {
		var observer = new window.ResizeObserver( function () {
			window.clearTimeout( resizeTimer );
			resizeTimer = window.setTimeout( layoutAll, 150 );
		} );
		var watch = function () {
			var docs = documents();
			for ( var d = 0; d < docs.length; d++ ) {
				var clouds = docs[ d ].querySelectorAll( '.kwc-cloud--ellipse' );
				for ( var i = 0; i < clouds.length; i++ ) {
					observer.observe( clouds[ i ] );
				}
			}
		};
		if ( 'loading' === document.readyState ) {
			document.addEventListener( 'DOMContentLoaded', watch );
		} else {
			watch();
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '.kwc-refresh' ) : null;
		if ( ! button ) {
			return;
		}
		event.preventDefault();
		refresh( button );
	} );
} )();
