/* gdsearch listing report: per-dealer trigger -> modal (5 reasons + note). AJAX, csrfKey in POST. */
(function () {
	var modal = null, currentUpc = '', currentDealerId = 0;

	function post(url, data) {
		var body = new URLSearchParams();
		Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
		return fetch(url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
			body: body.toString(), credentials: 'same-origin'
		}).then(function (r) { return r.json().catch(function () { return { ok: false }; }); });
	}

	function openModal(trigger) {
		currentUpc = trigger.getAttribute('data-upc') || '';
		currentDealerId = trigger.getAttribute('data-dealer-id') || '0';
		var dealerName = trigger.getAttribute('data-dealer-name') || 'this dealer';
		modal.querySelector('.gd-listing-report-dealer').textContent = 'Reporting ' + dealerName + '’s listing';
		modal.querySelector('.gd-listing-report-msg').textContent = '';
		modal.querySelector('.gd-listing-report-note').value = '';
		var radios = modal.querySelectorAll('input[name="gd_listing_reason"]');
		if (radios.length) { radios[0].checked = true; }
		modal.querySelector('.gd-listing-report-form').style.display = '';
		modal.querySelector('.gd-listing-report-submit').style.display = '';
		modal.style.display = 'flex';
	}

	function closeModal() { modal.style.display = 'none'; currentUpc = ''; currentDealerId = 0; }

	function wire() {
		modal = document.getElementById('gdListingReportModal');
		if (!modal) { return; }

		var inner = modal.querySelector('[data-report-url]');
		if (!inner) { return; }
		var reportUrl = inner.getAttribute('data-report-url') || '';
		var csrf = inner.getAttribute('data-csrf') || '';
		var loginUrl = inner.getAttribute('data-login-url') || '/';

		document.querySelectorAll('.gd-listing-report-trigger').forEach(function (t) {
			t.addEventListener('click', function () {
				if (t.getAttribute('data-logged-in') !== '1') { window.location = loginUrl; return; }
				openModal(t);
			});
		});

		modal.querySelector('.gd-listing-report-close').addEventListener('click', closeModal);
		modal.addEventListener('click', function (e) { if (e.target === modal) { closeModal(); } });
		document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.style.display === 'flex') { closeModal(); } });

		modal.querySelector('.gd-listing-report-submit').addEventListener('click', function () {
			if (!currentUpc) { return; }
			var reason = (modal.querySelector('input[name="gd_listing_reason"]:checked') || {}).value || 'other';
			var note = modal.querySelector('.gd-listing-report-note').value || '';
			var btn = this; btn.disabled = true;
			post(reportUrl, {
				upc: currentUpc, dealer_id: currentDealerId, reason: reason, note: note, csrfKey: csrf
			}).then(function (res) {
				btn.disabled = false;
				if (res && res.ok) {
					modal.querySelector('.gd-listing-report-form').style.display = 'none';
					btn.style.display = 'none';
					modal.querySelector('.gd-listing-report-msg').textContent = 'Thanks — your report has been sent to the dealer.';
					setTimeout(closeModal, 1600);
				} else if (res && res.error === 'login') {
					window.location = loginUrl;
				} else {
					modal.querySelector('.gd-listing-report-msg').textContent = 'Could not submit. Please try again.';
					modal.querySelector('.gd-listing-report-msg').style.color = '#dc2626';
				}
			});
		});
	}

	if (document.readyState !== 'loading') { wire(); }
	else { document.addEventListener('DOMContentLoaded', wire); }
})();
