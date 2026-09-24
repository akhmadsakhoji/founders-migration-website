/**
 * Founders Migration Website: Export, Import and Backups screens.
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 *
 * Plain JavaScript, no build step. Talks to the fmw/v1 REST API:
 * - jobs run in short slices, one request after another (POST /jobs/<id>/run),
 *   authenticated with the job's own token so a restore can finish after it
 *   replaced the users table;
 * - uploads go in chunks that each carry their offset, so a dropped
 *   connection or a reload resumes instead of starting over.
 */
( function () {
	'use strict';

	var config = window.FMW || {};
	var t      = config.i18n || {};
	var busy   = false;

	// ---------------------------------------------------------------- helpers

	function sprintf( format ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var next = 0;
		return String( format ).replace( /%(?:(\d+)\$)?([sd])/g, function ( match, position, type ) {
			var value = position ? args[ parseInt( position, 10 ) - 1 ] : args[ next++ ];
			return 'd' === type ? String( parseInt( value, 10 ) ) : String( value );
		} );
	}

	function bytes( value ) {
		var units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		var i     = 0;
		value     = Number( value ) || 0;
		while ( value >= 1024 && i < units.length - 1 ) {
			value /= 1024;
			i++;
		}
		return ( i ? value.toFixed( 1 ) : String( value ) ) + ' ' + units[ i ];
	}

	function duration( seconds ) {
		seconds = Math.max( 0, Math.round( seconds ) );
		var h   = Math.floor( seconds / 3600 );
		var m   = Math.floor( ( seconds % 3600 ) / 60 );
		var s   = seconds % 60;
		return h ? h + ' h ' + m + ' min' : ( m ? m + ' min ' + s + ' s' : s + ' s' );
	}

	function sleep( ms ) {
		return new Promise( function ( resolve ) {
			setTimeout( resolve, ms );
		} );
	}

	function el( tag, attributes, children ) {
		var node = document.createElement( tag );
		Object.keys( attributes || {} ).forEach( function ( key ) {
			if ( 'text' === key ) {
				node.textContent = attributes[ key ];
			} else if ( 'on' === key.slice( 0, 2 ) ) {
				node.addEventListener( key.slice( 2 ), attributes[ key ] );
			} else {
				node.setAttribute( key, attributes[ key ] );
			}
		} );
		( children || [] ).forEach( function ( child ) {
			node.appendChild( 'string' === typeof child ? document.createTextNode( child ) : child );
		} );
		return node;
	}

	function restUrl( path ) {
		var root = String( config.restRoot || '/' );
		return root + ( root.indexOf( '?' ) === -1 ? '?' : '&' ) + 'rest_route=' + encodeURIComponent( '/' + config.restNamespace + path );
	}

	/**
	 * Calls the REST API. With a token, no cookies are sent: the job token is the credential.
	 */
	function api( path, options ) {
		options     = options || {};
		var headers = options.headers || {};
		if ( options.token ) {
			headers[ 'X-FMW-Token' ] = options.token;
		} else {
			headers[ 'X-WP-Nonce' ] = config.nonce;
		}
		var body = options.body;
		if ( body && ! ( body instanceof Blob ) ) {
			headers[ 'Content-Type' ] = 'application/json';
			body                      = JSON.stringify( body );
		}
		return fetch( restUrl( path ), {
			method: options.method || 'GET',
			credentials: options.token ? 'omit' : 'same-origin',
			headers: headers,
			body: body,
		} ).then( function ( response ) {
			return response.text().then( function ( text ) {
				var data = null;
				try {
					data = text ? JSON.parse( text ) : null;
				} catch ( e ) {
					data = null;
				}
				if ( ! response.ok ) {
					var error    = new Error( ( data && data.message ) || ( response.status + ' ' + response.statusText ) );
					error.status = response.status;
					error.code   = data && data.code;
					error.data   = data && data.data;
					throw error;
				}
				return data;
			} );
		} );
	}

	// ------------------------------------------------------------------ modal

	var modal = {
		root: document.querySelector( '[data-fmw-modal]' ),
		part: function ( name ) {
			return this.root.querySelector( '[data-fmw-' + name + ']' );
		},
		open: function ( title ) {
			this.part( 'modal-title' ).textContent = title;
			this.part( 'modal-body' ).textContent  = '';
			this.part( 'progress-text' ).textContent   = '';
			this.part( 'progress-detail' ).textContent = '';
			this.part( 'log' ).textContent             = '';
			this.part( 'progress' ).hidden             = true;
			this.actions( [] );
			this.root.hidden = false;
		},
		close: function () {
			this.root.hidden = true;
		},
		body: function ( nodes ) {
			var body         = this.part( 'modal-body' );
			body.textContent = '';
			nodes.forEach( function ( node ) {
				body.appendChild( node );
			} );
		},
		progress: function ( fraction, text, detail ) {
			var bar                       = this.part( 'progress' );
			bar.hidden                    = false;
			bar.classList.toggle( 'fmw-indeterminate', null === fraction );
			this.part( 'progress-bar' ).style.width    = null === fraction ? '30%' : Math.round( fraction * 1000 ) / 10 + '%';
			this.part( 'progress-text' ).textContent   = text || '';
			this.part( 'progress-detail' ).textContent = detail || '';
		},
		log: function ( lines ) {
			var list         = this.part( 'log' );
			list.textContent = '';
			( lines || [] ).slice( -4 ).forEach( function ( line ) {
				list.appendChild( el( 'li', { text: String( line ).replace( /^\S+Z\s/, '' ) } ) );
			} );
		},
		actions: function ( buttons ) {
			var bar         = this.part( 'modal-actions' );
			bar.textContent = '';
			buttons.forEach( function ( button ) {
				bar.appendChild( button );
			} );
		},
		message: function ( text, kind ) {
			this.part( 'progress-text' ).textContent = '';
			this.part( 'progress-detail' ).textContent = '';
			this.body( [ el( 'div', { class: 'notice inline notice-' + ( kind || 'info' ) }, [ el( 'p', { text: text } ) ] ) ] );
		},
	};

	function button( label, onClick, primary ) {
		return el( 'button', { type: 'button', class: 'button' + ( primary ? ' button-primary' : '' ), text: label, onclick: onClick } );
	}

	function closeButton( reload ) {
		return button( t.close, function () {
			modal.close();
			if ( reload ) {
				window.location.reload();
			}
		} );
	}

	/**
	 * Speed from the bytes of the last 30 seconds.
	 */
	function Meter() {
		this.samples = [];
	}
	Meter.prototype.add = function ( done, phase ) {
		var now = Date.now();
		if ( this.phase !== phase ) {
			this.samples = [];
			this.phase   = phase;
		}
		this.samples.push( [ now, done ] );
		while ( this.samples.length > 2 && now - this.samples[ 0 ][ 0 ] > 30000 ) {
			this.samples.shift();
		}
		var first = this.samples[ 0 ];
		var span  = ( now - first[ 0 ] ) / 1000;
		return span > 1 ? ( done - first[ 1 ] ) / span : 0;
	};

	// ------------------------------------------------------------------- jobs

	/**
	 * Runs a job slice after slice until it ends; resolves with the final summary.
	 * With chained, a completed job leaves the modal to the caller (the next job).
	 */
	function runJob( job, token, title, chained ) {
		var meter   = new Meter();
		var failures = 0;
		busy        = true;
		modal.open( title );
		modal.progress( null, t.preparing );
		modal.actions( [ button( t.cancel, function () {
			if ( window.confirm( t.confirmCancel ) ) {
				api( '/jobs/' + job.id + '/cancel', { method: 'POST', token: token } ).catch( function () {} );
			}
		} ) ] );

		function show( summary ) {
			var speed  = meter.add( summary.bytes_done, summary.phase );
			var text   = sprintf( t.step, summary.step, summary.steps, summary.phase || '' );
			var detail = '';
			if ( summary.bytes_total > 0 ) {
				detail = bytes( summary.bytes_done ) + ' / ' + bytes( summary.bytes_total );
				if ( speed > 0 ) {
					detail += ' · ' + bytes( speed ) + '/s · ' + sprintf( t.eta, duration( ( summary.bytes_total - summary.bytes_done ) / speed ) );
				}
			}
			modal.progress( null === summary.progress ? null : summary.progress, text, detail );
			modal.log( summary.log );
		}

		function loop() {
			return api( '/jobs/' + job.id + '/run', { method: 'POST', token: token } ).then( function ( summary ) {
				failures = 0;
				show( summary );
				if ( 'completed' === summary.status || 'failed' === summary.status || 'cancelled' === summary.status ) {
					return summary;
				}
				return sleep( summary.busy ? 2000 : 50 ).then( loop );
			}, function ( error ) {
				failures++;
				if ( failures > 8 || ( error.status && 403 !== error.status && error.status < 500 ) ) {
					throw error;
				}
				var wait = Math.min( 60, Math.pow( 2, failures ) );
				modal.progress( null, sprintf( t.connectionLost, wait ) );
				return sleep( wait * 1000 ).then( loop );
			} );
		}

		return loop().then( function ( summary ) {
			busy = false;
			if ( ! ( chained && 'completed' === summary.status ) ) {
				finish( summary );
			}
			return summary;
		}, function ( error ) {
			busy = false;
			modal.message( t.failed + ' ' + error.message, 'error' );
			modal.actions( [ closeButton( true ) ] );
			error.shown = true;
			throw error;
		} );
	}

	function finish( summary ) {
		modal.part( 'progress' ).hidden = true;
		if ( 'completed' === summary.status && 'backup' === summary.type && summary.backup ) {
			modal.message( sprintf( t.backupDone, summary.backup.name, bytes( summary.backup.size ) ), 'success' );
			modal.actions( [
				el( 'a', { class: 'button button-primary', href: config.download + '&name=' + encodeURIComponent( summary.backup.name ), text: t.download } ),
				closeButton( false ),
			] );
		} else if ( 'completed' === summary.status && 'reset' === summary.type ) {
			modal.message( t.resetDone, 'success' );
			modal.actions( [ closeButton( true ) ] );
		} else if ( 'completed' === summary.status ) {
			modal.message( t.restoreDone, 'success' );
			modal.actions( [ el( 'a', { class: 'button button-primary', href: config.loginUrl, text: t.logIn } ) ] );
		} else if ( 'cancelled' === summary.status ) {
			modal.message( t.cancelled, 'warning' );
			modal.actions( [ closeButton( true ) ] );
		} else {
			modal.message( t.failed + ' ' + ( summary.error || '' ) + ' ' + t.failedHint, 'error' );
			modal.actions( [ closeButton( true ) ] );
		}
	}

	function startJob( request, title ) {
		return api( '/jobs', { method: 'POST', body: request } ).then( function ( created ) {
			return runJob( created, created.token, title );
		} );
	}

	// ----------------------------------------------------------------- export

	function exportSite( panel ) {
		var flags   = {};
		var request = { type: 'backup', flags: flags };
		panel.querySelectorAll( '.fmw-advanced input[type=checkbox]:checked' ).forEach( function ( box ) {
			flags[ box.name ] = true;
		} );
		if ( panel.querySelector( '[data-fmw-encrypt]' ).checked ) {
			var password = panel.querySelector( '[data-fmw-password]' ).value;
			if ( password.length < 8 ) {
				window.alert( t.passwordShort );
				return;
			}
			if ( password !== panel.querySelector( '[data-fmw-password-repeat]' ).value ) {
				window.alert( t.passwordMismatch );
				return;
			}
			request.password = password;
		}
		modal.open( t.export );
		modal.progress( null, t.preparing );
		startJob( request, t.export ).catch( function ( error ) {
			modal.message( error.message, 'error' );
			modal.actions( [ closeButton( false ) ] );
		} );
	}

	// ---------------------------------------------------------------- restore

	function confirmRestore( name ) {
		modal.open( t.restore );
		modal.progress( null, t.preparing );
		api( '/backups/' + encodeURIComponent( name ) + '/inspect' ).then( function ( info ) {
			modal.part( 'progress' ).hidden = true;
			modal.part( 'progress-text' ).textContent = '';

			var password = el( 'input', { type: 'password', class: 'regular-text', autocomplete: 'off' } );
			var keepOld  = el( 'input', { type: 'checkbox' } );
			var noEmail  = el( 'input', { type: 'checkbox' } );
			var error    = el( 'p', { class: 'fmw-error', role: 'alert' } );
			var rows     = [
				[ t.source, info.site_url ],
				[ t.createdBy, info.generator ],
				[ t.created, info.created ],
				[ t.size, bytes( info.size ) ],
				[ t.encrypted, info.encrypted ? t.yes : t.no ],
			];
			var table = el( 'table', { class: 'fmw-facts' }, rows.map( function ( row ) {
				return el( 'tr', {}, [ el( 'th', { text: row[ 0 ] } ), el( 'td', { text: row[ 1 ] || '-' } ) ] );
			} ) );
			var body = [
				el( 'p', {}, [ el( 'strong', { text: t.confirmRestore + ' ' } ), el( 'code', { text: info.name } ) ] ),
				table,
				el( 'div', { class: 'notice inline notice-warning' }, [ el( 'p', { text: t.restoreWarning } ) ] ),
			];
			if ( info.encrypted ) {
				body.push( el( 'p', {}, [ el( 'label', {}, [ t.password, el( 'br' ), password ] ) ] ) );
			}
			body.push( el( 'p', {}, [ el( 'label', {}, [ keepOld, ' ', t.keepOld ] ) ] ) );
			body.push( el( 'p', {}, [ el( 'label', {}, [ noEmail, ' ', t.noEmailReplace ] ) ] ) );
			body.push( error );
			modal.body( body );

			var go = button( t.restore, function () {
				go.disabled = true;
				error.textContent = '';
				var flags = {};
				if ( keepOld.checked ) {
					flags[ 'keep-old-tables' ] = true;
				}
				if ( noEmail.checked ) {
					flags[ 'exclude-email-replace' ] = true;
				}
				api( '/jobs', { method: 'POST', body: { type: 'restore', backup: info.name, password: password.value, flags: flags } } ).then( function ( created ) {
					return runJob( created, created.token, t.restore );
				} ).catch( function ( e ) {
					if ( ! modal.root.hidden && ( 'fmw_wrong_password' === e.code || 'fmw_password_required' === e.code ) ) {
						go.disabled       = false;
						error.textContent = e.message;
						password.focus();
						return;
					}
					modal.message( e.message, 'error' );
					modal.actions( [ closeButton( true ) ] );
				} );
			}, true );
			modal.actions( [ button( t.cancel, function () {
				modal.close();
			} ), go ] );
			if ( info.encrypted ) {
				password.focus();
			}
		} ).catch( function ( e ) {
			modal.message( e.message, 'error' );
			modal.actions( [ closeButton( false ) ] );
		} );
	}

	// ------------------------------------------------------------------ reset

	function resetParts( panel ) {
		return Array.prototype.map.call( panel.querySelectorAll( '[data-fmw-reset-part]:checked' ), function ( box ) {
			return box.value;
		} );
	}

	function resetReady( panel ) {
		var confirm = panel.querySelector( '[data-fmw-reset-confirm]' );
		return resetParts( panel ).length > 0 && confirm.value.trim().toLowerCase() === confirm.getAttribute( 'data-fmw-reset-confirm' );
	}

	function resetSite( panel ) {
		var parts = resetParts( panel );
		if ( ! parts.length ) {
			window.alert( t.resetNothing );
			return;
		}
		if ( ! resetReady( panel ) ) {
			window.alert( t.resetConfirm );
			return;
		}
		var request = { type: 'reset', parts: parts, confirm: panel.querySelector( '[data-fmw-reset-confirm]' ).value.trim() };
		var backup  = panel.querySelector( '[data-fmw-reset-backup]' ).checked;
		modal.open( t.reset );
		modal.progress( null, t.preparing );
		var first = backup
			? api( '/jobs', { method: 'POST', body: { type: 'backup', flags: {} } } ).then( function ( created ) {
				return runJob( created, created.token, t.safetyBackup, true );
			} )
			: Promise.resolve( null );

		first.then( function ( summary ) {
			if ( summary && 'completed' !== summary.status ) {
				return null; // The backup failed or was cancelled: runJob shows why, and nothing is reset.
			}
			var name = summary && summary.backup ? summary.backup.name : '';
			return api( '/jobs', { method: 'POST', body: request } ).then( function ( created ) {
				return runJob( created, created.token, t.reset );
			} ).then( function ( result ) {
				if ( name && 'completed' === result.status ) {
					modal.part( 'modal-body' ).appendChild( el( 'p', { text: sprintf( t.safetyBackupKept, name ) } ) );
				}
			} );
		} ).catch( function ( error ) {
			if ( error.shown ) {
				return; // runJob already shows it.
			}
			modal.message( error.message, 'error' );
			modal.actions( [ closeButton( true ) ] );
		} );
	}

	// ----------------------------------------------------------------- upload

	function upload( file ) {
		if ( ! /\.(fmw|wpress)$/i.test( file.name ) ) {
			window.alert( t.wrongType );
			return;
		}
		var meter    = new Meter();
		var failures = 0;
		var stopped  = false;
		var state;

		busy = true;
		modal.open( t.upload + ' ' + file.name );
		modal.progress( 0, t.preparing );
		modal.actions( [ button( t.cancel, function () {
			stopped = true;
			busy    = false;
			if ( state ) {
				api( '/uploads/' + state.id, { method: 'DELETE' } ).catch( function () {} );
			}
			modal.close();
		} ) ] );

		function send() {
			if ( stopped ) {
				return Promise.resolve( null );
			}
			if ( state.offset >= file.size ) {
				modal.progress( null, t.checking );
				return api( '/uploads/' + state.id + '/complete', { method: 'POST' } );
			}
			var end = Math.min( file.size, state.offset + state.chunk );
			return api( '/uploads/' + state.id, {
				method: 'POST',
				headers: { 'X-FMW-Offset': String( state.offset ), 'Content-Type': 'application/octet-stream' },
				body: file.slice( state.offset, end ),
			} ).then( function ( result ) {
				failures     = 0;
				state.offset = result.offset;
				var speed    = meter.add( state.offset, 'upload' );
				modal.progress( state.offset / file.size, bytes( state.offset ) + ' / ' + bytes( file.size ), speed > 0 ? bytes( speed ) + '/s · ' + sprintf( t.eta, duration( ( file.size - state.offset ) / speed ) ) : '' );
				return send();
			}, function ( error ) {
				if ( 409 === error.status && error.data && 'number' === typeof error.data.offset ) {
					state.offset = error.data.offset; // The server has more (or less) than we thought: continue from there.
					return send();
				}
				if ( 413 === error.status && state.chunk > 262144 ) {
					state.chunk = Math.max( 262144, Math.floor( state.chunk / 2 ) ); // A proxy limits request size.
					return send();
				}
				failures++;
				if ( failures > 10 || ( error.status && error.status < 500 && 408 !== error.status && 429 !== error.status ) ) {
					throw error;
				}
				var wait = Math.min( 60, Math.pow( 2, failures ) );
				modal.progress( state.offset / file.size, sprintf( t.connectionLost, wait ) );
				return sleep( wait * 1000 ).then( send );
			} );
		}

		api( '/uploads', { method: 'POST', body: { name: file.name, size: file.size } } ).then( function ( opened ) {
			state       = opened;
			state.chunk = Math.min( opened.chunk || config.chunk, config.chunk || opened.chunk );
			if ( state.offset > 0 && state.offset < file.size ) {
				modal.body( [ el( 'p', { text: sprintf( t.resuming, Math.floor( ( state.offset / file.size ) * 100 ) + '%' ) } ) ] );
			}
			return send();
		} ).then( function ( completed ) {
			busy = false;
			if ( completed && completed.backup ) {
				confirmRestore( completed.backup );
			}
		} ).catch( function ( error ) {
			busy = false;
			modal.message( error.message, 'error' );
			modal.actions( [ button( t.retry, function () {
				upload( file );
			}, true ), closeButton( false ) ] );
		} );
	}

	// --------------------------------------------------------------- backups

	function deleteBackup( row ) {
		var name = row.getAttribute( 'data-fmw-backup' );
		if ( ! window.confirm( sprintf( t.confirmDelete, name ) ) ) {
			return;
		}
		api( '/backups/' + encodeURIComponent( name ), { method: 'DELETE' } ).then( function () {
			row.parentNode.removeChild( row );
		}, function ( error ) {
			window.alert( error.message );
		} );
	}

	function listJobs( panel ) {
		var body = panel.querySelector( '[data-fmw-jobs-body]' );
		api( '/jobs' ).then( function ( jobs ) {
			body.textContent = '';
			panel.hidden     = ! jobs.length;
			jobs.forEach( function ( job ) {
				var label = ( 'backup' === job.type ? t.backup : ( 'reset' === job.type ? t.reset : t.restore + ' ' + ( job.archive || '' ) ) ) + ' · ' + job.id;
				var state = ( t.status && t.status[ job.status ] ) || job.status;
				body.appendChild( el( 'tr', {}, [
					el( 'td', { text: label } ),
					el( 'td', { text: state + ( job.error ? ': ' + job.error : '' ) } ),
					el( 'td', { text: sprintf( t.step, job.step, job.steps, job.phase || '' ) + ( null === job.progress ? '' : ' · ' + Math.floor( job.progress * 100 ) + '%' ) } ),
					el( 'td', {}, [
						button( t.continue, function () {
							api( '/jobs/' + job.id + '/token', { method: 'POST' } ).then( function ( renewed ) {
								runJob( renewed, renewed.token, { backup: t.export, reset: t.reset }[ job.type ] || t.restore ).catch( function () {} );
							}, function ( error ) {
								window.alert( error.message );
							} );
						}, true ),
						' ',
						button( t.cancel, function () {
							if ( window.confirm( t.confirmCancel ) ) {
								api( '/jobs/' + job.id + '/cancel', { method: 'POST' } ).then( function () {
									listJobs( panel );
								} );
							}
						} ),
					] ),
				] ) );
			} );
		} ).catch( function () {} );
	}

	// ------------------------------------------------------------------ wiring

	document.addEventListener( 'click', function ( event ) {
		var target = event.target.closest( '[data-fmw-action]' );
		if ( ! target ) {
			return;
		}
		var action = target.getAttribute( 'data-fmw-action' );
		if ( 'export' === action ) {
			exportSite( document.getElementById( 'fmw-export' ) );
		} else if ( 'restore' === action ) {
			confirmRestore( target.closest( '[data-fmw-backup]' ).getAttribute( 'data-fmw-backup' ) );
		} else if ( 'delete' === action ) {
			deleteBackup( target.closest( '[data-fmw-backup]' ) );
		} else if ( 'reset' === action ) {
			resetSite( document.getElementById( 'fmw-reset' ) );
		}
	} );

	var input = document.querySelector( '[data-fmw-file]' );
	if ( input ) {
		input.addEventListener( 'change', function () {
			if ( input.files && input.files[ 0 ] ) {
				upload( input.files[ 0 ] );
				input.value = '';
			}
		} );
	}

	var zone = document.querySelector( '[data-fmw-dropzone]' );
	if ( zone ) {
		[ 'dragenter', 'dragover' ].forEach( function ( type ) {
			zone.addEventListener( type, function ( event ) {
				event.preventDefault();
				zone.classList.add( 'fmw-dragging' );
			} );
		} );
		[ 'dragleave', 'drop' ].forEach( function ( type ) {
			zone.addEventListener( type, function ( event ) {
				event.preventDefault();
				zone.classList.remove( 'fmw-dragging' );
			} );
		} );
		zone.addEventListener( 'drop', function ( event ) {
			var files = event.dataTransfer && event.dataTransfer.files;
			if ( files && files[ 0 ] ) {
				upload( files[ 0 ] );
			}
		} );
	}

	var encrypt = document.querySelector( '[data-fmw-encrypt]' );
	if ( encrypt ) {
		encrypt.addEventListener( 'change', function () {
			document.querySelector( '[data-fmw-encrypt-fields]' ).hidden = ! encrypt.checked;
		} );
	}

	var resetPanel = document.getElementById( 'fmw-reset' );
	if ( resetPanel && resetPanel.querySelector( '[data-fmw-action=reset]' ) ) {
		var update = function () {
			resetPanel.querySelector( '[data-fmw-action=reset]' ).disabled = ! resetReady( resetPanel );
		};
		resetPanel.addEventListener( 'input', update );
		resetPanel.addEventListener( 'change', update );
	}

	var jobs = document.querySelector( '[data-fmw-jobs]' );
	if ( jobs ) {
		listJobs( jobs );
	}

	window.addEventListener( 'beforeunload', function ( event ) {
		if ( busy ) {
			event.preventDefault();
			event.returnValue = t.leaveWarning;
			return t.leaveWarning;
		}
	} );
}() );
