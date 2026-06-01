(function() {
    var monitorEl = document.getElementById('gdcatalog-monitor');
    if (!monitorEl) return;

    var url = monitorEl.getAttribute('data-monitor-url');
    if (!url) {
        try { url = ips.getSetting('gdcatalog_monitor_url'); } catch(e) {}
    }
    if (!url) return;

    function update() {
        fetch(url)
            .then(function(r) { return r.json(); })
            .then(function(d) {
                var el = document.getElementById('gdcatalog-monitor');
                if (!el) return;

                document.getElementById('gdc-products').textContent = d.products.toLocaleString();
                document.getElementById('gdc-active').textContent = d.active.toLocaleString();
                document.getElementById('gdc-conflicts').textContent = d.pending_conflicts;
                document.getElementById('gdc-broken').textContent = d.broken_images;
                document.getElementById('gdc-no-image').textContent = d.no_image;
                document.getElementById('gdc-queue').textContent = d.queue_total;

                var jobsEl = document.getElementById('gdc-jobs');
                if (d.queue_jobs && d.queue_jobs.length > 0) {
                    var html = d.queue_jobs.map(function(j) {
                        return '<span class="ipsBadge ipsBadge--neutral" style="margin-right:4px">' + j.key + ' (offset: ' + j.offset.toLocaleString() + ')</span>';
                    }).join('');
                    jobsEl.innerHTML = html;
                } else {
                    jobsEl.innerHTML = '<span class="ipsBadge ipsBadge--positive">Queue empty</span>';
                }

                if (d.last_import && d.last_import.status) {
                    document.getElementById('gdc-last-import').textContent =
                        d.last_import.status + ' — ' +
                        d.last_import.created + ' created, ' +
                        d.last_import.updated + ' updated, ' +
                        d.last_import.errored + ' errored';
                }

                document.getElementById('gdc-updated').textContent = new Date().toLocaleTimeString();
            })
            .catch(function() {});
    }

    update();
    setInterval(update, 10000);
})();
