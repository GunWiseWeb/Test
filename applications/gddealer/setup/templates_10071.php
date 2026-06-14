<?php
namespace IPS\gddealer\setup;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) {
    header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
    exit;
}

$gddealerV10071Templates = [];

/* dealerShell */
$gddealerV10071Templates[] = [
    'set_id'            => 1,
    'app'               => 'gddealer',
    'location'          => 'front',
    'group'             => 'dealers',
    'template_name'     => 'dealerShell',
    'template_data'     => '$dealer, $activeTab, $nav, $body, $suspended=false, $suspensionReason=\'\'',
    'template_content'  => <<<'TEMPLATE_EOT'
<div class="gdDealerApp">

    <div class="gdMobileBar">
        <div class="gdMobileBar__brand">
            <span class="gdSidebar__brandMark">GD</span>
            <div>
                <div class="gdSidebar__brandText">{$dealer['dealer_name']}</div>
                <div class="gdSidebar__brandSub">{$dealer['tier_label']}</div>
            </div>
        </div>
        <a href="#gdDrawer" class="gdMobileBar__menuBtn" aria-label="Open menu">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </a>
    </div>

    <div class="gdDrawer" id="gdDrawer">
        <div class="gdDrawer__panel">
            <a href="#" class="gdDrawer__close" aria-label="Close">&times;</a>
            {template="dealerSidebar" group="dealers" app="gddealer" params="$dealer, $activeTab, $nav"}
        </div>
    </div>

    <div class="gdDealerShell">
        <aside class="gdSidebar">
            {template="dealerSidebar" group="dealers" app="gddealer" params="$dealer, $activeTab, $nav"}
        </aside>

        <main class="gdMain">
            {$body|raw}
        </main>
    </div>

</div>
TEMPLATE_EOT
];

/* dealerSidebar */
$gddealerV10071Templates[] = [
    'set_id'            => 1,
    'app'               => 'gddealer',
    'location'          => 'front',
    'group'             => 'dealers',
    'template_name'     => 'dealerSidebar',
    'template_data'     => '$dealer, $activeTab, $nav',
    'template_content'  => <<<'TEMPLATE_EOT'
<div class="gdSidebar__brand">
    <span class="gdSidebar__brandMark">GD</span>
    <div>
        <div class="gdSidebar__brandText">gunrack.deals</div>
        <div class="gdSidebar__brandSub">Dealer Dashboard</div>
    </div>
</div>

<div class="gdSidebar__nav">
{{foreach $nav as $groupKey => $group}}
<div class="gdNavGroup">
    <div class="gdNavGroup__label">{$group['label']}</div>
    {{foreach $group['items'] as $item}}
    {{if isset($item['children']) and $item['children']}}
    <div class="gdNavItem gdNavItem--parent" data-gd-dropdown>
        <button type="button" class="gdNavItem__toggle" aria-expanded="false">
            {template="dealerNavIcon" group="dealers" app="gddealer" params="$item['icon']"}
            <span class="gdNavItem__label">{$item['label']}</span>
            <svg class="gdNavItem__caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
        <div class="gdNavItem__submenu">
            {{foreach $item['children'] as $child}}
            <a href="{$child['url']}" class="gdNavSub">{$child['label']}</a>
            {{endforeach}}
        </div>
    </div>
    {{else}}
    <a href="{$item['url']}" class="gdNavItem {expression="$activeTab === $item['key'] ? 'is-active' : ''"}">
        {template="dealerNavIcon" group="dealers" app="gddealer" params="$item['icon']"}
        <span class="gdNavItem__label">{$item['label']}</span>
        {{if $item['badge']}}
        <span class="gdNavItem__count is-{$item['badge']['variant']}">{$item['badge']['count']}</span>
        {{endif}}
    </a>
    {{endif}}
    {{endforeach}}
</div>
{{endforeach}}
</div>

<div class="gdSidebar__footer">
    <div class="gdSidebar__user">
        <span class="gdSidebar__avatar">
            {{if $dealer['avatar_url']}}
                <img src="{$dealer['avatar_url']}" alt="">
            {{else}}
                {expression="mb_substr($dealer['dealer_name'], 0, 2)"}
            {{endif}}
        </span>
        <div class="gdSidebar__userInfo">
            <div class="gdSidebar__userName">{$dealer['dealer_name']}</div>
            <div class="gdSidebar__userRole">{$dealer['tier_label']} dealer</div>
        </div>
    </div>
</div>
TEMPLATE_EOT
];

/* dealerNavIcon */
$gddealerV10071Templates[] = [
    'set_id'            => 1,
    'app'               => 'gddealer',
    'location'          => 'front',
    'group'             => 'dealers',
    'template_name'     => 'dealerNavIcon',
    'template_data'     => '$icon',
    'template_content'  => <<<'TEMPLATE_EOT'
{{if $icon === 'dashboard'}}
<svg class="gdNavItem__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>
{{elseif $icon === 'listings'}}
<svg class="gdNavItem__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 7h-9"/><path d="M14 17H5"/><circle cx="17" cy="17" r="3"/><circle cx="7" cy="7" r="3"/></svg>
{{elseif $icon === 'reviews'}}
<svg class="gdNavItem__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
{{elseif $icon === 'feed'}}
<svg class="gdNavItem__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
{{elseif $icon === 'unmatched'}}
<svg class="gdNavItem__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
{{elseif $icon === 'analytics'}}
<svg class="gdNavItem__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
{{elseif $icon === 'billing'}}
<svg class="gdNavItem__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
{{elseif $icon === 'help'}}
<svg class="gdNavItem__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/></svg>
{{elseif $icon === 'support'}}
<svg class="gdNavItem__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
{{endif}}
TEMPLATE_EOT
];

/* Seed/replace each template — compute a master_key that matches IPS 5's pattern. */
foreach ( $gddealerV10071Templates as $tpl ) {
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

/* Clear theme caches so new templates take effect */
try { \IPS\Theme::master()->recompileTemplates(); } catch ( \Throwable ) {}
try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
try { \IPS\Data\Store::i()->clearAll(); } catch ( \Throwable ) {}
