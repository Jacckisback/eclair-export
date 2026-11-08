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

	/* ------------------------------------------------ Export */
	var form = $( '#ee-export-form' );
	var box = $( '#ee-progress' );
	var bar = $( '.ee-bar', box );
	var label = $( '.ee-progress-label', box );
	var errorBox = $( '.ee-error', box );
	var resumeBtn = $( '#ee-resume' );
	var running = false;
	var failures = 0;

	function show( p ) {
		bar.querySelector( 'span' ).style.width = ( p.percent || 0 ) + '%';
		bar.setAttribute( 'aria-valuenow', p.percent || 0 );
		label.textContent = ( p.percent || 0 ) + ' % · ' + ( p.label || '' );
		errorBox.hidden = ! p.error;
		errorBox.textContent = p.error || '';
		if ( p.status === 'done' ) {
			var list = $( '#ee-downloads ul' );
			list.innerHTML = '';
			( p.parts || [] ).forEach( function ( part, i ) {
				var li = document.createElement( 'li' );
				var a = document.createElement( 'a' );
				a.className = 'button button-primary';
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
			$( '#ee-downloads' ).hidden = false;
			$( '#ee-cancel' ).hidden = true;
			resumeBtn.hidden = true;
		}
		if ( p.status === 'error' ) {
			resumeBtn.hidden = false;
		}
	}

	function loop() {
		if ( ! running ) {
			return;
		}
		post( 'step' ).then( function ( p ) {
			failures = 0;
			show( p );
			if ( p.status === 'running' ) {
				setTimeout( loop, 150 );
			} else {
				running = false;
			}
		} ).catch( function ( e ) {
			failures++;
			if ( e.fatal || failures > 6 ) {
				running = false;
				errorBox.hidden = false;
				errorBox.textContent = e.message + ' ' + t.paused;
				resumeBtn.hidden = false;
				return;
			}
			label.textContent = t.retry;
			setTimeout( loop, Math.min( 30000, 2000 * Math.pow( 2, failures - 1 ) ) );
		} );
	}

	function startLoop() {
		running = true;
		failures = 0;
		resumeBtn.hidden = true;
		loop();
	}

	if ( form ) {
		form.addEventListener( 'submit', function ( ev ) {
			ev.preventDefault();
			var types = Array.prototype.map.call( form.querySelectorAll( 'input[name="types[]"]:checked' ), function ( el ) {
				return el.value;
			} );
			var button = form.querySelector( 'button[type="submit"]' );
			button.disabled = true;
			post( 'start', {
				types: types,
				media: form.querySelector( 'input[name="media"]:checked' ).value,
				part_mb: form.querySelector( 'select[name="part_mb"]' ).value,
				emails: form.querySelector( 'input[name="emails"]' ).checked ? 1 : 0
			} ).then( function ( p ) {
				form.hidden = true;
				box.hidden = false;
				$( '#ee-downloads' ).hidden = true;
				$( '#ee-cancel' ).hidden = false;
				show( p );
				startLoop();
			} ).catch( function ( e ) {
				window.alert( e.message );
			} ).then( function () {
				button.disabled = false;
			} );
		} );
	}

	resumeBtn.addEventListener( 'click', function () {
		post( 'step' ).catch( function () {} ).then( startLoop );
	} );

	$( '#ee-cancel' ).addEventListener( 'click', function () {
		if ( ! window.confirm( t.confirm ) ) {
			return;
		}
		running = false;
		post( 'cancel' ).then( function () {
			box.hidden = true;
			form.hidden = false;
		} );
	} );

	$( '#ee-new' ).addEventListener( 'click', function () {
		post( 'cancel' ).then( function () {
			box.hidden = true;
			form.hidden = false;
		} );
	} );

	window.addEventListener( 'beforeunload', function ( ev ) {
		if ( running ) {
			ev.preventDefault();
			ev.returnValue = t.leave;
			return t.leave;
		}
	} );

	// An export already under way when the page opens: shown, and resumed.
	if ( cfg.progress && cfg.progress.status && cfg.progress.status !== 'none' ) {
		box.hidden = false;
		show( cfg.progress );
		if ( cfg.progress.status === 'running' ) {
			resumeBtn.hidden = false;
			errorBox.hidden = false;
			errorBox.textContent = t.paused;
		}
	}
}() );
