<?php
/**
 * @brief       GD Dealer Manager — ACP Dealers Controller
 * @package     IPS Community Suite
 * @subpackage  GD Dealer Manager
 * @since       15 Apr 2026
 *
 * Section 3.12: dealers list with tier / status / listing count,
 * per-dealer detail view, suspend/unsuspend, force feed import.
 */

namespace IPS\gddealer\modules\admin\dealers;

use IPS\gddealer\Attachment\Helper as AttachHelper;
use IPS\gddealer\Dealer\Dealer;
use IPS\gddealer\Dispute\EventLogger;
use IPS\gddealer\Feed\Importer;
use IPS\gddealer\Listing\Listing;
use IPS\gddealer\Log\ImportLog;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _dealers extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	protected const DEALER_ID_TABLES = [
		'gd_dealer_listings',
		'gd_dealer_import_log',
		'gd_dealer_feed_uploads',
		'gd_dealer_category_map',
		'gd_dealer_slug_history',
		'gd_dealer_rank_snapshot',
		'gd_dealer_dispute_counts',
		'gd_dealer_reset_backups',
		'gd_dealer_data_flags',
		'gd_dealer_ratings',
		'gd_dealer_support_tickets',
		'gd_unmatched_upcs',
		'gd_price_history',
		'gd_click_log',
		'gd_click_daily',
		'gd_deals',
		'gd_dealer_coupons',
	];

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'dealer_manage' );
		parent::execute();
	}

	protected function manage()
	{
		$raw = Dealer::loadAll();

		$onboardUrl = (string) \IPS\Http\Url::internal(
			'app=gddealer&module=dealers&controller=dealers&do=manualOnboard'
		);

		$dealers = [];
		foreach ( $raw as $dealer )
		{
			$listingCount = 0;
			try
			{
				$listingCount = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_dealer_listings', [ 'dealer_id=?', (int) $dealer->dealer_id ] )->first();
			}
			catch ( \Exception ) {}

			$viewUrl = (string) \IPS\Http\Url::internal(
				'app=gddealer&module=dealers&controller=dealers&do=view&id=' . (int) $dealer->dealer_id
			);
			$editUrl = (string) \IPS\Http\Url::internal(
				'app=gddealer&module=dealers&controller=dealers&do=edit&id=' . (int) $dealer->dealer_id
			);
			$suspendUrl = (string) \IPS\Http\Url::internal(
				'app=gddealer&module=dealers&controller=dealers&do=toggleSuspend&id=' . (int) $dealer->dealer_id
			)->csrf();
			$importUrl = (string) \IPS\Http\Url::internal(
				'app=gddealer&module=dealers&controller=dealers&do=forceImport&id=' . (int) $dealer->dealer_id
			)->csrf();

			$slug = (string) ( $dealer->dealer_slug ?? '' );
			$profileUrl = $slug !== ''
				? (string) \IPS\Http\Url::internal(
					'app=gddealer&module=dealers&controller=profile&dealer_slug=' . urlencode( $slug ),
					'front', 'dealers_profile', $slug
				)
				: '';

			$dealers[] = [
				'dealer_id'           => (int) $dealer->dealer_id,
				'dealer_name'         => (string) $dealer->dealer_name,
				'dealer_slug'         => $slug,
				'subscription_tier'   => (string) $dealer->subscription_tier,
				'active'              => (bool) $dealer->active,
				'suspended'           => (bool) $dealer->suspended,
				'disputes_suspended'  => (bool) ( $dealer->disputes_suspended ?? 0 ),
				'listing_count'       => $listingCount,
				'last_run'            => $dealer->last_run ?? null,
				'last_run_status'     => $dealer->last_run_status ?? null,
				'mrr'                 => '$' . number_format( $dealer->mrrContribution(), 2 ),
				'view_url'            => $viewUrl,
				'edit_url'            => $editUrl,
				'suspend_url'         => $suspendUrl,
				'import_url'          => $importUrl,
				'profile_url'         => $profileUrl,
			];
		}

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gddealer_dealers_title' );
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'admin' )->dealerList( $dealers, $onboardUrl );
	}

	/**
	 * View a single dealer — import log, listings, unmatched summary.
	 */
	protected function view()
	{
		$id = (int) \IPS\Request::i()->id;
		try
		{
			$dealer = Dealer::load( $id );
		}
		catch ( \OutOfRangeException )
		{
			\IPS\Output::i()->error( 'node_error', '2GDD200/1', 404 );
			return;
		}

		$logs = [];
		foreach ( ImportLog::loadForDealer( (int) $dealer->dealer_id, 20 ) as $log )
		{
			$logs[] = [
				'id'                 => (int) $log->id,
				'run_start'          => (string) $log->run_start,
				'run_end'            => (string) ( $log->run_end ?? '' ),
				'status'             => (string) $log->status,
				'records_total'      => (int) $log->records_total,
				'records_created'    => (int) $log->records_created,
				'records_updated'    => (int) $log->records_updated,
				'records_unchanged'  => (int) $log->records_unchanged,
				'records_unmatched'  => (int) $log->records_unmatched,
				'price_drops'        => (int) $log->price_drops,
				'error_log'          => (string) ( $log->error_log ?? '' ),
			];
		}

		$listings = [];
		foreach ( Listing::loadForDealer( (int) $dealer->dealer_id, 0, 25 ) as $l )
		{
			$listings[] = [
				'upc'            => (string) $l->upc,
				'dealer_price'   => '$' . number_format( (float) $l->dealer_price, 2 ),
				'in_stock'       => (bool) $l->in_stock,
				'listing_status' => (string) $l->listing_status,
				'last_updated'   => (string) ( $l->last_seen_in_feed ?? '' ),
			];
		}

		$trialRaw      = $dealer->trial_expires_at ?? null;
		$trialExpires  = $trialRaw ? (string) $trialRaw : '';
		$trialSoon     = false;
		if ( $trialRaw )
		{
			try
			{
				$dt = new \DateTime( $trialRaw );
				$now = new \DateTime();
				$trialSoon = ( $dt > $now && $dt->getTimestamp() - $now->getTimestamp() <= 30 * 86400 );
			}
			catch ( \Exception ) {}
		}

		$invoiceUrl = (string) \IPS\Http\Url::internal(
			'app=nexus&module=customers&controller=search&do=view&id=' . (int) $dealer->dealer_id
		);

		$slug       = (string) ( $dealer->dealer_slug ?? '' );
		$profileUrl = $slug !== ''
			? (string) \IPS\Http\Url::internal(
				'app=gddealer&module=dealers&controller=profile&dealer_slug=' . urlencode( $slug ),
				'front', 'dealers_profile', $slug
			)
			: '';

		$dealerData = [
			'dealer_id'         => (int) $dealer->dealer_id,
			'dealer_name'       => (string) $dealer->dealer_name,
			'dealer_slug'       => $slug,
			'subscription_tier' => (string) $dealer->subscription_tier,
			'feed_url'          => (string) ( $dealer->feed_url ?? '' ),
			'feed_format'       => strtoupper( (string) $dealer->feed_format ),
			'import_schedule'   => (string) $dealer->import_schedule,
			'active'            => (bool) $dealer->active,
			'suspended'         => (bool) $dealer->suspended,
			'last_run'          => $dealer->last_run ?? null,
			'last_record_count' => (int) $dealer->last_record_count,
			'api_key'           => (string) ( $dealer->api_key ?? '' ),
			'mrr'               => '$' . number_format( $dealer->mrrContribution(), 2 ),
			'trial_expires_at'  => $trialExpires,
			'trial_expires_soon'=> $trialSoon,
			'billing_note'         => (string) ( $dealer->billing_note ?? '' ),
			'profile_url'                  => $profileUrl,
			'regenerate_preview_url'       => (string) \IPS\Http\Url::internal(
				'app=gddealer&module=dealers&controller=dealers&do=regenerateSlug&id=' . (int) $dealer->dealer_id . '&preview=1'
			),
			'regenerate_confirm_url'       => (string) \IPS\Http\Url::internal(
				'app=gddealer&module=dealers&controller=dealers&do=regenerateSlug&id=' . (int) $dealer->dealer_id
			),
			'disputes_suspended'   => (bool) ( $dealer->disputes_suspended ?? 0 ),
			'failed_log_count'     => 0,
			'clear_logs_url'       => (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers&do=clearFailedLogs&dealer_id=' . (int) $dealer->dealer_id )->csrf(),
			'reset_feed_url'       => (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers&do=resetFeed&dealer_id=' . (int) $dealer->dealer_id )->csrf(),
		];

		try {
			$dealerData['failed_log_count'] = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_dealer_import_log', [ 'dealer_id=? AND status=?', (int) $dealer->dealer_id, 'failed' ] )->first();
		} catch ( \Throwable ) {}

		$backUrl    = (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers' );
		$editUrl    = (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers&do=edit&id=' . (int) $dealer->dealer_id );
		$importUrl  = (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers&do=forceImport&id=' . (int) $dealer->dealer_id )->csrf();
		$suspendUrl = (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers&do=toggleSuspend&id=' . (int) $dealer->dealer_id )->csrf();
		$disputeSuspendUrl = (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers&do=toggleDisputeSuspend&id=' . (int) $dealer->dealer_id )->csrf();
		$transferUrl = (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers&do=transferOwnership&id=' . (int) $dealer->dealer_id );
		$deleteUrl   = (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers&do=permanentDelete&id=' . (int) $dealer->dealer_id )->csrf();

		$reviews = [];
		try
		{
			foreach ( \IPS\Db::i()->select( '*', 'gd_dealer_ratings',
				[ 'dealer_id=?', (int) $dealer->dealer_id ],
				'created_at DESC', [ 0, 20 ]
			) as $r )
			{
				$memberName = '';
				try
				{
					$m = \IPS\Member::load( (int) $r['member_id'] );
					if ( $m->member_id ) { $memberName = (string) $m->name; }
				}
				catch ( \Exception ) {}

				$reviews[] = [
					'id'              => (int) $r['id'],
					'member_id'       => (int) $r['member_id'],
					'member_name'     => $memberName,
					'rating_pricing'  => (int) $r['rating_pricing'],
					'rating_shipping' => (int) $r['rating_shipping'],
					'rating_service'  => (int) $r['rating_service'],
					'review_body'     => ( $r['review_body'] ?? '' ) !== '' ? \IPS\Text\Parser::parseStatic( (string) $r['review_body'], [ (int) $r['id'], 1 ], NULL, 'gddealer_Responses' ) : '',
					'dealer_response' => ( $r['dealer_response'] ?? '' ) !== '' ? \IPS\Text\Parser::parseStatic( (string) $r['dealer_response'], [ (int) $r['id'], 2 ], NULL, 'gddealer_Responses' ) : '',
					'dispute_status'  => (string) ( $r['dispute_status'] ?? 'none' ),
					'created_at'      => (string) $r['created_at'],
					'delete_url'      => (string) \IPS\Http\Url::internal(
						'app=gddealer&module=dealers&controller=dealers&do=deleteReview&id=' . (int) $r['id']
					)->csrf(),
				];
			}
		}
		catch ( \Exception ) {}

		\IPS\Output::i()->title  = $dealerData['dealer_name'];
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'admin' )->dealerDetail(
			$dealerData, $logs, $listings, $backUrl, $editUrl, $importUrl, $suspendUrl, $invoiceUrl, $disputeSuspendUrl, $reviews, $transferUrl, $deleteUrl
		);
	}

	/**
	 * Edit a dealer's feed configuration.
	 */
	protected function edit()
	{
		if ( \IPS\Request::i()->requestMethod() === 'POST' )
		{
			\IPS\Session::i()->csrfCheck();
		}

		$id     = (int) \IPS\Request::i()->id;
		$dealer = Dealer::load( $id );

		$form = new \IPS\Helpers\Form;
		$form->add( new \IPS\Helpers\Form\Text( 'gddealer_dealer_name', $dealer->dealer_name, TRUE ) );
		$form->add( new \IPS\Helpers\Form\Select( 'gddealer_dealer_tier', $dealer->subscription_tier, TRUE, [
			'options' => [
				'basic'      => 'Basic',
				'pro'        => 'Pro',
				'enterprise' => 'Enterprise',
				'founding'   => 'Founding',
			],
		] ) );
		$form->add( new \IPS\Helpers\Form\Url( 'gddealer_dealer_feed_url', $dealer->feed_url, FALSE ) );
		$form->add( new \IPS\Helpers\Form\Select( 'gddealer_dealer_feed_format', $dealer->feed_format, TRUE, [
			'options' => [ 'xml' => 'XML', 'json' => 'JSON', 'csv' => 'CSV' ],
		] ) );
		$form->add( new \IPS\Helpers\Form\Select( 'gddealer_dealer_auth_type', $dealer->auth_type, TRUE, [
			'options' => [ 'none' => 'None', 'basic' => 'Basic Auth', 'apikey' => 'API Key', 'ftp' => 'FTP' ],
		] ) );
		$form->add( new \IPS\Helpers\Form\TextArea( 'gddealer_dealer_auth_credentials', $dealer->getCredentials() ?? '', FALSE, [
			'placeholder' => 'JSON: {"username":"...","password":"..."} or {"api_key":"..."}',
		] ) );
		$form->add( new \IPS\Helpers\Form\TextArea( 'gddealer_dealer_field_mapping', $dealer->field_mapping ?? '', FALSE, [
			'rows'        => 10,
			'placeholder' => '{"DEALER_FIELD":"canonical_field", "UPC_CODE":"upc", "PRICE":"dealer_price", ...}',
		] ) );
		$form->add( new \IPS\Helpers\Form\Select( 'gddealer_dealer_import_schedule', $dealer->import_schedule, TRUE, [
			'options' => [
				'15min' => 'Every 15 minutes',
				'30min' => 'Every 30 minutes',
				'1hr'   => 'Hourly',
				'6hr'   => 'Every 6 hours',
				'daily' => 'Daily',
			],
		] ) );
		$form->add( new \IPS\Helpers\Form\YesNo( 'gddealer_dealer_active', $dealer->active, FALSE ) );

		$existingTrialDate = null;
		if ( $dealer->trial_expires_at )
		{
			try { $existingTrialDate = new \IPS\DateTime( $dealer->trial_expires_at ); }
			catch ( \Exception ) {}
		}
		$form->add( new \IPS\Helpers\Form\Date( 'gddealer_onboard_trial_expires', $existingTrialDate, FALSE ) );
		$form->add( new \IPS\Helpers\Form\TextArea( 'gddealer_onboard_billing_note', (string) ( $dealer->billing_note ?? '' ), FALSE, [ 'rows' => 3 ] ) );

		if ( $values = $form->values() )
		{
			$dealer->dealer_name       = $values['gddealer_dealer_name'];
			$dealer->subscription_tier = $values['gddealer_dealer_tier'];
			$dealer->feed_url          = (string) $values['gddealer_dealer_feed_url'];
			$dealer->feed_format       = $values['gddealer_dealer_feed_format'];
			$dealer->auth_type         = $values['gddealer_dealer_auth_type'];
			$dealer->import_schedule   = $values['gddealer_dealer_import_schedule'];
			$dealer->active            = (int) $values['gddealer_dealer_active'];

			$creds = trim( $values['gddealer_dealer_auth_credentials'] );
			$dealer->setCredentials( $creds !== '' ? $creds : null );

			$mapJson = trim( $values['gddealer_dealer_field_mapping'] );
			$dealer->field_mapping = ( $mapJson !== '' && json_decode( $mapJson ) !== null ) ? $mapJson : null;

			if ( $values['gddealer_onboard_trial_expires'] instanceof \IPS\DateTime )
			{
				$dealer->trial_expires_at = $values['gddealer_onboard_trial_expires']->format( 'Y-m-d H:i:s' );
			}
			else
			{
				$dealer->trial_expires_at = null;
			}
			$billingNote = trim( (string) $values['gddealer_onboard_billing_note'] );
			$dealer->billing_note = $billingNote !== '' ? $billingNote : null;

			$dealer->save();

			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers&do=view&id=' . (int) $dealer->dealer_id ),
				'saved'
			);
		}

		\IPS\Output::i()->title  = $dealer->dealer_name;
		\IPS\Output::i()->output = (string) $form;
	}

	/**
	 * v1.0.182: Manually regenerate a dealer's URL slug from their current
	 * dealer_name. Records the old slug in gd_dealer_slug_history so old
	 * URLs continue 301-redirecting to the new one (handled in
	 * front/dealers/profile.php).
	 *
	 * GET ?preview=1 - returns JSON {old_slug, new_slug, would_change} for
	 *                  the confirmation modal to show user before they confirm.
	 * POST           - actually performs the regenerate. CSRF-checked.
	 */
	protected function regenerateSlug(): void
	{
		$id = (int) \IPS\Request::i()->id;
		if ( $id <= 0 )
		{
			\IPS\Output::i()->error( 'gddealer_regenerate_slug_failed', '2GDD400/1', 400 );
			return;
		}

		/* Preview mode for the confirmation modal */
		if ( !empty( \IPS\Request::i()->preview ) )
		{
			$preview = \IPS\gddealer\Dealer\Slug::previewRegenerate( $id );
			\IPS\Output::i()->json( [
				'old_slug'     => (string) $preview['old_slug'],
				'new_slug'     => (string) $preview['new_slug'],
				'would_change' => (bool) $preview['would_change'],
			] );
			return;
		}

		/* Real run - CSRF required */
		\IPS\Session::i()->csrfCheck();

		$result = \IPS\gddealer\Dealer\Slug::regenerate( $id, 'admin_regenerate' );

		if ( $result['success'] )
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers&do=view&id=' . $id ),
				\IPS\Member::loggedIn()->language()->addToStack( 'gddealer_regenerate_slug_done' )
			);
		}
		else if ( $result['message'] === 'unchanged' )
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers&do=view&id=' . $id ),
				\IPS\Member::loggedIn()->language()->addToStack( 'gddealer_regenerate_slug_unchanged' )
			);
		}
		else
		{
			\IPS\Output::i()->error( 'gddealer_regenerate_slug_failed', '2GDD400/2', 500 );
		}
	}

	/**
	 * Toggle dealer suspension. Suspended dealers' listings become invisible.
	 */
	protected function toggleSuspend()
	{
		\IPS\Session::i()->csrfCheck();

		$dealer = Dealer::load( (int) \IPS\Request::i()->id );
		$dealer->suspended = $dealer->suspended ? 0 : 1;
		$dealer->save();

		/* Cascade to listings */
		if ( $dealer->suspended )
		{
			\IPS\Db::i()->update(
				'gd_dealer_listings',
				[ 'listing_status' => Listing::STATUS_SUSPENDED ],
				[ 'dealer_id=? AND listing_status<>?', (int) $dealer->dealer_id, Listing::STATUS_SUSPENDED ]
			);
		}
		else
		{
			\IPS\Db::i()->update(
				'gd_dealer_listings',
				[ 'listing_status' => Listing::STATUS_ACTIVE ],
				[ 'dealer_id=? AND listing_status=?', (int) $dealer->dealer_id, Listing::STATUS_SUSPENDED ]
			);
		}

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers&do=view&id=' . (int) $dealer->dealer_id ),
			$dealer->suspended ? 'Dealer suspended' : 'Dealer unsuspended'
		);
	}

	/**
	 * Manual onboarding — admin-only stub used until IPS Commerce + Stripe
	 * is live. Creates a gd_dealer_feed_config row for the selected member,
	 * assigns the tier-specific Dealers secondary group, and
	 * generates an API key. feed_url is left NULL, which triggers the
	 * "onboarding incomplete" banner on the frontend dashboard. Once
	 * Commerce is wired (Section 3.3), this becomes a group-promotion
	 * listener instead.
	 */
	protected function manualOnboard()
	{
		$form = new \IPS\Helpers\Form( 'form', 'gddealer_onboard_submit' );
		$form->add( new \IPS\Helpers\Form\Member( 'gddealer_onboard_member', null, TRUE ) );
		$form->add( new \IPS\Helpers\Form\Text( 'gddealer_onboard_name', '', TRUE ) );
		$form->add( new \IPS\Helpers\Form\Select( 'gddealer_onboard_tier', Dealer::TIER_BASIC, TRUE, [
			'options' => [
				Dealer::TIER_BASIC      => 'Basic',
				Dealer::TIER_PRO        => 'Pro',
				Dealer::TIER_ENTERPRISE => 'Enterprise',
				Dealer::TIER_FOUNDING   => 'Founding',
			],
		] ) );
		$form->add( new \IPS\Helpers\Form\Date( 'gddealer_onboard_trial_expires', null, FALSE ) );
		$form->add( new \IPS\Helpers\Form\TextArea( 'gddealer_onboard_billing_note', '', FALSE, [ 'rows' => 3 ] ) );

		if ( $values = $form->values() )
		{
			$member = $values['gddealer_onboard_member'];
			if ( !( $member instanceof \IPS\Member ) || !$member->member_id )
			{
				\IPS\Output::i()->error( 'gddealer_onboard_no_member', '2GDD210/1', 400 );
				return;
			}

			$memberId = (int) $member->member_id;

			try
			{
				$exists = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_dealer_feed_config', [ 'dealer_id=?', $memberId ] )->first();
			}
			catch ( \Exception )
			{
				$exists = 0;
			}

			if ( $exists > 0 )
			{
				\IPS\Output::i()->redirect(
					\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers&do=view&id=' . $memberId ),
					'gddealer_onboard_existing'
				);
				return;
			}

			$tier    = (string) $values['gddealer_onboard_tier'];
			$tierKey = array_key_exists( $tier, Dealer::$tierSchedules ) ? $tier : Dealer::TIER_BASIC;
			$apiKey  = bin2hex( random_bytes( 24 ) );

			$trialExpires = null;
			if ( $values['gddealer_onboard_trial_expires'] instanceof \IPS\DateTime )
			{
				$trialExpires = $values['gddealer_onboard_trial_expires']->format( 'Y-m-d H:i:s' );
			}

			$dealerName = (string) $values['gddealer_onboard_name'];

			/* Build a URL-safe slug from the dealer name. Uniqueness is
			   enforced by the uq_dealer_slug index; we append -1, -2, ...
			   until a free slug is found. */
			$slug = strtolower( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $dealerName ) ) );
			$slug = trim( $slug, '-' );
			if ( $slug === '' )
			{
				$slug = 'dealer-' . $memberId;
			}
			$base = $slug;
			$i    = 1;
			while ( true )
			{
				try
				{
					$exists = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_dealer_feed_config', [ 'dealer_slug=?', $slug ] )->first();
				}
				catch ( \Exception )
				{
					$exists = 0;
				}
				if ( $exists === 0 ) { break; }
				$slug = $base . '-' . $i++;
			}

			\IPS\Db::i()->insert( 'gd_dealer_feed_config', [
				'dealer_id'         => $memberId,
				'dealer_name'       => $dealerName,
				'dealer_slug'       => $slug,
				'subscription_tier' => $tierKey,
				'feed_url'          => null,
				'feed_format'       => 'xml',
				'auth_type'         => 'none',
				'auth_credentials'  => null,
				'field_mapping'     => null,
				'import_schedule'   => Dealer::$tierSchedules[ $tierKey ],
				'active'            => 0,
				'suspended'         => 0,
				'last_run'          => null,
				'last_run_status'   => null,
				'last_record_count' => 0,
				'api_key'           => $apiKey,
				'created_at'        => date( 'Y-m-d H:i:s' ),
				'trial_expires_at'  => $trialExpires,
				'billing_note'      => trim( (string) $values['gddealer_onboard_billing_note'] ) ?: null,
			]);

			$this->assignDealersGroup( $member, $tierKey );

			try
			{
				\IPS\Email::buildFromTemplate( 'gddealer', 'dealerWelcome', [
					'name'          => $member->name,
					'api_key'       => $apiKey,
					'contact_email' => (string) ( \IPS\Settings::i()->gddealer_help_contact ?: 'dealers@gunrack.deals' ),
					'profile_url'   => (string) \IPS\Http\Url::internal(
						'app=gddealer&module=dealers&controller=profile&dealer_slug=' . urlencode( $slug ),
						'front', 'dealers_profile', $slug
					),
				], \IPS\Email::TYPE_TRANSACTIONAL )->send( $member );
			}
			catch ( \Exception ) {}

			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers&do=view&id=' . $memberId ),
				'gddealer_onboard_created'
			);
			return;
		}

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gddealer_onboard_title' );
		\IPS\Output::i()->output = (string) $form;
	}

	/**
	 * Add the tier-specific Dealers secondary group to a member.
	 * Uses Dealer::groupIdForTier() to resolve the correct group.
	 * No-op if the setting is unconfigured or the member already
	 * belongs to that group.
	 */
	protected function assignDealersGroup( \IPS\Member $member, string $tier ): void
	{
		$groupId = Dealer::groupIdForTier( $tier );
		if ( $groupId <= 0 )
		{
			return;
		}

		$current = $member->mgroup_others ? explode( ',', (string) $member->mgroup_others ) : [];
		$current = array_filter( array_map( 'intval', $current ) );
		if ( !in_array( $groupId, $current, true ) )
		{
			$current[] = $groupId;
			$member->mgroup_others = implode( ',', $current );
			$member->save();
		}
	}

	protected function removeDealersGroup( \IPS\Member $member ): void
	{
		$allTiers = [ 'basic', 'pro', 'founding', 'enterprise' ];
		$current  = $member->mgroup_others ? explode( ',', (string) $member->mgroup_others ) : [];
		$current  = array_filter( array_map( 'intval', $current ) );
		$changed  = false;

		foreach ( $allTiers as $tier )
		{
			$groupId = Dealer::groupIdForTier( $tier );
			if ( $groupId > 0 && in_array( $groupId, $current, true ) )
			{
				$current = array_diff( $current, [ $groupId ] );
				$changed = true;
			}
		}

		if ( $changed )
		{
			$member->mgroup_others = implode( ',', array_values( $current ) );
			$member->save();
		}
	}

	/**
	 * Toggle dispute suspension for a dealer. Suspended dealers cannot
	 * file new review contests until an admin lifts the suspension.
	 */
	protected function toggleDisputeSuspend(): void
	{
		\IPS\Session::i()->csrfCheck();

		$dealer = Dealer::load( (int) \IPS\Request::i()->id );
		$current = (int) ( $dealer->disputes_suspended ?? 0 );
		$dealer->disputes_suspended = $current ? 0 : 1;
		$dealer->save();

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers&do=view&id=' . (int) $dealer->dealer_id ),
			$dealer->disputes_suspended ? 'gddealer_disputes_suspended_on' : 'gddealer_disputes_suspended_off'
		);
	}

	/* =============== Disputed Reviews Queue =============== */

	/**
	 * Show every review in 'pending_admin' or 'pending_customer' status.
	 * Admin sees both sides of each dispute.
	 */
	protected function disputes()
	{
		$filterStatus = (string) ( \IPS\Request::i()->status ?? 'active' );
		$validFilters = [ 'active', 'pending_admin', 'pending_customer', 'resolved_dealer', 'dismissed', 'all' ];
		if ( !in_array( $filterStatus, $validFilters, TRUE ) )
		{
			$filterStatus = 'active';
		}

		$counts = [
			'active'           => 0,
			'pending_admin'    => 0,
			'pending_customer' => 0,
			'resolved_dealer'  => 0,
			'dismissed'        => 0,
			'all'              => 0,
		];
		try
		{
			foreach ( \IPS\Db::i()->select( 'dispute_status, COUNT(*) as cnt', 'gd_dealer_ratings',
				[ 'dispute_status<>?', 'none' ], NULL, NULL, 'dispute_status'
			) as $c )
			{
				$s = (string) $c['dispute_status'];
				$n = (int) $c['cnt'];
				if ( isset( $counts[ $s ] ) ) { $counts[ $s ] = $n; }
				$counts['all'] += $n;
				if ( $s === 'pending_admin' || $s === 'pending_customer' ) { $counts['active'] += $n; }
			}
		}
		catch ( \Exception ) {}

		switch ( $filterStatus )
		{
			case 'pending_admin':
				$where = [ 'dispute_status=?', 'pending_admin' ];
				break;
			case 'pending_customer':
				$where = [ 'dispute_status=?', 'pending_customer' ];
				break;
			case 'resolved_dealer':
				$where = [ 'dispute_status=?', 'resolved_dealer' ];
				break;
			case 'dismissed':
				$where = [ 'dispute_status=?', 'dismissed' ];
				break;
			case 'all':
				$where = [ 'dispute_status<>?', 'none' ];
				break;
			default:
				$where = [ \IPS\Db::i()->in( 'dispute_status', [ 'pending_admin', 'pending_customer' ] ) ];
				break;
		}

		$rows = [];
		try
		{
			foreach ( \IPS\Db::i()->select( '*', 'gd_dealer_ratings',
				$where,
				'dispute_at DESC',
				[ 0, 200 ]
			) as $r )
			{
				$dealerName = '';
				try
				{
					$dealerName = (string) \IPS\Db::i()->select( 'dealer_name', 'gd_dealer_feed_config', [ 'dealer_id=?', (int) $r['dealer_id'] ] )->first();
				}
				catch ( \Exception ) {}

				$memberName = '';
				try
				{
					$m = \IPS\Member::load( (int) $r['member_id'] );
					if ( $m->member_id ) { $memberName = (string) $m->name; }
				}
				catch ( \Exception ) {}

				$rows[] = [
					'id'                    => (int) $r['id'],
					'dealer_id'             => (int) $r['dealer_id'],
					'dealer_name'           => $dealerName,
					'member_id'             => (int) $r['member_id'],
					'member_name'           => $memberName,
					'rating_pricing'        => (int) $r['rating_pricing'],
					'rating_shipping'       => (int) $r['rating_shipping'],
					'rating_service'        => (int) $r['rating_service'],
					'review_body'           => ( $r['review_body'] ?? '' ) !== '' ? \IPS\Text\Parser::parseStatic( (string) $r['review_body'], [ (int) $r['id'], 1 ], NULL, 'gddealer_Responses' ) : '',
					'dealer_response'       => ( $r['dealer_response'] ?? '' ) !== '' ? \IPS\Text\Parser::parseStatic( (string) $r['dealer_response'], [ (int) $r['id'], 2 ], NULL, 'gddealer_Responses' ) : '',
					'dispute_status'        => (string) ( $r['dispute_status'] ?? '' ),
					'dispute_reason'        => ( $r['dispute_reason'] ?? '' ) !== '' ? \IPS\Text\Parser::parseStatic( (string) $r['dispute_reason'], [ (int) $r['id'], 3 ], NULL, 'gddealer_Responses' ) : '',
					'dispute_evidence'      => ( $r['dispute_evidence'] ?? '' ) !== '' ? \IPS\Text\Parser::parseStatic( (string) $r['dispute_evidence'], [ (int) $r['id'], 4 ], NULL, 'gddealer_Responses' ) : '',
					'dispute_at'            => (string) ( $r['dispute_at'] ?? '' ),
					'dispute_deadline'      => ( $r['dispute_deadline'] ?? '' ) !== '' ? date( 'F j, Y', strtotime( (string) $r['dispute_deadline'] ) ) : '',
					'days_remaining'        => ( $r['dispute_deadline'] ?? '' ) !== '' ? max( 0, (int) ceil( ( strtotime( (string) $r['dispute_deadline'] ) - time() ) / 86400 ) ) : 0,
					'customer_response'     => ( $r['customer_response'] ?? '' ) !== '' ? \IPS\Text\Parser::parseStatic( (string) $r['customer_response'], [ (int) $r['id'], 5 ], NULL, 'gddealer_Responses' ) : '',
					'customer_evidence'     => ( $r['customer_evidence'] ?? '' ) !== '' ? \IPS\Text\Parser::parseStatic( (string) $r['customer_evidence'], [ (int) $r['id'], 6 ], NULL, 'gddealer_Responses' ) : '',
					'customer_responded_at' => (string) ( $r['customer_responded_at'] ?? '' ),
					'dispute_outcome'       => (string) ( $r['dispute_outcome'] ?? '' ),
					'dispute_resolved_at'   => (string) ( $r['dispute_resolved_at'] ?? '' ),
					'created_at'            => (string) $r['created_at'],
					'uphold_url'            => (string) \IPS\Http\Url::internal(
						'app=gddealer&module=dealers&controller=dealers&do=upholdDispute&id=' . (int) $r['id']
					)->csrf(),
					'dismiss_url'           => (string) \IPS\Http\Url::internal(
						'app=gddealer&module=dealers&controller=dealers&do=dismissDispute&id=' . (int) $r['id']
					)->csrf(),
					'request_edit_url'      => (string) \IPS\Http\Url::internal(
						'app=gddealer&module=dealers&controller=dealers&do=requestEdit&id=' . (int) $r['id']
					)->csrf(),
					'status_label'          => match( (string) ( $r['dispute_status'] ?? '' ) ) {
						'pending_admin'    => 'Awaiting Admin',
						'pending_customer' => 'Awaiting Customer',
						'resolved_dealer'  => 'Upheld',
						'dismissed'        => 'Dismissed',
						default            => ucfirst( str_replace( '_', ' ', (string) ( $r['dispute_status'] ?? '' ) ) ),
					},
					'status_bg'             => match( (string) ( $r['dispute_status'] ?? '' ) ) {
						'pending_admin'    => '#dbeafe',
						'pending_customer' => '#fef3c7',
						'resolved_dealer'  => '#d1fae5',
						'dismissed'        => '#fee2e2',
						default            => '#f3f4f6',
					},
					'status_color'          => match( (string) ( $r['dispute_status'] ?? '' ) ) {
						'pending_admin'    => '#1e40af',
						'pending_customer' => '#92400e',
						'resolved_dealer'  => '#065f46',
						'dismissed'        => '#991b1b',
						default            => '#374151',
					},
					'status_border'         => match( (string) ( $r['dispute_status'] ?? '' ) ) {
						'pending_admin'    => '#93c5fd',
						'pending_customer' => '#fcd34d',
						'resolved_dealer'  => '#6ee7b7',
						'dismissed'        => '#fca5a5',
						default            => '#d1d5db',
					},
					'dispute_at_formatted'  => ( $r['dispute_at'] ?? '' ) !== '' ? date( 'M j, Y', strtotime( (string) $r['dispute_at'] ) ) : '',
				];

				$rowRef =& $rows[ count( $rows ) - 1 ];
				$attFields = [
					'review_body'      => 1,
					'dispute_reason'   => 3,
					'dispute_evidence' => 4,
					'customer_response'=> 5,
					'customer_evidence'=> 6,
				];
				foreach ( $attFields as $field => $hint )
				{
					$atts = AttachHelper::getAttachments( (int) $r['id'], $hint );
					$rowRef[ $field . '_attachments' ] = $atts;
					$hasImg = false;
					foreach ( $atts as $a ) { if ( $a['is_image'] ) { $hasImg = true; break; } }
					$rowRef[ $field . '_has_unembedded_images' ] = $hasImg && !preg_match( '/<img/i', (string) ( $rowRef[ $field ] ?? '' ) );
				}
				$rowRef['events'] = EventLogger::getEvents( (int) $r['id'] );
				unset( $rowRef );
			}
		}
		catch ( \Exception ) {}

		$baseUrl = (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers&do=disputes' );

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gddealer_disputes_title' );
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'admin' )->disputeQueue( $rows, $filterStatus, $counts, $baseUrl );
	}

	/**
	 * Uphold the dealer's contest. Review stays visible with a badge but
	 * is excluded from rating averages. Dealer cannot be contested twice
	 * for the same review.
	 */
	protected function upholdDispute()
	{
		\IPS\Session::i()->csrfCheck();
		$id = (int) \IPS\Request::i()->id;

		$review = null;
		try
		{
			$review = \IPS\Db::i()->select( '*', 'gd_dealer_ratings', [ 'id=?', $id ] )->first();
		}
		catch ( \Exception ) {}

		$dealerId   = (int) ( $review['dealer_id'] ?? 0 );
		$dealerName = '';
		if ( $dealerId > 0 )
		{
			try
			{
				$dealerName = (string) \IPS\Db::i()->select( 'dealer_name', 'gd_dealer_feed_config', [ 'dealer_id=?', $dealerId ] )->first();
			}
			catch ( \Exception ) {}
		}

		try
		{
			\IPS\Db::i()->update( 'gd_dealer_ratings', [
				'dispute_status'      => 'resolved_dealer',
				'dispute_outcome'     => 'upheld',
				'dispute_resolved_by' => (int) \IPS\Member::loggedIn()->member_id,
				'dispute_resolved_at' => date( 'Y-m-d H:i:s' ),
			], [ 'id=? AND ' . \IPS\Db::i()->in( 'dispute_status', [ 'pending_admin', 'pending_customer' ] ), $id ] );
		}
		catch ( \Exception ) {}

		EventLogger::log( $id, 'admin_upheld', 'admin', (int) \IPS\Member::loggedIn()->member_id, NULL );

		/* Side-effects to the dealer — email and IPS notification in
		   independent try/catch blocks so one channel's failure cannot
		   suppress the other. */
		if ( $dealerId > 0 )
		{
			$dealerMember = NULL;
			try { $dealerMember = \IPS\Member::load( $dealerId ); } catch ( \Exception ) {}

			try
			{
				if ( $dealerMember && $dealerMember->member_id )
				{
					\IPS\Email::buildFromTemplate( 'gddealer', 'disputeUpheld', [
						'name' => $dealerMember->name,
					], \IPS\Email::TYPE_TRANSACTIONAL )->send( $dealerMember );
				}
			}
			catch ( \Exception ) {}

			try
			{
				if ( $dealerMember && $dealerMember->member_id )
				{
					$notification = new \IPS\Notification(
						\IPS\Application::load( 'gddealer' ),
						'dispute_upheld',
						$dealerMember,
						[ $dealerMember ],
						[
							'dealer_name' => $dealerName,
						]
					);
					$notification->recipients->attach( $dealerMember );
					$notification->send();
				}
			}
			catch ( \Exception ) {}
		}

		/* Side-effects to the reviewer — email and PM in independent
		   try/catch blocks. */
		if ( $review && (int) ( $review['member_id'] ?? 0 ) > 0 )
		{
			$reviewerMember = NULL;
			try { $reviewerMember = \IPS\Member::load( (int) $review['member_id'] ); } catch ( \Exception ) {}

			$outcomeText = 'After reviewing the evidence, admin has ruled in the dealer\'s favor. The review will remain visible but no longer affects their rating average.';

			try
			{
				if ( $reviewerMember && $reviewerMember->member_id )
				{
					\IPS\Email::buildFromTemplate( 'gddealer', 'disputeOutcome', [
						'name'        => $reviewerMember->name,
						'dealer_name' => $dealerName,
						'outcome'     => $outcomeText,
					], \IPS\Email::TYPE_TRANSACTIONAL )->send( $reviewerMember );
				}
			}
			catch ( \Exception ) {}

			try
			{
				if ( $reviewerMember && $reviewerMember->member_id )
				{
					$notification = new \IPS\Notification(
						\IPS\Application::load( 'gddealer' ),
						'dispute_outcome_reviewer',
						$reviewerMember,
						[ $reviewerMember ],
						[
							'dealer_name' => $dealerName,
							'outcome'     => 'upheld',
							'review_id'   => (int) $id,
						]
					);
					$notification->recipients->attach( $reviewerMember );
					$notification->send();
				}
			}
			catch ( \Exception ) {}

			try
			{
				if ( $reviewerMember && $reviewerMember->member_id )
				{
					$sender = \IPS\Member::loggedIn();
					if ( \IPS\core\Messenger\Conversation::memberCanReceiveNewMessage( $reviewerMember, $sender ) )
					{
						$conversation = \IPS\core\Messenger\Conversation::createItem( $sender, \IPS\Request::i()->ipAddress(), \IPS\DateTime::create() );
						$conversation->title    = 'GunRack.deals — Review Dispute Resolved';
						$conversation->to_count = 1;
						$conversation->save();

						$commentClass = $conversation::$commentClass;
						$post = $commentClass::create(
							$conversation,
							'Your review on ' . $dealerName . ' was contested. ' . $outcomeText,
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
			\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers&do=disputes' ),
			'gddealer_dispute_upheld'
		);
	}

	/**
	 * Dismiss the dealer's contest. Review stands; dealer cannot re-dispute.
	 * Dealer receives an email notification.
	 */
	protected function dismissDispute()
	{
		\IPS\Session::i()->csrfCheck();
		$id = (int) \IPS\Request::i()->id;

		$review = null;
		try
		{
			$review = \IPS\Db::i()->select( '*', 'gd_dealer_ratings', [ 'id=?', $id ] )->first();
		}
		catch ( \Exception ) {}

		$dealerId   = (int) ( $review['dealer_id'] ?? 0 );
		$dealerName = '';
		if ( $dealerId > 0 )
		{
			try
			{
				$dealerName = (string) \IPS\Db::i()->select( 'dealer_name', 'gd_dealer_feed_config', [ 'dealer_id=?', $dealerId ] )->first();
			}
			catch ( \Exception ) {}
		}

		try
		{
			\IPS\Db::i()->update( 'gd_dealer_ratings', [
				'dispute_status'      => 'dismissed',
				'dispute_outcome'     => 'dismissed',
				'dispute_resolved_by' => (int) \IPS\Member::loggedIn()->member_id,
				'dispute_resolved_at' => date( 'Y-m-d H:i:s' ),
			], [ 'id=? AND ' . \IPS\Db::i()->in( 'dispute_status', [ 'pending_admin', 'pending_customer' ] ), $id ] );
		}
		catch ( \Exception ) {}

		EventLogger::log( $id, 'admin_dismissed', 'admin', (int) \IPS\Member::loggedIn()->member_id, NULL );

		/* Side-effects to the dealer — email and IPS notification in
		   independent try/catch blocks. */
		if ( $dealerId > 0 )
		{
			$dealerMember = NULL;
			try { $dealerMember = \IPS\Member::load( $dealerId ); } catch ( \Exception ) {}

			try
			{
				if ( $dealerMember && $dealerMember->member_id )
				{
					\IPS\Email::buildFromTemplate( 'gddealer', 'disputeDismissed', [
						'name' => $dealerMember->name,
					], \IPS\Email::TYPE_TRANSACTIONAL )->send( $dealerMember );
				}
			}
			catch ( \Exception ) {}

			try
			{
				if ( $dealerMember && $dealerMember->member_id )
				{
					$notification = new \IPS\Notification(
						\IPS\Application::load( 'gddealer' ),
						'dispute_dismissed',
						$dealerMember,
						[ $dealerMember ],
						[
							'dealer_name' => $dealerName,
						]
					);
					$notification->recipients->attach( $dealerMember );
					$notification->send();
				}
			}
			catch ( \Exception ) {}
		}

		/* Side-effects to the reviewer — email and PM in independent
		   try/catch blocks. */
		if ( $review && (int) ( $review['member_id'] ?? 0 ) > 0 )
		{
			$reviewerMember = NULL;
			try { $reviewerMember = \IPS\Member::load( (int) $review['member_id'] ); } catch ( \Exception ) {}

			$outcomeText = 'The dealer contested your review but after reviewing the evidence, admin has decided the review stands as-is.';

			try
			{
				if ( $reviewerMember && $reviewerMember->member_id )
				{
					\IPS\Email::buildFromTemplate( 'gddealer', 'disputeOutcome', [
						'name'        => $reviewerMember->name,
						'dealer_name' => $dealerName,
						'outcome'     => $outcomeText,
					], \IPS\Email::TYPE_TRANSACTIONAL )->send( $reviewerMember );
				}
			}
			catch ( \Exception ) {}

			try
			{
				if ( $reviewerMember && $reviewerMember->member_id )
				{
					$notification = new \IPS\Notification(
						\IPS\Application::load( 'gddealer' ),
						'dispute_outcome_reviewer',
						$reviewerMember,
						[ $reviewerMember ],
						[
							'dealer_name' => $dealerName,
							'outcome'     => 'dismissed',
							'review_id'   => (int) $id,
						]
					);
					$notification->recipients->attach( $reviewerMember );
					$notification->send();
				}
			}
			catch ( \Exception ) {}

			try
			{
				if ( $reviewerMember && $reviewerMember->member_id )
				{
					$sender = \IPS\Member::loggedIn();
					if ( \IPS\core\Messenger\Conversation::memberCanReceiveNewMessage( $reviewerMember, $sender ) )
					{
						$conversation = \IPS\core\Messenger\Conversation::createItem( $sender, \IPS\Request::i()->ipAddress(), \IPS\DateTime::create() );
						$conversation->title    = 'GunRack.deals — Review Dispute Resolved';
						$conversation->to_count = 1;
						$conversation->save();

						$commentClass = $conversation::$commentClass;
						$post = $commentClass::create(
							$conversation,
							'Your review on ' . $dealerName . ' was contested. ' . $outcomeText,
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
			\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers&do=disputes' ),
			'gddealer_dispute_dismissed'
		);
	}

	/**
	 * Send the dispute back to the customer with an admin note asking
	 * them to update the review. Resets dispute_status to
	 * pending_customer and extends the deadline by 30 days.
	 */
	protected function requestEdit()
	{
		\IPS\Session::i()->csrfCheck();
		$id       = (int) \IPS\Request::i()->id;
		$adminNote = trim( (string) ( \IPS\Request::i()->admin_note ?? '' ) );

		try
		{
			$row = \IPS\Db::i()->select( '*', 'gd_dealer_ratings',
				[ 'id=? AND ' . \IPS\Db::i()->in( 'dispute_status', [ 'pending_admin', 'pending_customer' ] ), $id ]
			)->first();
		}
		catch ( \Exception )
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers&do=disputes' )
			);
			return;
		}

		$deadline = date( 'Y-m-d H:i:s', strtotime( '+30 days' ) );

		try
		{
			\IPS\Db::i()->update( 'gd_dealer_ratings', [
				'dispute_status'   => 'pending_customer',
				'dispute_deadline' => $deadline,
			], [ 'id=?', $id ] );
		}
		catch ( \Exception ) {}

		/* Dispute-history log entry — admin requested an update. */
		EventLogger::log( $id, 'admin_edit_requested', 'admin', (int) \IPS\Member::loggedIn()->member_id, $adminNote !== '' ? $adminNote : NULL );

		if ( (int) ( $row['member_id'] ?? 0 ) > 0 )
		{
			$customer = NULL;
			try { $customer = \IPS\Member::load( (int) $row['member_id'] ); } catch ( \Exception ) {}

			$slug = '';
			try
			{
				$slug = (string) \IPS\Db::i()->select( 'dealer_slug', 'gd_dealer_feed_config', [ 'dealer_id=?', (int) $row['dealer_id'] ] )->first();
			}
			catch ( \Exception ) {}

			$dealerName = '';
			try
			{
				$dealerName = (string) \IPS\Db::i()->select( 'dealer_name', 'gd_dealer_feed_config', [ 'dealer_id=?', (int) $row['dealer_id'] ] )->first();
			}
			catch ( \Exception ) {}

			$respondUrl = $slug !== ''
				? (string) \IPS\Http\Url::internal(
					'app=gddealer&module=dealers&controller=profile&dealer_slug=' . urlencode( $slug ) . '&dispute=' . $id,
					'front', 'dealers_profile', $slug
				)
				: (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=reviews' );

			/* Email to customer — independent try/catch. */
			try
			{
				if ( $customer && $customer->member_id )
				{
					\IPS\Email::buildFromTemplate( 'gddealer', 'disputeEditRequested', [
						'name'        => (string) $customer->name,
						'dealer_name' => $dealerName,
						'admin_note'  => $adminNote,
						'deadline'    => date( 'F j, Y', strtotime( $deadline ) ),
						'respond_url' => $respondUrl,
					], \IPS\Email::TYPE_TRANSACTIONAL )->send( $customer );
				}
			}
			catch ( \Exception ) {}

			/* Bell notification — own try/catch, independent of email. */
			try
			{
				if ( $customer && $customer->member_id )
				{
					$notification = new \IPS\Notification(
						\IPS\Application::load( 'gddealer' ),
						'dispute_edit_requested',
						$customer,
						[ $customer ],
						[
							'dealer_name' => $dealerName,
							'dealer_slug' => $slug,
							'dispute_id'  => (int) $id,
							'admin_note'  => $adminNote,
						]
					);
					$notification->recipients->attach( $customer );
					$notification->send();
				}
			}
			catch ( \Exception ) {}

			/* Private message to customer — own try/catch. Mirrors dispute() PM. */
			try
			{
				$sender = \IPS\Member::loggedIn();
				if ( $customer && $customer->member_id && \IPS\core\Messenger\Conversation::memberCanReceiveNewMessage( $customer, $sender ) )
				{
					$bodyText = "An admin has reviewed your response to the dispute on " . $dealerName . " and asked for an update.\n\n";
					if ( $adminNote !== '' )
					{
						$bodyText .= "Admin's note: " . $adminNote . "\n\n";
					}
					$bodyText .= "You have until " . date( 'F j, Y', strtotime( $deadline ) ) . " to update your response.\n\n";
					$bodyText .= "Visit: " . $respondUrl;

					$conversation = \IPS\core\Messenger\Conversation::createItem( $sender, \IPS\Request::i()->ipAddress(), \IPS\DateTime::create() );
					$conversation->title    = 'Update requested on your dispute response — ' . $dealerName;
					$conversation->to_count = 1;
					$conversation->save();

					$commentClass = $conversation::$commentClass;
					$post = $commentClass::create(
						$conversation,
						$bodyText,
						TRUE, NULL, NULL, $sender, \IPS\DateTime::create()
					);

					$conversation->first_msg_id = $post->id;
					$conversation->save();
					$conversation->authorize( [ $sender->member_id, $customer->member_id ] );
					$post->sendNotifications();
				}
			}
			catch ( \Exception ) {}
		}

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers&do=disputes' ),
			'gddealer_dispute_edit_requested'
		);
	}

	/**
	 * Force a feed import immediately for a dealer.
	 */
	protected function forceImport()
	{
		\IPS\Session::i()->csrfCheck();

		$dealer = Dealer::load( (int) \IPS\Request::i()->id );
		$log    = Importer::run( $dealer );

		$msg = $log->status === 'completed'
			? "Import complete — {$log->records_created} new, {$log->records_updated} updated, {$log->records_unmatched} unmatched"
			: 'Import failed: ' . ( $log->error_log ?? 'unknown error' );

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers&do=view&id=' . (int) $dealer->dealer_id ),
			$msg
		);
	}

	/**
	 * Delete a single review. Redirects back to the dealer detail page
	 * on success; to the dealer list on lookup failure.
	 */
	protected function deleteReview(): void
	{
		\IPS\Session::i()->csrfCheck();
		$id = (int) ( \IPS\Request::i()->id ?? 0 );

		try
		{
			$review = \IPS\Db::i()->select( '*', 'gd_dealer_ratings', [ 'id=?', $id ] )->first();
			\IPS\Db::i()->delete( 'gd_dealer_ratings', [ 'id=?', $id ] );
			$msg      = 'gddealer_review_deleted';
			$redirect = \IPS\Http\Url::internal(
				'app=gddealer&module=dealers&controller=dealers&do=view&id=' . (int) $review['dealer_id']
			);
		}
		catch ( \Exception )
		{
			$msg      = 'gddealer_action_failed';
			$redirect = \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers' );
		}

		\IPS\Output::i()->redirect( $redirect, $msg );
	}

	/**
	 * Standalone ACP page listing ALL reviews across every dealer with
	 * filters for status and dealer. Admins can delete any review from
	 * here without navigating to the dealer detail page.
	 */
	protected function reviews()
	{
		$filterStatus = (string) ( \IPS\Request::i()->status ?? '' );
		$filterDealer = (int) ( \IPS\Request::i()->dealer_id ?? 0 );

		$where = [];
		if ( $filterStatus !== '' && in_array( $filterStatus, [ 'none', 'pending_customer', 'pending_admin', 'resolved_dealer', 'dismissed' ], TRUE ) )
		{
			$where[] = [ 'dispute_status=?', $filterStatus ];
		}
		if ( $filterDealer > 0 )
		{
			$where[] = [ 'dealer_id=?', $filterDealer ];
		}

		$dealerOptions = [ 0 => 'All dealers' ];
		try
		{
			foreach ( \IPS\Db::i()->select( 'dealer_id, dealer_name', 'gd_dealer_feed_config', null, 'dealer_name ASC' ) as $d )
			{
				$dealerOptions[ (int) $d['dealer_id'] ] = (string) $d['dealer_name'];
			}
		}
		catch ( \Exception ) {}

		$rows = [];
		try
		{
			$q = \IPS\Db::i()->select( '*', 'gd_dealer_ratings',
				$where ?: null,
				'created_at DESC',
				[ 0, 200 ]
			);
			foreach ( $q as $r )
			{
				$dealerName = '';
				try
				{
					$dealerName = (string) \IPS\Db::i()->select( 'dealer_name', 'gd_dealer_feed_config', [ 'dealer_id=?', (int) $r['dealer_id'] ] )->first();
				}
				catch ( \Exception ) {}

				$memberName = '';
				try
				{
					$m = \IPS\Member::load( (int) $r['member_id'] );
					if ( $m->member_id ) { $memberName = (string) $m->name; }
				}
				catch ( \Exception ) {}

				$rows[] = [
					'id'              => (int) $r['id'],
					'dealer_id'       => (int) $r['dealer_id'],
					'dealer_name'     => $dealerName,
					'member_name'     => $memberName,
					'rating_pricing'  => (int) $r['rating_pricing'],
					'rating_shipping' => (int) $r['rating_shipping'],
					'rating_service'  => (int) $r['rating_service'],
					'review_body'     => ( $r['review_body'] ?? '' ) !== '' ? \IPS\Text\Parser::parseStatic( (string) $r['review_body'], [ (int) $r['id'], 1 ], NULL, 'gddealer_Responses' ) : '',
					'dispute_status'  => (string) ( $r['dispute_status'] ?? 'none' ),
					'created_at'      => (string) $r['created_at'],
					'delete_url'      => (string) \IPS\Http\Url::internal(
						'app=gddealer&module=dealers&controller=dealers&do=deleteReview&id=' . (int) $r['id']
					)->csrf(),
				];
			}
		}
		catch ( \Exception ) {}

		$formUrl = (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers&do=reviews' );

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gddealer_all_reviews_title' );
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'admin' )->allReviews(
			$rows, $dealerOptions, $filterStatus, $filterDealer, $formUrl
		);
	}
	/* =============== Dispute Count Management =============== */

	protected static array $disputeLimits = [
		'basic'      => 2,
		'pro'        => 5,
		'founding'   => 5,
		'enterprise' => PHP_INT_MAX,
	];

	protected function disputeCounts()
	{
		$monthKey = date( 'Y-m' );
		$rows = [];

		try
		{
			foreach ( \IPS\Db::i()->select( '*', 'gd_dealer_feed_config', NULL, 'dealer_name ASC' ) as $d )
			{
				$dealerId = (int) $d['dealer_id'];
				$tier     = (string) ( $d['subscription_tier'] ?? 'basic' );
				$limit    = self::$disputeLimits[ $tier ] ?? 2;
				$used     = 0;
				$bonus    = 0;

				try
				{
					$row = \IPS\Db::i()->select( 'count, bonus', 'gd_dealer_dispute_counts',
						[ 'dealer_id=? AND month_key=?', $dealerId, $monthKey ]
					)->first();
					$used  = (int) $row['count'];
					$bonus = (int) ( $row['bonus'] ?? 0 );
				}
				catch ( \Exception ) {}

				$effectiveLimit = $limit === PHP_INT_MAX ? -1 : $limit + $bonus;
				$remaining      = $effectiveLimit === -1 ? -1 : max( 0, $effectiveLimit - $used );

				$rows[] = [
					'dealer_id'   => $dealerId,
					'dealer_name' => (string) $d['dealer_name'],
					'tier'        => $tier,
					'limit'       => $limit === PHP_INT_MAX ? -1 : $limit,
					'bonus'       => $bonus,
					'used'        => $used,
					'remaining'   => $remaining,
					'unlimited'   => $effectiveLimit === -1,
					'reset_url'   => (string) \IPS\Http\Url::internal(
						'app=gddealer&module=dealers&controller=dealers&do=resetDisputeCount&id=' . $dealerId
					)->csrf(),
					'grant1_url'  => (string) \IPS\Http\Url::internal(
						'app=gddealer&module=dealers&controller=dealers&do=grantDisputes&id=' . $dealerId . '&amount=1'
					)->csrf(),
					'grant5_url'  => (string) \IPS\Http\Url::internal(
						'app=gddealer&module=dealers&controller=dealers&do=grantDisputes&id=' . $dealerId . '&amount=5'
					)->csrf(),
				];
			}
		}
		catch ( \Exception ) {}

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gddealer_dispute_counts_title' );
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'admin' )->disputeCounts( $rows, $monthKey );
	}

	protected function resetDisputeCount()
	{
		\IPS\Session::i()->csrfCheck();

		$dealerId = (int) ( \IPS\Request::i()->id ?? 0 );
		$monthKey = date( 'Y-m' );

		if ( $dealerId > 0 )
		{
			try
			{
				\IPS\Db::i()->update( 'gd_dealer_dispute_counts',
					[ 'count' => 0 ],
					[ 'dealer_id=? AND month_key=?', $dealerId, $monthKey ]
				);
			}
			catch ( \Exception ) {}
		}

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers&do=disputeCounts' ),
			'gddealer_dispute_count_reset'
		);
	}

	protected function grantDisputes()
	{
		\IPS\Session::i()->csrfCheck();

		$dealerId = (int) ( \IPS\Request::i()->id ?? 0 );
		$amount   = max( 1, min( 100, (int) ( \IPS\Request::i()->amount ?? 1 ) ) );
		$monthKey = date( 'Y-m' );

		if ( $dealerId > 0 )
		{
			try
			{
				$exists = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_dealer_dispute_counts',
					[ 'dealer_id=? AND month_key=?', $dealerId, $monthKey ]
				)->first();

				if ( $exists > 0 )
				{
					\IPS\Db::i()->update( 'gd_dealer_dispute_counts',
						'bonus = bonus + ' . $amount,
						[ 'dealer_id=? AND month_key=?', $dealerId, $monthKey ]
					);
				}
				else
				{
					\IPS\Db::i()->insert( 'gd_dealer_dispute_counts', [
						'dealer_id' => $dealerId,
						'month_key' => $monthKey,
						'count'     => 0,
						'bonus'     => $amount,
					] );
				}
			}
			catch ( \Exception ) {}
		}

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers&do=disputeCounts' ),
			'gddealer_disputes_granted'
		);
	}
	/**
	 * AdminCP FFL verifications queue. Filterable by status
	 * (pending/verified/rejected/all). Default: pending.
	 */
	protected function fflVerifications()
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'dealer_manage' );

		$filter = (string) ( \IPS\Request::i()->filter ?? 'pending' );
		if ( !in_array( $filter, [ 'pending', 'verified', 'rejected', 'all' ], TRUE ) )
		{
			$filter = 'pending';
		}

		/* Build the WHERE clause per filter. */
		$whereByFilter = [
			'pending'  => [ 'ffl_submitted_at IS NOT NULL AND ffl_verified_at IS NULL AND ffl_rejection_reason IS NULL AND ffl_rejection_count < ?', 3 ],
			'verified' => [ 'ffl_verified_at IS NOT NULL' ],
			'rejected' => [ 'ffl_rejection_reason IS NOT NULL AND ffl_verified_at IS NULL' ],
			'all'      => [ 'ffl_submitted_at IS NOT NULL' ],
		];
		$where = $whereByFilter[ $filter ];

		/* Precompute counts for the filter tab badges. */
		$counts = [ 'pending' => 0, 'verified' => 0, 'rejected' => 0, 'all' => 0 ];
		foreach ( $whereByFilter as $k => $w )
		{
			try { $counts[ $k ] = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_dealer_feed_config', $w )->first(); }
			catch ( \Exception ) {}
		}

		$rows = [];
		try
		{
			foreach ( \IPS\Db::i()->select( '*', 'gd_dealer_feed_config',
				$where,
				'ffl_submitted_at DESC',
				[ 0, 200 ]
			) as $r )
			{
				$rows[] = [
					'dealer_id'            => (int) $r['dealer_id'],
					'dealer_name'          => (string) $r['dealer_name'],
					'dealer_slug'          => (string) $r['dealer_slug'],
					'ffl_number'           => (string) ( $r['ffl_number'] ?? '' ),
					'ffl_license_url'      => (string) ( $r['ffl_license_url'] ?? '' ),
					'ffl_submitted_at'     => (int) ( $r['ffl_submitted_at'] ?? 0 ),
					'ffl_submitted_label'  => !empty( $r['ffl_submitted_at'] ) ? \IPS\DateTime::ts( (int) $r['ffl_submitted_at'] )->relative() : '',
					'ffl_verified_at'      => (int) ( $r['ffl_verified_at'] ?? 0 ),
					'ffl_verified_label'   => !empty( $r['ffl_verified_at'] ) ? \IPS\DateTime::ts( (int) $r['ffl_verified_at'] )->relative() : '',
					'ffl_rejection_reason' => (string) ( $r['ffl_rejection_reason'] ?? '' ),
					'ffl_rejection_count'  => (int) ( $r['ffl_rejection_count'] ?? 0 ),
					'status'               => !empty( $r['ffl_verified_at'] ) ? 'verified' : ( !empty( $r['ffl_rejection_reason'] ) ? 'rejected' : 'pending' ),
					'verify_url'           => (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers&do=fflVerify&dealer_id=' . (int) $r['dealer_id'] )->csrf(),
					'reject_url'           => (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers&do=fflReject&dealer_id=' . (int) $r['dealer_id'] ),
					'reset_url'            => (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers&do=fflResetAttempts&dealer_id=' . (int) $r['dealer_id'] )->csrf(),
				];
			}
		}
		catch ( \Exception ) {}

		$filterUrls = [];
		foreach ( array_keys( $counts ) as $f )
		{
			$filterUrls[ $f ] = (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers&do=fflVerifications&filter=' . $f );
		}

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gddealer_acp_ffl_title' );
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'admin' )->fflVerifications( [
			'filter'      => $filter,
			'counts'      => $counts,
			'filter_urls' => $filterUrls,
			'rows'        => $rows,
		] );
	}

	/**
	 * AdminCP action: mark a dealer's FFL submission as verified.
	 * Only reachable by admins with dealer_manage restriction.
	 */
	protected function fflVerify()
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'dealer_manage' );
		\IPS\Session::i()->csrfCheck();

		$dealerId = (int) ( \IPS\Request::i()->dealer_id ?? 0 );
		if ( $dealerId <= 0 )
		{
			\IPS\Output::i()->error( 'Invalid dealer_id', '2S301/F', 400 );
			return;
		}

		try
		{
			$dealer = \IPS\Db::i()->select( '*', 'gd_dealer_feed_config', [ 'dealer_id=?', $dealerId ] )->first();
		}
		catch ( \Exception )
		{
			\IPS\Output::i()->error( 'Dealer not found', '2S301/F', 404 );
			return;
		}

		if ( empty( $dealer['ffl_submitted_at'] ) )
		{
			\IPS\Output::i()->error( 'This dealer has not submitted an FFL for review.', '2S301/F', 400 );
			return;
		}

		\IPS\Db::i()->update( 'gd_dealer_feed_config',
			[
				'ffl_verified_at'      => time(),
				'ffl_verified_by'      => (int) \IPS\Member::loggedIn()->member_id,
				'ffl_rejection_reason' => null,
			],
			[ 'dealer_id=?', $dealerId ]
		);

		/* Email the dealer that their FFL was verified. */
		try
		{
			$dealerMember = \IPS\Member::load( (int) $dealer['dealer_id'] );
			if ( $dealerMember->member_id )
			{
				$profileUrl = (string) \IPS\Http\Url::internal(
					'app=gddealer&module=dealers&controller=profile&dealer_slug=' . urlencode( (string) $dealer['dealer_slug'] ),
					'front', 'dealers_profile', (string) $dealer['dealer_slug']
				);
				\IPS\Email::buildFromTemplate( 'gddealer', 'gddealer_ffl_verified', [
					'name'        => $dealerMember->name,
					'ffl_number'  => (string) ( $dealer['ffl_number'] ?? '' ),
					'verified_on' => \IPS\DateTime::ts( time() )->localeDate(),
					'profile_url' => $profileUrl,
				], \IPS\Email::TYPE_TRANSACTIONAL )->send( $dealerMember );
			}
		}
		catch ( \Throwable ) {}

		/* Bell notification — independent try/catch per Rule 25. */
		try
		{
			if ( !isset( $dealerMember ) || !$dealerMember->member_id )
			{
				$dealerMember = \IPS\Member::load( (int) $dealer['dealer_id'] );
			}
			if ( $dealerMember->member_id )
			{
				$notification = new \IPS\Notification( \IPS\Application::load( 'gddealer' ), 'gddealer_ffl_verified', NULL, [ $dealerMember ] );
				$notification->recipients->attach( $dealerMember );
				$notification->send();
			}
		}
		catch ( \Throwable ) {}

		\IPS\Session::i()->log( 'acplog__gddealer_ffl_verified', [ $dealer['dealer_name'] => FALSE ] );

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers&do=fflVerifications' ),
			\IPS\Member::loggedIn()->language()->addToStack( 'gddealer_acp_ffl_verified_flash', FALSE, [ 'sprintf' => [ $dealer['dealer_name'] ] ] )
		);
	}

	/**
	 * AdminCP action: reject a dealer's FFL submission with a reason.
	 * Two-phase: GET renders a reason picker; POST performs the rejection.
	 */
	protected function fflReject()
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'dealer_manage' );

		$dealerId = (int) ( \IPS\Request::i()->dealer_id ?? 0 );
		if ( $dealerId <= 0 )
		{
			\IPS\Output::i()->error( 'Invalid dealer_id', '2S302/F', 400 );
			return;
		}

		try
		{
			$dealer = \IPS\Db::i()->select( '*', 'gd_dealer_feed_config', [ 'dealer_id=?', $dealerId ] )->first();
		}
		catch ( \Exception )
		{
			\IPS\Output::i()->error( 'Dealer not found', '2S302/F', 404 );
			return;
		}

		if ( empty( $dealer['ffl_submitted_at'] ) )
		{
			\IPS\Output::i()->error( 'This dealer has not submitted an FFL for review.', '2S302/F', 400 );
			return;
		}

		/* POST: perform the rejection. */
		if ( \IPS\Request::i()->requestMethod() === 'POST' )
		{
			\IPS\Session::i()->csrfCheck();

			$reasonKey   = (string) ( \IPS\Request::i()->reason_key ?? '' );
			$otherReason = trim( (string) ( \IPS\Request::i()->reason_other ?? '' ) );

			$lang = \IPS\Member::loggedIn()->language();

			$fallbacks = [
				'illegible' => 'License document is illegible',
				'expired'   => 'License has expired',
				'mismatch'  => 'Name or address does not match the submitted FFL number',
				'other'     => 'Other (see admin notes)',
			];

			$resolveReason = function( string $key ) use ( $lang, $fallbacks ): string
			{
				try
				{
					$resolved = $lang->get( 'gddealer_ffl_rejection_' . $key );
					if ( !empty( $resolved ) && stripos( $resolved, 'gddealer_ffl_rejection_' ) === false )
					{
						return $resolved;
					}
				}
				catch ( \Throwable ) {}
				return $fallbacks[ $key ] ?? '';
			};

			$reasonText = match( $reasonKey )
			{
				'illegible' => $resolveReason( 'illegible' ),
				'expired'   => $resolveReason( 'expired' ),
				'mismatch'  => $resolveReason( 'mismatch' ),
				'other'     => $otherReason !== '' ? $otherReason : $resolveReason( 'other' ),
				default     => '',
			};

			if ( preg_match( '/^[0-9a-f]{32,40}$/i', trim( $reasonText ) ) )
			{
				$reasonText = $fallbacks[ $reasonKey ] ?? 'Submission rejected (no reason recorded)';
			}
			$reasonText = substr( $reasonText, 0, 500 );

			if ( $reasonText === '' )
			{
				\IPS\Output::i()->error( 'Select a rejection reason.', '2S302/F', 400 );
				return;
			}

			$newRejectionCount = (int) ( $dealer['ffl_rejection_count'] ?? 0 ) + 1;

			\IPS\Db::i()->update( 'gd_dealer_feed_config',
				[
					'ffl_rejection_reason' => $reasonText,
					'ffl_rejection_count'  => $newRejectionCount,
					'ffl_verified_at'      => null,
					'ffl_verified_by'      => null,
				],
				[ 'dealer_id=?', $dealerId ]
			);

			/* Email the dealer that their FFL was rejected with the reason. */
			try
			{
				$dealerMember = \IPS\Member::load( (int) $dealer['dealer_id'] );
				if ( $dealerMember->member_id )
				{
					$customizeUrl = (string) \IPS\Http\Url::internal(
						'app=gddealer&module=dealers&controller=dashboard&do=customize'
					);
					\IPS\Email::buildFromTemplate( 'gddealer', 'gddealer_ffl_rejected', [
						'name'            => $dealerMember->name,
						'reason'          => $reasonText,
						'rejection_count' => $newRejectionCount,
						'customize_url'   => $customizeUrl,
					], \IPS\Email::TYPE_TRANSACTIONAL )->send( $dealerMember );
				}
			}
			catch ( \Throwable ) {}

			/* Bell notification — independent try/catch per Rule 25. */
			try
			{
				if ( !isset( $dealerMember ) || !$dealerMember->member_id )
				{
					$dealerMember = \IPS\Member::load( (int) $dealer['dealer_id'] );
				}
				if ( $dealerMember->member_id )
				{
					$notification = new \IPS\Notification( \IPS\Application::load( 'gddealer' ), 'gddealer_ffl_rejected', NULL, [ $dealerMember ], [ 'reason' => $reasonText ] );
					$notification->recipients->attach( $dealerMember );
					$notification->send();
				}
			}
			catch ( \Throwable ) {}

			\IPS\Session::i()->log( 'acplog__gddealer_ffl_rejected', [ $dealer['dealer_name'] => FALSE ] );

			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers&do=fflVerifications' ),
				\IPS\Member::loggedIn()->language()->addToStack( 'gddealer_acp_ffl_rejected_flash', FALSE, [ 'sprintf' => [ $dealer['dealer_name'] ] ] )
			);
			return;
		}

		/* GET: render the rejection form inline. */
		\IPS\Output::i()->title  = 'Reject FFL — ' . (string) $dealer['dealer_name'];
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'admin' )->fflRejectForm( [
			'dealer'      => $dealer,
			'post_url'    => (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers&do=fflReject&dealer_id=' . $dealerId )->csrf(),
			'cancel_url'  => (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers&do=fflVerifications' ),
		] );
	}

	protected function fflResetAttempts(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'dealer_manage' );
		\IPS\Session::i()->csrfCheck();

		$dealerId = (int) ( \IPS\Request::i()->dealer_id ?? 0 );
		if ( $dealerId <= 0 )
		{
			\IPS\Output::i()->error( 'Invalid dealer_id', '2S303/F', 400 );
			return;
		}

		try
		{
			$dealer = \IPS\Db::i()->select( '*', 'gd_dealer_feed_config', [ 'dealer_id=?', $dealerId ] )->first();
		}
		catch ( \Exception )
		{
			\IPS\Output::i()->error( 'Dealer not found', '2S303/F', 404 );
			return;
		}

		\IPS\Db::i()->update( 'gd_dealer_feed_config',
			[
				'ffl_rejection_count'  => 0,
				'ffl_rejection_reason' => null,
			],
			[ 'dealer_id=?', $dealerId ]
		);

		\IPS\Session::i()->log( 'acplog__gddealer_ffl_attempts_reset', [ $dealer['dealer_name'] => FALSE ] );

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers&do=fflVerifications' ),
			'Rejection attempts reset for ' . (string) $dealer['dealer_name'] . '. They can now re-submit.'
		);
	}
	protected function permanentDelete(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'dealer_manage' );
		\IPS\Session::i()->csrfCheck();

		$dealerId = (int) ( \IPS\Request::i()->id ?? 0 );
		if ( $dealerId <= 0 )
		{
			\IPS\Output::i()->error( 'Invalid dealer ID', '2GDD235/1', 400 );
			return;
		}

		try
		{
			$dealer = \IPS\Db::i()->select( '*', 'gd_dealer_feed_config', [ 'dealer_id=?', $dealerId ] )->first();
		}
		catch ( \Throwable )
		{
			\IPS\Output::i()->error( 'Dealer not found', '2GDD235/2', 404 );
			return;
		}

		$dealerName = (string) $dealer['dealer_name'];

		try
		{
			\IPS\Db::i()->preparedQuery( 'START TRANSACTION', [] );

			\IPS\Db::i()->preparedQuery(
				'DELETE r FROM `' . \IPS\Db::i()->prefix . 'gd_dealer_support_replies` r'
				. ' INNER JOIN `' . \IPS\Db::i()->prefix . 'gd_dealer_support_tickets` t'
				. ' ON r.ticket_id = t.id'
				. ' WHERE t.dealer_id = ?',
				[ $dealerId ]
			);

			foreach ( self::DEALER_ID_TABLES as $table )
			{
				\IPS\Db::i()->delete( $table, [ 'dealer_id=?', $dealerId ] );
			}

			\IPS\Db::i()->delete( 'gd_dealer_feed_config', [ 'dealer_id=?', $dealerId ] );

			\IPS\Db::i()->preparedQuery( 'COMMIT', [] );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Db::i()->preparedQuery( 'ROLLBACK', [] ); } catch ( \Throwable ) {}
			\IPS\Output::i()->error( 'Delete failed: ' . $e->getMessage(), '2GDD235/3', 500 );
			return;
		}

		try
		{
			$member = \IPS\Member::load( $dealerId );
			if ( $member->member_id )
			{
				$this->removeDealersGroup( $member );
			}
		}
		catch ( \Throwable ) {}

		\IPS\Session::i()->log( 'acplog__gddealer_permanent_delete', [ $dealerName => FALSE ] );

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers' ),
			'gddealer_dealer_permanently_deleted'
		);
	}

	protected function transferOwnership(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'dealer_manage' );

		$oldDealerId = (int) ( \IPS\Request::i()->id ?? 0 );
		if ( $oldDealerId <= 0 )
		{
			\IPS\Output::i()->error( 'Invalid dealer ID', '2GDD235/4', 400 );
			return;
		}

		try
		{
			$dealer = \IPS\Db::i()->select( '*', 'gd_dealer_feed_config', [ 'dealer_id=?', $oldDealerId ] )->first();
		}
		catch ( \Throwable )
		{
			\IPS\Output::i()->error( 'Dealer not found', '2GDD235/5', 404 );
			return;
		}

		$dealerName = (string) $dealer['dealer_name'];
		$dealerTier = (string) ( $dealer['subscription_tier'] ?? 'basic' );

		$form = new \IPS\Helpers\Form;
		$form->add( new \IPS\Helpers\Form\Member( 'gddealer_transfer_target', NULL, TRUE, [], NULL, NULL, NULL, 'gddealer_transfer_target' ) );

		if ( $values = $form->values() )
		{
			$newMember = $values['gddealer_transfer_target'];
			$newDealerId = (int) $newMember->member_id;

			if ( $newDealerId === $oldDealerId )
			{
				\IPS\Output::i()->error( 'gddealer_transfer_same_member', '2GDD235/6', 400 );
				return;
			}

			$existing = 0;
			try
			{
				$existing = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_dealer_feed_config', [ 'dealer_id=?', $newDealerId ] )->first();
			}
			catch ( \Throwable ) {}

			if ( $existing > 0 )
			{
				\IPS\Output::i()->error( 'gddealer_transfer_collision', '2GDD235/7', 400 );
				return;
			}

			try
			{
				\IPS\Db::i()->preparedQuery( 'START TRANSACTION', [] );

				\IPS\Db::i()->update( 'gd_dealer_feed_config', [ 'dealer_id' => $newDealerId ], [ 'dealer_id=?', $oldDealerId ] );

				foreach ( self::DEALER_ID_TABLES as $table )
				{
					\IPS\Db::i()->update( $table, [ 'dealer_id' => $newDealerId ], [ 'dealer_id=?', $oldDealerId ] );
				}

				\IPS\Db::i()->preparedQuery( 'COMMIT', [] );
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Db::i()->preparedQuery( 'ROLLBACK', [] ); } catch ( \Throwable ) {}
				\IPS\Output::i()->error( 'Transfer failed: ' . $e->getMessage(), '2GDD235/8', 500 );
				return;
			}

			try
			{
				$oldMember = \IPS\Member::load( $oldDealerId );
				if ( $oldMember->member_id )
				{
					$this->removeDealersGroup( $oldMember );
				}
			}
			catch ( \Throwable ) {}

			$this->assignDealersGroup( $newMember, $dealerTier );

			\IPS\Session::i()->log( 'acplog__gddealer_transfer_ownership', [ $dealerName => FALSE ] );

			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers&do=view&id=' . $newDealerId ),
				'gddealer_transfer_complete'
			);
			return;
		}

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gddealer_transfer_ownership_title' ) . ' — ' . $dealerName;
		\IPS\Output::i()->output = $form;
	}

	protected function clearFailedLogs(): void
	{
		\IPS\Session::i()->csrfCheck();
		\IPS\Dispatcher::i()->checkAcpPermission( 'gddealer_dealer_manage' );

		$dealerId = (int) \IPS\Request::i()->dealer_id;

		try {
			$deleted = \IPS\Db::i()->delete(
				'gd_dealer_import_log',
				[ 'dealer_id=? AND status=?', $dealerId, 'failed' ]
			);
		} catch ( \Throwable ) { $deleted = 0; }

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers&do=view&id=' . $dealerId ),
			\IPS\Member::loggedIn()->language()->addToStack( 'gddealer_logs_cleared', FALSE, [ 'sprintf' => [ $deleted ] ] )
		);
	}

	protected function resetFeed(): void
	{
		\IPS\Session::i()->csrfCheck();
		\IPS\Dispatcher::i()->checkAcpPermission( 'gddealer_dealer_manage' );

		$dealerId = (int) \IPS\Request::i()->dealer_id;

		try {
			\IPS\Db::i()->update( 'gd_dealer_feed_config', [
				'feed_url'            => null,
				'feed_format'         => null,
				'feed_auth_type'      => null,
				'feed_auth_config'    => null,
				'field_mapping'       => null,
				'last_run'            => null,
				'last_run_status'     => null,
				'last_record_count'   => 0,
			], [ 'dealer_id=?', $dealerId ] );
		} catch ( \Throwable ) {}

		try {
			\IPS\Db::i()->delete( 'gd_dealer_listings', [ 'dealer_id=?', $dealerId ] );
		} catch ( \Throwable ) {}

		try {
			\IPS\Db::i()->delete( 'gd_dealer_import_log', [ 'dealer_id=?', $dealerId ] );
		} catch ( \Throwable ) {}

		try {
			\IPS\Db::i()->delete( 'gd_unmatched_upcs', [ 'dealer_id=?', $dealerId ] );
		} catch ( \Throwable ) {}

		try {
			\IPS\Db::i()->delete( 'gd_dealer_data_flags', [ 'dealer_id=?', $dealerId ] );
		} catch ( \Throwable ) {}

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers&do=view&id=' . $dealerId ),
			'gddealer_feed_reset_done'
		);
	}
}

class dealers extends _dealers {}
