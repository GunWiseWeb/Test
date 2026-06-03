<?php
/**
 * @brief  GD Dealer Manager — DealerShell Trait
 *
 * Shared helpers for front-end controllers that render inside the dealer
 * dashboard shell (left sidebar nav). Consumers must set $this->dealer
 * (of type \IPS\gddealer\Dealer\Dealer) before calling output() /
 * dealerSummary(). Extracted from dashboard.php in v1.0.55, redesigned
 * in v1.0.71 to use a SaaS-style left-sidebar layout.
 */

namespace IPS\gddealer\Traits;

use IPS\gddealer\Dealer\Dealer;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

trait DealerShellTrait
{
	/**
	 * Wrap a tab body in the dealerShell template and push to output.
	 */
	protected function output( string $activeTab, string $body ): void
	{
		try {
			\IPS\Output::i()->cssFiles = array_merge(
				\IPS\Output::i()->cssFiles,
				\IPS\Theme::i()->css( 'dealer.css', 'gddealer', 'front' )
			);
		} catch ( \Throwable ) {}

		$js = '';
		$jsPath = \IPS\ROOT_PATH . '/applications/gddealer/dev/js/front/badge-picker.js';
		if ( file_exists( $jsPath ) ) {
			$js = '<script>' . file_get_contents( $jsPath ) . '</script>';
		}

		$fflOnboardingHtml = $this->fflOnboardingMarkup();

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gddealer_frontend_dashboard_title' );
		\IPS\Output::i()->output = $fflOnboardingHtml
			. \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->dealerShell(
				$this->dealerSummary(),
				$activeTab,
				$this->sidebarNav(),
				$body,
				(bool) ( $this->dealer->suspended ?? false ),
				(string) ( $this->dealer->suspension_reason ?? '' )
			)
			. $js;
	}

