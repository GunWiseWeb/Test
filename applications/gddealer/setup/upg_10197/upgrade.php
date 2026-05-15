<?php
/**
 * v1.0.197: Fix overview template signature + body on existing installs.
 *
 * The install.php seed had the old '$dealer, $overview, $tabUrls, $prefs'
 * signature but the controller passes a single '$data' array. This upgrade
 * overwrites the overview template with the correct body.
 *
 * Also fixes missing "name" field on gd_dealer_category_map in schema.json
 * (handled automatically by IPS schema diff on upgrade).
 */

namespace IPS\gddealer\setup\upg_10197;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		$body = $this->getOverviewBody();

		try
		{
			\IPS\Db::i()->update(
				'core_theme_templates',
				[
					'template_data'    => '$data',
					'template_content' => $body,
					'template_updated' => time(),
					'template_version' => '1.0.197',
				],
				[ 'template_app=? AND template_location=? AND template_group=? AND template_name=?',
				  'gddealer', 'front', 'dealers', 'overview' ]
			);
		}
		catch ( \Throwable ) {}

		try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->themes ); }       catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }            catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }            catch ( \Throwable ) {}

		return TRUE;
	}

	protected function getOverviewBody(): string
	{
		return <<<'TEMPLATE_EOT'
<div class="gdPageHeader">
    <div class="gdPageHeader__titleBlock">
        <h1 class="gdPageHeader__title">Overview</h1>
        <p class="gdPageHeader__sub">Last updated {expression="date('g:i A')"}</p>
    </div>
    <div class="gdPageHeader__actions">
        <a href="{$data['tab_urls']['analytics']}" class="gdBtn gdBtn--secondary">Analytics</a>
        <a href="{$data['tab_urls']['feedSettings']}" class="gdBtn gdBtn--primary">Upload feed</a>
    </div>
</div>

<div class="gdIdentity">
    <div class="gdIdentity__left">
        <div class="gdIdentity__avatar">
            {{if $data['dealer']['avatar_url']}}
                <img src="{$data['dealer']['avatar_url']}" alt="">
            {{else}}
                {expression="mb_substr($data['dealer']['dealer_name'], 0, 2)"}
            {{endif}}
        </div>
        <div>
            <div class="gdIdentity__name">
                <h2>{$data['dealer']['dealer_name']}</h2>
                <span class="gdTierBadge gdTierBadge--{$data['dealer']['subscription_tier']}">{$data['dealer']['tier_label']} dealer</span>
            </div>
            <p class="gdIdentity__url">gunrack.deals/dealers/{$data['dealer']['dealer_slug']}
                <button type="button" class="gdBtn gdBtn--secondary" style="margin-left:8px;padding:2px 10px;font-size:11px;vertical-align:middle" data-gd-regenerate-slug data-preview-url="{$data['regenerate_preview_url']}" data-confirm-url="{$data['regenerate_confirm_url']}">Regenerate</button>
            </p>
        </div>
    </div>
    <div class="gdIdentity__actions">
        <a href="{$data['public_profile_url']}" class="gdBtn gdBtn--secondary">View public profile</a>
        <a href="{$data['tab_urls']['subscription']}" class="gdBtn gdBtn--secondary">Manage subscription</a>
    </div>
</div>

{{if $data['steps_pct'] < 100}}
<div class="gdSetupCard">
    <div class="gdSetupCard__head">
        <div>
            <div class="gdSetupCard__title">Finish setting up your storefront</div>
            <div class="gdSetupCard__sub">{$data['steps_done']} of {$data['steps_total']} steps complete</div>
        </div>
        <div class="gdSetupCard__pct">{$data['steps_pct']}%</div>
    </div>
    <div class="gdProgress">
        <div class="gdProgress__fill" style="width: {$data['steps_pct']}%"></div>
    </div>
    <div class="gdSetupSteps">
        {{foreach $data['setup_steps'] as $step}}
        <div class="gdStep gdStep--{$step['state']}">
            <span class="gdStep__icon">
                {{if $step['state'] === 'done'}}&#10003;{{elseif $step['state'] === 'active'}}!{{endif}}
            </span>
            <span class="gdStep__text">{$step['label']}</span>
        </div>
        {{endforeach}}
    </div>
</div>
{{endif}}

<div class="gdKpiGrid">
    <div class="gdKpi">
        <div class="gdKpi__label">Active listings</div>
        <div class="gdKpi__value">{expression="number_format($data['stats']['active'])"}</div>
    </div>
    <div class="gdKpi">
        <div class="gdKpi__label">Out of stock</div>
        <div class="gdKpi__value">{expression="number_format($data['stats']['out'])"}</div>
    </div>
    <div class="gdKpi">
        <div class="gdKpi__label">Unmatched UPCs</div>
        <div class="gdKpi__value">{expression="number_format($data['stats']['unmatched'])"}</div>
    </div>
    <div class="gdKpi">
        <div class="gdKpi__label">Clicks &middot; 30d</div>
        <div class="gdKpi__value">{expression="number_format($data['stats']['clicks_30'])"}</div>
    </div>
</div>

<div class="gdContentSplit">

    <div>
        <div class="gdPanel">
            <div class="gdPanel__head">
                <div>
                    <div class="gdPanel__title">Last feed import</div>
                    <div class="gdPanel__sub">Most recent import activity</div>
                </div>
                <a href="{$data['tab_urls']['feedSettings']}" class="gdPanel__link">Feed settings &rarr;</a>
            </div>
            {{if $data['last_import']}}
            <table class="gdTable">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Status</th>
                        <th class="is-num">Records</th>
                        <th class="is-num">Updated</th>
                        <th class="is-num">Unmatched</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>{$data['last_import']['when_label']}</td>
                        <td><span class="gdLogStatus gdLogStatus--{$data['last_import']['status']}">{expression="ucfirst($data['last_import']['status'])"}</span></td>
                        <td class="is-num">{expression="number_format($data['last_import']['records'])"}</td>
                        <td class="is-num">{expression="number_format($data['last_import']['updated'])"}</td>
                        <td class="is-num">{expression="number_format($data['last_import']['unmatched'])"}</td>
                    </tr>
                </tbody>
            </table>
            {{else}}
            <p style="color: var(--gd-text-subtle); font-size: 13px; margin: 0;">No feed imports yet. <a href="{$data['tab_urls']['feedSettings']}" style="color: var(--gd-brand)">Configure your feed &rarr;</a></p>
            {{endif}}
        </div>
    </div>

    <div>
        <div class="gdRailCard">
            <div class="gdRailCard__title">Quick actions</div>
            <div class="gdActionList">
                <a href="{$data['tab_urls']['feedSettings']}" class="gdActionList__item">
                    <span>Upload product feed</span>
                    <span class="gdActionList__arrow">&rarr;</span>
                </a>
                <a href="{$data['tab_urls']['unmatched']}" class="gdActionList__item">
                    <span>Resolve unmatched UPCs</span>
                    {{if $data['stats']['unmatched'] > 0}}
                    <span class="gdActionList__count is-urgent">{$data['stats']['unmatched']}</span>
                    {{else}}
                    <span class="gdActionList__arrow">&rarr;</span>
                    {{endif}}
                </a>
                <a href="{$data['tab_urls']['reviews']}" class="gdActionList__item">
                    <span>Respond to reviews</span>
                    {{if $data['dealer']['new_reviews'] > 0}}
                    <span class="gdActionList__count is-warn">{$data['dealer']['new_reviews']}</span>
                    {{else}}
                    <span class="gdActionList__arrow">&rarr;</span>
                    {{endif}}
                </a>
                <a href="{$data['tab_urls']['analytics']}" class="gdActionList__item">
                    <span>View analytics</span>
                    <span class="gdActionList__arrow">&rarr;</span>
                </a>
                <a href="{$data['tab_urls']['subscription']}" class="gdActionList__item">
                    <span>Manage subscription</span>
                    <span class="gdActionList__arrow">&rarr;</span>
                </a>
            </div>
        </div>
    </div>

</div>

<!-- v1.0.183: Regenerate slug confirmation modal (overview page) -->
<div id="gd-regen-slug-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center">
	<div style="background:#fff;border-radius:8px;padding:24px;max-width:520px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,0.2)">
		<h3 style="margin:0 0 16px;font-size:1.2em;font-weight:600">Regenerate URL slug</h3>
		<div style="margin-bottom:12px">
			<div style="font-size:0.8em;color:#666;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">Current slug</div>
			<code id="gd-regen-old" style="display:block;background:#f4f4f4;padding:8px 12px;border-radius:4px;font-size:0.95em">&mdash;</code>
		</div>
		<div style="margin-bottom:12px">
			<div style="font-size:0.8em;color:#666;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">New slug</div>
			<code id="gd-regen-new" style="display:block;background:#e8f5e9;padding:8px 12px;border-radius:4px;font-size:0.95em">&mdash;</code>
		</div>
		<p style="margin:16px 0;padding:12px;background:#fff8e1;border-left:3px solid #ffa726;font-size:0.9em;line-height:1.5"><strong>Heads up:</strong> the old URL will 301-redirect to the new URL. Already-shared links keep working. The new slug is generated from your current saved business name.</p>
		<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:20px">
			<button type="button" class="gdBtn gdBtn--secondary" data-gd-regen-cancel>Cancel</button>
			<form id="gd-regen-confirm-form" method="POST" action="" style="display:inline">
				<input type="hidden" name="csrfKey" id="gd-regen-csrf" value="">
				<button type="submit" class="gdBtn gdBtn--primary" id="gd-regen-submit">Regenerate slug</button>
			</form>
		</div>
	</div>
</div>
<script>
(function(){
	var triggers = document.querySelectorAll('[data-gd-regenerate-slug]');
	var modal = document.getElementById('gd-regen-slug-modal');
	var oldEl = document.getElementById('gd-regen-old');
	var newEl = document.getElementById('gd-regen-new');
	var form = document.getElementById('gd-regen-confirm-form');
	var csrfInput = document.getElementById('gd-regen-csrf');
	var submitBtn = document.getElementById('gd-regen-submit');
	if ( !modal || !triggers.length ) { return; }

	function openModal(trigger){
		var previewUrl = trigger.getAttribute('data-preview-url');
		var confirmUrl = trigger.getAttribute('data-confirm-url');
		oldEl.textContent = 'Loading...';
		newEl.textContent = 'Loading...';
		submitBtn.disabled = false;
		submitBtn.textContent = 'Regenerate slug';
		modal.style.display = 'flex';
		fetch(previewUrl, {credentials: 'same-origin'})
			.then(function(r){return r.json();})
			.then(function(d){
				oldEl.textContent = d.old_slug || '(none)';
				if ( !d.would_change ) {
					newEl.textContent = '(no change needed)';
					submitBtn.disabled = true;
					submitBtn.textContent = 'No change needed';
				} else {
					newEl.textContent = d.new_slug;
					submitBtn.disabled = false;
					submitBtn.textContent = 'Regenerate slug';
				}
				/* v1.0.184 fix: csrfKey is already baked into confirmUrl via
				 * the controller's ->csrf() call. Extract it directly and
				 * stuff it into the hidden input AND keep it in the action
				 * URL - both belt and suspenders for IPS's csrfCheck(). */
				form.action = confirmUrl;
				var csrfMatch = confirmUrl.match(/[?&]csrfKey=([^&]+)/);
				if ( csrfMatch ) {
					csrfInput.value = decodeURIComponent(csrfMatch[1]);
				}
			})
			.catch(function(){
				oldEl.textContent = 'Error';
				newEl.textContent = 'Could not load preview.';
				submitBtn.disabled = true;
			});
	}

	function closeModal(){ modal.style.display = 'none'; }

	triggers.forEach(function(t){
		t.addEventListener('click', function(e){ e.preventDefault(); openModal(t); });
	});

	modal.addEventListener('click', function(e){
		if ( e.target === modal || e.target.hasAttribute('data-gd-regen-cancel') ) { closeModal(); }
	});
})();
</script>

{{if !empty( $data['verified_badge']['show'] )}}
<div class="gdPanel" id="gdBadgePicker" data-badges='{$data['verified_badge']['badges_json']|raw}' data-profile-url="{$data['verified_badge']['profile_url']}" style="margin-top:24px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:24px;">
	<div style="margin-bottom:18px;">
		<h2 style="margin:0 0 4px;font-size:16px;font-weight:600;color:#0f172a;display:flex;align-items:center;gap:8px;">
			<svg width="18" height="18" viewBox="0 0 24 24" fill="#10b981" style="flex-shrink:0;"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>
			Get your verified-dealer badge
		</h2>
		<p style="margin:0;font-size:13px;color:#64748b;">Show customers you're a verified Gun Rack Deals dealer. Pick a style and paste the embed code on your website.</p>
	</div>

	<div style="display:grid;grid-template-columns:1fr;gap:16px;">
		<div>
			<label for="gd_badge_picker" style="display:block;font-size:13px;font-weight:600;color:#0f172a;margin-bottom:6px;">Badge style</label>
			<select id="gd_badge_picker" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font:inherit;background:#fff;">
				{{foreach $data['verified_badge']['badges'] as $b}}
				<option value="{$b['id']}">{$b['label']}</option>
				{{endforeach}}
			</select>
		</div>

		<div>
			<div style="font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:8px;">Live preview</div>
			<div style="padding:24px;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px;display:flex;align-items:center;justify-content:center;min-height:90px;">
				<a id="gd_badge_preview_link" href="{$data['verified_badge']['profile_url']}" target="_blank" rel="noopener">
					<img id="gd_badge_preview_img" src="" alt="Verified Gun Rack Deals dealer">
				</a>
			</div>
		</div>

		<div>
			<div style="display:flex;gap:6px;border-bottom:1px solid #e5e7eb;margin-bottom:0;">
				<button type="button" data-tab="html" class="gdBadgeTab" style="padding:8px 14px;border:0;border-bottom:2px solid #1e40af;background:none;color:#1e40af;font-weight:600;font-size:13px;cursor:pointer;">HTML embed</button>
				<button type="button" data-tab="md" class="gdBadgeTab" style="padding:8px 14px;border:0;border-bottom:2px solid transparent;background:none;color:#64748b;font-weight:500;font-size:13px;cursor:pointer;">Markdown</button>
				<button type="button" data-tab="link" class="gdBadgeTab" style="padding:8px 14px;border:0;border-bottom:2px solid transparent;background:none;color:#64748b;font-weight:500;font-size:13px;cursor:pointer;">Direct link</button>
			</div>
			<div style="position:relative;">
				<textarea id="gd_badge_code" readonly rows="4" style="width:100%;padding:12px;border:1px solid #e5e7eb;border-top:0;border-radius:0 0 6px 6px;font-family:ui-monospace,'SF Mono',Menlo,monospace;font-size:12px;color:#0f172a;background:#f8fafc;resize:vertical;box-sizing:border-box;"></textarea>
				<button type="button" id="gd_badge_copy_btn" style="position:absolute;top:8px;right:8px;padding:6px 12px;background:#1e40af;color:#fff;border:0;border-radius:5px;font-size:12px;font-weight:600;cursor:pointer;">Copy</button>
			</div>
		</div>
	</div>
</div>
{{endif}}
TEMPLATE_EOT;
	}
}

class upgrade extends _upgrade {}
