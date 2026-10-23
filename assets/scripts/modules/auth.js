/**
 * Auth Module
 * Manages authentication flows, including PKCE and session restoration.
 *
 * @module Auth
 */

import { wp2Fetch } from './fetch';

const RETURN_URL_KEY = 'wp2_auth_return_url';
const RETURN_URL_COOKIE_MAX_AGE = 5 * 60; // 5 minutes

function getCookieDomain() {
	if (typeof window === 'undefined') {
		return '';
	}

	const configuredDomain = window.wp2Config?.cookieDomain;
	if (typeof configuredDomain === 'string') {
		return configuredDomain;
	}

	const host = window.location?.hostname || '';

	if (!host || host === 'localhost' || host === '127.0.0.1') {
		return '';
	}

	const isIpv4 = /^(\d{1,3}\.){3}\d{1,3}$/.test(host);
	const isIpv6 = host.includes(':');

	if (isIpv4 || isIpv6) {
		// Don't derive a cookie domain for raw IP hosts.
		return '';
	}

	const segments = host.split('.');

	if (segments.length <= 2) {
		return host;
	}

	const last = segments[segments.length - 1];
	const secondLast = segments[segments.length - 2];

	if (last.length === 2 && secondLast.length === 2 && segments.length >= 3) {
		return segments.slice(-3).join('.');
	}

	return segments.slice(-2).join('.');
}

function setCookie(name, value, maxAgeSeconds) {
	if (typeof document === 'undefined') {
		return;
	}

	const parts = [
		`${name}=${encodeURIComponent(value)}`,
		'path=/',
		`max-age=${maxAgeSeconds}`,
		'SameSite=Lax',
	];

	const domain = getCookieDomain();

	if (domain) {
		parts.push(`domain=.${domain}`);
	}

	if (window.location.protocol === 'https:') {
		parts.push('Secure');
	}

	document.cookie = parts.join('; ');
}

function setPkceCookie(name, value, maxAgeSeconds) {
	if (!name || !value) {
		return;
	}

	setCookie(name, value, maxAgeSeconds);
}

function getCookie(name) {
	if (typeof document === 'undefined' || !document.cookie) {
		return null;
	}

	return document.cookie.split('; ').reduce((value, pair) => {
		const [key, ...rest] = pair.split('=');

		if (key === name) {
			return decodeURIComponent(rest.join('='));
		}

		return value;
	}, null);
}

function deleteCookie(name) {
	if (typeof document === 'undefined') {
		return;
	}

	const parts = [
		`${name}=`,
		'path=/',
		'max-age=0',
		'SameSite=Lax',
	];

	const domain = getCookieDomain();

	if (domain) {
		parts.push(`domain=.${domain}`);
	}

	if (window.location.protocol === 'https:') {
		parts.push('Secure');
	}

	document.cookie = parts.join('; ');
}

/**
 * Creates a new Auth instance.
 *
 * @param {Object} app - The main application instance.
 */
export class Auth {
	constructor(app) {
		this.app = app;
		this.storageKey = (app.config && app.config.storageKey) || 'wp2_shopify_token';
		this.pkceVerifierKey = 'wp2_pkce_verifier';
		this.pkceStateKey = 'wp2_pkce_state';
		this.pkceNonceKey = 'wp2_pkce_nonce';
		this.defaultReturnUrl = this.app?.config?.auth?.returnUrl || null;
	}

	async checkSession() {
		try {
			const tokenStored = await this.handleCallback();

			if (tokenStored) {
				return;
			}

			const data = await wp2Fetch('/wp-json/wp2-shopify/v1/auth/status', {
				method: 'GET',
			});

			if (data?.logged_in && data?.token) {
				this.app.trigger('auth:restored', { token: data.token, customer: data.customer, source: 'cookie' });
				return;
			}
		} catch (error) {
			console.error('Error checking session:', error);
		}
	}

