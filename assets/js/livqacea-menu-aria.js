/**
 * Menu accessibility helper - keeps aria-expanded truthful on sub-menu triggers.
 *
 * WCAG 4.1.2. Fully static, no dynamic PHP data.
 *
 * Two rules drive this file:
 *
 * 1. Enter must never be swallowed on a menu item that is also a real link.
 *    Blocking it left keyboard users unable to reach parent pages that mouse
 *    users could open with a plain click. Space - which never activates a link -
 *    is the dedicated open/close key instead.
 *
 * 2. aria-expanded must describe what is actually on screen. Most themes open
 *    sub-menus from CSS (:hover / :focus-within) without telling JavaScript, so
 *    the state is read back from the sub-menu's computed style rather than
 *    tracked from click events alone.
 */
(function () {
	'use strict';

	// Scope every query to menu items the plugin itself annotated. A bare
	// [aria-haspopup="true"] selector also matched unrelated widgets and would
	// overwrite their aria-expanded state.
	var TRIGGERS = '.menu-item-has-children [aria-haspopup="true"]';

	function triggers() {
		return document.querySelectorAll( TRIGGERS );
	}

	function closestTrigger( node ) {
		if ( ! node || typeof node.closest !== 'function' ) {
			return null;
		}
		var trigger = node.closest( '[aria-haspopup="true"]' );
		return trigger && trigger.closest( '.menu-item-has-children' ) ? trigger : null;
	}

	/**
	 * True when activating the element navigates somewhere real.
	 */
	function isRealLink( el ) {
		if ( ! el || el.tagName !== 'A' ) {
			return false;
		}
		var href = el.getAttribute( 'href' );
		return !! href && href !== '#';
	}

	function submenuOf( trigger ) {
		var item = trigger.closest( '.menu-item-has-children' );
		return item ? item.querySelector( 'ul' ) : null;
	}

	function isVisible( el ) {
		if ( ! el ) {
			return false;
		}
		var cs = window.getComputedStyle( el );
		return cs.display !== 'none' && cs.visibility !== 'hidden' && cs.opacity !== '0';
	}

	/**
	 * Reflects the sub-menu's real visibility onto the trigger.
	 *
	 * Triggers whose sub-menu cannot be located are left alone, so click-driven
	 * state still works on markup we cannot introspect.
	 */
	function syncTrigger( trigger ) {
		var sub = submenuOf( trigger );
		if ( sub ) {
			trigger.setAttribute( 'aria-expanded', isVisible( sub ) ? 'true' : 'false' );
		}
	}

	var syncQueued = false;

	/**
	 * Event entry point - ignores pointer and focus traffic outside the menu so
	 * ordinary mouse movement across the page costs nothing.
	 */
	function maybeSync( e ) {
		var target = e.target;

		if ( ! target || typeof target.closest !== 'function' ) {
			return;
		}
		if ( ! target.closest( '.menu-item-has-children' ) ) {
			return;
		}

		scheduleSync();
	}

	function scheduleSync() {
		if ( syncQueued ) {
			return;
		}
		syncQueued = true;
		window.requestAnimationFrame( function () {
			syncQueued = false;
			Array.prototype.forEach.call( triggers(), syncTrigger );
		} );
	}

	function closeOthers( except ) {
		Array.prototype.forEach.call( triggers(), function ( el ) {
			if ( el !== except && el.getAttribute( 'aria-expanded' ) === 'true' ) {
				el.setAttribute( 'aria-expanded', 'false' );
			}
		} );
	}

	function toggle( trigger ) {
		var expanded = trigger.getAttribute( 'aria-expanded' ) === 'true';
		closeOthers( expanded ? null : trigger );
		trigger.setAttribute( 'aria-expanded', expanded ? 'false' : 'true' );
	}

	document.addEventListener( 'DOMContentLoaded', function () {

		document.addEventListener( 'click', function ( e ) {
			var trigger = closestTrigger( e.target );

			if ( ! trigger ) {
				closeOthers( null );
				return;
			}

			// A real link is about to navigate - leave the browser to it.
			if ( isRealLink( trigger ) ) {
				return;
			}

			e.preventDefault();
			toggle( trigger );
		} );

		document.addEventListener( 'keydown', function ( e ) {
			var trigger = closestTrigger( e.target );

			if ( trigger && ( e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar' ) ) {
				if ( e.key === 'Enter' && isRealLink( trigger ) ) {
					return; // Let the link do its job.
				}
				e.preventDefault();
				toggle( trigger );
				return;
			}

			if ( e.key === 'Escape' ) {
				var open = document.querySelector( '.menu-item-has-children [aria-haspopup="true"][aria-expanded="true"]' );
				if ( open ) {
					open.setAttribute( 'aria-expanded', 'false' );
					open.focus();
				}
			}
		} );

		// Keep the announced state aligned with CSS-driven open/close.
		[ 'mouseover', 'mouseout', 'focusin', 'focusout' ].forEach( function ( evt ) {
			document.addEventListener( evt, maybeSync, true );
		} );

		scheduleSync();
	} );
})();
