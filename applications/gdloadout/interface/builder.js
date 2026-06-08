(function() {
    'use strict';

    var container = document.getElementById('gdloBuilder');
    if (!container) return;

    var initData;
    try { initData = JSON.parse(container.getAttribute('data-init')); } catch(e) { console.error('gdloadout: failed to parse init data', e); return; }

    var loadout = initData.loadout || null;
    var existingItems = initData.items || [];
    var coreSlots = initData.coreSlots || {};
    var extraLib = initData.extraLib || {};
    var limits = initData.limits || {};
    var isVip = initData.isVip || false;
    var saveUrl = initData.saveUrl || '';
    var deleteUrl = initData.deleteUrl || '';
    var searchUrl = initData.searchUrl || '';
    var csrfKey = initData.csrfKey || '';

    var items = [];
    var searchTimeout = null;

    var nameInput = document.getElementById('gdloName');
    var descInput = document.getElementById('gdloDesc');
    var useCaseSelect = document.getElementById('gdloUseCase');
    var visibilitySelect = document.getElementById('gdloVisibility');
    var searchInput = document.getElementById('gdloSearch');
    var searchBtn = document.getElementById('gdloSearchBtn');
    var searchResults = document.getElementById('gdloSearchResults');
    var slotList = document.getElementById('gdloSlotList');
    var saveBtn = document.getElementById('gdloSaveBtn');
    var deleteBtn = document.getElementById('gdloDeleteBtn');

    function init() {
        if (loadout) {
            nameInput.value = loadout.name || '';
            descInput.value = loadout.description || '';
            useCaseSelect.value = loadout.use_case || '';
            visibilitySelect.value = loadout.visibility || 'unlisted';
            deleteBtn.style.display = '';
        }

        if (!isVip) {
            var privateOpt = visibilitySelect.querySelector('option[value="private"]');
            if (privateOpt) privateOpt.disabled = true;
        }

        existingItems.forEach(function(item) {
            items.push({
                upc: item.upc || '',
                slot_type: item.slot_type || 'extra',
                custom_label: item.custom_label || '',
                notes: item.notes || '',
                price: 0,
                title: item.custom_label || item.upc || '',
                brand: ''
            });
        });

        renderSlots();
        bindEvents();
    }

    function bindEvents() {
        searchBtn.addEventListener('click', doSearch);
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); doSearch(); }
        });
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(doSearch, 400);
        });
        saveBtn.addEventListener('click', doSave);
        deleteBtn.addEventListener('click', doDelete);
    }

    function doSearch() {
        var q = searchInput.value.trim();
        if (q.length < 2) { searchResults.innerHTML = ''; return; }

        fetch(searchUrl + '&q=' + encodeURIComponent(q), { method: 'GET', credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                renderSearchResults(data.results || []);
            })
            .catch(function() { searchResults.innerHTML = '<p class="gdlo-builder-no-results">Search failed</p>'; });
    }

    function renderSearchResults(results) {
        if (!results.length) {
            searchResults.innerHTML = '<p class="gdlo-builder-no-results">No products found</p>';
            return;
        }
        var html = '';
        results.forEach(function(r) {
            var priceStr = r.best_price ? ('$' + Number(r.best_price).toFixed(2)) : 'N/A';
            var stockBadge = r.in_stock ? '<span class="gdlo-badge gdlo-badge--instock">In Stock</span>' : '<span class="gdlo-badge gdlo-badge--oos">Out of Stock</span>';
            html += '<div class="gdlo-builder-result" data-upc="' + escHtml(r.upc) + '" data-title="' + escHtml(r.title) + '" data-brand="' + escHtml(r.brand) + '" data-price="' + (r.best_price || 0) + '">';
            html += '<div class="gdlo-builder-result-info"><strong>' + escHtml(r.title) + '</strong>';
            if (r.brand) html += ' <span class="gdlo-builder-result-brand">' + escHtml(r.brand) + '</span>';
            html += '</div>';
            html += '<div class="gdlo-builder-result-meta">' + priceStr + ' ' + stockBadge + '</div>';
            html += '<button type="button" class="gdlo-btn gdlo-btn--sm gdlo-btn--primary gdlo-builder-add-btn">+ Add</button>';
            html += '</div>';
        });
        searchResults.innerHTML = html;

        searchResults.querySelectorAll('.gdlo-builder-add-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var row = btn.closest('.gdlo-builder-result');
                addItem({
                    upc: row.getAttribute('data-upc'),
                    slot_type: 'extra',
                    custom_label: '',
                    notes: '',
                    price: parseFloat(row.getAttribute('data-price')) || 0,
                    title: row.getAttribute('data-title'),
                    brand: row.getAttribute('data-brand')
                });
            });
        });
    }

    function addItem(item) {
        if (limits.max_slots > 0 && items.length >= limits.max_slots) {
            alert('Slot limit reached (' + limits.max_slots + ')');
            return;
        }
        items.push(item);
        renderSlots();
    }

    function removeItem(idx) {
        items.splice(idx, 1);
        renderSlots();
    }

    function renderSlots() {
        if (!items.length) {
            slotList.innerHTML = '<p class="gdlo-builder-no-items">No items added yet. Search above to add products.</p>';
            return;
        }
        var html = '';
        items.forEach(function(item, idx) {
            var displayName = item.title || item.upc || 'Item';
            html += '<div class="gdlo-builder-slot" data-idx="' + idx + '">';
            html += '<div class="gdlo-builder-slot-grip"><i class="fa-solid fa-grip-vertical"></i></div>';
            html += '<div class="gdlo-builder-slot-info"><strong>' + escHtml(displayName) + '</strong>';
            if (item.brand) html += ' <span class="gdlo-builder-slot-brand">' + escHtml(item.brand) + '</span>';
            html += '</div>';
            html += '<select class="gdlo-input gdlo-builder-slot-type" data-idx="' + idx + '">';
            Object.keys(coreSlots).forEach(function(key) {
                var sel = item.slot_type === key ? ' selected' : '';
                html += '<option value="' + key + '"' + sel + '>' + escHtml(coreSlots[key].label) + '</option>';
            });
            html += '<option value="extra"' + (item.slot_type === 'extra' ? ' selected' : '') + '>Extra</option>';
            html += '</select>';
            html += '<button type="button" class="gdlo-btn gdlo-btn--sm gdlo-btn--danger gdlo-builder-remove-btn" data-idx="' + idx + '"><i class="fa-solid fa-xmark"></i></button>';
            html += '</div>';
        });
        slotList.innerHTML = html;

        slotList.querySelectorAll('.gdlo-builder-slot-type').forEach(function(sel) {
            sel.addEventListener('change', function() {
                var i = parseInt(sel.getAttribute('data-idx'), 10);
                if (items[i]) items[i].slot_type = sel.value;
            });
        });
        slotList.querySelectorAll('.gdlo-builder-remove-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                removeItem(parseInt(btn.getAttribute('data-idx'), 10));
            });
        });
    }

    function doSave() {
        var name = nameInput.value.trim();
        if (!name) { alert('Name is required'); nameInput.focus(); return; }

        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving...';

        var body = new FormData();
        body.append('csrfKey', csrfKey);
        body.append('loadout_name', name);
        body.append('loadout_description', descInput.value.trim());
        body.append('loadout_use_case', useCaseSelect.value);
        body.append('loadout_visibility', visibilitySelect.value);
        body.append('loadout_slots', JSON.stringify(items));
        if (loadout && loadout.id) body.append('loadout_id', loadout.id);

        fetch(saveUrl, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.ok && data.redirect) {
                    window.location = data.redirect;
                } else {
                    alert(data.error || 'Save failed');
                    saveBtn.disabled = false;
                    saveBtn.textContent = 'Save';
                }
            })
            .catch(function() {
                alert('Network error');
                saveBtn.disabled = false;
                saveBtn.textContent = 'Save';
            });
    }

    function doDelete() {
        if (!loadout || !loadout.id) return;
        if (!confirm('Delete this loadout? This cannot be undone.')) return;

        deleteBtn.disabled = true;
        var body = new FormData();
        body.append('csrfKey', csrfKey);
        body.append('loadout_id', loadout.id);

        fetch(deleteUrl, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.ok) {
                    window.location = '/loadouts/my-loadouts';
                } else {
                    alert(data.error || 'Delete failed');
                    deleteBtn.disabled = false;
                }
            })
            .catch(function() {
                alert('Network error');
                deleteBtn.disabled = false;
            });
    }

    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str || ''));
        return div.innerHTML;
    }

    init();
})();
