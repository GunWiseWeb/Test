<div class="gdSidebar__brand">
    <span class="gdSidebar__brandMark">GD</span>
    <div>
        <div class="gdSidebar__brandText">gunrack.deals</div>
        <div class="gdSidebar__brandSub">Dealer Dashboard</div>
    </div>
</div>

{{foreach $nav as $groupKey => $group}}
<div class="gdNavGroup">
    <div class="gdNavGroup__label">{$group['label']}</div>
    {{foreach $group['items'] as $item}}
    <a href="{$item['url']}" class="gdNavItem {expression="$activeTab === $item['key'] ? 'is-active' : ''"}">
        {template="dealerNavIcon" group="dealers" app="gddealer" params="$item['icon']"}
        <span class="gdNavItem__label">{$item['label']}</span>
        {{if $item['badge']}}
        <span class="gdNavItem__count is-{$item['badge']['variant']}">{$item['badge']['count']}</span>
        {{endif}}
    </a>
    {{endforeach}}
</div>
{{endforeach}}

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