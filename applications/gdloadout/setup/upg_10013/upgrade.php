<?php

namespace IPS\gdloadout\setup\upg_10013;

if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		/* --- Seed new lang strings (#39/#43/#44 — 6-col schema, per-row try/catch) --- */
		$newStrings = [
			'notifications__gdloadout_Loadouts'      => 'Loadout Notifications',
			'notifications__gdloadout_Loadouts_desc'  => 'Receive notifications when someone upvotes or follows your loadout, or when a loadout you follow is updated.',
			'gdloadout_notify_loadout_updated'        => 'Loadout Updated',
			'gdloadout_notify_loadout_upvoted'        => 'Loadout Upvoted',
			'gdloadout_notify_loadout_followed'       => 'Loadout Followed',
			'gdloadout_add_all_wishlist'               => 'Add all to wishlist',
			'gdloadout_set_all_alerts'                 => 'Set price alerts for all items',
			'gdloadout_widget_trending'                => 'Trending Loadouts',
			'gdloadout_wishlist_added'                 => 'items added to wishlist',
			'gdloadout_alerts_set'                     => 'price alerts set',
		];

		try
		{
			foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
			{
				foreach ( $newStrings as $key => $val )
				{
					try
					{
						\IPS\Db::i()->replace( 'core_sys_lang_words', [
							'lang_id'      => (int) $langId,
							'word_app'     => 'gdloadout',
							'word_key'     => $key,
							'word_default' => $val,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
					catch ( \Throwable ) {}
				}
			}
		}
		catch ( \Throwable ) {}

		/* --- Re-seed view template with wishlist/alert buttons (#52 — upgrade parity) --- */
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
		<button type="button" id="gdWishlistBtn" class="ipsButton ipsButton--small ipsButton--light">
			<i class="fa-solid fa-heart"></i> {lang="gdloadout_add_all_wishlist"}
		</button>
		<button type="button" id="gdAlertBtn" class="ipsButton ipsButton--small ipsButton--light">
			<i class="fa-solid fa-bell-exclamation"></i> {lang="gdloadout_set_all_alerts"}
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
				'template_location' => 'front',
				'template_group'   => 'loadouts',
				'template_name'    => 'view',
				'template_data'    => '$loadout, $items, $ownerName, $isOwner, $editUrl, $compliance, $hasVoted, $hasFollowed, $comments, $initData',
				'template_content' => $viewContent,
				'template_updated' => time(),
				'template_version' => '1.0.13',
			] );
		}
		catch ( \Throwable ) {}

		/* --- Seed widget template (#52 — upgrade parity) --- */
		$widgetContent = <<<'TEMPLATE_EOT'
<div class="ipsWidget">
	<h3 class="ipsWidget_title">{lang="gdloadout_widget_trending"}</h3>
	<div class="ipsWidget_inner ipsPad_half">
		{{if count($loadouts) > 0}}
		{{foreach $loadouts as $lo}}
		<a href="{expression="htmlspecialchars($lo['view_url'])"}" class="gdlo-hub-card" style="display:block;margin-bottom:8px;padding:10px;border:1px solid #e2e8f0;border-radius:6px;text-decoration:none;color:#1e293b">
			<div style="font-weight:700;font-size:.95em;color:#0f172a">{expression="htmlspecialchars($lo['name'])"}</div>
			{{if !empty($lo['use_case'])}}
			<div style="font-size:.75em;color:#64748b">{expression="htmlspecialchars($lo['use_case'])"}</div>
			{{endif}}
			<div style="font-size:.8em;color:#64748b;margin-top:4px">
				<span><i class="fa-solid fa-arrow-up"></i> {expression="(int)($lo['upvotes'] ?? 0)"}</span>
				<span style="margin-left:8px">{expression="(int)($lo['total_items'] ?? 0)"} items</span>
				{{if !empty($lo['total_min_price'])}}
				<span style="margin-left:8px;color:#16a34a">${expression="number_format((float)$lo['total_min_price'],2)"}</span>
				{{endif}}
			</div>
			<div style="font-size:.7em;color:#94a3b8;margin-top:2px">by {expression="htmlspecialchars($lo['owner_name'] ?? 'Unknown')"}</div>
		</a>
		{{endforeach}}
		{{else}}
		<p style="color:#94a3b8;text-align:center;padding:12px">{lang="gdloadout_hub_empty"}</p>
		{{endif}}
	</div>
</div>
TEMPLATE_EOT;

		try
		{
			\IPS\Db::i()->replace( 'core_theme_templates', [
				'template_set_id'  => 1,
				'template_app'     => 'gdloadout',
				'template_location' => 'front',
				'template_group'   => 'widgets',
				'template_name'    => 'trendingLoadouts',
				'template_data'    => '$loadouts',
				'template_content' => $widgetContent,
				'template_updated' => time(),
				'template_version' => '1.0.13',
			] );
		}
		catch ( \Throwable ) {}

		/* --- Self-heal extensions.json (#16) --- */
		try
		{
			$extFile = \IPS\ROOT_PATH . '/applications/gdloadout/data/extensions.json';
			if ( file_exists( $extFile ) )
			{
				$ext = json_decode( file_get_contents( $extFile ), true ) ?: [];
				$changed = false;
				if ( !isset( $ext['Notifications']['Loadouts'] ) )
				{
					$ext['Notifications'] = $ext['Notifications'] ?? [];
					$ext['Notifications']['Loadouts'] = 'IPS\\gdloadout\\extensions\\core\\Notifications\\Loadouts';
					$changed = true;
				}
				if ( $changed )
				{
					file_put_contents( $extFile, json_encode( $ext, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				}
			}
		}
		catch ( \Throwable ) {}

		/* --- Clear caches (#40) --- */
		try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }             catch ( \Throwable ) {}

		return TRUE;
	}
}

class upgrade extends _upgrade {}
