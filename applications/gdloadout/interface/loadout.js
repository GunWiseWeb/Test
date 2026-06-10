(function() {
    'use strict';

    var container = document.getElementById('gdloView');
    if (!container) return;

    var initData;
    try { initData = JSON.parse(container.getAttribute('data-init')); } catch(e) { return; }

    var loadoutId = initData.loadoutId || 0;
    var upvoteUrl = initData.upvoteUrl || '';
    var followUrl = initData.followUrl || '';
    var wishlistUrl = initData.wishlistUrl || '';
    var alertUrl = initData.alertUrl || '';
    var suggestUrl = initData.suggestUrl || '';
    var searchUrl = initData.searchUrl || '';
    var csrfKey = initData.csrfKey || '';
    var hasVoted = initData.hasVoted || false;
    var hasFollowed = initData.hasFollowed || false;
    var isLoggedIn = initData.isLoggedIn || false;
    var canSuggest = initData.canSuggest || false;
    var filledSlots = initData.filledSlots || {};

    var upvoteBtn = document.getElementById('gdloUpvoteBtn');
    var upvoteCount = document.getElementById('gdloUpvoteCount');
    var followBtn = document.getElementById('gdloFollowBtn');
    var followCount = document.getElementById('gdloFollowCount');
    var wishlistBtn = document.getElementById('gdloWishlistBtn');
    var alertBtn = document.getElementById('gdloAlertBtn');

    function postAction(url, extraData, callback) {
        if (!isLoggedIn) { alert('Please log in'); return; }
        var body = new FormData();
        body.append('csrfKey', csrfKey);
        body.append('loadout_id', loadoutId);
        if (extraData) {
            Object.keys(extraData).forEach(function(k) { body.append(k, extraData[k]); });
        }
        fetch(url, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function(r) { return r.json(); })
            .then(callback)
            .catch(function() { alert('Network error'); });
    }

    if (upvoteBtn) {
        upvoteBtn.addEventListener('click', function() {
            postAction(upvoteUrl, null, function(data) {
                if (data.ok) {
                    hasVoted = data.voted;
                    upvoteCount.textContent = data.count;
                    upvoteBtn.classList.toggle('gdlo-btn--voted', hasVoted);
                }
            });
        });
    }

    if (followBtn) {
        followBtn.addEventListener('click', function() {
            postAction(followUrl, null, function(data) {
                if (data.ok) {
                    hasFollowed = data.followed;
                    followCount.textContent = data.count;
                    followBtn.classList.toggle('gdlo-btn--followed', hasFollowed);
                }
            });
        });
    }

    if (wishlistBtn) {
        wishlistBtn.addEventListener('click', function() {
            postAction(wishlistUrl, null, function(data) {
                if (data.ok) {
                    wishlistBtn.textContent = 'Added ' + data.added + ' items';
                    wishlistBtn.disabled = true;
                }
            });
        });
    }

    if (alertBtn) {
        alertBtn.addEventListener('click', function() {
            postAction(alertUrl, null, function(data) {
                if (data.ok) {
                    alertBtn.textContent = 'Alerts set for ' + data.set + ' items';
                    alertBtn.disabled = true;
                }
            });
        });
    }

    /* ===== Suggestion Form ===== */
    if (canSuggest && suggestUrl) {
        var sugSlotSel = document.getElementById('gdloSuggestSlot');
        var sugSearch = document.getElementById('gdloSuggestSearch');
        var sugResults = document.getElementById('gdloSuggestResults');
        var sugSelected = document.getElementById('gdloSuggestSelected');
        var sugMessage = document.getElementById('gdloSuggestMessage');
        var sugSubmit = document.getElementById('gdloSuggestSubmit');
        var sugStatus = document.getElementById('gdloSuggestStatus');
        var selectedUpc = '';
        var searchTimer = null;

        if (sugSlotSel) {
            var slotKeys = Object.keys(filledSlots);
            for (var i = 0; i < slotKeys.length; i++) {
                var opt = document.createElement('option');
                opt.value = slotKeys[i];
                opt.textContent = slotKeys[i].replace(/_/g, ' ') + ' — ' + filledSlots[slotKeys[i]];
                sugSlotSel.appendChild(opt);
            }
        }

        function escHtml(s) {
            var d = document.createElement('div');
            d.appendChild(document.createTextNode(s || ''));
            return d.innerHTML;
        }

        if (sugSearch) {
            sugSearch.addEventListener('input', function() {
                var q = sugSearch.value.trim();
                if (searchTimer) clearTimeout(searchTimer);
                if (q.length < 2) { sugResults.style.display = 'none'; return; }
                searchTimer = setTimeout(function() {
                    var sep = searchUrl.indexOf('?') === -1 ? '?' : '&';
                    fetch(searchUrl + sep + 'q=' + encodeURIComponent(q), { credentials: 'same-origin' })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            var results = data.results || [];
                            if (!results.length) { sugResults.style.display = 'none'; return; }
                            var html = '';
                            for (var j = 0; j < Math.min(results.length, 8); j++) {
                                var r = results[j];
                                var img = r.image_url
                                    ? '<img src="' + escHtml(r.image_url) + '" class="gdlo-suggest-result-img" />'
                                    : '<div class="gdlo-suggest-result-img-ph"><i class="fa-solid fa-cube"></i></div>';
                                var price = r.best_price ? '$' + parseFloat(r.best_price).toFixed(2) : '';
                                html += '<div class="gdlo-suggest-result-item" data-upc="' + escHtml(r.upc) + '" data-title="' + escHtml(r.title) + '" data-image="' + escHtml(r.image_url || '') + '" data-price="' + escHtml(price) + '">'
                                    + img
                                    + '<div class="gdlo-suggest-result-info"><div class="gdlo-suggest-result-title">' + escHtml(r.title) + '</div>'
                                    + '<div class="gdlo-suggest-result-meta">' + escHtml(r.brand || '') + (price ? ' &mdash; ' + price : '') + '</div></div></div>';
                            }
                            sugResults.innerHTML = html;
                            sugResults.style.display = '';
                        })
                        .catch(function() {});
                }, 300);
            });

            document.addEventListener('click', function(e) {
                if (sugResults && !sugResults.contains(e.target) && e.target !== sugSearch) {
                    sugResults.style.display = 'none';
                }
            });
        }

        if (sugResults) {
            sugResults.addEventListener('click', function(e) {
                var item = e.target.closest('.gdlo-suggest-result-item');
                if (!item) return;
                selectedUpc = item.dataset.upc || '';
                var title = item.dataset.title || selectedUpc;
                var image = item.dataset.image || '';
                var price = item.dataset.price || '';
                var imgHtml = image
                    ? '<img src="' + escHtml(image) + '" class="gdlo-suggest-sel-img" />'
                    : '<div class="gdlo-suggest-sel-img-ph"><i class="fa-solid fa-cube"></i></div>';
                sugSelected.innerHTML = imgHtml + '<div><strong>' + escHtml(title) + '</strong>' + (price ? ' &mdash; ' + price : '') + '</div>'
                    + '<button type="button" class="gdlo-suggest-sel-clear">&times;</button>';
                sugSelected.style.display = '';
                sugResults.style.display = 'none';
                sugSearch.value = '';

                sugSelected.querySelector('.gdlo-suggest-sel-clear').addEventListener('click', function() {
                    selectedUpc = '';
                    sugSelected.style.display = 'none';
                    sugSelected.innerHTML = '';
                });
            });
        }

        if (sugSubmit) {
            sugSubmit.addEventListener('click', function() {
                var slot = sugSlotSel ? sugSlotSel.value : '';
                if (!slot) { showSugStatus('Please pick a slot', true); return; }
                if (!selectedUpc) { showSugStatus('Please search and select a product', true); return; }
                sugSubmit.disabled = true;
                postAction(suggestUrl, {
                    slot_type: slot,
                    suggested_upc: selectedUpc,
                    message: sugMessage ? sugMessage.value : ''
                }, function(data) {
                    sugSubmit.disabled = false;
                    if (data.ok) {
                        showSugStatus('Suggestion sent! The owner will be notified.', false);
                        selectedUpc = '';
                        if (sugSelected) { sugSelected.style.display = 'none'; sugSelected.innerHTML = ''; }
                        if (sugMessage) sugMessage.value = '';
                    } else {
                        showSugStatus(data.error || 'Failed', true);
                    }
                });
            });
        }

        function showSugStatus(msg, isError) {
            if (!sugStatus) return;
            sugStatus.textContent = msg;
            sugStatus.className = 'gdlo-suggest-status' + (isError ? ' gdlo-suggest-status--error' : ' gdlo-suggest-status--ok');
            sugStatus.style.display = '';
            setTimeout(function() { sugStatus.style.display = 'none'; }, 5000);
        }
    }
})();
