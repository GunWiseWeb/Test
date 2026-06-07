(function () {
	'use strict';

	var initEl = document.getElementById('gdlo-view-init');
	if (!initEl) return;

	var init;
	try { init = JSON.parse(initEl.textContent); } catch (e) { return; }

	var loadoutId  = init.loadoutId ? parseInt(init.loadoutId, 10) : 0;
	var upvoteUrl  = init.upvoteUrl || '';
	var followUrl  = init.followUrl || '';
	var commentUrl = init.commentUrl || '';
	var csrfKey    = init.csrfKey || '';
	var hasVoted   = !!init.hasVoted;
	var hasFollowed = !!init.hasFollowed;
	var isLoggedIn = !!init.isLoggedIn;

	var upvoteBtn   = document.getElementById('gdUpvoteBtn');
	var upvoteCount = document.getElementById('gdUpvoteCount');
	var followBtn   = document.getElementById('gdFollowBtn');
	var followLabel = document.getElementById('gdFollowLabel');
	var commentBtn  = document.getElementById('gdCommentBtn');
	var commentInput = document.getElementById('gdCommentInput');
	var commentsList = document.getElementById('gdCommentsList');

	function escapeHtml(str) {
		var d = document.createElement('div');
		d.appendChild(document.createTextNode(str || ''));
		return d.innerHTML;
	}

	function setActiveState(btn, active) {
		if (!btn) return;
		if (active) {
			btn.classList.add('ipsButton--primary');
			btn.classList.remove('ipsButton--light');
		} else {
			btn.classList.remove('ipsButton--primary');
			btn.classList.add('ipsButton--light');
		}
	}

	/* ------- Upvote toggle ------- */
	if (upvoteBtn) {
		setActiveState(upvoteBtn, hasVoted);

		upvoteBtn.addEventListener('click', function () {
			if (!isLoggedIn) {
				alert('Please log in to upvote loadouts.');
				return;
			}
			if (!upvoteUrl) return;

			upvoteBtn.disabled = true;

			var body = new FormData();
			body.append('csrfKey', csrfKey);
			body.append('loadout_id', loadoutId);

			fetch(upvoteUrl, {
				method: 'POST',
				body: body,
				headers: { 'X-Requested-With': 'XMLHttpRequest' }
			})
			.then(function (resp) { return resp.json(); })
			.then(function (data) {
				upvoteBtn.disabled = false;
				if (data.error) { alert(data.error); return; }

				hasVoted = !!data.voted;
				setActiveState(upvoteBtn, hasVoted);
				upvoteBtn.setAttribute('data-voted', hasVoted ? '1' : '0');

				if (upvoteCount && typeof data.count !== 'undefined') {
					upvoteCount.textContent = parseInt(data.count, 10);
				}
			})
			.catch(function () {
				upvoteBtn.disabled = false;
				alert('Upvote failed. Please try again.');
			});
		});
	}

	/* ------- Follow toggle ------- */
	if (followBtn) {
		setActiveState(followBtn, hasFollowed);

		followBtn.addEventListener('click', function () {
			if (!isLoggedIn) {
				alert('Please log in to follow loadouts.');
				return;
			}
			if (!followUrl) return;

			followBtn.disabled = true;

			var body = new FormData();
			body.append('csrfKey', csrfKey);
			body.append('loadout_id', loadoutId);

			fetch(followUrl, {
				method: 'POST',
				body: body,
				headers: { 'X-Requested-With': 'XMLHttpRequest' }
			})
			.then(function (resp) { return resp.json(); })
			.then(function (data) {
				followBtn.disabled = false;
				if (data.error) { alert(data.error); return; }

				hasFollowed = !!data.followed;
				setActiveState(followBtn, hasFollowed);
				followBtn.setAttribute('data-followed', hasFollowed ? '1' : '0');

				if (followLabel) {
					followLabel.textContent = hasFollowed ? 'Following' : 'Follow';
				}
			})
			.catch(function () {
				followBtn.disabled = false;
				alert('Follow failed. Please try again.');
			});
		});
	}

	/* ------- Post comment ------- */
	if (commentBtn && commentInput) {
		commentBtn.addEventListener('click', function () {
			if (!isLoggedIn) {
				alert('Please log in to post comments.');
				return;
			}
			if (!commentUrl) return;

			var text = commentInput.value.trim();
			if (!text) {
				commentInput.focus();
				return;
			}

			commentBtn.disabled = true;
			commentBtn.textContent = 'Posting...';

			var body = new FormData();
			body.append('csrfKey', csrfKey);
			body.append('loadout_id', loadoutId);
			body.append('comment_text', text);

			fetch(commentUrl, {
				method: 'POST',
				body: body,
				headers: { 'X-Requested-With': 'XMLHttpRequest' }
			})
			.then(function (resp) { return resp.json(); })
			.then(function (data) {
				commentBtn.disabled = false;
				commentBtn.textContent = 'Post Comment';

				if (data.error) { alert(data.error); return; }

				if (data.ok && commentsList) {
					var div = document.createElement('div');
					div.className = 'gdlo-comment';

					var meta = document.createElement('div');
					meta.className = 'gdlo-comment-meta';

					var strong = document.createElement('strong');
					strong.textContent = (data.comment && data.comment.member_name) || 'You';
					meta.appendChild(strong);

					var ts = document.createElement('span');
					ts.className = 'gdlo-search-meta';
					var now = new Date();
					var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
					ts.textContent = months[now.getMonth()] + ' ' + now.getDate() + ', ' + now.getFullYear();
					meta.appendChild(ts);

					div.appendChild(meta);

					var bodyDiv = document.createElement('div');
					bodyDiv.className = 'gdlo-comment-text';
					bodyDiv.innerHTML = escapeHtml(text).replace(/\n/g, '<br>');
					div.appendChild(bodyDiv);

					commentsList.appendChild(div);
					commentInput.value = '';
				}
			})
			.catch(function () {
				commentBtn.disabled = false;
				commentBtn.textContent = 'Post Comment';
				alert('Comment failed. Please try again.');
			});
		});
	}
})();