	async login() {
		const config = this.app.config?.auth || {};

		if (!config.authorizationEndpoint || !config.clientId) {
			console.warn('[WP2 Auth] Authorization endpoint or client ID missing from config.');
			return;
		}

		try {
			const verifier = this.generateVerifier();
			const challenge = await this.generateChallenge(verifier);
			const state = this.generateVerifier();
			const nonce = this.generateNonce();
			const redirectUri = config.redirectUri || window.location.href.split('#')[0];

			this.storeReturnUrl(window.location.href.split('#')[0]);

			sessionStorage.setItem(this.pkceVerifierKey, verifier);
			sessionStorage.setItem(this.pkceStateKey, state);
			sessionStorage.setItem(this.pkceNonceKey, nonce);

			setPkceCookie(this.pkceStateKey, state, 5 * 60);
			setPkceCookie(this.pkceNonceKey, nonce, 5 * 60);

			// Updated the default scope to include customer-account-api:full
			const params = new URLSearchParams({
				client_id: config.clientId,
				redirect_uri: redirectUri,
				response_type: 'code',
				scope: config.scope || 'openid email customer-account-api:full',
				code_challenge_method: 'S256',
				code_challenge: challenge,
				state,
				nonce,
			});

			window.location.assign(`${config.authorizationEndpoint}?${params.toString()}`);
		} catch (error) {
			console.error('[WP2 Auth] Failed to start PKCE flow', error);
		}
	}

	storeReturnUrl(url) {
		if (!url || typeof window === 'undefined') {
			return;
		}

		try {
			sessionStorage.setItem(RETURN_URL_KEY, url);
		} catch (error) {
			console.error('[WP2 Auth] Unable to persist return URL to session storage', error);
		}

		setCookie(RETURN_URL_KEY, url, RETURN_URL_COOKIE_MAX_AGE);
	}

	getStoredReturnUrl() {
		if (typeof window === 'undefined') {
			return null;
		}

		try {
			const stored = sessionStorage.getItem(RETURN_URL_KEY);

			if (stored) {
				return stored;
			}
		} catch (error) {
			console.error('[WP2 Auth] Unable to read return URL from session storage', error);
		}

		return getCookie(RETURN_URL_KEY);
	}

	clearReturnUrlStorage() {
		if (typeof window === 'undefined') {
			return;
		}

		try {
			sessionStorage.removeItem(RETURN_URL_KEY);
		} catch (error) {
			// ignore storage errors
		}

		deleteCookie(RETURN_URL_KEY);
	}

	logout() {
		try {
			sessionStorage?.removeItem(this.pkceVerifierKey);
			sessionStorage?.removeItem(this.pkceStateKey);
		} catch (error) {
			console.error('[WP2 Auth] Failed to clear session', error);
		}

		// Call the REST API to revoke the server-side session
		fetch('/wp-json/wp2-shopify/v1/logout', {
			method: 'POST',
			credentials: 'include',
		})
			.then((response) => {
				if (!response.ok) {
					throw new Error('Failed to revoke server session');
				}
				console.log('[WP2 Auth] Server session revoked');
			})
			.catch((error) => {
				console.error('[WP2 Auth] Logout failed', error);
			});

		// [FIX] Triggered 'auth:logout' event to update app state.
		this.app?.trigger('auth:logout');
	}

