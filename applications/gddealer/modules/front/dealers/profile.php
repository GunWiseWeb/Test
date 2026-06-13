<?php
/**
 * @brief       GD Dealer Manager — Public Dealer Profile Page
 * @package     IPS Community Suite
 * @subpackage  GD Dealer Manager
 * @since       16 Apr 2026
 *
 * Public-facing dealer profile at /dealers/{slug}. Renders the dealer
 * header, aggregated ratings, all approved reviews, and — for logged-in
 * members who have not yet rated this dealer — an inline rating form.
 */

namespace IPS\gddealer\modules\front\dealers;

use IPS\gddealer\Attachment\Helper as AttachHelper;
use IPS\gddealer\Dispute\EventLogger;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _profile extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		parent::execute();
	}

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

	/**
	 * Admin-configurable theme variables. Mirrors the dashboard
	 * controller's themeVars() so the public dealer profile picks up
	 * the same site-wide palette.
	 */
	protected function themeVars(): string
	{
		$s = \IPS\Settings::i();
		return '<style>
:root {
	--gd-primary:            ' . ( $s->gddealer_color_primary ?: '#2563eb' ) . ';
	--gd-primary-text:       ' . ( $s->gddealer_color_primary_text ?: '#ffffff' ) . ';
	--gd-tab-active-bg:      ' . ( $s->gddealer_color_active_tab_bg ?: '#1e3a5f' ) . ';
	--gd-tab-active-text:    ' . ( $s->gddealer_color_active_tab_text ?: '#ffffff' ) . ';
	--gd-tab-inactive-text:  ' . ( $s->gddealer_color_inactive_tab_text ?: '#374151' ) . ';
	--gd-accent:             ' . ( $s->gddealer_color_accent ?: '#16a34a' ) . ';
	--gd-warning:            ' . ( $s->gddealer_color_warning ?: '#d97706' ) . ';
	--gd-danger:             ' . ( $s->gddealer_color_danger ?: '#dc2626' ) . ';
	--gd-header-bg:          ' . ( $s->gddealer_color_header_bg ?: '#1e3a5f' ) . ';
	--gd-card-bg:            ' . ( $s->gddealer_color_card_bg ?: '#ffffff' ) . ';
	--gd-card-border:        ' . ( $s->gddealer_color_card_border ?: '#e0e0e0' ) . ';
}
.gdDealerTabs .ipsTabs__tab[aria-selected="true"] {
	background: var(--gd-tab-active-bg) !important;
	color: var(--gd-tab-active-text) !important;
	border-color: var(--gd-tab-active-bg) !important;
}
.gdDealerTabs .ipsTabs__tab[aria-selected="false"] {
	color: var(--gd-tab-inactive-text) !important;
}
.gdDealerWrapper .ipsButton--primary {
	background: var(--gd-primary) !important;
	color: var(--gd-primary-text) !important;
	border-color: var(--gd-primary) !important;
}
.gdStatCard {
	background: var(--gd-card-bg) !important;
	border-color: var(--gd-card-border) !important;
}
.gdDealerCoverFallback {
	background: var(--gd-header-bg) !important;
}
</style>';
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

	/**
	 * Default: render the public profile for the dealer identified by
	 * the {dealer_slug} URL segment.
	 */
	/**
	 * Human-readable perk text for a tier slug.
	 */
	protected static function tierPerkLabel( string $tier ): string
	{
		return match( $tier )
		{
			'founding'   => 'Founding dealer',
			'enterprise' => 'Custom features',
			'pro'        => 'Priority placement',
			'basic'      => 'Standard listing',
			default      => '',
		};
	}

	protected function manage(): void
	{
		$member = \IPS\Member::loggedIn();

		/* Primary: IPS furl sets this via matched params. */
		$slug = trim( (string) ( \IPS\Request::i()->dealer_slug ?? '' ) );

		/* Fallback: when Force Friendly URLs is enabled, IPS can redirect
		   the raw URL to the compiled friendly form but strip the matched
		   dealer_slug param before the controller runs. Parse REQUEST_URI
		   directly so the profile still resolves. */
		if ( $slug === '' )
		{
			$uri   = (string) parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
			$parts = array_values( array_filter( explode( '/', trim( $uri, '/' ) ) ) );

			foreach ( $parts as $i => $part )
			{
				if ( $part === 'profile' && isset( $parts[ $i + 1 ] ) )
				{
					$slug = trim( (string) $parts[ $i + 1 ] );
					break;
				}
			}

			if ( $slug === '' )
			{
				$last = end( $parts );
				if ( $last && $last !== 'profile' && $last !== 'dealers' )
				{
					try
					{
						$count = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_dealer_feed_config',
							[ 'dealer_slug=?', $last ] )->first();
						if ( $count > 0 )
						{
							$slug = (string) $last;
						}
					}
					catch ( \Exception ) {}
				}
			}
		}

		if ( $slug === '' )
		{
			\IPS\Output::i()->error( 'dealer_not_found', '2GDD300/1', 404 );
			return;
		}

		try
		{
			$dealerRow = \IPS\Db::i()->select( '*', 'gd_dealer_feed_config',
				[ 'dealer_slug=?', $slug ] )->first();
		}
		catch ( \Exception )
		{
			/* v1.0.182: Slug-history fallback. Before throwing 404, check
			 * if this slug used to belong to some dealer (regenerated since)
			 * and 301-redirect to their current slug. */
			$historyDealerId = \IPS\gddealer\Dealer\Slug::dealerIdForRetiredSlug( $slug );
			if ( $historyDealerId !== null )
			{
				try
				{
					$currentSlug = (string) \IPS\Db::i()->select(
						'dealer_slug', 'gd_dealer_feed_config',
						[ 'dealer_id=?', $historyDealerId ]
					)->first();
					if ( $currentSlug !== '' && $currentSlug !== $slug )
					{
						$targetUrl = (string) \IPS\Http\Url::internal(
							'app=gddealer&module=dealers&controller=profile&dealer_slug=' . urlencode( $currentSlug ),
							'front', 'dealers_profile', $currentSlug
						);
						/* 301 Moved Permanently - tells search engines and
						 * clients the URL has changed forever. */
						header( 'Location: ' . $targetUrl, true, 301 );
						exit;
					}
				}
				catch ( \Throwable )
				{
					/* Fall through to 404 if the dealer is gone too. */
				}
			}
			\IPS\Output::i()->error( 'dealer_not_found', '2GDD300/2', 404 );
			return;
		}

		$dealerId = (int) $dealerRow['dealer_id'];
		$isActive = (bool) ( $dealerRow['active'] ?? 0 );

		/* Aggregated ratings. Reviews resolved in the dealer's favor are
		   excluded; pending disputes and dismissed contests still count. */
		$stats = [
			'total'        => 0,
			'avg_pricing'  => 0.0,
			'avg_shipping' => 0.0,
			'avg_service'  => 0.0,
			'avg_overall'  => 0.0,
		];
		try
		{
			$agg = \IPS\Db::i()->select(
				'COUNT(*) as c, AVG(rating_pricing) as p, AVG(rating_shipping) as s, AVG(rating_service) as sv',
				'gd_dealer_ratings',
				[ 'dealer_id=? AND status=? AND dispute_status<>?', $dealerId, 'approved', 'resolved_dealer' ]
			)->first();
			$stats['total']        = (int) $agg['c'];
			$stats['avg_pricing']  = round( (float) $agg['p'], 1 );
			$stats['avg_shipping'] = round( (float) $agg['s'], 1 );
			$stats['avg_service']  = round( (float) $agg['sv'], 1 );
			$stats['avg_overall']  = $stats['total'] > 0
				? round( ( $stats['avg_pricing'] + $stats['avg_shipping'] + $stats['avg_service'] ) / 3, 1 )
				: 0.0;
		}
		catch ( \Exception ) {}

		$stats['rating_color']   = self::ratingColor( (float) $stats['avg_overall'] );
		$stats['rating_label']   = self::ratingLabel( (float) $stats['avg_overall'] );
		$stats['color_pricing']  = self::ratingColor( (float) $stats['avg_pricing'] );
		$stats['color_shipping'] = self::ratingColor( (float) $stats['avg_shipping'] );
		$stats['color_service']  = self::ratingColor( (float) $stats['avg_service'] );

		/* Reviews list — all approved rows. Dispute status is surfaced per
		   row so the template can render the appropriate badge. */

		/* Read filter params from URL with safe defaults. */
		$sortKey = (string) ( \IPS\Request::i()->sort  ?? 'newest' );
		$starKey = (string) ( \IPS\Request::i()->stars ?? 'all' );

		$validSorts = [ 'newest', 'oldest', 'highest', 'lowest' ];
		if ( !in_array( $sortKey, $validSorts, TRUE ) ) { $sortKey = 'newest'; }

		$validStars = [ 'all', '5', '4', '3', '2', '1' ];
		if ( !in_array( $starKey, $validStars, TRUE ) ) { $starKey = 'all'; }

		/* Build WHERE clause as a single SQL string + bound params. */
		$whereSql    = 'dealer_id=? AND status=?';
		$whereParams = [ $dealerId, 'approved' ];

		if ( $starKey !== 'all' )
		{
			$star = (int) $starKey;
			if ( $star === 5 )
			{
				$whereSql     .= ' AND ((rating_pricing + rating_shipping + rating_service) / 3) >= ?';
				$whereParams[] = 4.5;
			}
			elseif ( $star === 1 )
			{
				$whereSql     .= ' AND ((rating_pricing + rating_shipping + rating_service) / 3) < ?';
				$whereParams[] = 1.5;
			}
			else
			{
				$whereSql     .= ' AND ((rating_pricing + rating_shipping + rating_service) / 3) >= ? AND ((rating_pricing + rating_shipping + rating_service) / 3) < ?';
				$whereParams[] = $star - 0.5;
				$whereParams[] = $star + 0.5;
			}
		}

		$whereArg = array_merge( [ $whereSql ], $whereParams );

		$orderBy = match ( $sortKey ) {
			'oldest'  => 'created_at ASC',
			'highest' => '((rating_pricing + rating_shipping + rating_service) / 3) DESC, created_at DESC',
			'lowest'  => '((rating_pricing + rating_shipping + rating_service) / 3) ASC, created_at DESC',
			default   => 'created_at DESC',
		};

		/* Per-star counts for the filter dropdown. */
		$starCounts = [ 'all' => 0, '5' => 0, '4' => 0, '3' => 0, '2' => 0, '1' => 0 ];
		try
		{
			$starCounts['all'] = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_dealer_ratings',
				[ 'dealer_id=? AND status=?', $dealerId, 'approved' ]
			)->first();

			$starCounts['5'] = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_dealer_ratings',
				[ 'dealer_id=? AND status=? AND ((rating_pricing + rating_shipping + rating_service) / 3) >= ?',
					$dealerId, 'approved', 4.5 ]
			)->first();

			$starCounts['1'] = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_dealer_ratings',
				[ 'dealer_id=? AND status=? AND ((rating_pricing + rating_shipping + rating_service) / 3) < ?',
					$dealerId, 'approved', 1.5 ]
			)->first();

			foreach ( [ 4, 3, 2 ] as $s )
			{
				$starCounts[ (string) $s ] = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_dealer_ratings',
					[ 'dealer_id=? AND status=? AND ((rating_pricing + rating_shipping + rating_service) / 3) >= ? AND ((rating_pricing + rating_shipping + rating_service) / 3) < ?',
						$dealerId, 'approved', $s - 0.5, $s + 0.5 ]
				)->first();
			}
		}
		catch ( \Exception ) {}

		$reviews = [];
		try
		{
			foreach ( \IPS\Db::i()->select( '*', 'gd_dealer_ratings',
				$whereArg, $orderBy, [ 0, 50 ]
			) as $r )
			{
				$pricing  = max( 0, min( 5, (int) $r['rating_pricing'] ) );
				$shipping = max( 0, min( 5, (int) $r['rating_shipping'] ) );
				$service  = max( 0, min( 5, (int) $r['rating_service'] ) );
				$avg      = round( ( $pricing + $shipping + $service ) / 3, 1 );

				$reviewerName   = 'Anonymous';
				$reviewerAvatar = '';
				if ( (int) ( $r['member_id'] ?? 0 ) > 0 )
				{
					try
					{
						$rm = \IPS\Member::load( (int) $r['member_id'] );
						if ( $rm->member_id )
						{
							$reviewerName = (string) $rm->name;

							/* Only surface the avatar URL if the member uploaded a
							   real photo. IPS's default letter/gravatar fallback
							   is replaced by the template's gradient+initial
							   circle, which looks cleaner in the new card. */
							$photoType = (string) ( $rm->pp_photo_type ?? '' );
							if ( $photoType === 'custom' )
							{
								$reviewerAvatar = (string) ( $rm->get_photo( true, false ) ?? '' );
							}
						}
					}
					catch ( \Exception ) {}
				}

				$createdAt   = (string) $r['created_at'];
				$responseAt  = (string) ( $r['response_at'] ?? '' );
				$createdTs   = $createdAt !== '' ? strtotime( $createdAt ) : 0;
				$responseTs  = $responseAt !== '' ? strtotime( $responseAt ) : 0;

				$reviewMemberId = (int) ( $r['member_id'] ?? 0 );
				$isOwnReview    = $member->member_id && $reviewMemberId === (int) $member->member_id;
				$rowDisputeStatus = (string) ( $r['dispute_status'] ?? 'none' );

				$disputeRespondUrl = '';
				if ( $isOwnReview && $rowDisputeStatus === 'pending_customer' )
				{
					$disputeRespondUrl = (string) \IPS\Http\Url::internal(
						'app=gddealer&module=dealers&controller=profile&dealer_slug=' . urlencode( $slug ) . '&dispute=' . (int) $r['id']
					);
				}

				$editReviewUrl = '';
				if ( $isOwnReview && $rowDisputeStatus === 'none' )
				{
					$editReviewUrl = (string) \IPS\Http\Url::internal(
						'app=gddealer&module=dealers&controller=profile&do=editReview&dealer_slug=' . urlencode( $slug ) . '&id=' . (int) $r['id']
					);
				}

				$reviewerInitials = '';
				foreach ( preg_split( '/\s+/', trim( (string) $reviewerName ) ) ?: [] as $part )
				{
					if ( $part !== '' && strlen( $reviewerInitials ) < 2 )
					{
						$reviewerInitials .= mb_strtoupper( mb_substr( $part, 0, 1 ) );
					}
				}
				if ( $reviewerInitials === '' ) { $reviewerInitials = '?'; }
				$ratingOverall    = (int) round( $avg );

				$reviews[] = [
					'id'                   => (int) $r['id'],
					'member_id'            => $reviewMemberId,
					'rating_pricing'       => $pricing,
					'rating_shipping'      => $shipping,
					'rating_service'       => $service,
					'rating_overall'       => $ratingOverall,
					'review_body'          => (string) ( $r['review_body'] ?? '' ),
					'dealer_response'      => (string) ( $r['dealer_response'] ?? '' ),
					'dealer_name'          => (string) ( $dealerRow['dealer_name'] ?? '' ),
					'created_at'           => $createdAt,
					'created_at_formatted' => $createdTs ? (string) \IPS\DateTime::ts( $createdTs )->localeDate() : '',
					'created_at_display'   => $createdTs ? (string) \IPS\DateTime::ts( $createdTs )->localeDate() : '',
					'response_at'          => $responseTs ? (string) \IPS\DateTime::ts( $responseTs )->localeDate() : '',
					'dispute_status'       => $rowDisputeStatus,
					'dispute_outcome'      => (string) ( $r['dispute_outcome'] ?? '' ),
					'avg_score'            => $avg,
					'avg_color'            => self::ratingColor( (float) $avg ),
					'customer_name'        => $reviewerName,
					'customer_initials'    => $reviewerInitials,
					'reviewer_name'        => $reviewerName,
					'reviewer_avatar'      => $reviewerAvatar,
					'verified_buyer'       => (bool) ( $r['verified_buyer'] ?? 0 ),
					'stars_pricing'        => str_repeat( '★', $pricing ) . str_repeat( '☆', 5 - $pricing ),
					'stars_shipping'       => str_repeat( '★', $shipping ) . str_repeat( '☆', 5 - $shipping ),
					'stars_service'        => str_repeat( '★', $service ) . str_repeat( '☆', 5 - $service ),
					'is_own_review'        => $isOwnReview,
					'dispute_respond_url'  => $disputeRespondUrl,
					'edit_review_url'      => $editReviewUrl,
				];
			}
		}
		catch ( \Exception ) {}

		$alreadyRated = false;
		if ( $member->member_id )
		{
			try
			{
				$alreadyRated = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_dealer_ratings',
					[ 'dealer_id=? AND member_id=?', $dealerId, (int) $member->member_id ] )->first() > 0;
			}
			catch ( \Exception ) {}
		}

		/* A member cannot review their own dealership, and inactive dealers
		   no longer accept new reviews (the rate() action enforces this too). */
		$isOwnDealer   = $member->member_id && (int) $member->member_id === $dealerId;
		$canRate       = $isActive && $member->member_id && !$alreadyRated && !$isOwnDealer;
		$loginRequired = !$member->member_id;

		/* Rich-text editor for the "Leave a Review" form. Only built when
		   the viewer is actually allowed to rate — saves render cost for
		   logged-out / already-rated visitors. attachIds=[0,1] is a
		   placeholder; File::claimAttachments rewrites to the real review
		   id after insert. autoSaveKey scoped per-member so each viewer
		   has their own draft slot. */
		$reviewBodyEditorHtml = '';
		if ( $canRate )
		{
			$reviewEditor = new \IPS\Helpers\Form\Editor(
				'gddealer_review_body',
				'',
				FALSE,
				[
					'app'         => 'gddealer',
					'key'         => 'Responses',
					'autoSaveKey' => 'gddealer-review-new-' . (int) $member->member_id,
					'attachIds'   => [ 0, 1 ],
				],
				NULL,
				NULL,
				NULL,
				'editor_review_new_' . (int) $member->member_id
			);
			$reviewBodyEditorHtml = (string) $reviewEditor;
		}

		/* If this visit is in response to a dispute notification ("You have
		   been contested"), build the banner data and customer response
		   form target. */
		$customerDispute = null;
		$disputeId       = (int) ( \IPS\Request::i()->dispute ?? 0 );
		if ( $disputeId > 0 && $member->member_id )
		{
			try
			{
				$cd = \IPS\Db::i()->select( '*', 'gd_dealer_ratings',
					[ 'id=? AND dealer_id=? AND member_id=? AND dispute_status=?',
						$disputeId, $dealerId, (int) $member->member_id, 'pending_customer' ]
				)->first();
				$deadlineRaw = (string) ( $cd['dispute_deadline'] ?? '' );

				/* Two editors for the customer reply: customer_response
				   (id2=5) and customer_evidence (id2=6). Pre-fill with
				   existing values so when the customer revisits after an
				   admin edit-request, their previous response isn't lost. */
				$crEditor = new \IPS\Helpers\Form\Editor(
					'gddealer_customer_response',
					(string) ( $cd['customer_response'] ?? '' ),
					FALSE,
					[
						'app'         => 'gddealer',
						'key'         => 'Responses',
						'autoSaveKey' => 'gddealer-customer-response-' . (int) $cd['id'],
						'attachIds'   => [ (int) $cd['id'], 5 ],
					],
					NULL,
					NULL,
					NULL,
					'editor_customer_response_' . (int) $cd['id']
				);
				$custRespHtml = (string) $crEditor;

				$ceEditor = new \IPS\Helpers\Form\Editor(
					'gddealer_customer_evidence',
					(string) ( $cd['customer_evidence'] ?? '' ),
					FALSE,
					[
						'app'         => 'gddealer',
						'key'         => 'Responses',
						'autoSaveKey' => 'gddealer-customer-evidence-' . (int) $cd['id'],
						'attachIds'   => [ (int) $cd['id'], 6 ],
					],
					NULL,
					NULL,
					NULL,
					'editor_customer_evidence_' . (int) $cd['id']
				);
				$custEvidHtml = (string) $ceEditor;

				$reasonRendered   = (string) ( $cd['dispute_reason'] ?? '' );
				$evidenceRendered = (string) ( $cd['dispute_evidence'] ?? '' );

				$reasonAtts   = AttachHelper::getAttachments( (int) $cd['id'], 3 );
				$evidenceAtts = AttachHelper::getAttachments( (int) $cd['id'], 4 );

				$reasonHasUnembedded = false;
				foreach ( $reasonAtts as $a ) { if ( $a['is_image'] ) { $reasonHasUnembedded = true; break; } }
				$reasonHasUnembedded = $reasonHasUnembedded && !preg_match( '/<img/i', $reasonRendered );

				$evidenceHasUnembedded = false;
				foreach ( $evidenceAtts as $a ) { if ( $a['is_image'] ) { $evidenceHasUnembedded = true; break; } }
				$evidenceHasUnembedded = $evidenceHasUnembedded && !preg_match( '/<img/i', $evidenceRendered );

				$customerDispute = [
					'id'                 => (int) $cd['id'],
					'dispute_reason'     => $reasonRendered,
					'dispute_evidence'   => $evidenceRendered,
					'dispute_deadline'   => $deadlineRaw,
					'deadline_formatted' => $deadlineRaw !== '' ? date( 'F j, Y', strtotime( $deadlineRaw ) ) : '',
					'respond_url'        => (string) \IPS\Http\Url::internal(
						'app=gddealer&module=dealers&controller=profile&do=disputeRespond&dealer_slug=' . urlencode( $slug ) . '&id=' . (int) $cd['id']
					)->csrf(),
					'response_editor_html' => $custRespHtml,
					'evidence_editor_html' => $custEvidHtml,
					'dispute_reason_attachments'              => $reasonAtts,
					'dispute_evidence_attachments'            => $evidenceAtts,
					'dispute_reason_has_unembedded_images'    => $reasonHasUnembedded,
					'dispute_evidence_has_unembedded_images'  => $evidenceHasUnembedded,
					'events'                                  => EventLogger::getEvents( (int) $cd['id'] ),
				];
			}
			catch ( \Exception ) {}
		}

		$rateUrl = (string) \IPS\Http\Url::internal(
			'app=gddealer&module=dealers&controller=profile&do=rate&dealer_slug=' . urlencode( $slug )
		)->csrf();

		$loginUrl = (string) \IPS\Http\Url::internal(
			'app=core&module=system&controller=login',
			'front', 'login'
		);

		$guidelinesUrl = (string) \IPS\Http\Url::internal(
			'app=gddealer&module=dealers&controller=profile&do=guidelines',
			'front', 'dealers_review_guidelines'
		);

		/* Pull the matching IPS member so we can show their avatar, cover
		   photo, and accurate join date — the dealer profile page mirrors
		   the IPS member profile layout. */
		$avatarUrl     = '';
		$coverPhotoUrl = '';
		$coverOffset   = 0;
		$memberSince   = '';
		try
		{
			$ipsMember = \IPS\Member::load( $dealerId );
			if ( $ipsMember->member_id )
			{
				$avatarUrl = (string) ( $ipsMember->get_photo( true, false ) ?? '' );
				if ( $ipsMember->joined instanceof \IPS\DateTime )
				{
					$memberSince = $ipsMember->joined->format( 'F Y' );
				}
				$cp = $ipsMember->coverPhoto();
				if ( $cp->file )
				{
					$coverPhotoUrl = (string) $cp->file->url;
				}
				$coverOffset = (int) ( $cp->offset ?? 0 );
			}
		}
		catch ( \Exception ) {}

		if ( $memberSince === '' )
		{
			$createdAtRaw = (string) ( $dealerRow['created_at'] ?? '' );
			$memberSince  = $createdAtRaw ? substr( $createdAtRaw, 0, 7 ) : '';
		}

		$activeListings = 0;
		try
		{
			$activeListings = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_dealer_listings',
				[ 'dealer_id=? AND listing_status=?', $dealerId, 'active' ] )->first();
		}
		catch ( \Exception ) {}

		/* Tier derives from the dealer's IPS user groups (primary OR secondary).
		   Group IDs: 7=Dealers-Founding, 8=Dealers-Basic, 9=Dealers-Pro, 10=Dealers-Enterprise.
		   On this site the primary group is typically 3 (Members), with the dealer tier
		   in mgroup_others (CSV string). Highest-tier wins if multiple are set. */
		$dealerGroupIds = [];
		try
		{
			if ( isset( $ipsMember ) && $ipsMember->member_id )
			{
				$dealerGroupIds[] = (int) $ipsMember->member_group_id;

				$secondary = (string) ( $ipsMember->mgroup_others ?? '' );
				foreach ( array_filter( array_map( 'trim', explode( ',', $secondary ) ) ) as $gid )
				{
					$dealerGroupIds[] = (int) $gid;
				}
			}
		}
		catch ( \Exception ) {}

		/* Pick the highest-priority dealer tier present in any of the member's groups.
		   Priority: founding (7) > enterprise (10) > pro (9) > basic (8). */
		$tier = 'basic';
		if ( in_array( 7, $dealerGroupIds, true ) )
		{
			$tier = 'founding';
		}
		elseif ( in_array( 10, $dealerGroupIds, true ) )
		{
			$tier = 'enterprise';
		}
		elseif ( in_array( 9, $dealerGroupIds, true ) )
		{
			$tier = 'pro';
		}
		elseif ( in_array( 8, $dealerGroupIds, true ) )
		{
			$tier = 'basic';
		}
		$isFoundingMember = !empty( $dealerRow['is_founding_member'] );
		if ( $isFoundingMember )
		{
			$tierLabel = 'Founder';
			$tierColor = (string) ( \IPS\Settings::i()->gddealer_founding_badge_color ?: '#b45309' );
		}
		else
		{
			$tierLabel = match( $tier )
			{
				'founding'   => 'Founding Dealer',
				'pro'        => 'Pro Dealer',
				'enterprise' => 'Enterprise Dealer',
				default      => 'Basic Dealer',
			};
			$tierColor = match( $tier ) {
				'founding'   => (string) ( \IPS\Settings::i()->gddealer_founding_badge_color   ?: '#b45309' ),
				'pro'        => (string) ( \IPS\Settings::i()->gddealer_pro_badge_color        ?: '#2563eb' ),
				'enterprise' => (string) ( \IPS\Settings::i()->gddealer_enterprise_badge_color ?: '#7c3aed' ),
				default      => (string) ( \IPS\Settings::i()->gddealer_basic_badge_color      ?: '#6b7280' ),
			};
		}

		$contactEmail = '';
		try
		{
			if ( isset( $ipsMember ) && $ipsMember->member_id )
			{
				$contactEmail = (string) $ipsMember->email;
			}
		}
		catch ( \Exception ) {}
		if ( $contactEmail === '' )
		{
			$contactEmail = (string) ( \IPS\Settings::i()->gddealer_help_contact ?: 'dealers@gunrack.deals' );
		}

		/* Hours JSON decode */
		$hours = [];
		$hoursRaw = (string) ( $dealerRow['hours_json'] ?? '' );
		if ( $hoursRaw !== '' )
		{
			$decoded = json_decode( $hoursRaw, true );
			if ( is_array( $decoded ) ) { $hours = $decoded; }
		}

		$dayLabels = [ 'mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday' ];
		$todayKey  = strtolower( substr( date( 'D' ), 0, 3 ) );
		$hoursDisplay = [];
		$anyHoursSet  = false;
		foreach ( [ 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ] as $d )
		{
			$dayData = $hours[ $d ] ?? null;
			$closed  = (bool) ( $dayData['closed'] ?? false );
			$open    = (string) ( $dayData['open']  ?? '' );
			$close   = (string) ( $dayData['close'] ?? '' );

			if ( $closed || ( $open !== '' && $close !== '' ) ) { $anyHoursSet = true; }

			$hoursDisplay[] = [
				'key'       => $d,
				'label'     => $dayLabels[ $d ],
				'closed'    => $closed,
				'open'      => $open !== '' ? date( 'g:i A', strtotime( $open ) ) : '',
				'close'     => $close !== '' ? date( 'g:i A', strtotime( $close ) ) : '',
				'is_today'  => $d === $todayKey,
			];
		}

		/* Payment methods CSV → array of label objects */
		$pmLabels = [
			'visa'      => [ 'label' => 'Visa',        'short' => 'Visa' ],
			'mc'        => [ 'label' => 'Mastercard',  'short' => 'MC' ],
			'amex'      => [ 'label' => 'Amex',        'short' => 'Amex' ],
			'discover'  => [ 'label' => 'Discover',    'short' => 'Disc' ],
			'check'     => [ 'label' => 'Check',       'short' => 'Check' ],
			'mo'        => [ 'label' => 'Money Order', 'short' => 'MO' ],
			'cash'      => [ 'label' => 'Cash',        'short' => 'Cash' ],
			'layaway'   => [ 'label' => 'Layaway',     'short' => 'Layaway' ],
			'financing' => [ 'label' => 'Financing',   'short' => 'Finance' ],
		];
		$pmRaw = (string) ( $dealerRow['payment_methods'] ?? '' );
		$paymentMethods = [];
		if ( $pmRaw !== '' )
		{
			foreach ( array_filter( array_map( 'trim', explode( ',', $pmRaw ) ) ) as $pmKey )
			{
				if ( isset( $pmLabels[ $pmKey ] ) )
				{
					$paymentMethods[] = [ 'key' => $pmKey ] + $pmLabels[ $pmKey ];
				}
			}
		}

		/* Socials — only keep non-empty */
		$socials = [];
		foreach ( [ 'facebook', 'instagram', 'youtube', 'twitter', 'tiktok' ] as $net )
		{
			$url = (string) ( $dealerRow[ 'social_' . $net ] ?? '' );
			if ( $url !== '' ) { $socials[] = [ 'network' => $net, 'url' => $url ]; }
		}

		/* Address — honor address_public flag */
		$addressPublic    = (int) ( $dealerRow['address_public'] ?? 1 ) === 1;
		$addressStreet    = (string) ( $dealerRow['address_street'] ?? '' );
		$addressCity      = (string) ( $dealerRow['address_city']   ?? '' );
		$addressState     = (string) ( $dealerRow['address_state']  ?? '' );
		$addressZip       = (string) ( $dealerRow['address_zip']    ?? '' );
		$addressCityState = trim( $addressCity . ( ( $addressCity && $addressState ) ? ', ' : '' ) . $addressState );
		$addressLine      = '';
		if ( $addressPublic && $addressStreet !== '' )
		{
			$addressLine = trim( $addressStreet . ( $addressCityState ? ', ' . $addressCityState : '' ) . ( $addressZip ? ' ' . $addressZip : '' ) );
		}
		else
		{
			$addressLine = $addressCityState ?: '';
		}

		$brandColor = (string) ( $dealerRow['brand_color'] ?? '#1E40AF' );
		if ( !preg_match( '/^#[0-9A-Fa-f]{6}$/', $brandColor ) ) { $brandColor = '#1E40AF'; }

		$dealer = [
			'dealer_id'        => $dealerId,
			'dealer_name'      => (string) $dealerRow['dealer_name'],
			'dealer_slug'      => (string) $dealerRow['dealer_slug'],
			'member_since'     => $memberSince,
			'is_active'        => $isActive,
			'avatar_url'       => $avatarUrl,
			'cover_photo_url'  => $coverPhotoUrl,
			'cover_offset'     => $coverOffset,
			'tier'             => $tier,
			'tier_label'       => $tierLabel,
			'tier_color'       => $tierColor,
			'active_listings'  => $activeListings,
			'listing_count'    => $activeListings,
			'contact_email'    => $contactEmail,

			'tagline'            => (string) ( $dealerRow['tagline']        ?? '' ),
			'about'              => (string) ( $dealerRow['about']          ?? '' ),
			'logo_url'           => (string) ( $dealerRow['logo_url']       ?? '' ),
			'cover_url_custom'   => (string) ( $dealerRow['cover_url']      ?? '' ),
			'public_phone'       => (string) ( $dealerRow['public_phone']   ?? '' ),
			'public_email'       => (string) ( $dealerRow['public_email']   ?? '' ),
			'website_url'        => (string) ( $dealerRow['website_url']    ?? '' ),
			'address_line'       => $addressLine,
			'address_public'     => $addressPublic,
			'address_city_state' => $addressCityState,
			'hours'              => $hoursDisplay,
			'has_hours'          => $anyHoursSet,
			'socials'            => $socials,
			'payment_methods'    => $paymentMethods,
			'shipping_policy'    => (string) ( $dealerRow['shipping_policy']  ?? '' ),
			'return_policy'      => (string) ( $dealerRow['return_policy']    ?? '' ),
			'additional_notes'   => (string) ( $dealerRow['additional_notes'] ?? '' ),
			'brand_color'        => $brandColor,
			'verified'           => !empty( $dealerRow['ffl_verified_at'] ),
			'tier_perk'          => self::tierPerkLabel( $tier ),
			'response_rate'      => null,
			'response_window'    => null,
			'listings_updated'   => null,
		];

		if ( $dealer['cover_url_custom'] !== '' )
		{
			$dealer['cover_photo_url'] = $dealer['cover_url_custom'];
		}

		$stats['count']        = $stats['total'];
		$stats['pct_pricing']  = $stats['avg_pricing']  > 0 ? (int) round( ( $stats['avg_pricing']  / 5 ) * 100 ) : 0;
		$stats['pct_shipping'] = $stats['avg_shipping'] > 0 ? (int) round( ( $stats['avg_shipping'] / 5 ) * 100 ) : 0;
		$stats['pct_service']  = $stats['avg_service']  > 0 ? (int) round( ( $stats['avg_service']  / 5 ) * 100 ) : 0;

		$csrfKey = (string) \IPS\Session::i()->csrfKey;

		$baseUrl = \IPS\Http\Url::internal(
			'app=gddealer&module=dealers&controller=profile&dealer_slug=' . urlencode( $slug )
		);

		$starOptions = [];
		foreach ( [ 'all', '5', '4', '3', '2', '1' ] as $s )
		{
			$starOptions[ $s ] = (string) $baseUrl->setQueryString( [ 'sort' => $sortKey, 'stars' => $s ] );
		}

		$sortOptions = [];
		foreach ( [ 'newest', 'oldest', 'highest', 'lowest' ] as $sort )
		{
			$sortOptions[ $sort ] = (string) $baseUrl->setQueryString( [ 'sort' => $sort, 'stars' => $starKey ] );
		}

		$clearFiltersUrl = (string) $baseUrl->setQueryString( [ 'sort' => 'newest', 'stars' => 'all' ] );

		$totalInFilter = (int) ( $starCounts[ $starKey ] ?? $starCounts['all'] );

		$listPage    = max( 1, (int) ( \IPS\Request::i()->lpage ?? 1 ) );
		$listSearch  = trim( (string) ( \IPS\Request::i()->lq ?? '' ) );
		$listPerPage = 50;

		$listWhere = [ 'l.dealer_id=? AND l.listing_status=?', $dealerId, 'active' ];
		if ( $listSearch !== '' )
		{
			$like = '%' . $listSearch . '%';
			$listWhere = [ 'l.dealer_id=? AND l.listing_status=? AND ( l.upc LIKE ? OR c.title LIKE ? )', $dealerId, 'active', $like, $like ];
		}

		$listTotal = 0;
		try
		{
			$listTotal = (int) \IPS\Db::i()->select(
				'COUNT(*)',
				[ 'gd_dealer_listings', 'l' ],
				$listWhere
			)->join( [ 'gd_catalog', 'c' ], 'c.upc = l.upc', 'LEFT' )->first();
		}
		catch ( \Throwable ) {}

		$listPages = max( 1, (int) ceil( $listTotal / $listPerPage ) );
		if ( $listPage > $listPages ) { $listPage = $listPages; }
		$listOffset = ( $listPage - 1 ) * $listPerPage;

		$profileListings = [];
		try
		{
			foreach ( \IPS\Db::i()->select(
				'l.upc, l.dealer_price, l.listing_status, l.listing_url, c.title AS product_title, c.brand, c.image_url',
				[ 'gd_dealer_listings', 'l' ],
				$listWhere,
				'c.title ASC',
				[ $listOffset, $listPerPage ]
			)->join( [ 'gd_catalog', 'c' ], 'c.upc = l.upc', 'LEFT' ) as $row )
			{
				$profileListings[] = [
					'upc'           => (string) $row['upc'],
					'title'         => (string) ( $row['product_title'] ?? 'Unknown Product' ),
					'brand'         => (string) ( $row['brand'] ?? '' ),
					'image_url'     => (string) ( $row['image_url'] ?? '' ),
					'price_display' => '$' . number_format( (float) ( $row['dealer_price'] ?? 0 ), 2 ),
					'product_url'   => (string) ( $row['listing_url'] ?? '' ),
				];
			}
		}
		catch ( \Throwable $e ) {
			try { \IPS\Log::log( $e, 'gddealer_listings' ); } catch ( \Throwable ) {}
		}

		$profileDeals = [];
		try
		{
			$now = time();
			foreach ( \IPS\Db::i()->select(
				'd.deal_id, d.upc, d.deal_type, d.deal_price, d.deal_percent, d.title, d.blurb, d.end_date, c.title AS product_title, c.image_url, c.brand, l.dealer_price AS current_price',
				[ 'gd_deals', 'd' ],
				[ 'd.dealer_id=? AND d.is_active=1 AND (d.start_date IS NULL OR d.start_date <= ?) AND (d.end_date IS NULL OR d.end_date >= ?)', $dealerId, $now, $now ],
				'd.created DESC',
				[ 0, 20 ]
			)->join( [ 'gd_catalog', 'c' ], 'c.upc = d.upc', 'LEFT' )
			 ->join( [ 'gd_dealer_listings', 'l' ], 'l.dealer_id = d.dealer_id AND l.upc = d.upc', 'LEFT' ) as $row )
			{
				$regularPrice = (float) ( $row['current_price'] ?? 0 );
				$dealPriceDisplay = '';
				$pctOff = '';

				if ( $row['deal_type'] === 'price' )
				{
					$dealPriceDisplay = '$' . number_format( (float) $row['deal_price'], 2 );
				}
				else
				{
					$pct = (float) $row['deal_percent'];
					$pctOff = number_format( $pct, 0 ) . '% off';
					if ( $regularPrice > 0 )
					{
						$dealPriceDisplay = '$' . number_format( round( $regularPrice * ( 1 - $pct / 100 ), 2 ), 2 );
					}
				}

				$profileDeals[] = [
					'id'             => (int) $row['deal_id'],
					'product_title'  => (string) ( $row['product_title'] ?? 'Unknown Product' ),
					'image_url'      => (string) ( $row['image_url'] ?? '' ),
					'brand'          => (string) ( $row['brand'] ?? '' ),
					'deal_price'     => $dealPriceDisplay,
					'regular_price'  => $regularPrice > 0 ? '$' . number_format( $regularPrice, 2 ) : '',
					'pct_off'        => $pctOff,
					'title'          => (string) ( $row['title'] ?? '' ),
					'blurb'          => (string) ( $row['blurb'] ?? '' ),
					'end_date'       => (int) ( $row['end_date'] ?? 0 ) > 0 ? date( 'M j, Y', (int) $row['end_date'] ) : '',
				];
			}
		}
		catch ( \Throwable $e ) {
			try { \IPS\Log::log( $e, 'gddealer_listings' ); } catch ( \Throwable ) {}
		}

		$profileCoupons = [];
		try
		{
			$now = time();
			foreach ( \IPS\Db::i()->select( '*', 'gd_dealer_coupons',
				[ 'dealer_id=? AND is_active=1 AND (expiry IS NULL OR expiry = 0 OR expiry >= ?)', $dealerId, $now ],
				'created DESC', [ 0, 20 ]
			) as $row )
			{
				$dtype = (string) ( $row['discount_type'] ?? 'percent' );
				$discountDisplay = '';
				if ( $dtype === 'free_shipping' )
				{
					$discountDisplay = 'Free shipping';
				}
				elseif ( $dtype === 'percent' )
				{
					$discountDisplay = number_format( (float) $row['discount_value'], 0 ) . '% off';
				}
				else
				{
					$discountDisplay = '$' . number_format( (float) $row['discount_value'], 2 ) . ' off';
				}

				$profileCoupons[] = [
					'id'               => (int) $row['coupon_id'],
					'code'             => strtoupper( (string) $row['code'] ),
					'description'      => (string) ( $row['description'] ?? '' ),
					'discount_type'    => $dtype,
					'discount_display' => $discountDisplay,
					'min_purchase'     => (float) ( $row['min_purchase'] ?? 0 ) > 0 ? '$' . number_format( (float) $row['min_purchase'], 2 ) : '',
					'terms'            => (string) ( $row['terms'] ?? '' ),
					'expiry'           => (int) ( $row['expiry'] ?? 0 ) > 0 ? date( 'M j, Y', (int) $row['expiry'] ) : '',
				];
			}
		}
		catch ( \Throwable ) {}

		$data = [
			'dealer'                  => $dealer,
			'stats'                   => $stats,
			'reviews'                 => $reviews,
			'can_rate'                => $canRate,
			'already_rated'           => $alreadyRated,
			'login_required'          => $loginRequired,
			'rate_url'                => $rateUrl,
			'csrf_key'                => $csrfKey,
			'login_url'               => $loginUrl,
			'customer_dispute'        => $customerDispute,
			'guidelines_url'          => $guidelinesUrl,
			'review_body_editor_html' => $reviewBodyEditorHtml,
			'sort_key'                => $sortKey,
			'star_key'                => $starKey,
			'star_counts'             => $starCounts,
			'star_options'            => $starOptions,
			'sort_options'            => $sortOptions,
			'clear_filters_url'       => $clearFiltersUrl,
			'total_in_filter'         => $totalInFilter,
			'listings'                => $profileListings,
			'listings_total'          => $listTotal,
			'listings_page'           => $listPage,
			'listings_pages'          => $listPages,
			'listings_per_page'       => $listPerPage,
			'listings_search'         => $listSearch,
			'deals'                   => $profileDeals,
			'coupons'                 => $profileCoupons,
		];

		try {
			\IPS\Output::i()->cssFiles = array_merge(
				\IPS\Output::i()->cssFiles,
				\IPS\Theme::i()->css( 'dealer.css', 'gddealer', 'front' )
			);
		} catch ( \Throwable ) {}

		try {
			\IPS\Output::i()->jsFiles = array_merge(
				\IPS\Output::i()->jsFiles,
				\IPS\Output::i()->js( 'profileTabs.js', 'gddealer', 'interface' )
			);
		} catch ( \Throwable ) {}

		\IPS\Output::i()->title  = $dealer['dealer_name'];
		\IPS\Output::i()->output = $this->themeVars() . \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )
			->dealerProfile( $data );
	}

	/**
	 * Public "Review & Dispute Guidelines" page at /dealers/review-guidelines.
	 * Pulls all section titles/bodies from settings so admin can edit.
	 */
	protected function guidelines(): void
	{
		$settings = \IPS\Settings::i();

		$content = [
			'buyer_title'   => (string) ( $settings->gddealer_guidelines_buyer_title   ?? '' ),
			'buyer_body'    => (string) ( $settings->gddealer_guidelines_buyer_body    ?? '' ),
			'dispute_title' => (string) ( $settings->gddealer_guidelines_dispute_title ?? '' ),
			'dispute_body'  => (string) ( $settings->gddealer_guidelines_dispute_body  ?? '' ),
			'dealer_title'  => (string) ( $settings->gddealer_guidelines_dealer_title  ?? '' ),
			'dealer_body'   => (string) ( $settings->gddealer_guidelines_dealer_body   ?? '' ),
		];

		$contactEmail = (string) ( $settings->gddealer_help_contact ?: 'dealers@gunrack.deals' );

		try {
			\IPS\Output::i()->cssFiles = array_merge(
				\IPS\Output::i()->cssFiles,
				\IPS\Theme::i()->css( 'dealer.css', 'gddealer', 'front' )
			);
		} catch ( \Throwable ) {}

		\IPS\Output::i()->title  = 'Review & Dispute Guidelines';
		\IPS\Output::i()->output = $this->themeVars() . \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )
			->reviewGuidelines( $content, $contactEmail );
	}

	/**
	 * POST — create or update a member's rating for this dealer.
	 * Uniqueness is enforced by the uq_dealer_member index: each member
	 * can only rate a given dealer once (subsequent submits are updates).
	 */
	protected function rate(): void
	{
		\IPS\Session::i()->csrfCheck();

		$member = \IPS\Member::loggedIn();
		if ( !$member->member_id )
		{
			\IPS\Output::i()->error( 'node_error', '2GDD300/3', 403 );
			return;
		}

		$slug = trim( (string) ( \IPS\Request::i()->dealer_slug ?? '' ) );
		if ( $slug === '' )
		{
			\IPS\Output::i()->error( 'dealer_not_found', '2GDD300/4', 404 );
			return;
		}

		try
		{
			$dealerRow = \IPS\Db::i()->select( '*', 'gd_dealer_feed_config',
				[ 'dealer_slug=? AND active=?', $slug, 1 ] )->first();
		}
		catch ( \Exception )
		{
			\IPS\Output::i()->error( 'dealer_not_found', '2GDD300/5', 404 );
			return;
		}

		$dealerId = (int) $dealerRow['dealer_id'];
		if ( (int) $member->member_id === $dealerId )
		{
			\IPS\Output::i()->error( 'node_error', '2GDD300/6', 403 );
			return;
		}

		/* Construct the editor before any save logic so IPS's upload
		   interceptor (getUploader query param) fires on attachment POSTs.
		   autoSaveKey must match the render-side editor in manage(). */
		new \IPS\Helpers\Form\Editor(
			'gddealer_review_body',
			'',
			FALSE,
			[
				'app'         => 'gddealer',
				'key'         => 'Responses',
				'autoSaveKey' => 'gddealer-review-new-' . (int) $member->member_id,
				'attachIds'   => [ 0, 1 ],
			],
			NULL,
			NULL,
			NULL,
			'editor_review_new_' . (int) $member->member_id
		);

		$pricing  = max( 1, min( 5, (int) ( \IPS\Request::i()->rating_pricing ?? 0 ) ) );
		$shipping = max( 1, min( 5, (int) ( \IPS\Request::i()->rating_shipping ?? 0 ) ) );
		$service  = max( 1, min( 5, (int) ( \IPS\Request::i()->rating_service ?? 0 ) ) );
		$bodyRaw  = (string) ( \IPS\Request::i()->gddealer_review_body ?? '' );

		$upcRaw = trim( (string) ( \IPS\Request::i()->upc ?? '' ) );
		$upc    = preg_match( '/^[0-9]{8,14}$/', $upcRaw ) ? $upcRaw : null;

		$verifiedBuyer = 0;
		if ( $upc !== null ) {
			try {
				$clickCount = (int) \IPS\Db::i()->select(
					'COUNT(*)', 'gd_click_log',
					[ 'member_id=? AND dealer_id=? AND upc=? AND clicked_at > DATE_SUB(NOW(), INTERVAL 90 DAY)', (int) $member->member_id, $dealerId, $upc ]
				)->first();
				if ( $clickCount > 0 ) { $verifiedBuyer = 1; }
			} catch ( \Exception ) {}
		}

		$profileUrl = \IPS\Http\Url::internal(
			'app=gddealer&module=dealers&controller=profile&dealer_slug=' . urlencode( $slug )
		);

		if ( $pricing < 1 || $shipping < 1 || $service < 1 )
		{
			\IPS\Output::i()->redirect( $profileUrl );
			return;
		}

		$newReviewId = 0;
		try
		{
			$newReviewId = (int) \IPS\Db::i()->insert( 'gd_dealer_ratings', [
				'dealer_id'       => $dealerId,
				'member_id'       => (int) $member->member_id,
				'rating_pricing'  => $pricing,
				'rating_shipping' => $shipping,
				'rating_service'  => $service,
				'review_body'     => null,
				'created_at'      => date( 'Y-m-d H:i:s' ),
				'status'          => 'approved',
				'dispute_status'  => 'none',
				'upc'             => $upc,
				'verified_buyer'  => $verifiedBuyer,
			]);
		}
		catch ( \Exception ) {}

		$body = '';
		if ( trim( $bodyRaw ) !== '' && $newReviewId > 0 )
		{
			try
			{
				$body = \IPS\Text\Parser::parseStatic(
					$bodyRaw,
					[ $newReviewId, 1 ],
					\IPS\Member::loggedIn(),
					'gddealer_Responses'
				);
			}
			catch ( \Exception )
			{
				$body = $bodyRaw;
			}
		}

		if ( $newReviewId > 0 && $body !== '' )
		{
			try
			{
				\IPS\Db::i()->update( 'gd_dealer_ratings',
					[ 'review_body' => $body ],
					[ 'id=?', $newReviewId ]
				);
			}
			catch ( \Exception ) {}

			try
			{
				\IPS\File::claimAttachments(
					'gddealer-review-new-' . (int) $member->member_id,
					$newReviewId,
					1
				);
			}
			catch ( \Exception ) {}
		}

		$dealerMember = NULL;
		try
		{
			$dealerMember = \IPS\Member::load( $dealerId );
			if ( $dealerMember->member_id )
			{
				\IPS\Email::buildFromTemplate( 'gddealer', 'newDealerReview', [
					'name'          => $dealerMember->name,
					'reviewer_name' => (string) $member->name,
					'dealer_name'   => (string) $dealerRow['dealer_name'],
					'review_url'    => (string) \IPS\Http\Url::internal(
						'app=gddealer&module=dealers&controller=dashboard&do=reviews'
					),
				], \IPS\Email::TYPE_TRANSACTIONAL )->send( $dealerMember );
			}
		}
		catch ( \Exception ) {}

		/* IPS inline notification to the dealer — completely independent
		   try/catch so a failed email above does not suppress it. */
		try
		{
			$dealerMember = $dealerMember ?? \IPS\Member::load( $dealerId );
			if ( $dealerMember && $dealerMember->member_id )
			{
				$notification = new \IPS\Notification(
					\IPS\Application::load( 'gddealer' ),
					'new_dealer_review',
					$dealerMember,
					[ $dealerMember ],
					[
						'reviewer_id'   => (int) $member->member_id,
						'reviewer_name' => (string) $member->name,
						'dealer_name'   => (string) $dealerRow['dealer_name'],
					]
				);
				$notification->recipients->attach( $dealerMember );
				$notification->send();
			}
		}
		catch ( \Exception ) {}

		\IPS\Output::i()->redirect( $profileUrl );
	}

	/**
	 * POST — customer response to a dealer's contest.
	 * Requires the review is in 'pending_customer' status and the
	 * logged-in member is the review author. On success the dispute
	 * advances to 'pending_admin' and an admin notification is sent.
	 */
	protected function disputeRespond(): void
	{
		\IPS\Session::i()->csrfCheck();

		$member = \IPS\Member::loggedIn();
		if ( !$member->member_id )
		{
			\IPS\Output::i()->error( 'node_error', '2GDD300/7', 403 );
			return;
		}

		$slug = trim( (string) ( \IPS\Request::i()->dealer_slug ?? '' ) );
		$id   = (int) ( \IPS\Request::i()->id ?? 0 );
		if ( $slug === '' || $id <= 0 )
		{
			\IPS\Output::i()->error( 'dealer_not_found', '2GDD300/8', 404 );
			return;
		}

		try
		{
			$dealerRow = \IPS\Db::i()->select( '*', 'gd_dealer_feed_config',
				[ 'dealer_slug=?', $slug ] )->first();
		}
		catch ( \Exception )
		{
			\IPS\Output::i()->error( 'dealer_not_found', '2GDD300/9', 404 );
			return;
		}

		$dealerId   = (int) $dealerRow['dealer_id'];
		$profileUrl = \IPS\Http\Url::internal(
			'app=gddealer&module=dealers&controller=profile&dealer_slug=' . urlencode( $slug )
		);

		try
		{
			$review = \IPS\Db::i()->select( '*', 'gd_dealer_ratings',
				[ 'id=? AND dealer_id=? AND member_id=? AND dispute_status=?',
					$id, $dealerId, (int) $member->member_id, 'pending_customer' ]
			)->first();
		}
		catch ( \Exception )
		{
			\IPS\Output::i()->redirect( $profileUrl );
			return;
		}

		/* Construct editors before save logic so upload POSTs are intercepted.
		   autoSaveKeys must match the render-side editors in manage(). */
		new \IPS\Helpers\Form\Editor(
			'gddealer_customer_response',
			'',
			FALSE,
			[
				'app'         => 'gddealer',
				'key'         => 'Responses',
				'autoSaveKey' => 'gddealer-customer-response-' . (int) $review['id'],
				'attachIds'   => [ (int) $review['id'], 5 ],
			],
			NULL,
			NULL,
			NULL,
			'editor_customer_response_' . (int) $review['id']
		);
		new \IPS\Helpers\Form\Editor(
			'gddealer_customer_evidence',
			'',
			FALSE,
			[
				'app'         => 'gddealer',
				'key'         => 'Responses',
				'autoSaveKey' => 'gddealer-customer-evidence-' . (int) $review['id'],
				'attachIds'   => [ (int) $review['id'], 6 ],
			],
			NULL,
			NULL,
			NULL,
			'editor_customer_evidence_' . (int) $review['id']
		);

		$responseRaw = (string) ( \IPS\Request::i()->gddealer_customer_response ?? '' );
		$evidenceRaw = (string) ( \IPS\Request::i()->gddealer_customer_evidence ?? '' );

		/* Editor HTML → IPS sanitizer. attachIds=[id,5] for customer_response,
		   [id,6] for customer_evidence. */
		$response = '';
		if ( trim( $responseRaw ) !== '' )
		{
			try
			{
				$response = \IPS\Text\Parser::parseStatic(
					$responseRaw,
					[ (int) $review['id'], 5 ],
					\IPS\Member::loggedIn(),
					'gddealer_Responses'
				);
			}
			catch ( \Exception )
			{
				$response = $responseRaw;
			}
		}
		$response = trim( $response );

		$evidence = '';
		if ( trim( $evidenceRaw ) !== '' )
		{
			try
			{
				$evidence = \IPS\Text\Parser::parseStatic(
					$evidenceRaw,
					[ (int) $review['id'], 6 ],
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

		if ( $response === '' )
		{
			\IPS\Output::i()->redirect( $profileUrl );
			return;
		}

		try
		{
			\IPS\Db::i()->update( 'gd_dealer_ratings', [
				'dispute_status'        => 'pending_admin',
				'customer_response'     => $response,
				'customer_evidence'     => $evidence !== '' ? $evidence : null,
				'customer_responded_at' => date( 'Y-m-d H:i:s' ),
			], [ 'id=?', (int) $review['id'] ] );
		}
		catch ( \Exception ) {}

		EventLogger::log( (int) $review['id'], 'customer_responded', 'customer', (int) $member->member_id, NULL );

		/* Claim attachments uploaded via the two customer-side editors. */
		try
		{
			\IPS\File::claimAttachments(
				'gddealer-customer-response-' . (int) $review['id'],
				(int) $review['id'],
				5
			);
		}
		catch ( \Exception ) {}

		try
		{
			\IPS\File::claimAttachments(
				'gddealer-customer-evidence-' . (int) $review['id'],
				(int) $review['id'],
				6
			);
		}
		catch ( \Exception ) {}

		/* Email + IPS notification to admins so they can resolve the dispute. */
		try
		{
			$adminUrl = (string) \IPS\Http\Url::internal(
				'app=gddealer&module=dealers&controller=dealers&do=disputes',
				'admin'
			);
			$dealerName = (string) ( $dealerRow['dealer_name'] ?? '' );

			foreach ( \IPS\Db::i()->select( '*', 'core_members',
				[ \IPS\Db::i()->in( 'member_group_id', [ 4 ] ) ],
				null, [ 0, 50 ]
			) as $m )
			{
				$admin = NULL;
				try
				{
					$admin = \IPS\Member::constructFromData( $m );
				}
				catch ( \Exception ) {}

				/* Email to this admin — own try/catch. */
				try
				{
					if ( $admin && $admin->member_id )
					{
						\IPS\Email::buildFromTemplate( 'gddealer', 'disputeAdminNotify', [
							'admin_url' => $adminUrl,
						], \IPS\Email::TYPE_TRANSACTIONAL )->send( $admin );
					}
				}
				catch ( \Exception ) {}

				/* IPS notification to this admin — completely independent. */
				try
				{
					if ( $admin && $admin->member_id )
					{
						$notification = new \IPS\Notification(
							\IPS\Application::load( 'gddealer' ),
							'dispute_admin_review',
							$admin,
							[ $admin ],
							[
								'dealer_name'   => $dealerName,
								'reviewer_name' => (string) $member->name,
							]
						);
						$notification->recipients->attach( $admin );
						$notification->send();
					}
				}
				catch ( \Exception ) {}
			}
		}
		catch ( \Exception ) {}

		/* Email + IPS notification to the dealer so they know the customer responded. */
		$dealerMember = NULL;
		try
		{
			$dealerMember = \IPS\Member::load( $dealerId );
			if ( $dealerMember->member_id )
			{
				\IPS\Email::buildFromTemplate( 'gddealer', 'disputeCustomerResponded', [
					'name'          => (string) $dealerMember->name,
					'reviewer_name' => (string) $member->name,
					'dealer_name'   => (string) ( $dealerRow['dealer_name'] ?? '' ),
					'review_url'    => (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=reviews' ),
				], \IPS\Email::TYPE_TRANSACTIONAL )->send( $dealerMember );
			}
		}
		catch ( \Exception ) {}

		try
		{
			$dealerMember = $dealerMember ?? \IPS\Member::load( $dealerId );
			if ( $dealerMember && $dealerMember->member_id )
			{
				$notification = new \IPS\Notification(
					\IPS\Application::load( 'gddealer' ),
					'dispute_customer_responded',
					$dealerMember,
					[ $dealerMember ],
					[
						'reviewer_id'   => (int) $member->member_id,
						'reviewer_name' => (string) $member->name,
						'dealer_name'   => (string) ( $dealerRow['dealer_name'] ?? '' ),
						'dealer_slug'   => (string) $slug,
						'review_id'     => (int) $id,
					]
				);
				$notification->recipients->attach( $dealerMember );
				$notification->send();
			}
		}
		catch ( \Exception ) {}

		\IPS\Output::i()->redirect( $profileUrl );
	}

	/**
	 * GET — render an edit form; POST — save updated ratings/body.
	 * Only the review author may edit, and only while the review is not
	 * under active dispute (pending_customer or pending_admin).
	 */
	protected function editReview(): void
	{
		$member = \IPS\Member::loggedIn();
		if ( !$member->member_id )
		{
			\IPS\Output::i()->error( 'node_error', '2GDD300/10', 403 );
			return;
		}

		$id   = (int) ( \IPS\Request::i()->id ?? 0 );
		$slug = trim( (string) ( \IPS\Request::i()->dealer_slug ?? '' ) );

		try
		{
			$review = \IPS\Db::i()->select( '*', 'gd_dealer_ratings',
				[ 'id=? AND member_id=?', $id, (int) $member->member_id ]
			)->first();
		}
		catch ( \Exception )
		{
			\IPS\Output::i()->error( 'node_error', '2GDD300/11', 404 );
			return;
		}

		$profileUrl = (string) \IPS\Http\Url::internal(
			'app=gddealer&module=dealers&controller=profile&dealer_slug=' . urlencode( $slug )
		);

		if ( in_array( (string) ( $review['dispute_status'] ?? '' ), [ 'pending_customer', 'pending_admin' ], TRUE ) )
		{
			\IPS\Output::i()->redirect( $profileUrl );
			return;
		}

		$dealerId   = (int) $review['dealer_id'];
		$dealerName = '';
		try
		{
			$dealerName = (string) \IPS\Db::i()->select( 'dealer_name', 'gd_dealer_feed_config', [ 'dealer_id=?', $dealerId ] )->first();
		}
		catch ( \Exception ) {}

		$bodyEditor = new \IPS\Helpers\Form\Editor(
			'gddealer_review_body',
			(string) ( $review['review_body'] ?? '' ),
			FALSE,
			[
				'app'         => 'gddealer',
				'key'         => 'Responses',
				'autoSaveKey' => 'gddealer-review-edit-' . (int) $id,
				'attachIds'   => [ (int) $id, 1 ],
			],
			NULL,
			NULL,
			NULL,
			'editor_review_edit_' . (int) $id
		);

		if ( \IPS\Request::i()->requestMethod() === 'POST' )
		{
			\IPS\Session::i()->csrfCheck();

			$pricing  = max( 1, min( 5, (int) ( \IPS\Request::i()->rating_pricing  ?? 0 ) ) );
			$shipping = max( 1, min( 5, (int) ( \IPS\Request::i()->rating_shipping ?? 0 ) ) );
			$service  = max( 1, min( 5, (int) ( \IPS\Request::i()->rating_service  ?? 0 ) ) );
			$bodyRaw  = (string) ( \IPS\Request::i()->gddealer_review_body ?? '' );

			$body = '';
			if ( trim( $bodyRaw ) !== '' )
			{
				try
				{
					$body = \IPS\Text\Parser::parseStatic(
						$bodyRaw,
						[ $id, 1 ],
						\IPS\Member::loggedIn(),
						'gddealer_Responses'
					);
				}
				catch ( \Exception )
				{
					$body = $bodyRaw;
				}
			}

			try
			{
				\IPS\Db::i()->update( 'gd_dealer_ratings', [
					'rating_pricing'  => $pricing,
					'rating_shipping' => $shipping,
					'rating_service'  => $service,
					'review_body'     => $body !== '' ? $body : null,
				], [ 'id=? AND member_id=?', $id, (int) $member->member_id ] );
			}
			catch ( \Exception ) {}

			try
			{
				\IPS\File::claimAttachments(
					'gddealer-review-edit-' . (int) $id,
					(int) $id,
					1
				);
			}
			catch ( \Exception ) {}

			$dealerId = (int) $review['dealer_id'];
			try
			{
				$dealerRow = \IPS\Db::i()->select( '*', 'gd_dealer_feed_config',
					[ 'dealer_id=?', $dealerId ] )->first();
			}
			catch ( \Exception )
			{
				$dealerRow = NULL;
			}

			$dealerMember = NULL;
			try
			{
				$dealerMember = \IPS\Member::load( $dealerId );
				if ( $dealerMember->member_id && $dealerRow )
				{
					\IPS\Email::buildFromTemplate( 'gddealer', 'updatedDealerReview', [
						'name'          => $dealerMember->name,
						'reviewer_name' => (string) $member->name,
						'dealer_name'   => (string) $dealerRow['dealer_name'],
						'review_url'    => (string) \IPS\Http\Url::internal(
							'app=gddealer&module=dealers&controller=dashboard&do=reviews'
						),
					], \IPS\Email::TYPE_TRANSACTIONAL )->send( $dealerMember );
				}
			}
			catch ( \Exception ) {}

			try
			{
				$dealerMember = $dealerMember ?? \IPS\Member::load( $dealerId );
				if ( $dealerMember && $dealerMember->member_id && $dealerRow )
				{
					$notification = new \IPS\Notification(
						\IPS\Application::load( 'gddealer' ),
						'updated_dealer_review',
						$dealerMember,
						[ $dealerMember ],
						[
							'reviewer_id'   => (int) $member->member_id,
							'reviewer_name' => (string) $member->name,
							'dealer_name'   => (string) $dealerRow['dealer_name'],
						]
					);
					$notification->recipients->attach( $dealerMember );
					$notification->send();
				}
			}
			catch ( \Exception ) {}

			\IPS\Output::i()->redirect( $profileUrl );
			return;
		}

		$pricingNow  = max( 0, min( 5, (int) $review['rating_pricing'] ) );
		$shippingNow = max( 0, min( 5, (int) $review['rating_shipping'] ) );
		$serviceNow  = max( 0, min( 5, (int) $review['rating_service'] ) );

		$bodyEditorHtml = (string) $bodyEditor;

		$reviewData = [
			'id'              => (int) $review['id'],
			'rating_pricing'  => $pricingNow,
			'rating_shipping' => $shippingNow,
			'rating_service'  => $serviceNow,
			'review_body'     => (string) ( $review['review_body'] ?? '' ),
			'body_editor_html'=> $bodyEditorHtml,
			'stars_pricing'   => str_repeat( '★', $pricingNow ) . str_repeat( '☆', 5 - $pricingNow ),
			'stars_shipping'  => str_repeat( '★', $shippingNow ) . str_repeat( '☆', 5 - $shippingNow ),
			'stars_service'   => str_repeat( '★', $serviceNow ) . str_repeat( '☆', 5 - $serviceNow ),
			'dispute_status'  => (string) ( $review['dispute_status'] ?? 'none' ),
			'dealer_name'     => $dealerName,
		];

		$editUrl = (string) \IPS\Http\Url::internal(
			'app=gddealer&module=dealers&controller=profile&do=editReview&dealer_slug=' . urlencode( $slug ) . '&id=' . $id
		)->csrf();

		$csrfKey = (string) \IPS\Session::i()->csrfKey;

		try {
			\IPS\Output::i()->cssFiles = array_merge(
				\IPS\Output::i()->cssFiles,
				\IPS\Theme::i()->css( 'dealer.css', 'gddealer', 'front' )
			);
		} catch ( \Throwable ) {}

		\IPS\Output::i()->title  = 'Edit Your Review';
		\IPS\Output::i()->output = $this->themeVars() . \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )
			->editReview( $reviewData, $editUrl, $profileUrl, $csrfKey );
	}
}

class profile extends _profile {}