	protected function fflOnboardingMarkup(): string
	{
		try
		{
			$d = $this->dealer ?? null;
			if ( !$d ) { return ''; }

			$hasNumber = !empty( (string) ( $d->ffl_number ?? '' ) );
			$hasUrl    = !empty( (string) ( $d->ffl_license_url ?? '' ) );
			if ( $hasNumber && $hasUrl ) { return ''; }

			$dealerGroupIds = [ 7, 8, 9, 10 ];
			$me             = \IPS\Member::loggedIn();
			$myGroups       = [ (int) $me->member_group_id ];
			foreach ( array_filter( array_map( 'trim', explode( ',', (string) ( $me->mgroup_others ?? '' ) ) ) ) as $gid )
			{
				$myGroups[] = (int) $gid;
			}
			if ( count( array_intersect( $dealerGroupIds, $myGroups ) ) === 0 ) { return ''; }

			$prefs        = [];
			$dismissedAt  = 0;
			$rawPrefs     = (string) ( $d->dealer_dashboard_prefs ?? '' );
			if ( $rawPrefs !== '' )
			{
				$decoded = json_decode( $rawPrefs, true );
				if ( is_array( $decoded ) ) {
					$prefs       = $decoded;
					$dismissedAt = (int) ( $decoded['ffl_modal_dismissed_at'] ?? 0 );
				}
			}
			$modalSuppressed = ( $dismissedAt > 0 ) && ( ( time() - $dismissedAt ) < 86400 );

			$dismissUrl = (string) \IPS\Http\Url::internal(
				'app=gddealer&module=dealers&controller=dashboard&do=dismissFflModal'
			)->csrf();
			$customizeUrl = (string) \IPS\Http\Url::internal(
				'app=gddealer&module=dealers&controller=dashboard&do=customize'
			);

			$bannerHtml = <<<HTML
<div id="gdFflBanner" style="background:#fef3c7;border-bottom:1px solid #fde68a;color:#92400e;padding:10px 20px;display:flex;align-items:center;gap:12px;font-size:13px;">
	<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
	<span style="flex:1;"><strong>Submit your FFL license</strong> to display the verified badge on your public profile and gain customer trust.</span>
	<a href="{$customizeUrl}" style="background:#92400e;color:#fff;padding:6px 14px;border-radius:5px;text-decoration:none;font-weight:600;font-size:12px;white-space:nowrap;">Submit FFL</a>
</div>
HTML;

			$modalHtml = '';
			if ( !$modalSuppressed )
			{
				$modalHtml = <<<HTML
<div id="gdFflModalOverlay" onclick="if(event.target===this){document.getElementById('gdFflModalOverlay').style.display='none';fetch('{$dismissUrl}',{method:'POST',credentials:'same-origin'});}" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(15,23,42,0.5);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;">
	<div role="dialog" aria-labelledby="gdFflModalTitle" style="background:#fff;border-radius:10px;max-width:480px;width:100%;padding:28px;box-shadow:0 20px 25px -5px rgba(0,0,0,0.2);position:relative;">
		<button type="button" onclick="document.getElementById('gdFflModalOverlay').style.display='none';fetch('{$dismissUrl}',{method:'POST',credentials:'same-origin'});" aria-label="Dismiss" style="position:absolute;top:14px;right:14px;background:none;border:0;color:#94a3b8;cursor:pointer;padding:4px;line-height:0;">
			<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
		</button>

		<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
			<svg width="22" height="22" viewBox="0 0 24 24" fill="#1e40af"><path d="M12 2 4 5v6c0 5.5 3.8 10.7 8 12 4.2-1.3 8-6.5 8-12V5l-8-3z"/></svg>
			<h2 id="gdFflModalTitle" style="margin:0;font-size:18px;font-weight:600;color:#0f172a;">Submit your FFL license</h2>
		</div>
		<p style="margin:0 0 18px 0;font-size:13px;color:#64748b;line-height:1.5;">Get the green verified badge on your public profile to build customer trust. Admin reviews submissions within 24 hours.</p>

		<form method="post" action="{$customizeUrl}" id="gdFflModalForm" style="margin-bottom:0;">
			<input type="hidden" name="csrfKey" value="{$me->csrfKey}">
			<input type="hidden" name="MAX_FILE_SIZE" value="0">

			<label for="gd_ffl_modal_number" style="display:block;font-size:13px;font-weight:600;color:#0f172a;margin-bottom:6px;">FFL number</label>
			<input type="text" id="gd_ffl_modal_number" name="ffl_number" pattern="\d-\d{2}-\d{5}" placeholder="3-37-06855" required class="ipsInput ipsInput--text" style="width:100%;font-family:ui-monospace,'SF Mono',Menlo,monospace;box-sizing:border-box;margin-bottom:4px;">
			<p style="margin:0 0 14px 0;font-size:11px;color:#94a3b8;">Format: X-XX-XXXXX</p>

			<label for="gd_ffl_modal_url" style="display:block;font-size:13px;font-weight:600;color:#0f172a;margin-bottom:6px;">FFL license URL</label>
			<input type="url" id="gd_ffl_modal_url" name="ffl_license_url" placeholder="https://drive.google.com/..." required class="ipsInput ipsInput--text" style="width:100%;box-sizing:border-box;margin-bottom:4px;">
			<p style="margin:0 0 18px 0;font-size:11px;color:#94a3b8;">Direct link to your scanned FFL on Dropbox, Google Drive, or your own host.</p>

			<div style="display:flex;gap:10px;justify-content:flex-end;">
				<a href="#" onclick="event.preventDefault();document.getElementById('gdFflModalOverlay').style.display='none';fetch('{$dismissUrl}',{method:'POST',credentials:'same-origin'});" style="padding:9px 16px;background:#fff;border:1px solid #e5e7eb;color:#475569;border-radius:6px;text-decoration:none;font-size:13px;font-weight:500;">Skip for now</a>
				<button type="submit" style="padding:9px 18px;background:#1e40af;color:#fff;border:0;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;">Submit for verification</button>
			</div>
		</form>
	</div>
</div>
<script>
(function(){
	var ov=document.getElementById('gdFflModalOverlay');if(!ov)return;
	document.addEventListener('keydown',function(e){if(e.key==='Escape'&&ov.style.display!=='none'){ov.style.display='none';fetch('{$dismissUrl}',{method:'POST',credentials:'same-origin'});}});
})();
</script>
HTML;
			}

			return $bannerHtml . $modalHtml;
		}
		catch ( \Throwable )
		{
			return '';
		}
	}

