<div class="gdPageHeader">
    <div class="gdPageHeader__titleBlock">
        <h1 class="gdPageHeader__title">Customize dashboard</h1>
        <p class="gdPageHeader__sub">Pick which cards appear on your overview and choose a card color theme.</p>
    </div>
</div>

<form method="post" action="{$saveUrl}">
    <input type="hidden" name="csrfKey" value="{$csrfKey}">

    <div class="gdPanel">
        <div class="gdPanel__head">
            <div>
                <div class="gdPanel__title">Overview cards</div>
                <div class="gdPanel__sub">Toggle each card on or off. Changes apply next time you load Overview.</div>
            </div>
        </div>

        <div class="gdWidgetRows">
            <label class="gdWidgetRow">
                <span class="gdWidgetRow__icon" style="background:var(--gd-success-bg);color:var(--gd-success)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>
                <div class="gdWidgetRow__body">
                    <div class="gdWidgetRow__title">Active listings</div>
                    <div class="gdWidgetRow__desc">Total count of your active, in-stock listings</div>
                </div>
                <span class="gdToggle"><input type="checkbox" name="show_active" value="1" {expression="$prefs['show_active'] ? 'checked' : ''"}><span class="gdToggle__slider"></span></span>
            </label>

            <label class="gdWidgetRow">
                <span class="gdWidgetRow__icon" style="background:var(--gd-warn-bg);color:var(--gd-warn)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg></span>
                <div class="gdWidgetRow__body">
                    <div class="gdWidgetRow__title">Out of stock</div>
                    <div class="gdWidgetRow__desc">Listings currently marked out of stock by your feed</div>
                </div>
                <span class="gdToggle"><input type="checkbox" name="show_outofstock" value="1" {expression="$prefs['show_outofstock'] ? 'checked' : ''"}><span class="gdToggle__slider"></span></span>
            </label>

            <label class="gdWidgetRow">
                <span class="gdWidgetRow__icon" style="background:var(--gd-danger-bg);color:var(--gd-danger)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></span>
                <div class="gdWidgetRow__body">
                    <div class="gdWidgetRow__title">Unmatched UPCs</div>
                    <div class="gdWidgetRow__desc">Products from your feed we couldn't match to our catalog</div>
                </div>
                <span class="gdToggle"><input type="checkbox" name="show_unmatched" value="1" {expression="$prefs['show_unmatched'] ? 'checked' : ''"}><span class="gdToggle__slider"></span></span>
            </label>

            <label class="gdWidgetRow">
                <span class="gdWidgetRow__icon" style="background:var(--gd-brand-light);color:var(--gd-brand)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></span>
                <div class="gdWidgetRow__body">
                    <div class="gdWidgetRow__title">Clicks &mdash; 7 days</div>
                    <div class="gdWidgetRow__desc">Click-throughs to your listings in the last week</div>
                </div>
                <span class="gdToggle"><input type="checkbox" name="show_clicks_7d" value="1" {expression="$prefs['show_clicks_7d'] ? 'checked' : ''"}><span class="gdToggle__slider"></span></span>
            </label>

            <label class="gdWidgetRow">
                <span class="gdWidgetRow__icon" style="background:var(--gd-brand-light);color:var(--gd-brand)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></span>
                <div class="gdWidgetRow__body">
                    <div class="gdWidgetRow__title">Clicks &mdash; 30 days</div>
                    <div class="gdWidgetRow__desc">Click-throughs to your listings over the last month</div>
                </div>
                <span class="gdToggle"><input type="checkbox" name="show_clicks_30d" value="1" {expression="$prefs['show_clicks_30d'] ? 'checked' : ''"}><span class="gdToggle__slider"></span></span>
            </label>

            <label class="gdWidgetRow">
                <span class="gdWidgetRow__icon" style="background:var(--gd-surface-muted);color:var(--gd-text-muted)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg></span>
                <div class="gdWidgetRow__body">
                    <div class="gdWidgetRow__title">Last import</div>
                    <div class="gdWidgetRow__desc">Most recent feed import status and counts</div>
                </div>
                <span class="gdToggle"><input type="checkbox" name="show_last_import" value="1" {expression="$prefs['show_last_import'] ? 'checked' : ''"}><span class="gdToggle__slider"></span></span>
            </label>

            <label class="gdWidgetRow">
                <span class="gdWidgetRow__icon" style="background:var(--gd-surface-muted);color:var(--gd-text-muted)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg></span>
                <div class="gdWidgetRow__body">
                    <div class="gdWidgetRow__title">Profile URL</div>
                    <div class="gdWidgetRow__desc">Quick-copy link to your public dealer profile page</div>
                </div>
                <span class="gdToggle"><input type="checkbox" name="show_profile_url" value="1" {expression="$prefs['show_profile_url'] ? 'checked' : ''"}><span class="gdToggle__slider"></span></span>
            </label>
        </div>
    </div>

    <div class="gdPanel">
        <div class="gdPanel__head">
            <div>
                <div class="gdPanel__title">Card theme</div>
                <div class="gdPanel__sub">Pick the color scheme for your KPI cards on Overview.</div>
            </div>
        </div>

        <div class="gdThemePicker">
            <label class="gdThemeOption {expression="$prefs['card_theme'] === 'default' ? 'is-selected' : ''"}">
                <input type="radio" name="card_theme" value="default" {expression="$prefs['card_theme'] === 'default' ? 'checked' : ''"}>
                <div class="gdThemeOption__preview gdThemeOption__preview--default">
                    <div class="gdThemeOption__kpi">
                        <div class="gdThemeOption__label">Active</div>
                        <div class="gdThemeOption__value">1,234</div>
                    </div>
                </div>
                <div class="gdThemeOption__name">Default</div>
                <div class="gdThemeOption__desc">Clean and neutral</div>
            </label>

            <label class="gdThemeOption {expression="$prefs['card_theme'] === 'dark' ? 'is-selected' : ''"}">
                <input type="radio" name="card_theme" value="dark" {expression="$prefs['card_theme'] === 'dark' ? 'checked' : ''"}>
                <div class="gdThemeOption__preview gdThemeOption__preview--dark">
                    <div class="gdThemeOption__kpi">
                        <div class="gdThemeOption__label">Active</div>
                        <div class="gdThemeOption__value">1,234</div>
                    </div>
                </div>
                <div class="gdThemeOption__name">Dark</div>
                <div class="gdThemeOption__desc">High contrast</div>
            </label>

            <label class="gdThemeOption {expression="$prefs['card_theme'] === 'accent' ? 'is-selected' : ''"}">
                <input type="radio" name="card_theme" value="accent" {expression="$prefs['card_theme'] === 'accent' ? 'checked' : ''"}>
                <div class="gdThemeOption__preview gdThemeOption__preview--accent">
                    <div class="gdThemeOption__kpi">
                        <div class="gdThemeOption__label">Active</div>
                        <div class="gdThemeOption__value">1,234</div>
                    </div>
                </div>
                <div class="gdThemeOption__name">Accent</div>
                <div class="gdThemeOption__desc">Brand-colored</div>
            </label>
        </div>
    </div>

    <div class="gdCustomizeActions">
        <a href="{$cancelUrl}" class="gdBtn gdBtn--secondary">Cancel</a>
        <button type="submit" class="gdBtn gdBtn--primary">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            Save changes
        </button>
    </div>
</form>