<?php
/**
 * @brief  GD Deals — Custom Leaderboard (deals/dealers/community)
 *
 * Six time-windowed boards alongside the IPS native leaderboard (which
 * stays for forums reputation).
 *
 *   1. Top Deals          (upvotes - downvotes + heat, tie-break clicks)
 *   2. Best Savings       (highest discount_pct)
 *   3. Most Clicked       (highest click_count)
 *   4. Top Dealers        (rating + clicks + listings composite)
 *   5. Best Value Dealers (times-cheapest from latest rank snapshot)
 *   6. Top Members        (most approved community posts)
 *
 * Windows: This Week (7d) / This Month (30d) / All Time. Uses native
 * \IPS\Db::i()->select(...)->join(...) — no raw preparedQuery for row
 * iteration.
 */

namespace IPS\gddeals\modules\front\leaderboard;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _leaderboard extends \IPS\Dispatcher\Controller
{
	const BOARDS = [
		'top_deals'    => 'gddeals_lb_show_top_deals',
		'best_savings' => 'gddeals_lb_show_best_savings',
		'most_clicked' => 'gddeals_lb_show_most_clicked',
		'top_dealers'  => 'gddeals_lb_show_top_dealers',
		'best_value'   => 'gddeals_lb_show_best_value',
		'top_members'  => 'gddeals_lb_show_top_members',
	];

	const WINDOWS = [ 'week' => 7, 'month' => 30, 'all' => 0 ];

