/**
 * LU3G Mail — pagina in bacheca.
 *
 * Schede (con l'indicatore che scorre), servizi già pronti, interruttori, barra "Salva" che compare quando
 * qualcosa cambia, email di prova col dialogo col server, registro con filtri, ricerca, pagine e dettaglio.
 * Tutto passa da admin-ajax, azione "lu3g_mail".
 */
( function () {
	'use strict';

	var cfg = window.LU3G_MAIL;
	var page = document.querySelector( '.lu3g-mail' );
	if ( ! cfg || ! page ) {
		return;
	}

	var form = page.querySelector( '[data-form]' );
	var state = Object.assign( {}, cfg.settings );
	var saved = JSON.stringify( state );
	var savebar = page.querySelector( '[data-savebar]' );
	var toasts = page.querySelector( '[data-toasts]' );

	/* ------------------------------------------------------------ server */

	function call( data ) {
		var body = new FormData();
		body.append( 'action', 'lu3g_mail' );
		body.append( 'nonce', cfg.nonce );
		Object.keys( data ).forEach( function ( k ) {
			body.append( k, data[ k ] );
		} );
		return fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				return r.text();
			} )
			.then( function ( text ) {
				var json;
				try {
					json = JSON.parse( text );
				} catch ( e ) {
					throw new Error( cfg.error );
				}
				if ( ! json.success ) {
					throw new Error( json.data && json.data.message ? json.data.message : cfg.error );
				}
				return json.data;
			}, function () {
				throw new Error( cfg.error );
			} );
	}

	function toast( text, kind ) {
		if ( ! text ) {
			return;
		}
		var el = document.createElement( 'div' );
		el.className = 'lm-toast' + ( kind ? ' is-' + kind : '' );
		el.setAttribute( 'role', 'error' === kind ? 'alert' : 'status' );
		el.textContent = text;
		toasts.appendChild( el );
		setTimeout( function () {
			el.classList.add( 'is-out' );
			setTimeout( function () {
				el.remove();
			}, 350 );
		}, 'error' === kind ? 7000 : 4000 );
	}

	function shake( el ) {
		el.classList.remove( 'lm-shake' );
		void el.offsetWidth;
		el.classList.add( 'lm-shake' );
	}

	/* ------------------------------------------------------------ schede */

	var tabs = Array.prototype.slice.call( page.querySelectorAll( '[data-tab]' ) );
	var ink = page.querySelector( '.lm-tabs__ink' );

	function moveInk( tab ) {
		ink.style.width = tab.offsetWidth + 'px';
		ink.style.transform = 'translateX(' + tab.offsetLeft + 'px)';
	}

	function openTab( name ) {
		tabs.forEach( function ( t ) {
			var on = t.getAttribute( 'data-tab' ) === name;
			t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			if ( on ) {
				moveInk( t );
			}
		} );
		page.querySelectorAll( '[data-panel]' ).forEach( function ( p ) {
			var on = p.getAttribute( 'data-panel' ) === name;
			p.hidden = ! on;
			p.classList.toggle( 'is-current', on );
			if ( on ) {
				p.classList.remove( 'is-entering' );
				void p.offsetWidth;
				p.classList.add( 'is-entering' );
			}
		} );
		if ( 'log' === name ) {
			loadLog();
		}
		try {
			window.history.replaceState( null, '', '#' + name );
		} catch ( e ) {}
	}

	tabs.forEach( function ( t ) {
		t.addEventListener( 'click', function () {
			openTab( t.getAttribute( 'data-tab' ) );
		} );
	} );
	window.addEventListener( 'resize', function () {
		var cur = page.querySelector( '[data-tab][aria-selected="true"]' );
		if ( cur ) {
			moveInk( cur );
		}
	} );
	page.querySelectorAll( '[data-goto]' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			openTab( b.getAttribute( 'data-goto' ) );
			page.querySelector( '.lm-tabs' ).scrollIntoView( { behavior: 'smooth', block: 'start' } );
		} );
	} );

	/* ------------------------------------------------------------ campi */

	function markDirty() {
		var pass = form.elements.password && form.elements.password.value;
		var dirty = JSON.stringify( state ) !== saved || !! pass;
		savebar.classList.toggle( 'is-dirty', dirty );
	}

	function syncShowIf() {
		page.querySelectorAll( '[data-show-if]' ).forEach( function ( el ) {
			var rule = el.getAttribute( 'data-show-if' ).split( '=' );
			var val = state[ rule[ 0 ] ];
			el.hidden = rule.length > 1 ? String( val ) !== rule[ 1 ] : ! val;
		} );
	}

	[ 'host', 'port', 'username', 'from_email', 'from_name', 'log_days', 'alert_email' ].forEach( function ( name ) {
		var input = form.elements[ name ];
		if ( input ) {
			input.addEventListener( 'input', function () {
				state[ name ] = 'port' === name || 'log_days' === name ? parseInt( input.value, 10 ) || '' : input.value;
				if ( 'host' === name || 'port' === name ) {
					highlightProvider();
				}
				markDirty();
			} );
		}
	} );
	if ( form.elements.password ) {
		form.elements.password.addEventListener( 'input', markDirty );
	}

	page.querySelectorAll( '.lm-switch[data-name]' ).forEach( function ( sw ) {
		sw.addEventListener( 'click', function () {
			var on = sw.getAttribute( 'aria-checked' ) !== 'true';
			sw.setAttribute( 'aria-checked', on ? 'true' : 'false' );
			state[ sw.getAttribute( 'data-name' ) ] = on;
			syncShowIf();
			markDirty();
		} );
	} );

	var seg = page.querySelector( '[data-seg="encryption"]' );
	function syncSeg() {
		seg.querySelectorAll( 'button' ).forEach( function ( b ) {
			b.setAttribute( 'aria-checked', b.getAttribute( 'data-value' ) === state.encryption ? 'true' : 'false' );
		} );
	}
	seg.addEventListener( 'click', function ( e ) {
		var b = e.target.closest( 'button' );
		if ( ! b || seg.hasAttribute( 'data-locked' ) ) {
			return;
		}
		state.encryption = b.getAttribute( 'data-value' );
		// Porta tipica della cifratura scelta, se la porta era una di quelle standard.
		if ( [ 465, 587, 25, '' ].indexOf( state.port ) !== -1 || ! state.port ) {
			state.port = { ssl: 465, tls: 587, none: 25 }[ state.encryption ];
			form.elements.port.value = state.port;
		}
		syncSeg();
		syncShowIf();
		markDirty();
	} );

	var eye = page.querySelector( '[data-eye]' );
	if ( eye ) {
		eye.addEventListener( 'click', function () {
			var p = form.elements.password;
			p.type = 'password' === p.type ? 'text' : 'password';
			eye.setAttribute( 'aria-label', 'password' === p.type ? 'Mostra la password' : 'Nascondi la password' );
		} );
	}

	/* ------------------------------------------------------------ servizi */

	var grid = page.querySelector( '[data-providers]' );
	var note = page.querySelector( '[data-provider-note]' );
	var keys = Object.keys( cfg.providers );

	var SVG = 'http://www.w3.org/2000/svg';

	/** Riquadro della scheda: logo o icona in bianco sul colore del fornitore (iniziali se manca l'icona). */
	function providerMark( key, name ) {
		var mark = document.createElement( 'span' );
		var icon = cfg.icons && cfg.icons[ key ];
		mark.className = 'lm-provider__mark';
		mark.setAttribute( 'aria-hidden', 'true' );
		if ( ! icon ) {
			mark.textContent = name.replace( /[^A-Za-z0-9 ]/g, ' ' ).trim().split( /\s+/ ).slice( 0, 2 ).map( function ( w ) {
				return w.charAt( 0 );
			} ).join( '' ).toUpperCase();
			return mark;
		}
		var svg = document.createElementNS( SVG, 'svg' );
		var path = document.createElementNS( SVG, 'path' );
		svg.setAttribute( 'viewBox', '0 0 24 24' );
		svg.setAttribute( 'focusable', 'false' );
		svg.setAttribute( 'class', 'lm-provider__icon lm-provider__icon--' + icon[ 1 ] );
		path.setAttribute( 'd', icon[ 2 ] );
		svg.appendChild( path );
		mark.style.setProperty( '--brand', icon[ 0 ] );
		mark.appendChild( svg );
		return mark;
	}

	keys.forEach( function ( key, n ) {
		var p = cfg.providers[ key ];
		var b = document.createElement( 'button' );
		var label = document.createElement( 'span' );
		b.type = 'button';
		b.className = 'lm-provider';
		b.setAttribute( 'role', 'radio' );
		b.setAttribute( 'data-key', key );
		b.style.setProperty( '--hue', ( n * 47 ) % 360 );
		label.textContent = p.name;
		b.appendChild( providerMark( key, p.name ) );
		b.appendChild( label );
		b.addEventListener( 'click', function () {
			pickProvider( key, true );
		} );
		grid.appendChild( b );
	} );

	function pickProvider( key, fill ) {
		var p = cfg.providers[ key ];
		state.provider = key;
		if ( fill && 'custom' !== key ) {
			if ( ! cfg.locked.host ) {
				state.host = p.host;
				form.elements.host.value = p.host;
			}
			if ( ! cfg.locked.port ) {
				state.port = p.port;
				form.elements.port.value = p.port;
			}
			if ( ! cfg.locked.encryption ) {
				state.encryption = p.enc;
			}
			state.auth = true;
			page.querySelector( '.lm-switch[data-name="auth"]' ).setAttribute( 'aria-checked', 'true' );
			if ( p.user && ! cfg.locked.username && ! state.username ) {
				state.username = p.user;
				form.elements.username.value = p.user;
			}
			syncSeg();
			syncShowIf();
			markDirty();
		}
		grid.querySelectorAll( '.lm-provider' ).forEach( function ( b ) {
			b.setAttribute( 'aria-checked', b.getAttribute( 'data-key' ) === key ? 'true' : 'false' );
		} );
		note.textContent = p.note || '';
	}

	function highlightProvider() {
		var match = keys.filter( function ( k ) {
			return 'custom' !== k && cfg.providers[ k ].host && cfg.providers[ k ].host === state.host;
		} )[ 0 ];
		pickProvider( match || ( state.host ? 'custom' : state.provider || 'custom' ), false );
	}

	/* ------------------------------------------------------------ salvataggio */

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		var btn = page.querySelector( '[data-save]' );
		var payload = Object.assign( {}, state );
		var pass = form.elements.password.value;
		if ( pass ) {
			payload.password = pass;
		}
		btn.disabled = true;
		btn.textContent = 'Salvataggio…';
		call( { op: 'save', settings: JSON.stringify( payload ) } ).then( function ( data ) {
			state = Object.assign( {}, data.settings );
			saved = JSON.stringify( state );
			form.elements.password.value = '';
			if ( data.hasPassword ) {
				form.elements.password.placeholder = '•••••••• salvata: lascia vuoto per non cambiarla';
			}
			setStatus( data.status );
			markDirty();
			toast( data.message );
			if ( data.warning ) {
				toast( data.warning, 'warn' );
			}
		} ).catch( function ( err ) {
			toast( err.message, 'error' );
		} ).then( function () {
			btn.disabled = false;
			btn.textContent = 'Salva';
		} );
	} );

	function setStatus( status ) {
		var el = page.querySelector( '[data-status]' );
		if ( ! el || ! status ) {
			return;
		}
		el.className = 'lm-hero__status lm-status--' + status[ 0 ];
		el.querySelector( 'span' ).textContent = status[ 1 ];
	}

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( savebar.classList.contains( 'is-dirty' ) ) {
			e.preventDefault();
			e.returnValue = '';
		}
	} );

	/* ------------------------------------------------------------ email di prova */

	var testBtn = page.querySelector( '[data-test]' );
	testBtn.addEventListener( 'click', function () {
		var to = form.elements.test_to.value.trim();
		var box = page.querySelector( '[data-test-result]' );
		var dbg = page.querySelector( '[data-test-debug]' );
		if ( savebar.classList.contains( 'is-dirty' ) ) {
			toast( 'Salva prima le modifiche: la prova usa le impostazioni salvate.', 'warn' );
			return;
		}
		testBtn.disabled = true;
		box.hidden = true;
		dbg.hidden = true;
		call( { op: 'test', to: to } ).then( function ( data ) {
			box.classList.toggle( 'is-error', ! data.ok );
			box.querySelector( '[data-test-title]' ).textContent = data.ok ? 'Inviata' : 'Non partita';
			box.querySelector( '[data-test-message]' ).textContent = data.message;
			box.hidden = false;
			if ( data.log && data.log.length ) {
				page.querySelector( '[data-test-log]' ).textContent = data.log.join( '\n' );
				dbg.hidden = false;
				dbg.open = ! data.ok;
			}
			if ( data.stats ) {
				page.querySelector( '[data-stat="sent"]' ).textContent = data.stats.sent;
				page.querySelector( '[data-stat="failed"]' ).textContent = data.stats.failed;
			}
			setStatus( data.status );
			if ( ! data.ok ) {
				shake( box );
			}
		} ).catch( function ( err ) {
			toast( err.message, 'error' );
		} ).then( function () {
			testBtn.disabled = false;
		} );
	} );

	/* ------------------------------------------------------------ registro */

	var logBox = page.querySelector( '[data-log]' );
	var pager = page.querySelector( '[data-pager]' );
	var logState = { status: '', search: '', page: 1 };
	var searchTimer = null;

	function loadLog() {
		call( { op: 'log', status: logState.status, search: logState.search, page: logState.page } ).then( function ( data ) {
			logBox.textContent = '';
			if ( ! data.rows.length ) {
				logBox.innerHTML = '<p class="lm-empty">Nessuna email nel registro.</p>';
			}
			data.rows.forEach( function ( row, i ) {
				var b = document.createElement( 'button' );
				b.type = 'button';
				b.className = 'lm-row lm-row--' + row.status + ' is-new';
				b.style.setProperty( '--i', i );
				b.innerHTML = '<span class="lm-row__dot" aria-hidden="true"></span><span class="lm-row__main"><span class="lm-row__subject"></span><span class="lm-row__to"></span></span><span class="lm-row__when"></span>';
				b.querySelector( '.lm-row__subject' ).textContent = row.subject || '(senza oggetto)';
				b.querySelector( '.lm-row__to' ).textContent = row.recipients + ( 'server' === row.via ? ' · server del sito' : '' );
				b.querySelector( '.lm-row__when' ).textContent = row.ago;
				b.querySelector( '.lm-row__when' ).title = row.when;
				if ( row.error ) {
					var err = document.createElement( 'span' );
					err.className = 'lm-row__err';
					err.textContent = ( 'rescued' === row.status ? 'Partita col server del sito. ' : '' ) + row.error;
					b.querySelector( '.lm-row__main' ).appendChild( err );
				}
				b.setAttribute( 'aria-label', ( 'sent' === row.status ? 'Inviata: ' : 'Non partita: ' ) + ( row.subject || '' ) );
				b.addEventListener( 'click', function () {
					openDetail( row.id );
				} );
				logBox.appendChild( b );
			} );
			var pages = Math.ceil( data.total / 25 );
			pager.textContent = '';
			for ( var p = 1; p <= pages && pages > 1; p++ ) {
				var pb = document.createElement( 'button' );
				pb.type = 'button';
				pb.textContent = p;
				if ( p === logState.page ) {
					pb.setAttribute( 'aria-current', 'true' );
				}
				pb.addEventListener( 'click', ( function ( n ) {
					return function () {
						logState.page = n;
						loadLog();
					};
				}( p ) ) );
				pager.appendChild( pb );
			}
		} ).catch( function ( err ) {
			logBox.innerHTML = '';
			toast( err.message, 'error' );
		} );
	}

	page.querySelectorAll( '[data-log-status]' ).forEach( function ( chip ) {
		chip.addEventListener( 'click', function () {
			page.querySelectorAll( '[data-log-status]' ).forEach( function ( c ) {
				c.classList.toggle( 'is-current', c === chip );
			} );
			logState.status = chip.getAttribute( 'data-log-status' );
			logState.page = 1;
			loadLog();
		} );
	} );
	page.querySelector( '[data-log-search]' ).addEventListener( 'input', function ( e ) {
		clearTimeout( searchTimer );
		searchTimer = setTimeout( function () {
			logState.search = e.target.value.trim();
			logState.page = 1;
			loadLog();
		}, 300 );
	} );
	page.querySelector( '[data-clear-log]' ).addEventListener( 'click', function () {
		if ( ! window.confirm( 'Svuotare tutto il registro? Le righe cancellate non si recuperano.' ) ) {
			return;
		}
		call( { op: 'clear' } ).then( function ( data ) {
			toast( data.message );
			loadLog();
		} ).catch( function ( err ) {
			toast( err.message, 'error' );
		} );
	} );

	/* ------------------------------------------------------------ dettaglio */

	var modal = page.querySelector( '[data-modal]' );
	var modalBody = modal.querySelector( '[data-modal-body]' );
	var lastFocus = null;

	function closeModal() {
		modal.hidden = true;
		modalBody.textContent = '';
		if ( lastFocus ) {
			lastFocus.focus();
		}
	}
	modal.addEventListener( 'click', function ( e ) {
		if ( e.target === modal || e.target.closest( '[data-close]' ) ) {
			closeModal();
		}
	} );
	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && ! modal.hidden ) {
			closeModal();
		}
	} );

	function openDetail( id ) {
		lastFocus = document.activeElement;
		call( { op: 'view', id: id } ).then( function ( row ) {
			var wrap = document.createElement( 'div' );
			wrap.className = 'lm-detail';
			var h = document.createElement( 'h3' );
			h.id = 'lm-modal-title';
			h.textContent = row.subject || '(senza oggetto)';
			wrap.appendChild( h );

			var dl = document.createElement( 'dl' );
			var labels = { sent: 'Inviata', failed: 'Non partita', rescued: 'Partita col server del sito dopo un errore SMTP' };
			[
				[ 'Esito', labels[ row.status ] || row.status ],
				[ 'Quando', row.when ],
				[ 'A', row.recipients ],
				[ 'Via', 'server' === row.via ? 'Server del sito' : 'SMTP' ],
				[ 'Errore', row.error ],
				[ 'Allegati', parseInt( row.attachments, 10 ) ? row.attachments : '' ],
			].forEach( function ( pair ) {
				if ( ! pair[ 1 ] ) {
					return;
				}
				var dt = document.createElement( 'dt' );
				var dd = document.createElement( 'dd' );
				dt.textContent = pair[ 0 ];
				dd.textContent = pair[ 1 ];
				dl.appendChild( dt );
				dl.appendChild( dd );
			} );
			wrap.appendChild( dl );

			if ( null === row.message ) {
				var p = document.createElement( 'p' );
				p.className = 'lm-note';
				p.textContent = 'Il testo non è salvato (scheda Opzioni → "Salva anche il testo").';
				wrap.appendChild( p );
			} else if ( row.html ) {
				// Anteprima isolata: senza script, senza accesso alla pagina.
				var frame = document.createElement( 'iframe' );
				frame.setAttribute( 'sandbox', '' );
				frame.setAttribute( 'title', 'Anteprima dell\'email' );
				frame.srcdoc = row.message;
				wrap.appendChild( frame );
			} else {
				var pre = document.createElement( 'pre' );
				pre.textContent = row.message;
				wrap.appendChild( pre );
			}

			if ( null !== row.message ) {
				var actions = document.createElement( 'div' );
				actions.className = 'lm-detail__actions';
				var again = document.createElement( 'button' );
				again.type = 'button';
				again.className = 'lm-btn lm-btn--primary';
				again.textContent = 'Rimanda';
				again.addEventListener( 'click', function () {
					again.disabled = true;
					call( { op: 'resend', id: row.id } ).then( function ( d ) {
						toast( d.message );
						closeModal();
						loadLog();
					} ).catch( function ( err ) {
						toast( err.message, 'error' );
						again.disabled = false;
					} );
				} );
				actions.appendChild( again );
				wrap.appendChild( actions );
			}

			modalBody.textContent = '';
			modalBody.appendChild( wrap );
			modal.hidden = false;
			modal.querySelector( '[data-close]' ).focus();
		} ).catch( function ( err ) {
			toast( err.message, 'error' );
		} );
	}

	/* ------------------------------------------------------------ avvio */

	syncSeg();
	syncShowIf();
	highlightProvider();
	var start = ( window.location.hash || '' ).replace( '#', '' );
	openTab( tabs.some( function ( t ) {
		return t.getAttribute( 'data-tab' ) === start;
	} ) ? start : 'connection' );
}() );
