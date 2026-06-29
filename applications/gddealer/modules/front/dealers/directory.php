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
		$perPage = max( 1, (int) ( \IPS\Settings::i()->gddealer_dir_per_page ?: 24 ) );
		$page    = max( 1, (int) ( \IPS\Request::i()->page ?? 1 ) );
		$offset  = ( $page - 1 ) * $perPage;

		$defaultSort = (string) ( \IPS\Settings::i()->gddealer_dir_default_sort ?: 'featured' );

		$sort       = (string) ( \IPS\Request::i()->sort ?? $defaultSort );
		$search     = trim( str_replace( '+', ' ', (string) ( \IPS\Request::i()->search ?? '' ) ) );
		$stateParam = strtoupper( trim( (string) ( \IPS\Request::i()->state ?? '' ) ) );
		$minRating  = (float) ( \IPS\Request::i()->min_rating ?? 0 );
		if ( $minRating < 0 ) { $minRating = 0; }
		if ( $minRating > 5 ) { $minRating = 5; }

		$validSorts = [ 'featured', 'rating', 'listings', 'newest', 'alpha' ];
		if ( !in_array( $sort, $validSorts, true ) )
		{
			$sort = in_array( $defaultSort, $validSorts, true ) ? $defaultSort : 'featured';
		}

		$daySeed = (int) date( 'Ymd' );
		$orderBy = match( $sort ) {
			'rating'   => 'avg_overall DESC, total_reviews DESC',
			'listings' => 'listing_count DESC',
			'newest'   => 'MAX(d.created_at) DESC',
			'alpha'    => 'MAX(d.dealer_name) ASC',
			default    => 'RAND(' . $daySeed . ')',
		};

		/* Build WHERE as a single combined condition string + binds for native select(). */
		$whereSql   = [ 'd.active=?', 'd.directory_listed=?' ];
		$whereBinds = [ 1, 1 ];
		if ( $search !== '' )
		{
			$whereSql[]   = 'd.dealer_name LIKE ?';
			$whereBinds[] = '%' . $search . '%';
		}
		if ( $stateParam !== '' && preg_match( '/^[A-Z]{2}$/', $stateParam ) )
		{
			$whereSql[]   = 'd.address_state=?';
			$whereBinds[] = $stateParam;
		}
		$whereArg = array_merge( [ implode( ' AND ', $whereSql ) ], $whereBinds );

		$havingArg = null;
		if ( $minRating > 0 )
		{
			$havingArg = [
				'COALESCE(AVG((r.rating_pricing + r.rating_shipping + r.rating_service) / 3), 0) >= ?',
				$minRating
			];
		}

		$total = 0;
		try
		{
			if ( $minRating > 0 )
			{
				/* min_rating filter operates on the AVG aggregate — count by iterating
				   the grouped+having result rather than COUNT(*) over an aggregated set. */
				$countQuery = \IPS\Db::i()->select(
					'd.dealer_id',
					[ 'gd_dealer_feed_config', 'd' ],
					$whereArg,
					null,
					null,
					'd.dealer_id',
					$havingArg
				)->join(
					[ 'gd_dealer_ratings', 'r' ],
					"r.dealer_id=d.dealer_id AND r.status='approved'",
					'LEFT'
				);
				$total = iterator_count( $countQuery );
			}
			else
			{
				$total = (int) \IPS\Db::i()->select(
					'COUNT(*)',
					[ 'gd_dealer_feed_config', 'd' ],
					$whereArg
				)->first();
			}
		}
		catch ( \Throwable ) {}

		$dealers = [];
		try
		{
			$rows = \IPS\Db::i()->select(
				"MAX(d.dealer_id) AS dealer_id,
				 MAX(d.dealer_name) AS dealer_name,
				 MAX(d.dealer_slug) AS dealer_slug,
				 MAX(d.created_at) AS created_at,
				 MAX(d.address_city) AS address_city,
				 MAX(d.address_state) AS address_state,
				 MAX(d.address_public) AS address_public,
				 MAX(d.logo_url) AS logo_url,
				 COUNT(DISTINCT l.id) AS listing_count,
				 COUNT(DISTINCT r.id) AS total_reviews,
				 COALESCE(AVG((r.rating_pricing + r.rating_shipping + r.rating_service) / 3), 0) AS avg_overall",
				[ 'gd_dealer_feed_config', 'd' ],
				$whereArg,
				$orderBy,
				[ $offset, $perPage ],
				'd.dealer_id',
				$havingArg
			)->join(
				[ 'gd_dealer_listings', 'l' ],
				"l.dealer_id=d.dealer_id AND l.listing_status='active'",
				'LEFT'
			)->join(
				[ 'gd_dealer_ratings', 'r' ],
				"r.dealer_id=d.dealer_id AND r.status='approved'",
				'LEFT'
			);

			foreach ( $rows as $row )
			{
				try
				{
					$avg = round( (float) $row['avg_overall'], 1 );
					$ipsMember = \IPS\Member::load( (int) $row['dealer_id'] );

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
						catch ( \Throwable ) {}
					}

					$addressPublic = (int) ( $row['address_public'] ?? 1 ) === 1;
					$dealers[] = [
						'dealer_id'     => (int) $row['dealer_id'],
						'dealer_name'   => (string) $row['dealer_name'],
						'dealer_slug'   => (string) $row['dealer_slug'],
						'logo_url'      => (string) ( $row['logo_url'] ?? '' ),
						'avatar'        => (string) ( $ipsMember->get_photo( true, false ) ?? '' ),
						'listing_count' => (int) $row['listing_count'],
						'total_reviews' => (int) $row['total_reviews'],
						'avg_overall'   => $avg,
						'rating_color'  => $ratingColor,
						'member_since'  => $ipsMember->joined ? $ipsMember->joined->format( 'M Y' ) : '',
						'state'         => (string) ( $row['address_state'] ?? '' ),
						'city'          => $addressPublic ? (string) ( $row['address_city'] ?? '' ) : '',
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
				catch ( \Throwable $e )
				{
					/* Skip dealers with no valid member record / bad data — never
					   let one bad row blank the whole directory. */
					continue;
				}
			}
		}
		catch ( \Throwable ) {}

		$states      = [];
		$stateCounts = [];
		try
		{
			foreach ( \IPS\Db::i()->select(
				'address_state, COUNT(*) AS c',
				'gd_dealer_feed_config',
				[ 'active=? AND directory_listed=? AND address_state IS NOT NULL AND address_state != ?', 1, 1, '' ],
				'address_state ASC',
				null,
				'address_state'
			) as $row )
			{
				$code = strtoupper( (string) $row['address_state'] );
				if ( $code === '' ) { continue; }
				$states[]            = $code;
				$stateCounts[ $code ] = (int) $row['c'];
			}
		}
		catch ( \Throwable ) {}

		$pagination = '';
		if ( $total > $perPage )
		{
			$pagination = (string) \IPS\Theme::i()->getTemplate( 'global', 'core', 'global' )->pagination(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=directory', 'front', 'dealers_directory' ),
				(int) ceil( $total / $perPage ),
				$page,
				$perPage
			);
		}

		$joinUrl      = (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=join', 'front', 'dealers_join' );
		$directoryUrl = (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=directory', 'front', 'dealers_directory' );

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gddealer_directory_title' );
		$stateList = [
			'AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA',
			'ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR',
			'PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY'
		];

		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->dealerDirectory(
			$dealers, $total, $page, $perPage, $pagination,
			$sort, $search, $stateParam, $minRating, $states, $stateCounts, $stateList,
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
