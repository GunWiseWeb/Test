document.addEventListener('DOMContentLoaded', function() {
	var params = new URLSearchParams(window.location.search);
	var hash = window.location.hash.replace('#', '');

	var tabMap = {deals: 'gdTabDeals', coupons: 'gdTabCoupons', listings: 'gdTabListings', reviews: 'gdTabReviews', info: 'gdTabInfo'};
	var target = null;

	if (tabMap[hash]) {
		target = tabMap[hash];
	} else if (params.has('sort') || params.has('stars') || params.has('dispute')) {
		target = 'gdTabReviews';
	}

	if (target) {
		var radio = document.getElementById(target);
		if (radio) { radio.checked = true; }
	}
});
