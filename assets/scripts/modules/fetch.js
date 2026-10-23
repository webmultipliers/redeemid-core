// wp2Fetch.js

/**
 * A shared network utility to handle nonce and credentials setup.
 */
let isRefreshing = false;
let failedQueue = [];

const processQueue = (error, token = null) => {
	failedQueue.forEach(prom => {
		if (error) {
			prom.reject(error);
		} else {
			prom.resolve(token);
		}
	});

	failedQueue = [];
};

export async function wp2Fetch(url, options = {}) {
	const rest_nonce = window.wp2Config?.rest_nonce || '';

	const headers = {
		'Content-Type': 'application/json',
		...options.headers,
	};

	if (rest_nonce) {
		headers['X-WP-Nonce'] = rest_nonce;
	}

	let response = await fetch(url, {
		credentials: 'same-origin',
		...options,
		headers,
	});

	if (response.status === 401 && !url.includes('/auth/refresh')) {
		if (isRefreshing) {
			return new Promise((resolve, reject) => {
				failedQueue.push({ resolve, reject });
			})
				.then(() => {
					return wp2Fetch(url, options);
				})
				.catch((err) => {
					return Promise.reject(err);
				});
		}

		isRefreshing = true;

		try {
			const refreshResponse = await fetch('/wp-json/wp2-shopify/v1/auth/refresh', {
				method: 'POST',
				credentials: 'same-origin',
				headers,
			});

			const data = await refreshResponse.json();

			if (refreshResponse.ok && data.success) {
				processQueue(null, data);
				return wp2Fetch(url, options);
			} else {
				processQueue(new Error('Refresh failed'));
				throw new Error('Session expired');
			}
		} catch (error) {
			processQueue(error);
			if (options.onUnauthorized) {
				options.onUnauthorized();
			} else {
				window.location.reload();
			}
			throw error;
		} finally {
			isRefreshing = false;
		}
	}

	if (response.status === 403) {
		console.warn('Nonce expired. Reloading.');
		window.location.reload();
		return;
	}

	if (!response.ok) {
		throw new Error(`HTTP error! status: ${response.status}`);
	}

	return response.json();
}
