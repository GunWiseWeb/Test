document.addEventListener('DOMContentLoaded', function () {
    var master = document.getElementById('gdMasterCheckbox');
    var bulkBar = document.getElementById('gdBulkBar');
    var bulkCount = document.getElementById('gdBulkCount');
    var bulkMode = document.getElementById('gdBulkMode');
    var bulkAllWrap = document.getElementById('gdBulkAllWrap');
    var bulkAllLabel = document.getElementById('gdBulkAllLabel');
    var selectAllLink = document.getElementById('gdBulkSelectAll');
    var table = document.getElementById('gdBulkTable');

    if (!master || !bulkBar || !table) return;

    var totalMatching = parseInt(table.getAttribute('data-total-matching') || '0', 10);

    function getCheckboxes() {
        return table.querySelectorAll('.gdBulkCheckbox');
    }

    function getCheckedCount() {
        var boxes = getCheckboxes();
        var n = 0;
        for (var i = 0; i < boxes.length; i++) {
            if (boxes[i].checked) n++;
        }
        return n;
    }

    function sync() {
        var count = getCheckedCount();
        var boxes = getCheckboxes();

        bulkCount.textContent = count;
        bulkBar.style.display = count > 0 ? 'flex' : 'none';

        var allPageChecked = count === boxes.length && boxes.length > 0;
        master.checked = allPageChecked;
        master.indeterminate = count > 0 && !allPageChecked;

        if (allPageChecked && totalMatching > boxes.length) {
            bulkAllWrap.style.display = '';
        } else {
            bulkAllWrap.style.display = 'none';
            bulkAllLabel.style.display = 'none';
            bulkMode.value = 'selected';
        }
    }

    master.addEventListener('change', function () {
        var boxes = getCheckboxes();
        for (var i = 0; i < boxes.length; i++) {
            boxes[i].checked = master.checked;
        }
        if (!master.checked) {
            bulkMode.value = 'selected';
            bulkAllLabel.style.display = 'none';
        }
        sync();
    });

    table.addEventListener('change', function (e) {
        if (e.target.classList.contains('gdBulkCheckbox')) {
            sync();
        }
    });

    if (selectAllLink) {
        selectAllLink.addEventListener('click', function (e) {
            e.preventDefault();
            bulkMode.value = 'all';
            bulkCount.textContent = totalMatching;
            selectAllLink.style.display = 'none';
            bulkAllLabel.style.display = '';
        });
    }
});
