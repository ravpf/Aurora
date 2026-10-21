/**
 * Q&A voting.
 *
 * The buttons are rendered server-side (so they work before JS loads and stay
 * readable to crawlers); this file only sends the click and reconciles the
 * numbers that come back. No optimistic update: a vote that the server rejected
 * must not look like it counted.
 */
(function () {
	'use strict';

	var cfg = window.AuroraQA;
	if (!cfg || !cfg.ajax) {
		return;
	}

	function post(action, body) {
		var data = new FormData();
		data.append('action', action);
		data.append('nonce', cfg.nonce);
		Object.keys(body).forEach(function (k) {
			data.append(k, body[k]);
		});

		return fetch(cfg.ajax, {
			method: 'POST',
			body: data,
			credentials: 'same-origin',
			headers: { 'X-Requested-With': 'XMLHttpRequest' }
		}).then(function (r) {
			return r.json().then(function (json) {
				return { ok: r.ok, json: json };
			});
		});
	}

	function paint(box, payload) {
		var score = box.querySelector('[data-score]');
		if (score) {
			score.textContent = String(payload.score);
		}
		box.querySelectorAll('[data-qa-vote]').forEach(function (btn) {
			var dir = parseInt(btn.getAttribute('data-qa-vote'), 10);
			btn.classList.toggle('is-on', dir === payload.my);
		});
	}

	function flash(box, text) {
		var tip = box.querySelector('.aurora-qa__tip');
		if (!tip) {
			tip = document.createElement('span');
			tip.className = 'aurora-qa__tip';
			box.appendChild(tip);
		}
		tip.textContent = text;
		window.setTimeout(function () {
			tip.textContent = '';
		}, 2600);
	}

	document.addEventListener('click', function (event) {
		var vote = event.target.closest('[data-qa-vote]');
		var accept = event.target.closest('[data-qa-accept]');

		if (vote) {
			var box = vote.closest('.aurora-qa');
			var dir = parseInt(vote.getAttribute('data-qa-vote'), 10);
			var on = vote.classList.contains('is-on');

			// Clicking the same arrow again withdraws the vote.
			post('aurora_qa_vote', {
				comment: box.getAttribute('data-comment'),
				dir: on ? 0 : dir
			}).then(function (res) {
				if (res.ok && res.json.success) {
					paint(box, res.json.data);
				} else {
					flash(box, (res.json.data && res.json.data.message) || cfg.i18n.login);
				}
			});
			return;
		}

		if (accept) {
			var host = accept.closest('.aurora-qa');
			post('aurora_qa_accept', {
				post: cfg.post,
				comment: accept.getAttribute('data-qa-accept')
			}).then(function (res) {
				if (res.ok && res.json.success) {
					window.location.reload();
				} else {
					flash(host, (res.json.data && res.json.data.message) || '');
				}
			});
		}
	});
})();
