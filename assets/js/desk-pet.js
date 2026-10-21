/**
 * Desk pet behaviour.
 *
 * 插画是位图（assets/img/desk-pet-mascot.webp），做不了眨眼和瞳孔位移，所以「活着」的
 * 感觉靠三件事撑：卡片自己轻轻浮动（CSS）、隔一会儿点一下头、鼠标靠近时轻微
 * 视差倾斜。被点一下则弹起来说一句话。
 *
 * 所有效果都只是 class 或 transform：滚动里不读布局，定时器最密 30ms，
 * 访客开了「减少动态效果」就只保留说话。
 */
( function () {
	'use strict';

	var root = document.querySelector( '[data-aurora-pet]' );
	if ( ! root ) {
		return;
	}

	var btn = root.querySelector( '[data-aurora-pet-btn]' );
	var art = root.querySelector( '.aurora-pet__art' );
	var bubble = root.querySelector( '[data-aurora-pet-bubble]' );
	var lines = ( window.AURORA_PET && window.AURORA_PET.lines ) || [];
	var calm = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	var lastLine = -1;
	var bubbleTimer = 0;
	var actionTimer = 0;
	var nodTimer = 0;

	/* ---------------------------------------------------------- idle nods */

	function nod() {
		root.classList.add( 'is-nodding' );
		window.setTimeout( function () {
			root.classList.remove( 'is-nodding' );
		}, 900 );
	}

	function scheduleNod() {
		nodTimer = window.setTimeout( function () {
			nod();
			scheduleNod();
		}, 7000 + Math.random() * 5000 );
	}

	if ( ! calm ) {
		scheduleNod();
	}

	/* ------------------------------------------------------ pointer skew */

	var frame = 0;
	var pointer = { x: 0, y: 0 };

	function skew() {
		frame = 0;
		if ( ! art ) {
			return;
		}

		var box = root.getBoundingClientRect();
		if ( ! box.width ) {
			return;
		}

		var cx = box.left + box.width / 2;
		var cy = box.top + box.height / 2;

		var dx = Math.max( -1, Math.min( 1, ( pointer.x - cx ) / 260 ) );
		var dy = Math.max( -1, Math.min( 1, ( pointer.y - cy ) / 260 ) );

		art.style.transform = 'translate3d(' + ( dx * 3 ).toFixed( 2 ) + 'px,' +
			( dy * 2.4 ).toFixed( 2 ) + 'px,0) rotate(' + ( dx * 0.7 ).toFixed( 2 ) + 'deg)';
	}

	function resetSkew() {
		if ( art ) {
			art.style.transform = '';
		}
	}

	if ( ! calm && art ) {
		document.addEventListener( 'pointermove', function ( event ) {
			pointer.x = event.clientX;
			pointer.y = event.clientY;
			if ( ! frame ) {
				frame = window.requestAnimationFrame( skew );
			}
		}, { passive: true } );

		root.addEventListener( 'pointerleave', resetSkew );
	}

	/* ------------------------------------------------------------- talking */

	function say( text ) {
		if ( ! bubble || ! text ) {
			return;
		}

		bubble.textContent = text;
		bubble.hidden = false;
		root.classList.add( 'is-talking' );

		window.clearTimeout( bubbleTimer );
		bubbleTimer = window.setTimeout( function () {
			bubble.hidden = true;
			root.classList.remove( 'is-talking' );
		}, 3000 );
	}

	function pickLine() {
		if ( ! lines.length ) {
			return '';
		}

		var index = Math.floor( Math.random() * lines.length );
		if ( lines.length > 1 && index === lastLine ) {
			index = ( index + 1 ) % lines.length;
		}
		lastLine = index;

		return lines[ index ];
	}

	function poke() {
		if ( calm ) {
			say( pickLine() );
			return;
		}

		root.classList.remove( 'is-jumping' );
		// 连点两下也要重新播：强制一次重排，让动画从头开始。
		void root.offsetWidth;
		root.classList.add( 'is-jumping' );

		window.clearTimeout( actionTimer );
		actionTimer = window.setTimeout( function () {
			root.classList.remove( 'is-jumping' );
		}, 720 );

		say( pickLine() );
	}

	if ( btn ) {
		btn.addEventListener( 'click', poke );
	}

	/* --------------------------------------------------------- little moods */

	if ( ! calm ) {
		root.addEventListener( 'pointerenter', function () {
			root.classList.add( 'is-happy' );
		} );

		root.addEventListener( 'pointerleave', function () {
			root.classList.remove( 'is-happy' );
		} );

		// 从别的标签页回来时打个招呼，比一直沉默更像有人在。
		document.addEventListener( 'visibilitychange', function () {
			if ( ! document.hidden && Math.random() < 0.35 ) {
				say( pickLine() );
			}
		} );
	}

	// 页面在后台时别继续点头。
	document.addEventListener( 'visibilitychange', function () {
		if ( document.hidden ) {
			window.clearTimeout( nodTimer );
		} else if ( ! calm ) {
			window.clearTimeout( nodTimer );
			scheduleNod();
		}
	} );
}() );
