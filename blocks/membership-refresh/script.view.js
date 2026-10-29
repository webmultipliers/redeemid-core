/**
 * Click handler for redeemid/membership-refresh. The button and its REST
 * nonce arrive in the block's island fragment after load, so listen on the
 * document rather than on the button. Posts to /account/sync, then reloads so
 * gates re-render with the new tags. The fragment carries the translated
 * messages; these English ones are only the fallback.
 */
const fallback = {
	checking: 'Checking with Shopify…',
	synced: 'Membership updated. Reloading…',
	sync_wait: 'You just refreshed. Try again in a minute.',
	sync_failed: 'Shopify could not be reached. Try again later.',
};

async function refresh(root) {
	const button = root.querySelector('.redeemid-refresh__button');
	const message = root.querySelector('.redeemid-refresh__message');
	let messages = fallback;

	try {
		messages = { ...fallback, ...JSON.parse(root.dataset.redeemidRefreshMessages || '{}') };
	} catch {}

	button.disabled = true;
	message.textContent = messages.checking;

	let result = 'sync_failed';

	try {
		const response = await fetch(root.dataset.redeemidRefreshEndpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { Accept: 'application/json', 'X-WP-Nonce': root.dataset.redeemidRefreshNonce },
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
	const root = event.target.closest('.redeemid-refresh__button')?.closest('[data-redeemid-refresh-nonce]');

	if (root) {
		refresh(root);
	}
});
