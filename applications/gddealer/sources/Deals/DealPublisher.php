<?php
/**
 * @brief       GD Dealer Manager — Deal Publisher (Phase 2)
 * @package     IPS Community Suite
 * @subpackage  GD Dealer Manager
 * @since       v1.0.320
 *
 * Promotes the strongest flagged listings (from DealEngine, Phase 1) to
 * gd_deal_posts as source_badge='auto' Community Deal posts.
 *
 * Phase 2 defaults (locked; Phase 3 makes settings):
 *   - source_badge = 'auto'
 *   - approved     = 0   (gated for admin review)
 *   - cap          = 50  (top-N by composite deal score)
 *
 * Reconciliation each run (idempotent):
 *   - top-N strongest: upsert by (upc, dealer_id), un-expire if was expired
 *   - existing auto posts NOT in top-N: mark expired=1 (preserves votes/comments)
 *
 * Called from Importer::run() after DealEngine::computeAll(); wrapped so any
 * failure never breaks an import.
 */

namespace IPS\gddealer\Deals;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _DealPublisher
{
	const CAP      = 50;        /* top-N by score */
	const APPROVED = 0;         /* gated — admin approves */
	const BADGE    = 'auto';    /* source_badge value */

	/* gd_deal_categories id used when nothing else matches. */
	const DEAL_CAT_DEFAULT = 6; /* gun-accessories */

	/* gd_categories top-level (parent_id=0) id → gd_deal_categories.id.
	   Anything not listed falls back to DEAL_CAT_DEFAULT (gun-accessories). */
	protected static array $topLevelToDeal = [
		1   => 1,   /* Handguns           */
		7   => 2,   /* Rifles             */
		16  => 3,   /* Shotguns           */
		23  => 4,   /* Ammunition         */
		44  => 5,   /* Optics             */
		72  => 7,   /* Holsters & Carry   */
		83  => 8,   /* Storage & Safety   */
		31  => 9,   /* NFA Items          */
		138 => 10,  /* Knives             */
		135 => 1,   /* Air Guns -> handguns (mixed; default) */
		137 => 2,   /* Muzzleloading -> rifles */
		136 => 4,   /* Reloading -> ammunition */
	];

	/* Per-publish cache of catalog_category_id → deal_category_id resolutions. */
	protected static array $resolveCache = [];

	/**
	 * Walk a gd_catalog.category_id up the gd_categories parent chain to its
	 * top-level parent (parent_id=0), then map to a gd_deal_categories.id.
	 * Falls back to DEAL_CAT_DEFAULT (6, gun-accessories) on missing data.
	 */
	protected static function resolveDealCategory( int $catalogCategoryId ): int
	{
		if ( $catalogCategoryId <= 0 ) { return self::DEAL_CAT_DEFAULT; }
		if ( isset( self::$resolveCache[ $catalogCategoryId ] ) )
		{
			return self::$resolveCache[ $catalogCategoryId ];
		}

		$id    = $catalogCategoryId;
		$topId = $catalogCategoryId;
		$guard = 0;
		try
		{
			while ( $guard++ < 12 )
			{
				$row = \IPS\Db::i()->select( 'id, parent_id', 'gd_categories', [ 'id=?', $id ] )->first();
				if ( (int) $row['parent_id'] === 0 ) { $topId = (int) $row['id']; break; }
				$id    = (int) $row['parent_id'];
				$topId = $id;
			}
		}
		catch ( \Throwable )
		{
			/* missing row → default */
		}

		$deal = self::$topLevelToDeal[ $topId ] ?? self::DEAL_CAT_DEFAULT;
		self::$resolveCache[ $catalogCategoryId ] = $deal;
		return $deal;
	}

