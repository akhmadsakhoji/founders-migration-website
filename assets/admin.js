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
			this.part( 'modal-body' ).textContent = ''; // Nothing (a pull key, say) lingers in the page.
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

	function remoteLine( summary ) {
		return summary.remote ? el( 'p', { text: sprintf( t.uploadedTo, summary.remote.name, summary.remote.key ) } ) : null;
	}

	function finish( summary ) {
		modal.part( 'progress' ).hidden = true;
		if ( 'completed' === summary.status && 'backup' === summary.type && summary.deleted_local ) {
			modal.message( sprintf( t.uploadedTo, summary.remote.name, summary.remote.key ), 'success' );
			modal.part( 'modal-body' ).appendChild( el( 'p', { text: t.deletedLocal } ) );
			modal.actions( [ closeButton( false ) ] );
		} else if ( 'completed' === summary.status && 'backup' === summary.type && summary.backup ) {
			modal.message( sprintf( t.backupDone, summary.backup.name, bytes( summary.backup.size ) ), 'success' );
			if ( summary.remote ) {
				modal.part( 'modal-body' ).appendChild( remoteLine( summary ) );
			}
			modal.actions( [
				el( 'a', { class: 'button button-primary', href: config.download + '&name=' + encodeURIComponent( summary.backup.name ), text: t.download } ),
				closeButton( false ),
			] );
		} else if ( 'completed' === summary.status && 'upload' === summary.type ) {
			modal.message( sprintf( t.uploadedTo, summary.remote.name, summary.remote.key ), 'success' );
			modal.actions( [ closeButton( false ) ] );
		} else if ( 'completed' === summary.status && ( 'download' === summary.type || 'pull' === summary.type ) && summary.backup ) {
			modal.message( sprintf( 'pull' === summary.type ? t.pullDone : t.downloaded, summary.backup.name ), 'success' );
			modal.actions( [
				button( t.restore, function () {
					confirmRestore( summary.backup.name );
				}, true ),
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
		var storage = panel.querySelector( '[data-fmw-export-storage]' );
		if ( storage && storage.value ) {
			flags.storage = storage.value;
			if ( panel.querySelector( '[data-fmw-export-delete-local] input' ).checked ) {
				flags[ 'delete-local' ] = true;
			}
		}
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

	// -------------------------------------------------------------- schedules

	var schedules = {};

	function loadSchedules() {
		var body = document.querySelector( '[data-fmw-schedule-rows]' );
		return api( '/schedules' ).then( function ( data ) {
			body.textContent = '';
			schedules        = {};
			if ( ! data.schedules.length ) {
				body.appendChild( el( 'tr', {}, [ el( 'td', { colspan: '6', text: t.noSchedules } ) ] ) );
			}
			data.schedules.forEach( function ( schedule ) {
				schedules[ schedule.id ] = schedule;
				var state  = schedule.state || {};
				var status = state.last_status ? ( t.runStatus[ state.last_status ] || state.last_status ) : '';
				var last   = el( 'td', {}, [ state.last_run ? schedule.last_run_text : '—' ] );
				if ( status ) {
					last.appendChild( el( 'br' ) );
					last.appendChild( el( 'span', { class: 'fmw-status fmw-status-' + state.last_status, text: status + ( state.last_error ? ': ' + state.last_error : '' ) } ) );
				}
				var name = el( 'td', {}, [ el( 'strong', { text: schedule.name } ) ] );
				if ( schedule.encrypted ) {
					name.appendChild( el( 'span', { class: 'dashicons dashicons-lock', title: t.encrypted } ) );
				}
				if ( ! schedule.enabled ) {
					name.appendChild( el( 'span', { class: 'fmw-badge', text: t.disabled } ) );
				}
				body.appendChild( el( 'tr', { 'data-fmw-schedule': schedule.id }, [
					name,
					el( 'td', { text: schedule.description } ),
					el( 'td', { text: schedule.enabled ? schedule.next_run_text : '—' } ),
					last,
					el( 'td', { text: schedule.keep ? String( schedule.keep ) : t.keepAll } ),
					el( 'td', { class: 'fmw-row-actions' }, [
						button( t.runNow, function () {
							runSchedule( schedule );
						}, true ),
						button( t.edit, function () {
							editSchedule( schedule );
						} ),
						button( schedule.enabled ? t.disable : t.enable, function () {
							api( '/schedules/' + schedule.id, { method: 'POST', body: { enabled: ! schedule.enabled } } ).then( loadSchedules, function ( error ) {
								window.alert( error.message );
							} );
						} ),
						button( t.delete, function () {
							if ( window.confirm( sprintf( t.confirmDeleteSchedule, schedule.name ) ) ) {
								api( '/schedules/' + schedule.id, { method: 'DELETE' } ).then( loadSchedules, function ( error ) {
									window.alert( error.message );
								} );
							}
						} ),
					] ),
				] ) );
			} );
		} ).catch( function ( error ) {
			body.textContent = '';
			body.appendChild( el( 'tr', {}, [ el( 'td', { colspan: '6', class: 'fmw-error', text: error.message } ) ] ) );
		} );
	}

	function runSchedule( schedule ) {
		modal.open( schedule.name );
		modal.progress( null, t.preparing );
		api( '/schedules/' + schedule.id + '/run', { method: 'POST' } ).then( function ( created ) {
			return runJob( created, created.token, schedule.name );
		} ).catch( function ( error ) {
			if ( ! error.shown ) {
				modal.message( error.message, 'error' );
				modal.actions( [ closeButton( false ) ] );
			}
		} ).then( loadSchedules );
	}

	function scheduleForm() {
		return document.querySelector( '[data-fmw-schedule-form]' );
	}

	function showFrequency( form ) {
		var frequency = form.elements.frequency.value;
		form.querySelectorAll( '[data-fmw-when]' ).forEach( function ( part ) {
			part.hidden = part.getAttribute( 'data-fmw-when' ) !== frequency;
		} );
	}

	function showPassword( form, kept ) {
		var on = form.elements.encrypt.checked;
		form.querySelector( '[data-fmw-schedule-password]' ).hidden      = ! on;
		form.querySelector( '[data-fmw-schedule-password-kept]' ).hidden = ! ( on && kept );
	}

	function showRemote( form ) {
		form.querySelector( '[data-fmw-schedule-remote]' ).hidden = ! form.elements.storage.value;
	}

	function resetScheduleForm() {
		var form  = scheduleForm();
		var title = document.querySelector( '[data-fmw-schedule-title]' );
		form.reset();
		form.elements.id.value = '';
		title.textContent      = title.getAttribute( 'data-add' );
		form.querySelector( '[data-fmw-schedule-cancel]' ).hidden = true;
		form.querySelector( '[data-fmw-schedule-error]' ).textContent = '';
		showFrequency( form );
		showRemote( form );
		showPassword( form, false );
	}

	function editSchedule( schedule ) {
		var form  = scheduleForm();
		var title = document.querySelector( '[data-fmw-schedule-title]' );
		resetScheduleForm();
		form.elements.id.value        = schedule.id;
		form.elements.name.value      = schedule.name;
		form.elements.frequency.value = schedule.frequency;
		form.elements.time.value      = schedule.time;
		form.elements.weekday.value   = String( schedule.weekday );
		form.elements.monthday.value  = String( schedule.monthday );
		form.elements.keep.value      = String( schedule.keep );
		form.elements.notify.value    = schedule.notify;
		form.elements.email.value     = schedule.email;
		form.elements.storage.value   = schedule.storage || '';
		form.elements.remote_keep.value = String( undefined === schedule.remote_keep ? 30 : schedule.remote_keep );
		form.elements.keep_local.checked = false !== schedule.keep_local;
		form.elements.enabled.checked = !! schedule.enabled;
		form.elements.encrypt.checked = !! schedule.encrypted;
		form.querySelectorAll( '[data-fmw-flag]' ).forEach( function ( box ) {
			box.checked = !! ( schedule.flags && schedule.flags[ box.getAttribute( 'data-fmw-flag' ) ] );
		} );
		title.textContent = title.getAttribute( 'data-edit' ) + ': ' + schedule.name;
		form.querySelector( '[data-fmw-schedule-cancel]' ).hidden = false;
		showFrequency( form );
		showRemote( form );
		showPassword( form, schedule.encrypted );
		form.scrollIntoView( { behavior: 'smooth', block: 'start' } );
	}

	function saveSchedule( form ) {
		var error    = form.querySelector( '[data-fmw-schedule-error]' );
		var id       = form.elements.id.value;
		var existing = id ? schedules[ id ] : null;
		var flags    = {};
		form.querySelectorAll( '[data-fmw-flag]:checked' ).forEach( function ( box ) {
			flags[ box.getAttribute( 'data-fmw-flag' ) ] = true;
		} );
		var body = {
			name: form.elements.name.value,
			frequency: form.elements.frequency.value,
			time: form.elements.time.value,
			weekday: form.elements.weekday.value,
			monthday: form.elements.monthday.value,
			keep: form.elements.keep.value,
			notify: form.elements.notify.value,
			email: form.elements.email.value,
			enabled: form.elements.enabled.checked,
			flags: flags,
			storage: form.elements.storage.value,
			remote_keep: form.elements.remote_keep.value,
			keep_local: form.elements.keep_local.checked,
		};
		var password = form.elements.password.value;
		error.textContent = '';
		if ( ! form.elements.encrypt.checked ) {
			body.password = '';
		} else if ( password || ! ( existing && existing.encrypted ) ) {
			if ( password.length < 8 ) {
				error.textContent = t.passwordShort;
				return;
			}
			if ( password !== form.elements.password_repeat.value ) {
				error.textContent = t.passwordMismatch;
				return;
			}
			body.password = password;
		}
		api( '/schedules' + ( id ? '/' + id : '' ), { method: 'POST', body: body } ).then( function () {
			resetScheduleForm();
			return loadSchedules();
		}, function ( e ) {
			error.textContent = e.message;
		} );
	}

	// ------------------------------------------------------------ cloud storage

	function startRemoteJob( path, body, title ) {
		modal.open( title );
		modal.progress( null, t.preparing );
		return api( path, { method: 'POST', body: body } ).then( function ( created ) {
			return runJob( created, created.token, title );
		} ).catch( function ( error ) {
			if ( ! error.shown ) {
				modal.message( error.message, 'error' );
				modal.actions( [ closeButton( false ) ] );
			}
		} );
	}

	function uploadBackup( name ) {
		var select = el( 'select', {}, ( config.storages || [] ).map( function ( storage ) {
			return el( 'option', { value: storage.id, text: storage.name } );
		} ) );
		modal.open( t.uploadTitle );
		modal.body( [ el( 'p', {}, [ el( 'code', { text: name } ) ] ), el( 'p', {}, [ el( 'label', {}, [ t.uploadTitle + ': ', select ] ) ] ) ] );
		modal.actions( [ button( t.cancel, function () {
			modal.close();
		} ), button( t.uploadButton, function () {
			startRemoteJob( '/backups/' + encodeURIComponent( name ) + '/upload', { storage: select.value }, t.uploadTitle );
		}, true ) ] );
	}

	var storages = {};

	function storageStatus( text, kind ) {
		var status         = document.querySelector( '[data-fmw-storage-status]' );
		status.textContent = text || '';
		status.className   = 'fmw-test-result' + ( kind ? ' fmw-status-' + kind : '' );
	}

	function loadStorages() {
		var body = document.querySelector( '[data-fmw-storage-rows]' );
		return api( '/storages' ).then( function ( data ) {
			body.textContent = '';
			storages         = {};
			if ( ! data.storages.length ) {
				body.appendChild( el( 'tr', {}, [ el( 'td', { colspan: '4', text: t.noStorages } ) ] ) );
			}
			data.storages.forEach( function ( storage ) {
				storages[ storage.id ] = storage;
				var drive   = 'gdrive' === storage.provider;
				var service = drive ? storage.provider_label + ' · ' + ( storage.connected ? storage.account : t.notConnected ) : storage.provider_label + ( storage.region ? ' · ' + storage.region : '' );
				var ready   = ! drive || storage.connected;
				var actions = [
					ready ? button( t.browse, function () {
						browseStorage( storage );
					}, true ) : button( t.connect, function () {
						connectStorage( storage );
					}, true ),
				];
				if ( ready ) {
					actions.push( button( t.test, function () {
						storageStatus( t.testing );
						api( '/storages/' + storage.id + '/test', { method: 'POST' } ).then( function ( result ) {
							storageStatus( storage.name + ': ' + sprintf( t.testOk, result.result ), 'completed' );
						}, function ( error ) {
							storageStatus( storage.name + ': ' + error.message, 'failed' );
						} );
					} ) );
				}
				actions.push( button( t.edit, function () {
					editStorage( storage );
				} ) );
				if ( drive && storage.connected ) {
					actions.push( button( t.disconnect, function () {
						if ( window.confirm( sprintf( t.confirmDisconnect, storage.name ) ) ) {
							api( '/storages/' + storage.id + '/disconnect', { method: 'POST' } ).then( loadStorages, function ( error ) {
								window.alert( error.message );
							} );
						}
					} ) );
				}
				actions.push( button( t.delete, function () {
					if ( window.confirm( sprintf( t.confirmDeleteStorage, storage.name ) ) ) {
						api( '/storages/' + storage.id, { method: 'DELETE' } ).then( loadStorages, function ( error ) {
							window.alert( error.message );
						} );
					}
				} ) );
				body.appendChild( el( 'tr', {}, [
					el( 'td', {}, [ el( 'strong', { text: storage.name } ), el( 'br' ), el( 'code', { text: storage.id } ) ] ),
					el( 'td', { text: service } ),
					el( 'td', {}, [ el( 'code', { text: storage.location } ) ] ),
					el( 'td', { class: 'fmw-row-actions' }, actions ),
				] ) );
			} );
		} ).catch( function ( error ) {
			body.textContent = '';
			body.appendChild( el( 'tr', {}, [ el( 'td', { colspan: '4', class: 'fmw-error', text: error.message } ) ] ) );
		} );
	}

	function connectStorage( storage ) {
		storageStatus( t.connecting );
		api( '/storages/' + storage.id + '/connect', { method: 'POST' } ).then( function ( result ) {
			window.location.href = result.url; // Google's consent page; it comes back to this page.
		}, function ( error ) {
			storageStatus( storage.name + ': ' + error.message, 'failed' );
		} );
	}

	function browseStorage( storage ) {
		var panel = document.getElementById( 'fmw-remote-files' );
		var body  = panel.querySelector( '[data-fmw-file-rows]' );
		panel.hidden = false;
		panel.querySelector( '[data-fmw-files-title]' ).textContent = t.browse + ': ' + storage.name + ' (' + storage.location + ')';
		body.textContent = '';
		body.appendChild( el( 'tr', {}, [ el( 'td', { colspan: '4', text: t.loading } ) ] ) );
		panel.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		api( '/storages/' + storage.id + '/files' ).then( function ( data ) {
			body.textContent = '';
			if ( ! data.files.length ) {
				body.appendChild( el( 'tr', {}, [ el( 'td', { colspan: '4', text: t.noFiles } ) ] ) );
			}
			data.files.forEach( function ( file ) {
				var name = el( 'td', {}, [ el( 'code', { text: file.name } ) ] );
				if ( file.here ) {
					name.appendChild( el( 'span', { class: 'fmw-badge', text: t.onServer } ) );
				}
				body.appendChild( el( 'tr', {}, [
					name,
					el( 'td', { text: file.date } ),
					el( 'td', { text: bytes( file.size ) } ),
					el( 'td', { class: 'fmw-row-actions' }, [
						button( t.downloadToServer, function () {
							startRemoteJob( '/storages/' + storage.id + '/download', { name: file.name }, t.downloadTitle );
						}, true ),
						button( t.delete, function () {
							if ( window.confirm( sprintf( t.confirmDeleteRemote, file.name, storage.name ) ) ) {
								api( '/storages/' + storage.id + '/files/delete', { method: 'POST', body: { name: file.name } } ).then( function () {
									browseStorage( storage );
								}, function ( error ) {
									window.alert( error.message );
								} );
							}
						} ),
					] ),
				] ) );
			} );
		} ).catch( function ( error ) {
			body.textContent = '';
			body.appendChild( el( 'tr', {}, [ el( 'td', { colspan: '4', class: 'fmw-error', text: error.message } ) ] ) );
		} );
	}

	function storageForm() {
		return document.querySelector( '[data-fmw-storage-form]' );
	}

	function applyProvider( form, fill ) {
		var drive = 'gdrive' === form.elements.provider.value;
		form.querySelectorAll( '[data-fmw-kind]' ).forEach( function ( row ) {
			row.hidden = row.getAttribute( 'data-fmw-kind' ) !== ( drive ? 'gdrive' : 's3' );
		} );
		if ( drive ) {
			form.elements.prefix.placeholder = 'FMW Backups/' + window.location.hostname;
			return;
		}
		var option  = form.elements.provider.selectedOptions[ 0 ];
		var region  = form.elements.region.value || option.getAttribute( 'data-region' );
		var pattern = option.getAttribute( 'data-endpoint' );
		if ( fill ) {
			form.elements.region.value        = option.getAttribute( 'data-region' );
			form.elements.path_style.checked  = '1' === option.getAttribute( 'data-path-style' );
			form.elements.endpoint.value      = pattern.indexOf( '{region}' ) !== -1 ? pattern.replace( '{region}', form.elements.region.value ) : '';
			region                            = form.elements.region.value;
		}
		form.elements.endpoint.placeholder = pattern.replace( '{region}', region ) || 'https://minio.example.com:9000';
		form.querySelector( '[data-fmw-storage-class]' ).hidden = 'aws' !== form.elements.provider.value;
	}

	function resetStorageForm() {
		var form  = storageForm();
		var title = document.querySelector( '[data-fmw-storage-title]' );
		form.reset();
		form.elements.id.value = '';
		title.textContent      = title.getAttribute( 'data-add' );
		form.elements.secret_key.placeholder    = '';
		form.elements.client_secret.placeholder = '';
		form.querySelector( '[data-fmw-storage-cancel]' ).hidden = true;
		form.querySelector( '[data-fmw-storage-error]' ).textContent = '';
		applyProvider( form, true );
	}

	function editStorage( storage ) {
		var form  = storageForm();
		var title = document.querySelector( '[data-fmw-storage-title]' );
		resetStorageForm();
		form.elements.id.value            = storage.id;
		form.elements.provider.value      = storage.provider;
		form.elements.region.value        = storage.region;
		form.elements.endpoint.value      = storage.endpoint;
		form.elements.bucket.value        = storage.bucket;
		form.elements.prefix.value        = storage.prefix;
		form.elements.access_key.value    = storage.access_key;
		form.elements.name.value          = storage.name;
		form.elements.path_style.checked  = !! storage.path_style;
		form.elements.storage_class.value = storage.storage_class || '';
		form.elements.secret_key.placeholder = t.secretKept;
		form.elements.client_id.value        = storage.client_id || '';
		form.elements.client_secret.placeholder = t.secretKept;
		title.textContent = title.getAttribute( 'data-edit' ) + ': ' + storage.name;
		form.querySelector( '[data-fmw-storage-cancel]' ).hidden = false;
		applyProvider( form, false );
		form.scrollIntoView( { behavior: 'smooth', block: 'start' } );
	}

	function saveStorage( form ) {
		var error  = form.querySelector( '[data-fmw-storage-error]' );
		var submit = form.querySelector( '[type=submit]' );
		var id     = form.elements.id.value;
		var body   = {};
		var drive  = 'gdrive' === form.elements.provider.value;
		( drive ? [ 'provider', 'prefix', 'name', 'client_id', 'client_secret' ] : [ 'provider', 'region', 'endpoint', 'bucket', 'prefix', 'access_key', 'secret_key', 'name', 'storage_class' ] ).forEach( function ( field ) {
			body[ field ] = form.elements[ field ].value;
		} );
		if ( ! drive ) {
			body.path_style = form.elements.path_style.checked;
		}
		if ( drive && ! body.prefix ) {
			delete body.prefix; // Keeps the default folder.
		}
		error.textContent = t.testing;
		submit.disabled   = true;
		api( '/storages' + ( id ? '/' + id : '' ), { method: 'POST', body: body } ).then( function ( saved ) {
			submit.disabled = false;
			if ( 'gdrive' === saved.provider && ! saved.connected ) {
				connectStorage( saved ); // Straight to Google's consent page.
				return null;
			}
			resetStorageForm();
			storageStatus( sprintf( t.testOk, saved.name ), 'completed' );
			return loadStorages();
		}, function ( e ) {
			submit.disabled   = false;
			error.textContent = e.message;
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
				var label = ( 'backup' === job.type ? t.backup + ( job.schedule ? ' (' + job.schedule + ')' : '' ) : ( 'reset' === job.type ? t.reset : ( t.jobType[ job.type ] || ( job.pull ? t.pullRestore + ' ' + job.pull : t.restore + ' ' + ( job.archive || '' ) ) ) ) ) + ' · ' + job.id;
				var state = ( t.status && t.status[ job.status ] ) || job.status;
				body.appendChild( el( 'tr', {}, [
					el( 'td', { text: label } ),
					el( 'td', { text: state + ( job.error ? ': ' + job.error : '' ) } ),
					el( 'td', { text: sprintf( t.step, job.step, job.steps, job.phase || '' ) + ( null === job.progress ? '' : ' · ' + Math.floor( job.progress * 100 ) + '%' ) } ),
					el( 'td', {}, [
						button( t.continue, function () {
							api( '/jobs/' + job.id + '/token', { method: 'POST' } ).then( function ( renewed ) {
								runJob( renewed, renewed.token, { backup: t.export, reset: t.reset, upload: t.uploadTitle, download: t.downloadTitle, pull: t.pull }[ job.type ] || ( job.pull ? t.pullRestore : t.restore ) ).catch( function () {} );
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

	// ------------------------------------------------------------------- pull

	function copyText( text, trigger ) {
		var done = function () {
			var label           = trigger.textContent;
			trigger.textContent = t.copied;
			setTimeout( function () {
				trigger.textContent = label;
			}, 1500 );
		};
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then( done, function () {} );
			return;
		}
		var area   = el( 'textarea', { class: 'screen-reader-text' } );
		area.value = text;
		document.body.appendChild( area );
		area.select();
		try {
			document.execCommand( 'copy' );
			done();
		} catch ( e ) {} // The text stays selectable in the dialog.
		area.remove();
	}

	function copyField( text ) {
		var field = el( 'input', { type: 'text', class: 'large-text code', readonly: 'readonly' } );
		var copy  = button( t.copy, function () {
			copyText( text, copy );
		} );
		copy.setAttribute( 'aria-live', 'polite' );
		field.value = text;
		field.addEventListener( 'focus', function () {
			field.select();
		} );
		return el( 'p', { class: 'fmw-copy' }, [ field, ' ', copy ] );
	}

	function pullForm() {
		return document.querySelector( '[data-fmw-pull-form]' );
	}

	/**
	 * What the form asks for, as the body of POST /pull-check and POST /jobs.
	 */
	function pullRequest( form ) {
		var flags = {};
		form.querySelectorAll( '[data-fmw-pull-flag]:checked' ).forEach( function ( box ) {
			flags[ box.name ] = true;
		} );
		var backup = form.querySelector( '[data-fmw-pull-backup]' ); // Only there after a check with the current address and key.
		return {
			type: 'pull',
			url: form.elements.url.value.trim(),
			key: form.elements.key.value.trim(),
			allow_http: form.elements.allow_http.checked,
			download_only: form.elements.download_only.checked,
			keep_source: form.elements.keep_source.checked,
			password: form.elements.password.value,
			backup: backup ? backup.value : '',
			flags: flags,
		};
	}

	function pullError( form, text ) {
		form.querySelector( '[data-fmw-pull-error]' ).textContent = text || '';
	}

	function sourceFacts( info ) {
		var rows = [
			[ t.pullSite, info.url + ( info.home_url && info.home_url.replace( /\/+$/, '' ) !== info.url ? ' (' + info.home_url + ')' : '' ) ],
			[ t.pullName, info.name ],
			[ t.pullVersions, 'WordPress ' + info.wp_version + ' · PHP ' + info.php_version + ' · FMW ' + info.fmw ],
			[ t.pullKeyValid, info.expires ],
		];
		if ( info.network ) {
			var kind = info.network.subdomain ? t.subdomains : t.subdirectories;
			rows.splice( 2, 0, [ t.pullNetwork, 1 === info.network.sites ? sprintf( t.pullNetworkFact, kind ) : sprintf( t.pullNetworkFacts, info.network.sites, kind ) ] );
		}
		return el( 'table', { class: 'fmw-facts' }, rows.map( function ( row ) {
			return el( 'tr', {}, [ el( 'th', { text: row[ 0 ] } ), el( 'td', { text: row[ 1 ] || '-' } ) ] );
		} ) );
	}

	/**
	 * Asks the source through this site (POST /pull-check) and shows what it said.
	 */
	function checkSource( form ) {
		var request = pullRequest( form );
		var box     = form.querySelector( '[data-fmw-pull-source]' );
		var buttons = form.querySelectorAll( '[data-fmw-pull-check], [data-fmw-pull-submit]' );
		var busyUi  = function ( on ) {
			buttons.forEach( function ( node ) {
				node.disabled = on;
			} );
		};
		pullError( form, '' );
		if ( ! request.url || ! request.key ) {
			pullError( form, t.pullNeedBoth );
			return Promise.reject( new Error( t.pullNeedBoth ) );
		}
		if ( box.hidden ) {
			box.classList.remove( 'fmw-stale' );
			box.hidden      = false;
			box.textContent = t.pullChecking;
		} else {
			box.classList.add( 'fmw-stale' ); // Keep the height while checking again.
		}
		busyUi( true );
		return api( '/pull-check', { method: 'POST', body: request } ).then( function ( info ) {
			busyUi( false );
			var previous = form.querySelector( '[data-fmw-pull-backup]' );
			var chosen   = previous ? previous.value : '';
			box.classList.remove( 'fmw-stale' );
			box.textContent = '';
			box.appendChild( sourceFacts( info ) );
			if ( ! info.same_version ) {
				box.appendChild( el( 'div', { class: 'notice inline notice-warning' }, [ el( 'p', { text: sprintf( t.pullVersion, info.fmw, config.version ) } ) ] ) );
			}
			if ( info.allow_existing && info.backups.length ) {
				var select = el( 'select', { 'data-fmw-pull-backup': '' }, [ el( 'option', { value: '', text: t.pullNewBackup } ) ].concat( info.backups.map( function ( backup ) {
					return el( 'option', { value: backup.name, text: backup.name + ' (' + bytes( backup.size ) + ')' } );
				} ) ) );
				select.value = chosen;
				select.addEventListener( 'change', function () {
					form.querySelector( '[data-fmw-pull-exclusions]' ).disabled = '' !== select.value; // An existing backup has its own contents.
				} );
				select.dispatchEvent( new Event( 'change' ) );
				box.appendChild( el( 'p', {}, [ el( 'label', {}, [ t.backup + ': ', select ] ) ] ) );
			} else {
				form.querySelector( '[data-fmw-pull-exclusions]' ).disabled = false;
			}
			return info;
		}, function ( error ) {
			busyUi( false );
			box.hidden      = true;
			box.textContent = '';
			form.querySelector( '[data-fmw-pull-exclusions]' ).disabled = false;
			pullError( form, error.message );
			throw error;
		} );
	}

	function startPull( form ) {
		var request = pullRequest( form );
		if ( request.password && ! request.backup && request.password.length < 8 ) {
			pullError( form, t.passwordShort );
			return;
		}
		checkSource( form ).then( function ( info ) {
			request = pullRequest( form ); // The backup choice may have appeared with the check.
			var title = request.download_only ? t.pull : t.pullRestore;
			modal.open( title );
			var body = [
				el( 'p', {}, [ el( 'strong', { text: request.download_only ? sprintf( t.pullDownloadConfirm, info.url ) : sprintf( t.pullConfirm, info.url, info.target ) } ) ] ),
				sourceFacts( info ),
			];
			if ( ! request.download_only ) {
				body.push( el( 'div', { class: 'notice inline notice-warning' }, [ el( 'p', { text: t.pullWarning } ) ] ) );
			}
			var error = el( 'p', { class: 'fmw-error', role: 'alert' } );
			body.push( error );
			modal.body( body );
			var go = button( title, function () {
				go.disabled = true;
				api( '/jobs', { method: 'POST', body: request } ).then( function ( created ) {
					form.elements.key.value      = ''; // The job keeps it, sealed.
					form.elements.password.value = '';
					return runJob( created, created.token, title );
				} ).catch( function ( e ) {
					if ( e.shown ) {
						return;
					}
					go.disabled       = false;
					error.textContent = e.message;
				} );
			}, true );
			modal.actions( [ button( t.cancel, function () {
				modal.close();
			} ), go ] );
		} ).catch( function () {} );
	}

	function loadKeys() {
		var body = document.querySelector( '[data-fmw-key-rows]' );
		if ( ! body ) {
			return;
		}
		api( '/pull-keys' ).then( function ( data ) {
			body.textContent = '';
			if ( ! data.keys.length ) {
				body.appendChild( el( 'tr', {}, [ el( 'td', { colspan: '6', text: t.noPullKeys } ) ] ) );
				return;
			}
			data.keys.forEach( function ( key ) {
				body.appendChild( el( 'tr', { class: key.expired ? 'fmw-muted' : '' }, [
					el( 'td', {}, [ el( 'strong', { text: key.name } ), el( 'br' ), el( 'code', { text: key.id } ) ].concat( key.allow_existing ? [ el( 'span', { class: 'description', text: ' · ' + t.allowExisting } ) ] : [] ) ),
					el( 'td', { text: key.expired ? t.expired : key.expires } ),
					el( 'td', { text: key.ips.length ? key.ips.join( ', ' ) : t.anyAddress } ),
					el( 'td', { text: key.last_used ? key.last_used + ' · ' + key.last_ip : t.never } ),
					el( 'td', { text: bytes( key.bytes_sent ) } ),
					el( 'td', {}, [ el( 'button', { type: 'button', class: 'button', text: t.revoke, 'aria-label': t.revoke + ': ' + key.name, onclick: function () {
						if ( window.confirm( sprintf( t.confirmRevoke, key.name ) ) ) {
							api( '/pull-keys/' + key.id, { method: 'DELETE' } ).then( loadKeys, function ( error ) {
								window.alert( error.message );
							} );
						}
					} } ) ] ),
				] ) );
			} );
		} ).catch( function ( error ) {
			body.textContent = '';
			body.appendChild( el( 'tr', {}, [ el( 'td', { colspan: '6', text: error.message } ) ] ) );
		} );
	}

	function createKey( form ) {
		var error = form.querySelector( '[data-fmw-key-error]' );
		error.textContent = '';
		api( '/pull-keys', {
			method: 'POST',
			body: {
				name: form.elements.name.value,
				expires: parseInt( form.elements.expires.value, 10 ),
				ips: form.elements.ips.value,
				allow_existing: form.elements.allow_existing.checked,
			},
		} ).then( function ( made ) {
			form.reset();
			loadKeys();
			modal.open( t.pullKeyCreated );
			modal.body( [
				copyField( made.key ),
				el( 'p', { text: t.pullKeyOnce } ),
				copyField( made.command ),
				el( 'p', {}, [ t.thisSite + ': ', el( 'code', { text: made.site } ), ' · ' + t.pullKeyValid + ': ' + made.expires ] ),
				el( 'div', { class: 'notice inline notice-warning' }, [ el( 'p', { text: t.pullKeyShare } ) ] ),
			] );
			modal.actions( [ closeButton( false ) ] );
			modal.root.querySelector( '.fmw-copy input' ).focus();
		}, function ( e ) {
			error.textContent = e.message;
		} );
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
		} else if ( 'upload' === action ) {
			uploadBackup( target.closest( '[data-fmw-backup]' ).getAttribute( 'data-fmw-backup' ) );
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

	var exportStorage = document.querySelector( '[data-fmw-export-storage]' );
	if ( exportStorage ) {
		exportStorage.addEventListener( 'change', function () {
			document.querySelector( '[data-fmw-export-delete-local]' ).hidden = ! exportStorage.value;
		} );
	}

	var storageFormNode = storageForm();
	if ( storageFormNode ) {
		storageFormNode.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			saveStorage( storageFormNode );
		} );
		storageFormNode.elements.provider.addEventListener( 'change', function () {
			applyProvider( storageFormNode, true );
		} );
		storageFormNode.elements.region.addEventListener( 'input', function () {
			var pattern = storageFormNode.elements.provider.selectedOptions[ 0 ].getAttribute( 'data-endpoint' );
			if ( pattern.indexOf( '{region}' ) !== -1 ) {
				storageFormNode.elements.endpoint.value = pattern.replace( '{region}', storageFormNode.elements.region.value.trim() );
			}
			applyProvider( storageFormNode, false );
		} );
		storageFormNode.querySelector( '[data-fmw-storage-cancel]' ).addEventListener( 'click', resetStorageForm );
		resetStorageForm();
		loadStorages();
	}

	var resetPanel = document.getElementById( 'fmw-reset' );
	if ( resetPanel && resetPanel.querySelector( '[data-fmw-action=reset]' ) ) {
		var update = function () {
			resetPanel.querySelector( '[data-fmw-action=reset]' ).disabled = ! resetReady( resetPanel );
		};
		resetPanel.addEventListener( 'input', update );
		resetPanel.addEventListener( 'change', update );
	}

	var form = scheduleForm();
	if ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			saveSchedule( form );
		} );
		form.elements.frequency.addEventListener( 'change', function () {
			showFrequency( form );
		} );
		form.elements.storage.addEventListener( 'change', function () {
			showRemote( form );
		} );
		form.elements.encrypt.addEventListener( 'change', function () {
			var id = form.elements.id.value;
			showPassword( form, !! ( id && schedules[ id ] && schedules[ id ].encrypted ) );
		} );
		form.querySelector( '[data-fmw-schedule-cancel]' ).addEventListener( 'click', resetScheduleForm );
		resetScheduleForm();
		loadSchedules();
	}

	var pullFormNode = pullForm();
	if ( pullFormNode ) {
		pullFormNode.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			startPull( pullFormNode );
		} );
		pullFormNode.querySelector( '[data-fmw-pull-check]' ).addEventListener( 'click', function () {
			checkSource( pullFormNode ).catch( function () {} );
		} );
		pullFormNode.elements.download_only.addEventListener( 'change', function () {
			pullFormNode.querySelector( '[data-fmw-pull-submit]' ).textContent = pullFormNode.elements.download_only.checked ? t.pull : t.pullRestore;
		} );
		[ 'url', 'key', 'allow_http' ].forEach( function ( name ) {
			pullFormNode.elements[ name ].addEventListener( 'input', function () {
				// Dimmed, not hidden: the layout must not move under a click (Pull checks again anyway).
				pullFormNode.querySelector( '[data-fmw-pull-source]' ).classList.add( 'fmw-stale' );
				var chosen = pullFormNode.querySelector( '[data-fmw-pull-backup]' );
				if ( chosen ) {
					chosen.disabled = true; // A backup of another source or key must not be sent.
					chosen.removeAttribute( 'data-fmw-pull-backup' );
					pullFormNode.querySelector( '[data-fmw-pull-exclusions]' ).disabled = false;
				}
			} );
		} );
	}

	var keyForm = document.querySelector( '[data-fmw-key-form]' );
	if ( keyForm ) {
		keyForm.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			createKey( keyForm );
		} );
		loadKeys();
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
