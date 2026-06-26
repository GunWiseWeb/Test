document.addEventListener('DOMContentLoaded', function() {
	var params = new URLSearchParams(window.location.search);
	var hash = window.location.hash.replace('#', '');
	var sections = ['deals', 'coupons', 'listings', 'reviews', 'info'];
	var active = 'deals';

	var wrapper = document.querySelector('.gdProfile2');
	if (wrapper && wrapper.getAttribute('data-gd-default')) {
		active = wrapper.getAttribute('data-gd-default');
	}

	if (sections.indexOf(hash) !== -1) {
		active = hash;
	} else if (params.has('sort') || params.has('stars') || params.has('dispute')) {
		active = 'reviews';
	} else if (params.has('lq') || params.has('lpage')) {
		active = 'listings';
	}

	function activate(section) {
		var panels = document.querySelectorAll('[data-gd-panel]');
		for (var i = 0; i < panels.length; i++) {
			if (panels[i].getAttribute('data-gd-panel') === section) {
				panels[i].classList.add('gdPanel--active');
			} else {
				panels[i].classList.remove('gdPanel--active');
			}
		}
		var navs = document.querySelectorAll('[data-gd-navitem]');
		for (var j = 0; j < navs.length; j++) {
			if (navs[j].getAttribute('data-gd-navitem') === section) {
				navs[j].classList.add('gdNavList__item--active');
			} else {
				navs[j].classList.remove('gdNavList__item--active');
			}
		}

		/* IPS editorv5 defers mounting when its container is display:none at load.
		   The review editor sits inside the hidden reviews tab, so it never receives
		   its initializeEditor jQuery event. Fire it ourselves when reviews is shown. */
		if (section === 'reviews') {
			try {
				if (window.jQuery) {
					window.jQuery(document).trigger('initializeEditor', [{ editorID: 'gddealer_review_body' }]);
				}
			} catch (err) { /* no-op */ }
		}
	}

	activate(active);

	var links = document.querySelectorAll('[data-gd-section]');
	for (var k = 0; k < links.length; k++) {
		links[k].addEventListener('click', function(e) {
			e.preventDefault();
			var sec = this.getAttribute('data-gd-section');
			activate(sec);
			history.replaceState(null, '', '#' + sec);
		});
	}
});
