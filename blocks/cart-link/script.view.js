/**
 * Fill in the cart link's item count from the readable redeemid_cart_count
 * cookie that RedeemID sets on every cart change. It holds a number only;
 * the cart itself stays on the server. No request is made.
 */
const match = document.cookie.match(/(?:^|;\s*)redeemid_cart_count=(\d+)/);
const count = match ? Number(match[1]) : 0;

if (count > 0) {
	document.querySelectorAll('[data-redeemid-cart-link]').forEach((link) => {
		const badge = link.querySelector('.redeemid-cart-link__count');
		const words = count === 1 ? link.dataset.redeemidItem : (link.dataset.redeemidItems || '%d items').replace('%d', count);

		badge.textContent = String(count);
		badge.hidden = false;
		link.setAttribute('aria-label', `${link.querySelector('.redeemid-cart-link__label').textContent.trim()}, ${words}`);
	});
}
