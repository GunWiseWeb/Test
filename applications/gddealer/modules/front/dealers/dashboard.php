<?php
/**
 * @brief       GD Dealer Manager — Frontend Dealer Dashboard
 * @package     IPS Community Suite
 * @subpackage  GD Dealer Manager
 * @since       16 Apr 2026
 *
 * Section 3.11 of the spec. Accessible at /dealers/dashboard. Visible
 * only to members with an active gd_dealer_feed_config row. Six tabs:
 * Overview, Feed Settings, Listings, Unmatched UPCs, Analytics,
 * Subscription.
 *
 * Until IPS Commerce + Stripe is live the dealer row is created via
 * the ACP "Manual Onboard" action; when feed_url is NULL or empty we
 * render an "onboarding incomplete" banner with a Feed Settings CTA.
 */

namespace IPS\gddealer\modules\front\dealers;

use IPS\gddealer\Attachment\Helper as AttachHelper;
use IPS\gddealer\Dealer\Dealer;
use IPS\gddealer\Dispute\EventLogger;
use IPS\gddealer\Feed\Importer;
use IPS\gddealer\Listing\Listing;
use IPS\gddealer\Log\ImportLog;
use IPS\gddealer\Unmatched\UnmatchedUpc;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _dashboard extends \IPS\Dispatcher\Controller
{
	use \IPS\gddealer\Traits\DealerShellTrait;

	public static bool $csrfProtected = TRUE;

	/** Current dealer loaded from the logged-in member */
	protected ?Dealer $dealer = null;

	public function execute(): void
	{
		$member = \IPS\Member::loggedIn();

		if ( !$member->member_id )
		{
			\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=join' ) );
			return;
		}

		if ( $member->isAdmin() )
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers', 'admin' )
			);
			return;
		}

		try
		{
			$this->dealer = Dealer::load( (int) $member->member_id );
		}
		catch ( \OutOfRangeException )
		{
			$this->dealer = null;
		}

		/* Member is in a dealer group (via Commerce subscription) but has
		   not yet completed the one-time self-service registration form. */
		if ( $this->dealer === null && Dealer::isDealerMember( $member ) )
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=join&do=register' )
			);
			return;
		}

		if ( $this->dealer === null )
		{
			$contactEmail = (string) ( \IPS\Settings::i()->gddealer_help_contact ?: 'dealers@gunrack.deals' );
			\IPS\Output::i()->title  = $member->language()->addToStack( 'gddealer_frontend_dashboard_title' );
			\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->notSubscribed(
				(string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=join' ),
				$contactEmail
			);
			return;
		}

		parent::execute();
	}

	/** Default tab = overview */
	protected function manage()
	{
		$this->overview();
	}

	/* ---------------- Tab: Overview ---------------- */

	protected function overview()
	{
		$dealer = $this->dealer;

		$active = $out = $unmatched = $clicks7 = $clicks30 = 0;
		try
		{
			$active = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_dealer_listings', [ 'dealer_id=? AND listing_status=?', (int) $dealer->dealer_id, Listing::STATUS_ACTIVE ] )->first();
		}
		catch ( \Exception ) {}
		try
		{
			$out = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_dealer_listings', [ 'dealer_id=? AND listing_status=?', (int) $dealer->dealer_id, Listing::STATUS_OUT_OF_STOCK ] )->first();
		}
		catch ( \Exception ) {}
		try
		{
			$unmatched = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_unmatched_upcs', [ 'dealer_id=? AND admin_excluded=?', (int) $dealer->dealer_id, 0 ] )->first();
		}
		catch ( \Exception ) {}
		try
		{
			$clicks7 = (int) \IPS\Db::i()->select( 'SUM(click_count_7d)', 'gd_dealer_listings', [ 'dealer_id=?', (int) $dealer->dealer_id ] )->first();
		}
		catch ( \Exception ) {}
		try
		{
			$clicks30 = (int) \IPS\Db::i()->select( 'SUM(click_count_30d)', 'gd_dealer_listings', [ 'dealer_id=?', (int) $dealer->dealer_id ] )->first();
		}
		catch ( \Exception ) {}

		$lastLogRow = [];
		try
		{
			$lastLogRow = \IPS\Db::i()->select( '*', 'gd_dealer_import_log', [ 'dealer_id=?', (int) $dealer->dealer_id ], 'run_start DESC', [ 0, 1 ] )->first();
		}
		catch ( \Exception ) {}

		$lastImport = null;
		if ( !empty( $lastLogRow ) )
		{
			$lastImport = [
				'when_label'    => \IPS\DateTime::ts( strtotime( (string) $lastLogRow['run_start'] ) )->relative(),
				'status'        => (string) ( $lastLogRow['status']            ?? 'completed' ),
				'records'       => (int)    ( $lastLogRow['records_total']     ?? 0 ),
				'updated'       => (int)    ( $lastLogRow['records_updated']   ?? 0 ),
				'unmatched'     => (int)    ( $lastLogRow['records_unmatched'] ?? 0 ),
			];
		}

		$setupSteps = [
			[
				'key'   => 'ffl',
				'label' => self::fflStatusKey( $dealer ) === 'verified'
				            ? 'FFL verified'
				            : ( self::fflStatusKey( $dealer ) === 'pending'
				                ? 'FFL verification pending'
				                : 'Upload FFL license for verification' ),
				'done'  => self::fflStatusKey( $dealer ) === 'verified',
				'hint'  => match( self::fflStatusKey( $dealer ) )
				{
					'pending'  => 'Submitted — awaiting admin review (usually within 24 hours)',
					'rejected' => 'Rejected: ' . (string) ( $dealer->ffl_rejection_reason ?? '' ),
					'blocked'  => 'Blocked after 3 rejections — contact support',
					'verified' => '',
					default    => 'Submit your FFL number + license URL on the Edit profile page',
				},
			],
			[
				'key'   => 'business',
				'label' => 'Add business details',
				'done'  => !empty( $dealer->dealer_name ) && !empty( $dealer->public_phone ?? null ),
				'hint'  => '',
			],
			[
				'key'   => 'logo',
				'label' => 'Upload logo',
				'done'  => !empty( trim( (string) ( $dealer->logo_url ?? '' ) ) ),
				'hint'  => '',
			],
			[
				'key'   => 'feed',
				'label' => 'Configure product feed',
				'done'  => ( (string) ( $dealer->feed_delivery_mode ?? 'url' ) === 'manual' )
				            ? (bool) \IPS\Db::i()->select( 'COUNT(*)', 'gd_dealer_feed_uploads', [ 'dealer_id=?', (int) $dealer->dealer_id ] )->first()
				            : !empty( $dealer->feed_url ),
				'hint'  => '',
			],
			[
				'key'   => 'shipping',
				'label' => 'Set shipping rules',
				'done'  => !empty( trim( (string) ( $dealer->shipping_policy ?? '' ) ) ),
				'hint'  => '',
			],
		];
		$stepsDone  = count( array_filter( $setupSteps, fn($s) => $s['done'] ) );
		$stepsTotal = count( $setupSteps );
		$stepsPct   = $stepsTotal > 0 ? (int) round( ( $stepsDone / $stepsTotal ) * 100 ) : 0;
		$activeHit = false;
		foreach ( $setupSteps as &$step ) {
			if ( $step['done'] ) { $step['state'] = 'done'; continue; }
			if ( !$activeHit ) { $step['state'] = 'active'; $activeHit = true; continue; }
			$step['state'] = 'pending';
		}
		unset( $step );

		$publicProfileUrl = (string) \IPS\Http\Url::internal(
			'app=gddealer&module=dealers&controller=profile&dealer_slug=' . urlencode( (string) $dealer->dealer_slug ),
			'front', 'dealers_profile', (string) $dealer->dealer_slug
		);

		$prefs = $this->dashboardPrefs();

		$data = [
			'dealer'             => $this->dealerSummary(),
			'tab_urls'           => $this->tabUrls(),
			'stats'              => [
				'active'        => $active,
				'out'           => $out,
				'unmatched'     => $unmatched,
				'clicks_7'      => $clicks7,
				'clicks_30'     => $clicks30,
				'listing_cap'   => $dealer->getListingCap(),
				'cap_label'     => $dealer->getListingCap() >= PHP_INT_MAX ? 'Unlimited' : number_format( $dealer->getListingCap() ),
			],
			'last_import'        => $lastImport,
			'setup_steps'        => $setupSteps,
			'steps_done'         => $stepsDone,
			'steps_total'        => $stepsTotal,
			'steps_pct'          => $stepsPct,
			'public_profile_url' => $publicProfileUrl,
			'regenerate_preview_url' => (string) \IPS\Http\Url::internal(
				'app=gddealer&module=dealers&controller=dashboard&do=regenerateSlug&preview=1'
			),
			'regenerate_confirm_url' => (string) \IPS\Http\Url::internal(
				'app=gddealer&module=dealers&controller=dashboard&do=regenerateSlug'
			)->csrf(),
			'prefs'              => $prefs,
			'card_theme'         => (string) ( $prefs['card_theme'] ?? 'default' ),
			'card_styles'        => [
				'bg'     => (string) ( $prefs['card_bg']     ?? '#ffffff' ),
				'border' => (string) ( $prefs['card_border'] ?? '#e0e0e0' ),
				'color'  => (string) ( $prefs['card_color']  ?? '#111827' ),
				'label'  => (string) ( $prefs['card_label']  ?? '#6b7280' ),
			],
		];

		$badgeIds = [
			'chip-light-blue', 'chip-light-green', 'chip-dark-blue', 'chip-dark-black',
			'bar-light-blue', 'bar-light-green', 'bar-dark-blue', 'bar-dark-black',
		];
		$badgeLabels = [
			'chip-light-blue'  => 'Chip · Brand Blue (light)',
			'chip-light-green' => 'Chip · Green Accent (light)',
			'chip-dark-blue'   => 'Chip · Dark Slate + Blue',
			'chip-dark-black'  => 'Chip · Black + Gold',
			'bar-light-blue'   => 'Bar · Brand Blue (light)',
			'bar-light-green'  => 'Bar · Green (light)',
			'bar-dark-blue'    => 'Bar · Solid Brand Blue',
			'bar-dark-black'   => 'Bar · Black + Gold',
		];
		$badgeSizes = [
			'chip-light-blue'  => [ 220, 56 ],
			'chip-light-green' => [ 220, 56 ],
			'chip-dark-blue'   => [ 220, 56 ],
			'chip-dark-black'  => [ 220, 56 ],
			'bar-light-blue'   => [ 200, 40 ],
			'bar-light-green'  => [ 200, 40 ],
			'bar-dark-blue'    => [ 200, 40 ],
			'bar-dark-black'   => [ 200, 40 ],
		];
		$badges = [];
		foreach ( $badgeIds as $bid )
		{
			$badges[ $bid ] = [
				'id'     => $bid,
				'label'  => $badgeLabels[ $bid ],
				'svg'    => (string) \IPS\Http\Url::external(
					rtrim( (string) \IPS\Settings::i()->base_url, '/' )
					. '/applications/gddealer/interface/badge/badge.php?id=' . urlencode( $bid ) . '&v=126'
				),
				'width'  => (int) $badgeSizes[ $bid ][0],
				'height' => (int) $badgeSizes[ $bid ][1],
			];
		}
		$dealerProfileUrl = (string) \IPS\Http\Url::internal(
			'app=gddealer&module=dealers&controller=profile&dealer_slug=' . urlencode( (string) ( $this->dealer->dealer_slug ?? '' ) ),
			'front', 'dealers_profile', (string) ( $this->dealer->dealer_slug ?? '' )
		)->setQueryString( 'utm_source', 'verified_badge' );
		/* Pre-encode the badge map as JSON so the template can drop it directly
		   into a data-badges='{...|raw}' attribute. IPS template parser handles
		   the {var|raw} pattern inside single-quoted attributes specifically
		   for JSON-in-attribute use cases (see system/Theme/Theme.php:4034). */
		$data['verified_badge'] = [
			'show'        => !empty( $this->dealer->ffl_verified_at ?? null ),
			'badges'      => $badges,
			'badges_json' => (string) json_encode( $badges, JSON_HEX_QUOT | JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP ),
			'profile_url' => $dealerProfileUrl,
		];

		$ann = [ 'show' => false, 'body' => '', 'style' => 'info', 'version' => '' ];
		$annBody = trim( (string) ( \IPS\Settings::i()->gddealer_announce_body ?? '' ) );
		if ( ( \IPS\Settings::i()->gddealer_announce_enabled ?? 0 ) && $annBody !== '' )
		{
			$version = substr( md5( $annBody ), 0, 12 );
			$dismissed = false;
			try {
				$dismissed = (bool) \IPS\Db::i()->select( 'COUNT(*)', 'gd_dealer_announce_dismiss',
					[ 'member_id=? AND version=?', (int) \IPS\Member::loggedIn()->member_id, $version ] )->first();
			} catch ( \Throwable ) {}
			$ann = [
				'show'    => !$dismissed,
				'body'    => $annBody,
				'style'   => ( \IPS\Settings::i()->gddealer_announce_style === 'warning' ? 'warning' : 'info' ),
				'version' => $version,
			];
		}
		$data['announcement'] = $ann;
		$data['announce_dismiss_url'] = (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=dismissAnnounce', 'front' );
		$data['csrfKey'] = \IPS\Session::i()->csrfKey;

		\IPS\Output::i()->jsFiles = array_merge( \IPS\Output::i()->jsFiles, \IPS\Output::i()->js( 'dismiss.js', 'gddealer', 'interface' ) );

		$this->output( 'overview',
			\IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->overview( $data )
		);
	}

	/* ---------------- Dismiss announcement banner (AJAX) ---------------- */

	protected function dismissAnnounce(): void
	{
		\IPS\Session::i()->csrfCheck();
		$version = substr( preg_replace( '/[^a-f0-9]/', '', (string) ( \IPS\Request::i()->version ?? '' ) ), 0, 12 );
		if ( $version !== '' && \IPS\Member::loggedIn()->member_id )
		{
			try {
				\IPS\Db::i()->insert( 'gd_dealer_announce_dismiss', [
					'member_id'    => (int) \IPS\Member::loggedIn()->member_id,
					'version'      => $version,
					'dismissed_at' => time(),
				], TRUE );
			} catch ( \Throwable ) {}
		}
		if ( \IPS\Request::i()->isAjax() )
		{
			\IPS\Output::i()->json( [ 'ok' => true ] );
			return;
		}
		\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard', 'front' ) );
	}

	/* ---------------- Tab: Edit Dealer Profile (was Customize) ---------------- */

	protected function customize(): void
	{
		$dealer   = $this->dealer;
		$dealerId = (int) $dealer->dealer_id;

		$profile = [
			'dealer_name'      => (string) ( $dealer->dealer_name ?? '' ),
			'dealer_slug'      => (string) ( $dealer->dealer_slug ?? '' ),
			'tagline'          => (string) ( $dealer->tagline ?? '' ),
			'about'            => (string) ( $dealer->about ?? '' ),
			'logo_url'         => (string) ( $dealer->logo_url ?? '' ),
			'cover_url'        => (string) ( $dealer->cover_url ?? '' ),
			'public_phone'     => (string) ( $dealer->public_phone ?? '' ),
			'public_email'     => (string) ( $dealer->public_email ?? '' ),
			'website_url'      => (string) ( $dealer->website_url ?? '' ),
			'address_street'   => (string) ( $dealer->address_street ?? '' ),
			'address_city'     => (string) ( $dealer->address_city ?? '' ),
			'address_state'    => (string) ( $dealer->address_state ?? '' ),
			'address_zip'      => (string) ( $dealer->address_zip ?? '' ),
			'address_public'   => (int) ( $dealer->address_public ?? 1 ) === 1,
			'social_facebook'  => (string) ( $dealer->social_facebook ?? '' ),
			'social_instagram' => (string) ( $dealer->social_instagram ?? '' ),
			'social_youtube'   => (string) ( $dealer->social_youtube ?? '' ),
			'social_twitter'   => (string) ( $dealer->social_twitter ?? '' ),
			'social_tiktok'    => (string) ( $dealer->social_tiktok ?? '' ),
			'shipping_policy'  => (string) ( $dealer->shipping_policy ?? '' ),
			'return_policy'    => (string) ( $dealer->return_policy ?? '' ),
			'additional_notes' => (string) ( $dealer->additional_notes ?? '' ),
			'brand_color'      => (string) ( $dealer->brand_color ?? '#1E40AF' ),
			'ffl_number'           => (string) ( $dealer->ffl_number ?? '' ),
			'ffl_license_url'      => (string) ( $dealer->ffl_license_url ?? '' ),
			'ffl_submitted_at'     => (int) ( $dealer->ffl_submitted_at ?? 0 ),
			'ffl_verified_at'      => (int) ( $dealer->ffl_verified_at ?? 0 ),
			'ffl_rejection_reason' => (string) ( $dealer->ffl_rejection_reason ?? '' ),
			'ffl_rejection_count'  => (int) ( $dealer->ffl_rejection_count ?? 0 ),
			'ffl_status'           => self::fflStatusKey( $dealer ),
			'ffl_status_label'     => self::fflStatusLabel( $dealer ),
			'ffl_blocked'          => (int) ( $dealer->ffl_rejection_count ?? 0 ) >= 3,
		];

		$pmRaw = (string) ( $dealer->payment_methods ?? '' );
		$profile['payment_methods'] = $pmRaw !== '' ? array_filter( array_map( 'trim', explode( ',', $pmRaw ) ) ) : [];

		$hoursRaw = (string) ( $dealer->hours_json ?? '' );
		$hours = [];
		if ( $hoursRaw !== '' )
		{
			$decoded = json_decode( $hoursRaw, true );
			if ( is_array( $decoded ) ) { $hours = $decoded; }
		}
		$days = [ 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ];
		$dayLabels = [ 'mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday' ];
		$profile['hours'] = [];
		foreach ( $days as $d )
		{
			$profile['hours'][ $d ] = [
				'label'  => $dayLabels[ $d ],
				'open'   => (string) ( $hours[ $d ]['open']   ?? '09:00' ),
				'close'  => (string) ( $hours[ $d ]['close']  ?? '17:00' ),
				'closed' => (bool)   ( $hours[ $d ]['closed'] ?? ( $d === 'sun' ) ),
			];
		}

		$prefs = $this->dashboardPrefs();

		$states = [ 'AL'=>'Alabama','AK'=>'Alaska','AZ'=>'Arizona','AR'=>'Arkansas','CA'=>'California','CO'=>'Colorado','CT'=>'Connecticut','DE'=>'Delaware','DC'=>'District of Columbia','FL'=>'Florida','GA'=>'Georgia','HI'=>'Hawaii','ID'=>'Idaho','IL'=>'Illinois','IN'=>'Indiana','IA'=>'Iowa','KS'=>'Kansas','KY'=>'Kentucky','LA'=>'Louisiana','ME'=>'Maine','MD'=>'Maryland','MA'=>'Massachusetts','MI'=>'Michigan','MN'=>'Minnesota','MS'=>'Mississippi','MO'=>'Missouri','MT'=>'Montana','NE'=>'Nebraska','NV'=>'Nevada','NH'=>'New Hampshire','NJ'=>'New Jersey','NM'=>'New Mexico','NY'=>'New York','NC'=>'North Carolina','ND'=>'North Dakota','OH'=>'Ohio','OK'=>'Oklahoma','OR'=>'Oregon','PA'=>'Pennsylvania','RI'=>'Rhode Island','SC'=>'South Carolina','SD'=>'South Dakota','TN'=>'Tennessee','TX'=>'Texas','UT'=>'Utah','VT'=>'Vermont','VA'=>'Virginia','WA'=>'Washington','WV'=>'West Virginia','WI'=>'Wisconsin','WY'=>'Wyoming' ];

		$paymentOptions = [
			'visa'      => 'Visa',
			'mc'        => 'Mastercard',
			'amex'      => 'American Express',
			'discover'  => 'Discover',
			'check'     => 'Check',
			'mo'        => 'Money order',
			'cash'      => 'Cash',
			'layaway'   => 'Layaway',
			'financing' => 'Financing',
		];

		$forumProfileUrl = (string) \IPS\Http\Url::internal(
			'app=core&module=members&controller=profile&id=' . (int) \IPS\Member::loggedIn()->member_id,
			'front', 'profile', [ \IPS\Member::loggedIn()->members_seo_name ]
		);

		$publicProfileUrl = (string) \IPS\Http\Url::internal(
			'app=gddealer&module=dealers&controller=profile&dealer_slug=' . urlencode( (string) $dealer->dealer_slug ),
			'front', 'dealers_profile', (string) $dealer->dealer_slug
		);

		$saveUrl = (string) \IPS\Http\Url::internal(
			'app=gddealer&module=dealers&controller=dashboard&do=saveCustomize'
		)->csrf();
		$cancelUrl = (string) \IPS\Http\Url::internal(
			'app=gddealer&module=dealers&controller=dashboard&do=overview'
		);
		$csrfKey = (string) \IPS\Session::i()->csrfKey;

		$data = [
			'dealer'             => $this->dealerSummary(),
			'tab_urls'           => $this->tabUrls(),
			'profile'            => $profile,
			'prefs'              => $prefs,
			'states'             => $states,
			'payment_options'    => $paymentOptions,
			'forum_profile_url'  => $forumProfileUrl,
			'public_profile_url' => $publicProfileUrl,
			'save_url'           => $saveUrl,
			'cancel_url'         => $cancelUrl,
			'csrf_key'           => $csrfKey,
			'regenerate_preview_url' => (string) \IPS\Http\Url::internal(
				'app=gddealer&module=dealers&controller=dashboard&do=regenerateSlug&preview=1'
			),
			'regenerate_confirm_url' => (string) \IPS\Http\Url::internal(
				'app=gddealer&module=dealers&controller=dashboard&do=regenerateSlug'
			)->csrf(),
			'reset_action_url' => (string) \IPS\Http\Url::internal(
				'app=gddealer&module=dealers&controller=dashboard&do=confirmReset'
			)->csrf(),
			'dealer_name_escaped' => htmlspecialchars( (string) ( $dealer->dealer_name ?? '' ), ENT_QUOTES, 'UTF-8' ),
		];

		$this->output( 'customize', \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->dashboardCustomize( $data ) );
	}

	protected function saveCustomize(): void
	{
		\IPS\Session::i()->csrfCheck();
		$req      = \IPS\Request::i();
		$dealerId = (int) $this->dealer->dealer_id;

		$theme = (string) ( $req->card_theme ?? 'default' );
		if ( !in_array( $theme, [ 'default', 'dark', 'accent' ], true ) ) { $theme = 'default'; }
		$prefs = [
			'show_active'      => (bool) ( $req->show_active      ?? false ),
			'show_outofstock'  => (bool) ( $req->show_outofstock  ?? false ),
			'show_unmatched'   => (bool) ( $req->show_unmatched   ?? false ),
			'show_clicks_7d'   => (bool) ( $req->show_clicks_7d   ?? false ),
			'show_clicks_30d'  => (bool) ( $req->show_clicks_30d  ?? false ),
			'show_last_import' => (bool) ( $req->show_last_import ?? false ),
			'show_profile_url' => (bool) ( $req->show_profile_url ?? false ),
			'card_theme'       => $theme,
		];

		$days = [ 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ];
		$hoursOut = [];
		foreach ( $days as $d )
		{
			$closed = (bool) ( $req->{'hours_' . $d . '_closed'} ?? false );
			$open   = (string) ( $req->{'hours_' . $d . '_open'}  ?? '09:00' );
			$close  = (string) ( $req->{'hours_' . $d . '_close'} ?? '17:00' );
			if ( !preg_match( '/^\d{2}:\d{2}$/', $open ) )  { $open  = '09:00'; }
			if ( !preg_match( '/^\d{2}:\d{2}$/', $close ) ) { $close = '17:00'; }
			$hoursOut[ $d ] = [ 'open' => $open, 'close' => $close, 'closed' => $closed ];
		}

		$validPM = [ 'visa','mc','amex','discover','check','mo','cash','layaway','financing' ];
		$pmIn  = (array) ( $req->payment_methods ?? [] );
		$pmOut = implode( ',', array_values( array_intersect( $validPM, $pmIn ) ) );

		$brandColor = (string) ( $req->brand_color ?? '#1E40AF' );
		if ( !preg_match( '/^#[0-9A-Fa-f]{6}$/', $brandColor ) ) { $brandColor = '#1E40AF'; }

		$state = strtoupper( substr( (string) ( $req->address_state ?? '' ), 0, 2 ) );
		if ( $state !== '' && !preg_match( '/^[A-Z]{2}$/', $state ) ) { $state = ''; }

		/* FFL verification fields. Format validation happens here (before $update
		   is built). Submitting/changing either field resets status to pending
		   (wipes rejection reason, sets submitted_at). Dealers at 3+ rejections
		   are blocked — we silently skip their FFL updates. */
		$newFflNumber     = trim( (string) ( $req->ffl_number ?? '' ) );
		$newFflNumber     = substr( $newFflNumber, 0, 32 );
		$newFflLicenseUrl = trim( (string) ( $req->ffl_license_url ?? '' ) );
		$newFflLicenseUrl = substr( $newFflLicenseUrl, 0, 500 );

		/* Validate FFL number format: X-XX-XXXXX (e.g. 3-37-06855). Empty is OK. */
		if ( $newFflNumber !== '' && !preg_match( '/^\d-\d{2}-\d{5}$/', $newFflNumber ) )
		{
			\IPS\Output::i()->error(
				'FFL number must match the format X-XX-XXXXX (for example, 3-37-06855). You can find this on your Federal Firearms License.',
				'3S103/F', 400, ''
			);
			return;
		}

		$currentFflNumber     = (string) ( $this->dealer->ffl_number ?? '' );
		$currentFflLicenseUrl = (string) ( $this->dealer->ffl_license_url ?? '' );
		$rejectionCount       = (int) ( $this->dealer->ffl_rejection_count ?? 0 );
		$fflBlocked           = $rejectionCount >= 3;

		$currentlyRejected = !empty( $this->dealer->ffl_rejection_reason ?? '' )
		                  && empty( $this->dealer->ffl_verified_at ?? null );

		$fflChanged = ( $newFflNumber !== $currentFflNumber )
		           || ( $newFflLicenseUrl !== $currentFflLicenseUrl );

		$shouldUpdate = !$fflBlocked && ( $fflChanged || $currentlyRejected );

		$fflUpdate = [];
		if ( $shouldUpdate )
		{
			$fflUpdate['ffl_number']      = $newFflNumber !== '' ? $newFflNumber : null;
			$fflUpdate['ffl_license_url'] = $newFflLicenseUrl !== '' ? $newFflLicenseUrl : null;

			if ( $newFflNumber !== '' && $newFflLicenseUrl !== '' )
			{
				$fflUpdate['ffl_submitted_at']     = time();
				$fflUpdate['ffl_verified_at']      = null;
				$fflUpdate['ffl_verified_by']      = null;
				$fflUpdate['ffl_rejection_reason'] = null;
			}
		}

		$update = [
			'dealer_name'            => substr( (string) ( $req->dealer_name ?? '' ), 0, 150 ),
			'tagline'                => substr( (string) ( $req->tagline     ?? '' ), 0, 160 ),
			'about'                  => (string) ( $req->about ?? '' ),
			'logo_url'               => substr( (string) ( $req->logo_url ?? '' ), 0, 500 ),
			'cover_url'              => substr( (string) ( $req->cover_url ?? '' ), 0, 500 ),
			'public_phone'           => substr( (string) ( $req->public_phone ?? '' ), 0, 32 ),
			'public_email'           => substr( (string) ( $req->public_email ?? '' ), 0, 160 ),
			'website_url'            => substr( (string) ( $req->website_url ?? '' ), 0, 500 ),
			'address_street'         => substr( (string) ( $req->address_street ?? '' ), 0, 255 ),
			'address_city'           => substr( (string) ( $req->address_city ?? '' ), 0, 100 ),
			'address_state'          => $state,
			'address_zip'            => substr( (string) ( $req->address_zip ?? '' ), 0, 10 ),
			'address_public'         => (bool) ( $req->address_public ?? false ) ? 1 : 0,
			'hours_json'             => json_encode( $hoursOut ),
			'social_facebook'        => substr( (string) ( $req->social_facebook ?? '' ), 0, 500 ),
			'social_instagram'       => substr( (string) ( $req->social_instagram ?? '' ), 0, 500 ),
			'social_youtube'         => substr( (string) ( $req->social_youtube ?? '' ), 0, 500 ),
			'social_twitter'         => substr( (string) ( $req->social_twitter ?? '' ), 0, 500 ),
			'social_tiktok'          => substr( (string) ( $req->social_tiktok ?? '' ), 0, 500 ),
			'shipping_policy'        => (string) ( $req->shipping_policy ?? '' ),
			'return_policy'          => (string) ( $req->return_policy ?? '' ),
			'additional_notes'       => (string) ( $req->additional_notes ?? '' ),
			'payment_methods'        => $pmOut,
			'brand_color'            => $brandColor,
			'dealer_dashboard_prefs' => json_encode( $prefs ),
		];

		/* Merge FFL updates into the main update array (so they save in a single query). */
		$update = array_merge( $update, $fflUpdate );

		try
		{
			\IPS\Db::i()->update( 'gd_dealer_feed_config', $update, [ 'dealer_id=?', $dealerId ] );
		}
		catch ( \Throwable ) {}

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=customize' )
		);
	}

	/**
	 * v1.0.182: Dealer-side regenerate URL slug. Mirrors the admin version
	 * but scoped to the logged-in dealer's own record (security: the dealer
	 * can't regenerate someone else's slug).
	 *
	 * GET ?preview=1 - JSON preview for the confirmation modal
	 * POST           - actually performs the regenerate. CSRF-checked.
	 */
	protected function regenerateSlug(): void
	{
		$dealer   = $this->dealer;
		$dealerId = (int) $dealer->dealer_id;

		if ( $dealerId <= 0 )
		{
			\IPS\Output::i()->error( 'gddealer_regenerate_slug_failed', '2GDD500/1', 400 );
			return;
		}

		/* Preview mode for the confirmation modal */
		if ( !empty( \IPS\Request::i()->preview ) )
		{
			$preview = \IPS\gddealer\Dealer\Slug::previewRegenerate( $dealerId );
			\IPS\Output::i()->json( [
				'old_slug'     => (string) $preview['old_slug'],
				'new_slug'     => (string) $preview['new_slug'],
				'would_change' => (bool) $preview['would_change'],
			] );
			return;
		}

		/* Real run - CSRF required */
		\IPS\Session::i()->csrfCheck();

		$result = \IPS\gddealer\Dealer\Slug::regenerate( $dealerId, 'dealer_regenerate' );

		$redirectUrl = \IPS\Http\Url::internal(
			'app=gddealer&module=dealers&controller=dashboard&do=customize'
		);

		if ( $result['success'] )
		{
			\IPS\Output::i()->redirect( $redirectUrl,
				\IPS\Member::loggedIn()->language()->addToStack( 'gddealer_regenerate_slug_done' )
			);
		}
		else if ( $result['message'] === 'unchanged' )
		{
			\IPS\Output::i()->redirect( $redirectUrl,
				\IPS\Member::loggedIn()->language()->addToStack( 'gddealer_regenerate_slug_unchanged' )
			);
		}
		else
		{
			\IPS\Output::i()->error( 'gddealer_regenerate_slug_failed', '2GDD500/2', 500 );
		}
	}

	protected function dismissFflModal(): void
	{
		\IPS\Session::i()->csrfCheck();

		try
		{
			$prefs = [];
			$raw   = (string) ( $this->dealer->dealer_dashboard_prefs ?? '' );
			if ( $raw !== '' )
			{
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) ) { $prefs = $decoded; }
			}
			$prefs['ffl_modal_dismissed_at'] = time();

			\IPS\Db::i()->update( 'gd_dealer_feed_config',
				[ 'dealer_dashboard_prefs' => json_encode( $prefs ) ],
				[ 'dealer_id=?', (int) $this->dealer->dealer_id ]
			);

			\IPS\Output::i()->json( [ 'ok' => true ] );
		}
		catch ( \Throwable )
		{
			\IPS\Output::i()->json( [ 'ok' => false ], 500 );
		}
	}

	protected function submitFfl(): void
	{
		\IPS\Session::i()->csrfCheck();

		if ( !$this->dealer )
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard' ),
				'dealer_not_found'
			);
			return;
		}

		$newFflNumber     = substr( trim( (string) ( \IPS\Request::i()->ffl_number ?? '' ) ), 0, 32 );
		$newFflLicenseUrl = substr( trim( (string) ( \IPS\Request::i()->ffl_license_url ?? '' ) ), 0, 500 );

		if ( $newFflNumber === '' || $newFflLicenseUrl === '' )
		{
			\IPS\Output::i()->error(
				'Both the FFL number and a license URL are required.',
				'3GDD237/1', 400, ''
			);
			return;
		}

		if ( !preg_match( '/^\d-\d{2}-\d{5}$/', $newFflNumber ) )
		{
			\IPS\Output::i()->error(
				'FFL number must match the format X-XX-XXXXX (for example, 3-37-06855).',
				'3GDD237/2', 400, ''
			);
			return;
		}

		if ( (int) ( $this->dealer->ffl_rejection_count ?? 0 ) >= 3 )
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard' ),
				'FFL verification is blocked after 3 rejections — contact support.'
			);
			return;
		}

		try
		{
			\IPS\Db::i()->update( 'gd_dealer_feed_config', [
				'ffl_number'           => $newFflNumber,
				'ffl_license_url'      => $newFflLicenseUrl,
				'ffl_submitted_at'     => time(),
				'ffl_verified_at'      => null,
				'ffl_verified_by'      => null,
				'ffl_rejection_reason' => null,
			], [ 'dealer_id=?', (int) $this->dealer->dealer_id ] );
		}
		catch ( \Throwable )
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard' ),
				'Something went wrong saving your FFL. Please try again.'
			);
			return;
		}

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard' ),
			'Your FFL was submitted for verification. An admin will review it within 24 hours.'
		);
	}

	/**
	 * Load dashboard preferences for the current dealer. Returns the
	 * stored JSON merged over the defaults so missing keys never break
	 * the template.
	 */
	protected function dashboardPrefs(): array
	{
		$defaults = [
			'show_active'      => true,
			'show_outofstock'  => true,
			'show_unmatched'   => true,
			'show_clicks_7d'   => true,
			'show_clicks_30d'  => true,
			'show_last_import' => true,
			'show_profile_url' => true,
			'card_theme'       => 'default',
		];

		$raw = (string) ( $this->dealer->dealer_dashboard_prefs ?? '' );
		if ( $raw !== '' )
		{
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) )
			{
				$defaults = array_merge( $defaults, $decoded );
			}
		}

		$theme = $defaults['card_theme'];
		$themeStyles = match( $theme ) {
			'dark'   => [
				'card_bg'     => '#1e2d3d',
				'card_border' => '#334155',
				'card_color'  => '#ffffff',
				'card_label'  => '#94a3b8',
				'num_success' => '#86efac',
				'num_danger'  => '#fca5a5',
				'num_warning' => '#fcd34d',
				'num_default' => '#ffffff',
			],
			'accent' => [
				'card_bg'     => '#1e40af',
				'card_border' => '#1e3a8a',
				'card_color'  => '#ffffff',
				'card_label'  => '#bfdbfe',
				'num_success' => '#bbf7d0',
				'num_danger'  => '#fecaca',
				'num_warning' => '#fef3c7',
				'num_default' => '#ffffff',
			],
			default  => [
				'card_bg'     => '#ffffff',
				'card_border' => 'var(--i-border-color,#e0e0e0)',
				'card_color'  => '#111827',
				'card_label'  => '#6b7280',
				'num_success' => '#16a34a',
				'num_danger'  => '#dc2626',
				'num_warning' => '#f59e0b',
				'num_default' => 'inherit',
			],
		};
		$defaults = array_merge( $defaults, $themeStyles );

		return $defaults;
	}

	/* ---------------- Tab: Feed Settings ---------------- */

	protected function feedSettings()
	{
		/* v158: read-only feed settings page. All editing happens in the
		 * setup wizard now. This page shows current config + the manual
		 * upload widget + recent activity. */
		$dealer = $this->dealer;
		$currentMode = (string) ( $dealer->feed_delivery_mode ?? 'url' );

		/* Pull wizard config row to surface completion status + mapping. */
		$wizardCfg = [];
		try
		{
			$row = \IPS\Db::i()->select( '*', 'gd_dealer_feed_config',
				[ 'dealer_id=?', (int) $dealer->dealer_id ]
			)->first();
			$wizardCfg = is_array( $row ) ? $row : [];
		}
		catch ( \Throwable ) {}

		/* Count mapped fields + defaults from the saved field_mapping JSON. */
		$mappedCount  = 0;
		$defaultCount = 0;
		$rawMappingJson = (string) ( $dealer->field_mapping ?? '' );
		if ( $rawMappingJson !== '' )
		{
			$decoded = json_decode( $rawMappingJson, true );
			if ( is_array( $decoded ) )
			{
				if ( isset( $decoded['_defaults'] ) && is_array( $decoded['_defaults'] ) )
				{
					$defaultCount = count( $decoded['_defaults'] );
				}
				foreach ( $decoded as $key => $value )
				{
					if ( $key === '_defaults' ) { continue; }
					$mappedCount++;
				}
			}
		}

		$creds = $dealer->getCredentials() ?? '';
		$hasCredentials = trim( (string) $creds ) !== '';

		$recentUploads = [];
		try
		{
			foreach ( \IPS\Db::i()->select( '*', 'gd_dealer_feed_uploads',
				[ 'dealer_id=?', (int) $dealer->dealer_id ],
				'uploaded_at DESC',
				[ 0, 10 ]
			) as $u )
			{
				$recentUploads[] = [
					'upload_id'       => (int) $u['upload_id'],
					'upload_format'   => (string) $u['upload_format'],
					'file_name'       => (string) ( $u['file_name'] ?? '' ),
					'file_size_bytes' => (int) ( $u['file_size_bytes'] ?? 0 ),
					'uploaded_at'     => (int) $u['uploaded_at'],
					'uploaded_ago'    => \IPS\DateTime::ts( (int) $u['uploaded_at'] )->relative(),
				];
			}
		}
		catch ( \Exception ) {}

		$importLog = [];
		try {
			foreach ( ImportLog::loadForDealer( (int) $dealer->dealer_id, 15 ) as $log )
			{
				$importLog[] = [
					'when'      => date( 'M j, Y g:i A', strtotime( (string) $log->run_start ) ),
					'when_ago'  => \IPS\DateTime::ts( strtotime( (string) $log->run_start ) )->relative(),
					'status'    => (string) $log->status,
					'records'   => (int) $log->records_total,
					'new'       => (int) ( $log->records_created ?? 0 ),
					'updated'   => (int) ( $log->records_updated ?? 0 ),
					'unmatched' => (int) ( $log->records_unmatched ?? 0 ),
					'error'     => (string) ( $log->error_log ?? '' ),
				];
			}
		} catch ( \Exception ) {}

		$latest = $importLog[0] ?? null;
		$wizardCompleted = !empty( $wizardCfg['wizard_completed_at'] );
		$pendingUploads  = is_array( $recentUploads ) ? count( $recentUploads ) : 0;

		$syncHealth = 'healthy';
		$syncTitle  = 'Feed is healthy';
		$syncSub    = 'Ready to import when your feed updates';

		if ( !$wizardCompleted ) {
			/* State 1: wizard not finished. */
			$syncHealth = 'warn';
			$syncTitle  = 'Feed not configured yet';
			$syncSub    = 'Run the Setup Wizard to get started.';
		} elseif ( !$latest ) {
			/* States 2 & 3: wizard done but no imports have run yet. */
			if ( $pendingUploads > 0 ) {
				/* State 3: file uploaded, awaiting next scheduler tick. */
				$syncHealth = 'warn';
				$syncTitle  = 'Feed pending first import';
				$syncSub    = 'Your uploaded file is queued and will be processed on the next scheduled run. You can also trigger an import now from the Feed Settings page.';
			} elseif ( $currentMode === 'manual' ) {
				/* State 2a: manual mode but no uploads yet. */
				$syncHealth = 'warn';
				$syncTitle  = 'Feed configured, ready for first upload';
				$syncSub    = 'Upload a feed file below to start syncing.';
			} else {
				/* State 2b: URL mode, awaiting first scheduled fetch. */
				$syncHealth = 'warn';
				$syncTitle  = 'Feed configured, awaiting first sync';
				$syncSub    = 'Feed configured. Your first import will run on the next scheduled sync. You can also trigger an import now from the Feed Settings page.';
			}
		} elseif ( $latest['status'] === 'failed' ) {
			/* State 5: last import failed. */
			$syncHealth = 'error';
			$syncTitle  = 'Last import failed';
			$syncSub    = $latest['when_ago'] . ' — ' . ( $latest['error'] ?: 'Check configuration' );
		} elseif ( $latest['status'] === 'partial' ) {
			/* State 6: last import was partial. */
			$syncHealth = 'warn';
			$syncTitle  = 'Last import was partial';
			$syncSub    = $latest['when_ago'];
		} else {
			/* State 4: latest import succeeded. */
			$syncTitle  = 'Feed is healthy';
			$syncSub    = 'Last imported ' . $latest['when_ago'];
		}

		$wizardUrl = (string) \IPS\Http\Url::internal(
			'app=gddealer&module=dealers&controller=setupwizard',
			'front',
			'dealers_setup_wizard'
		);

		$wizardDoneFlash = (int) ( \IPS\Request::i()->wizard_done ?? 0 ) === 1;

		$data = [
			'dealer'              => $this->dealerSummary(),
			'tab_urls'            => $this->tabUrls(),
			'delivery_mode'       => $currentMode,
			'feed_url'            => (string) ( $dealer->feed_url ?? '' ),
			'feed_format'         => (string) ( $dealer->feed_format ?? '' ),
			'auth_type'           => (string) ( $dealer->auth_type ?? 'none' ),
			'has_credentials'     => $hasCredentials,
			'mapped_count'        => $mappedCount,
			'default_count'       => $defaultCount,
			'wizard_url'          => $wizardUrl,
			'wizard_completed_at' => !empty( $wizardCfg['wizard_completed_at'] ) ? date( 'M j, Y g:i A', strtotime( (string) $wizardCfg['wizard_completed_at'] ) ) : '',
			'wizard_done_flash'   => $wizardDoneFlash,
			'import_log'          => $importLog,
			'recent_uploads'      => $recentUploads,
			'upload_url'          => (string) \IPS\Http\Url::internal(
				'app=gddealer&module=dealers&controller=dashboard&do=uploadFeed'
			)->csrf(),
			'latest'              => $latest,
			'sync_health'         => $syncHealth,
			'sync_title'          => $syncTitle,
			'sync_sub'            => $syncSub,
		];

		$this->output( 'feedSettings',
			\IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->feedSettings( $data )
		);
	}

	protected function uploadFeed(): void
	{
		\IPS\Session::i()->csrfCheck();

		$dealer = $this->dealer;

		if ( (string) ( $dealer->feed_delivery_mode ?? 'url' ) !== 'manual' )
		{
			\IPS\Output::i()->error( 'Switch to Manual upload mode in Feed Settings before uploading a file.', '3S402/F', 400 );
			return;
		}

		$form = new \IPS\Helpers\Form( 'gd_feed_upload_form', 'gddealer_front_feed_upload_save' );
		$form->add( new \IPS\Helpers\Form\Upload( 'gddealer_front_feed_file', NULL, TRUE, [
			'storageExtension' => 'gddealer_FeedUpload',
			'allowedFileTypes' => [ 'csv', 'xml', 'json', 'tsv' ],
			'maxFileSize'      => 50,
		] ) );

		if ( $values = $form->values() )
		{
			/** @var \IPS\File $file */
			$file = $values['gddealer_front_feed_file'];
			if ( !$file )
			{
				\IPS\Output::i()->error( 'No file received.', '3S402/F', 400 );
				return;
			}

			$ext = strtolower( pathinfo( (string) $file->originalFilename, PATHINFO_EXTENSION ) );
			$format = match( $ext )
			{
				'xml'         => 'xml',
				'json'        => 'json',
				'csv','tsv' => 'csv',
				default       => (string) ( $dealer->feed_format ?? 'csv' ),
			};

			try
			{
				\IPS\Db::i()->insert( 'gd_dealer_feed_uploads', [
					'dealer_id'       => (int) $dealer->dealer_id,
					'upload_format'   => $format,
					'file_url'        => (string) $file,
					'file_name'       => (string) ( $file->originalFilename ?? '' ),
					'file_size_bytes' => (int) ( $file->filesize() ?? 0 ),
					'uploaded_at'     => time(),
					'uploaded_by'     => (int) \IPS\Member::loggedIn()->member_id,
				] );

				if ( (string) $dealer->feed_format !== $format )
				{
					$dealer->feed_format = $format;
					$dealer->save();
				}
			}
			catch ( \Throwable $e )
			{
				\IPS\Output::i()->error( 'Failed to record upload: ' . $e->getMessage(), '3S402/F', 500 );
				return;
			}

			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=feedSettings' ),
				'Feed file uploaded. The next import cycle will pick it up automatically.'
			);
			return;
		}

		$data = [
			'dealer'   => $this->dealerSummary(),
			'tab_urls' => $this->tabUrls(),
			'form'     => (string) $form,
		];

		$this->output( 'feedSettings',
			\IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->feedUploadForm( $data )
		);
	}

	/** Runs a feed import on demand from the Feed Settings tab. */
	protected function manualImport()
	{
		\IPS\Session::i()->csrfCheck();

		$dealer = $this->dealer;
		$mode = (string) ( $dealer->feed_delivery_mode ?? 'url' );
		if ( $mode === 'url' && empty( $dealer->feed_url ) )
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=feedSettings' ),
				'gddealer_front_feed_url_required'
			);
			return;
		}

		$log = Importer::run( $dealer );

		$msg = $log->status === 'completed'
			? sprintf( 'Import complete: %d total, %d new, %d updated, %d unmatched',
				(int) $log->records_total, (int) $log->records_created, (int) $log->records_updated, (int) $log->records_unmatched )
			: 'Import failed: ' . ( $log->error_log ?? 'unknown error' );

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=feedSettings' ),
			$msg
		);
	}

	/* ---------------- Tab: Listings ---------------- */

	protected function listings()
	{
		$dealer = $this->dealer;

		$filter = (string) ( \IPS\Request::i()->filter ?? '' );
		$search = trim( (string) ( \IPS\Request::i()->q ?? '' ) );

		$where = [ [ 'dealer_id=?', (int) $dealer->dealer_id ] ];

		if ( in_array( $filter, [ Listing::STATUS_ACTIVE, Listing::STATUS_OUT_OF_STOCK, Listing::STATUS_SUSPENDED, Listing::STATUS_DISCONTINUED ], true ) )
		{
			$where[] = [ 'listing_status=?', $filter ];
		}
		elseif ( $filter === 'in_stock' )
		{
			$where[] = [ 'in_stock=?', 1 ];
		}
		elseif ( $filter === 'out_of_stock' )
		{
			$where[] = [ 'in_stock=?', 0 ];
		}

		if ( $search !== '' )
		{
			$where[] = [ 'upc LIKE ?', '%' . $search . '%' ];
		}

		$page    = max( 1, (int) ( \IPS\Request::i()->page ?? 1 ) );
		$perPage = 50;
		$offset  = ( $page - 1 ) * $perPage;

		$total = 0;
		try
		{
			$total = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_dealer_listings', $where )->first();
		}
		catch ( \Exception ) {}

		$rows = [];
		try
		{
			foreach ( \IPS\Db::i()->select( '*', 'gd_dealer_listings', $where, 'last_seen_in_feed DESC', [ $offset, $perPage ] ) as $r )
			{
				$rows[] = [
					'upc'            => (string) $r['upc'],
					'dealer_price'   => '$' . number_format( (float) $r['dealer_price'], 2 ),
					'in_stock'       => (bool) $r['in_stock'],
					'condition'      => (string) $r['condition'],
					'listing_status' => (string) $r['listing_status'],
					'last_updated'   => (string) ( $r['last_seen_in_feed'] ?? '' ),
				];
			}
		}
		catch ( \Exception ) {}

		if ( isset( \IPS\Request::i()->export ) )
		{
			$this->exportListingsCsv( $where );
			return;
		}

		$exportUrl = (string) \IPS\Http\Url::internal(
			'app=gddealer&module=dealers&controller=dashboard&do=listings&export=1&filter=' . rawurlencode( $filter ) . '&q=' . rawurlencode( $search )
		);

		$pages = (int) ceil( $total / $perPage );
		$baseUrl = (string) \IPS\Http\Url::internal(
			'app=gddealer&module=dealers&controller=dashboard&do=listings&filter=' . rawurlencode( $filter ) . '&q=' . rawurlencode( $search )
		);

		$statusCounts = [
			'all'          => 0,
			'active'       => 0,
			'out_of_stock' => 0,
			'suspended'    => 0,
			'discontinued' => 0,
		];
		try {
			$statusCounts['all'] = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_dealer_listings',
				[ 'dealer_id=?', (int) $dealer->dealer_id ]
			)->first();
			foreach ( \IPS\Db::i()->select( 'listing_status, COUNT(*) AS cnt', 'gd_dealer_listings',
				[ 'dealer_id=?', (int) $dealer->dealer_id ],
				null, null, 'listing_status'
			) as $row ) {
				$k = (string) $row['listing_status'];
				if ( isset( $statusCounts[$k] ) ) {
					$statusCounts[$k] = (int) $row['cnt'];
				}
			}
		} catch ( \Exception ) {}

		$filterUrls = [
			'all'          => (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=listings' ),
			'active'       => (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=listings&filter=active' ),
			'out_of_stock' => (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=listings&filter=out_of_stock' ),
			'suspended'    => (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=listings&filter=suspended' ),
			'discontinued' => (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=listings&filter=discontinued' ),
		];

		if ( $search !== '' ) {
			foreach ( $filterUrls as $k => $u ) {
				$filterUrls[$k] = $u . ( str_contains( $u, '?' ) ? '&' : '?' ) . 'q=' . rawurlencode( $search );
			}
		}

		$data = [
			'dealer'         => $this->dealerSummary(),
			'tab_urls'       => $this->tabUrls(),
			'rows'           => $rows,
			'total'          => $total,
			'page'           => $page,
			'pages'          => $pages,
			'base_url'       => $baseUrl,
			'active_filter'  => $filter ?: 'all',
			'search'         => $search,
			'export_url'     => $exportUrl,
			'status_counts'  => $statusCounts,
			'filter_urls'    => $filterUrls,
			'per_page'       => $perPage,
		];

		$this->output( 'listings',
			\IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->listings( $data )
		);
	}

	/** Stream a CSV export of the current listing query. */
	protected function exportListingsCsv( array $where ): void
	{
		$fh = fopen( 'php://temp', 'r+' );
		fputcsv( $fh, [ 'upc', 'dealer_price', 'in_stock', 'condition', 'listing_status', 'last_seen_in_feed' ] );
		try
		{
			foreach ( \IPS\Db::i()->select( '*', 'gd_dealer_listings', $where, 'last_seen_in_feed DESC' ) as $r )
			{
				fputcsv( $fh, [
					(string) $r['upc'],
					number_format( (float) $r['dealer_price'], 2, '.', '' ),
					(int) $r['in_stock'],
					(string) $r['condition'],
					(string) $r['listing_status'],
					(string) ( $r['last_seen_in_feed'] ?? '' ),
				] );
			}
		}
		catch ( \Exception ) {}

		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh );

		\IPS\Output::i()->sendOutput( $csv, 200, 'text/csv', [
			'Content-Disposition' => 'attachment; filename="dealer-listings-' . (int) $this->dealer->dealer_id . '.csv"',
		]);
	}

	/* ---------------- Tab: Unmatched UPCs ---------------- */

	protected function unmatched()
	{
		$dealer = $this->dealer;

		$rows = UnmatchedUpc::loadForDealer( (int) $dealer->dealer_id, 0, 200 );

		$out = [];
		foreach ( $rows as $r )
		{
			$upcId = (int) $r['id'];

			$excludeUrl = (string) \IPS\Http\Url::internal(
				'app=gddealer&module=dealers&controller=dashboard&do=excludeUnmatched&unmatched_id=' . $upcId
			)->csrf();

			$reportUrl = (string) \IPS\Http\Url::internal(
				'app=gddealer&module=dealers&controller=dashboard&do=reportUnmatched&upc_id=' . $upcId
			)->csrf();

			$snapshotUrl = (string) \IPS\Http\Url::internal(
				'app=gddealer&module=dealers&controller=dashboard&do=viewSnapshot&upc_id=' . $upcId
			);

			$out[] = [
				'id'                  => $upcId,
				'upc'                 => (string) $r['upc'],
				'first_seen'          => (string) $r['first_seen'],
				'last_seen'           => (string) $r['last_seen'],
				'occurrence_count'    => (int) $r['occurrence_count'],
				'exclude_url'         => $excludeUrl,
				'report_url'          => $reportUrl,
				'snapshot_url'        => $snapshotUrl,
				'has_snapshot'        => !empty( $r['snapshot_json'] ),
				'already_reported'    => !empty( $r['dealer_reported_at'] ),
				'dealer_reported_at'  => (string) ( $r['dealer_reported_at'] ?? '' ),
			];
		}

		if ( isset( \IPS\Request::i()->export ) )
		{
			$this->exportUnmatchedCsv( $rows );
			return;
		}

		$exportUrl = (string) \IPS\Http\Url::internal(
			'app=gddealer&module=dealers&controller=dashboard&do=unmatched&export=1'
		);

		$data = [
			'dealer'     => $this->dealerSummary(),
			'tab_urls'   => $this->tabUrls(),
			'rows'       => $out,
			'total'      => \count( $out ),
			'export_url' => $exportUrl,
		];

		$this->output( 'unmatched',
			\IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->unmatched( $data )
		);
	}

	protected function excludeUnmatched()
	{
		\IPS\Session::i()->csrfCheck();

		$id = (int) \IPS\Request::i()->unmatched_id;
		try
		{
			$row = \IPS\Db::i()->select( '*', 'gd_unmatched_upcs', [ 'id=? AND dealer_id=?', $id, (int) $this->dealer->dealer_id ] )->first();
		}
		catch ( \UnderflowException )
		{
			\IPS\Output::i()->error( 'node_error', '2GDD220/1', 404 );
			return;
		}

		UnmatchedUpc::exclude( (int) $row['id'] );

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=unmatched' ),
			'gddealer_front_unmatched_excluded'
		);
	}

	/** Stream unmatched UPCs as CSV. */
	protected function exportUnmatchedCsv( array $rows ): void
	{
		$fh = fopen( 'php://temp', 'r+' );
		fputcsv( $fh, [ 'upc', 'first_seen', 'last_seen', 'occurrence_count' ] );
		foreach ( $rows as $r )
		{
			fputcsv( $fh, [
				(string) $r['upc'],
				(string) $r['first_seen'],
				(string) $r['last_seen'],
				(int) $r['occurrence_count'],
			] );
		}
		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh );

		\IPS\Output::i()->sendOutput( $csv, 200, 'text/csv', [
			'Content-Disposition' => 'attachment; filename="unmatched-upcs-' . (int) $this->dealer->dealer_id . '.csv"',
		]);
	}

	/* ---------------- Tab: Data Flags (v1.0.221) ---------------- */

	protected function dataFlags(): void
	{
		$dealer    = $this->dealer;
		$dealerId  = (int) $dealer->dealer_id;
		$page      = max( 1, (int) ( \IPS\Request::i()->page ?? 1 ) );
		$perPage   = 50;
		$offset    = ( $page - 1 ) * $perPage;

		$statusFilter = (string) ( \IPS\Request::i()->flag_status ?? 'new' );
		$validStatuses = [ 'new', 'submitted', 'resolved', 'all' ];
		if ( !in_array( $statusFilter, $validStatuses, true ) ) {
			$statusFilter = 'new';
		}

		$where = [ [ 'dealer_id=?', $dealerId ] ];
		if ( $statusFilter !== 'all' ) {
			$where[] = [ 'status=?', $statusFilter ];
		}

		$total = 0;
		$flags = [];
		$newCount = 0;
		try {
			$total = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_dealer_data_flags', $where )->first();
			$newCount = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_dealer_data_flags', [ 'dealer_id=? AND status=?', $dealerId, 'new' ] )->first();

			foreach ( \IPS\Db::i()->select( '*', 'gd_dealer_data_flags', $where, 'created_at DESC', [ $offset, $perPage ] ) as $row ) {
				$submitUrl = (string) \IPS\Http\Url::internal(
					'app=gddealer&module=dealers&controller=dashboard&do=submitDataFlag&flag_id=' . (int) $row['id']
				)->csrf();

				$catalogTitle = '';
				try {
					$catalogTitle = (string) \IPS\Db::i()->select( 'title', 'gd_catalog', [ 'upc=?', $row['upc'] ] )->first();
				} catch ( \Throwable ) {}

				$flags[] = [
					'id'            => (int) $row['id'],
					'upc'           => (string) $row['upc'],
					'catalog_title' => $catalogTitle,
					'flag_type'     => (string) $row['flag_type'],
					'dealer_value'  => (string) $row['dealer_value'],
					'catalog_value' => (string) $row['catalog_value'],
					'status'        => (string) $row['status'],
					'submitted_at'  => (string) ( $row['submitted_at'] ?? '' ),
					'admin_note'    => (string) ( $row['admin_note'] ?? '' ),
					'created_at'    => (string) $row['created_at'],
					'submit_url'    => $submitUrl,
				];
			}
		} catch ( \Throwable ) {}

		$pagination = '';
		if ( $total > $perPage ) {
			$pagination = (string) \IPS\Theme::i()->getTemplate( 'global', 'core', 'global' )->pagination(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=dataFlags', 'front', 'dealers_dashboard' ),
				(int) ceil( $total / $perPage ),
				$page,
				$perPage
			);
		}

		$data = [
			'flags'         => $flags,
			'total'         => $total,
			'status_filter' => $statusFilter,
			'pagination'    => $pagination,
			'new_count'     => $newCount,
		];

		$this->output( 'dataFlags',
			\IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->dataFlags( $data )
		);
	}

	protected function submitDataFlag(): void
	{
		\IPS\Session::i()->csrfCheck();

		$flagId   = (int) \IPS\Request::i()->flag_id;
		$dealerId = (int) $this->dealer->dealer_id;
		$now      = date( 'Y-m-d H:i:s' );

		try {
			$flag = \IPS\Db::i()->select( '*', 'gd_dealer_data_flags', [ 'id=? AND dealer_id=?', $flagId, $dealerId ] )->first();

			if ( $flag['status'] === 'new' ) {
				\IPS\Db::i()->update( 'gd_dealer_data_flags', [
					'status'       => 'submitted',
					'submitted_at' => $now,
					'updated_at'   => $now,
				], [ 'id=?', $flagId ] );
			}
		} catch ( \Throwable ) {}

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=dataFlags', 'front', 'dealers_dashboard' )
		);
	}

	/* ---------------- Tab: Categories (v1.0.188) ---------------- */

	protected function categories(): void
	{
		$dealer   = $this->dealer;
		$dealerId = (int) $dealer->dealer_id;

		$mappings = \IPS\gddealer\Feed\CategoryMap::allForDealer( $dealerId );

		$byCanonical = [];
		foreach ( $mappings as $m )
		{
			$c = $m['canonical'];
			if ( !isset( $byCanonical[ $c ] ) )
			{
				$byCanonical[ $c ] = [ 'count' => 0, 'rows' => 0 ];
			}
			$byCanonical[ $c ]['count'] += $m['occurrence_count'];
			$byCanonical[ $c ]['rows']++;
		}

		$valid      = \IPS\gddealer\Feed\CategoryNormalizer::VALID;
		$unreviewed = \IPS\gddealer\Feed\CategoryMap::unreviewedCount( $dealerId );
		$saveUrl    = (string) \IPS\Http\Url::internal(
			'app=gddealer&module=dealers&controller=dashboard&do=saveCategoryMap'
		)->csrf();

		$data = [
			'dealer'        => $this->dealerSummary(),
			'tab_urls'      => $this->tabUrls(),
			'mappings'      => $mappings,
			'by_canonical'  => $byCanonical,
			'valid_options' => $valid,
			'unreviewed'    => $unreviewed,
			'save_url'      => $saveUrl,
			'csrf_key'      => (string) \IPS\Session::i()->csrfKey,
		];

		$this->output( 'categories', \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->dashboardCategories( $data ) );
	}

	protected function saveCategoryMap(): void
	{
		\IPS\Session::i()->csrfCheck();

		$dealerId = (int) $this->dealer->dealer_id;
		$raw = \IPS\Request::i()->canonical ?? [];
		if ( !is_array( $raw ) ) { $raw = []; }

		$updates = [];
		foreach ( $raw as $id => $canonical )
		{
			$updates[ (int) $id ] = (string) $canonical;
		}

		$applied = \IPS\gddealer\Feed\CategoryMap::applyDealerEdits( $dealerId, $updates );

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=categories' ),
			\IPS\Member::loggedIn()->language()->addToStack( 'gddealer_categories_saved', false, [ 'sprintf' => [ $applied ] ] )
		);
	}

	/* ---------------- Tab: Analytics (Pro / Enterprise) ---------------- */

	protected function analytics(): void
	{
		$dealer   = $this->dealer;
		$dealerId = (int) $dealer->dealer_id;

		$range = (string) ( \IPS\Request::i()->range ?? '30' );
		if ( !in_array( $range, [ '7', '30', '90', 'ytd' ], true ) ) { $range = '30'; }

		switch ( $range ) {
			case '7':   $startDate = date( 'Y-m-d', strtotime( '-6 days' ) );  $rangeDays = 7;  break;
			case '90':  $startDate = date( 'Y-m-d', strtotime( '-89 days' ) ); $rangeDays = 90; break;
			case 'ytd': $startDate = date( 'Y-01-01' );                        $rangeDays = (int) ( ( strtotime( 'today' ) - strtotime( $startDate ) ) / 86400 ) + 1; break;
			default:    $startDate = date( 'Y-m-d', strtotime( '-29 days' ) ); $rangeDays = 30;
		}
		$endDate   = date( 'Y-m-d' );
		$prevStart = date( 'Y-m-d', strtotime( $startDate . ' -' . $rangeDays . ' days' ) );
		$prevEnd   = date( 'Y-m-d', strtotime( $startDate . ' -1 day' ) );

		$clicksNow = 0;
		$clicksPrev = 0;
		try {
			$clicksNow = (int) \IPS\Db::i()->select( 'COALESCE(SUM(click_count),0)', 'gd_click_daily',
				[ 'dealer_id=? AND click_date >= ? AND click_date <= ?', $dealerId, $startDate, $endDate ]
			)->first();
		} catch ( \Throwable ) {}
		try {
			$clicksPrev = (int) \IPS\Db::i()->select( 'COALESCE(SUM(click_count),0)', 'gd_click_daily',
				[ 'dealer_id=? AND click_date >= ? AND click_date <= ?', $dealerId, $prevStart, $prevEnd ]
			)->first();
		} catch ( \Throwable ) {}
		$clickDeltaPct = $clicksPrev > 0 ? (int) round( ( ( $clicksNow - $clicksPrev ) / $clicksPrev ) * 100 ) : null;

		$latestSnapDate = null;
		try {
			$latestSnapDate = (string) \IPS\Db::i()->select( 'MAX(snapshot_date)', 'gd_dealer_rank_snapshot',
				[ 'dealer_id=?', $dealerId ]
			)->first();
		} catch ( \Throwable ) {}

		$tierCounts    = [ 'lowest' => 0, 'close' => 0, 'overpriced' => 0, 'only' => 0 ];
		$snapshotTotal = 0;
		if ( $latestSnapDate ) {
			try {
				foreach ( \IPS\Db::i()->select( 'tier, COUNT(*) AS cnt', 'gd_dealer_rank_snapshot',
					[ 'dealer_id=? AND snapshot_date=?', $dealerId, $latestSnapDate ],
					null, null, 'tier'
				) as $row ) {
					$t = (string) $row['tier'];
					if ( isset( $tierCounts[ $t ] ) ) { $tierCounts[ $t ] = (int) $row['cnt']; }
					$snapshotTotal += (int) $row['cnt'];
				}
			} catch ( \Throwable ) {}
		}

		$lowestCount     = $tierCounts['lowest'] + $tierCounts['only'];
		$overpricedCount = $tierCounts['overpriced'];

		$priceDrops = 0;
		try {
			$rangeStart = $startDate . ' 00:00:00';
			$rangeEnd   = $endDate . ' 23:59:59';
			$firstPrices = [];
			$lastPrices  = [];
			foreach ( \IPS\Db::i()->select(
				'upc, price',
				'gd_price_history',
				[ 'dealer_id=? AND recorded_at >= ? AND recorded_at <= ?', $dealerId, $rangeStart, $rangeEnd ],
				'recorded_at ASC'
			) as $row ) {
				$u = (string) $row['upc'];
				if ( !isset( $firstPrices[ $u ] ) ) {
					$firstPrices[ $u ] = (float) $row['price'];
				}
				$lastPrices[ $u ] = (float) $row['price'];
			}
			foreach ( $firstPrices as $u => $first ) {
				if ( $lastPrices[ $u ] < $first ) {
					$priceDrops++;
				}
			}
		} catch ( \Throwable $e ) {
			try { \IPS\Log::log( $e, 'gddealer_listings' ); } catch ( \Throwable ) {}
		}

		$days = [];
		for ( $i = 0; $i < $rangeDays; $i++ ) {
			$d = date( 'Y-m-d', strtotime( $startDate . ' +' . $i . ' days' ) );
			$days[ $d ] = 0;
		}
		try {
			foreach ( \IPS\Db::i()->select(
				'click_date, SUM(click_count) AS daily',
				'gd_click_daily',
				[ 'dealer_id=? AND click_date >= ? AND click_date <= ?', $dealerId, $startDate, $endDate ],
				'click_date ASC', null, 'click_date'
			) as $row ) {
				$d = (string) $row['click_date'];
				if ( isset( $days[ $d ] ) ) { $days[ $d ] = (int) $row['daily']; }
			}
		} catch ( \Throwable ) {}

		$chartSeries = [];
		foreach ( $days as $d => $n ) {
			$chartSeries[] = [ 'date' => $d, 'label' => date( 'M j', strtotime( $d ) ), 'count' => $n ];
		}
		$chartMax = max( 1, !empty( $chartSeries ) ? max( array_column( $chartSeries, 'count' ) ) : 0 );

		$chartPoints = [];
		$plotLeft = 40; $plotRight = 590; $plotTop = 30; $plotBottom = 180;
		$plotW = $plotRight - $plotLeft; $plotH = $plotBottom - $plotTop;
		$n = count( $chartSeries );
		foreach ( $chartSeries as $i => $pt ) {
			$x = $plotLeft + ( $n <= 1 ? 0 : ( $i / ( $n - 1 ) ) * $plotW );
			$y = $plotBottom - ( $pt['count'] / $chartMax ) * $plotH;
			$chartPoints[] = round( $x, 1 ) . ',' . round( $y, 1 );
		}
		$chartPolyline = implode( ' ', $chartPoints );
		$chartArea = count( $chartPoints ) > 0
			? $chartPoints[0] . ' ' . implode( ' ', array_slice( $chartPoints, 1 ) ) . ' ' . $plotRight . ',' . $plotBottom . ' ' . $plotLeft . ',' . $plotBottom
			: '';

		$chartXLabels = [];
		if ( $n >= 2 ) {
			$labelIdxs = $n >= 5 ? [ 0, (int) ( $n * 0.25 ), (int) ( $n * 0.5 ), (int) ( $n * 0.75 ), $n - 1 ] : range( 0, $n - 1 );
			foreach ( $labelIdxs as $idx ) {
				$x = $plotLeft + ( $idx / ( $n - 1 ) ) * $plotW;
				$chartXLabels[] = [ 'x' => round( $x, 1 ), 'label' => $chartSeries[ $idx ]['label'] ];
			}
		}

		$chartYLabels = [];
		for ( $t = 0; $t <= 4; $t++ ) {
			$value = $chartMax * ( 1 - $t / 4 );
			$y = $plotTop + ( $t * $plotH / 4 );
			$chartYLabels[] = [ 'y' => round( $y, 1 ) + 4, 'label' => number_format( round( $value ) ) ];
		}

		$topListings = [];
		try {
			$i = 0;
			foreach ( \IPS\Db::i()->select(
				'upc, COUNT(*) AS clicks',
				'gd_click_log',
				[ 'dealer_id=? AND clicked_at >= ? AND clicked_at <= ?', $dealerId, $startDate . ' 00:00:00', $endDate . ' 23:59:59' ],
				'clicks DESC',
				[ 0, 10 ],
				'upc'
			) as $row ) {
				$productName = '';
				try {
					$productName = (string) \IPS\Db::i()->select( 'title', 'gd_catalog',
						[ 'upc=?', (string) $row['upc'] ], null, [ 0, 1 ]
					)->first();
				} catch ( \Throwable ) {}
				$topListings[] = [
					'rank'   => ++$i,
					'upc'    => (string) $row['upc'],
					'name'   => $productName !== '' ? $productName : ( 'UPC ' . $row['upc'] ),
					'clicks' => (int) $row['clicks'],
				];
			}
		} catch ( \Throwable $e ) {
			try { \IPS\Log::log( $e, 'gddealer_analytics' ); } catch ( \Throwable ) {}
		}

		$geoDistribution = [];
		$geoTotal = 0;
		try {
			foreach ( \IPS\Db::i()->select(
				'user_state, COUNT(*) AS clicks',
				'gd_click_log',
				[ 'dealer_id=? AND clicked_at >= ? AND clicked_at <= ? AND user_state IS NOT NULL AND user_state != ?', $dealerId, $startDate . ' 00:00:00', $endDate . ' 23:59:59', '' ],
				'clicks DESC',
				[ 0, 10 ],
				'user_state'
			) as $row ) {
				$geoTotal += (int) $row['clicks'];
				$geoDistribution[] = [
					'state'  => (string) $row['user_state'],
					'clicks' => (int) $row['clicks'],
					'pct'    => 0,
				];
			}
			if ( $geoTotal > 0 ) {
				foreach ( $geoDistribution as &$g ) {
					$g['pct'] = round( ( $g['clicks'] / $geoTotal ) * 100, 1 );
				}
				unset( $g );
			}
		} catch ( \Throwable $e ) {
			try { \IPS\Log::log( $e, 'gddealer_analytics' ); } catch ( \Throwable ) {}
		}

		$rangeUrls = [];
		foreach ( [ '7', '30', '90', 'ytd' ] as $r ) {
			$rangeUrls[ $r ] = (string) \IPS\Http\Url::internal(
				'app=gddealer&module=dealers&controller=dashboard&do=analytics&range=' . $r
			);
		}

		$data = [
			'dealer'            => $this->dealerSummary(),
			'tab_urls'          => $this->tabUrls(),
			'active_range'      => $range,
			'range_urls'        => $rangeUrls,
			'range_label'       => $range === 'ytd' ? 'Year to date' : ( 'Last ' . $range . ' days' ),
			'clicks_now'        => $clicksNow,
			'clicks_prev'       => $clicksPrev,
			'clicks_delta_pct'  => $clickDeltaPct,
			'lowest_count'      => $lowestCount,
			'overpriced_count'  => $overpricedCount,
			'price_drops'       => $priceDrops,
			'tier_counts'       => $tierCounts,
			'snapshot_total'    => $snapshotTotal,
			'snapshot_date'     => $latestSnapDate,
			'chart_series'      => $chartSeries,
			'chart_polyline'    => $chartPolyline,
			'chart_area'        => $chartArea,
			'chart_y_labels'    => $chartYLabels,
			'chart_x_labels'    => $chartXLabels,
			'top_listings'      => $topListings,
			'geo_distribution'  => $geoDistribution,
			'geo_total'         => $geoTotal,
		];

		$data['tier_pct'] = [];
		if ( $snapshotTotal > 0 ) {
			foreach ( $tierCounts as $k => $v ) {
				$data['tier_pct'][ $k ] = round( ( $v / $snapshotTotal ) * 100, 1 );
			}
		} else {
			$data['tier_pct'] = [ 'lowest' => 0, 'close' => 0, 'overpriced' => 0, 'only' => 0 ];
		}

		$this->output( 'analytics',
			\IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->analytics( $data )
		);
	}

	/* ---------------- Tab: Help ---------------- */

	protected function help(): void
	{
		$s = \IPS\Settings::i();
		$requirements = array_filter( array_map( 'trim',
			explode( "\n", (string) ( $s->gddealer_help_requirements ?? '' ) )
		) );

		$helpData = [
			'dealer'       => $this->dealerSummary(),
			'tab_urls'     => $this->tabUrls(),
			'support_url'  => $this->supportUrl(),
			'intro'        => (string) ( $s->gddealer_help_intro ?? '' ),
			'step1'        => (string) ( $s->gddealer_help_step1 ?? '' ),
			'step2'        => (string) ( $s->gddealer_help_step2 ?? '' ),
			'step3'        => (string) ( $s->gddealer_help_step3 ?? '' ),
			'step4'        => (string) ( $s->gddealer_help_step4 ?? '' ),
			'step5'        => (string) ( $s->gddealer_help_step5 ?? '' ),
			'step2_csv'    => (string) ( $s->gddealer_help_step2_csv ?? '' ),
			'step2_json'   => (string) ( $s->gddealer_help_step2_json ?? '' ),
			'step2_xml'    => (string) ( $s->gddealer_help_step2_xml ?? '' ),
			'requirements' => array_values( $requirements ),
			'contact'      => (string) ( $s->gddealer_help_contact ?? 'dealers@gunrack.deals' ),
			'sync_basic'   => (string) ( $s->gddealer_help_sync_basic ?? 'Every 6 hours' ),
			'sync_pro'     => (string) ( $s->gddealer_help_sync_pro ?? 'Every 30 minutes' ),
			'sync_ent'     => (string) ( $s->gddealer_help_sync_enterprise ?? 'Every 15 minutes' ),
		];

		$this->output( 'help', \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->help( $helpData ) );
	}

	/* ---------------- Tab: Subscription ---------------- */

	protected function subscription()
	{
		$dealer = $this->dealer;

		$trialExpiresAt        = (string) ( $dealer->trial_expires_at ?? '' );
		$trialDaysLeft         = null;
		$trialExpiringSoon     = false;
		$trialExpiresFormatted = '';

		if ( $trialExpiresAt && $trialExpiresAt !== '0000-00-00 00:00:00' )
		{
			$expiryTs              = strtotime( $trialExpiresAt );
			$trialDaysLeft         = (int) ceil( ( $expiryTs - time() ) / 86400 );
			$trialExpiringSoon     = $trialDaysLeft <= 30;
			$trialExpiresFormatted = date( 'F j, Y', $expiryTs );
		}

		$manageUrl = (string) \IPS\Http\Url::internal( 'app=nexus&module=clients&controller=purchases' );

		$tierBuyUrls = [];
		foreach ( [ 'basic' => 'gddealer_commerce_basic_id', 'pro' => 'gddealer_commerce_pro_id',
					'enterprise' => 'gddealer_commerce_enterprise_id', 'max' => 'gddealer_commerce_max_id' ] as $tk => $setting )
		{
			$pid = (int) ( \IPS\Settings::i()->$setting ?? 0 );
			$url = $manageUrl;
			if ( $pid )
			{
				try {
					$pkg = \IPS\nexus\Subscription\Package::load( $pid );
					$url = (string) $pkg->url()->setQueryString( 'do', 'purchase' )->csrf();
				}
				catch ( \Throwable ) { $url = $manageUrl; }
			}
			$tierBuyUrls[ $tk ] = $url;
		}

		$dashboardIsFoundingMember = $dealer->isFoundingMember();
		$dashboardTierLabel        = $dashboardIsFoundingMember ? 'Founder' : ucfirst( (string) $dealer->subscription_tier );
		$dashboardTierKey          = $dashboardIsFoundingMember ? 'founding' : (string) $dealer->subscription_tier;

		/* v1.0.175: load all plan data + populate sync_label from active plan.
		 * Falls back gracefully if plans helper unavailable (defensive). */
		$plans     = [];
		$syncLabel = '';
		try
		{
			$plans = \IPS\gddealer\modules\admin\dealers\plans::getAllPlans();
			$activePlanKey = $dashboardIsFoundingMember ? 'founding' : (string) $dealer->subscription_tier;
			if ( isset( $plans[ $activePlanKey ]['sync_label'] ) )
			{
				$syncLabel = (string) $plans[ $activePlanKey ]['sync_label'];
			}
		}
		catch ( \Throwable )
		{
			/* If the plans helper isn't loadable, fall back so the page still renders.
			 * The template has fallback text in case $plans is empty. */
		}

		$sub = [
			'tier'                    => $dashboardTierKey,
			'tier_label'              => $dashboardTierLabel,
			'is_founding'             => $dashboardIsFoundingMember,
			'sync_label'              => $syncLabel ?: '—',
			'mrr'                     => '$' . number_format( $dealer->mrrContribution(), 2 ),
			'active'                  => (bool) $dealer->active,
			'suspended'               => (bool) $dealer->suspended,
			'trial_expires_at'        => $trialExpiresAt ?: '',
			'trial_expires_formatted' => $trialExpiresFormatted,
			'trial_days_left'         => $trialDaysLeft,
			'trial_expiring_soon'     => $trialExpiringSoon,
			'manage_url'              => $manageUrl,
			'tier_buy_urls'           => $tierBuyUrls,
		];

		$billingNote = (string) ( \IPS\Settings::i()->gddealer_subscription_billing_note ?? '' );

		$this->output( 'subscription', \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->subscription(
			$this->dealerSummary(),
			$sub,
			$billingNote,
			$this->tabUrls(),
			$plans
		) );
	}

	/* ---------------- Tab: Reviews ---------------- */

	/** Monthly dispute limit by subscription tier. */
	protected static array $disputeLimits = [
		'basic'      => 2,
		'pro'        => 5,
		'founding'   => 5,
		'enterprise' => PHP_INT_MAX,
		'max'        => PHP_INT_MAX,
	];

	/** Map a 1–5 rating to a color on the shared rating scale. */
	private static function ratingColor( float $r ): string
	{
		return match( true ) {
			$r >= 4.0 => '#16a34a',
			$r >= 3.0 => '#d97706',
			$r > 0    => '#dc2626',
			default   => '#9ca3af',
		};
	}

	/** Map a 1–5 rating to a human-readable label on the shared scale. */
	private static function ratingLabel( float $r ): string
	{
		return match( true ) {
			$r >= 4.0 => 'Excellent',
			$r >= 3.0 => 'Good',
			$r > 0    => 'Poor',
			default   => 'No ratings yet',
		};
	}

	protected function reviews(): void
	{
		$dealerId = (int) $this->dealer->dealer_id;

		try
		{
			\IPS\Db::i()->update( 'gd_dealer_feed_config',
				[ 'last_review_check' => date( 'Y-m-d H:i:s' ) ],
				[ 'dealer_id=?', $dealerId ]
			);
		}
		catch ( \Exception ) {}

		$activeTab = (string) ( \IPS\Request::i()->tab ?? '' );
		if ( !in_array( $activeTab, [ 'attention', 'contested', 'recent', 'all' ], TRUE ) )
		{
			$activeTab = 'attention';
		}

		$ratingFilter = (string) ( \IPS\Request::i()->rating ?? '' );
		if ( !in_array( $ratingFilter, [ '5', '4-5', '3-', '1-2' ], TRUE ) ) { $ratingFilter = 'all'; }

		$dateFilter = (string) ( \IPS\Request::i()->date ?? '' );
		if ( !in_array( $dateFilter, [ '7', '30', '90', 'year' ], TRUE ) ) { $dateFilter = 'any'; }

		$search  = trim( (string) ( \IPS\Request::i()->q ?? '' ) );
		$page    = max( 1, (int) ( \IPS\Request::i()->page ?? 1 ) );
		$perPage = 25;

		$counts = [
			'attention' => $this->_countReviews( $dealerId, 'attention' ),
			'contested' => $this->_countReviews( $dealerId, 'contested' ),
			'recent'    => $this->_countReviews( $dealerId, 'recent' ),
			'all'       => $this->_countReviews( $dealerId, 'all' ),
		];

		$whereStr  = 'dealer_id=? AND status=?';
		$whereVals = [ $dealerId, 'approved' ];

		switch ( $activeTab )
		{
			case 'attention':
				$whereStr .= " AND ((dispute_status='none' AND dealer_response IS NULL AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)) OR dispute_status IN ('pending_customer','pending_admin'))";
				break;
			case 'contested':
				$whereStr .= " AND dispute_status IN ('pending_customer','pending_admin','resolved_dealer','resolved_customer')";
				break;
		}

		if ( $ratingFilter === '5' )       { $whereStr .= ' AND ((rating_pricing + rating_shipping + rating_service) / 3) >= 5'; }
		elseif ( $ratingFilter === '4-5' ) { $whereStr .= ' AND ((rating_pricing + rating_shipping + rating_service) / 3) >= 4'; }
		elseif ( $ratingFilter === '3-' )  { $whereStr .= ' AND ((rating_pricing + rating_shipping + rating_service) / 3) <= 3'; }
		elseif ( $ratingFilter === '1-2' ) { $whereStr .= ' AND ((rating_pricing + rating_shipping + rating_service) / 3) <= 2'; }

		if ( $dateFilter === '7' )        { $whereStr .= ' AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)'; }
		elseif ( $dateFilter === '30' )   { $whereStr .= ' AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)'; }
		elseif ( $dateFilter === '90' )   { $whereStr .= ' AND created_at > DATE_SUB(NOW(), INTERVAL 90 DAY)'; }
		elseif ( $dateFilter === 'year' ) { $whereStr .= ' AND YEAR(created_at) = YEAR(NOW())'; }

		if ( $search !== '' )
		{
			$esc = str_replace( [ '\\', '%', '_' ], [ '\\\\', '\\%', '\\_' ], $search );
			$whereStr .= ' AND review_body LIKE ?';
			$whereVals[] = '%' . $esc . '%';
		}

		$where = array_merge( [ $whereStr ], $whereVals );

		$totalCount = 0;
		try { $totalCount = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_dealer_ratings', $where )->first(); }
		catch ( \Exception ) {}

		$totalPages = max( 1, (int) ceil( $totalCount / $perPage ) );
		$page       = min( $page, $totalPages );
		$offset     = ( $page - 1 ) * $perPage;

		$rows = [];
		try
		{
			foreach ( \IPS\Db::i()->select( '*', 'gd_dealer_ratings', $where, 'created_at DESC', [ $offset, $perPage ] ) as $r )
			{
				$reviewAvg = ( (int) $r['rating_pricing'] + (int) $r['rating_shipping'] + (int) $r['rating_service'] ) / 3;

				$createdTs  = $r['created_at'] ? strtotime( (string) $r['created_at'] ) : 0;
				$responseTs = $r['response_at'] ? strtotime( (string) $r['response_at'] ) : 0;
				$deadlineTs = $r['dispute_deadline'] ? strtotime( (string) $r['dispute_deadline'] ) : 0;

				$respondEditorHtml = '';
				$editEditorHtml    = '';
				$dealerResp        = (string) ( $r['dealer_response'] ?? '' );

				if ( $dealerResp === '' && (string) ( $r['dispute_status'] ?? 'none' ) === 'none' )
				{
					$editor = new \IPS\Helpers\Form\Editor(
						'gddealer_response',
						'',
						FALSE,
						[
							'app'         => 'gddealer',
							'key'         => 'Responses',
							'autoSaveKey' => 'gddealer-response-' . (int) $r['id'],
							'attachIds'   => [ (int) $r['id'], 2 ],
						],
						NULL,
						NULL,
						NULL,
						'editor_respond_' . (int) $r['id']
					);
					$respondEditorHtml = (string) $editor;
				}
				elseif ( $dealerResp !== '' )
				{
					$editEditor = new \IPS\Helpers\Form\Editor(
						'gddealer_response',
						$dealerResp,
						FALSE,
						[
							'app'         => 'gddealer',
							'key'         => 'Responses',
							'autoSaveKey' => 'gddealer-response-' . (int) $r['id'],
							'attachIds'   => [ (int) $r['id'], 2 ],
						],
						NULL,
						NULL,
						NULL,
						'editor_respond_edit_' . (int) $r['id']
					);
					$editEditorHtml = (string) $editEditor;
				}

				$disputeReasonEditorHtml   = '';
				$disputeEvidenceEditorHtml = '';
				if ( (string) ( $r['dispute_status'] ?? 'none' ) === 'none' )
				{
					$rEditor = new \IPS\Helpers\Form\Editor(
						'gddealer_dispute_reason',
						'',
						FALSE,
						[
							'app'         => 'gddealer',
							'key'         => 'Responses',
							'autoSaveKey' => 'gddealer-dispute-reason-' . (int) $r['id'],
							'attachIds'   => [ (int) $r['id'], 3 ],
						],
						NULL,
						NULL,
						NULL,
						'editor_dispute_reason_' . (int) $r['id']
					);
					$disputeReasonEditorHtml = (string) $rEditor;

					$eEditor = new \IPS\Helpers\Form\Editor(
						'gddealer_dispute_evidence',
						'',
						FALSE,
						[
							'app'         => 'gddealer',
							'key'         => 'Responses',
							'autoSaveKey' => 'gddealer-dispute-evidence-' . (int) $r['id'],
							'attachIds'   => [ (int) $r['id'], 4 ],
						],
						NULL,
						NULL,
						NULL,
						'editor_dispute_evidence_' . (int) $r['id']
					);
					$disputeEvidenceEditorHtml = (string) $eEditor;
				}

				$rows[] = [
					'id'               => (int) $r['id'],
					'member_id'        => (int) $r['member_id'],
					'rating_pricing'   => (int) $r['rating_pricing'],
					'rating_shipping'  => (int) $r['rating_shipping'],
					'rating_service'   => (int) $r['rating_service'],
					'review_body'      => (string) ( $r['review_body'] ?? '' ),
					'dealer_response'  => (string) ( $r['dealer_response'] ?? '' ),
					'response_at'      => $responseTs
						? (string) \IPS\DateTime::ts( $responseTs )->localeDate() . ' · ' . (string) \IPS\DateTime::ts( $responseTs )->localeTime()
						: '',
					'response_at_raw'  => (string) ( $r['response_at'] ?? '' ),
					'created_at'       => $createdTs
						? (string) \IPS\DateTime::ts( $createdTs )->localeDate()
						: '',
					'created_at_raw'   => (string) ( $r['created_at'] ?? '' ),
					'dispute_status'   => (string) ( $r['dispute_status'] ?? 'none' ),
					'dispute_outcome'  => (string) ( $r['dispute_outcome'] ?? '' ),
					'dispute_deadline' => $deadlineTs
						? (string) \IPS\DateTime::ts( $deadlineTs )->localeDate()
						: '',
					'avg_color'        => self::ratingColor( (float) $reviewAvg ),
					'avg_overall'      => round( $reviewAvg, 1 ),
					'respond_url'      => (string) \IPS\Http\Url::internal(
						'app=gddealer&module=dealers&controller=dashboard&do=respond&id=' . (int) $r['id']
					)->csrf(),
					'delete_response_url' => (string) \IPS\Http\Url::internal(
						'app=gddealer&module=dealers&controller=dashboard&do=deleteResponse&id=' . (int) $r['id']
					)->csrf(),
					'dispute_url'      => (string) \IPS\Http\Url::internal(
						'app=gddealer&module=dealers&controller=dashboard&do=dispute&id=' . (int) $r['id']
					)->csrf(),
					'respond_editor_html' => $respondEditorHtml,
					'edit_editor_html'    => $editEditorHtml,
					'dispute_reason_editor_html'   => $disputeReasonEditorHtml,
					'dispute_evidence_editor_html' => $disputeEvidenceEditorHtml,
					'upc'                   => (string) ( $r['upc'] ?? '' ),
					'verified_buyer'        => (int) ( $r['verified_buyer'] ?? 0 ) === 1,
					'dispute_reason'        => (string) ( $r['dispute_reason'] ?? '' ),
					'dispute_evidence'      => (string) ( $r['dispute_evidence'] ?? '' ),
					'customer_response'     => (string) ( $r['customer_response'] ?? '' ),
					'customer_evidence'     => (string) ( $r['customer_evidence'] ?? '' ),
					'dispute_at'            => (string) ( $r['dispute_at'] ?? '' ),
					'customer_responded_at' => (string) ( $r['customer_responded_at'] ?? '' ),
					'dispute_resolved_at'   => (string) ( $r['dispute_resolved_at'] ?? '' ),
					'created_at_display'    => $createdTs ? (string) \IPS\DateTime::ts( $createdTs )->relative() : '',
					'response_at_display'   => $responseTs ? (string) \IPS\DateTime::ts( $responseTs )->relative() : '',
				];

				$rowRef =& $rows[ count( $rows ) - 1 ];
				$attFields = [ 'review_body' => 1 ];
				if ( in_array( (string) ( $r['dispute_status'] ?? 'none' ), [ 'pending_admin', 'resolved_dealer', 'dismissed' ], TRUE ) )
				{
					$attFields['customer_response'] = 5;
					$attFields['customer_evidence'] = 6;
				}
				foreach ( $attFields as $field => $hint )
				{
					$atts = AttachHelper::getAttachments( (int) $r['id'], $hint );
					$rowRef[ $field . '_attachments' ] = $atts;
					$hasImg = false;
					foreach ( $atts as $a ) { if ( $a['is_image'] ) { $hasImg = true; break; } }
					$rowRef[ $field . '_has_unembedded_images' ] = $hasImg && !preg_match( '/<img/i', (string) ( $rowRef[ $field ] ?? '' ) );
				}
				unset( $rowRef );
			}
		}
		catch ( \Exception ) {}

		$memberIds = array_unique( array_filter( array_column( $rows, 'member_id' ) ) );
		$memberNames = [];
		if ( $memberIds ) {
			try {
				foreach ( \IPS\Db::i()->select( 'member_id, name', 'core_members', \IPS\Db::i()->in( 'member_id', $memberIds ) ) as $_m ) {
					$memberNames[ (int) $_m['member_id'] ] = (string) $_m['name'];
				}
			} catch ( \Exception ) {}
		}

		$upcList = array_unique( array_filter( array_column( $rows, 'upc' ) ) );
		$productNames = [];
		if ( $upcList ) {
			try {
				foreach ( \IPS\Db::i()->select( 'upc, title', 'gd_catalog', \IPS\Db::i()->in( 'upc', $upcList ) ) as $_p ) {
					$productNames[ (string) $_p['upc'] ] = (string) $_p['title'];
				}
			} catch ( \Exception ) {}
		}

		foreach ( $rows as &$_row ) {
			$_row['author_name'] = $memberNames[ $_row['member_id'] ] ?? 'Customer';
			$name = $_row['author_name'];
			$parts = explode( ' ', trim( $name ) );
			$_row['customer_initials'] = mb_strtoupper( mb_substr( $parts[0], 0, 1 ) . ( isset( $parts[1] ) ? mb_substr( $parts[1], 0, 1 ) : '' ) );

			$_row['product_name'] = $_row['upc'] !== '' ? ( $productNames[ $_row['upc'] ] ?? '' ) : '';

			$_row['dispute_status_label'] = match( $_row['dispute_status'] ) {
				'pending_customer'  => 'Waiting on customer',
				'pending_admin'     => 'Under admin review',
				'resolved_dealer'   => 'Resolved in your favor',
				'resolved_customer' => 'Resolved for customer',
				'dismissed'         => 'Dismissed',
				default             => 'No dispute',
			};

			$_row['deadline_display']  = '';
			$_row['deadline_days_left'] = -1;
			if ( $_row['dispute_status'] === 'pending_customer' && $_row['dispute_deadline'] !== '' ) {
				$dlTs = strtotime( (string) \IPS\Db::i()->select( 'dispute_deadline', 'gd_dealer_ratings', [ 'id=?', $_row['id'] ] )->first() );
				if ( $dlTs ) {
					$daysLeft = (int) ceil( ( $dlTs - time() ) / 86400 );
					$_row['deadline_display']  = (string) \IPS\DateTime::ts( $dlTs )->localeDate();
					$_row['deadline_days_left'] = max( 0, $daysLeft );
				}
			}

			$_row['dispute_at_display']             = '';
			$_row['dispute_at_date']                = '';
			$_row['customer_responded_at_display']   = '';
			$_row['customer_responded_at_date']      = '';
			$_row['dispute_resolved_at_display']     = '';
			if ( $_row['dispute_at'] !== '' ) {
				$dts = strtotime( $_row['dispute_at'] );
				if ( $dts ) {
					$_row['dispute_at_display'] = (string) \IPS\DateTime::ts( $dts )->relative();
					$_row['dispute_at_date']    = (string) \IPS\DateTime::ts( $dts )->localeDate();
				}
			}
			if ( $_row['customer_responded_at'] !== '' ) {
				$crts = strtotime( $_row['customer_responded_at'] );
				if ( $crts ) {
					$_row['customer_responded_at_display'] = (string) \IPS\DateTime::ts( $crts )->relative();
					$_row['customer_responded_at_date']    = (string) \IPS\DateTime::ts( $crts )->localeDate();
				}
			}
			if ( $_row['dispute_resolved_at'] !== '' ) {
				$drts = strtotime( $_row['dispute_resolved_at'] );
				if ( $drts ) { $_row['dispute_resolved_at_display'] = (string) \IPS\DateTime::ts( $drts )->relative(); }
			}

			$customerName = $_row['author_name'];
			$activity = [];

			if ( !empty( $_row['created_at_raw'] ) ) {
				$ts = strtotime( (string) $_row['created_at_raw'] );
				$activity[] = [
					'ts'    => $ts ?: 0,
					'dot'   => 'neutral',
					'label' => \IPS\Member::loggedIn()->language()->addToStack( 'gddealer_front_activity_review_posted', false, [ 'sprintf' => [ $customerName ] ] ),
					'time'  => $ts ? (string) \IPS\DateTime::ts( $ts )->localeDate() . ' · ' . (string) \IPS\DateTime::ts( $ts )->localeTime() : '',
				];
			}

			if ( !empty( $_row['response_at_raw'] ) ) {
				$ts = strtotime( (string) $_row['response_at_raw'] );
				$activity[] = [
					'ts'    => $ts ?: 0,
					'dot'   => 'info',
					'label' => \IPS\Member::loggedIn()->language()->addToStack( 'gddealer_front_activity_dealer_responded' ),
					'time'  => $ts ? (string) \IPS\DateTime::ts( $ts )->localeDate() . ' · ' . (string) \IPS\DateTime::ts( $ts )->localeTime() : '',
				];
			}

			try {
				foreach ( \IPS\Db::i()->select( '*', 'gd_dealer_dispute_events',
					[ 'review_id=?', (int) $_row['id'] ],
					'created_at ASC'
				) as $ev ) {
					$eventType = (string) $ev['event_type'];
					$dot = match ( $eventType ) {
						'dispute_opened'       => 'warn',
						'customer_responded'   => 'info',
						'admin_edit_requested' => 'neutral',
						'dispute_resolved', 'admin_upheld', 'admin_dismissed' => 'success',
						default                => 'neutral',
					};
					$langKey = 'gddealer_front_activity_event_' . $eventType;
					$label = \IPS\Member::loggedIn()->language()->addToStack( $langKey );

					$actorName = '';
					if ( !empty( $ev['actor_id'] ) ) {
						try {
							$a = \IPS\Member::load( (int) $ev['actor_id'] );
							if ( $a->member_id ) { $actorName = (string) $a->name; }
						} catch ( \Exception ) {}
					}

					$ts = strtotime( (string) $ev['created_at'] );
					$activity[] = [
						'ts'         => $ts ?: 0,
						'dot'        => $dot,
						'label'      => $label,
						'note'       => (string) ( $ev['note'] ?? '' ),
						'actor_name' => $actorName,
						'time'       => $ts ? (string) \IPS\DateTime::ts( $ts )->localeDate() . ' · ' . (string) \IPS\DateTime::ts( $ts )->localeTime() : '',
					];
				}
			} catch ( \Exception ) {}

			usort( $activity, fn( $a, $b ) => $a['ts'] <=> $b['ts'] );
			foreach ( $activity as &$_ev ) { unset( $_ev['ts'] ); }
			unset( $_ev );
			$_row['activity'] = $activity;
		}
		unset( $_row );

		$avgPricing  = 0.0;
		$avgShipping = 0.0;
		$avgService  = 0.0;
		$total       = 0;

		try
		{
			$agg = \IPS\Db::i()->select(
				'COUNT(*) as c, AVG(rating_pricing) as p, AVG(rating_shipping) as s, AVG(rating_service) as sv',
				'gd_dealer_ratings',
				[ 'dealer_id=? AND status=? AND dispute_status<>?', $dealerId, 'approved', 'resolved_dealer' ]
			)->first();
			$total       = (int) $agg['c'];
			$avgPricing  = round( (float) $agg['p'], 1 );
			$avgShipping = round( (float) $agg['s'], 1 );
			$avgService  = round( (float) $agg['sv'], 1 );
		}
		catch ( \Exception ) {}

		$starDistribution = [ 5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0 ];
		try {
			foreach ( \IPS\Db::i()->select(
				'ROUND((rating_pricing + rating_shipping + rating_service) / 3) AS star, COUNT(*) AS cnt',
				'gd_dealer_ratings',
				[ 'dealer_id=? AND status=?', $dealerId, 'approved' ],
				null, null, 'star'
			) as $_s ) {
				$_star = (int) $_s['star'];
				if ( $_star >= 1 && $_star <= 5 ) {
					$starDistribution[$_star] = (int) $_s['cnt'];
				}
			}
		} catch ( \Exception ) {}

		$tier     = (string) $this->dealer->subscription_tier;
		$limit    = self::$disputeLimits[ $tier ] ?? 2;
		$monthKey = date( 'Y-m' );
		$bonus    = 0;
		try
		{
			$row = \IPS\Db::i()->select( 'count, bonus', 'gd_dealer_dispute_counts',
				[ 'dealer_id=? AND month_key=?', $dealerId, $monthKey ]
			)->first();
			$used  = (int) $row['count'];
			$bonus = (int) ( $row['bonus'] ?? 0 );
		}
		catch ( \Exception )
		{
			$used = 0;
		}
		$effectiveLimit = $limit === PHP_INT_MAX ? PHP_INT_MAX : $limit + $bonus;
		$remaining = $effectiveLimit === PHP_INT_MAX ? -1 : max( 0, $effectiveLimit - $used );

		$avgOverall = $total > 0 ? round( ( $avgPricing + $avgShipping + $avgService ) / 3, 1 ) : 0.0;

		$base = \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=reviews' );

		$buildQS = function ( array $extra ) use ( $activeTab, $ratingFilter, $dateFilter, $search ): array
		{
			$qs = [ 'tab' => $activeTab ];
			if ( $ratingFilter !== 'all' ) { $qs['rating'] = $ratingFilter; }
			if ( $dateFilter !== 'any' )   { $qs['date']   = $dateFilter; }
			if ( $search !== '' )          { $qs['q']      = $search; }
			return array_merge( $qs, $extra );
		};

		$subNav = [];
		foreach ( [ 'attention' => 'Needs attention', 'contested' => 'Contested', 'recent' => 'Recent', 'all' => 'All' ] as $tab => $label )
		{
			$subNav[] = [
				'key'    => $tab,
				'label'  => $label,
				'count'  => $counts[ $tab ],
				'url'    => (string) $base->setQueryString( $buildQS( [ 'tab' => $tab ] ) ),
				'active' => $tab === $activeTab,
			];
		}

		$pageLinks = [];
		if ( $totalPages > 1 )
		{
			if ( $page > 1 )
			{
				$pageLinks[] = [ 'label' => "\xC2\xAB Prev", 'url' => (string) $base->setQueryString( $buildQS( [ 'page' => $page - 1 ] ) ), 'active' => false, 'disabled' => false ];
			}
			$start = max( 1, $page - 2 );
			$end   = min( $totalPages, $page + 2 );
			if ( $start > 1 )
			{
				$pageLinks[] = [ 'label' => '1', 'url' => (string) $base->setQueryString( $buildQS( [ 'page' => 1 ] ) ), 'active' => false, 'disabled' => false ];
				if ( $start > 2 ) { $pageLinks[] = [ 'label' => '...', 'url' => '', 'active' => false, 'disabled' => true ]; }
			}
			for ( $p = $start; $p <= $end; $p++ )
			{
				$pageLinks[] = [ 'label' => (string) $p, 'url' => (string) $base->setQueryString( $buildQS( [ 'page' => $p ] ) ), 'active' => $p === $page, 'disabled' => false ];
			}
			if ( $end < $totalPages )
			{
				if ( $end < $totalPages - 1 ) { $pageLinks[] = [ 'label' => '...', 'url' => '', 'active' => false, 'disabled' => true ]; }
				$pageLinks[] = [ 'label' => (string) $totalPages, 'url' => (string) $base->setQueryString( $buildQS( [ 'page' => $totalPages ] ) ), 'active' => false, 'disabled' => false ];
			}
			if ( $page < $totalPages )
			{
				$pageLinks[] = [ 'label' => "Next \xC2\xBB", 'url' => (string) $base->setQueryString( $buildQS( [ 'page' => $page + 1 ] ) ), 'active' => false, 'disabled' => false ];
			}
		}

		$reviewTabUrls = [];
		foreach ( $subNav as $_nav ) {
			$reviewTabUrls[ $_nav['key'] ] = $_nav['url'];
		}

		$pagesArray = [];
		$prevUrl = '';
		$nextUrl = '';
		foreach ( $pageLinks as $_pl ) {
			if ( $_pl['label'] === "\xC2\xAB Prev" )       { $prevUrl = $_pl['url']; continue; }
			if ( $_pl['label'] === "Next \xC2\xBB" )       { $nextUrl = $_pl['url']; continue; }
			if ( $_pl['disabled'] || $_pl['label'] === '...' ) { continue; }
			$pagesArray[] = [ 'num' => (int) $_pl['label'], 'url' => $_pl['url'], 'is_current' => $_pl['active'] ];
		}

		$data = [
			'dealer'          => $this->dealerSummary(),
			'tab_urls'        => $this->tabUrls(),
			'summary'         => [
				'count'        => $total,
				'average'      => $avgOverall,
				'distribution' => $starDistribution,
				'avg_pricing'  => $avgPricing,
				'avg_shipping' => $avgShipping,
				'avg_service'  => $avgService,
			],
			'counts'          => $counts,
			'active_tab'      => $activeTab,
			'rating_filter'   => $ratingFilter,
			'date_filter'     => $dateFilter,
			'search'          => $search,
			'reviews'         => $rows,
			'page'            => $page,
			'total_pages'     => $totalPages,
			'total_count'     => $totalCount,
			'review_tab_urls' => $reviewTabUrls,
			'pages_array'     => $pagesArray,
			'prev_url'        => $prevUrl,
			'next_url'        => $nextUrl,
			'five'            => [ 1, 2, 3, 4, 5 ],
			'five_rev'        => [ 5, 4, 3, 2, 1 ],
		];

		$this->output( 'reviews',
			\IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->reviews( $data )
		);
	}

	protected function _countReviews( int $dealerId, string $tab ): int
	{
		$whereStr  = 'dealer_id=? AND status=?';
		$whereVals = [ $dealerId, 'approved' ];

		switch ( $tab )
		{
			case 'attention':
				$whereStr .= " AND ((dispute_status='none' AND dealer_response IS NULL AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)) OR dispute_status IN ('pending_customer','pending_admin'))";
				break;
			case 'contested':
				$whereStr .= " AND dispute_status IN ('pending_customer','pending_admin','resolved_dealer','resolved_customer')";
				break;
		}

		try { return (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_dealer_ratings', array_merge( [ $whereStr ], $whereVals ) )->first(); }
		catch ( \Exception ) { return 0; }
	}

	/** Post a dealer response to a review. */
	protected function respond(): void
	{
		\IPS\Session::i()->csrfCheck();
		$id  = (int) ( \IPS\Request::i()->id ?? 0 );

		/* Construct editor before save logic so upload POSTs are intercepted.
		   autoSaveKey must match the render-side editor in reviews(). */
		if ( $id > 0 )
		{
			new \IPS\Helpers\Form\Editor(
				'gddealer_response',
				'',
				FALSE,
				[
					'app'         => 'gddealer',
					'key'         => 'Responses',
					'autoSaveKey' => 'gddealer-response-' . $id,
					'attachIds'   => [ $id, 2 ],
				],
				NULL,
				NULL,
				NULL,
				'editor_respond_' . $id
			);
		}

		$raw = (string) \IPS\Request::i()->gddealer_response;

		/* Editor output is HTML. parseStatic runs IPS's HTMLPurifier against
		   it (strips unsafe tags/attrs, converts pasted embeds, resolves
		   attachment placeholders). The $area string must match the editor
		   location extension: gddealer_Responses. attachIds=[reviewId,2]
		   identifies the dealer_response field for attachment bookkeeping. */
		$response = '';
		if ( $raw !== '' && $id > 0 )
		{
			try
			{
				$response = \IPS\Text\Parser::parseStatic(
					$raw,
					[ (int) $id, 2 ],
					\IPS\Member::loggedIn(),
					'gddealer_Responses'
				);
			}
			catch ( \Exception )
			{
				$response = $raw;
			}
		}
		$response = trim( $response );

		if ( $id > 0 && $response !== '' )
		{
			try
			{
				\IPS\Db::i()->update( 'gd_dealer_ratings',
					[ 'dealer_response' => $response, 'response_at' => date( 'Y-m-d H:i:s' ) ],
					[ 'id=? AND dealer_id=? AND dispute_status=?', $id, (int) $this->dealer->dealer_id, 'none' ]
				);
			}
			catch ( \Exception ) {}

			/* Claim any attachments uploaded via the editor so they're
			   linked to this review+field and survive garbage collection.
			   autoSaveKey matches the editor instantiation in reviews(). */
			try
			{
				\IPS\File::claimAttachments(
					'gddealer-response-' . (int) $id,
					(int) $id,
					2
				);
			}
			catch ( \Exception ) {}

			/* Look up the reviewer once; each side-effect below gets its
			   own fully independent try/catch so a failure in one channel
			   cannot suppress the others. */
			$reviewerMember = NULL;
			$dealerName     = (string) $this->dealer->dealer_name;
			$slug           = (string) ( $this->dealer->dealer_slug ?? '' );
			$profileUrl     = (string) \IPS\Http\Url::internal(
				'app=gddealer&module=dealers&controller=profile&dealer_slug=' . urlencode( $slug )
			);

			try
			{
				$review         = \IPS\Db::i()->select( '*', 'gd_dealer_ratings', [ 'id=?', $id ] )->first();
				$reviewerMember = \IPS\Member::load( (int) $review['member_id'] );
			}
			catch ( \Exception ) {}

			/* Email to the reviewer — own try/catch. */
			try
			{
				if ( $reviewerMember && $reviewerMember->member_id )
				{
					\IPS\Email::buildFromTemplate( 'gddealer', 'dealerResponded', [
						'name'        => $reviewerMember->name,
						'dealer_name' => $dealerName,
						'response'    => $response,
						'profile_url' => $profileUrl,
					], \IPS\Email::TYPE_TRANSACTIONAL )->send( $reviewerMember );
				}
			}
			catch ( \Exception ) {}

			/* IPS inline notification to the reviewer — own try/catch. */
			try
			{
				if ( $reviewerMember && $reviewerMember->member_id )
				{
					$notification = new \IPS\Notification(
						\IPS\Application::load( 'gddealer' ),
						'dealer_responded',
						$reviewerMember,
						[ $reviewerMember ],
						[
							'dealer_name' => $dealerName,
							'dealer_slug' => $slug,
						]
					);
					$notification->recipients->attach( $reviewerMember );
					$notification->send();
				}
			}
			catch ( \Exception ) {}

			/* PM to the reviewer — own try/catch. */
			try
			{
				if ( $reviewerMember && $reviewerMember->member_id )
				{
					$dealerMember = \IPS\Member::load( (int) $this->dealer->dealer_id );
					$sender       = $dealerMember->member_id ? $dealerMember : \IPS\Member::loggedIn();
					if ( \IPS\core\Messenger\Conversation::memberCanReceiveNewMessage( $reviewerMember, $sender ) )
					{
						$conversation = \IPS\core\Messenger\Conversation::createItem( $sender, \IPS\Request::i()->ipAddress(), \IPS\DateTime::create() );
						$conversation->title    = $dealerName . ' responded to your review';
						$conversation->to_count = 1;
						$conversation->save();

						$commentClass = $conversation::$commentClass;
						$post = $commentClass::create(
							$conversation,
							$dealerName . ' has posted a public response to your review on GunRack.deals. View it here: ' . $profileUrl,
							TRUE, NULL, NULL, $sender, \IPS\DateTime::create()
						);

						$conversation->first_msg_id = $post->id;
						$conversation->save();
						$conversation->authorize( [ $sender->member_id, $reviewerMember->member_id ] );
						$post->sendNotifications();
					}
				}
			}
			catch ( \Exception ) {}
		}

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=reviews' ),
			'gddealer_front_response_saved'
		);
	}

	/** Remove a dealer response. Dealer-scoped; CSRF-protected. */
	protected function deleteResponse(): void
	{
		\IPS\Session::i()->csrfCheck();
		$id = (int) ( \IPS\Request::i()->id ?? 0 );

		if ( $id > 0 )
		{
			try
			{
				\IPS\Db::i()->update( 'gd_dealer_ratings',
					[ 'dealer_response' => NULL, 'response_at' => NULL ],
					[ 'id=? AND dealer_id=?', $id, (int) $this->dealer->dealer_id ]
				);
			}
			catch ( \Exception ) {}
		}

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=reviews' )
		);
	}

	/**
	 * Contest a review. Opens the pending_customer stage of the dispute
	 * flow: customer is given 30 days to respond, a dispute count is
	 * incremented for the month, customer gets an email notification.
	 * Monthly disputes are capped by subscription tier.
	 */
	protected function dispute(): void
	{
		\IPS\Session::i()->csrfCheck();

		$id = (int) ( \IPS\Request::i()->id ?? 0 );

		/* Construct editors before save logic so upload POSTs are intercepted.
		   autoSaveKeys must match the render-side editors in reviews(). */
		if ( $id > 0 )
		{
			new \IPS\Helpers\Form\Editor(
				'gddealer_dispute_reason',
				'',
				FALSE,
				[
					'app'         => 'gddealer',
					'key'         => 'Responses',
					'autoSaveKey' => 'gddealer-dispute-reason-' . $id,
					'attachIds'   => [ $id, 3 ],
				],
				NULL,
				NULL,
				NULL,
				'editor_dispute_reason_' . $id
			);
			new \IPS\Helpers\Form\Editor(
				'gddealer_dispute_evidence',
				'',
				FALSE,
				[
					'app'         => 'gddealer',
					'key'         => 'Responses',
					'autoSaveKey' => 'gddealer-dispute-evidence-' . $id,
					'attachIds'   => [ $id, 4 ],
				],
				NULL,
				NULL,
				NULL,
				'editor_dispute_evidence_' . $id
			);
		}

		$reasonRaw   = (string) \IPS\Request::i()->gddealer_dispute_reason;
		$evidenceRaw = (string) \IPS\Request::i()->gddealer_dispute_evidence;

		/* Both fields arrive as editor HTML — parse through IPS sanitizer
		   (area='gddealer_Responses', attachIds tag the review + the
		   specific field: 3=dispute_reason, 4=dispute_evidence). */
		$reason = '';
		if ( trim( $reasonRaw ) !== '' && $id > 0 )
		{
			try
			{
				$reason = \IPS\Text\Parser::parseStatic(
					$reasonRaw,
					[ $id, 3 ],
					\IPS\Member::loggedIn(),
					'gddealer_Responses'
				);
			}
			catch ( \Exception )
			{
				$reason = $reasonRaw;
			}
		}
		$reason = trim( $reason );

		$evidence = '';
		if ( trim( $evidenceRaw ) !== '' && $id > 0 )
		{
			try
			{
				$evidence = \IPS\Text\Parser::parseStatic(
					$evidenceRaw,
					[ $id, 4 ],
					\IPS\Member::loggedIn(),
					'gddealer_Responses'
				);
			}
			catch ( \Exception )
			{
				$evidence = $evidenceRaw;
			}
		}
		$evidence = trim( $evidence );
		$tier     = (string) $this->dealer->subscription_tier;
		$dealerId = (int) $this->dealer->dealer_id;

		$redirectUrl = \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=reviews' );

		if ( (int) ( $this->dealer->disputes_suspended ?? 0 ) )
		{
			\IPS\Output::i()->redirect( $redirectUrl, 'gddealer_front_disputes_suspended' );
			return;
		}

		if ( $id <= 0 || $reason === '' )
		{
			\IPS\Output::i()->redirect( $redirectUrl );
			return;
		}

		$limit    = self::$disputeLimits[ $tier ] ?? 2;
		$monthKey = date( 'Y-m' );
		$bonus    = 0;

		try
		{
			$row = \IPS\Db::i()->select( 'count, bonus', 'gd_dealer_dispute_counts',
				[ 'dealer_id=? AND month_key=?', $dealerId, $monthKey ]
			)->first();
			$used  = (int) $row['count'];
			$bonus = (int) ( $row['bonus'] ?? 0 );
		}
		catch ( \Exception )
		{
			$used = 0;
		}

		$effectiveLimit = $limit === PHP_INT_MAX ? PHP_INT_MAX : $limit + $bonus;
		if ( $used >= $effectiveLimit )
		{
			\IPS\Output::i()->redirect( $redirectUrl, 'gddealer_front_dispute_limit_reached' );
			return;
		}

		/* Verify review belongs to this dealer and has no active dispute.
		   A review previously dismissed by admin cannot be contested a
		   second time — dispute_status must equal 'none'. */
		try
		{
			$review = \IPS\Db::i()->select( '*', 'gd_dealer_ratings',
				[ 'id=? AND dealer_id=? AND dispute_status=?', $id, $dealerId, 'none' ]
			)->first();
		}
		catch ( \Exception )
		{
			\IPS\Output::i()->redirect( $redirectUrl );
			return;
		}

		$deadline = date( 'Y-m-d H:i:s', strtotime( '+30 days' ) );

		try
		{
			\IPS\Db::i()->update( 'gd_dealer_ratings', [
				'dispute_status'   => 'pending_customer',
				'dispute_reason'   => $reason,
				'dispute_evidence' => $evidence !== '' ? $evidence : null,
				'dispute_at'       => date( 'Y-m-d H:i:s' ),
				'dispute_deadline' => $deadline,
			], [ 'id=?', $id ] );
		}
		catch ( \Exception ) {}

		EventLogger::log( $id, 'dispute_opened', 'dealer', (int) \IPS\Member::loggedIn()->member_id, $reason !== '' ? strip_tags( $reason ) : NULL );

		/* Claim any editor attachments for each dispute field. Same
		   autoSaveKey the editor was rendered with. */
		try
		{
			\IPS\File::claimAttachments(
				'gddealer-dispute-reason-' . (int) $id,
				(int) $id,
				3
			);
		}
		catch ( \Exception ) {}

		try
		{
			\IPS\File::claimAttachments(
				'gddealer-dispute-evidence-' . (int) $id,
				(int) $id,
				4
			);
		}
		catch ( \Exception ) {}

		/* Increment monthly dispute count. Insert-or-update without any
		   raw SQL string interpolation (CLAUDE.md Rule #2). */
		try
		{
			$exists = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_dealer_dispute_counts',
				[ 'dealer_id=? AND month_key=?', $dealerId, $monthKey ]
			)->first();
		}
		catch ( \Exception )
		{
			$exists = 0;
		}

		try
		{
			if ( $exists === 0 )
			{
				\IPS\Db::i()->insert( 'gd_dealer_dispute_counts', [
					'dealer_id' => $dealerId,
					'month_key' => $monthKey,
					'count'     => 1,
				] );
			}
			else
			{
				\IPS\Db::i()->update( 'gd_dealer_dispute_counts',
					'count = count + 1',
					[ 'dealer_id=? AND month_key=?', $dealerId, $monthKey ]
				);
			}
		}
		catch ( \Exception ) {}

		/* Side-effects to the customer — each channel in its own independent
		   try/catch so a failure in one does not suppress the others. */
		if ( (int) ( $review['member_id'] ?? 0 ) > 0 )
		{
			$customer     = NULL;
			$slug         = (string) ( $this->dealer->dealer_slug ?? '' );
			$respondUrl   = (string) \IPS\Http\Url::internal(
				'app=gddealer&module=dealers&controller=profile&dealer_slug=' . urlencode( $slug ) . '&dispute=' . $id
			);
			$dealerName   = (string) $this->dealer->dealer_name;
			$contactEmail = (string) ( \IPS\Settings::i()->gddealer_help_contact ?: 'dealers@gunrack.deals' );

			try { $customer = \IPS\Member::load( (int) $review['member_id'] ); } catch ( \Exception ) {}

			/* Email to the customer. */
			try
			{
				if ( $customer && $customer->member_id )
				{
					\IPS\Email::buildFromTemplate( 'gddealer', 'disputeNotify', [
						'name'          => $customer->name,
						'dealer_name'   => $dealerName,
						'reason'        => $reason,
						'deadline'      => date( 'F j, Y', strtotime( $deadline ) ),
						'respond_url'   => $respondUrl,
						'contact_email' => $contactEmail,
					], \IPS\Email::TYPE_TRANSACTIONAL )->send( $customer );
				}
			}
			catch ( \Exception ) {}

			/* IPS inline notification to the customer. */
			try
			{
				if ( $customer && $customer->member_id )
				{
					$notification = new \IPS\Notification(
						\IPS\Application::load( 'gddealer' ),
						'review_disputed',
						$customer,
						[ $customer ],
						[
							'dealer_name' => $dealerName,
							'dealer_slug' => $slug,
							'review_id'   => $id,
						]
					);
					$notification->recipients->attach( $customer );
					$notification->send();
				}
			}
			catch ( \Exception ) {}

			/* PM to the customer. */
			try
			{
				if ( $customer && $customer->member_id )
				{
					$dealerMember = \IPS\Member::load( (int) $this->dealer->dealer_id );
					$sender       = $dealerMember->member_id ? $dealerMember : \IPS\Member::loggedIn();
					if ( \IPS\core\Messenger\Conversation::memberCanReceiveNewMessage( $customer, $sender ) )
					{
						$conversation = \IPS\core\Messenger\Conversation::createItem( $sender, \IPS\Request::i()->ipAddress(), \IPS\DateTime::create() );
						$conversation->title    = $dealerName . ' has disputed your review';
						$conversation->to_count = 1;
						$conversation->save();

						$commentClass = $conversation::$commentClass;
						$post = $commentClass::create(
							$conversation,
							"Hi,\n\n" . $dealerName . " has contested the review you left on GunRack.deals and provided the following evidence:\n\n" . $reason . "\n\nYou have 30 days to respond. Visit the dealer profile to submit your response:\n\n" . $respondUrl,
							TRUE, NULL, NULL, $sender, \IPS\DateTime::create()
						);

						$conversation->first_msg_id = $post->id;
						$conversation->save();
						$conversation->authorize( [ $sender->member_id, $customer->member_id ] );
						$post->sendNotifications();
					}
				}
			}
			catch ( \Exception ) {}
		}

		\IPS\Output::i()->redirect( $redirectUrl, 'gddealer_front_dispute_submitted' );
	}
	/**
	 * Current FFL verification state for a dealer, one of:
	 *   'none'      → nothing submitted yet
	 *   'pending'   → submitted, awaiting admin review
	 *   'verified'  → admin approved
	 *   'rejected'  → admin rejected (reason in ffl_rejection_reason)
	 *   'blocked'   → 3 or more rejections, dealer cannot re-submit
	 */
	protected static function fflStatusKey( $dealer ): string
	{
		if ( (int) ( $dealer->ffl_rejection_count ?? 0 ) >= 3 )
		{
			return 'blocked';
		}
		if ( !empty( $dealer->ffl_verified_at ) )
		{
			return 'verified';
		}
		if ( !empty( $dealer->ffl_rejection_reason ) )
		{
			return 'rejected';
		}
		if ( !empty( $dealer->ffl_submitted_at ) && !empty( $dealer->ffl_number ) && !empty( $dealer->ffl_license_url ) )
		{
			return 'pending';
		}
		return 'none';
	}

	protected static function fflStatusLabel( $dealer ): string
	{
		return match( self::fflStatusKey( $dealer ) )
		{
			'verified' => 'FFL verified',
			'pending'  => 'FFL verification pending (usually within 24 hours)',
			'rejected' => 'FFL verification rejected — see reason below and re-submit',
			'blocked'  => 'FFL verification blocked after 3 rejections — contact support',
			default    => 'Upload FFL license for verification',
		};
	}

	protected function reportUnmatched(): void
	{
		\IPS\Session::i()->csrfCheck();
		$dealer = $this->dealer;
		$upcId  = (int) \IPS\Request::i()->upc_id;

		if ( !$dealer or !$upcId )
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=unmatched' ),
				'Invalid request.', 400
			);
			return;
		}

		try
		{
			\IPS\Db::i()->update(
				'gd_unmatched_upcs',
				[ 'dealer_reported_at' => date( 'Y-m-d H:i:s' ) ],
				[ 'id=? AND dealer_id=?', $upcId, (int) $dealer->dealer_id ]
			);
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'reportUnmatched error: ' . $e->getMessage(), 'gddealer_v196' ); } catch ( \Throwable ) {}
		}

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=unmatched' ),
			'UPC reported to GunRack. Thank you!'
		);
	}

	protected function viewSnapshot(): void
	{
		$dealer = $this->dealer;
		$upcId  = (int) \IPS\Request::i()->upc_id;

		$row = NULL;
		try
		{
			$row = \IPS\Db::i()->select(
				'*', 'gd_unmatched_upcs',
				[ 'id=? AND dealer_id=?', $upcId, (int) $dealer->dealer_id ]
			)->first();
		}
		catch ( \Throwable ) {}

		if ( !$row )
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=unmatched' ),
				'Snapshot not found.', 404
			);
			return;
		}

		$snapshot = NULL;
		if ( !empty( $row['snapshot_json'] ) )
		{
			$snapshot = @json_decode( $row['snapshot_json'], true );
		}

		$html  = '<div class="gdPanel" style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:24px">';
		$html .= '<h2 style="margin:0 0 12px;font-size:18px;font-weight:600">Feed snapshot for UPC ' . htmlspecialchars( $row['upc'], ENT_QUOTES, 'UTF-8' ) . '</h2>';
		$html .= '<p style="font-size:13px;color:#64748b;margin:0 0 16px">First seen: ' . htmlspecialchars( $row['first_seen'] ?? '', ENT_QUOTES, 'UTF-8' ) . ' · Last seen: ' . htmlspecialchars( $row['last_seen'] ?? '', ENT_QUOTES, 'UTF-8' ) . ' · Occurrences: ' . (int) ( $row['occurrence_count'] ?? 0 ) . '</p>';
		if ( $snapshot )
		{
			$html .= '<pre style="background:#0f172a;color:#e2e8f0;padding:16px;border-radius:8px;overflow:auto;font-size:12px;font-family:ui-monospace,monospace;white-space:pre-wrap;word-break:break-word">' . htmlspecialchars( json_encode( $snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), ENT_QUOTES, 'UTF-8' ) . '</pre>';
		}
		else
		{
			$html .= '<p style="font-size:13px;color:#64748b">No snapshot available for this UPC.</p>';
		}
		$html .= '<div style="margin-top:16px"><a href="' . htmlspecialchars( (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=unmatched' ), ENT_QUOTES, 'UTF-8' ) . '" class="gdBtn gdBtn--secondary">&larr; Back to unmatched UPCs</a></div>';
		$html .= '</div>';

		$this->output( 'viewSnapshot', $html );
	}

	protected function resetDealer(): bool
	{
		$dealer = $this->dealer;
		if ( !$dealer )
		{
			return FALSE;
		}

		$dealerId = (int) $dealer->dealer_id;

		$snapshot = [
			'dealer_id'         => $dealerId,
			'created_at'        => date( 'c' ),
			'gd_dealer_listings'    => [],
			'gd_unmatched_upcs'     => [],
			'gd_dealer_category_map'=> [],
			'gd_dealer_import_log'  => [],
		];

		foreach ( [ 'gd_dealer_listings', 'gd_unmatched_upcs', 'gd_dealer_category_map', 'gd_dealer_import_log' ] as $tbl )
		{
			try
			{
				$rows = \IPS\Db::i()->select( '*', $tbl, [ 'dealer_id=?', $dealerId ] );
				foreach ( $rows as $r )
				{
					$snapshot[ $tbl ][] = $r;
				}
			}
			catch ( \Throwable ) {}
		}

		try
		{
			\IPS\Db::i()->insert( 'gd_dealer_reset_backups', [
				'dealer_id'     => $dealerId,
				'scope'         => 'B',
				'snapshot_json' => json_encode( $snapshot ),
				'created_at'    => date( 'Y-m-d H:i:s' ),
			] );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'resetDealer backup error: ' . $e->getMessage(), 'gddealer_v196' ); } catch ( \Throwable ) {}
			return FALSE;
		}

		foreach ( [ 'gd_dealer_listings', 'gd_unmatched_upcs', 'gd_dealer_category_map', 'gd_dealer_import_log' ] as $tbl )
		{
			try { \IPS\Db::i()->delete( $tbl, [ 'dealer_id=?', $dealerId ] ); }
			catch ( \Throwable ) {}
		}

		try
		{
			\IPS\Db::i()->update( 'gd_dealer_feed_config',
				[
					'feed_url'          => '',
					'auth'              => '',
					'wizard_state_json' => '',
				],
				[ 'dealer_id=?', $dealerId ]
			);
		}
		catch ( \Throwable ) {}

		return TRUE;
	}

	protected function confirmReset(): void
	{
		\IPS\Session::i()->csrfCheck();

		$dealer = $this->dealer;
		$typed  = trim( (string) \IPS\Request::i()->confirm_name );
		$expected = $dealer ? (string) $dealer->dealer_name : '';

		if ( !$dealer or $typed === '' or $typed !== $expected )
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=customize' ),
				'Reset cancelled: confirmation name did not match.', 400
			);
			return;
		}

		$ok = $this->resetDealer();

		if ( $ok )
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::external( (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=overview' ) ),
				'Your dealer data has been reset. Run through the setup wizard to reconfigure your feed. (24-hour undo available - contact support.)'
			);
		}
		else
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=customize' ),
				'Reset failed. Contact GunRack support.', 500
			);
		}
	}

	protected function reportedListings(): void
	{
		$dealerId = (int) $this->dealer->dealer_id;

		$statusFilter = (string) ( \IPS\Request::i()->report_status ?? '' );
		$validStatuses = [ 'new', 'reviewed', 'resolved', 'dismissed', '' ];
		if ( !in_array( $statusFilter, $validStatuses, true ) )
		{
			$statusFilter = '';
		}

		$page    = max( 1, (int) ( \IPS\Request::i()->page ?? 1 ) );
		$perPage = 25;
		$offset  = ( $page - 1 ) * $perPage;

		$where = [ [ 'r.dealer_id=?', $dealerId ] ];
		if ( $statusFilter !== '' )
		{
			$where[] = [ 'r.status=?', $statusFilter ];
		}

		$total   = 0;
		$reports = [];

		try
		{
			$total = (int) \IPS\Db::i()->select(
				'COUNT(*)',
				[ 'gd_listing_reports', 'r' ],
				$where
			)->first();
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( $e, 'gddealer_reported_listings' ); } catch ( \Throwable ) {}
		}

		try
		{
			foreach ( \IPS\Db::i()->select(
				'r.*, c.title AS product_title, c.image_url',
				[ 'gd_listing_reports', 'r' ],
				$where,
				'r.created_at DESC',
				[ $offset, $perPage ]
			)->join(
				[ 'gd_catalog', 'c' ],
				'c.upc = r.upc',
				'LEFT'
			) as $row )
			{
				$reasonLabels = \IPS\gddealer\Reports\ListingReport::REASON_LABELS;

				$reporterName = '';
				try
				{
					$m = \IPS\Member::load( (int) $row['reporter_member_id'] );
					$reporterName = $m->member_id ? $m->name : '';
				}
				catch ( \Throwable ) {}

				$reports[] = [
					'id'             => (int) $row['id'],
					'upc'            => (string) $row['upc'],
					'product_title'  => (string) ( $row['product_title'] ?? '' ),
					'image_url'      => (string) ( $row['image_url'] ?? '' ),
					'reason'         => (string) $row['reason'],
					'reason_label'   => $reasonLabels[ $row['reason'] ] ?? (string) $row['reason'],
					'note'           => (string) ( $row['note'] ?? '' ),
					'reporter_name'  => $reporterName ?: 'A user',
					'status'         => (string) $row['status'],
					'created_at'     => date( 'M j, Y g:i A', (int) $row['created_at'] ),
					'updated_at'     => $row['updated_at'] ? date( 'M j, Y g:i A', (int) $row['updated_at'] ) : '',
				];
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( $e, 'gddealer_reported_listings' ); } catch ( \Throwable ) {}
		}

		$pagination = '';
		if ( $total > $perPage )
		{
			$pagination = (string) \IPS\Theme::i()->getTemplate( 'global', 'core', 'global' )->pagination(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=reportedListings' ),
				(int) ceil( $total / $perPage ),
				$page,
				$perPage
			);
		}

		$csrfKey = \IPS\Session::i()->csrfKey;

		$data = [
			'reports'       => $reports,
			'total'         => $total,
			'pagination'    => $pagination,
			'statusFilter'  => $statusFilter,
			'csrfKey'       => $csrfKey,
		];

		$this->output( 'reportedListings',
			\IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->reportedListings( $data )
		);
	}

	protected function resolveReport(): void
	{
		\IPS\Session::i()->csrfCheck();
		$dealerId = (int) $this->dealer->dealer_id;

		$reportId  = (int) \IPS\Request::i()->report_id;
		$newStatus = (string) ( \IPS\Request::i()->new_status ?? '' );

		$validStatuses = [ 'reviewed', 'resolved', 'dismissed' ];
		if ( !in_array( $newStatus, $validStatuses, true ) )
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=reportedListings' )
			);
			return;
		}

		try
		{
			\IPS\Db::i()->update( 'gd_listing_reports', [
				'status'     => $newStatus,
				'updated_at' => time(),
			], [ 'id=? AND dealer_id=?', $reportId, $dealerId ] );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( $e, 'gddealer_resolve_report' ); } catch ( \Throwable ) {}
		}

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=reportedListings' )
		);
	}
}

class dashboard extends _dashboard {}
