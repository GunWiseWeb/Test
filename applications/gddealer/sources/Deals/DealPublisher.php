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

	/**
	 * Publish/refresh the top-N auto deals. Returns counts for logging.
	 * @return array{created:int,updated:int,expired:int,considered:int,errors:int}
	 */
	public static function publish(): array
	{
		$counts = [ 'created' => 0, 'updated' => 0, 'expired' => 0, 'considered' => 0, 'errors' => 0 ];

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
					d.dealer_name, d.dealer_slug,
					(
						l.deal_msrp_pct * 1.0
						+ l.deal_drop_pct * 0.8
						+ ( l.deal_price_drop * 10 )
						+ ( l.deal_back_in_stock * 8 )
						+ ( l.deal_free_ship_steal * 6 )
						+ ( l.deal_lowest_ever * 4 )
						+ ( l.deal_lowest_30d * 2 )
						+ ( l.deal_rare_find * 2 )
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
				LIMIT " . (int) self::CAP;

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

		/* 4. Pick a system author (Member 0 == guest) and a default category. */
		$author = \IPS\Member::load( 0 );
		try
		{
			$author = new \IPS\Member;
		}
		catch ( \Throwable ) {}

		/* Map gd_dealer_listings.category → gd_deal_categories.id.
		   Every auto-deal MUST land in a real container or it won't show
		   in the moderation queue. */
		$catMap = [
			'firearm'   => 1,   /* handguns (default firearm bucket) */
			'ammo'      => 4,   /* ammunition */
			'optic'     => 5,   /* optics */
			'accessory' => 6,   /* gun-accessories */
			'part'      => 6,   /* gun-accessories */
			'knife'     => 10,  /* knives */
			'apparel'   => 6,   /* gun-accessories fallback */
			'reloading' => 4,   /* ammunition (reloading components) */
		];
		$defaultCategoryId = 6; /* gun-accessories — safe catch-all */

		/* Resolve and cache category nodes up-front so create/update doesn't
		   re-load each row. */
		$categoryNodes = [];
		foreach ( array_unique( array_merge( array_values( $catMap ), [ $defaultCategoryId ] ) ) as $cid )
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

				$catKey      = strtolower( (string) $r['listing_category'] );
				$categoryId  = $catMap[ $catKey ] ?? $defaultCategoryId;
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
					/* CREATE via the canonical pattern from submit.php — pass TRUE
					   as the 5th arg so IPS creates the item as hidden/pending,
					   then register an Approval row so the mod queue surfaces it.
					   Just setting approved=0 on the column is NOT enough — the
					   moderation UI keys off core_approvals. */
					try
					{
						$ip = '127.0.0.1';
						try { $ip = (string) \IPS\Request::i()->ipAddress(); } catch ( \Throwable ) {}

						$post = \IPS\gddeals\Deal::createItem( $author, $ip, \IPS\DateTime::create(), $container, TRUE );
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

						/* Register a core_approvals row so the moderation queue
						   surfaces it. Mirrors submit.php lines 149-162. */
						try
						{
							\IPS\core\Approval::loadFromContent( get_class( $post ), $post->id );
						}
						catch ( \OutOfRangeException )
						{
							try { \IPS\core\Approval::create( $post, 'node' ); } catch ( \Throwable ) {}
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
