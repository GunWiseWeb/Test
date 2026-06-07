(function(){
'use strict';

var el = document.getElementById('gdlo-init');
if (!el) return;
var cfg = JSON.parse(el.textContent);

var state = {
	loadout: cfg.loadout,
	items: [],
	activeSlot: null,
	searchTimer: null
};

var slotDefs = cfg.slots;
var limits   = cfg.limits;
var isVip    = cfg.isVip;
var csrfKey  = cfg.csrfKey;
var searchUrl = cfg.searchUrl;
var saveUrl   = cfg.saveUrl;
var deleteUrl = cfg.deleteUrl;

if (cfg.loadout) {
	document.getElementById('gdlo-name').value = cfg.loadout.name || '';
	document.getElementById('gdlo-desc').value = cfg.loadout.description || '';
	document.getElementById('gdlo-usecase').value = cfg.loadout.use_case || '';
	var visSel = document.getElementById('gdlo-visibility');
	if (visSel) {
		for (var i = 0; i < visSel.options.length; i++) {
			if (visSel.options[i].value === cfg.loadout.visibility) {
				visSel.selectedIndex = i;
				break;
			}
		}
	}
	document.getElementById('gdlo-delete-wrap').style.display = '';
}

if (!isVip) {
	var visSel = document.getElementById('gdlo-visibility');
	if (visSel) {
		for (var i = visSel.options.length - 1; i >= 0; i--) {
			if (visSel.options[i].value === 'private') {
				visSel.remove(i);
			}
		}
	}
}

function renderSlots() {
	var container = document.getElementById('gdlo-slots');
	container.innerHTML = '';

	var keys = Object.keys(slotDefs);
	for (var s = 0; s < keys.length; s++) {
		var key = keys[s];
		var def = slotDefs[key];
		var div = document.createElement('div');
		div.className = 'gdlo-slot';
		div.dataset.slot = key;

		var filled = findItem(key);
		if (filled) {
			div.classList.add('gdlo-slot-filled');
		}
		if (state.activeSlot === key) {
			div.classList.add('gdlo-slot-active');
		}

		var iconSpan = document.createElement('span');
		iconSpan.className = 'gdlo-slot-icon';
		iconSpan.innerHTML = '<i class="fa fa-' + escHtml(def.icon) + '"></i>';
		div.appendChild(iconSpan);

		var label = document.createElement('span');
		label.className = 'gdlo-slot-label';
		label.textContent = def.label;
		div.appendChild(label);

		var prod = document.createElement('span');
		prod.className = 'gdlo-slot-product';
		if (filled) {
			if (filled.image_url) {
				var img = document.createElement('img');
				img.src = filled.image_url;
				img.alt = '';
				prod.appendChild(img);
			}
			var txt = document.createTextNode(filled.title || filled.upc);
			prod.appendChild(txt);
			if (filled.best_price !== null && filled.best_price !== undefined) {
				var priceSpan = document.createElement('span');
				priceSpan.style.marginLeft = '8px';
				priceSpan.style.color = '#2a7a2a';
				priceSpan.textContent = '$' + Number(filled.best_price).toFixed(2);
				prod.appendChild(priceSpan);
			}
		} else {
			prod.innerHTML = '<em style="color:#999">Empty — click to search</em>';
		}
		div.appendChild(prod);

		var actions = document.createElement('span');
		actions.className = 'gdlo-slot-actions';
		if (filled) {
			var rmBtn = document.createElement('button');
			rmBtn.className = 'ipsButton ipsButton_verySmall ipsButton_light';
			rmBtn.textContent = 'Remove';
			rmBtn.dataset.removeSlot = key;
			actions.appendChild(rmBtn);
		}
		div.appendChild(actions);

		div.addEventListener('click', (function(k) {
			return function(e) {
				if (e.target.dataset.removeSlot) return;
				state.activeSlot = k;
				renderSlots();
				document.getElementById('gdlo-search-input').focus();
			};
		})(key));

		container.appendChild(div);
	}

	container.addEventListener('click', function(e) {
		var removeKey = e.target.dataset.removeSlot;
		if (removeKey) {
			e.stopPropagation();
			removeItem(removeKey);
		}
	});
}

function renderExtras() {
	var container = document.getElementById('gdlo-extras');
	container.innerHTML = '';

	var extras = state.items.filter(function(it) { return it.slot_type === 'extra'; });
	for (var i = 0; i < extras.length; i++) {
		var it = extras[i];
		var row = document.createElement('div');
		row.className = 'gdlo-extra-row';

		if (it.image_url) {
			var img = document.createElement('img');
			img.src = it.image_url;
			img.alt = '';
			row.appendChild(img);
		}

		var info = document.createElement('span');
		info.style.flex = '1';
		info.textContent = it.custom_label || it.title || it.upc;
		if (it.best_price !== null && it.best_price !== undefined) {
			info.textContent += ' — $' + Number(it.best_price).toFixed(2);
		}
		row.appendChild(info);

		if (isVip) {
			var notesInput = document.createElement('input');
			notesInput.type = 'text';
			notesInput.placeholder = 'Notes';
			notesInput.maxLength = 300;
			notesInput.value = it.notes || '';
			notesInput.style.width = '120px';
			notesInput.dataset.extraNotes = String(i);
			row.appendChild(notesInput);
		}

		var rmBtn = document.createElement('button');
		rmBtn.className = 'ipsButton ipsButton_verySmall ipsButton_light';
		rmBtn.textContent = 'Remove';
		rmBtn.dataset.removeExtra = String(i);
		row.appendChild(rmBtn);

		container.appendChild(row);
	}

	container.addEventListener('click', function(e) {
		var idx = e.target.dataset.removeExtra;
		if (idx !== undefined) {
			var extras2 = state.items.filter(function(it) { return it.slot_type === 'extra'; });
			var target = extras2[parseInt(idx, 10)];
			if (target) {
				var pos = state.items.indexOf(target);
				if (pos !== -1) {
					state.items.splice(pos, 1);
					renderExtras();
					updateSummary();
				}
			}
		}
	});

	container.addEventListener('input', function(e) {
		var idx = e.target.dataset.extraNotes;
		if (idx !== undefined) {
			var extras2 = state.items.filter(function(it) { return it.slot_type === 'extra'; });
			var target = extras2[parseInt(idx, 10)];
			if (target) {
				target.notes = e.target.value;
			}
		}
	});
}

function updateSummary() {
	var count = state.items.length;
	var total = 0;
	var hasNfa = false;

	document.getElementById('gdlo-item-count').textContent = String(count);

	var tbody = document.getElementById('gdlo-item-tbody');
	tbody.innerHTML = '';

	for (var i = 0; i < state.items.length; i++) {
		var it = state.items[i];
		var tr = document.createElement('tr');

		var tdName = document.createElement('td');
		tdName.textContent = it.title || it.custom_label || it.upc;
		tr.appendChild(tdName);

		var tdPrice = document.createElement('td');
		if (it.best_price !== null && it.best_price !== undefined) {
			tdPrice.textContent = '$' + Number(it.best_price).toFixed(2);
			total += Number(it.best_price);
		} else {
			tdPrice.textContent = '—';
		}
		tr.appendChild(tdPrice);

		var tdRm = document.createElement('td');
		tdRm.innerHTML = '<span class="gdlo-remove-btn" data-summary-remove="' + i + '">✕</span>';
		tr.appendChild(tdRm);

		tbody.appendChild(tr);

		if (it.slot_type === 'suppressor') hasNfa = true;
	}

	document.getElementById('gdlo-total-cost').textContent = '$' + total.toFixed(2);

	var limitNotice = document.getElementById('gdlo-slot-limit-notice');
	if (limits.max_slots > 0 && count >= limits.max_slots) {
		limitNotice.style.display = '';
	} else {
		limitNotice.style.display = 'none';
	}

	var nfaNotice = document.getElementById('gdlo-nfa-notice');
	nfaNotice.style.display = hasNfa ? '' : 'none';

	tbody.addEventListener('click', function(e) {
		var idx = e.target.dataset.summaryRemove;
		if (idx !== undefined) {
			var removed = state.items.splice(parseInt(idx, 10), 1);
			if (removed.length && removed[0].slot_type !== 'extra') {
				renderSlots();
			}
			renderExtras();
			updateSummary();
		}
	});
}

function findItem(slotType) {
	for (var i = 0; i < state.items.length; i++) {
		if (state.items[i].slot_type === slotType && slotType !== 'extra') {
			return state.items[i];
		}
	}
	return null;
}

function removeItem(slotType) {
	for (var i = 0; i < state.items.length; i++) {
		if (state.items[i].slot_type === slotType && slotType !== 'extra') {
			state.items.splice(i, 1);
			break;
		}
	}
	renderSlots();
	renderExtras();
	updateSummary();
}

function addProduct(product) {
	if (limits.max_slots > 0 && state.items.length >= limits.max_slots) {
		return;
	}

	var slotType = state.activeSlot || 'extra';

	if (slotType !== 'extra') {
		for (var i = 0; i < state.items.length; i++) {
			if (state.items[i].slot_type === slotType) {
				state.items.splice(i, 1);
				break;
			}
		}
	}

	state.items.push({
		upc: product.upc,
		slot_type: slotType,
		custom_label: slotType === 'extra' ? (product.title || '') : '',
		title: product.title || product.upc,
		image_url: product.image_url || '',
		best_price: product.best_price,
		category: product.category || '',
		notes: '',
		sort_order: state.items.length
	});

	state.activeSlot = null;
	renderSlots();
	renderExtras();
	updateSummary();
}

var searchInput = document.getElementById('gdlo-search-input');
searchInput.addEventListener('input', function() {
	clearTimeout(state.searchTimer);
	var q = searchInput.value.trim();
	if (q.length < 2) {
		document.getElementById('gdlo-search-results').innerHTML = '';
		return;
	}
	state.searchTimer = setTimeout(function() { doSearch(q); }, 350);
});

function doSearch(q) {
	var fd = new FormData();
	fd.append('csrfKey', csrfKey);
	fd.append('q', q);

	fetch(searchUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
		.then(function(r) { return r.json(); })
		.then(function(data) {
			var container = document.getElementById('gdlo-search-results');
			container.innerHTML = '';
			var results = data.results || [];
			if (!results.length) {
				container.innerHTML = '<p class="ipsType_light">No results found.</p>';
				return;
			}
			for (var i = 0; i < results.length; i++) {
				var r = results[i];
				var row = document.createElement('div');
				row.className = 'gdlo-sr';

				if (r.image_url) {
					var img = document.createElement('img');
					img.src = r.image_url;
					img.alt = '';
					img.loading = 'lazy';
					row.appendChild(img);
				}

				var info = document.createElement('div');
				info.className = 'gdlo-sr-info';
				var title = document.createElement('div');
				title.textContent = r.title;
				info.appendChild(title);
				var meta = document.createElement('div');
				meta.style.fontSize = '0.85em';
				meta.style.color = '#666';
				var parts = [];
				if (r.category) parts.push(r.category);
				if (r.caliber) parts.push(r.caliber);
				meta.textContent = parts.join(' · ');
				info.appendChild(meta);
				row.appendChild(info);

				if (r.best_price !== null && r.best_price !== undefined) {
					var price = document.createElement('span');
					price.className = 'gdlo-sr-price';
					price.textContent = '$' + Number(r.best_price).toFixed(2);
					row.appendChild(price);
				}

				row.addEventListener('click', (function(prod) {
					return function() { addProduct(prod); };
				})(r));

				container.appendChild(row);
			}
		})
		.catch(function() {
			document.getElementById('gdlo-search-results').innerHTML = '<p class="ipsType_light">Search error.</p>';
		});
}

document.getElementById('gdlo-add-extra').addEventListener('click', function() {
	state.activeSlot = 'extra';
	document.getElementById('gdlo-search-input').focus();
});

document.getElementById('gdlo-save').addEventListener('click', function() {
	var name = document.getElementById('gdlo-name').value.trim();
	if (!name) {
		alert('A name is required.');
		document.getElementById('gdlo-name').focus();
		return;
	}

	var payload = {
		csrfKey: csrfKey,
		name: name,
		description: document.getElementById('gdlo-desc').value.trim(),
		use_case: document.getElementById('gdlo-usecase').value.trim(),
		visibility: document.getElementById('gdlo-visibility').value,
		items: state.items.map(function(it, idx) {
			return {
				upc: it.upc,
				slot_type: it.slot_type,
				custom_label: it.custom_label || '',
				sort_order: idx,
				notes: it.notes || ''
			};
		})
	};

	if (state.loadout && state.loadout.id) {
		payload.id = state.loadout.id;
	}

	var btn = document.getElementById('gdlo-save');
	btn.disabled = true;
	btn.textContent = 'Saving...';

	fetch(saveUrl, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify(payload),
		credentials: 'same-origin'
	})
	.then(function(r) { return r.json(); })
	.then(function(data) {
		if (data.ok && data.redirect) {
			window.location.href = data.redirect;
		} else {
			alert(data.error || 'Save failed.');
			btn.disabled = false;
			btn.textContent = 'Save Loadout';
		}
	})
	.catch(function() {
		alert('Network error. Please try again.');
		btn.disabled = false;
		btn.textContent = 'Save Loadout';
	});
});

var delBtn = document.getElementById('gdlo-delete');
if (delBtn) {
	delBtn.addEventListener('click', function() {
		if (!state.loadout || !state.loadout.id) return;
		if (!confirm('Delete this loadout? This cannot be undone.')) return;

		fetch(deleteUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ csrfKey: csrfKey, id: state.loadout.id }),
			credentials: 'same-origin'
		})
		.then(function(r) { return r.json(); })
		.then(function(data) {
			if (data.ok && data.redirect) {
				window.location.href = data.redirect;
			} else {
				alert(data.error || 'Delete failed.');
			}
		})
		.catch(function() { alert('Network error.'); });
	});
}

function escHtml(s) {
	var d = document.createElement('div');
	d.appendChild(document.createTextNode(s));
	return d.innerHTML;
}

if (cfg.items && cfg.items.length) {
	state.items = cfg.items;
}

renderSlots();
renderExtras();
updateSummary();

})();
