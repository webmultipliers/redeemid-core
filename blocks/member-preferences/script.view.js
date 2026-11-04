/**
 * Member Preferences: saves a member's form through /account/preferences, and
 * holds a guest's answers in this browser (redeemid.holdPreferences) until
 * they sign in, then shows them in the member's form to confirm. Nothing is
 * saved to an account without that confirmation. The form and nonce arrive
 * in the block's island fragment after load, so this watches for it.
 *
 * Without JavaScript a member's form still posts to admin-post.php; a
 * guest's form stays hidden and the login link remains.
 */
const client = () => window.redeemid || null;

const messagesOf = (root) => {
	try {
		return JSON.parse(root.dataset.redeemidMessages || '{}');
	} catch {
		return {};
	}
};

const say = (root, key) => {
	const status = root.querySelector('.redeemid-preferences__status');

	if (status) {
		status.textContent = messagesOf(root)[key] || '';
	}
};

// The form's answers as namespace.key => value, typed as the REST route takes them.
const collect = (form) => {
	const values = {};

	form.querySelectorAll('[data-redeemid-key]').forEach((el) => {
		const key = el.dataset.redeemidKey;
		const type = el.dataset.redeemidType;

		if (el.tagName === 'FIELDSET') {
			values[key] = [...el.querySelectorAll('input[type="checkbox"]:checked')].map((box) => box.value);
		} else if (type === 'boolean') {
			values[key] = el.checked;
		} else if (type === 'list.single_line_text_field') {
			values[key] = el.value.split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
		} else {
			values[key] = el.value.trim();
		}
	});

	return values;
};

// Put held answers into the form, for fields it can change.
const fill = (form, values) => {
	Object.entries(values || {}).forEach(([key, value]) => {
		const el = form.querySelector(`[data-redeemid-key="${CSS.escape(key)}"]`);

		if (!el) {
			return;
		}

		if (el.tagName === 'FIELDSET') {
			el.querySelectorAll('input[type="checkbox"]').forEach((box) => {
				box.checked = Array.isArray(value) && value.includes(box.value);
			});
		} else if (el.type === 'checkbox') {
			el.checked = value === true;
		} else {
			el.value = Array.isArray(value) ? value.join('\n') : value ?? '';
		}
	});
};

// Mark the fields the server refused, in words as well as colour: each gets
// "Check this answer." tied to it with aria-describedby. Returns the first.
const mark = (root, form, fields) => {
	let first = null;

	form.querySelectorAll('[data-redeemid-key]').forEach((el) => {
		const bad = Object.prototype.hasOwnProperty.call(fields || {}, el.dataset.redeemidKey);
		const wrap = el.closest('.redeemid-preferences__field');
		const id = `redeemid-pref-error-${el.dataset.redeemidKey.replace(/[^\w-]/g, '-')}`;
		const described = (el.getAttribute('aria-describedby') || '').split(' ').filter((ref) => ref && ref !== id);
		let note = wrap?.querySelector('.redeemid-preferences__error');

		el.toggleAttribute('aria-invalid', bad);
		wrap?.classList.toggle('is-invalid', bad);

		if (bad && wrap) {
			if (!note) {
				note = document.createElement('span');
				note.className = 'redeemid-preferences__error';
				note.id = id;
				wrap.append(note);
			}

			note.textContent = messagesOf(root).field || '';
			described.push(id);
			first = first || el;
		} else {
			note?.remove();
		}

		described.length ? el.setAttribute('aria-describedby', described.join(' ')) : el.removeAttribute('aria-describedby');
	});

	return first;
};

async function save(root, form) {
	const button = form.querySelector('button[type="submit"]');
	const values = collect(form);

	if (button?.getAttribute('aria-disabled') === 'true') {
		return;
	}

	// aria-disabled, not disabled: disabling the focused button would drop focus to the page.
	button?.setAttribute('aria-disabled', 'true');

	try {
		const response = await fetch(root.dataset.redeemidEndpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': root.dataset.redeemidNonce },
			body: JSON.stringify({ values }),
		});
		const body = await response.json().catch(() => null);

		const invalid = mark(root, form, response.status === 400 ? body?.data?.fields : {});

		if (response.ok) {
			client()?.clearHeldPreferences?.();
			say(root, 'saved');
		} else {
			say(root, response.status === 400 ? 'invalid' : response.status === 429 ? 'busy' : 'error');
			// The first refused answer takes focus, so it is the next thing read.
			invalid?.focus();
		}
	} catch {
		say(root, 'error');
	} finally {
		button?.removeAttribute('aria-disabled');
	}
}

function hold(root, form) {
	const held = client()?.holdPreferences?.(collect(form));

	say(root, held ? 'held' : 'error');
}

function start(root) {
	if (root.dataset.redeemidReady) {
		return;
	}

	const form = root.querySelector('.redeemid-preferences__form');
	const state = root.dataset.redeemidPreferences;

	if (!form || (state !== 'member' && state !== 'guest')) {
		return;
	}

	root.dataset.redeemidReady = '1';

	const held = client()?.heldPreferences?.();

	if (state === 'guest') {
		form.hidden = false;
		fill(form, held);
	} else if (held) {
		fill(form, held);
		say(root, 'confirm');
	}

	form.addEventListener('submit', (event) => {
		event.preventDefault();
		state === 'member' ? save(root, form) : hold(root, form);
	});
}

const scan = () => document.querySelectorAll('[data-redeemid-preferences]').forEach(start);

// The client script may load after this one: wait for the page, then for each fragment.
const run = () => {
	scan();
	new MutationObserver(scan).observe(document.body, { childList: true, subtree: true });
};

document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', run) : run();
