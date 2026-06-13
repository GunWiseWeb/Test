document.addEventListener('DOMContentLoaded', function() {
    var picker = document.querySelector('.gdDealsPicker');
    if (!picker) return;

    var searchUrl = picker.getAttribute('data-search-url');
    if (!searchUrl) return;

    var input = document.getElementById('gdDealsPickerInput');
    var results = document.getElementById('gdDealsPickerResults');
    var hiddenUpc = document.getElementById('gdDealsPickerUpc');
    var selectedWrap = document.getElementById('gdDealsPickerSelected');
    var selectedText = document.getElementById('gdDealsPickerSelectedText');
    var clearBtn = document.getElementById('gdDealsPickerClear');
    var searchWrap = document.getElementById('gdDealsPickerSearchWrap');

    if (!input || !results || !hiddenUpc || !selectedWrap || !selectedText || !clearBtn || !searchWrap) return;

    var debounceTimer = null;

    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            var rows = results.querySelectorAll('.gdDealsPicker__resultRow');
            if (rows.length === 1) {
                rows[0].click();
            }
        }
    });

    input.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        var val = input.value.trim();
        if (val.length < 2) {
            results.innerHTML = '';
            results.style.display = 'none';
            return;
        }
        debounceTimer = setTimeout(function() {
            var sep = searchUrl.indexOf('?') === -1 ? '?' : '&';
            fetch(searchUrl + sep + 'q=' + encodeURIComponent(val), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                results.innerHTML = '';
                if (!data.results || data.results.length === 0) {
                    var noRow = document.createElement('div');
                    noRow.className = 'gdDealsPicker__noResults';
                    noRow.textContent = 'No products found';
                    results.appendChild(noRow);
                    results.style.display = 'block';
                    return;
                }
                data.results.forEach(function(item) {
                    var row = document.createElement('div');
                    row.className = 'gdDealsPicker__resultRow';
                    var titleSpan = document.createElement('span');
                    titleSpan.className = 'gdDealsPicker__resultTitle';
                    titleSpan.textContent = item.title;
                    var priceSpan = document.createElement('span');
                    priceSpan.className = 'gdDealsPicker__resultPrice';
                    priceSpan.textContent = item.price;
                    row.appendChild(titleSpan);
                    row.appendChild(priceSpan);
                    row.addEventListener('click', function() {
                        hiddenUpc.value = item.upc;
                        selectedText.textContent = item.title + ' — ' + item.price;
                        selectedWrap.style.display = '';
                        searchWrap.style.display = 'none';
                        results.innerHTML = '';
                        results.style.display = 'none';
                        input.value = '';
                    });
                    results.appendChild(row);
                });
                results.style.display = 'block';
            })
            .catch(function() {
                results.innerHTML = '';
                results.style.display = 'none';
            });
        }, 250);
    });

    clearBtn.addEventListener('click', function() {
        hiddenUpc.value = '';
        selectedWrap.style.display = 'none';
        searchWrap.style.display = '';
        input.value = '';
        input.focus();
        results.innerHTML = '';
        results.style.display = 'none';
    });

    document.addEventListener('click', function(e) {
        if (!picker.contains(e.target)) {
            results.innerHTML = '';
            results.style.display = 'none';
        }
    });
});
