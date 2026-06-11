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

    var sugToggle = document.getElementById('gdloSugBannerToggle');
    var sugBody = document.getElementById('gdloSugBannerBody');
    if (sugToggle && sugBody) {
        sugToggle.addEventListener('click', function() {
            var expanded = sugToggle.getAttribute('aria-expanded') === 'true';
            sugToggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            sugBody.hidden = expanded;
        });
    }

})();
