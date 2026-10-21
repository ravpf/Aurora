/**
 * Front-end AI: the summary card and the /ai-ask form.
 *
 * No framework, no build step — this file ships as-is. Both widgets talk to the
 * same REST namespace and both stay silent when the theme has AI switched off;
 * the PHP side only prints the markup when it can work.
 */
( function () {
	'use strict';

	var config = window.AURORA_AI || {};
	var i18n = config.i18n || {};

	function post( path, body ) {
		return fetch( ( config.root || '/wp-json/aurora/v1/' ) + path, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce || ''
			},
			body: JSON.stringify( body || {} )
		} ).then( function ( response ) {
			return response.json().then( function ( data ) {
				if ( ! response.ok ) {
					throw new Error( ( data && data.message ) || 'HTTP ' + response.status );
				}
				return data;
			} );
		} );
	}

	/* ---------------------------------------------------------------- summary */

	function initSummary() {
		var card = document.querySelector( '[data-aurora-aisum]' );

		if ( ! card ) {
			return;
		}

		var id = card.getAttribute( 'data-aurora-aisum' );
		var text = card.querySelector( '[data-aurora-aisum-text]' );
		var make = card.querySelector( '[data-aurora-aisum-make]' );
		var redo = card.querySelector( '[data-aurora-aisum-redo]' );
		var hint = card.querySelector( '.aurora-aisum__hint' );
		var note = card.querySelector( '[data-aurora-aisum-note]' );

		function run( force ) {
			if ( make ) {
				make.disabled = true;
				make.textContent = i18n.working || '…';
			}
			if ( redo ) {
				redo.disabled = true;
			}
			if ( hint ) {
				hint.textContent = i18n.working || '…';
			}

			post( 'ai/summary', { post_id: parseInt( id, 10 ), force: !! force } )
				.then( function ( data ) {
					if ( text ) {
						text.textContent = data.summary;
						text.hidden = false;
					}
					if ( note ) {
						note.hidden = false;
					}
					if ( make ) {
						make.remove();
					}
					if ( hint ) {
						hint.textContent = '';
					}
					if ( redo ) {
						redo.disabled = false;
					}
				} )
				.catch( function ( error ) {
					if ( hint ) {
						hint.textContent = ( i18n.failed || '' ) + error.message;
					}
					if ( make ) {
						make.disabled = false;
						make.textContent = i18n.retry || '重试';
					}
					if ( redo ) {
						redo.disabled = false;
					}
				} );
		}

		if ( make ) {
			make.addEventListener( 'click', function () {
				run( false );
			} );
		}

		if ( redo ) {
			redo.addEventListener( 'click', function () {
				run( true );
			} );
		}
	}

	/* ------------------------------------------------------------------- q&a */

	function initAsk() {
		var form = document.querySelector( '[data-aurora-qa-form]' );

		if ( ! form ) {
			return;
		}

		var input = form.querySelector( 'input[name="question"]' );
		var button = form.querySelector( 'button[type="submit"]' );
		var result = document.querySelector( '[data-aurora-qa-result]' );
		var answer = document.querySelector( '[data-aurora-qa-answer]' );
		var sourceBox = document.querySelector( '[data-aurora-qa-sources]' );

		function renderSources( sources ) {
			if ( ! sourceBox ) {
				return;
			}

			sourceBox.textContent = '';

			if ( ! sources || ! sources.length ) {
				return;
			}

			var label = document.createElement( 'span' );
			label.className = 'aurora-aiqa__sources-label';
			label.textContent = i18n.sources || '出处';
			sourceBox.appendChild( label );

			var list = document.createElement( 'ol' );
			list.className = 'aurora-aiqa__sources-list';

			sources.forEach( function ( source, index ) {
				var item = document.createElement( 'li' );
				var link = document.createElement( 'a' );
				link.href = source.url;
				link.textContent = '[' + ( index + 1 ) + '] ' + source.title;
				item.appendChild( link );
				list.appendChild( item );
			} );

			sourceBox.appendChild( list );
		}

		function renderAnswer( text ) {
			if ( ! answer ) {
				return;
			}

			answer.textContent = '';

			String( text ).split( /\n{2,}/ ).forEach( function ( paragraph ) {
				if ( ! paragraph.trim() ) {
					return;
				}
				var p = document.createElement( 'p' );
				p.textContent = paragraph.trim();
				answer.appendChild( p );
			} );
		}

		function ask() {
			var question = ( input && input.value || '' ).trim();

			if ( question.length < 2 ) {
				if ( input ) {
					input.focus();
				}
				return;
			}

			if ( result ) {
				result.hidden = false;
			}
			if ( answer ) {
				answer.textContent = i18n.thinking || '…';
			}
			if ( sourceBox ) {
				sourceBox.textContent = '';
			}
			if ( button ) {
				button.disabled = true;
			}

			post( 'ai/ask', { question: question } )
				.then( function ( data ) {
					renderAnswer( data.answer );
					renderSources( data.sources );
				} )
				.catch( function ( error ) {
					if ( answer ) {
						answer.textContent = ( i18n.failed || '' ) + error.message;
					}
				} )
				.then( function () {
					if ( button ) {
						button.disabled = false;
					}
				} );
		}

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			ask();
		} );

		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-aurora-qa-example]' ),
			function ( chip ) {
				chip.addEventListener( 'click', function () {
					if ( input ) {
						input.value = chip.getAttribute( 'data-aurora-qa-example' );
					}
					ask();
				} );
			}
		);
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			initSummary();
			initAsk();
		} );
	} else {
		initSummary();
		initAsk();
	}
}() );
