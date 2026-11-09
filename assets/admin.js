/* Eclair Export: diagnostic and export screen (no dependency). */
( function () {
	'use strict';
	var cfg = window.EclairExport;
	if ( ! cfg ) {
		return;
	}
	var t = cfg.i18n;
	var $ = function ( sel, root ) {
		return ( root || document ).querySelector( sel );
	};

	function post( action, data ) {
		var body = new FormData();
		body.append( 'action', 'eclair_export_' + action );
		body.append( 'nonce', cfg.nonce );
		Object.keys( data || {} ).forEach( function ( key ) {
			var value = data[ key ];
			if ( Array.isArray( value ) ) {
				value.forEach( function ( v ) {
					body.append( key + '[]', v );
				} );
			} else if ( value && typeof value === 'object' ) {
				Object.keys( value ).forEach( function ( k ) {
					body.append( key + '[' + k + ']', value[ k ] );
				} );
			} else {
				body.append( key, value );
			}
		} );
		return fetch( cfg.ajax, { method: 'POST', body: body, credentials: 'same-origin' } ).then( function ( res ) {
			return res.json().catch( function () {
				throw new Error( t.failed );
			} );
		} ).then( function ( json ) {
			if ( ! json || ! json.success ) {
				var err = new Error( ( json && json.data && json.data.message ) || t.failed );
				err.fatal = true;
				throw err;
			}
			return json.data;
		} );
	}

	/* ------------------------------------------------ Diagnostic */
	var runBtn = $( '#ee-run-diagnostic' );
	var result = $( '#ee-diagnostic-result' );
	if ( runBtn ) {
		runBtn.addEventListener( 'click', function () {
			runBtn.disabled = true;
			result.innerHTML = '<p class="ee-muted"><span class="spinner is-active ee-spin"></span>' + t.running + '</p>';
			post( 'diagnose' ).then( function ( data ) {
				result.innerHTML = data.html;
				$( '#ee-report' ).hidden = false;
				cfg.diagnosed = true;
				stepper();
			} ).catch( function ( e ) {
				result.innerHTML = '<p class="ee-error">' + e.message + '</p>';
			} ).then( function () {
				runBtn.disabled = false;
			} );
		} );
	}

	var brandForm = $( '#ee-branding-form' );
	if ( brandForm ) {
		brandForm.addEventListener( 'submit', function ( ev ) {
			ev.preventDefault();
			var branding = {};
			Array.prototype.forEach.call( brandForm.elements, function ( el ) {
				if ( el.name ) {
					branding[ el.name ] = el.value;
				}
			} );
			post( 'branding', { branding: branding } ).then( function () {
				$( '#ee-branding-status' ).textContent = t.saved;
			} ).catch( function ( e ) {
				$( '#ee-branding-status' ).textContent = e.message;
			} );
		} );
	}

	/* ------------------------------------------------ Migration: export, then transfer */
	var form = $( '#ee-export-form' );
	var box = $( '#ee-progress' );
	var trackExport = $( '#ee-track-export' );
	var trackSend = $( '#ee-track-send' );
	var errorBox = $( '#ee-run-error' );
	var startError = $( '#ee-start-error' );
	var resumeBtn = $( '#ee-resume' );
	var cancelBtn = $( '#ee-cancel' );
	var downloads = $( '#ee-downloads' );
	var delivered = $( '#ee-delivered' );
	var keyInput = $( '#ee-key' );
	var running = false;
	var failures = 0;
	var last = cfg.progress || { status: 'none', send: { status: 'none' } };

	function stepper() {
		var done = last.send && last.send.status === 'done';
		var stage = done ? 'eclair' : ( cfg.diagnosed || last.status !== 'none' ? 'migration' : 'diagnostic' );
		var order = [ 'diagnostic', 'migration', 'eclair' ];
		Array.prototype.forEach.call( document.querySelectorAll( '.ee-stepper li' ), function ( li ) {
			var at = order.indexOf( li.getAttribute( 'data-ee-stage' ) );
			li.classList.toggle( 'is-done', at < order.indexOf( stage ) );
			li.classList.toggle( 'is-current', at === order.indexOf( stage ) );
		} );
	}

	function track( el, percent, text, done ) {
		var bar = $( '.ee-bar', el );
		bar.querySelector( 'span' ).style.width = percent + '%';
		bar.setAttribute( 'aria-valuenow', percent );
		$( '.ee-progress-label', el ).textContent = percent + ' % · ' + ( text || '' );
		el.classList.toggle( 'is-done', !! done );
	}

	/** What to ask the server next: a step of the export, pieces of the transfer, or nothing. */
	function nextAction( p ) {
		var send = p.send || { status: 'none' };
		if ( p.status === 'running' ) {
			return 'step';
		}
		if ( p.status === 'done' && ( send.status === 'waiting' || send.status === 'running' ) ) {
			return 'send';
		}
		return null;
	}

	function show( p ) {
		last = p;
		var send = p.send || { status: 'none' };
		var arrived = send.status === 'done';
		var exported = p.status === 'done' || arrived;
		track( trackExport, exported ? 100 : ( p.percent || 0 ), arrived ? '' : p.label, exported );
		trackSend.hidden = send.status === 'none';
		if ( send.status !== 'none' ) {
			track( trackSend, send.percent || 0, send.label, arrived );
		}
		var error = p.error || send.error || '';
		errorBox.hidden = ! error;
		errorBox.textContent = error;
		delivered.hidden = ! arrived;
		downloads.hidden = ! ( p.status === 'done' && send.status === 'none' );
		cancelBtn.hidden = arrived || ! downloads.hidden;
		resumeBtn.hidden = ! ( p.status === 'error' || send.status === 'error' );
		if ( arrived ) {
			$( '#ee-delivered-label' ).textContent = send.label;
			$( '#ee-open-eclair' ).href = send.url;
		}
		if ( ! downloads.hidden ) {
			var list = $( 'ul', downloads );
			list.innerHTML = '';
			( p.parts || [] ).forEach( function ( part, i ) {
				var li = document.createElement( 'li' );
				var a = document.createElement( 'a' );
				a.className = 'button';
				a.href = part.url;
				a.textContent = t.download + ( p.parts.length > 1 ? ' · ' + t.part.replace( '%d', i + 1 ) : '' ) + ' (' + part.human + ')';
				a.addEventListener( 'click', function () {
					setTimeout( function () {
						li.classList.add( 'is-done' );
					}, 500 );
				} );
				li.appendChild( a );
				list.appendChild( li );
			} );
		}
		stepper();
	}

	function loop() {
		var action = running ? nextAction( last ) : null;
		if ( ! action ) {
			running = false;
			return;
		}
		post( action ).then( function ( p ) {
			failures = 0;
			show( p );
			setTimeout( loop, 150 );
		} ).catch( function ( e ) {
			failures++;
			if ( e.fatal || failures > 6 ) {
				running = false;
				errorBox.hidden = false;
				errorBox.textContent = e.message + ' ' + ( action === 'send' ? t.sendpause : t.paused );
				resumeBtn.hidden = false;
				return;
			}
			$( '.ee-progress-label', action === 'send' ? trackSend : trackExport ).textContent = t.retry;
			setTimeout( loop, Math.min( 30000, 2000 * Math.pow( 2, failures - 1 ) ) );
		} );
	}

	function startLoop() {
		running = true;
		failures = 0;
		resumeBtn.hidden = true;
		loop();
	}

	function reset() {
		running = false;
		last = { status: 'none', send: { status: 'none' } };
		box.hidden = true;
		form.hidden = false;
		stepper();
	}

	if ( form ) {
		var startBtn = form.querySelector( 'button[type="submit"]' );
		var relabel = function () {
			startBtn.textContent = keyInput.value.trim() ? t.start : t.startonly;
		};
		keyInput.addEventListener( 'input', relabel );
		relabel();
		form.addEventListener( 'submit', function ( ev ) {
			ev.preventDefault();
			var types = Array.prototype.map.call( form.querySelectorAll( 'input[name="types[]"]:checked' ), function ( el ) {
				return el.value;
			} );
			startBtn.disabled = true;
			startError.hidden = true;
			if ( keyInput.value.trim() ) {
				startBtn.textContent = t.checking;
			}
			post( 'start', {
				key: keyInput.value.trim(),
				types: types,
				media: form.querySelector( 'input[name="media"]:checked' ).value,
				part_mb: form.querySelector( 'select[name="part_mb"]' ).value,
				emails: form.querySelector( 'input[name="emails"]' ).checked ? 1 : 0
			} ).then( function ( p ) {
				form.hidden = true;
				box.hidden = false;
				keyInput.value = '';
				show( p );
				startLoop();
			} ).catch( function ( e ) {
				startError.hidden = false;
				startError.textContent = e.message;
			} ).then( function () {
				startBtn.disabled = false;
				relabel();
			} );
		} );
	}

	// Package ready without a key: it can still be sent instead of downloaded.
	$( '#ee-send-form' ).addEventListener( 'submit', function ( ev ) {
		ev.preventDefault();
		var input = $( '#ee-key-2' );
		var button = ev.target.querySelector( 'button' );
		button.disabled = true;
		errorBox.hidden = true;
		post( 'connect', { key: input.value.trim() } ).then( function ( p ) {
			input.value = '';
			show( p );
			startLoop();
		} ).catch( function ( e ) {
			errorBox.hidden = false;
			errorBox.textContent = e.message;
		} ).then( function () {
			button.disabled = false;
		} );
	} );

	resumeBtn.addEventListener( 'click', function () {
		var send = last.send || { status: 'none' };
		post( last.status === 'done' && send.status !== 'none' ? 'send' : 'step' ).then( show ).catch( function () {} ).then( startLoop );
	} );

	cancelBtn.addEventListener( 'click', function () {
		if ( ! window.confirm( t.confirm ) ) {
			return;
		}
		running = false;
		post( 'cancel' ).then( reset );
	} );

	[ '#ee-new', '#ee-new-2' ].forEach( function ( id ) {
		$( id ).addEventListener( 'click', function () {
			post( 'cancel' ).then( reset );
		} );
	} );

	window.addEventListener( 'beforeunload', function ( ev ) {
		if ( running ) {
			var text = nextAction( last ) === 'send' ? t.sendleave : t.leave;
			ev.preventDefault();
			ev.returnValue = text;
			return text;
		}
	} );

	// A migration already under way when the page opens: shown, and resumed on request.
	if ( last.status !== 'none' || ( last.send && last.send.status !== 'none' ) ) {
		form.hidden = true;
		box.hidden = false;
		show( last );
		if ( nextAction( last ) ) {
			resumeBtn.hidden = false;
			errorBox.hidden = false;
			errorBox.textContent = nextAction( last ) === 'send' ? t.sendpause : t.paused;
		}
	}
	stepper();
}() );