	protected function manage(): void
	{
		$S = \IPS\Settings::i();

		/* Master switch */
		if ( $S->gddeals_lb_enabled !== null && $S->gddeals_lb_enabled !== '' && (int) $S->gddeals_lb_enabled === 0 )
		{
			\IPS\Output::i()->error( 'gddeals_lb_disabled', '2GD400/1', 404 );
			return;
		}

		/* Enabled boards (skip ones the admin turned off). */
		$enabledBoards = [];
		foreach ( self::BOARDS as $board => $settingKey )
		{
			$v = $S->$settingKey;
			if ( $v === null || $v === '' || (int) $v === 1 )
			{
				$enabledBoards[] = $board;
			}
		}
		if ( empty( $enabledBoards ) )
		{
			$enabledBoards[] = 'top_deals';
		}

		$defaultBoard  = $enabledBoards[0];
		$requestedBoard = (string) ( \IPS\Request::i()->board ?? '' );
		$activeBoard   = in_array( $requestedBoard, $enabledBoards, true ) ? $requestedBoard : $defaultBoard;

		$defaultWindow  = (string) ( $S->gddeals_lb_default_window ?: 'month' );
		$requestedWindow = (string) ( \IPS\Request::i()->window ?? '' );
		$activeWindow   = array_key_exists( $requestedWindow, self::WINDOWS ) ? $requestedWindow : $defaultWindow;
		if ( !array_key_exists( $activeWindow, self::WINDOWS ) ) { $activeWindow = 'month'; }

		$perBoard = (int) ( $S->gddeals_lb_per_board ?: 25 );
		if ( $perBoard < 1 ) { $perBoard = 25; }
		$excludeAuto = ( $S->gddeals_lb_members_exclude_auto === null || $S->gddeals_lb_members_exclude_auto === '' )
			? true : (bool) (int) $S->gddeals_lb_members_exclude_auto;

		$windowDays = self::WINDOWS[ $activeWindow ];

		$rows = [];
		try
		{
			$rows = match ( $activeBoard ) {
				'top_deals'    => self::queryTopDeals( $perBoard, $windowDays ),
				'best_savings' => self::queryBestSavings( $perBoard, $windowDays ),
				'most_clicked' => self::queryMostClicked( $perBoard, $windowDays ),
				'top_dealers'  => self::queryTopDealers( $perBoard, $windowDays ),
				'best_value'   => self::queryBestValueDealers( $perBoard ),
				'top_members'  => self::queryTopMembers( $perBoard, $windowDays, $excludeAuto ),
				default        => [],
			};
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'leaderboard ' . $activeBoard . ': ' . $e->getMessage(), 'gddeals_leaderboard' ); } catch ( \Throwable ) {}
		}

		$boardUrls   = [];
		$windowUrls  = [];
		foreach ( $enabledBoards as $b )
		{
			$boardUrls[ $b ] = (string) \IPS\Http\Url::internal( 'app=gddeals&module=leaderboard&controller=leaderboard&board=' . $b . '&window=' . $activeWindow, 'front', 'gddeals_leaderboard' );
		}
		foreach ( array_keys( self::WINDOWS ) as $w )
		{
			$windowUrls[ $w ] = (string) \IPS\Http\Url::internal( 'app=gddeals&module=leaderboard&controller=leaderboard&board=' . $activeBoard . '&window=' . $w, 'front', 'gddeals_leaderboard' );
		}

		\IPS\Output::i()->cssFiles = array_merge( \IPS\Output::i()->cssFiles, \IPS\Theme::i()->css( 'deals.css', 'gddeals', 'front' ) );

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gddeals_lb_title' );
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'leaderboard', 'gddeals', 'front' )->board(
			$rows, $activeBoard, $activeWindow, $enabledBoards, $boardUrls, $windowUrls
		);
	}

	/* ---------------------- Queries ---------------------- */

	protected static function queryTopDeals( int $limit, int $windowDays ): array
	{
		$where = [ [ 'approved=?', 1 ], [ 'expired=?', 0 ] ];
		if ( $windowDays > 0 )
		{
			$where[] = [ 'posted_at >= ?', time() - ( $windowDays * 86400 ) ];
		}

		$out = [];
		try
		{
			foreach ( \IPS\Db::i()->select(
				'id, title, image_url, dealer_id, upc, deal_price, original_price, discount_pct, upvotes, downvotes, heat_score, click_count, posted_at, source_badge, retailer_name',
				'gd_deal_posts',
				$where,
				'(upvotes - downvotes + heat_score) DESC, click_count DESC, posted_at DESC',
				$limit
			) as $r )
			{
				$out[] = self::shapeDealRow( $r );
			}
		}
		catch ( \Throwable $e ) { try { \IPS\Log::log( 'queryTopDeals: ' . $e->getMessage(), 'gddeals_leaderboard' ); } catch ( \Throwable ) {} }
		return $out;
	}

	protected static function queryBestSavings( int $limit, int $windowDays ): array
	{
		$where = [ [ 'approved=?', 1 ], [ 'expired=?', 0 ], [ 'discount_pct>?', 0 ] ];
		if ( $windowDays > 0 )
		{
			$where[] = [ 'posted_at >= ?', time() - ( $windowDays * 86400 ) ];
		}

		$out = [];
		try
		{
			foreach ( \IPS\Db::i()->select(
				'id, title, image_url, dealer_id, upc, deal_price, original_price, discount_pct, upvotes, downvotes, heat_score, click_count, posted_at, source_badge, retailer_name',
				'gd_deal_posts',
				$where,
				'discount_pct DESC, posted_at DESC',
				$limit
			) as $r )
			{
				$out[] = self::shapeDealRow( $r );
			}
		}
		catch ( \Throwable $e ) { try { \IPS\Log::log( 'queryBestSavings: ' . $e->getMessage(), 'gddeals_leaderboard' ); } catch ( \Throwable ) {} }
		return $out;
	}

	protected static function queryMostClicked( int $limit, int $windowDays ): array
	{
		$where = [ [ 'approved=?', 1 ], [ 'expired=?', 0 ], [ 'click_count>?', 0 ] ];
		if ( $windowDays > 0 )
		{
			$where[] = [ 'posted_at >= ?', time() - ( $windowDays * 86400 ) ];
		}

		$out = [];
		try
		{
			foreach ( \IPS\Db::i()->select(
				'id, title, image_url, dealer_id, upc, deal_price, original_price, discount_pct, upvotes, downvotes, heat_score, click_count, posted_at, source_badge, retailer_name',
				'gd_deal_posts',
				$where,
				'click_count DESC, posted_at DESC',
				$limit
			) as $r )
			{
				$out[] = self::shapeDealRow( $r );
			}
		}
		catch ( \Throwable $e ) { try { \IPS\Log::log( 'queryMostClicked: ' . $e->getMessage(), 'gddeals_leaderboard' ); } catch ( \Throwable ) {} }
		return $out;
	}

	protected static function queryTopDealers( int $limit, int $windowDays ): array
	{
		/* Window for click totals only. Listings + ratings are aggregated all-time. */
		$clickWhere = $windowDays > 0
			? "click_date >= ( CURDATE() - INTERVAL {$windowDays} DAY )"
			: "1=1";

		$prefix = \IPS\Db::i()->prefix;
		$sql = "SELECT
				d.dealer_id, MAX(d.dealer_name) AS dealer_name, MAX(d.dealer_slug) AS dealer_slug,
				COALESCE(AVG((r.rating_pricing + r.rating_shipping + r.rating_service) / 3), 0) AS avg_rating,
				COUNT(DISTINCT r.id) AS review_count,
				COUNT(DISTINCT l.id) AS listing_count,
				COALESCE((
					SELECT SUM(click_count) FROM `{$prefix}gd_click_daily` cd
					WHERE cd.dealer_id = d.dealer_id AND {$clickWhere}
				), 0) AS click_total
			FROM `{$prefix}gd_dealer_feed_config` d
			LEFT JOIN `{$prefix}gd_dealer_listings` l
			  ON l.dealer_id = d.dealer_id AND l.listing_status = 'active'
			LEFT JOIN `{$prefix}gd_dealer_ratings` r
			  ON r.dealer_id = d.dealer_id AND r.status = 'approved'
			WHERE d.active = 1
			GROUP BY d.dealer_id
			ORDER BY ( COALESCE(AVG((r.rating_pricing + r.rating_shipping + r.rating_service) / 3), 0) * 20 + LEAST(COUNT(DISTINCT l.id), 100) + LEAST(COALESCE((SELECT SUM(click_count) FROM `{$prefix}gd_click_daily` cd WHERE cd.dealer_id = d.dealer_id AND {$clickWhere}), 0), 500) ) DESC,
			         avg_rating DESC, click_total DESC
			LIMIT " . (int) $limit;

		$out = [];
		try
		{
			$result = \IPS\Db::i()->query( $sql );
			while ( $r = $result->fetch_assoc() )
			{
				$out[] = self::shapeDealerRow( $r );
			}
		}
		catch ( \Throwable $e ) { try { \IPS\Log::log( 'queryTopDealers: ' . $e->getMessage(), 'gddeals_leaderboard' ); } catch ( \Throwable ) {} }
		return $out;
	}

	protected static function queryBestValueDealers( int $limit ): array
	{
		$prefix = \IPS\Db::i()->prefix;
		/* Find latest snapshot_date; rank dealers by times_cheapest within it. */
		$latest = null;
		try { $latest = (string) \IPS\Db::i()->select( 'MAX(snapshot_date)', 'gd_dealer_rank_snapshot' )->first(); }
		catch ( \Throwable ) {}

		if ( !$latest )
		{
			return [];
		}

		$sql = "SELECT
				d.dealer_id, MAX(d.dealer_name) AS dealer_name, MAX(d.dealer_slug) AS dealer_slug,
				SUM( CASE WHEN s.rank_position = 1 THEN 1 ELSE 0 END ) AS times_cheapest,
				COUNT(s.id) AS ranked_items,
				AVG(s.price_delta_pct) AS avg_delta_pct
			FROM `{$prefix}gd_dealer_rank_snapshot` s
			JOIN `{$prefix}gd_dealer_feed_config` d ON d.dealer_id = s.dealer_id AND d.active = 1
			WHERE s.snapshot_date = '" . \IPS\Db::i()->real_escape_string( $latest ) . "'
			GROUP BY d.dealer_id
			ORDER BY times_cheapest DESC, avg_delta_pct ASC
			LIMIT " . (int) $limit;

		$out = [];
		try
		{
			$result = \IPS\Db::i()->query( $sql );
			while ( $r = $result->fetch_assoc() )
			{
				$out[] = [
					'kind'           => 'best_value',
					'dealer_id'      => (int) $r['dealer_id'],
					'name'           => (string) ( $r['dealer_name'] ?? '' ),
					'profile_url'    => self::dealerUrl( (string) ( $r['dealer_slug'] ?? '' ) ),
					'times_cheapest' => (int) $r['times_cheapest'],
					'ranked_items'   => (int) $r['ranked_items'],
					'avg_delta_pct'  => round( (float) $r['avg_delta_pct'], 2 ),
				];
			}
		}
		catch ( \Throwable $e ) { try { \IPS\Log::log( 'queryBestValueDealers: ' . $e->getMessage(), 'gddeals_leaderboard' ); } catch ( \Throwable ) {} }
		return $out;
	}

	protected static function queryTopMembers( int $limit, int $windowDays, bool $excludeAuto ): array
	{
		$where = [ [ 'approved=?', 1 ], [ 'member_id>?', 0 ] ];
		if ( $excludeAuto )
		{
			$where[] = [ 'source_badge!=?', 'auto' ];
		}
		if ( $windowDays > 0 )
		{
			$where[] = [ 'posted_at >= ?', time() - ( $windowDays * 86400 ) ];
		}

		$out = [];
		try
		{
			foreach ( \IPS\Db::i()->select(
				'member_id, MAX(author_name) AS author_name, COUNT(*) AS post_count',
				'gd_deal_posts',
				$where,
				'post_count DESC, member_id ASC',
				$limit,
				'member_id'
			) as $r )
			{
				$memberId = (int) $r['member_id'];
				$photo    = '';
				try
				{
					$m     = \IPS\Member::load( $memberId );
					$photo = (string) ( $m->photo ?? '' );
				}
				catch ( \Throwable ) {}
				$out[] = [
					'kind'        => 'member',
					'member_id'   => $memberId,
					'name'        => (string) ( $r['author_name'] ?? '' ),
					'post_count'  => (int) $r['post_count'],
					'profile_url' => (string) \IPS\Http\Url::internal( 'app=core&module=members&controller=profile&id=' . $memberId, 'front', 'profile' ),
					'photo'       => $photo,
				];
			}
		}
		catch ( \Throwable $e ) { try { \IPS\Log::log( 'queryTopMembers: ' . $e->getMessage(), 'gddeals_leaderboard' ); } catch ( \Throwable ) {} }
		return $out;
	}

	/* ---------------------- Shapers ---------------------- */

	protected static function shapeDealRow( array $r ): array
	{
		$id    = (int) $r['id'];
		$slug  = self::dealSlug( (string) ( $r['title'] ?? '' ) );
		$url   = (string) \IPS\Http\Url::internal( 'app=gddeals&module=deals&controller=view&id=' . $id, 'front', 'gddeals_view', [ $id, $slug ] );
		$score = (int) ( $r['upvotes'] ?? 0 ) - (int) ( $r['downvotes'] ?? 0 );

		return [
			'kind'         => 'deal',
			'id'           => $id,
			'title'        => (string) ( $r['title'] ?? '' ),
			'image_url'    => (string) ( $r['image_url'] ?? '' ),
			'url'          => $url,
			'dealer_id'    => (int) ( $r['dealer_id'] ?? 0 ),
			'retailer'     => (string) ( $r['retailer_name'] ?? '' ),
			'deal_price'   => $r['deal_price'] !== null ? (float) $r['deal_price'] : null,
			'original'     => $r['original_price'] !== null ? (float) $r['original_price'] : null,
			'discount_pct' => (float) ( $r['discount_pct'] ?? 0 ),
			'upvotes'      => (int) ( $r['upvotes'] ?? 0 ),
			'downvotes'    => (int) ( $r['downvotes'] ?? 0 ),
			'score'        => $score,
			'heat_score'   => (int) ( $r['heat_score'] ?? 0 ),
			'click_count'  => (int) ( $r['click_count'] ?? 0 ),
			'source_badge' => (string) ( $r['source_badge'] ?? '' ),
		];
	}

	protected static function shapeDealerRow( array $r ): array
	{
		return [
			'kind'         => 'dealer',
			'dealer_id'    => (int) $r['dealer_id'],
			'name'         => (string) ( $r['dealer_name'] ?? '' ),
			'profile_url'  => self::dealerUrl( (string) ( $r['dealer_slug'] ?? '' ) ),
			'avg_rating'   => round( (float) ( $r['avg_rating'] ?? 0 ), 2 ),
			'review_count' => (int) ( $r['review_count'] ?? 0 ),
			'listing_count' => (int) ( $r['listing_count'] ?? 0 ),
			'click_total'  => (int) ( $r['click_total'] ?? 0 ),
		];
	}

	protected static function dealerUrl( string $slug ): string
	{
		if ( $slug === '' ) { return ''; }
		return (string) \IPS\Http\Url::internal(
			'app=gddealer&module=dealers&controller=profile&dealer_slug=' . urlencode( $slug ),
			'front', 'dealers_profile', $slug
		);
	}

	protected static function dealSlug( string $title ): string
	{
		$s = strtolower( $title );
		$s = preg_replace( '/[^a-z0-9]+/', '-', $s ) ?? '';
		return trim( substr( $s, 0, 60 ), '-' );
	}
}

class leaderboard extends _leaderboard {}
