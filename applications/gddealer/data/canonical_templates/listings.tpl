<div class="gdPageHeader">
    <div class="gdPageHeader__titleBlock">
        <h1 class="gdPageHeader__title">Listings</h1>
        <p class="gdPageHeader__sub">{expression="number_format($data['total'])"} products synced from your feed</p>
    </div>
    <div class="gdPageHeader__actions">
        <a href="{$data['export_url']}" class="gdBtn gdBtn--secondary">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Export CSV
        </a>
        <a href="{$data['tab_urls']['feedSettings']}" class="gdBtn gdBtn--primary">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10"/></svg>
            Run import now
        </a>
    </div>
</div>

<form method="get" class="gdFilterBar">
    <input type="hidden" name="app" value="gddealer">
    <input type="hidden" name="module" value="dealers">
    <input type="hidden" name="controller" value="dashboard">
    <input type="hidden" name="do" value="listings">
    {{if $data['active_filter'] !== 'all'}}<input type="hidden" name="filter" value="{$data['active_filter']}">{{endif}}
    <div class="gdFilterBar__search">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" name="q" placeholder="Search by UPC" value="{$data['search']}">
    </div>
    <div class="gdFilterTabs">
        <a href="{$data['filter_urls']['all']}" class="gdFilterTab {expression="$data['active_filter'] === 'all' ? 'is-active' : ''"}">
            All <span class="gdFilterTab__count">{expression="number_format($data['status_counts']['all'])"}</span>
        </a>
        <a href="{$data['filter_urls']['active']}" class="gdFilterTab {expression="$data['active_filter'] === 'active' ? 'is-active' : ''"}">
            Active <span class="gdFilterTab__count">{expression="number_format($data['status_counts']['active'])"}</span>
        </a>
        <a href="{$data['filter_urls']['out_of_stock']}" class="gdFilterTab {expression="$data['active_filter'] === 'out_of_stock' ? 'is-active' : ''"}">
            Out of stock <span class="gdFilterTab__count">{expression="number_format($data['status_counts']['out_of_stock'])"}</span>
        </a>
        {{if $data['status_counts']['suspended'] > 0}}
        <a href="{$data['filter_urls']['suspended']}" class="gdFilterTab {expression="$data['active_filter'] === 'suspended' ? 'is-active' : ''"}">
            Suspended <span class="gdFilterTab__count">{expression="number_format($data['status_counts']['suspended'])"}</span>
        </a>
        {{endif}}
        {{if $data['status_counts']['discontinued'] > 0}}
        <a href="{$data['filter_urls']['discontinued']}" class="gdFilterTab {expression="$data['active_filter'] === 'discontinued' ? 'is-active' : ''"}">
            Discontinued <span class="gdFilterTab__count">{expression="number_format($data['status_counts']['discontinued'])"}</span>
        </a>
        {{endif}}
    </div>
</form>

<div class="gdPanel gdPanel--tableShell">
    {{if count($data['rows']) === 0}}
    <div class="gdEmptyState">
        <p style="margin:0;color:var(--gd-text-subtle);font-size:14px">
            {{if $data['search'] !== '' or $data['active_filter'] !== 'all'}}
            No listings match your filters. <a href="{$data['filter_urls']['all']}" style="color:var(--gd-brand)">Clear filters &rarr;</a>
            {{else}}
            No listings yet. <a href="{$data['tab_urls']['feedSettings']}" style="color:var(--gd-brand)">Configure your feed &rarr;</a>
            {{endif}}
        </p>
    </div>
    {{else}}
    <table class="gdListingsTable">
        <thead>
            <tr>
                <th>UPC</th>
                <th class="is-num">Price</th>
                <th>Condition</th>
                <th>Status</th>
                <th class="is-num">Last updated</th>
            </tr>
        </thead>
        <tbody>
            {{foreach $data['rows'] as $row}}
            <tr>
                <td data-label="UPC"><span class="gdListingsTable__upc">{$row['upc']}</span></td>
                <td class="is-num" data-label="Price">{$row['dealer_price']}</td>
                <td data-label="Condition">{expression="ucfirst($row['condition'] ?: 'new')"}</td>
                <td data-label="Status">
                    {{if $row['listing_status'] === 'active' and $row['in_stock']}}
                    <span class="gdStatusPill gdStatusPill--active">In stock</span>
                    {{elseif $row['listing_status'] === 'active'}}
                    <span class="gdStatusPill gdStatusPill--outofstock">Out of stock</span>
                    {{elseif $row['listing_status'] === 'suspended'}}
                    <span class="gdStatusPill gdStatusPill--suspended">Suspended</span>
                    {{elseif $row['listing_status'] === 'discontinued'}}
                    <span class="gdStatusPill gdStatusPill--discontinued">Discontinued</span>
                    {{else}}
                    <span class="gdStatusPill gdStatusPill--muted">{expression="ucfirst($row['listing_status'])"}</span>
                    {{endif}}
                </td>
                <td class="is-num" data-label="Last updated" style="font-size:12px;color:var(--gd-text-subtle)">{$row['last_updated']}</td>
            </tr>
            {{endforeach}}
        </tbody>
    </table>
    {{endif}}
</div>

{{if $data['pages'] > 1}}
<div class="gdPagination">
    <div class="gdPagination__info">
        Showing {expression="number_format((($data['page'] - 1) * $data['per_page']) + 1)"}&ndash;{expression="number_format(min($data['page'] * $data['per_page'], $data['total']))"} of {expression="number_format($data['total'])"}
    </div>
    <div class="gdPagination__controls">
        {{if $data['page'] > 1}}
        <a href="{$data['base_url']}&page={expression="$data['page'] - 1"}" class="gdBtn gdBtn--secondary gdBtn--sm">Previous</a>
        {{endif}}
        <span class="gdPagination__page">Page {$data['page']} of {$data['pages']}</span>
        {{if $data['page'] < $data['pages']}}
        <a href="{$data['base_url']}&page={expression="$data['page'] + 1"}" class="gdBtn gdBtn--secondary gdBtn--sm">Next</a>
        {{endif}}
    </div>
</div>
{{endif}}