	async handleCallback() {
		if (typeof window === 'undefined' || typeof URLSearchParams === 'undefined') {
			return false;
		}

		const params = new URLSearchParams(window.location.search);

		if (!params.has('code')) {
			return false;
		}

		const state = params.get('state');

		const storedState = sessionStorage.getItem(this.pkceStateKey);
		const storedNonce = sessionStorage.getItem(this.pkceNonceKey);
		const verifier = sessionStorage.getItem(this.pkceVerifierKey);

		let tokenWasStored = false;

		if (state !== storedState || !verifier) {
			console.warn('[WP2 Auth] State or verifier mismatch. Aborting token exchange.');
			this.cleanupCallbackParams();
			return false;
		}

		if (!storedNonce) {
			console.warn('[WP2 Auth] Missing PKCE nonce. Aborting token exchange.');
			this.cleanupCallbackParams();
			return false;
		}

		const tokenRoute = this.app.config?.auth?.tokenRoute || this.app.config?.routes?.auth?.token;

		if (!tokenRoute) {
			console.warn('[WP2 Auth] Token route missing from config.');
			this.cleanupCallbackParams();
			return false;
		}

		try {
			const headers = {
				'Content-Type': 'application/json',
			};

			const restNonce = window.wp2Config?.rest_nonce;

			if (restNonce) {
				headers['X-WP-Nonce'] = restNonce;
			}

			const response = await fetch(tokenRoute, {
				method: 'POST',
				headers,
				credentials: 'same-origin',
				body: JSON.stringify({
					code: params.get('code'),
					code_verifier: verifier,
					state,
					nonce: storedNonce,
				}),
			});

			if (!response.ok) {
				const errorBody = await response.text().catch(() => null);
				console.warn('[WP2 Auth] Token exchange failed.', response.status, errorBody);
				this.showCallbackError('Login failed. Please try again.');
				return false;
			}

			const data = await response.json();

			const tokenValue = data?.token ?? data?.access_token;

			if (tokenValue) {
				this.app.trigger('auth:success', { token: tokenValue, ...data });
				tokenWasStored = true;

				const storedReturnUrl = this.getStoredReturnUrl();
				const targetReturnUrl = storedReturnUrl || this.defaultReturnUrl;

				if (targetReturnUrl) {
					this.clearReturnUrlStorage();
					window.location.href = targetReturnUrl; // Redirect back to the stored URL
				}
			} else {
				console.warn('[WP2 Auth] Token exchange response missing token.', data);
				this.showCallbackError('Login failed. Please try again.');
			}
		} catch (error) {
			console.error('[WP2 Auth] Token exchange failed', error);
			this.showCallbackError('Unable to complete login. Please try again.');
		} finally {
			this.cleanupCallbackParams();
			// Clean the URL after processing
			const cleanUrl = window.location.origin + window.location.pathname;
			window.history.replaceState({}, document.title, cleanUrl);
		}

		return tokenWasStored;
	}

	cleanupCallbackParams() {
		try {
			// Immediately clear sensitive PKCE data
			sessionStorage.removeItem(this.pkceVerifierKey);
			sessionStorage.removeItem(this.pkceStateKey);
			sessionStorage.removeItem(this.pkceNonceKey); // Clear PKCE nonce
			this.clearReturnUrlStorage();
		} catch (error) {
			console.error('[WP2 Auth] Failed to clear PKCE storage', error);
		}

		deleteCookie(this.pkceStateKey);
		deleteCookie(this.pkceNonceKey);

		if (typeof window !== 'undefined' && window.history?.replaceState) {
			const url = new URL(window.location.href);
			url.searchParams.delete('code');
			url.searchParams.delete('state');
			url.searchParams.delete('nonce');
			const query = url.searchParams.toString();
			const cleanUrl = `${url.pathname}${query ? `?${query}` : ''}${url.hash || ''}`;
			window.history.replaceState({}, document.title, cleanUrl);
		}
	}

	showCallbackNotification(message, color = '#dc2626') {
		if (typeof document === 'undefined') {
			return;
		}

		const msgEl = document.getElementById('wp2-id-message');

		if (!msgEl) {
			return;
		}

		msgEl.style.display = 'block';
		msgEl.style.color = color;
		msgEl.textContent = message;
	}

	showCallbackError(message) {
		this.showCallbackNotification(message);
	}

	generateVerifier(length = 64) {
		const array = new Uint8Array(length);
		window.crypto.getRandomValues(array);
		return this.base64UrlEncode(array);
	}

	generateNonce(length = 64) {
		return this.generateVerifier(length);
	}

	async generateChallenge(verifier) {
		const encoder = new TextEncoder();
		const data = encoder.encode(verifier);
		const digest = await window.crypto.subtle.digest('SHA-256', data);
		return this.base64UrlEncode(new Uint8Array(digest));
	}

	base64UrlEncode(buffer) {
		let binary = '';
		const bytes = buffer instanceof Uint8Array ? buffer : new Uint8Array(buffer);

		bytes.forEach((byte) => {
			binary += String.fromCharCode(byte);
		});

		return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
	}
}
