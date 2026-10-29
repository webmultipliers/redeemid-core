/*
 * RedeemID → Setup: copy buttons and the confirm on site-wide changes.
 * Loaded only on the wizard page (Wizard::enqueue).
 */
( function () {
	var status = document.getElementById( 'redeemid-copy-status' );

	function copied( button ) {
		var label = button.textContent;

		button.textContent = button.getAttribute( 'data-copied' );
		if ( status ) {
			status.textContent = button.getAttribute( 'data-copied' );
		}
		setTimeout( function () {
			button.textContent = label;
		}, 2000 );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-redeemid-copy]' );
		var field = button && document.getElementById( button.getAttribute( 'data-redeemid-copy' ) );

		if ( ! field ) {
			return;
		}

		field.select();

		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( field.value ).then( function () {
				copied( button );
			} );
		} else if ( document.execCommand( 'copy' ) ) {
			copied( button );
		}
	} );

	document.addEventListener( 'submit', function ( event ) {
		var message = event.target.getAttribute( 'data-redeemid-confirm' );

		if ( message && ! window.confirm( message ) ) {
			event.preventDefault();
		}
	} );
} )();
