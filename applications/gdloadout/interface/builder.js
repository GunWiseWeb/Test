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
	var suggestMode = !!init.suggestMode;
	var submitSuggestionUrl = init.submitSuggestionUrl || '';
	/* v1.0.65 — AJAX state selector: URLs to write the cookie
	   and to re-check compliance for a batch of UPCs, plus the
	   current state for JS-side use. */
	var setStateUrl        = init.setStateUrl || '';
	var complianceCheckUrl = init.complianceCheckUrl || '';
	var currentComplianceState = init.complianceState || '';
	/* v1.0.67 — dealer picker endpoints */
	var dealersUrl       = init.dealersUrl || '';
	var setItemDealerUrl = init.setItemDealerUrl || '';
	var originalSlots = {};

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

	var submitSugBtn = document.getElementById('gdSubmitSugBtn');
	var suggestNoteWrap = document.getElementById('gdSuggestNoteWrap');
	var suggestNoteInput = document.getElementById('gdSuggestNote');

	if (suggestMode) {
		if (nameInput)  nameInput.disabled = true;
		if (descInput)  descInput.disabled = true;
		if (useCaseSel) useCaseSel.disabled = true;
		if (visSel)     visSel.disabled = true;
		if (saveBtn) saveBtn.style.display = 'none';
		if (deleteBtn) deleteBtn.style.display = 'none';
		if (submitSugBtn) submitSugBtn.style.display = '';
		if (suggestNoteWrap) suggestNoteWrap.style.display = '';
	} else {
		if (loadoutId > 0 && deleteBtn) deleteBtn.style.display = '';
	}

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
	function hasAnyPart() {
		var keys = Object.keys(slots);
		for (var i = 0; i < keys.length; i++) {
			if (slots[keys[i]] && slots[keys[i]].upc) return true;
		}
		return false;
	}

	function showModeNote() {
		var grid = document.getElementById('gdModeGrid');
		if (!grid) return;
		var next = grid.nextElementSibling;
		if (next && next.classList.contains('gdlo-mode-note')) return;
		var note = document.createElement('div');
		note.className = 'gdlo-mode-note';
		note.textContent = 'Reset the build to change modes.';
		grid.parentNode.insertBefore(note, grid.nextSibling);
	}

	function hideModeNote() {
		var grid = document.getElementById('gdModeGrid');
		if (!grid) return;
		var next = grid.nextElementSibling;
		if (next && next.classList.contains('gdlo-mode-note')) {
			next.parentNode.removeChild(next);
		}
	}

	function updateModeLock() {
		var hasParts = hasAnyPart();
		var cards = document.querySelectorAll('#gdModeGrid .gdlo-mode-card');
		for (var c = 0; c < cards.length; c++) {
			var isActive = cards[c].dataset.mode === buildMode;
			if (hasParts && !isActive) {
				cards[c].classList.add('gdlo-mode-locked');
				cards[c].setAttribute('aria-disabled', 'true');
			} else {
				cards[c].classList.remove('gdlo-mode-locked');
				cards[c].removeAttribute('aria-disabled');
			}
		}
		if (!hasParts) hideModeNote();
	}

	function setMode(mode) {
		if (hasAnyPart() && mode !== buildMode) {
			showModeNote();
			return;
		}
		hideModeNote();
		buildMode = mode;
		var cards = document.querySelectorAll('#gdModeGrid .gdlo-mode-card');
		for (var c = 0; c < cards.length; c++) {
			cards[c].classList.toggle('gdlo-mode-card--active', cards[c].dataset.mode === mode);
		}
		var platRow = document.getElementById('gdPlatformRow');
		if (platRow) platRow.style.display = mode === 'component_build' ? '' : 'none';
		updateModeLock();
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
			slots[key] = { type: key, upc: '', title: '', price: null, custom_label: null, image: '', compliance: null, dealer_count: 0, preferred_dealer_id: null, preferred_dealer: null, item_id: 0 };
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
		if (isSlotChanged(key) || (slots[key] && slots[key].suggested)) card.classList.add('gdlo-card--suggested');
		card.dataset.slotKey = key;
		var slot = slots[key];

		if (slot && slot.upc) {
			card.classList.add('gdlo-card--filled');
			var tint = slotTintClass(slot.compliance);
			if (tint) card.classList.add(tint);
			var imgHtml = slot.image
				? '<img class="gdlo-card-img" src="' + escapeAttr(slot.image) + '" alt="" loading="lazy" onerror="this.style.display=\'none\'">'
				: '<div class="gdlo-card-img-ph"><i class="' + escapeAttr(slotDef.icon || 'fa-solid fa-cube') + '"></i></div>';
			card.innerHTML = imgHtml
				+ '<div class="gdlo-card-body">'
				+ '<div class="gdlo-card-label">' + escapeHtml(slotDef.label) + '</div>'
				+ '<div class="gdlo-card-title">' + escapeHtml(slot.title || slot.upc) + '</div>'
				+ renderDealerLine(slot, key)
				+ renderComplianceBadge(slot.compliance)
				+ '</div>'
				+ '<button type="button" class="gdlo-card-remove" data-slot-key="' + escapeAttr(key) + '">&times;</button>';
			wireDealerPicker(card, key);
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
			/* v1.0.68 — dealer picker UI (toggle button + expanded
			   panel with Select/Reset/View controls) sits INSIDE
			   the card. Any click on it must NOT bubble up to
			   openPicker() → do=search — that was hijacking the
			   Choose-dealer click and firing search instead of
			   the dealer fetch. */
			if (e.target.closest('.gdld-toggle')) return;
			if (e.target.closest('.gdld-panel'))  return;
			activeSlotKey = key;
			highlightCards();
			openPicker(key, slotDef);
		});

		var rmBtn = card.querySelector('.gdlo-card-remove');
		if (rmBtn) {
			rmBtn.addEventListener('click', function (e) {
				e.stopPropagation();
				slots[key] = { type: key, upc: '', title: '', price: null, custom_label: null, image: '', compliance: null, dealer_count: 0, preferred_dealer_id: null, preferred_dealer: null, item_id: 0 };
				delete itemNotes[key];
				if (currentStep === 2) renderCoreGrid();
				if (currentStep === 3) { renderAccGrid(); renderExtraGrid(); }
				updateAllSummaries();
				updateModeLock();
			});
		}

		return card;
	}

	function createExtraCard(key) {
		var slot = slots[key];
		if (!slot) return null;
		var card = document.createElement('div');
		card.className = 'gdlo-card';
		if (isSlotChanged(key) || (slots[key] && slots[key].suggested)) card.classList.add('gdlo-card--suggested');
		card.dataset.slotKey = key;
		var label = slot.custom_label || 'Extra';

		if (slot.upc) {
			card.classList.add('gdlo-card--filled');
			var extraTint = slotTintClass(slot.compliance);
			if (extraTint) card.classList.add(extraTint);
			var imgHtml = slot.image
				? '<img class="gdlo-card-img" src="' + escapeAttr(slot.image) + '" alt="" loading="lazy" onerror="this.style.display=\'none\'">'
				: '<div class="gdlo-card-img-ph"><i class="fa-solid fa-cube"></i></div>';
			card.innerHTML = imgHtml
				+ '<div class="gdlo-card-body">'
				+ '<div class="gdlo-card-label">' + escapeHtml(label) + '</div>'
				+ '<div class="gdlo-card-title">' + escapeHtml(slot.title || slot.upc) + '</div>'
				+ renderDealerLine(slot, key)
				+ renderComplianceBadge(slot.compliance)
				+ '</div>'
				+ '<button type="button" class="gdlo-card-remove" data-slot-key="' + escapeAttr(key) + '">&times;</button>';
			wireDealerPicker(card, key);
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
			/* v1.0.68 — same guard as createSlotCard: the dealer
			   toggle + expanded panel controls live inside this
			   extra-slot card too. Ignore them so Choose-dealer
			   doesn't fall through to openPicker/do=search. */
			if (e.target.closest('.gdld-toggle')) return;
			if (e.target.closest('.gdld-panel'))  return;
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
				updateModeLock();
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
			slots[key] = { type: 'extra', upc: '', title: '', price: null, custom_label: label, image: '', compliance: null, dealer_count: 0, preferred_dealer_id: null, preferred_dealer: null, item_id: 0 };
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

		/* v1.0.64 — compact compliance banner written into the
		   server-rendered #gdlc-summary placeholder. Computed
		   client-side from the slots' compliance sub-objects so
		   it reflects add/remove instantly, without a server
		   round trip. */
		updateComplianceBanner(filled);
	}

	function updateComplianceBanner(filled) {
		var host = document.getElementById('gdlc-summary');
		if (!host) return;
		ensureCbadgeStyles();

		var stateCode = '';
		var restricted = 0;
		var advisory   = 0;
		for (var i = 0; i < filled.length; i++) {
			var c = filled[i].slot ? filled[i].slot.compliance : null;
			if (!c) continue;
			if (c.state && !stateCode) { stateCode = c.state; }
			if (c.restricted_here)     { restricted++; }
			else if (c.advisory_here)  { advisory++; }
		}
		var stateName = stateCode ? (GDLO_STATE_NAMES[stateCode] || stateCode) : '';

		if (!stateCode) {
			host.innerHTML = '<div class="gdlo-banner gdlo-banner--info">Set your state above to check this build for restrictions.</div>';
			return;
		}
		if (restricted > 0) {
			host.innerHTML = '<div class="gdlo-banner gdlo-banner--danger">&#9940; ' + restricted + ' item' + (restricted === 1 ? '' : 's') + ' in this build ' + (restricted === 1 ? 'is' : 'are') + ' restricted in ' + escapeHtml(stateName) + '.</div>';
			return;
		}
		if (advisory > 0) {
			host.innerHTML = '<div class="gdlo-banner gdlo-banner--warn">&#9432; ' + advisory + ' item' + (advisory === 1 ? '' : 's') + ' ha' + (advisory === 1 ? 's' : 've') + ' buyer requirements in ' + escapeHtml(stateName) + '.</div>';
			return;
		}
		if (filled.length === 0) {
			host.innerHTML = '<div class="gdlo-banner gdlo-banner--info">Add items to check compliance for ' + escapeHtml(stateName) + '.</div>';
			return;
		}
		host.innerHTML = '<div class="gdlo-banner gdlo-banner--ok">&#10003; No items restricted in ' + escapeHtml(stateName) + '.</div>';
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
		var backdrop = document.getElementById('gdPickBackdrop');

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
		panel.classList.add('is-open');
		if (backdrop) backdrop.classList.add('is-open');
		loadPickerResults(false);
		if (searchInput) setTimeout(function () { searchInput.focus(); }, 100);
	}

	function closePicker() {
		var panel = document.getElementById('gdPickerPanel');
		if (panel) panel.classList.remove('is-open');
		var backdrop = document.getElementById('gdPickBackdrop');
		if (backdrop) backdrop.classList.remove('is-open');
		activeSlotKey = null;
		highlightCards();
	}

	var pickCloseBtn = document.getElementById('gdPickClose');
	if (pickCloseBtn) pickCloseBtn.addEventListener('click', closePicker);

	var pickBackdrop = document.getElementById('gdPickBackdrop');
	if (pickBackdrop) pickBackdrop.addEventListener('click', closePicker);

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
					var complianceHtml = renderResultCompliance(r.compliance);
					if (complianceHtml && card.classList) {
						var c = r.compliance || {};
						if (c.restricted_here) { card.classList.add('gdlo-pick-card--restricted'); }
						else if (c.advisory_here) { card.classList.add('gdlo-pick-card--advisory'); }
					}
					card.innerHTML = imgHtml
						+ '<div class="gdlo-pick-card-body">'
						+ '<div class="gdlo-pick-card-title">' + escapeHtml(r.title || r.upc) + '</div>'
						+ '<div class="gdlo-pick-card-brand">' + escapeHtml(r.brand || '') + '</div>'
						+ priceHtml
						+ complianceHtml
						+ '</div>';
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
		/* v1.0.64 — carry the search-result's compliance data into
		   the slot so the filled slot card can show the same
		   restriction badge without a second server round trip. */
		slots[activeSlotKey].compliance = product.compliance || null;
		/* v1.0.67 — search results carry dealer_count directly, so
		   the picker can show "N dealers" before the user expands
		   without a second server round trip. Picking a fresh
		   result resets any prior preferred_dealer choice. */
		slots[activeSlotKey].dealer_count = product.dealer_count !== undefined ? parseInt(product.dealer_count, 10) : 0;
		slots[activeSlotKey].preferred_dealer_id = null;
		slots[activeSlotKey].preferred_dealer = null;

		closePicker();
		if (currentStep === 2) renderCoreGrid();
		if (currentStep === 3) { renderAccGrid(); renderExtraGrid(); }
		updateAllSummaries();
		updateModeLock();
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
						price: s.price || null,
						/* v1.0.67 — persist the user's dealer choice.
						   NULL / 0 → save() writes NULL → cheapest
						   offer is the render-time default. */
						preferred_dealer_id: (s.preferred_dealer_id && parseInt(s.preferred_dealer_id, 10) > 0)
							? parseInt(s.preferred_dealer_id, 10) : null
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
			if (acceptSuggestionId) body.append('accept_suggestion_id', acceptSuggestionId);

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

	/* ===== v1.0.63 — real-time compliance badge on search results.
	 *
	 * search() attaches r.compliance = {
	 *   state, restricted_here, advisory_here, reason_here,
	 *   restricted_count, restricted_codes
	 * } to every result. renderResultCompliance() turns that into
	 * a small badge on the result card BEFORE the buyer clicks add
	 * — so restrictions are visible while browsing, not only after
	 * saving the loadout.
	 *
	 * Scoped `.gdlo-pick-cbadge-*` classes; the parent `.gdlo-pick-
	 * card--restricted / --advisory` classes tint the card so
	 * restricted rows are obvious at a glance in a grid. Style
	 * block is injected once on first call. */
	var GDLO_STATE_NAMES = {
		AL:'Alabama', AK:'Alaska', AZ:'Arizona', AR:'Arkansas', CA:'California',
		CO:'Colorado', CT:'Connecticut', DE:'Delaware', DC:'District of Columbia',
		FL:'Florida', GA:'Georgia', HI:'Hawaii', ID:'Idaho', IL:'Illinois',
		IN:'Indiana', IA:'Iowa', KS:'Kansas', KY:'Kentucky', LA:'Louisiana',
		ME:'Maine', MD:'Maryland', MA:'Massachusetts', MI:'Michigan', MN:'Minnesota',
		MS:'Mississippi', MO:'Missouri', MT:'Montana', NE:'Nebraska', NV:'Nevada',
		NH:'New Hampshire', NJ:'New Jersey', NM:'New Mexico', NY:'New York',
		NC:'North Carolina', ND:'North Dakota', OH:'Ohio', OK:'Oklahoma',
		OR:'Oregon', PA:'Pennsylvania', RI:'Rhode Island', SC:'South Carolina',
		SD:'South Dakota', TN:'Tennessee', TX:'Texas', UT:'Utah', VT:'Vermont',
		VA:'Virginia', WA:'Washington', WV:'West Virginia', WI:'Wisconsin', WY:'Wyoming'
	};
	var gdloCbadgeStylesInjected = false;
	function ensureCbadgeStyles() {
		if (gdloCbadgeStylesInjected) return;
		gdloCbadgeStylesInjected = true;
		var s = document.createElement('style');
		s.textContent =
			/* pick-card (search result) tint */
			'.gdlo-pick-card--restricted{border:1px solid #fecaca;background:#fef7f7}' +
			'.gdlo-pick-card--advisory{border:1px solid #fde68a;background:#fffdf5}' +
			/* v1.0.64 — filled slot-card tint (same colors as pick cards) */
			'.gdlo-card--restricted{box-shadow:0 0 0 2px #fecaca inset;background:#fef7f7}' +
			'.gdlo-card--advisory{box-shadow:0 0 0 2px #fde68a inset;background:#fffdf5}' +
			/* Shared badge — used on both pick cards and slot cards */
			'.gdlo-cbadge{display:inline-block;margin-top:6px;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;line-height:1.4}' +
			'.gdlo-cbadge--restricted{background:#7f1d1d;color:#fee2e2}' +
			'.gdlo-cbadge--advisory{background:#78350f;color:#fef3c7}' +
			'.gdlo-cbadge--elsewhere{background:#e2e8f0;color:#475569}' +
			'.gdlo-cbadge--hint{background:#f1f5f9;color:#64748b;text-transform:none;font-weight:500;letter-spacing:0}' +
			/* Compact build-summary banner (targets the server-rendered #gdlc-summary placeholder) */
			'#gdlc-summary .gdlo-banner{padding:8px 12px;border-radius:8px;font-size:.92em;font-weight:500}' +
			'#gdlc-summary .gdlo-banner--danger{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}' +
			'#gdlc-summary .gdlo-banner--warn{background:#fef3c7;color:#78350f;border:1px solid #fde68a}' +
			'#gdlc-summary .gdlo-banner--ok{background:#f0fdf4;color:#14532d;border:1px solid #bbf7d0}' +
			'#gdlc-summary .gdlo-banner--info{background:#f1f5f9;color:#475569;border:1px solid #e2e8f0}';
		document.head.appendChild(s);
	}

	/* v1.0.64 — one badge renderer used by both search results and
	 * filled slot cards. Reads the shared compliance sub-object
	 * shape produced by search()/manage() enrichment. */
	function renderComplianceBadge(c) {
		if (!c) return '';
		ensureCbadgeStyles();
		var stateCode = c.state || '';
		var stateName = stateCode ? (GDLO_STATE_NAMES[stateCode] || stateCode) : '';
		if (c.restricted_here) {
			var t = 'Restricted' + (stateName ? ' in ' + stateName : '');
			var title = c.reason_here ? ' title="' + escapeAttr(c.reason_here) + '"' : '';
			return '<span class="gdlo-cbadge gdlo-cbadge--restricted"' + title + '>&#9940; ' + escapeHtml(t) + '</span>';
		}
		if (c.advisory_here) {
			var a = 'Buyer requirement' + (stateName ? ' in ' + stateName : '');
			var atitle = c.reason_here ? ' title="' + escapeAttr(c.reason_here) + '"' : '';
			return '<span class="gdlo-cbadge gdlo-cbadge--advisory"' + atitle + '>&#9432; ' + escapeHtml(a) + '</span>';
		}
		var count = parseInt(c.restricted_count || 0, 10) || 0;
		if (count > 0 && !stateCode) {
			return '<span class="gdlo-cbadge gdlo-cbadge--hint">Set your state to check restrictions (' + count + ' state' + (count === 1 ? '' : 's') + ')</span>';
		}
		if (count > 0) {
			return '<span class="gdlo-cbadge gdlo-cbadge--elsewhere">Restricted in ' + count + ' state' + (count === 1 ? '' : 's') + '</span>';
		}
		return '';
	}

	/* Back-compat alias: existing renderResultCompliance() call
	   sites on search-result cards keep working. */
	function renderResultCompliance(c) { return renderComplianceBadge(c); }

	/* v1.0.64 — CSS class for the parent slot card to tint when
	   restricted / advisory in the buyer's state. Empty when
	   clear or when no state is set. */
	function slotTintClass(c) {
		if (!c) return '';
		if (c.restricted_here) return 'gdlo-card--restricted';
		if (c.advisory_here)   return 'gdlo-card--advisory';
		return '';
	}

	/* ===== v1.0.67 — per-item dealer picker =====
	 *
	 * Each filled slot card renders a compact "N dealers — from
	 * $X" line under the price; clicking the toggle reveals a
	 * <details> panel that fetches the full dealer list lazily
	 * and lets the buyer pick a specific offer. Clicking Select
	 * updates slots[key].preferred_dealer_id + preferred_dealer,
	 * re-renders the card, and — if the loadout is already saved
	 * (slot.item_id > 0) — POSTs to setItemDealer for immediate
	 * persistence. Otherwise the choice rides on the next save().
	 *
	 * All fetches are guarded; a network failure keeps the
	 * default cheapest and shows a small inline retry note. */
	var gdloDealerStylesInjected = false;
	function ensureDealerStyles() {
		if (gdloDealerStylesInjected) return;
		gdloDealerStylesInjected = true;
		var s = document.createElement('style');
		s.textContent =
			'.gdld-line{margin-top:4px;font-size:.85em;color:#475569;line-height:1.35}' +
			'.gdld-line strong{color:#0f172a}' +
			'.gdld-line--preferred{color:#166534}' +
			'.gdld-toggle{display:inline-block;margin-top:4px;font-size:.85em;color:#0f172a;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;padding:2px 8px;cursor:pointer}' +
			'.gdld-toggle:hover{background:#e2e8f0}' +
			'.gdld-panel{margin-top:6px;padding:8px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;font-size:.85em}' +
			'.gdld-row{display:flex;justify-content:space-between;align-items:center;gap:8px;padding:6px 0;border-top:1px dashed #e2e8f0}' +
			'.gdld-row:first-child{border-top:none}' +
			'.gdld-row--preferred{background:#dcfce7;padding-left:6px;padding-right:6px;border-radius:6px;border-top:none}' +
			'.gdld-name{font-weight:600;color:#0f172a}' +
			'.gdld-meta{color:#64748b;font-size:.9em}' +
			'.gdld-actions{display:flex;gap:6px;flex-shrink:0}' +
			'.gdld-btn{padding:3px 8px;border-radius:6px;border:1px solid #cbd5e1;background:#fff;font:inherit;font-size:.85em;cursor:pointer;color:#0f172a;text-decoration:none;display:inline-block}' +
			'.gdld-btn:hover{background:#f1f5f9}' +
			'.gdld-btn--primary{background:#0f172a;color:#fff;border-color:#0f172a}' +
			'.gdld-btn--primary:hover{background:#1e293b}' +
			'.gdld-empty{color:#94a3b8;font-style:italic;padding:6px 0}' +
			'.gdld-loading{color:#64748b;padding:6px 0}';
		document.head.appendChild(s);
	}

	function renderDealerLine(slot, key) {
		ensureDealerStyles();
		var priceStr, dealerLine;
		var pref = slot.preferred_dealer || null;

		if (pref) {
			var pp = (pref.price !== null && pref.price !== undefined) ? parseFloat(pref.price) : null;
			priceStr = pp !== null ? '$' + pp.toFixed(2) : '';
			dealerLine = '<div class="gdld-line gdld-line--preferred">via <strong>' + escapeHtml(pref.dealer_name || 'dealer') + '</strong>'
				+ (priceStr ? ' — <strong>' + priceStr + '</strong>' : '') + '</div>';
		} else if (slot.price) {
			priceStr = '$' + parseFloat(slot.price).toFixed(2);
			var count = parseInt(slot.dealer_count || 0, 10) || 0;
			dealerLine = '<div class="gdlo-card-price">' + priceStr + '</div>';
			if (count > 1) {
				dealerLine += '<div class="gdld-line">' + count + ' dealers — from <strong>' + priceStr + '</strong></div>';
			} else if (count === 1) {
				dealerLine += '<div class="gdld-line">1 dealer</div>';
			}
		} else {
			dealerLine = '<div class="gdld-line">No dealers currently carry this</div>';
		}

		var toggle = '<button type="button" class="gdld-toggle" data-gdld-key="' + escapeAttr(key) + '">Choose dealer &#9662;</button>';
		var panel  = '<div class="gdld-panel" data-gdld-panel="' + escapeAttr(key) + '" hidden></div>';
		return dealerLine + toggle + panel;
	}

	function wireDealerPicker(card, key) {
		var toggle = card.querySelector('button.gdld-toggle');
		var panel  = card.querySelector('div.gdld-panel');
		if (!toggle || !panel) return;
		toggle.addEventListener('click', function(ev) {
			ev.stopPropagation();
			if (panel.hasAttribute('hidden')) {
				panel.removeAttribute('hidden');
				loadDealerPanel(panel, key);
			} else {
				panel.setAttribute('hidden', '');
			}
		});
	}

	var gdloDealerCache = {};
	function loadDealerPanel(panel, key) {
		var slot = slots[key];
		if (!slot || !slot.upc) { panel.innerHTML = '<div class="gdld-empty">No product in this slot.</div>'; return; }
		if (!dealersUrl) { panel.innerHTML = '<div class="gdld-empty">Dealer picker unavailable.</div>'; return; }

		if (gdloDealerCache[slot.upc]) {
			renderDealerList(panel, key, gdloDealerCache[slot.upc]);
			return;
		}

		panel.innerHTML = '<div class="gdld-loading">Loading dealers…</div>';
		var sep = dealersUrl.indexOf('?') === -1 ? '?' : '&';
		fetch(dealersUrl + sep + 'upc=' + encodeURIComponent(slot.upc), {
			credentials: 'same-origin',
			headers: { 'X-Requested-With': 'XMLHttpRequest' }
		})
		.then(function(r){ return r.json(); })
		.then(function(data){
			var list = (data && data.dealers) || [];
			gdloDealerCache[slot.upc] = list;
			renderDealerList(panel, key, list);
		})
		.catch(function(){
			panel.innerHTML = '<div class="gdld-empty">Couldn\'t load dealers — please try again.</div>';
		});
	}

	function renderDealerList(panel, key, list) {
		var slot = slots[key];
		if (!slot) return;
		if (!list.length) {
			panel.innerHTML = '<div class="gdld-empty">No dealers currently carry this UPC.</div>';
			return;
		}
		var currentId = slot.preferred_dealer_id ? parseInt(slot.preferred_dealer_id, 10) : 0;
		var html = '';
		list.forEach(function(d) {
			var isPreferred = currentId > 0 && parseInt(d.dealer_id, 10) === currentId;
			var isCheapestDefault = currentId === 0 && d === list[0];
			var badgeClass = (isPreferred || isCheapestDefault) ? ' gdld-row--preferred' : '';
			var pp = (d.price !== null && d.price !== undefined) ? parseFloat(d.price) : null;
			var priceStr = pp !== null ? '$' + pp.toFixed(2) : '—';
			var stock = d.in_stock ? '<span style="color:#166534">In stock' + (d.stock_qty ? ' (' + d.stock_qty + ')' : '') + '</span>' : '<span style="color:#991b1b">Out of stock</span>';
			var ship = escapeHtml(d.shipping_info || '');
			var cond = escapeHtml(d.condition || 'new');
			var actions = '';
			if (d.url) {
				actions += '<a class="gdld-btn" href="' + escapeAttr(d.url) + '" target="_blank" rel="noopener noreferrer">View</a>';
			}
			actions += '<button type="button" class="gdld-btn gdld-btn--primary" data-gdld-select="' + escapeAttr(d.dealer_id) + '" data-gdld-key="' + escapeAttr(key) + '">' + (isPreferred ? 'Selected' : 'Select') + '</button>';
			html += '<div class="gdld-row' + badgeClass + '">'
				+ '<div>'
				+ '<div class="gdld-name">' + escapeHtml(d.dealer_name || ('dealer ' + d.dealer_id)) + '</div>'
				+ '<div class="gdld-meta"><strong>' + priceStr + '</strong> · ' + (ship || '—') + ' · ' + stock + ' · ' + cond + '</div>'
				+ '</div>'
				+ '<div class="gdld-actions">' + actions + '</div>'
				+ '</div>';
		});
		if (currentId > 0) {
			html += '<div style="margin-top:8px;text-align:right">'
				+ '<button type="button" class="gdld-btn" data-gdld-select="0" data-gdld-key="' + escapeAttr(key) + '">Reset to cheapest</button>'
				+ '</div>';
		}
		panel.innerHTML = html;

		var buttons = panel.querySelectorAll('button[data-gdld-select]');
		buttons.forEach(function(b) {
			b.addEventListener('click', function(ev) {
				ev.stopPropagation();
				var did = parseInt(b.getAttribute('data-gdld-select'), 10) || 0;
				var kk  = b.getAttribute('data-gdld-key');
				selectDealer(kk, did);
			});
		});
	}

	function selectDealer(key, dealerId) {
		var slot = slots[key];
		if (!slot) return;
		if (dealerId > 0) {
			var list = gdloDealerCache[slot.upc] || [];
			var chosen = null;
			for (var i = 0; i < list.length; i++) {
				if (parseInt(list[i].dealer_id, 10) === dealerId) { chosen = list[i]; break; }
			}
			slot.preferred_dealer_id = dealerId;
			slot.preferred_dealer = chosen ? {
				dealer_id: chosen.dealer_id,
				dealer_name: chosen.dealer_name,
				price: chosen.price,
				shipping_info: chosen.shipping_info,
				in_stock: chosen.in_stock,
				stock_qty: chosen.stock_qty,
				condition: chosen.condition,
				listing_url: chosen.url || ''
			} : null;
			if (chosen && chosen.price !== null && chosen.price !== undefined) {
				slot.price = parseFloat(chosen.price);
			}
		} else {
			slot.preferred_dealer_id = null;
			slot.preferred_dealer = null;
		}

		if (slot.item_id > 0 && setItemDealerUrl) {
			var body = 'csrfKey=' + encodeURIComponent(csrfKey)
				+ '&item_id=' + encodeURIComponent(slot.item_id)
				+ '&dealer_id=' + encodeURIComponent(dealerId);
			fetch(setItemDealerUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
					'X-Requested-With': 'XMLHttpRequest'
				},
				body: body
			}).catch(function(){ /* Silent — save() will reconcile. */ });
		}

		if (currentStep === 2) renderCoreGrid();
		if (currentStep === 3) { renderAccGrid(); renderExtraGrid(); }
		updateAllSummaries();
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
					custom_label: it.custom_label || 'Extra', image: it.image_url || '',
					compliance: it.compliance || null,
					dealer_count: (it.dealer_count !== undefined) ? parseInt(it.dealer_count, 10) : 0,
					preferred_dealer_id: it.preferred_dealer_id ? parseInt(it.preferred_dealer_id, 10) : null,
					preferred_dealer: it.preferred_dealer || null,
					item_id: it.id ? parseInt(it.id, 10) : 0
				};
				if (it.notes) itemNotes[ek] = it.notes;
			} else {
				ensureSlot(key);
				slots[key].upc   = it.upc || '';
				slots[key].title = it.title || it.custom_label || key;
				slots[key].price = (it.price_snapshot !== undefined && it.price_snapshot !== null) ? it.price_snapshot : null;
				slots[key].image = it.image_url || '';
				slots[key].compliance = it.compliance || null;
				slots[key].dealer_count = (it.dealer_count !== undefined) ? parseInt(it.dealer_count, 10) : 0;
				slots[key].preferred_dealer_id = it.preferred_dealer_id ? parseInt(it.preferred_dealer_id, 10) : null;
				slots[key].preferred_dealer = it.preferred_dealer || null;
				slots[key].item_id = it.id ? parseInt(it.id, 10) : 0;
				if (it.notes) itemNotes[key] = it.notes;
			}
		}
	}

	setMode(buildMode);
	initPlatformChips();
	initFromExisting();

	if (suggestMode) {
		Object.keys(slots).forEach(function(k) {
			if (slots[k] && slots[k].upc) {
				originalSlots[k] = { upc: slots[k].upc, title: slots[k].title || '' };
			}
		});

		var bar = document.createElement('div');
		bar.className = 'gdlo-suggest-mode-bar';
		bar.innerHTML = '<i class="fa-solid fa-lightbulb" aria-hidden="true"></i> You are suggesting changes to this loadout. Swap any slots, then submit your suggestion.';
		var wiz = document.getElementById('gdLoadoutBuilder');
		if (wiz) wiz.insertBefore(bar, wiz.firstChild);

		var modeCards = document.querySelectorAll('#gdModeGrid .gdlo-mode-card');
		for (var mc = 0; mc < modeCards.length; mc++) {
			modeCards[mc].classList.add('gdlo-mode-locked');
			modeCards[mc].setAttribute('aria-disabled', 'true');
		}
	}

	function isSlotChanged(key) {
		if (!suggestMode) return false;
		var orig = originalSlots[key];
		var cur = slots[key];
		if (!orig && cur && cur.upc) return true;
		if (orig && (!cur || !cur.upc)) return true;
		if (orig && cur && orig.upc !== cur.upc) return true;
		return false;
	}

	function getChangedSlots() {
		var changes = [];
		Object.keys(slots).forEach(function(k) {
			if (isSlotChanged(k) && slots[k] && slots[k].upc) {
				changes.push({ slot: slots[k].type || k, upc: slots[k].upc });
			}
		});
		return changes;
	}

	/* ===== Apply Suggestion ===== */
	var acceptSuggestionId = null;
	(function applySuggestion() {
		var params = new URLSearchParams(window.location.search);
		var rawAcceptId = params.get('accept_suggestion_id');
		if (rawAcceptId) acceptSuggestionId = rawAcceptId;

		var applyChangesRaw = params.get('apply_changes');
		if (applyChangesRaw && loadoutId) {
			var applyChanges;
			try { applyChanges = JSON.parse(applyChangesRaw); } catch(e) { applyChanges = null; }
			if (Array.isArray(applyChanges) && applyChanges.length > 0) {
				var upcsToFetch = [];
				for (var ci = 0; ci < applyChanges.length; ci++) {
					var ch = applyChanges[ci];
					if (ch.slot && ch.upc) {
						ensureSlot(ch.slot);
						slots[ch.slot].upc = ch.upc;
						slots[ch.slot].title = 'Loading...';
						slots[ch.slot].price = null;
						slots[ch.slot].image = '';
						slots[ch.slot].suggested = true;
						upcsToFetch.push(ch.upc);
					}
				}
				if (upcsToFetch.length > 0) {
					var fetchPromises = upcsToFetch.map(function(upc) {
						var sep = searchUrl.indexOf('?') === -1 ? '?' : '&';
						return fetch(searchUrl + sep + 'q=' + encodeURIComponent(upc), { credentials: 'same-origin' })
							.then(function(r) { return r.json(); })
							.then(function(data) {
								var results = data.results || [];
								for (var ri = 0; ri < results.length; ri++) {
									if (results[ri].upc === upc) {
										Object.keys(slots).forEach(function(sk) {
											if (slots[sk] && slots[sk].upc === upc) {
												slots[sk].title = results[ri].title || upc;
												slots[sk].price = results[ri].best_price || null;
												slots[sk].image = results[ri].image_url || '';
											}
										});
										break;
									}
								}
							})
							.catch(function() {});
					});
					Promise.all(fetchPromises).then(function() {
						renderCoreGrid();
						renderAccGrid();
						renderExtraGrid();
						updateAllSummaries();
					});
				}
				return;
			}
		}

		var applySlot = params.get('apply_slot');
		var applyUpc = params.get('apply_upc');
		if (!applySlot || !applyUpc || !loadoutId) return;

		ensureSlot(applySlot);
		slots[applySlot].upc = applyUpc;
		slots[applySlot].title = 'Loading...';
		slots[applySlot].price = null;
		slots[applySlot].image = '';
		slots[applySlot].suggested = true;

		var sep = searchUrl.indexOf('?') === -1 ? '?' : '&';
		fetch(searchUrl + sep + 'q=' + encodeURIComponent(applyUpc), { credentials: 'same-origin' })
			.then(function(r) { return r.json(); })
			.then(function(data) {
				var results = data.results || [];
				for (var i = 0; i < results.length; i++) {
					if (results[i].upc === applyUpc) {
						slots[applySlot].title = results[i].title || applyUpc;
						slots[applySlot].price = results[i].best_price || null;
						slots[applySlot].image = results[i].image_url || '';
						break;
					}
				}
				renderCoreGrid();
				renderAccGrid();
				renderExtraGrid();
				updateAllSummaries();
			})
			.catch(function() {});
	})();

	/* ===== Success Modal ===== */
	function showSuccessModal() {
		var existing = document.getElementById('gdSuccessModal');
		if (existing) existing.remove();

		var modal = document.createElement('div');
		modal.className = 'gdlo-success-modal';
		modal.id = 'gdSuccessModal';

		var backdrop = document.createElement('div');
		backdrop.className = 'gdlo-success-backdrop';

		var box = document.createElement('div');
		box.className = 'gdlo-success-box';
		box.innerHTML = '<div class="gdlo-success-icon"><i class="fa-solid fa-circle-check"></i></div>'
			+ '<div class="gdlo-success-title">' + (init.lang_suggest_submitted_title || 'Suggestion Submitted!') + '</div>'
			+ '<div class="gdlo-success-desc">' + (init.lang_suggest_submitted_desc || 'The loadout owner will be notified of your suggested changes.') + '</div>'
			+ '<button type="button" class="gdlo-btn gdlo-btn--primary gdlo-success-ok">OK</button>';

		modal.appendChild(backdrop);
		modal.appendChild(box);
		document.body.appendChild(modal);

		function dismiss() {
			modal.remove();
			window.history.back();
		}
		box.querySelector('.gdlo-success-ok').addEventListener('click', dismiss);
		backdrop.addEventListener('click', dismiss);
	}

	/* ===== Submit Suggestion ===== */
	if (submitSugBtn && suggestMode) {
		submitSugBtn.addEventListener('click', function() {
			var changes = getChangedSlots();
			if (changes.length === 0) {
				alert('Make at least one change before submitting.');
				return;
			}

			submitSugBtn.disabled = true;
			submitSugBtn.textContent = 'Submitting...';

			var body = new FormData();
			body.append('csrfKey', csrfKey);
			body.append('loadout_id', loadoutId);
			body.append('changes', JSON.stringify(changes));
			body.append('message', suggestNoteInput ? suggestNoteInput.value : '');

			fetch(submitSuggestionUrl, {
				method: 'POST', body: body,
				headers: { 'X-Requested-With': 'XMLHttpRequest' }
			})
			.then(function(resp) { return resp.json(); })
			.then(function(data) {
				submitSugBtn.disabled = false;
				submitSugBtn.textContent = 'Submit Suggestion';
				if (data && data.error) { alert(data.error); return; }
				if (data && data.ok) {
					try { showSuccessModal(); }
					catch (e) { alert('Suggestion submitted.'); window.history.back(); }
					return;
				}
				alert('Suggestion submitted.');
			})
			.catch(function() {
				submitSugBtn.disabled = false;
				submitSugBtn.textContent = 'Submit Suggestion';
				alert('Failed — please try again.');
			});
		});
	}

	/* v1.0.65 — AJAX state selector. Change or Apply → POST the
	   new state to setComplianceState (cookie writer, returns
	   JSON in AJAX mode), then batch-recheck compliance for
	   every filled slot's UPC via complianceCheck, patch
	   slots[].compliance in place, and re-render slot cards +
	   summary. No navigation. */
	(function wireComplianceStateSelector() {
		var sel     = document.getElementById('gdlc-state');
		var applyBt = document.getElementById('gdlc-apply');
		var status  = document.getElementById('gdlc-status');
		if (!sel || !setStateUrl) return;

		function fire() {
			var newState = (sel.value || '').toUpperCase();
			if (status) status.textContent = 'Updating…';
			if (applyBt) applyBt.disabled = true;

			var body = 'state=' + encodeURIComponent(newState)
				+ '&csrfKey=' + encodeURIComponent(csrfKey);

			fetch(setStateUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
					'X-Requested-With': 'XMLHttpRequest'
				},
				body: body
			})
			.then(function(r){ return r.json(); })
			.then(function(){
				currentComplianceState = newState;
				return recheckFilledSlots(newState);
			})
			.then(function(){
				if (status) status.textContent = '';
				if (applyBt) applyBt.disabled = false;
			})
			.catch(function(){
				if (status) status.textContent = 'Failed — try again';
				if (applyBt) applyBt.disabled = false;
			});
		}

		sel.addEventListener('change', fire);
		if (applyBt) applyBt.addEventListener('click', fire);
	})();

	function recheckFilledSlots(newState) {
		var filled = getFilledSlots();
		var upcs = [];
		var byUpc = {};
		for (var i = 0; i < filled.length; i++) {
			var u = filled[i].slot && filled[i].slot.upc;
			if (u) { upcs.push(u); if (!byUpc[u]) byUpc[u] = []; byUpc[u].push(filled[i].key); }
		}
		if (!complianceCheckUrl || upcs.length === 0) {
			applyComplianceUpdate({}, newState);
			return Promise.resolve();
		}

		var body = 'state=' + encodeURIComponent(newState)
			+ '&csrfKey=' + encodeURIComponent(csrfKey);
		upcs.forEach(function(u){ body += '&upcs%5B%5D=' + encodeURIComponent(u); });

		return fetch(complianceCheckUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
				'X-Requested-With': 'XMLHttpRequest'
			},
			body: body
		})
		.then(function(r){ return r.json(); })
		.then(function(data){
			applyComplianceUpdate((data && data.results) || {}, newState);
		})
		.catch(function(){
			/* On a failure, still re-render with the empty results
			   so at least the state label / summary updates and
			   filled badges clear rather than showing stale info. */
			applyComplianceUpdate({}, newState);
		});
	}

	function applyComplianceUpdate(resultsByUpc, newState) {
		Object.keys(slots).forEach(function(k) {
			var s = slots[k];
			if (!s || !s.upc) return;
			var fresh = resultsByUpc[s.upc];
			if (fresh) {
				s.compliance = fresh;
			} else if (s.compliance) {
				/* No fresh result → clear per-state flags but keep
				   the state code so the empty-state banner renders
				   correctly for the newly-selected state. */
				s.compliance = {
					state: newState,
					restricted_here: false,
					advisory_here: false,
					reason_here: '',
					restricted_count: s.compliance.restricted_count || 0,
					restricted_codes: s.compliance.restricted_codes || []
				};
			}
		});

		if (currentStep === 2) renderCoreGrid();
		if (currentStep === 3) { renderAccGrid(); renderExtraGrid(); }
		updateAllSummaries();
	}

	updateModeLock();
	goToStep(1);
})();
