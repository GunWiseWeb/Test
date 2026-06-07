<?php

namespace IPS\gdloadout\setup\upg_10009;

if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		$now = time();
		$ver = '1.0.9';

		/* Re-seed view template with null-safe item access */
		$viewContent = <<<'TEMPLATE_EOT'
<script type="application/json" id="gdlo-view-init">{$initData|raw}</script>
<div class="gdlo-view">

	<div class="gdlo-view-header">
		<h1 class="gdlo-view-title">{expression="htmlspecialchars($loadout['name'])"}</h1>
		{{if !empty($loadout['use_case'])}}
		<span class="gdlo-view-badge">{expression="htmlspecialchars($loadout['use_case'])"}</span>
		{{endif}}
		<div class="gdlo-view-meta">
			<span>by {expression="htmlspecialchars($ownerName)"}</span>
			<span>{expression="(int)($loadout['view_count'] ?? 0)"} views</span>
			<span>{expression="(int)($loadout['total_items'] ?? 0)"} items</span>
		</div>
	</div>

	{{if !empty($compliance['has_issues'])}}
	<div class="gdlo-compliance gdlo-compliance--warn">
		{{if ($compliance['nfa_count'] ?? 0) > 0}}
		<span class="gdlo-compliance-tag"><i class="fa-solid fa-shield"></i> {expression="(int)$compliance['nfa_count']"} NFA Items</span>
		{{endif}}
		{{if ($compliance['ffl_count'] ?? 0) > 0}}
		<span class="gdlo-compliance-tag"><i class="fa-solid fa-id-card"></i> {expression="(int)$compliance['ffl_count']"} FFL Required</span>
		{{endif}}
	</div>
	{{else}}
	<div class="gdlo-compliance gdlo-compliance--ok">
		<i class="fa-solid fa-check-circle"></i> {lang="gdloadout_compliance_none"}
	</div>
	{{endif}}

	<div class="gdlo-view-actions">
		<button type="button" id="gdUpvoteBtn" class="ipsButton ipsButton--small" data-voted="{expression="$hasVoted ? '1' : '0'}">
			<i class="fa-solid fa-arrow-up"></i> <span id="gdUpvoteCount">{expression="(int)($loadout['upvotes'] ?? 0)"}</span>
		</button>
		<button type="button" id="gdFollowBtn" class="ipsButton ipsButton--small" data-followed="{expression="$hasFollowed ? '1' : '0'}">
			<i class="fa-solid fa-bell"></i> <span id="gdFollowLabel">{expression="$hasFollowed ? 'Following' : 'Follow'"}</span>
		</button>
		{{if $isOwner}}
		<a href="{$editUrl}" class="ipsButton ipsButton--small ipsButton--light"><i class="fa-solid fa-pen"></i> Edit</a>
		{{endif}}
	</div>

	<div class="gdlo-view-items">
		{{foreach $items as $item}}
		<div class="gdlo-view-item">
			<div class="gdlo-view-item-img">
				{{if !empty($item['image_url'])}}
				<img src="{expression="htmlspecialchars($item['image_url'])"}" alt="" loading="lazy" />
				{{else}}
				<i class="fa-solid fa-box" style="font-size:2em;color:#ccc"></i>
				{{endif}}
			</div>
			<div class="gdlo-view-item-info">
				<div class="gdlo-view-item-slot">{expression="htmlspecialchars(($item['custom_label'] ?? '') !== '' ? $item['custom_label'] : ucwords(str_replace('_',' ',$item['slot_type'] ?? 'extra')))"}</div>
				<div class="gdlo-view-item-name">{expression="htmlspecialchars(($item['product_title'] ?? '') !== '' ? $item['product_title'] : ($item['upc'] ?? ''))"}</div>
				{{if !empty($item['brand'])}}
				<div class="gdlo-view-item-brand">{expression="htmlspecialchars($item['brand'])"}</div>
				{{endif}}
				{{if !empty($item['notes'])}}
				<div class="gdlo-view-item-notes"><i class="fa-solid fa-sticky-note"></i> {expression="htmlspecialchars($item['notes'])"}</div>
				{{endif}}
			</div>
			<div class="gdlo-view-item-price">
				{{if !empty($item['live_price'])}}
				<div class="gdlo-slot-price">${expression="number_format((float)$item['live_price'],2)"}</div>
				{{if !empty($item['active_dealer_count'])}}
				<div class="gdlo-search-meta">{expression="(int)$item['active_dealer_count']"} dealers</div>
				{{endif}}
				{{else}}
				<div class="gdlo-search-meta">&mdash;</div>
				{{endif}}
			</div>
			<div class="gdlo-view-item-badges">
				{{if !empty($item['nfa_item'])}}
				<span class="gdlo-badge gdlo-badge--nfa">NFA</span>
				{{endif}}
				{{if !empty($item['requires_ffl'])}}
				<span class="gdlo-badge gdlo-badge--ffl">FFL</span>
				{{endif}}
			</div>
		</div>
		{{endforeach}}
	</div>

	{{if !empty($loadout['total_min_price'])}}
	<div class="gdlo-view-total">
		<span>Est. Total:</span>
		<span class="gdlo-total-cost">${expression="number_format((float)$loadout['total_min_price'],2)"}</span>
	</div>
	{{endif}}

	{{if !empty($loadout['description'])}}
	<div class="gdlo-view-desc">
		{expression="nl2br(htmlspecialchars($loadout['description']))"}
	</div>
	{{endif}}

	<div class="gdlo-view-comments">
		<h3 class="gdlo-section-label">Comments ({expression="count($comments)"})</h3>
		<div id="gdCommentsList">
			{{foreach $comments as $c}}
			<div class="gdlo-comment">
				<div class="gdlo-comment-meta">
					<strong>{expression="htmlspecialchars($c['member_name'] ?? '')"}</strong>
					<span class="gdlo-search-meta">{expression="date('M j, Y', (int)($c['created_at'] ?? 0))"}</span>
				</div>
				<div class="gdlo-comment-text">{expression="nl2br(htmlspecialchars($c['comment'] ?? ''))"}</div>
			</div>
			{{endforeach}}
		</div>

		<div id="gdCommentForm" class="gdlo-comment-form">
			<textarea id="gdCommentInput" class="gdlo-input" rows="2" placeholder="Add a comment..."></textarea>
			<button type="button" id="gdCommentBtn" class="ipsButton ipsButton--primary ipsButton--small" style="margin-top:8px">Post Comment</button>
		</div>
	</div>

