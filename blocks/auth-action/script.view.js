/**
 * Hydrates wp2-shopify/auth-action shells with the visitor's own state.
 * One status request per page, shared by every instance.
 */
let statusRequest;

function fetchStatus(url) {
	statusRequest ??= fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
		.then((response) => (response.ok ? response.json() : null))
		.catch(() => null);

	return statusRequest;
}

function postForm(status, config, action, nonce, label, redirect) {
	const form = document.createElement('form');
	form.method = 'post';
	form.action = status.actionUrl;
	form.className = 'wp2-shopify-auth__form';

	const fields = { action, _wpnonce: nonce, redirect_to: redirect || window.location.href };

	for (const [name, value] of Object.entries(fields)) {
		const input = document.createElement('input');
		input.type = 'hidden';
		input.name = name;
		input.value = value;
		form.append(input);
	}

	const button = document.createElement('button');
	button.type = 'submit';
	button.className = config.className;
	button.textContent = label;
	form.append(button);

	return form;
}

function render(root, config, status) {
	if (!status || !status.signedIn) {
		return; // The server-rendered guest shell is already correct.
	}

	root.replaceChildren();

	const linked = status.linked || status.shadow;

	if (linked && (config.mode === 'auto' || config.mode === 'logout')) {
		root.append(postForm(status, config, 'wp2_shopify_logout', status.nonces.logout, config.labels.logout, config.afterLogout));
	} else if (!linked && (config.mode === 'auto' || config.mode === 'connect')) {
		root.append(postForm(status, config, 'wp2_shopify_connect', status.nonces.connect, config.labels.connect, ''));
	}
}

for (const root of document.querySelectorAll('[data-wp2-auth]')) {
	let config;

	try {
		config = JSON.parse(root.dataset.wp2Auth);
	} catch {
		continue;
	}

	fetchStatus(config.statusUrl).then((status) => render(root, config, status));
}
