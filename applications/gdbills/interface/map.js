/*
 * GD Bills — state-map modal handler.
 *
 * Reads endpoints from data-ajax-state-url / data-ajax-map-url on the
 * .gd-bills wrapper. Clicking a state tile opens a modal pre-populated
 * with that state's bills (server-rendered HTML fragment).
 *
 * Note: clicking a tile ALSO has a real href so users without JS get
 * the full page filtered by that state. The JS handler hijacks the
 * click only when modal is preferable (default).
 */
(function () {
	'use strict';

	function ready(fn) {
		if (document.readyState !== 'loading') { fn(); }
		else { document.addEventListener('DOMContentLoaded', fn); }
	}

	ready(function () {
		var wrap = document.querySelector('.gd-bills');
		if (!wrap) { return; }

		var stateUrl = wrap.getAttribute('data-ajax-state-url') || '';
		var modal    = document.getElementById('gdBillsModal');
		var body     = document.getElementById('gdBillsModalBody');

		function openModal() { if (modal) { modal.hidden = false; document.body.style.overflow = 'hidden'; } }
		function closeModal() { if (modal) { modal.hidden = true; document.body.style.overflow = ''; if (body) { body.innerHTML = ''; } } }

		if (modal) {
			modal.addEventListener('click', function (e) {
				if (e.target && e.target.getAttribute && e.target.getAttribute('data-close-modal') === '1') {
					closeModal();
				}
			});
			document.addEventListener('keydown', function (e) {
				if (e.key === 'Escape' && !modal.hidden) { closeModal(); }
			});
		}

		var tiles = wrap.querySelectorAll('.gd-bills__tile[data-state]');
		for (var i = 0; i < tiles.length; i++) {
			tiles[i].addEventListener('click', function (e) {
				if (!stateUrl || !modal || !body) { return; /* fall through to href */ }
				var st = this.getAttribute('data-state');
				if (!st) { return; }
				e.preventDefault();
				body.innerHTML = '<p style="text-align:center;padding:40px;color:#6b7480">Loading…</p>';
				openModal();
				var url = stateUrl + (stateUrl.indexOf('?') === -1 ? '?' : '&') + 'state=' + encodeURIComponent(st);
				fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
					.then(function (r) { return r.json(); })
					.then(function (j) {
						if (j && j.ok && j.html) { body.innerHTML = j.html; }
						else { body.innerHTML = '<p style="text-align:center;padding:40px;color:#6b7480">No bills.</p>'; }
					})
					.catch(function () {
						body.innerHTML = '<p style="text-align:center;padding:40px;color:#c0392b">Error loading.</p>';
					});
			});
		}
	});
})();