	/**
	 * Publish/refresh the top-N auto deals. Returns counts for logging.
	 * @return array{created:int,updated:int,expired:int,considered:int,errors:int}
	 */
	public static function publish(): array
	{
		$counts = [ 'created' => 0, 'updated' => 0, 'expired' => 0, 'considered' => 0, 'errors' => 0 ];

		/* Read settings with constant fallback. Empty setting → original default. */
		$S = \IPS\Settings::i();

		/* Master switch — when disabled, do nothing (and don't disturb existing
		   auto posts; admin re-enables to resume). */
		$enabled = $S->gddeals_auto_enabled;
		if ( $enabled !== null && $enabled !== '' && (int) $enabled === 0 )
		{
			return $counts;
		}

		$cap          = ( $S->gddeals_auto_cap !== null && $S->gddeals_auto_cap !== '' ) ? (int)   $S->gddeals_auto_cap : (int)   self::CAP;
		$approveLive  = ( $S->gddeals_auto_approve !== null && $S->gddeals_auto_approve !== '' ) ? (int) $S->gddeals_auto_approve : (int) self::APPROVED;
		if ( $cap < 1 ) { $cap = (int) self::CAP; }

		$w = [
			'msrp'  => ( $S->gddeals_wt_msrp        !== null && $S->gddeals_wt_msrp        !== '' ) ? (float) $S->gddeals_wt_msrp        : 1.0,
			'drop'  => ( $S->gddeals_wt_drop        !== null && $S->gddeals_wt_drop        !== '' ) ? (float) $S->gddeals_wt_drop        : 0.8,
			'dropf' => ( $S->gddeals_wt_drop_flag   !== null && $S->gddeals_wt_drop_flag   !== '' ) ? (float) $S->gddeals_wt_drop_flag   : 10,
			'back'  => ( $S->gddeals_wt_back        !== null && $S->gddeals_wt_back        !== '' ) ? (float) $S->gddeals_wt_back        : 8,
			'fs'    => ( $S->gddeals_wt_freeship    !== null && $S->gddeals_wt_freeship    !== '' ) ? (float) $S->gddeals_wt_freeship    : 6,
			'le'    => ( $S->gddeals_wt_lowest_ever !== null && $S->gddeals_wt_lowest_ever !== '' ) ? (float) $S->gddeals_wt_lowest_ever : 4,
			'l30'   => ( $S->gddeals_wt_lowest_30d  !== null && $S->gddeals_wt_lowest_30d  !== '' ) ? (float) $S->gddeals_wt_lowest_30d  : 2,
			'rare'  => ( $S->gddeals_wt_rare        !== null && $S->gddeals_wt_rare        !== '' ) ? (float) $S->gddeals_wt_rare        : 2,
		];

		/* 1. Score qualifying listings — those with at least one deal flag set. */
		$rows = [];
		try
		{
			$sql = "SELECT
					l.dealer_id, l.upc, l.dealer_price, l.shipping_info, l.in_stock,
					l.category AS listing_category,
					l.deal_msrp_pct, l.deal_drop_pct,
					l.deal_lowest_ever, l.deal_lowest_30d, l.deal_msrp_off,
					l.deal_price_drop, l.deal_back_in_stock, l.deal_rare_find,
					l.deal_free_ship_steal,
					c.title AS catalog_title, c.msrp AS catalog_msrp,
					c.image_url AS catalog_image, c.image_validated,
					c.category_id AS catalog_category_id,
					d.dealer_name, d.dealer_slug,
					(
						l.deal_msrp_pct * {$w['msrp']}
						+ l.deal_drop_pct * {$w['drop']}
						+ ( l.deal_price_drop * {$w['dropf']} )
						+ ( l.deal_back_in_stock * {$w['back']} )
						+ ( l.deal_free_ship_steal * {$w['fs']} )
						+ ( l.deal_lowest_ever * {$w['le']} )
						+ ( l.deal_lowest_30d * {$w['l30']} )
						+ ( l.deal_rare_find * {$w['rare']} )
					) AS deal_score
				FROM " . \IPS\Db::i()->prefix . "gd_dealer_listings l
				JOIN " . \IPS\Db::i()->prefix . "gd_catalog c ON c.upc = l.upc
				JOIN " . \IPS\Db::i()->prefix . "gd_dealer_feed_config d ON d.dealer_id = l.dealer_id
				WHERE l.listing_status = 'active'
					AND l.in_stock = 1
					AND l.dealer_price > 0
					AND (
						l.deal_lowest_ever = 1 OR l.deal_lowest_30d = 1
						OR l.deal_msrp_off = 1 OR l.deal_price_drop = 1
						OR l.deal_back_in_stock = 1 OR l.deal_rare_find = 1
						OR l.deal_free_ship_steal = 1
					)
				ORDER BY deal_score DESC
				LIMIT " . (int) $cap;

			$result = \IPS\Db::i()->query( $sql );
			while ( $row = $result->fetch_assoc() )
			{
				$rows[] = $row;
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'DealPublisher score query: ' . $e->getMessage(), 'gddealer_deals' ); } catch ( \Throwable ) {}
			return $counts;
		}

		$counts['considered'] = count( $rows );
		if ( empty( $rows ) )
		{
			return $counts;
		}

		/* 2. Build the set of (upc, dealer_id) keys we want to KEEP. */
		$keepKeys = [];
		foreach ( $rows as $r )
		{
			$keepKeys[ (string) $r['upc'] . '|' . (int) $r['dealer_id'] ] = true;
		}

