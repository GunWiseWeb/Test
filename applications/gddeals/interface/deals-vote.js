document.addEventListener('click', function(e) {
	var btn = e.target.closest('.gd-vote-btn');
	if (!btn) return;

	e.preventDefault();
	e.stopPropagation();

	var widget = btn.closest('.gd-vote-widget');
	if (!widget) return;

	var url = widget.getAttribute('data-vote-url');
	var csrfKey = widget.getAttribute('data-csrf-key');
	var dir = btn.getAttribute('data-dir');
	if (!url || !csrfKey || !dir) return;

	var body = new FormData();
	body.append('csrfKey', csrfKey);
	body.append('dir', dir);

	fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
		.then(function(r) { return r.json(); })
		.then(function(j) {
			if (!j || j.error) return;

			var upBtn = widget.querySelector('.gd-vote-btn[data-dir="up"]');
			var dnBtn = widget.querySelector('.gd-vote-btn[data-dir="down"]');
			var scoreEl = widget.querySelector('.gd-vote-score');

			if (upBtn) {
				if (j.user_vote === 1) {
					upBtn.classList.add('gd-voted');
				} else {
					upBtn.classList.remove('gd-voted');
				}
			}
			if (dnBtn) {
				if (j.user_vote === -1) {
					dnBtn.classList.add('gd-voted');
				} else {
					dnBtn.classList.remove('gd-voted');
				}
			}
			if (scoreEl) {
				scoreEl.textContent = j.score;
			}

			var heatBadge = widget.parentElement.querySelector('.gd-heat-badge');
			if (!heatBadge) {
				heatBadge = widget.closest('.gd-deal-card, .gd-deal-info');
				if (heatBadge) heatBadge = heatBadge.querySelector('.gd-heat-badge');
			}
			if (heatBadge) {
				heatBadge.className = 'gd-heat-badge gd-heat--' + j.heat_label;
				heatBadge.textContent = j.heat_text;
				if (j.heat_label === 'cold') {
					heatBadge.style.display = 'none';
				} else {
					heatBadge.style.display = '';
				}
			}
		})
		.catch(function() {});
});
