import { Auth } from './modules/auth';
import { wp2Fetch } from './modules/fetch';

const LINK_USER_COOKIE = 'wp2_shopify_link_user';
const LINK_USER_COOKIE_MAX_AGE = 5 * 60;

function setLinkUserCookie(userId) {
	if (!userId || typeof document === 'undefined') {
		return;
	}

	const parts = [
		`${LINK_USER_COOKIE}=${encodeURIComponent(userId)}`,
		'path=/',
		`max-age=${LINK_USER_COOKIE_MAX_AGE}`,
		'SameSite=Lax',
	];

	if (window.location.protocol === 'https:') {
		parts.push('Secure');
	}

	document.cookie = parts.join('; ');
}

class WP2ShopifyID {
	constructor() {
		this.config = window.wp2Config || {};
		this.state = {
			customer: null,
			token: null,
		};

		this.auth = new Auth(this);
		this.bindGlobalEvents();
		this.bindUIEvents();
	}

	async init() {
		await this.auth.checkSession();
		await this.refreshIdentityCard();
	}

	bindGlobalEvents() {
		document.addEventListener('wp2:auth:success', (event) => {
			// If we were previously a guest (userId 0), reload only if not on the callback page
			const isAuthCallbackPage = !!this.config.isAuthCallbackPage;
			if (this.config.userId === 0 && !isAuthCallbackPage) {
				window.location.reload();
			} else {
				this.updateIdentity(event.detail);
				this.refreshIdentityCard();
			}
		});

		document.addEventListener('wp2:auth:restored', (event) => {
			this.updateIdentity(event.detail);
			this.refreshIdentityCard();
		});
		
		document.addEventListener('wp2:auth:logout', () => {
			this.updateIdentity({});
			window.location.reload();
		});
	}

	bindUIEvents() {
		document.addEventListener('click', async (e) => {
			if (e.target.matches('#wp2-btn-connect')) {
				e.preventDefault();
				const userId = window.wp2Config?.userId;
				if (userId > 0) {
					setLinkUserCookie(userId);
				}
				this.auth.login();
			}

			if (e.target.matches('#wp2-btn-disconnect')) {
				e.preventDefault();
				if (confirm('Are you sure you want to disconnect your Shopify account?')) {
					this.handleDisconnect(e.target);
				}
			}

			if (e.target.matches('#wp2-btn-sync')) {
				e.preventDefault();
				this.handleSync(e.target);
			}
		});
	}

	async handleSync(btn) {
		const originalText = btn.textContent;
		btn.textContent = 'Syncing...';
		btn.disabled = true;
		const msgEl = document.getElementById('wp2-id-message');

		try {
			const response = await wp2Fetch('/wp-json/wp2-shopify/v1/auth/refresh', { method: 'POST' });

			if (msgEl) {
				msgEl.style.display = 'block';
				msgEl.style.color = response.success ? '#059669' : '#dc2626';
				msgEl.textContent = response.success ? 'Data synced successfully.' : 'Sync failed. Please reconnect.';
			}

			if (response.success) {
				if (msgEl) {
					msgEl.textContent = 'Data synced successfully.';
				}
				setTimeout(() => window.location.reload(), 1000);
			} else {
				if (msgEl) {
					msgEl.textContent = 'Sync failed. Please reconnect.';
				}
				this.auth.login();
			}
		} catch (err) {
			console.error(err);
		} finally {
			btn.textContent = originalText;
			btn.disabled = false;
		}
	}

	async handleDisconnect(btn) {
		const msgEl = document.getElementById('wp2-id-message');
		const originalText = btn.textContent;
		btn.textContent = 'Disconnecting...';
		btn.disabled = true;

		try {
			const response = await wp2Fetch('/wp-json/wp2-shopify/v1/identity/unlink', { method: 'POST' });

			if (msgEl) {
				msgEl.style.display = 'block';
				msgEl.textContent = response.success ? 'Disconnected successfully. Reloading…' : 'Disconnect failed.';
				msgEl.style.color = response.success ? '#059669' : '#dc2626';
			}

			if (response.success) {
				setTimeout(() => window.location.reload(), 800);
			}
		} catch (err) {
			console.error(err);
		} finally {
			btn.textContent = originalText;
			btn.disabled = false;
		}
	}

	async refreshIdentityCard() {
		const card = document.querySelector('.wp2-identity-card');

		if (!card) {
			return;
		}

		try {
			const response = await wp2Fetch('/wp-json/wp2-shopify/v1/identity/card', {
				method: 'GET',
			});

			if (response?.html) {
				const wrapper = document.createElement('div');
				wrapper.innerHTML = response.html;
				const refreshedCard = wrapper.querySelector('.wp2-identity-card');

				if (refreshedCard) {
					card.replaceWith(refreshedCard);
				}
			}
		} catch (error) {
			console.error('[WP2 ID] Failed to refresh identity card.', error);
		}
	}

	updateIdentity(identity = {}) {
		this.state.token = identity.token || identity.accessToken || null;
		this.state.customer = identity.customer || null;

		document.dispatchEvent(new CustomEvent('wp2:identity:updated', { detail: this.state }));

		const email =
			this.state.customer?.emailAddress?.emailAddress ||
			this.state.customer?.email ||
			this.state.customer?.emailAddress;
		const status = this.state.customer ? `Logged in as ${email ?? this.state.customer.id ?? 'Customer'}` : 'Guest';
		console.log(`[WP2 ID] ${status}`);
	}

	trigger(eventName, data) {
		document.dispatchEvent(new CustomEvent(`wp2:${eventName}`, { detail: data }));
	}
}

document.addEventListener('DOMContentLoaded', () => {
	window.wp2ID = new WP2ShopifyID();
	window.wp2ID.init();
});

export default WP2ShopifyID;
