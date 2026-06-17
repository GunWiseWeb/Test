(function () {
	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.gd-vote-btn');
		if (!btn) { return; }
		e.preventDefault();
		var w = btn.closest('.gd-vote');
		if (!w) { return; }
		var url = w.getAttribute('data-vote-url');
		var dir = btn.getAttribute('data-dir');
		btn.disabled = true;
		fetch(url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
			credentials: 'same-origin',
			body: 'dir=' + encodeURIComponent(dir)
		}).then(function (r) { return r.json(); }).then(function (d) {
			btn.disabled = false;
			if (d && d.error === 'login') { var l = w.getAttribute('data-login-url'); if (l) { window.location = l; } return; }
			if (!d || !d.ok) { return; }
			var s = w.querySelector('.gd-vote-score'); if (s) { s.textContent = d.score; }
			var up = w.querySelector('.gd-vote-up'), dn = w.querySelector('.gd-vote-down');
			if (up) { up.classList.toggle('gd-voted', d.userVote === 1); }
			if (dn) { dn.classList.toggle('gd-voted', d.userVote === -1); }
			w.setAttribute('data-uservote', d.userVote);
		}).catch(function () { btn.disabled = false; });
	});

	document.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('.gd-cpn-copy') : null;
		if (!btn) { return; }
		e.preventDefault();
		var code = btn.getAttribute('data-code') || '';
		if (!code) { return; }
		var done = function () {
			var prev = btn.textContent;
			btn.textContent = 'Copied!';
			btn.classList.add('gd-cpn-copied');
			setTimeout(function () { btn.textContent = prev; btn.classList.remove('gd-cpn-copied'); }, 1500);
		};
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(code).then(done).catch(done);
		} else {
			done();
		}
	});
})();