	/**
	 * Scalar summary of the dealer used by every tab template header.
	 */
	protected function dealerSummary(): array
	{
		$d    = $this->dealer;
		$tier = (string) $d->subscription_tier;

		$avatarUrl       = '';
		$coverPhotoUrl   = '';
		$coverOffset     = 0;
		try
		{
			$ipsMember = \IPS\Member::loggedIn();
			$avatarUrl = (string) ( $ipsMember->get_photo( true, false ) ?? '' );
			$cp        = $ipsMember->coverPhoto();
			if ( $cp->file )
			{
				$coverPhotoUrl = (string) $cp->file->url;
			}
			$coverOffset = (int) ( $cp->offset ?? 0 );
		}
		catch ( \Exception ) {}

		$lastVisit  = (string) ( $d->last_review_check ?? '2000-01-01 00:00:00' );
		$newReviews = 0;
		try
		{
			$newReviews = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_dealer_ratings', [
				'dealer_id=? AND created_at>? AND status=?',
				(int) $d->dealer_id, $lastVisit, 'approved'
			] )->first();
		}
		catch ( \Exception ) {}

		return [
			'dealer_id'             => (int) $d->dealer_id,
			'dealer_name'           => (string) $d->dealer_name,
			'dealer_slug'           => (string) ( $d->dealer_slug ?? '' ),
			'subscription_tier'     => $tier,
			'tier_label'            => ucfirst( $tier ),
			'tier_color'            => match( $tier ) {
				'founding'   => (string) ( \IPS\Settings::i()->gddealer_founding_badge_color   ?: '#b45309' ),
				'pro'        => (string) ( \IPS\Settings::i()->gddealer_pro_badge_color        ?: '#2563eb' ),
				'enterprise' => (string) ( \IPS\Settings::i()->gddealer_enterprise_badge_color ?: '#7c3aed' ),
				default      => (string) ( \IPS\Settings::i()->gddealer_basic_badge_color      ?: '#6b7280' ),
			},
			'onboarding_incomplete' => ( (string) ( $d->feed_delivery_mode ?? 'url' ) === 'url' )
                              ? empty( $d->feed_url )
                              : !(bool) \IPS\Db::i()->select( 'COUNT(*)', 'gd_dealer_feed_uploads', [ 'dealer_id=?', (int) $d->dealer_id ] )->first(),
			'suspended'             => (bool) $d->suspended,
			'disputes_suspended'    => (bool) ( $d->disputes_suspended ?? 0 ),
			'help_email'            => (string) ( \IPS\Settings::i()->gddealer_help_contact ?: 'dealers@gunrack.deals' ),
			'avatar_url'            => $avatarUrl,
			'cover_photo_url'       => $coverPhotoUrl,
			'cover_offset'          => $coverOffset,
			'new_reviews'           => $newReviews,
			'card_theme'            => (function() use ( $d ) {
				try {
					$raw = (string) ( $d->dealer_dashboard_prefs ?? '' );
					if ( $raw !== '' ) {
						$stored = json_decode( $raw, true );
						if ( is_array( $stored ) && isset( $stored['card_theme'] ) ) {
							return (string) $stored['card_theme'];
						}
					}
				} catch ( \Throwable ) {}
				return 'default';
			})(),
		];
	}

	/**
	 * URLs for the dashboard tabs — pre-built in the controller so
	 * templates never nest {url=...} inside conditionals (Rule #12.6).
	 */
	protected function tabUrls(): array
	{
		$base = 'app=gddealer&module=dealers&controller=dashboard&do=';
		return [
			'overview'      => (string) \IPS\Http\Url::internal( $base . 'overview' ),
			'feedSettings'  => (string) \IPS\Http\Url::internal( $base . 'feedSettings' ),
			'feedValidator' => (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=feedvalidator', 'front', 'dealers_feed_validator' ),
			'setupWizard'   => (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=setupwizard', 'front', 'dealers_setup_wizard' ),
			'listings'      => (string) \IPS\Http\Url::internal( $base . 'listings' ),
			'unmatched'     => (string) \IPS\Http\Url::internal( $base . 'unmatched' ),
			'categories'    => (string) \IPS\Http\Url::internal( $base . 'categories' ),
			'analytics'     => (string) \IPS\Http\Url::internal( $base . 'analytics' ),
			'reviews'       => (string) \IPS\Http\Url::internal( $base . 'reviews' ),
			'subscription'  => (string) \IPS\Http\Url::internal( $base . 'subscription' ),
			'customize'     => (string) \IPS\Http\Url::internal( $base . 'customize' ),
			'help'          => (string) \IPS\Http\Url::internal( $base . 'help' ),
		];
	}

	protected function supportUrl(): string
	{
		return (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=support' );
	}

	/**
	 * Returns the nav structure consumed by the new dealerShell template.
	 */
	protected function sidebarNav(): array
	{
		$urls        = $this->tabUrls();
		$summary     = $this->dealerSummary();
		$canSupport  = Dealer::canAccessSupport( \IPS\Member::loggedIn() );
		$supportUrl  = $canSupport ? $this->supportUrl() : '';

		$unmatched = 0;
		try
		{
			$unmatched = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_unmatched_upcs',
				[ 'dealer_id=? AND status=?', (int) $this->dealer->dealer_id, 'pending' ]
			)->first();
		}
		catch ( \Exception ) {}

		$unreviewedCats = 0;
		try
		{
			$unreviewedCats = \IPS\gddealer\Feed\CategoryMap::unreviewedCount( (int) $this->dealer->dealer_id );
		}
		catch ( \Throwable ) {}

		/* Setup wizard completion check - powers the warning badge on
		 * the Setup Wizard nav item until the dealer finishes the wizard. */
		$wizardComplete = false;
		try
		{
			$completedAt = \IPS\Db::i()->select( 'wizard_completed_at', 'gd_dealer_feed_config',
				[ 'dealer_id=?', (int) $this->dealer->dealer_id ]
			)->first();
			$wizardComplete = !empty( $completedAt );
		}
		catch ( \Exception ) {}

		$lang = \IPS\Member::loggedIn()->language();

		return [
			'main' => [
				'label' => 'Main',
				'items' => [
					[ 'key' => 'overview',  'label' => $lang->addToStack('gddealer_front_tab_overview'),
					  'url' => $urls['overview'],  'icon' => 'dashboard', 'badge' => null ],
					[ 'key' => 'listings',  'label' => $lang->addToStack('gddealer_front_tab_listings'),
					  'url' => $urls['listings'],  'icon' => 'listings',  'badge' => null ],
					[ 'key' => 'reviews',   'label' => $lang->addToStack('gddealer_front_tab_reviews'),
					  'url' => $urls['reviews'],   'icon' => 'reviews',
					  'badge' => $summary['new_reviews'] > 0 ? [ 'count' => $summary['new_reviews'], 'variant' => 'warn' ] : null ],
				]
			],
			'catalog' => [
				'label' => 'Catalog',
				'items' => [
					[ 'key' => 'setupWizard', 'label' => $lang->addToStack('gddealer_front_tab_wizard'),
					  'url' => $urls['setupWizard'], 'icon' => 'wizard',
					  'badge' => $wizardComplete ? null : [ 'count' => '!', 'variant' => 'warn' ] ],
					[ 'key' => 'feedSettings', 'label' => $lang->addToStack('gddealer_front_tab_feed'),
					  'url' => $urls['feedSettings'], 'icon' => 'feed', 'badge' => null ],
					[ 'key' => 'feedValidator', 'label' => $lang->addToStack('gddealer_front_tab_validator'),
					  'url' => $urls['feedValidator'], 'icon' => 'validator', 'badge' => null ],
					[ 'key' => 'unmatched', 'label' => $lang->addToStack('gddealer_front_tab_unmatched'),
					  'url' => $urls['unmatched'], 'icon' => 'unmatched',
					  'badge' => $unmatched > 0 ? [ 'count' => $unmatched, 'variant' => 'urgent' ] : null ],
					[ 'key' => 'categories', 'label' => $lang->addToStack('gddealer_front_tab_categories'),
					  'url' => $urls['categories'], 'icon' => 'listings',
					  'badge' => $unreviewedCats > 0 ? [ 'count' => $unreviewedCats, 'variant' => 'warn' ] : null ],
				]
			],
			'growth' => [
				'label' => 'Growth',
				'items' => [
					[ 'key' => 'analytics', 'label' => $lang->addToStack('gddealer_front_tab_analytics'),
					  'url' => $urls['analytics'], 'icon' => 'analytics', 'badge' => null ],
				]
			],
			'account' => [
				'label' => 'Account',
				'items' => array_values( array_filter( [
					[ 'key' => 'customize', 'label' => $lang->addToStack('gddealer_front_tab_edit_profile'),
					  'url' => $urls['customize'], 'icon' => 'profile', 'badge' => null ],
					[ 'key' => 'subscription', 'label' => $lang->addToStack('gddealer_front_tab_subscription'),
					  'url' => $urls['subscription'], 'icon' => 'billing', 'badge' => null ],
					[ 'key' => 'help', 'label' => $lang->addToStack('gddealer_front_tab_help'),
					  'url' => $urls['help'], 'icon' => 'help', 'badge' => null ],
					$canSupport ? [ 'key' => 'support', 'label' => $lang->addToStack('gddealer_support_nav'),
					  'url' => $supportUrl, 'icon' => 'support', 'badge' => null ] : null,
				] ) )
			],
		];
	}
}
