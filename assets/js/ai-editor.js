/**
 * Editor assistant panel.
 *
 * Reads whatever editor is on screen — the block editor through wp.data, the
 * classic editor through the textarea — sends the text to the theme's own
 * endpoint, and puts the answer in a panel. Nothing touches the post until the
 * author clicks a button.
 */
( function () {
	'use strict';

	var config = window.AURORA_AI_EDITOR || {};
	var i18n = config.i18n || {};

	function blockEditor() {
		return !!( window.wp && wp.data && wp.data.select( 'core/editor' ) );
	}

	function getContent() {
		if ( blockEditor() ) {
			var content = wp.data.select( 'core/editor' ).getEditedPostContent();
			if ( content ) {
				return content;
			}
		}

		var textarea = document.getElementById( 'content' );
		return textarea ? textarea.value : '';
	}

	function getTitle() {
		if ( blockEditor() ) {
			return wp.data.select( 'core/editor' ).getEditedPostAttribute( 'title' ) || '';
		}

		var input = document.getElementById( 'title' );
		return input ? input.value : '';
	}

	/**
	 * Turn plain paragraphs into block markup, so "replace" does not leave the
	 * post as a single Classic block.
	 */
	function toBlocks( text ) {
		return String( text )
			.split( /\n{2,}/ )
			.map( function ( paragraph ) {
				var clean = paragraph.trim();
				if ( ! clean ) {
					return '';
				}
				var escaped = clean
					.replace( /&/g, '&amp;' )
					.replace( /</g, '&lt;' )
					.replace( />/g, '&gt;' );
				return '<!-- wp:paragraph -->\n<p>' + escaped + '</p>\n<!-- /wp:paragraph -->';
			} )
			.filter( Boolean )
			.join( '\n\n' );
	}

	function setContent( text ) {
		if ( blockEditor() ) {
			wp.data.dispatch( 'core/editor' ).editPost( { content: toBlocks( text ) } );
			return;
		}

		var textarea = document.getElementById( 'content' );
		if ( textarea ) {
			textarea.value = text;
		}
	}

	function setTitle( text ) {
		if ( blockEditor() ) {
			wp.data.dispatch( 'core/editor' ).editPost( { title: text } );
			return;
		}

		var input = document.getElementById( 'title' );
		if ( input ) {
			input.value = text;
		}
	}

	function init() {
		var root = document.querySelector( '[data-aurora-aiw]' );
		if ( ! root ) {
			return;
		}

		var state = root.querySelector( '[data-aurora-aiw-state]' );
		var output = root.querySelector( '[data-aurora-aiw-out]' );
		var textBox = root.querySelector( '[data-aurora-aiw-text]' );
		var buttons = root.querySelectorAll( '[data-aurora-aiw-run]' );
		var lastText = '';
		var lastMode = '';

		function say( message ) {
			if ( state ) {
				state.textContent = message || '';
			}
		}

		function call( mode ) {
			var text = getContent().trim();

			if ( text.replace( /<[^>]+>/g, '' ).trim().length < 10 ) {
				say( i18n.empty || '' );
				return;
			}

			Array.prototype.forEach.call( buttons, function ( button ) {
				button.disabled = true;
			} );
			say( i18n.working || '…' );

			fetch( ( config.root || '/wp-json/aurora/v1/' ) + 'ai/write', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': config.nonce || ''
				},
				body: JSON.stringify( { mode: mode, text: text, title: getTitle() } )
			} )
				.then( function ( response ) {
					return response.json().then( function ( data ) {
						if ( ! response.ok ) {
							throw new Error( ( data && data.message ) || 'HTTP ' + response.status );
						}
						return data;
					} );
				} )
				.then( function ( data ) {
					lastText = data.text;
					lastMode = data.mode;
					render( data.text, data.mode );
					say( '' );
				} )
				.catch( function ( error ) {
					say( ( i18n.failed || '' ) + error.message );
				} )
				.then( function () {
					Array.prototype.forEach.call( buttons, function ( button ) {
						button.disabled = false;
					} );
				} );
		}

		function render( text, mode ) {
			if ( ! output || ! textBox ) {
				return;
			}

			output.hidden = false;
			textBox.textContent = '';

			if ( 'titles' === mode ) {
				text.split( /\n+/ ).forEach( function ( line ) {
					var clean = line.replace( /^\s*\d+[.、)]\s*/, '' ).trim();
					if ( ! clean ) {
						return;
					}
					var button = document.createElement( 'button' );
					button.type = 'button';
					button.className = 'aurora-aiw__title';
					button.textContent = clean;
					button.title = '用作标题';
					button.addEventListener( 'click', function () {
						setTitle( clean );
						say( '已设为标题。' );
					} );
					textBox.appendChild( button );
				} );
				return;
			}

			text.split( /\n{2,}/ ).forEach( function ( paragraph ) {
				if ( ! paragraph.trim() ) {
					return;
				}
				var p = document.createElement( 'p' );
				p.textContent = paragraph.trim();
				textBox.appendChild( p );
			} );
		}

		Array.prototype.forEach.call( buttons, function ( button ) {
			button.addEventListener( 'click', function () {
				call( button.getAttribute( 'data-aurora-aiw-run' ) );
			} );
		} );

		var replace = root.querySelector( '[data-aurora-aiw-replace]' );
		var append = root.querySelector( '[data-aurora-aiw-append]' );
		var copy = root.querySelector( '[data-aurora-aiw-copy]' );
		var close = root.querySelector( '[data-aurora-aiw-close]' );

		if ( replace ) {
			replace.addEventListener( 'click', function () {
				if ( lastText ) {
					setContent( lastText );
					say( i18n.replaced || '' );
				}
			} );
		}

		if ( append ) {
			append.addEventListener( 'click', function () {
				if ( ! lastText ) {
					return;
				}
				// Read again: the author may have typed while the request ran.
				var current = getContent();
				setContent( current + '\n\n' + lastText );
				say( '已追加。' );
			} );
		}

		if ( copy ) {
			copy.addEventListener( 'click', function () {
				if ( ! lastText ) {
					return;
				}
				if ( navigator.clipboard ) {
					navigator.clipboard.writeText( lastText );
				}
				say( i18n.copied || '' );
			} );
		}

		if ( close && output ) {
			close.addEventListener( 'click', function () {
				output.hidden = true;
			} );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
