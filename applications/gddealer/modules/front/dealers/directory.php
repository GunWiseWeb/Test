<?php
/**
 * @brief       GD Dealer Manager — Dealer Directory
 * @package     IPS Community Suite
 * @subpackage  GD Dealer Manager
 * @since       18 Apr 2026
 *
 * Public listing of all active dealers at /dealers. Supports tier filtering,
 * search, sort by rating/listings/newest/alpha, and IPS follow toggle.
 */

namespace IPS\gddealer\modules\front\dealers;

use IPS\gddealer\Dealer\Dealer;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _directory extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		parent::execute();
	}

	protected function manage(): void
	{
		$member  = \IPS\Member::loggedIn();
		$perPage = 24;
		$page    = max( 1, (int) ( \IPS\Request::i()->page ?? 1 ) );
		$offset  = ( $page - 1 ) * $perPage;

		$tier   = (string) ( \IPS\Request::i()->tier   ?? '' );
		$sort   = (string) ( \IPS\Request::i()->sort   ?? 'rating' );
		$search = trim( str_replace( '+', ' ', (string) ( \IPS\Request::i()->search ?? '' ) ) );

		$validTiers = [ 'founding', 'basic', 'pro', 'enterprise' ];
		if ( $tier !== '' && !in_array( $tier, $validTiers, true ) )
		{
			$tier = '';
		}

		$validSorts = [ 'rating', 'listings', 'newest', 'alpha' ];
		if ( !in_array( $sort, $validSorts, true ) )
		{
			$sort = 'rating';
		}

		$whereMain  = [ [ 'd.active=?', 1 ] ];
		$whereCount = [ [ 'active=?', 1 ] ];
		if ( $tier !== '' )
		{
			$whereMain[]  = [ 'd.subscription_tier=?', $tier ];
			$whereCount[] = [ 'subscription_tier=?', $tier ];
		}
		if ( $search !== '' )
		{
			$whereMain[]  = [ 'd.dealer_name LIKE ?', '%' . $search . '%' ];
			$whereCount[] = [ 'dealer_name LIKE ?', '%' . $search . '%' ];
		}

		$orderBy = match( $sort ) {
			'listings' => 'listing_count DESC',
			'newest'   => 'd.created_at DESC',
			'alpha'    => 'd.dealer_name ASC',
			default    => 'avg_overall DESC, total_reviews DESC',
		};

		$total = 0;
		try
		{
			$total = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_dealer_feed_config', $whereCount )->first();
		}
		catch ( \Exception ) {}

		$dealers = [];
		try
		{
			foreach ( \IPS\Db::i()->select(
				'd.*, COUNT(DISTINCT l.id) as listing_count, COUNT(DISTINCT r.id) as total_reviews, COALESCE(AVG((r.rating_pricing + r.rating_shipping + r.rating_service) / 3), 0) as avg_overall',
				[ 'gd_dealer_feed_config', 'd' ],
				$whereMain,
				$orderBy,
				[ $offset, $perPage ],
				'd.dealer_id'
			)->join(
				[ 'gd_dealer_listings', 'l' ],
				"l.dealer_id = d.dealer_id AND l.listing_status = 'active'",
				'LEFT'
			)->join(
				[ 'gd_dealer_ratings', 'r' ],
				"r.dealer_id = d.dealer_id AND r.status = 'approved'",
				'LEFT'
			) as $row )
			{
				$avg = round( (float) $row['avg_overall'], 1 );
				$ipsMember = \IPS\Member::load( (int) $row['dealer_id'] );

				$isFoundingMember = !empty( $row['is_founding_member'] );
				if ( $isFoundingMember )
				{
					$tierColor = (string) ( \IPS\Settings::i()->gddealer_founding_badge_color ?: '#b45309' );
					$tierLabel = 'Founder';
					$tierKey   = 'founding';
				}
				else
				{
					$tierColor = match( $row['subscription_tier'] ) {
						'founding'   => (string) ( \IPS\Settings::i()->gddealer_founding_badge_color   ?: '#b45309' ),
						'pro'        => (string) ( \IPS\Settings::i()->gddealer_pro_badge_color        ?: '#2563eb' ),
						'enterprise' => (string) ( \IPS\Settings::i()->gddealer_enterprise_badge_color ?: '#7c3aed' ),
						default      => (string) ( \IPS\Settings::i()->gddealer_basic_badge_color      ?: '#6b7280' ),
					};
					$tierLabel = ucfirst( (string) $row['subscription_tier'] );
					$tierKey   = (string) $row['subscription_tier'];
				}

				$ratingColor = match( true ) {
					$avg >= 4.0 => '#16a34a',
					$avg >= 3.0 => '#d97706',
					$avg > 0    => '#dc2626',
					default     => '#9ca3af',
				};

				$isFollowing = false;
				if ( $member->member_id )
				{
					try
					{
						$isFollowing = (bool) \IPS\Db::i()->select( 'COUNT(*)', 'core_follow', [
							'follow_app=? AND follow_area=? AND follow_rel_id=? AND follow_member_id=?',
							'gddealer', 'dealer', (int) $row['dealer_id'], (int) $member->member_id,
						] )->first();
					}
					catch ( \Exception ) {}
				}

				$dealers[] = [
					'dealer_id'     => (int) $row['dealer_id'],
					'dealer_name'   => (string) $row['dealer_name'],
					'dealer_slug'   => (string) $row['dealer_slug'],
					'tier'          => $tierKey,
					'tier_label'    => $tierLabel,
					'tier_color'    => $tierColor,
					'is_founding'   => $isFoundingMember,
					'avatar'        => (string) ( $ipsMember->get_photo( true, false ) ?? '' ),
					'listing_count' => (int) $row['listing_count'],
					'total_reviews' => (int) $row['total_reviews'],
					'avg_overall'   => $avg,
					'rating_color'  => $ratingColor,
					'member_since'  => $ipsMember->joined ? $ipsMember->joined->format( 'M Y' ) : '',
					'profile_url'   => (string) \IPS\Http\Url::internal(
						'app=gddealer&module=dealers&controller=profile&dealer_slug=' . urlencode( (string) $row['dealer_slug'] ),
						'front', 'dealers_profile', (string) $row['dealer_slug']
					),
					'follow_url'    => (string) \IPS\Http\Url::internal(
						'app=gddealer&module=dealers&controller=directory&do=follow&id=' . (int) $row['dealer_id'],
						'front', 'dealers_directory'
					)->csrf(),
					'is_following'  => $isFollowing,
				];
			}
		}
		catch ( \Exception ) {}

		$pagination = '';
		if ( $total > $perPage )
		{
			$pagination = (string) \IPS\Theme::i()->getTemplate( 'global', 'core', 'front' )->pagination(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=directory', 'front', 'dealers_directory' ),
				(int) ceil( $total / $perPage ),
				$page,
				$perPage
			);
		}

		$joinUrl      = (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=join', 'front', 'dealers_join' );
		$directoryUrl = (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=directory', 'front', 'dealers_directory' );

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gddealer_directory_title' );
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->dealerDirectory(
			$dealers, $total, $page, $perPage, $pagination,
			$tier, $sort, $search,
			$member->member_id ? true : false,
			$joinUrl, $directoryUrl
		);
	}

	protected function follow(): void
	{
		\IPS\Session::i()->csrfCheck();
		$member = \IPS\Member::loggedIn();
		if ( !$member->member_id )
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=core&module=system&controller=login', 'front', 'login' )
			);
			return;
		}

		$dealerId = (int) ( \IPS\Request::i()->id ?? 0 );
		if ( $dealerId <= 0 )
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=directory', 'front', 'dealers_directory' )
			);
			return;
		}

		try
		{
			Dealer::load( $dealerId );
		}
		catch ( \OutOfRangeException )
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=directory', 'front', 'dealers_directory' )
			);
			return;
		}

		try
		{
			$existing = \IPS\Db::i()->select( 'follow_id', 'core_follow', [
				'follow_app=? AND follow_area=? AND follow_rel_id=? AND follow_member_id=?',
				'gddealer', 'dealer', $dealerId, (int) $member->member_id,
			] )->first();

			\IPS\Db::i()->delete( 'core_follow', [ 'follow_id=?', $existing ] );
			$msg = 'gddealer_unfollowed';
		}
		catch ( \UnderflowException )
		{
			\IPS\Db::i()->insert( 'core_follow', [
				'follow_id'          => md5( 'gddealer_dealer_' . $dealerId . '_' . $member->member_id ),
				'follow_app'         => 'gddealer',
				'follow_area'        => 'dealer',
				'follow_rel_id'      => $dealerId,
				'follow_member_id'   => (int) $member->member_id,
				'follow_is_anon'     => 0,
				'follow_added'       => time(),
				'follow_notify_do'   => 1,
				'follow_notify_meta' => '',
				'follow_notify_freq' => 'immediate',
				'follow_notify_sent' => 0,
				'follow_visible'     => 1,
			] );
			$msg = 'gddealer_followed';
		}

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=directory', 'front', 'dealers_directory' ),
			$msg
		);
	}
}

class directory extends _directory {}
