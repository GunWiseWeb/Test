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

	var completeFirearmCore = init.completeFirearmCore || {};
	var componentCore       = init.componentCore || {};
	var accessorySlots      = init.accessorySlots || {};
	var slotCategories      = init.slotCategories || {};
	var platformOptions     = init.platforms || {};
	var items               = init.items || [];

	var currentStep = 1;
	var buildMode   = 'complete_firearm';
	var platform    = '';
	var slots       = {};
	var activeSlotKey = null;
	var itemNotes   = {};
	var extraCounter = 0;

	var nameInput  = document.getElementById('gdLoadoutName');
	var descInput  = document.getElementById('gdLoadoutDesc');
	var useCaseSel = document.getElementById('gdLoadoutUseCase');
	var visSel     = document.getElementById('gdLoadoutVisibility');
	var saveBtn    = document.getElementById('gdSaveBtn');
	var deleteBtn  = document.getElementById('gdDeleteBtn');
	var prevBtn    = document.getElementById('gdPrevBtn');
	var nextBtn    = document.getElementById('gdNextBtn');
	var stepperEl  = document.getElementById('gdStepper');

	if (isVip && visSel) {
		var privOpt = document.createElement('option');
		privOpt.value = 'private';
		privOpt.textContent = 'Private';
		visSel.appendChild(privOpt);
	}

	if (init.loadout) {
		if (nameInput)  nameInput.value  = init.loadout.name || '';
		if (descInput)  descInput.value  = init.loadout.description || '';
		if (useCaseSel) useCaseSel.value = init.loadout.use_case || '';
		if (visSel)     visSel.value     = init.loadout.visibility || 'unlisted';
		if (init.loadout.build_mode) buildMode = init.loadout.build_mode;
		if (init.loadout.platform)   platform  = init.loadout.platform;
	}

	if (loadoutId > 0 && deleteBtn) deleteBtn.style.display = '';

	for (var i = 0; i < items.length; i++) {
		if (items[i].notes) {
			var noteKey = items[i].slot_type === 'extra'
				? 'extra_pending_' + i
				: items[i].slot_type;
			itemNotes[noteKey] = items[i].notes;
		}
	}

	/* ===== Wizard Navigation ===== */
	function goToStep(step) {
		if (step < 1 || step > 4) return;
		currentStep = step;
		for (var s = 1; s <= 4; s++) {
			var el = document.getElementById('gdStep' + s);
			if (el) el.style.display = s === step ? '' : 'none';
		}
		var btns = stepperEl ? stepperEl.querySelectorAll('.gdlo-step') : [];
		for (var j = 0; j < btns.length; j++) {
			var sn = parseInt(btns[j].dataset.step, 10);
			btns[j].classList.remove('gdlo-step--active', 'gdlo-step--done');
			if (sn === step) btns[j].classList.add('gdlo-step--active');
			else if (sn < step) btns[j].classList.add('gdlo-step--done');
		}
		if (prevBtn) prevBtn.style.display = step > 1 ? '' : 'none';
		if (nextBtn) nextBtn.style.display = step < 4 ? '' : 'none';
		closePicker();
		if (step === 2) renderCoreGrid();
		if (step === 3) { renderAccGrid(); renderExtraGrid(); }
		if (step === 4) { renderReview(); renderNotesPanel(); }
		updateAllSummaries();
		window.scrollTo({ top: 0, behavior: 'smooth' });
	}

	if (stepperEl) {
		stepperEl.addEventListener('click', function (e) {
			var btn = e.target.closest('.gdlo-step');
			if (!btn) return;
			var s = parseInt(btn.dataset.step, 10);
			if (s) goToStep(s);
		});
	}
	if (prevBtn) prevBtn.addEventListener('click', function () { goToStep(currentStep - 1); });
	if (nextBtn) nextBtn.addEventListener('click', function () { goToStep(currentStep + 1); });

	/* ===== Build Mode ===== */
	function setMode(mode) {
		buildMode = mode;
		var cards = document.querySelectorAll('#gdModeGrid .gdlo-mode-card');
		for (var c = 0; c < cards.length; c++) {
			cards[c].classList.toggle('gdlo-mode-card--active', cards[c].dataset.mode === mode);
		}
		var platRow = document.getElementById('gdPlatformRow');
		if (platRow) platRow.style.display = mode === 'component_build' ? '' : 'none';
	}

	var modeGrid = document.getElementById('gdModeGrid');
	if (modeGrid) {
		modeGrid.addEventListener('click', function (e) {
			var card = e.target.closest('.gdlo-mode-card');
			if (!card) return;
			setMode(card.dataset.mode);
		});
	}

	/* ===== Platform ===== */
	function initPlatformChips() {
		var container = document.getElementById('gdPlatformChips');
		if (!container) return;
		container.innerHTML = '';
		var keys = Object.keys(platformOptions);
		keys.forEach(function (key) {
			var chip = document.createElement('button');
			chip.type = 'button';
			chip.className = 'gdlo-platform-chip' + (platform === key ? ' gdlo-platform-chip--active' : '');
			chip.textContent = platformOptions[key];
			chip.dataset.platform = key;
			chip.addEventListener('click', function () {
				platform = key;
				var all = container.querySelectorAll('.gdlo-platform-chip');
				for (var a = 0; a < all.length; a++) {
					all[a].classList.toggle('gdlo-platform-chip--active', all[a].dataset.platform === key);
				}
			});
			container.appendChild(chip);
		});
	}

	/* ===== Slot Helpers ===== */
	function getCoreSlotDefs() {
		return buildMode === 'complete_firearm' ? completeFirearmCore : componentCore;
	}

	function getAllSlotDefs() {
		var defs = {};
		var core = getCoreSlotDefs();
		Object.keys(core).forEach(function (k) { defs[k] = core[k]; });
		Object.keys(accessorySlots).forEach(function (k) { defs[k] = accessorySlots[k]; });
		return defs;
	}

	function ensureSlot(key) {
		if (!slots[key]) {
			slots[key] = { type: key, upc: '', title: '', price: null, custom_label: null, image: '' };
		}
	}

	function getSlotLabel(key) {
		var allDefs = getAllSlotDefs();
		if (allDefs[key]) return allDefs[key].label;
		if (slots[key] && slots[key].custom_label) return slots[key].custom_label;
		return key;
	}

	/* ===== Card Rendering ===== */
	function createSlotCard(key, slotDef) {
		ensureSlot(key);
		var card = document.createElement('div');
		card.className = 'gdlo-card';
		card.dataset.slotKey = key;
		var slot = slots[key];

		if (slot && slot.upc) {
			card.classList.add('gdlo-card--filled');
			var imgHtml = slot.image
				? '<img class="gdlo-card-img" src="' + escapeAttr(slot.image) + '" alt="" loading="lazy" onerror="this.style.display=\'none\'">'
				: '<div class="gdlo-card-img-ph"><i class="' + escapeAttr(slotDef.icon || 'fa-solid fa-cube') + '"></i></div>';
			card.innerHTML = imgHtml
				+ '<div class="gdlo-card-body">'
				+ '<div class="gdlo-card-label">' + escapeHtml(slotDef.label) + '</div>'
				+ '<div class="gdlo-card-title">' + escapeHtml(slot.title || slot.upc) + '</div>'
				+ (slot.price ? '<div class="gdlo-card-price">$' + parseFloat(slot.price).toFixed(2) + '</div>' : '')
				+ '</div>'
				+ '<button type="button" class="gdlo-card-remove" data-slot-key="' + escapeAttr(key) + '">&times;</button>';
		} else {
			card.innerHTML = '<div class="gdlo-card-empty">'
				+ '<i class="' + escapeAttr(slotDef.icon || 'fa-solid fa-plus') + ' gdlo-card-empty-icon"></i>'
				+ '<div class="gdlo-card-label">' + escapeHtml(slotDef.label) + '</div>'
				+ '<div class="gdlo-card-add">+ Add</div>'
				+ (slotDef.required ? '<div class="gdlo-card-req">Required</div>' : '')
				+ '</div>';
		}

		if (activeSlotKey === key) card.classList.add('gdlo-card--active');

		card.addEventListener('click', function (e) {
			if (e.target.closest('.gdlo-card-remove')) return;
			activeSlotKey = key;
			highlightCards();
			openPicker(key, slotDef);
		});

		var rmBtn = card.querySelector('.gdlo-card-remove');
		if (rmBtn) {
			rmBtn.addEventListener('click', function (e) {
				e.stopPropagation();
				slots[key] = { type: key, upc: '', title: '', price: null, custom_label: null, image: '' };
				delete itemNotes[key];
				if (currentStep === 2) renderCoreGrid();
				if (currentStep === 3) { renderAccGrid(); renderExtraGrid(); }
				updateAllSummaries();
			});
		}

		return card;
	}

	function createExtraCard(key) {
		var slot = slots[key];
		if (!slot) return null;
		var card = document.createElement('div');
		card.className = 'gdlo-card';
		card.dataset.slotKey = key;
		var label = slot.custom_label || 'Extra';

		if (slot.upc) {
			card.classList.add('gdlo-card--filled');
			var imgHtml = slot.image
				? '<img class="gdlo-card-img" src="' + escapeAttr(slot.image) + '" alt="" loading="lazy" onerror="this.style.display=\'none\'">'
				: '<div class="gdlo-card-img-ph"><i class="fa-solid fa-cube"></i></div>';
			card.innerHTML = imgHtml
				+ '<div class="gdlo-card-body">'
				+ '<div class="gdlo-card-label">' + escapeHtml(label) + '</div>'
				+ '<div class="gdlo-card-title">' + escapeHtml(slot.title || slot.upc) + '</div>'
				+ (slot.price ? '<div class="gdlo-card-price">$' + parseFloat(slot.price).toFixed(2) + '</div>' : '')
				+ '</div>'
				+ '<button type="button" class="gdlo-card-remove" data-slot-key="' + escapeAttr(key) + '">&times;</button>';
		} else {
			card.innerHTML = '<div class="gdlo-card-empty">'
				+ '<i class="fa-solid fa-plus gdlo-card-empty-icon"></i>'
				+ '<div class="gdlo-card-label">' + escapeHtml(label) + '</div>'
				+ '<div class="gdlo-card-add">+ Add</div>'
				+ '</div>'
				+ '<button type="button" class="gdlo-card-remove" data-slot-key="' + escapeAttr(key) + '">&times;</button>';
		}

		if (activeSlotKey === key) card.classList.add('gdlo-card--active');

		card.addEventListener('click', function (e) {
			if (e.target.closest('.gdlo-card-remove')) return;
			activeSlotKey = key;
			highlightCards();
			openPicker(key, { label: label, icon: 'fa-solid fa-cube', required: false });
		});

		var rmBtn = card.querySelector('.gdlo-card-remove');
		if (rmBtn) {
			rmBtn.addEventListener('click', function (e) {
				e.stopPropagation();
				delete slots[key];
				delete itemNotes[key];
				if (activeSlotKey === key) { activeSlotKey = null; closePicker(); }
				renderExtraGrid();
				updateAllSummaries();
			});
		}

		return card;
	}

	function highlightCards() {
		var all = document.querySelectorAll('.gdlo-card');
		for (var c = 0; c < all.length; c++) {
			all[c].classList.toggle('gdlo-card--active', all[c].dataset.slotKey === activeSlotKey);
		}
	}

	/* ===== Grid Rendering ===== */
	function renderCoreGrid() {
		var grid = document.getElementById('gdCoreGrid');
		if (!grid) return;
		grid.innerHTML = '';
		var defs = getCoreSlotDefs();
		Object.keys(defs).forEach(function (key) {
			ensureSlot(key);
			grid.appendChild(createSlotCard(key, defs[key]));
		});
		updateProgress(2);
	}

	function renderAccGrid() {
		var grid = document.getElementById('gdAccGrid');
		if (!grid) return;
		grid.innerHTML = '';
		Object.keys(accessorySlots).forEach(function (key) {
			ensureSlot(key);
			grid.appendChild(createSlotCard(key, accessorySlots[key]));
		});
		updateProgress(3);
	}

	function renderExtraGrid() {
		var grid = document.getElementById('gdExtraGrid');
		if (!grid) return;
		grid.innerHTML = '';
		Object.keys(slots).forEach(function (key) {
			if (!key.match(/^extra_/)) return;
			var card = createExtraCard(key);
			if (card) grid.appendChild(card);
		});
	}

	/* ===== Extra Slots ===== */
	var addExtraBtn = document.getElementById('gdAddExtra');
	var extraNameInput = document.getElementById('gdExtraName');

	if (addExtraBtn) {
		addExtraBtn.addEventListener('click', function () {
			var label = extraNameInput ? extraNameInput.value.trim() : '';
			if (!label) label = 'Extra';
			extraCounter++;
			var key = 'extra_' + extraCounter;
			slots[key] = { type: 'extra', upc: '', title: '', price: null, custom_label: label, image: '' };
			if (extraNameInput) extraNameInput.value = '';
			renderExtraGrid();
			updateAllSummaries();
		});
	}

	/* ===== Progress ===== */
	function updateProgress(step) {
		var fillEl, textEl;
		if (step === 2) {
			fillEl = document.getElementById('gdProgressFill2');
			textEl = document.getElementById('gdProgressText2');
		} else if (step === 3) {
			fillEl = document.getElementById('gdProgressFill3');
			textEl = document.getElementById('gdProgressText3');
		} else return;

		var defs, filled = 0, total = 0;
		if (step === 2) {
			defs = getCoreSlotDefs();
			Object.keys(defs).forEach(function (k) {
				total++;
				if (slots[k] && slots[k].upc) filled++;
			});
		} else {
			Object.keys(accessorySlots).forEach(function (k) {
				total++;
				if (slots[k] && slots[k].upc) filled++;
			});
			Object.keys(slots).forEach(function (k) {
				if (k.match(/^extra_/)) {
					total++;
					if (slots[k].upc) filled++;
				}
			});
		}

		var pct = total > 0 ? Math.round((filled / total) * 100) : 0;
		if (fillEl) fillEl.style.width = pct + '%';
		if (textEl) textEl.textContent = filled + ' / ' + total + ' slots filled';
	}

	/* ===== Summary ===== */
	function getFilledSlots() {
		var result = [];
		Object.keys(slots).forEach(function (key) {
			if (slots[key] && slots[key].upc) {
				result.push({ key: key, slot: slots[key] });
			}
		});
		return result;
	}

	function updateAllSummaries() {
		var filled = getFilledSlots();
		var totalCost = 0, count = filled.length;
		filled.forEach(function (f) {
			if (f.slot.price) totalCost += parseFloat(f.slot.price);
		});

		var costStr = '$' + totalCost.toFixed(2);
		var itemStr = count + ' item' + (count !== 1 ? 's' : '');

		var costEls = ['gdSideCost2', 'gdSideCost3', 'gdTotalCost'];
		var itemEls = ['gdSideItems2', 'gdSideItems3', 'gdTotalItems'];
		costEls.forEach(function (id) { var el = document.getElementById(id); if (el) el.textContent = costStr; });
		itemEls.forEach(function (id) { var el = document.getElementById(id); if (el) el.textContent = itemStr; });

		var breakdownEl = document.getElementById('gdItemBreakdown');
		if (breakdownEl) {
			if (count === 0) {
				breakdownEl.innerHTML = '<div style="color:#999;text-align:center;padding:4px">No items yet</div>';
			} else {
				var html = '';
				filled.forEach(function (f) {
					var p = f.slot.price ? parseFloat(f.slot.price) : 0;
					html += '<div class="gdlo-breakdown-row">'
						+ '<span class="gdlo-breakdown-name">' + escapeHtml(f.slot.title || f.slot.upc) + '</span>'
						+ '<span class="gdlo-breakdown-price">' + (p > 0 ? '$' + p.toFixed(2) : '—') + '</span></div>';
				});
				breakdownEl.innerHTML = html;
			}
		}

		updateProgress(2);
		updateProgress(3);
	}

	/* ===== Review (Step 4) ===== */
	function renderReview() {
		var list = document.getElementById('gdReviewList');
		if (!list) return;
		list.innerHTML = '';

		var filled = getFilledSlots();
		if (filled.length === 0) {
			list.innerHTML = '<div class="gdlo-review-empty">No items added yet. Go back to add products to your build.</div>';
			return;
		}

		filled.forEach(function (f) {
			var slot = f.slot;
			var label = getSlotLabel(f.key);
			var imgHtml = slot.image
				? '<img class="gdlo-review-item-img" src="' + escapeAttr(slot.image) + '" alt="" loading="lazy" onerror="this.style.display=\'none\'">'
				: '<div class="gdlo-review-item-ph"><i class="fa-solid fa-cube"></i></div>';
			var priceHtml = slot.price && parseFloat(slot.price) > 0
				? '<div class="gdlo-review-item-price">$' + parseFloat(slot.price).toFixed(2) + '</div>'
				: '<div class="gdlo-review-item-noprice">No price</div>';

			var item = document.createElement('div');
			item.className = 'gdlo-review-item';
			item.innerHTML = imgHtml
				+ '<div class="gdlo-review-item-info">'
				+ '<div class="gdlo-review-item-label">' + escapeHtml(label) + '</div>'
				+ '<div class="gdlo-review-item-title">' + escapeHtml(slot.title || slot.upc) + '</div>'
				+ '</div>' + priceHtml;
			list.appendChild(item);
		});
	}

	function renderNotesPanel() {
		var panel = document.getElementById('gdVipNotes');
		var body  = document.getElementById('gdNotesBody');
		if (!panel || !body) return;
		if (!isVip) { panel.style.display = 'none'; return; }

		var filled = getFilledSlots();
		if (filled.length === 0) { panel.style.display = 'none'; return; }

		panel.style.display = '';
		body.innerHTML = '';
		filled.forEach(function (f) {
			var label = getSlotLabel(f.key);
			var row = document.createElement('div');
			row.style.marginBottom = '8px';
			var lbl = document.createElement('div');
			lbl.className = 'gdlo-card-label';
			lbl.textContent = label;
			row.appendChild(lbl);
			var inp = document.createElement('input');
			inp.type = 'text';
			inp.className = 'gdlo-notes-input';
			inp.placeholder = 'Note for ' + label + '...';
			inp.maxLength = 300;
			inp.value = itemNotes[f.key] || '';
			inp.addEventListener('input', function () { itemNotes[f.key] = inp.value; });
			row.appendChild(inp);
			body.appendChild(row);
		});
	}

	/* ===== Inline Picker ===== */
	var pickerTimer     = null;
	var pickerCategory  = [];
	var pickerCatField  = 'category';
	var pickerSort      = 'relevance';
	var pickerPage      = 1;
	var pickerLoaded    = 0;
	var pickerFilters   = {};

	var FACET_MAP = {
		brands:            { param: 'brand',              label: 'Brand' },
		calibers:          { param: 'caliber',            label: 'Caliber' },
		actions:           { param: 'action',             label: 'Action' },
		capacities:        { param: 'capacity',           label: 'Capacity' },
		casings:           { param: 'casing',             label: 'Casing' },
		bullet_types:      { param: 'bullet_type',        label: 'Bullet Type' },
		holster_types:     { param: 'holster_type',        label: 'Holster Type' },
		holster_colors:    { param: 'holster_color',       label: 'Holster Color' },
		holster_materials: { param: 'holster_material',    label: 'Holster Material' },
		holster_hands:     { param: 'holster_hand',        label: 'Hand' },
		apparel_patterns:  { param: 'apparel_pattern',     label: 'Pattern' },
		apparel_sizes:     { param: 'apparel_size',        label: 'Size' },
		apparel_materials: { param: 'apparel_material',    label: 'Material' },
		blade_shapes:      { param: 'blade_shape',         label: 'Blade Shape' },
		blade_lengths:     { param: 'blade_length',        label: 'Blade Length' },
		blade_materials:   { param: 'blade_material',      label: 'Blade Material' },
		blade_edges:       { param: 'blade_edge',          label: 'Edge Type' },
		knife_handles:     { param: 'knife_handle',        label: 'Handle' },
		hunt_call_types:   { param: 'hunt_call_type',      label: 'Call Type' },
		hunt_games:        { param: 'hunt_game',           label: 'Game' },
		optics_mags:       { param: 'optic_magnification', label: 'Magnification' },
		optics_objs:       { param: 'optic_objective',     label: 'Objective' }
	};

	function openPicker(key, slotDef) {
		var panel = document.getElementById('gdPickerPanel');
		if (!panel) return;

		activeSlotKey = key;
		document.getElementById('gdLoadoutBuilder').classList.add('gdlo-pick-open');

		var title = document.getElementById('gdPickTitle');
		if (title) title.textContent = slotDef.label || key;

		var searchInput = document.getElementById('gdPickSearch');
		if (searchInput) searchInput.value = '';

		var resultsEl = document.getElementById('gdPickResults');
		if (resultsEl) resultsEl.innerHTML = '';
		var emptyEl = document.getElementById('gdPickEmpty');
		if (emptyEl) emptyEl.style.display = 'none';
		var loadMoreEl = document.getElementById('gdPickLoadMore');
		if (loadMoreEl) loadMoreEl.style.display = 'none';
		var facetsEl = document.getElementById('gdPickFacets');
		if (facetsEl) facetsEl.innerHTML = '';
		var facetBarEl = document.getElementById('gdPickFacetBar');
		if (facetBarEl) facetBarEl.innerHTML = '';
		var facetClearEl = document.getElementById('gdPickFacetClear');
		if (facetClearEl) facetClearEl.style.display = 'none';

		pickerCategory = [];
		pickerSort = 'relevance';
		pickerPage = 1;
		pickerLoaded = 0;
		pickerFilters = {};

		var catInfo = slotCategories[key];
		pickerCatField = (catInfo && catInfo.field) ? catInfo.field : 'category';

		var typesEl = document.getElementById('gdPickTypes');
		if (typesEl) typesEl.innerHTML = '';

		if (catInfo && catInfo.types) {
			var typeKeys = Object.keys(catInfo.types);
			typeKeys.forEach(function (typeName) {
				var btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'gdlo-pick-type-btn';
				btn.textContent = typeName;
				btn.addEventListener('click', function () {
					var btns = typesEl.querySelectorAll('.gdlo-pick-type-btn');
					for (var b = 0; b < btns.length; b++) btns[b].classList.remove('active');
					btn.classList.add('active');
					pickerCategory = catInfo.types[typeName];
					pickerPage = 1; pickerLoaded = 0;
					loadPickerResults(false);
				});
				typesEl.appendChild(btn);
			});
			var firstBtn = typesEl.querySelector('.gdlo-pick-type-btn');
			if (firstBtn) {
				firstBtn.classList.add('active');
				pickerCategory = catInfo.types[typeKeys[0]];
			}
			typesEl.style.display = '';
		} else if (catInfo && catInfo.category) {
			if (typesEl) typesEl.style.display = 'none';
			pickerCategory = [catInfo.category];
		} else {
			if (typesEl) typesEl.style.display = 'none';
		}

		renderPickerSort();
		panel.style.display = '';
		loadPickerResults(false);
		if (searchInput) setTimeout(function () { searchInput.focus(); }, 100);
	}

	function closePicker() {
		var panel = document.getElementById('gdPickerPanel');
		if (panel) panel.style.display = 'none';
		var builder = document.getElementById('gdLoadoutBuilder');
		if (builder) builder.classList.remove('gdlo-pick-open');
		activeSlotKey = null;
		highlightCards();
	}

	var pickCloseBtn = document.getElementById('gdPickClose');
	if (pickCloseBtn) pickCloseBtn.addEventListener('click', closePicker);

	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') closePicker();
	});

	function renderPickerSort() {
		var sortEl = document.getElementById('gdPickSort');
		if (!sortEl) return;
		sortEl.innerHTML = '';
		var sorts = [
			{ key: 'relevance', label: 'Relevance' },
			{ key: 'brand', label: 'Name (A–Z)' }
		];
		sorts.forEach(function (s) {
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'gdlo-pick-sort-btn' + (pickerSort === s.key ? ' active' : '');
			btn.textContent = s.label;
			btn.addEventListener('click', function () {
				if (pickerSort === s.key) return;
				pickerSort = s.key;
				pickerPage = 1; pickerLoaded = 0;
				renderPickerSort();
				loadPickerResults(false);
			});
			sortEl.appendChild(btn);
		});
	}

	function buildFacetParams() {
		var parts = [];
		Object.keys(pickerFilters).forEach(function (param) {
			var vals = pickerFilters[param];
			if (Array.isArray(vals)) {
				vals.forEach(function (v) { parts.push(encodeURIComponent(param) + '[]=' + encodeURIComponent(v)); });
			} else if (vals === true) {
				parts.push(encodeURIComponent(param) + '=1');
			} else if (typeof vals === 'number' && vals > 0) {
				parts.push(encodeURIComponent(param) + '=' + vals);
			}
		});
		return parts.join('&');
	}

	function hasActiveFilters() {
		var keys = Object.keys(pickerFilters);
		for (var i = 0; i < keys.length; i++) {
			var v = pickerFilters[keys[i]];
			if (Array.isArray(v) && v.length > 0) return true;
			if (v === true) return true;
			if (typeof v === 'number' && v > 0) return true;
		}
		return false;
	}

	function renderFacets(aggs) {
		var facetsEl = document.getElementById('gdPickFacets');
		if (!facetsEl) return;
		facetsEl.innerHTML = '';

		var facetBarEl = document.getElementById('gdPickFacetBar');
		if (facetBarEl) facetBarEl.innerHTML = '';

		if (aggs && typeof aggs === 'object') {
			Object.keys(FACET_MAP).forEach(function (aggKey) {
				if (!aggs[aggKey] || !aggs[aggKey].buckets || aggs[aggKey].buckets.length === 0) return;
				var info = FACET_MAP[aggKey];
				var buckets = aggs[aggKey].buckets;
				var selected = pickerFilters[info.param] || [];

				var details = document.createElement('details');
				details.className = 'gdlo-pick-facet-group';
				if (selected.length > 0) details.open = true;

				var summary = document.createElement('summary');
				summary.textContent = info.label;
				if (selected.length > 0) {
					var cnt = document.createElement('span');
					cnt.className = 'gdlo-pick-facet-cnt';
					cnt.textContent = ' (' + selected.length + ')';
					summary.appendChild(cnt);
				}
				details.appendChild(summary);

				var opts = document.createElement('div');
				opts.className = 'gdlo-pick-facet-opts';
				buckets.forEach(function (b) {
					var label = document.createElement('label');
					label.className = 'gdlo-pick-facet-opt';
					var cb = document.createElement('input');
					cb.type = 'checkbox';
					cb.value = b.key;
					if (selected.indexOf(b.key) !== -1) cb.checked = true;
					cb.addEventListener('change', function () {
						var cur = pickerFilters[info.param] || [];
						if (cb.checked) { if (cur.indexOf(b.key) === -1) cur.push(b.key); }
						else { cur = cur.filter(function (v) { return v !== b.key; }); }
						pickerFilters[info.param] = cur.length > 0 ? cur : [];
						if (cur.length === 0) delete pickerFilters[info.param];
						pickerPage = 1; pickerLoaded = 0;
						loadPickerResults(false);
					});
					var text = document.createElement('span');
					text.textContent = b.key;
					var count = document.createElement('em');
					count.className = 'gdlo-pick-facet-doc';
					count.textContent = '(' + b.doc_count + ')';
					label.appendChild(cb);
					label.appendChild(text);
					label.appendChild(count);
					opts.appendChild(label);
				});
				details.appendChild(opts);
				facetsEl.appendChild(details);
			});
		}

		if (facetBarEl) {
			var priceRow = document.createElement('div');
			priceRow.className = 'gdlo-pick-facet-price';
			var minInput = document.createElement('input');
			minInput.type = 'number'; minInput.placeholder = 'Min $'; minInput.min = '0'; minInput.step = '1';
			if (pickerFilters.min_price > 0) minInput.value = pickerFilters.min_price;
			var dash = document.createElement('span');
			dash.textContent = '–';
			var maxInput = document.createElement('input');
			maxInput.type = 'number'; maxInput.placeholder = 'Max $'; maxInput.min = '0'; maxInput.step = '1';
			if (pickerFilters.max_price > 0) maxInput.value = pickerFilters.max_price;
			priceRow.appendChild(minInput); priceRow.appendChild(dash); priceRow.appendChild(maxInput);
			facetBarEl.appendChild(priceRow);

			var stockLabel = document.createElement('label');
			stockLabel.className = 'gdlo-pick-facet-instock';
			var stockCb = document.createElement('input');
			stockCb.type = 'checkbox';
			if (pickerFilters.in_stock === true) stockCb.checked = true;
			stockCb.addEventListener('change', function () {
				if (stockCb.checked) pickerFilters.in_stock = true;
				else delete pickerFilters.in_stock;
				pickerPage = 1; pickerLoaded = 0;
				loadPickerResults(false);
			});
			var stockText = document.createElement('span');
			stockText.textContent = 'In stock only';
			stockLabel.appendChild(stockCb); stockLabel.appendChild(stockText);
			facetBarEl.appendChild(stockLabel);

			var priceTimer = null;
			function onPriceChange() {
				clearTimeout(priceTimer);
				priceTimer = setTimeout(function () {
					var mn = parseFloat(minInput.value) || 0;
					var mx = parseFloat(maxInput.value) || 0;
					if (mn > 0) pickerFilters.min_price = mn; else delete pickerFilters.min_price;
					if (mx > 0) pickerFilters.max_price = mx; else delete pickerFilters.max_price;
					pickerPage = 1; pickerLoaded = 0;
					loadPickerResults(false);
				}, 500);
			}
			minInput.addEventListener('input', onPriceChange);
			maxInput.addEventListener('input', onPriceChange);
		}

		var clearBtn = document.getElementById('gdPickFacetClear');
		if (clearBtn) clearBtn.style.display = hasActiveFilters() ? '' : 'none';
	}

	var facetClearBtn = document.getElementById('gdPickFacetClear');
	if (facetClearBtn) {
		facetClearBtn.addEventListener('click', function () {
			pickerFilters = {};
			pickerPage = 1; pickerLoaded = 0;
			loadPickerResults(false);
		});
	}

	function loadPickerResults(append) {
		var resultsEl = document.getElementById('gdPickResults');
		if (!resultsEl) return;
		var searchInput = document.getElementById('gdPickSearch');
		var q = searchInput ? searchInput.value.trim() : '';

		if (!append) {
			resultsEl.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:20px"><i class="fa-solid fa-spinner fa-spin"></i></div>';
			pickerPage = 1;
			pickerLoaded = 0;
		}

		var emptyEl = document.getElementById('gdPickEmpty');
		var loadMoreEl = document.getElementById('gdPickLoadMore');
		if (emptyEl) emptyEl.style.display = 'none';
		if (loadMoreEl) loadMoreEl.style.display = 'none';

		var catStr = '';
		if (pickerCategory.length === 1) catStr = pickerCategory[0];
		else if (pickerCategory.length > 1) catStr = pickerCategory.join(',');

		var url = searchUrl + (searchUrl.indexOf('?') > -1 ? '&' : '?') + 'q=' + encodeURIComponent(q)
			+ '&page=' + pickerPage + '&sort=' + encodeURIComponent(pickerSort);
		if (catStr) url += '&category=' + encodeURIComponent(catStr) + '&catfield=' + encodeURIComponent(pickerCatField);

		var facetParams = buildFacetParams();
		if (facetParams) url += '&' + facetParams;

		fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
			.then(function (resp) { return resp.json(); })
			.then(function (data) {
				if (!append) resultsEl.innerHTML = '';
				if (!append) renderFacets(data.aggregations || null);

				var aggs = data.aggregations || {};
				Object.keys(aggs).forEach(function (k) {
					if (aggs[k] && aggs[k].values && aggs[k].values.buckets) {
						aggs[k].buckets = aggs[k].values.buckets;
					}
				});

				if (!data.results || data.results.length === 0) {
					if (pickerLoaded === 0 && emptyEl) emptyEl.style.display = '';
					return;
				}
				if (emptyEl) emptyEl.style.display = 'none';

				data.results.forEach(function (r) {
					var card = document.createElement('div');
					card.className = 'gdlo-pick-card';
					var imgHtml = (r.image_url && r.image_url.length)
						? '<img class="gdlo-pick-card-img" src="' + escapeAttr(r.image_url) + '" alt="" loading="lazy" onerror="this.style.display=\'none\'">'
						: '<div class="gdlo-pick-card-ph">&#128230;</div>';
					var priceHtml = (r.best_price && parseFloat(r.best_price) > 0)
						? '<div class="gdlo-pick-card-price">$' + parseFloat(r.best_price).toFixed(2) + '</div>'
						: '<div class="gdlo-pick-card-noprice">No price yet</div>';
					card.innerHTML = imgHtml
						+ '<div class="gdlo-pick-card-body">'
						+ '<div class="gdlo-pick-card-title">' + escapeHtml(r.title || r.upc) + '</div>'
						+ '<div class="gdlo-pick-card-brand">' + escapeHtml(r.brand || '') + '</div>'
						+ priceHtml + '</div>';
					card.addEventListener('click', function () { assignProduct(r); });
					resultsEl.appendChild(card);
				});

				pickerLoaded += data.results.length;
				var total = data.total || 0;
				if (loadMoreEl) {
					loadMoreEl.style.display = (data.results.length >= 24 && pickerLoaded < total) ? '' : 'none';
				}
			})
			.catch(function () {
				if (!append) resultsEl.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:20px;color:#dc2626">Search error — please try again.</div>';
			});
	}

	var pickSearch = document.getElementById('gdPickSearch');
	if (pickSearch) {
		pickSearch.addEventListener('input', function () {
			clearTimeout(pickerTimer);
			pickerPage = 1; pickerLoaded = 0;
			pickerTimer = setTimeout(function () { loadPickerResults(false); }, 300);
		});
	}

	var pickLoadMore = document.getElementById('gdPickLoadMore');
	if (pickLoadMore) {
		pickLoadMore.addEventListener('click', function () {
			pickerPage++;
			loadPickerResults(true);
		});
	}

	/* ===== Assign Product ===== */
	function assignProduct(product) {
		if (!activeSlotKey) return;

		if (maxSlots > 0) {
			var filled = 0;
			Object.keys(slots).forEach(function (k) { if (slots[k] && slots[k].upc) filled++; });
			if (!(slots[activeSlotKey] && slots[activeSlotKey].upc) && filled >= maxSlots) {
				alert('Slot limit reached (' + maxSlots + ')');
				return;
			}
		}

		ensureSlot(activeSlotKey);
		slots[activeSlotKey].upc   = product.upc || '';
		slots[activeSlotKey].title = product.title || product.upc || '';
		slots[activeSlotKey].price = product.best_price || null;
		slots[activeSlotKey].image = product.image_url || '';

		closePicker();
		if (currentStep === 2) renderCoreGrid();
		if (currentStep === 3) { renderAccGrid(); renderExtraGrid(); }
		updateAllSummaries();
	}

	/* ===== Save ===== */
	if (saveBtn) {
		saveBtn.addEventListener('click', function () {
			var name = nameInput ? nameInput.value.trim() : '';
			if (!name) { if (nameInput) nameInput.focus(); alert('Please enter a name.'); return; }

			var slotArr = [];
			Object.keys(slots).forEach(function (key) {
				var s = slots[key];
				if (s && s.upc) {
					var entry = {
						upc: s.upc,
						slot_type: s.type || key,
						custom_label: s.custom_label || null,
						price: s.price || null
					};
					if (isVip && itemNotes[key]) entry.notes = itemNotes[key];
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
			body.append('loadout_build_mode', buildMode);
			body.append('loadout_platform', platform);
			body.append('loadout_slots', JSON.stringify(slotArr));

			saveBtn.disabled = true;
			saveBtn.textContent = 'Saving...';

			fetch(saveUrl, {
				method: 'POST', body: body,
				headers: { 'X-Requested-With': 'XMLHttpRequest' }
			})
			.then(function (resp) { return resp.json(); })
			.then(function (data) {
				saveBtn.disabled = false;
				saveBtn.textContent = 'Save Loadout';
				if (data.error) { alert(data.error); return; }
				if (data.ok && data.redirect) { window.location.href = data.redirect; return; }
				if (data.ok && data.loadout_id) loadoutId = data.loadout_id;
			})
			.catch(function () {
				saveBtn.disabled = false;
				saveBtn.textContent = 'Save Loadout';
				alert('Save failed — please try again.');
			});
		});
	}

	/* ===== Delete ===== */
	if (deleteBtn) {
		deleteBtn.addEventListener('click', function () {
			if (!confirm('Are you sure you want to delete this loadout?')) return;
			var body = new FormData();
			body.append('csrfKey', csrfKey);
			body.append('loadout_id', loadoutId);

			fetch(deleteUrl, {
				method: 'POST', body: body,
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

	/* ===== Utilities ===== */
	function escapeHtml(str) {
		var d = document.createElement('div');
		d.appendChild(document.createTextNode(str || ''));
		return d.innerHTML;
	}

	function escapeAttr(str) {
		return (str || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
	}

	/* ===== Init ===== */
	function initFromExisting() {
		var coreDefs = getCoreSlotDefs();
		Object.keys(coreDefs).forEach(function (key) { ensureSlot(key); });
		Object.keys(accessorySlots).forEach(function (key) { ensureSlot(key); });

		for (var i = 0; i < items.length; i++) {
			var it = items[i];
			var key = it.slot_type || 'extra';

			if (key === 'extra') {
				extraCounter++;
				var ek = 'extra_' + extraCounter;
				slots[ek] = {
					type: 'extra', upc: it.upc || '', title: it.title || it.custom_label || 'Extra',
					price: (it.price_snapshot !== undefined && it.price_snapshot !== null) ? it.price_snapshot : null,
					custom_label: it.custom_label || 'Extra', image: it.image_url || ''
				};
				if (it.notes) itemNotes[ek] = it.notes;
			} else {
				ensureSlot(key);
				slots[key].upc   = it.upc || '';
				slots[key].title = it.title || it.custom_label || key;
				slots[key].price = (it.price_snapshot !== undefined && it.price_snapshot !== null) ? it.price_snapshot : null;
				slots[key].image = it.image_url || '';
				if (it.notes) itemNotes[key] = it.notes;
			}
		}
	}

	setMode(buildMode);
	initPlatformChips();
	initFromExisting();
	goToStep(1);
})();