		/* 3. Load existing auto posts so we can match + reconcile. Key by upc|dealer_id. */
		$existingByKey = [];
		try
		{
			foreach ( \IPS\Db::i()->select( 'id, upc, dealer_id, expired', 'gd_deal_posts',
				[ 'source_badge=?', self::BADGE ] ) as $ex )
			{
				$key = (string) $ex['upc'] . '|' . (int) $ex['dealer_id'];
				$existingByKey[ $key ] = $ex;
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'DealPublisher load existing: ' . $e->getMessage(), 'gddealer_deals' ); } catch ( \Throwable ) {}
		}

		/* 4. Author auto-deals as the configured deals-author
		      member (default: the AutoDeals system account
		      created by upg_10328). Falls back to guest only if
		      the configured member has since been deleted so
		      publishing never dies on a bad setting. */
		$authorId = 0;
		try { $authorId = (int) \IPS\Settings::i()->gddealer_deals_author_id; } catch ( \Throwable ) {}
		$author = $authorId ? \IPS\Member::load( $authorId ) : \IPS\Member::load( 0 );
		if ( !$author->member_id )
		{
			/* Configured member is gone — silently drop back to
			   guest so the whole publish batch doesn't blow up. */
			$author = \IPS\Member::load( 0 );
		}

		/* Resolve deal category from the catalog hierarchy (granular: rifle vs
		   handgun vs shotgun). The coarse listing.category can't distinguish
		   firearm types. See self::resolveDealCategory(). */
		$defaultCategoryId = self::DEAL_CAT_DEFAULT;

		/* Pre-load every possible deal-category container we might assign so
		   the per-row loop doesn't re-load each one. */
		$categoryNodes = [];
		foreach ( array_unique( array_merge( array_values( self::$topLevelToDeal ), [ $defaultCategoryId ] ) ) as $cid )
		{
			try
			{
				$categoryNodes[ (int) $cid ] = \IPS\gddeals\Category::load( (int) $cid );
			}
			catch ( \Throwable ) {}
		}

		/* 5. Upsert each top-N row. */
		foreach ( $rows as $r )
		{
			try
			{
				$dealerId = (int) $r['dealer_id'];
				$upc      = (string) $r['upc'];
				$key      = $upc . '|' . $dealerId;
				$price    = (float) $r['dealer_price'];
				$msrp     = (float) $r['catalog_msrp'];

				$categoryId  = self::resolveDealCategory( (int) ( $r['catalog_category_id'] ?? 0 ) );
				$container   = $categoryNodes[ $categoryId ] ?? ( $categoryNodes[ $defaultCategoryId ] ?? null );
				if ( $container === null )
				{
					$counts['errors']++;
					try { \IPS\Log::log( "DealPublisher: no category container available for id $categoryId", 'gddealer_deals' ); } catch ( \Throwable ) {}
					continue;
				}

				$catTitle = trim( (string) ( $r['catalog_title'] ?? '' ) );
				$dealerNm = trim( (string) ( $r['dealer_name'] ?? '' ) );

				/* Strongest applicable label for the title suffix. */
				$suffix = '';
				if ( (int) $r['deal_msrp_off'] && (float) $r['deal_msrp_pct'] > 0 )
				{
					$suffix = round( (float) $r['deal_msrp_pct'] ) . '% off MSRP';
				}
				elseif ( (int) $r['deal_price_drop'] )
				{
					$suffix = round( (float) $r['deal_drop_pct'] ) . '% price drop';
				}
				elseif ( (int) $r['deal_lowest_ever'] )
				{
					$suffix = 'Lowest ever';
				}
				elseif ( (int) $r['deal_back_in_stock'] )
				{
					$suffix = 'Back in stock';
				}
				elseif ( (int) $r['deal_free_ship_steal'] )
				{
					$suffix = 'Free shipping';
				}
				elseif ( (int) $r['deal_lowest_30d'] )
				{
					$suffix = '30-day low';
				}
				elseif ( (int) $r['deal_rare_find'] )
				{
					$suffix = 'Rare find';
				}

				$title = $catTitle !== '' ? $catTitle : ( 'UPC ' . $upc );
				if ( $suffix !== '' )
				{
					$title = mb_substr( $title, 0, 140 ) . ' — ' . $suffix;
				}
				$title = mb_substr( $title, 0, 150 );

				$origPrice  = ( $msrp > 0 && $msrp > $price ) ? $msrp : null;
				$discount   = ( $origPrice !== null && $origPrice > 0 && $price > 0 )
					? round( ( 1 - ( $price / $origPrice ) ) * 100, 2 )
					: 0;

				$imageUrl = '';
				if ( !empty( $r['image_validated'] ) && !empty( $r['catalog_image'] ) )
				{
					$imageUrl = (string) $r['catalog_image'];
				}

				$dealUrl = (string) \IPS\Http\Url::internal(
					'app=gddealer&module=dealers&controller=click&d=' . $dealerId . '&u=' . urlencode( $upc ),
					'front', 'dealers_click'
				);

				$score   = (float) $r['deal_score'];
				$heat    = (int) min( 9, 3 + (int) round( $score / 15 ) );
				$hl      = $heat >= 25 ? 'fire' : ( $heat >= 10 ? 'hot' : ( $heat >= 3 ? 'warm' : 'cold' ) );
				$freeSh  = (int) ( !empty( $r['deal_free_ship_steal'] ) ? 1 : 0 );

				if ( isset( $existingByKey[ $key ] ) )
				{
					/* UPDATE — load + edit + save so IPS hooks fire correctly. */
					try
					{
						$post = \IPS\gddeals\Deal::load( (int) $existingByKey[ $key ]['id'] );
						$post->category_id    = $categoryId;
						$post->title          = $title;
						$post->upc            = $upc;
						$post->dealer_id      = $dealerId;
						$post->retailer_name  = $dealerNm ?: $title;
						$post->deal_price     = $price ?: null;
						$post->original_price = $origPrice;
						$post->discount_pct   = $discount;
						$post->image_url      = $imageUrl ?: null;
						$post->deal_url       = $dealUrl;
						$post->source_badge   = self::BADGE;
						$post->heat_score     = $heat;
						$post->heat_label     = $hl;
						$post->free_shipping  = $freeSh;
						$post->expired        = 0;
						$post->post_type      = 'product';
						$post->save();
						$counts['updated']++;
					}
					catch ( \Throwable $e )
					{
						$counts['errors']++;
						try { \IPS\Log::log( "DealPublisher update $key: " . $e->getMessage(), 'gddealer_deals' ); } catch ( \Throwable ) {}
					}
				}
				else
				{
					/* CREATE via the canonical pattern from submit.php. When the
					   admin's auto-approve setting is 1, create the item as
					   visible/approved (5th arg FALSE). When 0 (gated), create
					   as hidden/pending and register a core_approvals row so
					   the moderation queue surfaces it. */
					try
					{
						$ip = '127.0.0.1';
						try { $ip = (string) \IPS\Request::i()->ipAddress(); } catch ( \Throwable ) {}

						$hidden = $approveLive ? FALSE : TRUE;
						$post = \IPS\gddeals\Deal::createItem( $author, $ip, \IPS\DateTime::create(), $container, $hidden );
						$post->title          = $title;
						$post->upc            = $upc;
						$post->dealer_id      = $dealerId;
						$post->retailer_name  = $dealerNm ?: $title;
						$post->deal_price     = $price ?: null;
						$post->original_price = $origPrice;
						$post->discount_pct   = $discount;
						$post->image_url      = $imageUrl ?: null;
						$post->deal_url       = $dealUrl;
						$post->source_badge   = self::BADGE;
						$post->heat_score     = $heat;
						$post->heat_label     = $hl;
						$post->free_shipping  = $freeSh;
						$post->expired        = 0;
						$post->post_type      = 'product';
						$post->save();

						/* Only register the approval row when gated. When auto-
						   approve is on, the item is visible and doesn't belong
						   in the mod queue. */
						if ( !$approveLive )
						{
							try
							{
								\IPS\core\Approval::loadFromContent( get_class( $post ), $post->id );
							}
							catch ( \OutOfRangeException )
							{
								try { \IPS\core\Approval::create( $post, 'node' ); } catch ( \Throwable ) {}
							}
						}

						$counts['created']++;
					}
					catch ( \Throwable $e )
					{
						$counts['errors']++;
						try { \IPS\Log::log( "DealPublisher create $key: " . $e->getMessage(), 'gddealer_deals' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				$counts['errors']++;
				try { \IPS\Log::log( 'DealPublisher row: ' . $e->getMessage(), 'gddealer_deals' ); } catch ( \Throwable ) {}
			}
		}

		/* 6. Reconcile — expire existing auto posts NOT in the new top-N. */
		try
		{
			$expireIds = [];
			foreach ( $existingByKey as $key => $ex )
			{
				if ( !isset( $keepKeys[ $key ] ) && (int) $ex['expired'] === 0 )
				{
					$expireIds[] = (int) $ex['id'];
				}
			}
			if ( !empty( $expireIds ) )
			{
				\IPS\Db::i()->update( 'gd_deal_posts', [ 'expired' => 1 ],
					[ \IPS\Db::i()->in( 'id', $expireIds ) ] );
				$counts['expired'] = count( $expireIds );
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'DealPublisher expire sweep: ' . $e->getMessage(), 'gddealer_deals' ); } catch ( \Throwable ) {}
		}

		try
		{
			\IPS\Log::log( 'DealPublisher::publish ' . implode( ' ', array_map(
				fn( $k, $v ) => "$k=$v", array_keys( $counts ), array_values( $counts )
			) ), 'gddealer_deals' );
		}
		catch ( \Throwable ) {}

		return $counts;
	}
}

class DealPublisher extends _DealPublisher {}
