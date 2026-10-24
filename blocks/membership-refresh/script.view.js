/**
 * Click handler for wp2-shopify/membership-refresh. The button and its REST
 * nonce arrive in the block's island fragment after load, so listen on the
 * document rather than on the button. Posts to /account/sync, then reloads so
 * gates re-render with the new tags.
 */
const messages = {
	synced: 'Membership updated. Reloading…',
	sync_wait: 'You just refreshed. Try again in a minute.',
	sync_failed: 'Shopify could not be reached. Try again later.',
};

async function refresh(root) {
	const button = root.querySelector('.wp2-shopify-refresh__button');
	const message = root.querySelector('.wp2-shopify-refresh__message');

	button.disabled = true;
	message.textContent = 'Checking with Shopify…';

	let result = 'sync_failed';

	try {
		const response = await fetch(root.dataset.wp2RefreshEndpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { Accept: 'application/json', 'X-WP-Nonce': root.dataset.wp2RefreshNonce },
		});
		const body = await response.json().catch(() => null);
		result = response.ok && body && body.result ? body.result : 'sync_failed';
	} catch {
		result = 'sync_failed';
	}

	message.textContent = messages[result] ?? messages.sync_failed;

	if (result === 'synced') {
		window.setTimeout(() => window.location.reload(), 600);
		return;
	}

	button.disabled = false;
}

document.addEventListener('click', (event) => {
	const root = event.target.closest('.wp2-shopify-refresh__button')?.closest('[data-wp2-refresh-nonce]');

	if (root) {
		refresh(root);
	}
});
