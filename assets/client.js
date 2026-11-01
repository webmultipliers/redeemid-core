/**
 * window.redeemid: RedeemID's browser client (RedeemID\Api\Client).
 *
 * Loads only when a script lists "redeemid-client" as a dependency, or a
 * theme enqueues it. It never sends anything anywhere by itself: analytics
 * tools, CRMs and other plugins subscribe to its events and forward what
 * they choose.
 *
 *   redeemid.subscribe( ( name, props, event ) => { ... } );
 *
 * Every event has the same shape as the server's redeemid_event action:
 * { name, version, time, props }, with scalar props only and never a user
 * ID, an email or a Shopify tag. A subscriber also gets the events that fired
 * before it subscribed (pass { replay: false } to skip them), so it can wait
 * for consent. Each event is also dispatched on document as a CustomEvent
 * named "redeemid:<name>".
 */
(() => {
	if (window.redeemid) {
		return;
	}

	const config = window.redeemidClientConfig || {};
	const EVENTS = ['ready', 'state', 'wall_shown', 'metered_view', 'sign_in_click', 'checkout_click'];
	const SITE_EVENT = /^site_[a-z0-9_]{1,40}$/;
	const KEEP = 100;
	const listeners = [];
	const history = [];
	let pending = null;

	const clean = (props) => {
		const out = {};

		Object.keys(props || {}).forEach((key) => {
			const value = props[key];
			out[key] = ['string', 'number', 'boolean'].includes(typeof value) ? value : null;
		});

		return out;
	};

	const deliver = (fn, event) => {
		try {
			fn(event.name, event.props, event);
		} catch (error) {
			// A broken subscriber must not break the page or the others.
			window.console?.error?.(error);
		}
	};

	const send = (name, props) => {
		const event = Object.freeze({ name, version: config.eventVersion || 1, time: Date.now(), props: Object.freeze(clean(props)) });

		history.push(event);

		if (history.length > KEEP) {
			history.shift();
		}

		listeners.slice().forEach((fn) => deliver(fn, event));
		document.dispatchEvent(new CustomEvent(`redeemid:${name}`, { detail: event }));

		return event;
	};

	const subscribe = (fn, options = {}) => {
		if (typeof fn !== 'function') {
			return () => {};
		}

		listeners.push(fn);

		if (options.replay !== false) {
			history.slice().forEach((event) => deliver(fn, event));
		}

		return () => {
			const at = listeners.indexOf(fn);

			if (at > -1) {
				listeners.splice(at, 1);
			}
		};
	};

	// Sites may add their own events, named site_*. RedeemID's names are reserved.
	const emit = (name, props = {}) => (SITE_EVENT.test(String(name)) ? send(String(name), props) : null);

	const refresh = () => {
		pending = fetch(config.stateUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
			.then((response) => (response.ok ? response.json() : Promise.reject(new Error(`redeemid: state ${response.status}`))))
			.then((state) => {
				send('state', {
					signedIn: !!state.signedIn,
					linked: !!state.linked,
					levels: (state.levels || []).join(','),
					features: (state.features || []).join(','),
				});

				return state;
			});

		pending.catch(() => {
			pending = null;
		});

		return pending;
	};

	const state = () => pending || refresh();

	const signIn = (returnTo) => {
		const url = new URL(config.loginUrl, window.location.href);
		url.searchParams.set('redirect_to', returnTo || window.location.href);
		window.location.assign(url.toString());
	};

	const signOut = async (returnTo) => {
		const current = await state();
		const back = returnTo || window.location.href;

		clearHeldPreferences();

		if (!current.signedIn) {
			return;
		}

		const response = await fetch(`${config.restUrl}auth/logout`, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': current.nonce },
			body: JSON.stringify({ redirect_to: back }),
		});
		const body = response.ok ? await response.json() : {};

		window.location.assign(body.redirect || back);
	};

	// Guest preferences: answers held in this browser only, never sent to the
	// server until the visitor has an account and confirms them.
	const HELD = 'redeemid:held-preferences';
	const HELD_DAYS = 30;
	const HELD_MAX = 8000;

	const storage = () => {
		try {
			return window.localStorage;
		} catch {
			return null;
		}
	};

	const clearHeldPreferences = () => {
		storage()?.removeItem(HELD);
	};

	const heldPreferences = () => {
		const store = storage();
		let held = null;

		try {
			held = JSON.parse(store?.getItem(HELD) || 'null');
		} catch {
			held = null;
		}

		if (!held || typeof held.values !== 'object' || !held.values || Date.now() - Number(held.at || 0) > HELD_DAYS * 86400000) {
			clearHeldPreferences();

			return null;
		}

		return held.values;
	};

	const plain = (value) =>
		value === null ||
		['string', 'number', 'boolean'].includes(typeof value) ||
		(Array.isArray(value) && value.every((item) => typeof item === 'string'));

	const holdPreferences = (values) => {
		const store = storage();
		const next = { ...(heldPreferences() || {}) };

		Object.keys(values || {}).forEach((key) => {
			if (/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/.test(key) && plain(values[key])) {
				next[key] = values[key];
			}
		});

		const json = JSON.stringify({ values: next, at: Date.now() });

		if (!store || json.length > HELD_MAX) {
			return null;
		}

		store.setItem(HELD, json);

		return next;
	};

	const preferenceFields = () =>
		fetch(`${config.restUrl}preferences/fields`, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then((response) =>
			response.ok ? response.json() : Promise.reject(new Error(`redeemid: preference fields ${response.status}`))
		);

	const saveHeldPreferences = async (values) => {
		const current = await state();
		const body = values || heldPreferences();

		if (!current.linked) {
			throw new Error('redeemid: sign in with Shopify before saving preferences');
		}

		if (!body) {
			return null;
		}

		const response = await fetch(`${config.restUrl}account/preferences`, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': current.nonce },
			body: JSON.stringify({ values: body }),
		});
		const result = await response.json().catch(() => null);

		if (!response.ok) {
			const error = new Error(result?.message || `redeemid: preferences ${response.status}`);
			error.fields = result?.data?.fields || {};
			throw error;
		}

		clearHeldPreferences();

		return result;
	};

	const isSignIn = (href) => {
		try {
			const url = new URL(href, window.location.href);

			return url.searchParams.get('action') === 'redeemid_login' || url.pathname.endsWith('/redeemid/v1/auth/login');
		} catch {
			return false;
		}
	};

	const where = (el) => (el.closest('.redeemid-paywall') ? 'wall' : 'page');

	document.addEventListener(
		'click',
		(e) => {
			const link = e.target instanceof Element ? e.target.closest('a[href]') : null;

			if (link && isSignIn(link.href)) {
				send('sign_in_click', { source: where(link) });
			}
		},
		true
	);

	document.addEventListener(
		'submit',
		(e) => {
			const form = e.target;

			if (!(form instanceof HTMLFormElement)) {
				return;
			}

			const action = e.submitter?.name === 'action' ? e.submitter.value : form.elements.action?.value;

			if (action === config.buyAction) {
				send('checkout_click', {
					variant: form.elements.variant?.value || '',
					subscription: !!form.elements.selling_plan?.value,
					source: where(form),
				});
			}
		},
		true
	);

	const scan = () => {
		document.querySelectorAll('.redeemid-paywall[data-redeemid-post]').forEach((wall) => {
			send('wall_shown', {
				post_id: Number(wall.dataset.redeemidPost) || 0,
				level: wall.dataset.redeemidLevel || '',
				reason: wall.dataset.redeemidReason || '',
				view: wall.dataset.redeemidView || 'page',
			});
		});

		document.querySelectorAll('[data-redeemid-metered]').forEach((note) => {
			send('metered_view', { post_id: Number(note.dataset.redeemidMetered) || 0 });
		});

		send('ready', { api_version: config.version || '' });
	};

	window.redeemid = Object.freeze({ version: config.version || '', events: Object.freeze(EVENTS.slice()), state, refresh, signIn, signOut, subscribe, emit, preferenceFields, holdPreferences, heldPreferences, saveHeldPreferences, clearHeldPreferences });

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', scan, { once: true });
	} else {
		scan();
	}
})();
