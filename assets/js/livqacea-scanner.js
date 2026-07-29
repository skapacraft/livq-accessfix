/**
 * Accessibility Scanner admin page.
 *
 * Reads dynamic data (ajax nonce, discovered templates, cached results,
 * translated strings) from the localized `livqaceaScanner` object.
 */
(function ( $ ) {
	'use strict';

	var ajaxUrl   = livqaceaScanner.ajaxUrl;
	var nonce     = livqaceaScanner.nonce;
	var templates = livqaceaScanner.templates;
	var strings   = livqaceaScanner.strings;
	var results   = {};

	function scoreColor( s ) {
		if ( s >= 90 ) return '#46b450';
		if ( s >= 75 ) return '#7ad03a';
		if ( s >= 60 ) return '#dba617';
		if ( s >= 40 ) return '#f56e28';
		return '#dc3232';
	}

	function statusTag( status ) {
		if ( status === 'unresolved' ) {
			return '<span class="livqacea-tag-unresolved">' + strings.statusUnresolved + '</span>';
		}
		if ( status === 'fixable' ) {
			return '<span class="livqacea-tag-fixable">' + strings.statusFixable + '</span>';
		}
		return '<span class="livqacea-tag-manual">' + strings.statusManual + '</span>';
	}

	function refreshSummary() {
		var c = 0, h = 0, w = 0, f = 0, scoreSum = 0, n = 0;
		Object.values( results ).forEach( function ( r ) {
			r.issues.forEach( function ( i ) {
				var cnt = parseInt( i.count, 10 ) || 1;
				if ( i.severity === 'critical' ) c += cnt;
				else if ( i.severity === 'high' ) h += cnt;
				else w += cnt;
				if ( i.status === 'fixable' ) f += cnt;
			} );
			scoreSum += r.score; n++;
		} );
		var avg = n ? Math.round( scoreSum / n ) : 0;
		$( '#livqacea-cnt-critical' ).text( c );
		$( '#livqacea-cnt-high' ).text( h );
		$( '#livqacea-cnt-warning' ).text( w );
		$( '#livqacea-cnt-fixed' ).text( f );
		var badge = $( '#livqacea-badge-score' );
		badge.text( avg + '/100' ).css( { background: scoreColor( avg ), color: '#fff', 'border-color': scoreColor( avg ) } );
		$( '#livqacea-summary-strip' ).show();
	}

	function buildIssueTable( issues ) {
		if ( ! issues.length ) {
			return '<p style="padding:10px 16px;color:#1e8325;font-weight:600;">✓ ' + strings.noIssues + '</p>';
		}
		var rows = issues.map( function ( iss ) {
			var sev = '<span class="livqacea-badge livqacea-badge-' + iss.severity + '">' + iss.severity.charAt( 0 ).toUpperCase() + iss.severity.slice( 1 ) + '</span>';
			var status = statusTag( iss.status );
			var sample = iss.sample ? '<code class="livqacea-sample">' + $( '<div>' ).text( iss.sample ).html() + '</code>' : '';
			return '<tr>' +
				'<td>' + sev + '</td>' +
				'<td><code style="font-size:.8rem;">' + iss.wcag + '</code></td>' +
				'<td>' + iss.message + sample + '</td>' +
				'<td>' + status + '</td>' +
				'</tr>';
		} ).join( '' );
		return '<table class="livqacea-issues-tbl"><thead><tr>' +
			'<th style="width:110px;">' + strings.severity + '</th>' +
			'<th style="width:100px;">' + strings.wcag + '</th>' +
			'<th>' + strings.issue + '</th>' +
			'<th style="width:120px;">' + strings.status + '</th>' +
			'</tr></thead><tbody>' + rows + '</tbody></table>';
	}

	function scanOne( tpl, index, total, cb ) {
		$( '#livqacea-progress-msg' ).show().text(
			strings.scanning + ' ' + tpl.label +
			' (' + ( index + 1 ) + '/' + total + ')…'
		);
		$.post( ajaxUrl, { action: 'livqacea_scan_url', nonce: nonce, url: tpl.url, key: tpl.key }, function ( res ) {
			if ( ! res.success ) { cb(); return; }
			var d = res.data;
			results[ d.key ] = d;
			var row = $( '#livqacea-row-' + d.key );
			$( '#livqacea-score-' + d.key ).text( d.score + '/100' ).css( { 'font-weight': '700', color: scoreColor( d.score ) } );
			$( '#livqacea-issues-' + d.key ).text( d.issues.length );
			$( '#livqacea-date-' + d.key ).text( new Date().toLocaleString() );
			// Target this template's own detail row by id. nextAll() walked
			// forward across the whole table and, when the current row had no
			// detail row yet, deleted the one belonging to another template.
			$( '#livqacea-detail-' + d.key ).remove();
			row.after( '<tr class="livqacea-detail-row" id="livqacea-detail-' + d.key + '"><td colspan="5" style="padding:0;background:#f9f9f9;">' + buildIssueTable( d.issues ) + '</td></tr>' );
			refreshSummary();
			cb();
		} ).fail( function () { cb(); } );
	}

	$( '#livqacea-btn-scan' ).on( 'click', function () {
		var btn = $( this ).prop( 'disabled', true ).text( strings.scanningEllipsis );
		var idx = 0;
		( function next() {
			if ( idx >= templates.length ) {
				btn.prop( 'disabled', false ).text( strings.startScan );
				$( '#livqacea-progress-msg' ).hide();
				return;
			}
			scanOne( templates[ idx ], idx, templates.length, function () { idx++; next(); } );
		}() );
	} );

	$( '#livqacea-btn-clear' ).on( 'click', function () {
		$.post( ajaxUrl, { action: 'livqacea_scan_clear', nonce: nonce }, function () { location.reload(); } );
	} );

	$( '#livqacea-btn-csv' ).on( 'click', function () {
		var rows = [ [ 'Template', 'URL', 'Score', 'Severity', 'WCAG', 'Issue', 'Status' ] ];
		templates.forEach( function ( tpl ) {
			var r = results[ tpl.key ];
			if ( ! r ) return;
			if ( ! r.issues.length ) {
				rows.push( [ tpl.label, tpl.url, r.score, '', '', 'No issues', '' ] );
			} else {
				r.issues.forEach( function ( iss ) {
					rows.push( [ tpl.label, tpl.url, r.score, iss.severity, iss.wcag, iss.message, iss.status || 'manual' ] );
				} );
			}
		} );
		var csv = '﻿' + rows.map( function ( r ) {
			return r.map( function ( c ) {
				var cell = String( c );
				// Neutralise spreadsheet formula injection.
				if ( cell && '=+-@'.indexOf( cell.charAt( 0 ) ) !== -1 ) {
					cell = "'" + cell;
				}
				return '"' + cell.replace( /"/g, '""' ) + '"';
			} ).join( ',' );
		} ).join( '\n' );
		var a = document.createElement( 'a' );
		a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent( csv );
		a.download = 'a11y-scan-' + new Date().toISOString().slice( 0, 10 ) + '.csv';
		a.click();
	} );

	// Hydrate summary from PHP-rendered cache on page load.
	if ( livqaceaScanner.cachedData ) {
		Object.entries( livqaceaScanner.cachedData ).forEach( function ( e ) { results[ e[0] ] = e[1]; } );
		refreshSummary();
	}
}( jQuery ) );
