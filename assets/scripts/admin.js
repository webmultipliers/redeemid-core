function updateFeedback(feedbackElement, success, message) {
	if (!feedbackElement) {
		return;
	}

	feedbackElement.textContent = message;
	feedbackElement.classList.remove('success', 'error');
	feedbackElement.classList.add(success ? 'success' : 'error');
	feedbackElement.style.display = 'block';
}

document.addEventListener('DOMContentLoaded', () => {
	const verifyButton = document.getElementById('btn-verify');
	const feedbackElement = document.getElementById('connection-feedback');

	if (!verifyButton) {
		return;
	}

	verifyButton.addEventListener('click', async (event) => {
		event.preventDefault();

		const nonce = window.wp2Config?.rest_nonce || '';
		const originalLabel = verifyButton.textContent;

		verifyButton.disabled = true;
		verifyButton.textContent = 'Verifying…';
		if (feedbackElement) {
			feedbackElement.style.display = 'none';
		}

		try {
			const response = await fetch('/wp-json/wp2-shopify/v1/verify-connection', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce,
				},
				body: JSON.stringify({}),
			});

			const data = await response.json();

			if (response.ok && data.success) {
				const label = data.shop_name ? `${data.message} (${data.shop_name})` : data.message;
				updateFeedback(feedbackElement, true, label || 'Connection verified');
			} else {
				updateFeedback(feedbackElement, false, data.message || 'Unexpected response from Shopify.');
			}
		} catch (error) {
			updateFeedback(
				feedbackElement,
				false,
				error?.message ? `Error: ${error.message}` : 'Unable to reach the identity service.'
			);
		} finally {
			verifyButton.disabled = false;
			verifyButton.textContent = originalLabel;
		}
	});
});
