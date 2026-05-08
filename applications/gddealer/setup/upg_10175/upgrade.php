<?php
namespace IPS\gddealer\setup\upg_10175;

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
		/* v1.0.175 - Subscription Plans Phase 2 of 3.
		 *
		 * v1.0.174 shipped the admin editor and storage. This version rewires
		 * the dashboard subscription template to read from those settings
		 * instead of hardcoded values.
		 *
		 * Changes:
		 *   - Reseed the 'subscription' template via core_theme_templates
		 *     replace() with new template_data adding $plans
		 *   - The dashboard.php controller change is in the file directly
		 *     (not handled by upgrade.php)
		 *
		 * Per CLAUDE.md rule #28: template content is inlined as nowdoc
		 * heredoc, not extracted at runtime.
		 *
		 * Per CLAUDE.md rule #45: core_theme_templates schema verified -
		 * NO template_user_edited / _user_created / _user_added columns. */

		$newTemplateContent = $this->getSubscriptionTemplate();

		try
		{
			\IPS\Db::i()->replace( 'core_theme_templates', [
				'template_set_id'      => 1,
				'template_app'         => 'gddealer',
				'template_location'    => 'front',
				'template_group'       => 'dealers',
				'template_name'        => 'subscription',
				'template_data'        => '$dealer, $sub, $billingNote, $tabUrls, $plans',
				'template_content'     => $newTemplateContent,
				'template_master_key'  => md5( 'gddealer;front;dealers;subscription' ),
				'template_updated'     => time(),
				'template_version'     => '1.0.175',
			] );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'v1.0.175 subscription template reseed failed: ' . $e->getMessage(), 'gddealer_upg_10175' ); } catch ( \Throwable ) {}
			return FALSE;
		}

		/* Cache invalidation */
		try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'theme_%' OR store_key LIKE 'template_%'" ] ); } catch ( \Throwable ) {}

		foreach ( glob( \IPS\ROOT_PATH . '/datastore/template_*' ) ?: [] as $f )
		{
			@unlink( $f );
		}

		try { unset( \IPS\Data\Store::i()->extensions );   } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll();            } catch ( \Throwable ) {}

		return TRUE;
	}

	public function step1CustomTitle()
	{
		return 'v1.0.175 - rewire dashboard subscription template to read from plan settings (Phase 2 of 3)';
	}

	/**
	 * v1.0.175 subscription template content. Replaces hardcoded plan values
	 * (Basic / Pro / Enterprise prices, taglines, features) with reads from
	 * the new $plans array passed by the controller.
	 *
	 * Founding tier display in the hero card now reads from $plans['founding']
	 * instead of hardcoded "Founding partner * all features unlocked".
	 */
	protected function getSubscriptionTemplate(): string
	{
		return <<<'TEMPLATE_EOT'
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
                {{if $sub['is_founding'] and isset($plans['founding']['tagline']) and $plans['founding']['tagline']}}{$plans['founding']['tagline']}
                {{elseif $sub['is_founding']}}Founding partner &middot; all features unlocked
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
        <a href="{$sub['subscribe_url']}" class="gdBtn gdBtn--primary">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
            Manage subscription
        </a>
    </div>
</div>

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
            {{elseif $sub['tier'] === 'pro' or $sub['tier'] === 'enterprise' or $sub['tier'] === 'founding'}}
            <a href="{$sub['subscribe_url']}" class="gdTierCol__cta is-downgrade">Downgrade to {$plans['basic']['name']}</a>
            {{else}}
            <a href="{$sub['subscribe_url']}" class="gdTierCol__cta">Choose {$plans['basic']['name']}</a>
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
            {{elseif $sub['tier'] === 'enterprise' or $sub['tier'] === 'founding'}}
            <a href="{$sub['subscribe_url']}" class="gdTierCol__cta is-downgrade">Downgrade to {$plans['pro']['name']}</a>
            {{else}}
            <a href="{$sub['subscribe_url']}" class="gdTierCol__cta is-upgrade">Upgrade to {$plans['pro']['name']}</a>
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
            {{elseif $sub['tier'] === 'founding'}}
            <span class="gdTierCol__cta is-current">Included in Founding</span>
            {{else}}
            <a href="{$sub['subscribe_url']}" class="gdTierCol__cta is-upgrade">Upgrade to {$plans['enterprise']['name']}</a>
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
TEMPLATE_EOT;
	}
}

class upgrade extends _upgrade {}
