(function () {
	'use strict';

	var root = document.getElementById('gdLoadoutBuilder');
	if (!root) return;

	var saveUrl   = root.dataset.saveUrl;
	var deleteUrl = root.dataset.deleteUrl;
	var searchUrl = root.dataset.searchUrl;
	var csrfKey   = root.dataset.csrfKey;
	var loadoutId = parseInt(root.dataset.loadoutId, 10) || 0;
	var maxSlots  = parseInt(root.dataset.maxSlots, 10) || 0;

	var coreSlots = {};
	var extraLib  = [];
	var items     = [];

	try { coreSlots = JSON.parse(root.dataset.coreSlots || '{}'); } catch (e) {}
	try { extraLib  = JSON.parse(root.dataset.extraLib || '[]'); } catch (e) {}
	try { items     = JSON.parse(root.dataset.items || '[]'); } catch (e) {}

	var nameInput   = document.getElementById('gdLoadoutName');
	var descInput   = document.getElementById('gdLoadoutDesc');
	var useCaseSel  = document.getElementById('gdLoadoutUseCase');
	var visSel      = document.getElementById('gdLoadoutVisibility');
	var slotGrid    = document.getElementById('gdSlotGrid');
	var extraSlots  = document.getElementById('gdExtraSlots');
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
	var saveBtn     = document.getElementById('gdSaveBtn');
	var deleteBtn   = document.getElementById('gdDeleteBtn');

	var slots = {};
	var activeSlotKey = null;
	var extraCounter = 0;
	var searchTimer = null;

	if (nameInput)   nameInput.value   = root.dataset.loadoutName || '';
	if (descInput)   descInput.value   = root.dataset.loadoutDescription || '';
	if (useCaseSel)  useCaseSel.value  = root.dataset.loadoutUseCase || '';
	if (visSel)      visSel.value      = root.dataset.loadoutVisibility || 'unlisted';

	function initCoreSlots() {
		if (!slotGrid) return;
		slotGrid.innerHTML = '';

		var slotLabels = {
			base_firearm: 'Base Firearm', optic: 'Optic', weapon_light: 'Weapon Light',
			laser: 'Laser', suppressor: 'Suppressor', foregrip: 'Foregrip',
			sling: 'Sling', holster: 'Holster', ammo: 'Ammo', cleaning: 'Cleaning'
		};

		Object.keys(coreSlots).forEach(function (key) {
			var info = coreSlots[key];
			slots[key] = { type: key, upc: '', title: '', price: null, custom_label: null };

			var existingItem = null;
			for (var i = 0; i < items.length; i++) {
				if (items[i].slot_type === key) { existingItem = items[i]; break; }
			}
			if (existingItem) {
				slots[key].upc   = existingItem.upc || '';
				slots[key].title = existingItem.custom_label || slotLabels[key] || key;
			}

			var card = document.createElement('div');
			card.dataset.slotKey = key;
			card.style.cssText = 'border:2px solid var(--i-border-color,#e0e0e0);border-radius:8px;padding:14px;cursor:pointer;text-align:center;position:relative;transition:border-color 0.2s;min-height:80px;display:flex;flex-direction:column;justify-content:center;align-items:center;';

			card.addEventListener('click', function () { selectSlot(key); });
			slotGrid.appendChild(card);
			renderSlotCard(key, card);
		});
	}

	function renderSlotCard(key, card) {
		if (!card) card = slotGrid.querySelector('[data-slot-key="' + key + '"]');
		if (!card) return;

		var info = coreSlots[key] || {};
		var slot = slots[key];
		var slotLabels = {
			base_firearm: 'Base Firearm', optic: 'Optic', weapon_light: 'Weapon Light',
			laser: 'Laser', suppressor: 'Suppressor', foregrip: 'Foregrip',
			sling: 'Sling', holster: 'Holster', ammo: 'Ammo', cleaning: 'Cleaning'
		};

		if (slot && slot.upc) {
			card.innerHTML = '<div style="font-size:0.75em;color:' + (info.color || '#666') + ';text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">' + (slotLabels[key] || key) + '</div>'
				+ '<div style="font-weight:600;font-size:0.9em;margin-bottom:4px;word-break:break-word">' + escapeHtml(slot.title || slot.upc) + '</div>'
				+ (slot.price ? '<div style="color:#2a8a2a;font-weight:700">$' + parseFloat(slot.price).toFixed(2) + '</div>' : '')
				+ '<button type="button" class="gdSlotRemove" data-slot-key="' + key + '" style="position:absolute;top:4px;right:6px;background:none;border:none;color:#c00;cursor:pointer;font-size:1.1em">&times;</button>';

			var rmBtn = card.querySelector('.gdSlotRemove');
			if (rmBtn) {
				rmBtn.addEventListener('click', function (e) {
					e.stopPropagation();
					slots[key].upc = '';
					slots[key].title = '';
					slots[key].price = null;
					renderSlotCard(key);
					updateSummary();
				});
			}

			card.style.borderColor = info.color || '#2980b9';
		} else {
			card.innerHTML = '<i class="fa-solid fa-' + (info.icon || 'plus') + '" style="font-size:1.4em;color:' + (info.color || '#bbb') + ';margin-bottom:6px"></i>'
				+ '<div style="font-size:0.8em;color:#999">' + (slotLabels[key] || key) + '</div>'
				+ '<div style="font-size:0.75em;color:#bbb;margin-top:4px">+ Add</div>';
			card.style.borderColor = 'var(--i-border-color,#e0e0e0)';
		}

		if (activeSlotKey === key) {
			card.style.borderColor = '#3498db';
			card.style.boxShadow = '0 0 0 2px rgba(52,152,219,0.3)';
		} else {
			card.style.boxShadow = 'none';
		}
	}

	function selectSlot(key) {
		activeSlotKey = key;
		var cards = slotGrid.querySelectorAll('[data-slot-key]');
		for (var i = 0; i < cards.length; i++) {
			var k = cards[i].dataset.slotKey;
			renderSlotCard(k, cards[i]);
		}
		var extraCards = extraSlots.querySelectorAll('[data-slot-key]');
		for (var j = 0; j < extraCards.length; j++) {
			var ek = extraCards[j].dataset.slotKey;
			extraCards[j].style.borderColor = (activeSlotKey === ek) ? '#3498db' : 'var(--i-border-color,#e0e0e0)';
			extraCards[j].style.boxShadow = (activeSlotKey === ek) ? '0 0 0 2px rgba(52,152,219,0.3)' : 'none';
		}
		if (searchInput) searchInput.focus();
	}

	function addExtraSlot(label) {
		extraCounter++;
		var key = 'extra_' + extraCounter;
		slots[key] = { type: 'extra', upc: '', title: '', price: null, custom_label: label || 'Extra' };

		var card = document.createElement('div');
		card.dataset.slotKey = key;
		card.style.cssText = 'border:2px dashed var(--i-border-color,#e0e0e0);border-radius:8px;padding:12px;cursor:pointer;text-align:center;position:relative;min-width:120px;min-height:60px;display:flex;flex-direction:column;justify-content:center;align-items:center;';
		card.addEventListener('click', function () { selectSlot(key); });
		extraSlots.appendChild(card);
		renderExtraCard(key, card);
		updateSummary();
	}

	function renderExtraCard(key, card) {
		if (!card) card = extraSlots.querySelector('[data-slot-key="' + key + '"]');
		if (!card) return;
		var slot = slots[key];

		if (slot && slot.upc) {
			card.innerHTML = '<div style="font-size:0.7em;color:#888;text-transform:uppercase;margin-bottom:2px">' + escapeHtml(slot.custom_label || 'Extra') + '</div>'
				+ '<div style="font-weight:600;font-size:0.85em;word-break:break-word">' + escapeHtml(slot.title || slot.upc) + '</div>'
				+ (slot.price ? '<div style="color:#2a8a2a;font-weight:700;font-size:0.85em">$' + parseFloat(slot.price).toFixed(2) + '</div>' : '')
				+ '<button type="button" class="gdExtraRemove" data-slot-key="' + key + '" style="position:absolute;top:2px;right:4px;background:none;border:none;color:#c00;cursor:pointer">&times;</button>';
		} else {
			card.innerHTML = '<div style="font-size:0.7em;color:#888">' + escapeHtml(slot.custom_label || 'Extra') + '</div>'
				+ '<div style="font-size:0.75em;color:#bbb;margin-top:2px">+ Add</div>'
				+ '<button type="button" class="gdExtraRemove" data-slot-key="' + key + '" style="position:absolute;top:2px;right:4px;background:none;border:none;color:#c00;cursor:pointer;font-size:0.8em">&times;</button>';
		}

		card.querySelector('.gdExtraRemove').addEventListener('click', function (e) {
			e.stopPropagation();
			delete slots[key];
			card.remove();
			if (activeSlotKey === key) activeSlotKey = null;
			updateSummary();
		});
	}

	function initExtraSlots() {
		for (var i = 0; i < items.length; i++) {
			if (items[i].slot_type === 'extra') {
				addExtraSlot(items[i].custom_label || 'Extra');
				var lastKey = 'extra_' + extraCounter;
				if (slots[lastKey]) {
					slots[lastKey].upc   = items[i].upc || '';
					slots[lastKey].title  = items[i].custom_label || 'Extra';
					var card = extraSlots.querySelector('[data-slot-key="' + lastKey + '"]');
					renderExtraCard(lastKey, card);
				}
			}
		}
	}

	function initExtraPicker() {
		if (!extraChips) return;
		extraChips.innerHTML = '';
		extraLib.forEach(function (name) {
			var chip = document.createElement('button');
			chip.type = 'button';
			chip.textContent = name;
			chip.style.cssText = 'padding:4px 10px;border:1px solid #ccc;border-radius:16px;background:#f5f5f5;cursor:pointer;font-size:0.8em;';
			chip.addEventListener('click', function () {
				addExtraSlot(name);
				extraPicker.style.display = 'none';
			});
			extraChips.appendChild(chip);
		});
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
				extraPicker.style.display = 'none';
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
		searchRes.innerHTML = '<div style="padding:8px;color:#888;text-align:center"><i class="fa-solid fa-spinner fa-spin"></i></div>';

		var url = searchUrl + (searchUrl.indexOf('?') > -1 ? '&' : '?') + 'q=' + encodeURIComponent(q);

		fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
			.then(function (resp) { return resp.json(); })
			.then(function (data) {
				searchRes.innerHTML = '';
				if (!data.results || data.results.length === 0) {
					searchRes.innerHTML = '<div style="padding:8px;color:#888;text-align:center;font-size:0.85em">No results</div>';
					return;
				}
				data.results.forEach(function (r) {
					var row = document.createElement('div');
					row.style.cssText = 'padding:8px 0;border-bottom:1px solid #eee;cursor:pointer;display:flex;justify-content:space-between;align-items:center;';
					row.innerHTML = '<div><div style="font-weight:600;font-size:0.9em">' + escapeHtml(r.title || r.upc) + '</div>'
						+ '<div style="font-size:0.75em;color:#888">' + escapeHtml(r.brand || '') + (r.category ? ' &middot; ' + escapeHtml(r.category) : '') + '</div></div>'
						+ (r.best_price ? '<div style="font-weight:700;color:#2a8a2a;white-space:nowrap">$' + parseFloat(r.best_price).toFixed(2) + '</div>' : '<div style="color:#999;font-size:0.8em">N/A</div>');

					row.addEventListener('click', function () {
						assignProduct(r);
					});
					searchRes.appendChild(row);
				});
			})
			.catch(function () {
				searchRes.innerHTML = '<div style="padding:8px;color:#c00;text-align:center;font-size:0.85em">Search error</div>';
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
				alert('Slot limit reached');
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
				breakdown += '<div style="display:flex;justify-content:space-between;padding:3px 0;border-bottom:1px solid #f0f0f0">'
					+ '<span style="word-break:break-word">' + escapeHtml(s.title || s.upc) + '</span>'
					+ '<span style="white-space:nowrap;font-weight:600">' + (p > 0 ? '$' + p.toFixed(2) : '—') + '</span></div>';
			}
		});

		if (totalCostEl)  totalCostEl.textContent  = '$' + total.toFixed(2);
		if (totalItemsEl) totalItemsEl.textContent  = count + ' item' + (count !== 1 ? 's' : '');
		if (breakdownEl)  breakdownEl.innerHTML    = breakdown || '<div style="color:#999;text-align:center;padding:4px">No items yet</div>';
	}

	if (saveBtn) {
		saveBtn.addEventListener('click', function () {
			var name = nameInput ? nameInput.value.trim() : '';
			if (!name) { nameInput.focus(); alert('Please enter a name.'); return; }

			var slotArr = [];
			Object.keys(slots).forEach(function (key) {
				var s = slots[key];
				if (s.upc) {
					slotArr.push({
						upc: s.upc,
						slot_type: s.type,
						custom_label: s.custom_label || null,
						price: s.price || null
					});
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
				if (data.ok && data.url) {
					loadoutId = data.loadout_id;
					root.dataset.loadoutId = loadoutId;
					window.history.replaceState(null, '', data.url);
				}
				alert('Loadout saved!');
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
					window.location.href = root.dataset.saveUrl.replace(/&do=save/, '').replace(/\?do=save/, '');
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

	initCoreSlots();
	initExtraSlots();
	initExtraPicker();
	updateSummary();
})();
