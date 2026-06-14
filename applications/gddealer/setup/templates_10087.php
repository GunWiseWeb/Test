<?php
namespace IPS\gddealer\setup;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) {
    header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
    exit;
}

$gddealerV10087Templates = [];

/* subscription */
$gddealerV10087Templates[] = [
    'set_id'            => 1,
    'app'               => 'gddealer',
    'location'          => 'front',
    'group'             => 'dealers',
    'template_name'     => 'subscription',
    'template_data'     => '$dealer, $sub, $billingNote, $tabUrls, $plans',
    'template_content'  => <<<'TEMPLATE_EOT'
<div class="gdPageHeader">
    <div class="gdPageHeader__titleBlock">
        <h1 class="gdPageHeader__title">Subscription</h1>
        <p class="gdPageHeader__sub">Manage your plan, see what's included, and change tiers.</p>
    </div>
</div>

<div class="gdPlanHero gdPlanHero--{$sub['tier']}">
    <div class="gdPlanHero__head">
        <div>
            <div class="gdPlanHero__label">Current plan</div>
            <div class="gdPlanHero__nameRow">
                <span class="gdPlanHero__name">{$sub['tier_label']}</span>
                <span class="gdTierBadge gdTierBadge--{$sub['tier']}">{$sub['tier_label']}</span>
            </div>
            <div class="gdPlanHero__meta">
                {{if $sub['tier'] === 'founding'}}Founding partner &middot; all features unlocked
                {{elseif $sub['trial_days_left'] !== null and $sub['trial_days_left'] > 0}}Trial &middot; {$sub['trial_days_left']} day{expression="$sub['trial_days_left'] === 1 ? '' : 's'"} left
                {{else}}Billed monthly through IPS Commerce &middot; Renews automatically{{endif}}
            </div>
        </div>
        <span class="gdPlanHero__status {expression="$sub['suspended'] ? 'is-suspended' : ($sub['active'] ? 'is-active' : 'is-inactive')"}">
            {{if $sub['suspended']}}Suspended{{elseif $sub['active']}}Active{{else}}Inactive{{endif}}
        </span>
    </div>

    <div class="gdPlanHero__stats">
        <div class="gdPlanHero__stat">
            <div class="gdPlanHero__statLabel">Monthly cost</div>
            <div class="gdPlanHero__statValue">{$sub['mrr']}</div>
            {{if $sub['trial_expires_formatted']}}
            <div class="gdPlanHero__statSub">Trial ends: {$sub['trial_expires_formatted']}</div>
            {{endif}}
        </div>
        <div class="gdPlanHero__stat">
            <div class="gdPlanHero__statLabel">Feed sync frequency</div>
            <div class="gdPlanHero__statValue">{$sub['sync_label']}</div>
            <div class="gdPlanHero__statSub">Based on your plan</div>
        </div>
        <div class="gdPlanHero__stat">
            <div class="gdPlanHero__statLabel">Dispute allowance</div>
            <div class="gdPlanHero__statValue">
                {{if $sub['tier'] === 'basic'}}2 / mo
                {{elseif $sub['tier'] === 'pro'}}5 / mo
                {{else}}Unlimited
                {{endif}}
            </div>
            <div class="gdPlanHero__statSub">Resets monthly</div>
        </div>
    </div>

    <div class="gdPlanHero__actions">
        <a href="{$sub['manage_url']}" class="gdBtn gdBtn--primary">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
            Manage subscription
        </a>
    </div>
</div>

{{if $sub['is_founding'] and $sub['trial_expires_formatted']}}
<div class="gdTrialNotice">
    <strong>Founding member —</strong> you have enterprise-level sync and unlimited listings until
    <strong>{$sub['trial_expires_formatted']}</strong>. After that, choose a paid plan to keep premium features. Your
    Founder badge is permanent.
</div>
{{endif}}

<div class="gdComparisonSection">
    <h2 class="gdComparisonSection__title">Change plan</h2>
    <p class="gdComparisonSection__sub">Upgrade for faster sync, more features, and priority support. Downgrades take effect at the end of your current billing period.</p>

    <div class="gdTierComparison">

        <div class="gdTierCol {expression="$sub['tier'] === 'basic' ? 'is-current' : ''"}">
            {{if $sub['tier'] === 'basic'}}<span class="gdTierCol__badge">Your plan</span>{{endif}}
            <div class="gdTierCol__head">
                <div class="gdTierCol__name">{$plans['basic']['name']}</div>
                <div class="gdTierCol__price"><span class="gdTierCol__priceNum">{$plans['basic']['price']}</span></div>
                <div class="gdTierCol__tagline">{$plans['basic']['tagline']}</div>
            </div>
            <ul class="gdTierCol__features">
                {{foreach $plans['basic']['features'] as $feature}}
                <li><span class="gdTierCol__check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>{$feature|raw}</li>
                {{endforeach}}
            </ul>
            {{if $sub['tier'] === 'basic'}}
            <span class="gdTierCol__cta is-current">Current plan</span>
            {{elseif $sub['tier'] === 'pro' or $sub['tier'] === 'enterprise' or $sub['tier'] === 'max' or $sub['tier'] === 'founding'}}
            <a href="{$sub['tier_buy_urls']['basic']}" class="gdTierCol__cta is-downgrade">Downgrade to {$plans['basic']['name']}</a>
            {{else}}
            <a href="{$sub['tier_buy_urls']['basic']}" class="gdTierCol__cta">Choose {$plans['basic']['name']}</a>
            {{endif}}
        </div>

        <div class="gdTierCol {expression="$sub['tier'] === 'pro' ? 'is-current' : ''"}">
            {{if $sub['tier'] === 'pro'}}<span class="gdTierCol__badge">Your plan</span>{{endif}}
            <div class="gdTierCol__head">
                <div class="gdTierCol__name">{$plans['pro']['name']}</div>
                <div class="gdTierCol__price"><span class="gdTierCol__priceNum">{$plans['pro']['price']}</span></div>
                <div class="gdTierCol__tagline">{$plans['pro']['tagline']}</div>
            </div>
            <ul class="gdTierCol__features">
                {{foreach $plans['pro']['features'] as $feature}}
                <li><span class="gdTierCol__check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>{$feature|raw}</li>
                {{endforeach}}
            </ul>
            {{if $sub['tier'] === 'pro'}}
            <span class="gdTierCol__cta is-current">Current plan</span>
            {{elseif $sub['tier'] === 'enterprise' or $sub['tier'] === 'max' or $sub['tier'] === 'founding'}}
            <a href="{$sub['tier_buy_urls']['pro']}" class="gdTierCol__cta is-downgrade">Downgrade to {$plans['pro']['name']}</a>
            {{else}}
            <a href="{$sub['tier_buy_urls']['pro']}" class="gdTierCol__cta is-upgrade">Upgrade to {$plans['pro']['name']}</a>
            {{endif}}
        </div>

        <div class="gdTierCol {expression="$sub['tier'] === 'enterprise' ? 'is-current' : ''"}">
            {{if $sub['tier'] === 'enterprise'}}<span class="gdTierCol__badge">Your plan</span>{{endif}}
            <div class="gdTierCol__head">
                <div class="gdTierCol__name">{$plans['enterprise']['name']}</div>
                <div class="gdTierCol__price"><span class="gdTierCol__priceNum">{$plans['enterprise']['price']}</span></div>
                <div class="gdTierCol__tagline">{$plans['enterprise']['tagline']}</div>
            </div>
            <ul class="gdTierCol__features">
                {{foreach $plans['enterprise']['features'] as $feature}}
                <li><span class="gdTierCol__check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>{$feature|raw}</li>
                {{endforeach}}
            </ul>
            {{if $sub['tier'] === 'enterprise'}}
            <span class="gdTierCol__cta is-current">Current plan</span>
            {{elseif $sub['tier'] === 'max'}}
            <a href="{$sub['tier_buy_urls']['enterprise']}" class="gdTierCol__cta is-downgrade">Downgrade to {$plans['enterprise']['name']}</a>
            {{elseif $sub['tier'] === 'founding'}}
            <span class="gdTierCol__cta is-current">Included in Founding</span>
            {{else}}
            <a href="{$sub['tier_buy_urls']['enterprise']}" class="gdTierCol__cta is-upgrade">Upgrade to {$plans['enterprise']['name']}</a>
            {{endif}}
        </div>

        <div class="gdTierCol {expression="$sub['tier'] === 'max' ? 'is-current' : ''"}">
            {{if $sub['tier'] === 'max'}}<span class="gdTierCol__badge">Your plan</span>{{endif}}
            <div class="gdTierCol__head">
                <div class="gdTierCol__name">{$plans['max']['name']}</div>
                <div class="gdTierCol__price"><span class="gdTierCol__priceNum">{$plans['max']['price']}</span></div>
                <div class="gdTierCol__tagline">{$plans['max']['tagline']}</div>
            </div>
            <ul class="gdTierCol__features">
                {{foreach $plans['max']['features'] as $feature}}
                <li><span class="gdTierCol__check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>{$feature|raw}</li>
                {{endforeach}}
            </ul>
            {{if $sub['tier'] === 'max'}}
            <span class="gdTierCol__cta is-current">Current plan</span>
            {{else}}
            <a href="{$sub['tier_buy_urls']['max']}" class="gdTierCol__cta is-upgrade">Upgrade to {$plans['max']['name']}</a>
            {{endif}}
        </div>

    </div>
</div>

{{if $billingNote}}
<div class="gdComparisonSection">
    <h2 class="gdComparisonSection__title">Billing notes</h2>
    <div class="gdPanel">
        <div style="font-size:13px;color:var(--gd-text);line-height:1.6;padding:16px 20px">{$billingNote|raw}</div>
    </div>
</div>
{{endif}}
TEMPLATE_EOT
];

