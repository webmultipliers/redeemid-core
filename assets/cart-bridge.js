/**
 * Signs the official plugin's cart in (RedeemID\Commerce\CartBridge).
 *
 * Its <shopify-cart> keeps the cart GID in localStorage['__shopify:cartId'].
 * When a linked customer is signed in, the GID is posted to RedeemID, which attaches
 * their identity on the server. When nobody is signed in, a cart this script
 * attached is dropped so the next visitor can't check out as that customer.
 *
 * Guests without a cart cost nothing: no request is made until a cart exists.
 * Every failure is silent; the cart still works as a guest cart.
 */
(() => {
	const config = window.redeemidCartBridge;
	const CART = '__shopify:cartId';
	const ATTACHED = 'redeemid:attachedCart';
	const SEEN = 'redeemid:signedInCart';

	if (!config) {
		return;
	}

	const store = {
		get(key) {
			try {
				return window.localStorage.getItem(key);
			} catch {
				return null;
			}
		},
		set(key, value) {
			try {
				window.localStorage.setItem(key, value);
			} catch {}
		},
		remove(key) {
			try {
				window.localStorage.removeItem(key);
			} catch {}
		},
	};

	let status;
	let pending = '';

	function fetchStatus() {
		status ??= fetch(config.statusUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
			.then((response) => (response.ok ? response.json() : null))
			.catch(() => null);

		return status;
	}

	function cartId() {
		const raw = store.get(CART);

		if (!raw) {
			return '';
		}

		// Stored as a JSON string by some versions of the component.
		try {
			const parsed = JSON.parse(raw);
			return typeof parsed === 'string' ? parsed : '';
		} catch {
			return raw;
		}
	}

	async function sync() {
		const cart = cartId();

		if (!cart && !store.get(ATTACHED)) {
			return;
		}

		const me = await fetchStatus();

		if (!me) {
			return;
		}

		if (!me.signedIn) {
			const attached = store.get(ATTACHED);

			if ((attached && attached === cart) || (config.clearOnLogout && store.get(SEEN))) {
				store.remove(CART);
			}

			store.remove(ATTACHED);
			store.remove(SEEN);

			return;
		}

		if (cart) {
			store.set(SEEN, '1');
		}

		if (!cart || !me.linked || !me.nonces || store.get(ATTACHED) === cart || pending === cart) {
			return;
		}

		pending = cart;

		try {
			const response = await fetch(config.identityUrl, {
				method: 'POST',
				credentials: 'same-origin',
				keepalive: true, // Finish even if this click navigates to checkout.
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': me.nonces.rest },
				body: JSON.stringify({ cartId: cart }),
			});

			if (response.ok) {
				store.set(ATTACHED, cart);
			}
		} catch {
			// Leave it as a guest cart.
		} finally {
			pending = '';
		}
	}

	sync();

	// The cart is created on the first add, which doesn't fire a storage event
	// in this tab, so check again whenever the visitor interacts. Each check
	// is a localStorage read until there is a new cart to attach.
	document.addEventListener('pointerdown', sync, true);
	window.addEventListener('focus', sync);
	window.addEventListener('storage', (event) => event.key === CART && sync());
	window.setInterval(() => cartId() && cartId() !== store.get(ATTACHED) && sync(), 3000);
})();
