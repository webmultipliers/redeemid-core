/**
 * Read out the result of a form the visitor just sent.
 *
 * RedeemID's forms (buy now, the cart, Refresh membership, connect, privacy
 * requests, preferences) post to admin-post.php and come back to the page
 * with ?redeemid_notice. A status message in a freshly loaded page is not
 * announced by screen readers, so the first notice marked data-redeemid-notice
 * takes focus, which reads it and starts the visitor from there. Notices that
 * arrive in a Blockstudio island fragment (Member Account, Member Preferences)
 * are caught when the fragment renders.
 *
 * It only moves focus that is still on the page itself: once the visitor has
 * moved on, nothing is taken from them. Once a page, at most.
 */
let done = false;

const say = (root) => {
	const notice = done ? null : root.querySelector?.('[data-redeemid-notice]');

	if (!notice || '' === notice.textContent.trim()) {
		return;
	}

	const active = document.activeElement;

	if (active && active !== document.body && active !== document.documentElement) {
		return;
	}

	done = true;
	notice.setAttribute('tabindex', '-1');
	notice.focus();
};

const start = () => say(document);

document.addEventListener('blockstudio:island:rendered', (event) => say(event.target));
document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', start) : start();
