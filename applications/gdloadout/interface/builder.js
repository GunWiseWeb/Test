(function () {
	'use strict';

	var initEl = document.getElementById('gdlo-init');
	if (!initEl) return;

	var init;
	try { init = JSON.parse(initEl.textContent); } catch (e) { return; }

	var saveUrl   = init.saveUrl;
	var deleteUrl = init.deleteUrl;
	var searchUrl = init.searchUrl;
	var csrfKey   = init.csrfKey;
	var isVip     = !!init.isVip;
	var maxSlots  = (init.limits && init.limits.max_slots) ? parseInt(init.limits.max_slots, 10) : 0;
	var loadoutId = (init.loadout && init.loadout.id) ? parseInt(init.loadout.id, 10) : 0;
	var coreSlots = init.coreSlots || {};
	var extraLib  = init.extraLib || [];
	var items     = init.items || [];

	var slotLabels = {
		base_firearm: 'Base Firearm', optic: 'Optic', weapon_light: 'Weapon Light',
		laser: 'Laser', suppressor: 'Suppressor', foregrip: 'Foregrip',
		rail_mount: 'Rail / Mount', trigger: 'Trigger', stock: 'Stock', sling: 'Sling'
	};

	var nameInput   = document.getElementById('gdLoadoutName');
	var descInput   = document.getElementById('gdLoadoutDesc');
	var useCaseSel  = document.getElementById('gdLoadoutUseCase');
	var visSel      = document.getElementById('gdLoadoutVisibility');
	var slotGrid    = document.getElementById('gdSlotGrid');
	var extraSlotsEl = document.getElementById('gdExtraSlots');
	var addExtraBtn = document.getElementById('gdAddExtra');
	var extraPicker = document.getElementById('gdExtraPicker');
	var extraChips  = document.getElementById('gdExtraChips');
	var customInput = document.getElementById('gdCustomSlotName');
	var addCustom   = document.getElementById('gdAddCustomSlot');
	var searchInput = document.getElementById('gdSearchInput');
	var searchRes   = document.getElementById('gdSearchResults');
	var totalCostEl = document.getElementById('gdTotalCost');
	var totalItemsEl = document.getElementById('gdTotalItems');
	var breakdownEl = document.getElementById('gdItemBreakdown');
	var vipNotesEl  = document.getElementById('gdVipNotes');
	var notesBody   = document.getElementById('gdNotesBody');
	var saveBtn     = document.getElementById('gdSaveBtn');
	var deleteBtn   = document.getElementById('gdDeleteBtn');

	var heroSlotEl = document.getElementById('gdHeroSlot');
	var slotCountEl = document.getElementById('gdSlotCount');

	var slots = {};
	var activeSlotKey = null;
	var extraCounter = 0;
	var searchTimer = null;
	var itemNotes = {};

	if (isVip) {
		var privOpt = document.createElement('option');
		privOpt.value = 'private';
		privOpt.textContent = 'Private';
		if (visSel) visSel.appendChild(privOpt);
	}

	if (init.loadout) {
		if (nameInput)  nameInput.value  = init.loadout.name || '';
		if (descInput)  descInput.value  = init.loadout.description || '';
		if (useCaseSel) useCaseSel.value = init.loadout.use_case || '';
		if (visSel)     visSel.value     = init.loadout.visibility || 'unlisted';
	}

	if (loadoutId > 0 && deleteBtn) {
		deleteBtn.style.display = '';
	}

	for (var i = 0; i < items.length; i++) {
		if (items[i].notes) {
			var noteKey = items[i].slot_type === 'extra'
				? 'extra_pending_' + i
				: items[i].slot_type;
			itemNotes[noteKey] = items[i].notes;
		}
	}

	function initCoreSlots() {
		if (!slotGrid) return;
		slotGrid.innerHTML = '';

		Object.keys(coreSlots).forEach(function (key) {
			slots[key] = { type: key, upc: '', title: '', price: null, custom_label: null };

			var existingItem = null;
			for (var i = 0; i < items.length; i++) {
				if (items[i].slot_type === key) { existingItem = items[i]; break; }
			}
			if (existingItem) {
				slots[key].upc   = existingItem.upc || '';
				slots[key].title = existingItem.title || existingItem.custom_label || slotLabels[key] || key;
				slots[key].price = (existingItem.price_snapshot !== undefined && existingItem.price_snapshot !== null) ? existingItem.price_snapshot : null;
				if (existingItem.notes) {
					itemNotes[key] = existingItem.notes;
				}
			}

			if (key === 'base_firearm') {
				renderHeroCard();
				if (heroSlotEl) {
					heroSlotEl.addEventListener('click', function () { selectSlot('base_firearm'); });
				}
				return;
			}

			var card = document.createElement('div');
			card.className = 'gdlo-slot-card';
			card.dataset.slotKey = key;
			card.addEventListener('click', function () { selectSlot(key); });
			slotGrid.appendChild(card);
			renderSlotCard(key, card);
		});
		updateSlotCount();
	}

	function renderHeroCard() {
		if (!heroSlotEl) return;
		var s = slots['base_firearm'];
		heroSlotEl.className = 'gdlo-hero-card';
		if (activeSlotKey === 'base_firearm') heroSlotEl.classList.add('gdlo-hero-card--active');
		if (s && s.upc) {
			heroSlotEl.classList.add('gdlo-hero-card--filled');
			heroSlotEl.innerHTML = '<div class="gdlo-hero-label">' + escapeHtml(slotLabels['base_firearm']) + '</div>'
				+ '<div class="gdlo-hero-title">' + escapeHtml(s.title || s.upc) + '</div>'
				+ (s.price ? '<div class="gdlo-hero-price">$' + parseFloat(s.price).toFixed(2) + '</div>' : '');
		} else {
			heroSlotEl.innerHTML = '<div class="gdlo-hero-label">' + escapeHtml(slotLabels['base_firearm']) + '</div>'
				+ '<div class="gdlo-hero-empty">Select your base firearm</div>';
		}
	}

	function updateSlotCount() {
		if (!slotCountEl) return;
		var filled = 0, empty = 0;
		Object.keys(coreSlots).forEach(function (k) {
			if (slots[k] && slots[k].upc) filled++; else empty++;
		});
		slotCountEl.textContent = filled + ' filled · ' + empty + ' empty';
	}

	function renderSlotCard(key, card) {
		if (key === 'base_firearm') { renderHeroCard(); updateSlotCount(); return; }
		if (!card) card = slotGrid.querySelector('[data-slot-key="' + key + '"]');
		if (!card) return;

		var info = coreSlots[key] || {};
		var slot = slots[key];

		card.className = 'gdlo-slot-card';

		if (slot && slot.upc) {
			card.classList.add('gdlo-slot-card--filled');
			card.style.setProperty('--slot-color', info.color || '#2980b9');

			card.innerHTML = '<div class="gdlo-slot-label" style="color:' + escapeAttr(info.color || '#666') + '">' + escapeHtml(slotLabels[key] || key) + '</div>'
				+ '<div class="gdlo-slot-title">' + escapeHtml(slot.title || slot.upc) + '</div>'
				+ (slot.price ? '<div class="gdlo-slot-price">$' + parseFloat(slot.price).toFixed(2) + '</div>' : '')
				+ '<button type="button" class="gdlo-slot-remove" data-slot-key="' + key + '">&times;</button>';

			var rmBtn = card.querySelector('.gdlo-slot-remove');
			if (rmBtn) {
				rmBtn.addEventListener('click', function (e) {
					e.stopPropagation();
					slots[key].upc = '';
					slots[key].title = '';
					slots[key].price = null;
					delete itemNotes[key];
					renderSlotCard(key);
					updateSummary();
					updateSlotCount();
					updateNotesPanel();
				});
			}
		} else {
			card.innerHTML = '<i class="fa-solid fa-' + escapeAttr(info.icon || 'plus') + ' gdlo-slot-empty-icon" style="color:' + escapeAttr(info.color || '#bbb') + '"></i>'
				+ '<div class="gdlo-slot-empty-label">' + escapeHtml(slotLabels[key] || key) + '</div>'
				+ '<div class="gdlo-slot-empty-add">+ Add</div>';
			card.style.removeProperty('--slot-color');
		}

		if (activeSlotKey === key) {
			card.classList.add('gdlo-slot-card--active');
		}
	}

	function selectSlot(key) {
		activeSlotKey = key;

		renderHeroCard();

		var cards = slotGrid.querySelectorAll('[data-slot-key]');
		for (var i = 0; i < cards.length; i++) {
			renderSlotCard(cards[i].dataset.slotKey, cards[i]);
		}

		var extraCards = extraSlotsEl.querySelectorAll('[data-slot-key]');
		for (var j = 0; j < extraCards.length; j++) {
			var ek = extraCards[j].dataset.slotKey;
			if (activeSlotKey === ek) {
				extraCards[j].classList.add('gdlo-slot-card--active');
			} else {
				extraCards[j].classList.remove('gdlo-slot-card--active');
			}
		}

		if (searchInput) searchInput.focus();
	}

	function addExtraSlot(label) {
		extraCounter++;
		var key = 'extra_' + extraCounter;
		slots[key] = { type: 'extra', upc: '', title: '', price: null, custom_label: label || 'Extra' };

		var card = document.createElement('div');
		card.className = 'gdlo-extra-card';
		card.dataset.slotKey = key;
		card.addEventListener('click', function () { selectSlot(key); });
		extraSlotsEl.appendChild(card);
		renderExtraCard(key, card);
		updateSummary();
	}

	function renderExtraCard(key, card) {
		if (!card) card = extraSlotsEl.querySelector('[data-slot-key="' + key + '"]');
		if (!card) return;
		var slot = slots[key];

		if (slot && slot.upc) {
			card.innerHTML = '<div class="gdlo-slot-label">' + escapeHtml(slot.custom_label || 'Extra') + '</div>'
				+ '<div class="gdlo-slot-title">' + escapeHtml(slot.title || slot.upc) + '</div>'
				+ (slot.price ? '<div class="gdlo-slot-price">$' + parseFloat(slot.price).toFixed(2) + '</div>' : '')
				+ '<button type="button" class="gdlo-slot-remove" data-slot-key="' + key + '">&times;</button>';
		} else {
			card.innerHTML = '<div class="gdlo-slot-empty-label">' + escapeHtml(slot.custom_label || 'Extra') + '</div>'
				+ '<div class="gdlo-slot-empty-add">+ Add</div>'
				+ '<button type="button" class="gdlo-slot-remove" data-slot-key="' + key + '">&times;</button>';
		}

		card.querySelector('.gdlo-slot-remove').addEventListener('click', function (e) {
			e.stopPropagation();
			delete slots[key];
			delete itemNotes[key];
			card.remove();
			if (activeSlotKey === key) activeSlotKey = null;
			updateSummary();
			updateNotesPanel();
		});
	}

	function initExtraSlots() {
		var extraIdx = 0;
		for (var i = 0; i < items.length; i++) {
			if (items[i].slot_type === 'extra') {
				addExtraSlot(items[i].custom_label || 'Extra');
				var lastKey = 'extra_' + extraCounter;
				if (slots[lastKey]) {
					slots[lastKey].upc   = items[i].upc || '';
					slots[lastKey].title = items[i].title || items[i].custom_label || 'Extra';
					slots[lastKey].price = (items[i].price_snapshot !== undefined && items[i].price_snapshot !== null) ? items[i].price_snapshot : null;
					if (items[i].notes) {
						itemNotes[lastKey] = items[i].notes;
					}
					var card = extraSlotsEl.querySelector('[data-slot-key="' + lastKey + '"]');
					renderExtraCard(lastKey, card);
				}
				extraIdx++;
			}
		}
	}

	function initExtraPicker() {
		if (!extraChips) return;
		extraChips.innerHTML = '';
		var libNames = Array.isArray(extraLib) ? extraLib : Object.keys(extraLib).map(function(k){ return extraLib[k]; });
		libNames.forEach(function (name) {
			var chip = document.createElement('button');
			chip.type = 'button';
			chip.className = 'gdlo-chip';
			chip.textContent = name;
			chip.addEventListener('click', function () {
				addExtraSlot(name);
			});
			extraChips.appendChild(chip);
		});
		var customChip = document.createElement('button');
		customChip.type = 'button';
		customChip.className = 'gdlo-chip gdlo-chip--custom';
		customChip.textContent = 'Custom…';
		customChip.addEventListener('click', function () {
			var wrap = document.getElementById('gdCustomWrap');
			if (wrap) wrap.style.display = wrap.style.display === 'none' ? 'flex' : 'none';
		});
		extraChips.appendChild(customChip);
	}

	if (addExtraBtn) {
		addExtraBtn.addEventListener('click', function () {
			extraPicker.style.display = extraPicker.style.display === 'none' ? 'block' : 'none';
		});
	}

	if (addCustom) {
		addCustom.addEventListener('click', function () {
			var val = customInput.value.trim();
			if (val) {
				addExtraSlot(val);
				customInput.value = '';
				var wrap = document.getElementById('gdCustomWrap');
				if (wrap) wrap.style.display = 'none';
			}
		});
	}

	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && extraPicker) extraPicker.style.display = 'none';
	});

	if (searchInput) {
		searchInput.addEventListener('input', function () {
			clearTimeout(searchTimer);
			var q = searchInput.value.trim();
			if (q.length < 2) { searchRes.innerHTML = ''; return; }
			searchTimer = setTimeout(function () { doSearch(q); }, 350);
		});
	}

	function doSearch(q) {
		searchRes.innerHTML = '<div class="gdlo-search-row" style="justify-content:center;border:none;cursor:default"><i class="fa-solid fa-spinner fa-spin"></i></div>';

		var url = searchUrl + (searchUrl.indexOf('?') > -1 ? '&' : '?') + 'q=' + encodeURIComponent(q);

		fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
			.then(function (resp) { return resp.json(); })
			.then(function (data) {
				searchRes.innerHTML = '';
				if (!data.results || data.results.length === 0) {
					searchRes.innerHTML = '<div class="gdlo-search-row" style="justify-content:center;border:none;cursor:default"><span class="gdlo-search-meta">No results</span></div>';
					return;
				}
				data.results.forEach(function (r) {
					var row = document.createElement('div');
					row.className = 'gdlo-search-row';
					row.innerHTML = '<div><div class="gdlo-search-title">' + escapeHtml(r.title || r.upc) + '</div>'
						+ '<div class="gdlo-search-meta">' + escapeHtml(r.brand || '') + (r.caliber ? ' &middot; ' + escapeHtml(r.caliber) : '') + (r.category ? ' &middot; ' + escapeHtml(r.category) : '') + '</div></div>'
						+ (r.best_price ? '<div class="gdlo-search-price">$' + parseFloat(r.best_price).toFixed(2) + '</div>' : '<div class="gdlo-search-meta">N/A</div>');

					row.addEventListener('click', function () {
						assignProduct(r);
					});
					searchRes.appendChild(row);
				});
			})
			.catch(function () {
				searchRes.innerHTML = '<div class="gdlo-search-row" style="justify-content:center;border:none;cursor:default"><span style="color:#c00">Search error</span></div>';
			});
	}

	function assignProduct(product) {
		if (!activeSlotKey) {
			var emptyKey = findFirstEmptySlot();
			if (emptyKey) activeSlotKey = emptyKey;
			else return;
		}

		if (maxSlots > 0) {
			var filled = 0;
			Object.keys(slots).forEach(function (k) { if (slots[k].upc) filled++; });
			if (!slots[activeSlotKey].upc && filled >= maxSlots) {
				alert('Slot limit reached (' + maxSlots + ')');
				return;
			}
		}

		slots[activeSlotKey].upc   = product.upc || '';
		slots[activeSlotKey].title = product.title || product.upc || '';
		slots[activeSlotKey].price = product.best_price || null;

		if (coreSlots[activeSlotKey]) {
			renderSlotCard(activeSlotKey);
		} else {
			renderExtraCard(activeSlotKey);
		}

		updateSummary();
		updateSlotCount();
		updateNotesPanel();

		var nextEmpty = findNextEmptySlot(activeSlotKey);
		if (nextEmpty) selectSlot(nextEmpty);
	}

	function findFirstEmptySlot() {
		var keys = Object.keys(slots);
		for (var i = 0; i < keys.length; i++) {
			if (!slots[keys[i]].upc) return keys[i];
		}
		return null;
	}

	function findNextEmptySlot(afterKey) {
		var keys = Object.keys(slots);
		var idx = keys.indexOf(afterKey);
		for (var i = idx + 1; i < keys.length; i++) {
			if (!slots[keys[i]].upc) return keys[i];
		}
		for (var j = 0; j < idx; j++) {
			if (!slots[keys[j]].upc) return keys[j];
		}
		return null;
	}

	function updateSummary() {
		var total = 0;
		var count = 0;
		var breakdown = '';

		Object.keys(slots).forEach(function (key) {
			var s = slots[key];
			if (s.upc) {
				count++;
				var p = s.price ? parseFloat(s.price) : 0;
				total += p;
				breakdown += '<div class="gdlo-breakdown-row">'
					+ '<span class="gdlo-breakdown-name">' + escapeHtml(s.title || s.upc) + '</span>'
					+ '<span class="gdlo-breakdown-price">' + (p > 0 ? '$' + p.toFixed(2) : '—') + '</span></div>';
			}
		});

		if (totalCostEl)  totalCostEl.textContent  = '$' + total.toFixed(2);
		if (totalItemsEl) totalItemsEl.textContent  = count + ' item' + (count !== 1 ? 's' : '');
		if (breakdownEl)  breakdownEl.innerHTML    = breakdown || '<div style="color:#999;text-align:center;padding:4px">No items yet</div>';
	}

	function updateNotesPanel() {
		if (!isVip || !vipNotesEl || !notesBody) return;

		var filledKeys = [];
		Object.keys(slots).forEach(function (key) {
			if (slots[key].upc) filledKeys.push(key);
		});

		if (filledKeys.length === 0) {
			vipNotesEl.style.display = 'none';
			return;
		}

		vipNotesEl.style.display = '';
		notesBody.innerHTML = '';

		filledKeys.forEach(function (key) {
			var s = slots[key];
			var label = s.custom_label || slotLabels[key] || s.title || key;

			var row = document.createElement('div');
			row.style.marginBottom = '8px';

			var lbl = document.createElement('div');
			lbl.className = 'gdlo-slot-label';
			lbl.textContent = label;
			row.appendChild(lbl);

			var inp = document.createElement('input');
			inp.type = 'text';
			inp.className = 'gdlo-notes-input';
			inp.placeholder = 'Note for ' + label + '...';
			inp.maxLength = 300;
			inp.value = itemNotes[key] || '';
			inp.dataset.slotKey = key;
			inp.addEventListener('input', function () {
				itemNotes[key] = inp.value;
			});
			row.appendChild(inp);

			notesBody.appendChild(row);
		});
	}

	if (saveBtn) {
		saveBtn.addEventListener('click', function () {
			var name = nameInput ? nameInput.value.trim() : '';
			if (!name) { if (nameInput) nameInput.focus(); alert('Please enter a name.'); return; }

			var slotArr = [];
			Object.keys(slots).forEach(function (key) {
				var s = slots[key];
				if (s.upc) {
					var entry = {
						upc: s.upc,
						slot_type: s.type,
						custom_label: s.custom_label || null,
						price: s.price || null
					};
					if (isVip && itemNotes[key]) {
						entry.notes = itemNotes[key];
					}
					slotArr.push(entry);
				}
			});

			var body = new FormData();
			body.append('csrfKey', csrfKey);
			body.append('loadout_id', loadoutId);
			body.append('loadout_name', name);
			body.append('loadout_description', descInput ? descInput.value : '');
			body.append('loadout_use_case', useCaseSel ? useCaseSel.value : '');
			body.append('loadout_visibility', visSel ? visSel.value : 'unlisted');
			body.append('loadout_slots', JSON.stringify(slotArr));

			saveBtn.disabled = true;
			saveBtn.textContent = 'Saving...';

			fetch(saveUrl, {
				method: 'POST',
				body: body,
				headers: { 'X-Requested-With': 'XMLHttpRequest' }
			})
			.then(function (resp) { return resp.json(); })
			.then(function (data) {
				saveBtn.disabled = false;
				saveBtn.textContent = 'Save Loadout';
				if (data.error) { alert(data.error); return; }
				if (data.ok && data.redirect) {
					window.location.href = data.redirect;
					return;
				}
				if (data.ok && data.loadout_id) {
					loadoutId = data.loadout_id;
				}
			})
			.catch(function () {
				saveBtn.disabled = false;
				saveBtn.textContent = 'Save Loadout';
				alert('Save failed — please try again.');
			});
		});
	}

	if (deleteBtn) {
		deleteBtn.addEventListener('click', function () {
			if (!confirm('Are you sure you want to delete this loadout?')) return;

			var body = new FormData();
			body.append('csrfKey', csrfKey);
			body.append('loadout_id', loadoutId);

			fetch(deleteUrl, {
				method: 'POST',
				body: body,
				headers: { 'X-Requested-With': 'XMLHttpRequest' }
			})
			.then(function (resp) { return resp.json(); })
			.then(function (data) {
				if (data.ok) {
					window.location.href = saveUrl.replace(/[&?]do=save.*/, '').replace(/\?$/, '');
				}
			})
			.catch(function () { alert('Delete failed.'); });
		});
	}

	function escapeHtml(str) {
		var d = document.createElement('div');
		d.appendChild(document.createTextNode(str || ''));
		return d.innerHTML;
	}

	function escapeAttr(str) {
		return (str || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
	}

	initCoreSlots();
	initExtraSlots();
	initExtraPicker();
	updateSummary();
	updateNotesPanel();
})();
