document.addEventListener('click', function (e) {
	var btn = e.target.closest('[data-gd-announce-close]');
	if (!btn) { return; }
	var box = btn.closest('[data-gd-announce]');
	if (!box) { return; }
	var url = box.getAttribute('data-dismissurl');
	var version = box.getAttribute('data-version');
	var csrf = box.getAttribute('data-csrfkey');
	box.style.display = 'none';
	try {
		fetch(url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
			body: 'version=' + encodeURIComponent(version) + '&csrfKey=' + encodeURIComponent(csrf)
		});
	} catch (err) {}
});