</div>
TEMPLATE_EOT;

		try
		{
			\IPS\Db::i()->replace( 'core_theme_templates', [
				'template_set_id'  => 1,
				'template_app'     => 'gdloadout',
				'template_location'=> 'front',
				'template_group'   => 'loadouts',
				'template_name'    => 'view',
				'template_data'    => '$loadout, $items, $ownerName, $isOwner, $editUrl, $compliance, $hasVoted, $hasFollowed, $comments, $initData',
				'template_content' => $viewContent,
				'template_updated' => $now,
				'template_version' => $ver,
			] );
		}
		catch ( \Throwable ) {}

		/* Re-import and compile CSS */
		try
		{
			if ( class_exists( '\\IPS\\Theme\\Dev\\Theme' ) )
			{
				\IPS\Theme\Dev\Theme::importDevCss( 'gdloadout', 0 );
			}
		}
		catch ( \Throwable ) {}

		try
		{
			foreach ( \IPS\Theme::themes() as $theme )
			{
				try
				{
					if ( method_exists( $theme, 'compileCss' ) )
					{
						$theme->compileCss( 'gdloadout', 'front', '.', 'loadouts.css' );
					}
				}
				catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable ) {}

		/* Clear all caches */
		try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }             catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }             catch ( \Throwable ) {}

		return TRUE;
	}
}

class upgrade extends _upgrade {}
