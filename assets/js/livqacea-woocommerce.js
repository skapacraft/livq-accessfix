/**
 * WooCommerce accessibility - cart live region + variation aria-current.
 *
 * Reads translated strings from the localized `livqaceaWooCommerce` object.
 */
(function () {
	'use strict';

	var announcer = document.getElementById( 'livqacea-wc-announcer' );
	if ( ! announcer || typeof livqaceaWooCommerce === 'undefined' ) return;

	var strings = livqaceaWooCommerce.strings;

	function announce( msg ) {
		// Toggle text to force re-announcement even for identical messages.
		announcer.textContent = '';
		setTimeout( function () { announcer.textContent = msg; }, 50 );
	}

	// ── Cart live region ───────────────────────────────────────────────────

	jQuery( document.body ).on( 'added_to_cart', function ( e, fragments, cart_hash, $btn ) {
		var label = ( $btn && $btn.attr( 'aria-label' ) ) ? $btn.attr( 'aria-label' ) : $btn && $btn.text().trim();
		if ( label ) {
			announce( label + ' ' + strings.addedToCart );
		} else {
			announce( strings.itemAddedToCart );
		}
	} );

	jQuery( document.body ).on( 'removed_from_cart', function () {
		announce( strings.itemRemovedFromCart );
	} );

	// ── Variation aria-current ─────────────────────────────────────────────

	jQuery( document.body ).on( 'found_variation', function ( e, variation ) {
		jQuery( '.variations select, .variations .button' ).removeAttr( 'aria-current' );
		jQuery( '.variations_form' ).find( 'select' ).each( function () {
			if ( jQuery( this ).val() !== '' ) {
				jQuery( this ).attr( 'aria-current', 'true' );
			}
		} );
	} );

	jQuery( document.body ).on( 'reset_data', function () {
		jQuery( '.variations select' ).removeAttr( 'aria-current' );
	} );

	// ── Quantity input label ───────────────────────────────────────────────
	// Safety net for cases the PHP buffer didn't catch (e.g. AJAX-loaded cart fragments).

	jQuery( document.body ).on( 'updated_cart_totals wc-blocks-cart:renderComplete', function () {
		jQuery( 'input.qty' ).each( function () {
			// HTMLInputElement.labels is null on non-labelable inputs - notably
			// type="hidden", which WooCommerce renders for "sold individually"
			// products. Reading .length off it threw and killed the handler.
			var labels = this.labels;
			if ( ! this.getAttribute( 'aria-label' ) && ( ! labels || ! labels.length ) ) {
				this.setAttribute( 'aria-label', strings.quantity );
			}
		} );
	} );
})();