/* dashboardCustomize */
$gddealerV10087Templates[] = [
    'set_id'            => 1,
    'app'               => 'gddealer',
    'location'          => 'front',
    'group'             => 'dealers',
    'template_name'     => 'dashboardCustomize',
    'template_data'     => '$prefs, $saveUrl, $cancelUrl, $csrfKey',
    'template_content'  => <<<'TEMPLATE_EOT'
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
TEMPLATE_EOT
];

foreach ( $gddealerV10087Templates as $tpl ) {
    $masterKey = md5( $tpl['app'] . ';' . $tpl['location'] . ';' . $tpl['group'] . ';' . $tpl['template_name'] );

    \IPS\Db::i()->replace( 'core_theme_templates', [
        'template_set_id'        => $tpl['set_id'],
        'template_app'           => $tpl['app'],
        'template_location'      => $tpl['location'],
        'template_group'         => $tpl['group'],
        'template_name'          => $tpl['template_name'],
        'template_data'          => $tpl['template_data'],
        'template_content'       => $tpl['template_content'],
        'template_master_key'    => $masterKey,
        'template_updated'       => time(),
    ] );
}

try { \IPS\Theme::master()->recompileTemplates(); } catch ( \Throwable ) {}
try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
try { \IPS\Data\Store::i()->clearAll(); } catch ( \Throwable ) {}
