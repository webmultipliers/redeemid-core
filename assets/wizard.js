/*
 * RedeemID → Setup: copy buttons and the confirm on site-wide changes.
 * Loaded only on the wizard page (Wizard::enqueue).
 */
( function() {
	const status = document.getElementById( 'redeemid-copy-status' );

	function copied( button ) {
		const label = button.textContent;

		button.textContent = button.getAttribute( 'data-copied' );
		if ( status ) {
			status.textContent = button.getAttribute( 'data-copied' );
		}
		setTimeout( function() {
			button.textContent = label;
		}, 2000 );
	}

	document.addEventListener( 'click', function( event ) {
		const button = event.target.closest( '[data-redeemid-copy]' );
		const field = button && document.getElementById( button.getAttribute( 'data-redeemid-copy' ) );

		if ( ! field ) {
			return;
		}

		field.select();

		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( field.value ).then( function() {
				copied( button );
			} );
		} else if ( document.execCommand( 'copy' ) ) {
			copied( button );
		}
	} );

	document.addEventListener( 'submit', function( event ) {
		const message = event.target.getAttribute( 'data-redeemid-confirm' );

		// A native confirm: it works with no extra markup and every screen reader announces it.
		// eslint-disable-next-line no-alert
		if ( message && ! window.confirm( message ) ) {
			event.preventDefault();
		}
	} );
}() );
