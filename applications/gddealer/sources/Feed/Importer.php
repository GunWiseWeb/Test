<?php
/**
 * @brief       GD Dealer Manager — Feed Importer
 * @package     IPS Community Suite
 * @subpackage  GD Dealer Manager
 * @since       15 Apr 2026
 *
 * Orchestrates a full dealer feed ingestion (Section 3.8 of the spec).
 * Fetch → parse → field-map → validate → upsert listings → write
 * price history on change → mark stale listings out of stock → log run.
 */

namespace IPS\gddealer\Feed;

use IPS\gddealer\Dealer\Dealer;
use IPS\gddealer\Listing\Listing;
use IPS\gddealer\Listing\PriceHistory;
use IPS\gddealer\Unmatched\UnmatchedUpc;
use IPS\gddealer\Log\ImportLog;
use IPS\gddealer\Feed\Parser\XmlParser;
use IPS\gddealer\Feed\Parser\JsonParser;
use IPS\gddealer\Feed\Parser\CsvParser;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class Importer
{
	/**
	 * Run a full feed import for a dealer and return the completed log.
	 *
	 * After the per-record upsert loop completes, we:
	 *   (a) mark stale listings out of stock (spec §3.8),
	 *   (b) recalculate denormalized pricing fields on gd_catalog for every
	 *       UPC touched in this run — total_min_price, free_ship_avail,
	 *       min_cpr — which power the Plugin 3 OpenSearch filters (spec §3.8),
	 *   (c) queue UPCs whose dealer_price decreased this run into the
	 *       gdpc_pending_alert_upcs setting so Plugin 3's watchlist task
	 *       can dispatch subscriber price alerts (spec §3.8 / §4.7).
	 */
	public static function run( Dealer $dealer ): ImportLog
	{
		$log = ImportLog::start( (int) $dealer->dealer_id, (string) $dealer->subscription_tier );

		try
		{
			$mode = (string) ( $dealer->feed_delivery_mode ?? 'url' );
			if ( $mode === 'url' && empty( $dealer->feed_url ) )
			{
				throw new \RuntimeException( 'Feed URL is not configured for this dealer.' );
			}

			$body    = self::fetch( $dealer );
			$records = self::parseBody( $body, (string) $dealer->feed_format );
			$map     = self::decodeMap( (string) ( $dealer->field_mapping ?? '' ) );

			$stats = [
				'records_total'     => 0,
				'records_created'   => 0,
				'records_updated'   => 0,
				'records_unchanged' => 0,
				'records_unmatched' => 0,
				'price_drops'       => 0,
				'price_increases'   => 0,
				'alerts_triggered'  => 0,
			];

			$runStart      = date( 'Y-m-d H:i:s' );
			$seenIds       = [];
			$touchedUpcs   = [];
			$priceDropUpcs = [];

			foreach ( $records as $raw )
			{
				$stats['records_total']++;

				$canonical = FieldMapper::apply( $raw, $map );

				if ( empty( $canonical['upc'] ) || !isset( $canonical['dealer_price'] ) || (float) $canonical['dealer_price'] <= 0 )
				{
					continue;
				}

				$upc = (string) $canonical['upc'];

				/* v188-category-map: classify category via dealer's mapping (lazy-populates
				 * gd_dealer_category_map on miss with auto-classified value). */
				$rawCategory = isset( $canonical['category'] ) ? (string) $canonical['category'] : '';
				if ( $rawCategory !== '' )
				{
					$resolvedCategory = \IPS\gddealer\Feed\CategoryMap::lookup( (int) $dealer->dealer_id, $rawCategory );
					if ( $resolvedCategory === 'skip' )
					{
						$stats['records_skipped']++;
						continue;
					}
					$canonical['category'] = $resolvedCategory;
				}

				if ( !self::upcExistsInCatalog( $upc ) )
				{
					/* v1.0.180: Capture the dealer's parsed product data at
					 * the moment we flag this UPC as unmatched. Stored as
					 * JSON in gd_unmatched_upcs.snapshot_json. Powers the
					 * gdcatalog admin review queue (v1.0.40 there) - admin
					 * can pre-fill the catalog add() form from this data
					 * instead of typing it from scratch.
					 *
					 * Only include fields with non-empty values - we don't
					 * want a snapshot full of nulls/empties. UnmatchedUpc::
					 * record() will skip the JSON write if the array is
					 * empty, falling back to the legacy count-only path. */
					$snapshot = self::extractSnapshot( $canonical );
					UnmatchedUpc::record( $upc, (int) $dealer->dealer_id, $snapshot );
					$stats['records_unmatched']++;
					continue;
				}

				$listing = Listing::loadFor( (int) $dealer->dealer_id, $upc );
				if ( $listing === null )
				{
					$listing = self::createListing( $dealer, $canonical, $runStart );
					PriceHistory::record( (int) $dealer->dealer_id, $upc, (float) $canonical['dealer_price'], !empty( $canonical['in_stock'] ) );
					$stats['records_created']++;
				}
				else
				{
					$diff = self::applyChanges( $listing, $canonical, $runStart );
					if ( $diff['priceChanged'] || $diff['stockChanged'] )
					{
						PriceHistory::record( (int) $dealer->dealer_id, $upc, (float) $canonical['dealer_price'], !empty( $canonical['in_stock'] ) );
						$stats['records_updated']++;
						if ( $diff['priceDelta'] < 0 )
						{
							$stats['price_drops']++;
							$priceDropUpcs[ $upc ] = true;
						}
						if ( $diff['priceDelta'] > 0 ) { $stats['price_increases']++; }
					}
					else
					{
						$stats['records_unchanged']++;
					}
					$listing->save();
				}

				$seenIds[]           = (int) $listing->id;
				$touchedUpcs[ $upc ] = true;
			}

			self::markStale( (int) $dealer->dealer_id, $runStart );

			/* Recalculate denormalized pricing on gd_catalog for every UPC
			 * touched this run (new, updated, or unchanged). Every touched
			 * UPC must be refreshed even if THIS dealer's listing didn't
			 * change, because a stale-listing sweep above may have changed
			 * the global MIN(price) across other dealers. */
			foreach ( array_keys( $touchedUpcs ) as $upc )
			{
				self::updateProductPricing( (string) $upc );
			}

			/* Queue price-drop UPCs for Plugin 3's watchlist dispatcher.
			 * This is a transient handoff — gdpricecompare reads, drains,
			 * and clears the setting. Merge with any UPCs already queued
			 * by a concurrent run so we don't clobber them. */
			if ( !empty( $priceDropUpcs ) )
			{
				self::queuePriceAlertUpcs( array_keys( $priceDropUpcs ) );
			}

			$dealer->last_run           = $runStart;
			$dealer->last_record_count  = $stats['records_total'];
			$dealer->last_run_status    = 'completed';
			$dealer->save();

			UnmatchedUpc::sweepMatched();
			$log->complete( $stats );
			return $log;
		}
		catch ( \Throwable $e )
		{
			$dealer->last_run        = date( 'Y-m-d H:i:s' );
			$dealer->last_run_status = 'failed';
			$dealer->save();
			$log->fail( $e->getMessage() );
			return $log;
		}
	}

	/**
	 * Refresh denormalized pricing fields on the gd_catalog row for a UPC.
	 *
	 * Aggregates all ACTIVE in-stock dealer listings across every dealer.
	 * If no in-stock listings remain, the pricing fields are cleared so the
	 * product drops out of Plugin 3's "available" search filter.
	 *
	 *   total_min_price    = MIN( dealer_price + COALESCE(shipping_cost, 0) )
	 *   free_ship_avail    = 1 if any listing has free_shipping = 1, else 0
	 *   min_cpr            = MIN( (dealer_price + shipping_cost) / rounds_per_box )
	 *                        for ammo only — NULL for non-ammo products.
	 */
	protected static function updateProductPricing( string $upc ): void
	{
		try
		{
			$product = \IPS\Db::i()->select( 'upc, is_ammo, rounds_per_box', 'gd_catalog', [ 'upc=?', $upc ] )->first();
		}
		catch ( \UnderflowException )
		{
			return;
		}

		try
		{
			$agg = \IPS\Db::i()->select(
				'MIN( dealer_price + COALESCE( shipping_cost, 0 ) ) AS total_min, MAX( free_shipping ) AS any_free_ship',
				'gd_dealer_listings',
				[ 'upc=? AND listing_status=? AND in_stock=?', $upc, Listing::STATUS_ACTIVE, 1 ]
			)->first();
		}
		catch ( \Exception )
		{
			return;
		}

		$totalMin = ( $agg['total_min'] !== null ) ? (float) $agg['total_min'] : null;
		$anyFree  = ( (int) ( $agg['any_free_ship'] ?? 0 ) ) === 1 ? 1 : 0;

		$minCpr = null;
		if ( ! empty( $product['is_ammo'] ) && (int) ( $product['rounds_per_box'] ?? 0 ) > 0 && $totalMin !== null )
		{
			$minCpr = round( $totalMin / (int) $product['rounds_per_box'], 4 );
		}

		\IPS\Db::i()->update( 'gd_catalog', [
			'total_min_price'  => $totalMin,
			'free_ship_avail'  => $anyFree,
			'min_cpr'          => $minCpr,
		], [ 'upc=?', $upc ] );
	}

	/**
	 * Merge the given UPCs into the gdpc_pending_alert_upcs setting so
	 * Plugin 3's watchlist alert dispatcher can process them. Stub handoff
	 * only — Plugin 3 owns the actual alert mail-out.
	 */
	protected static function queuePriceAlertUpcs( array $upcs ): void
	{
		try
		{
			$existingRaw = (string) \IPS\Settings::i()->gdpc_pending_alert_upcs;
			$existing    = $existingRaw !== '' ? (array) json_decode( $existingRaw, true ) : [];
			if ( ! is_array( $existing ) ) { $existing = []; }

			$merged = array_values( array_unique( array_merge( $existing, $upcs ) ) );

			\IPS\Settings::i()->changeValues( [
				'gdpc_pending_alert_upcs' => json_encode( $merged ),
			] );
		}
		catch ( \Throwable )
		{
			/* Setting may not exist yet if Plugin 3 hasn't been installed.
			 * That's expected during Plugin 2 isolated runs — don't fail
			 * the whole import just because the handoff target is missing. */
		}
	}

	/**
	 * Fetch the feed body with optional auth credentials.
	 */
	protected static function fetch( Dealer $dealer ): string
	{
		$mode = (string) ( $dealer->feed_delivery_mode ?? 'url' );

		if ( $mode === 'manual' )
		{
			try
			{
				$row = \IPS\Db::i()->select( '*', 'gd_dealer_feed_uploads',
					[ 'dealer_id=?', (int) $dealer->dealer_id ],
					'uploaded_at DESC',
					[ 0, 1 ]
				)->first();
			}
			catch ( \Exception )
			{
				throw new \RuntimeException( 'No feed file uploaded yet. Upload a file from the Feed Settings tab.' );
			}

			$fileUrl = (string) ( $row['file_url'] ?? '' );
			if ( $fileUrl === '' )
			{
				throw new \RuntimeException( 'Latest upload row has no file URL — the file may have been moved or deleted.' );
			}

			try
			{
				$contents = (string) \IPS\File::get( 'gddealer_FeedUpload', $fileUrl )->contents();
				if ( $contents === '' )
				{
					throw new \RuntimeException( 'Uploaded feed file is empty.' );
				}
				return $contents;
			}
			catch ( \Throwable $e )
			{
				throw new \RuntimeException( 'Failed to read uploaded feed file: ' . $e->getMessage() );
			}
		}

		$url  = (string) $dealer->feed_url;
		$auth = $dealer->getCredentials();

		$http = \IPS\Http\Url::external( $url )->request( 90 );

		if ( $auth )
		{
			$decoded = json_decode( $auth, true );
			if ( is_array( $decoded ) && isset( $decoded['username'], $decoded['password'] ) && $dealer->auth_type === 'basic' )
			{
				$http = $http->login( (string) $decoded['username'], (string) $decoded['password'] );
			}
			elseif ( is_array( $decoded ) && isset( $decoded['api_key'] ) && $dealer->auth_type === 'apikey' )
			{
				$http = $http->setHeaders( [ 'Authorization' => 'Bearer ' . $decoded['api_key'] ] );
			}
		}

		$response = $http->get();
		if ( (int) $response->httpResponseCode >= 400 )
		{
			throw new \RuntimeException( 'Feed fetch failed: HTTP ' . $response->httpResponseCode );
		}
		return (string) $response->content;
	}

	protected static function parseBody( string $body, string $format ): array
	{
		return match ( strtolower( $format ) ) {
			'xml'  => XmlParser::parse( $body ),
			'json' => JsonParser::parse( $body ),
			'csv'  => CsvParser::parse( $body ),
			default => throw new \RuntimeException( "Unsupported feed format: {$format}" ),
		};
	}

	/**
	 * @return array<string, string>
	 */
	protected static function decodeMap( string $json ): array
	{
		if ( $json === '' )
		{
			return [];
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * v1.0.180: Pull catalog-relevant fields out of a parsed canonical
	 * record for the unmatched-UPC snapshot. Only includes non-empty
	 * values. Returns empty array if nothing useful to snapshot.
	 *
	 * Field naming note: FieldMapper::apply() returns records keyed by
	 * STORAGE column names (dealer_price, dealer_sku, listing_url) for
	 * v1.1 canonical fields that have a translation layer. For non-
	 * translated fields (title, brand, mpn, image_url), the canonical
	 * slug and storage column match. We probe both names defensively
	 * so future FieldMapper changes don't silently break the snapshot.
	 *
	 * @param  array<string,mixed> $canonical Output of FieldMapper::apply()
	 * @return array<string,mixed>
	 */
	protected static function extractSnapshot( array $canonical ): array
	{
		$probe = function( array $rec, array $candidates ): ?string {
			foreach ( $candidates as $key )
			{
				if ( !isset( $rec[ $key ] ) )
				{
					continue;
				}
				$val = trim( (string) $rec[ $key ] );
				if ( $val !== '' )
				{
					return $val;
				}
			}
			return null;
		};

		$snapshot = [];

		/* Product identity */
		$title = $probe( $canonical, [ 'title' ] );
		if ( $title !== null ) { $snapshot['title'] = $title; }

		$brand = $probe( $canonical, [ 'brand' ] );
		if ( $brand !== null ) { $snapshot['brand'] = $brand; }

		$mpn = $probe( $canonical, [ 'mpn' ] );
		if ( $mpn !== null ) { $snapshot['mpn'] = $mpn; }

		$manufacturer = $probe( $canonical, [ 'manufacturer' ] );
		if ( $manufacturer !== null ) { $snapshot['manufacturer'] = $manufacturer; }

		$model = $probe( $canonical, [ 'model' ] );
		if ( $model !== null ) { $snapshot['model'] = $model; }

		/* Image - probe canonical 'image_url' first, then alternate 'image' slug */
		$imageUrl = $probe( $canonical, [ 'image_url', 'image' ] );
		if ( $imageUrl !== null ) { $snapshot['image_url'] = $imageUrl; }

		/* Description (helpful for catalog admin even if not always present) */
		$description = $probe( $canonical, [ 'description' ] );
		if ( $description !== null ) { $snapshot['description'] = $description; }

		/* Pricing - probe canonical 'msrp' for suggested retail. We do NOT
		 * include dealer_price here - that's the dealer's selling price,
		 * not the canonical MSRP. */
		$msrp = $probe( $canonical, [ 'msrp' ] );
		if ( $msrp !== null )
		{
			$cleanMsrp = preg_replace( '/[^0-9.]/', '', $msrp );
			if ( $cleanMsrp !== '' && (float) $cleanMsrp > 0 )
			{
				$snapshot['msrp'] = (float) $cleanMsrp;
			}
		}

		/* Caliber, MSRP, category - helpful for firearm-specific records */
		$caliber = $probe( $canonical, [ 'caliber' ] );
		if ( $caliber !== null ) { $snapshot['caliber'] = $caliber; }

		$capacity = $probe( $canonical, [ 'capacity' ] );
		if ( $capacity !== null )
		{
			$cleanCapacity = preg_replace( '/[^0-9]/', '', $capacity );
			if ( $cleanCapacity !== '' )
			{
				$snapshot['capacity'] = (int) $cleanCapacity;
			}
		}

		return $snapshot;
	}

	protected static function upcExistsInCatalog( string $upc ): bool
	{
		try
		{
			$count = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_catalog', [ 'upc=?', $upc ] )->first();
			return $count > 0;
		}
		catch ( \Exception )
		{
			return false;
		}
	}

	protected static function createListing( Dealer $dealer, array $canonical, string $runStart ): Listing
	{
		$listing = new Listing;
		$listing->dealer_id          = (int) $dealer->dealer_id;
		$listing->upc                = (string) $canonical['upc'];
		$listing->dealer_sku         = $canonical['dealer_sku'] ?? null;
		$listing->dealer_price       = (float) $canonical['dealer_price'];
		$listing->shipping_cost      = $canonical['shipping_cost'] ?? null;
		$listing->free_shipping      = !empty( $canonical['free_shipping'] ) ? 1 : 0;
		$listing->in_stock           = !empty( $canonical['in_stock'] ) ? 1 : 0;
		$listing->stock_qty          = $canonical['stock_qty'] ?? null;
		$listing->condition          = $canonical['condition'] ?? 'new';
		$listing->category           = $canonical['category'] ?? null;
		$listing->listing_url        = $canonical['listing_url'] ?? null;
		$listing->subscription_tier  = (string) $dealer->subscription_tier;
		$listing->listing_status     = $listing->in_stock ? Listing::STATUS_ACTIVE : Listing::STATUS_OUT_OF_STOCK;
		$listing->first_seen_in_feed = $runStart;
		$listing->last_seen_in_feed  = $runStart;
		$listing->last_price_change  = $runStart;
		$listing->save();
		return $listing;
	}

	/**
	 * @return array{priceChanged:bool, stockChanged:bool, priceDelta:float}
	 */
	protected static function applyChanges( Listing $listing, array $canonical, string $runStart ): array
	{
		$newPrice = (float) $canonical['dealer_price'];
		$newStock = !empty( $canonical['in_stock'] ) ? 1 : 0;

		$priceChanged = abs( (float) $listing->dealer_price - $newPrice ) > 0.001;
		$stockChanged = ( (int) $listing->in_stock ) !== $newStock;
		$delta        = $newPrice - (float) $listing->dealer_price;

		if ( $priceChanged )
		{
			$listing->dealer_price      = $newPrice;
			$listing->last_price_change = $runStart;
		}
		$listing->in_stock          = $newStock;
		$listing->stock_qty         = $canonical['stock_qty'] ?? null;
		$listing->shipping_cost     = $canonical['shipping_cost'] ?? null;
		$listing->free_shipping     = !empty( $canonical['free_shipping'] ) ? 1 : 0;
		$listing->condition         = $canonical['condition'] ?? $listing->condition;
		$listing->category          = $canonical['category'] ?? $listing->category;
		$listing->listing_url       = $canonical['listing_url'] ?? $listing->listing_url;
		$listing->subscription_tier = (string) $listing->subscription_tier;
		$listing->last_seen_in_feed = $runStart;

		if ( $newStock === 0 )
		{
			$listing->listing_status = Listing::STATUS_OUT_OF_STOCK;
		}
		elseif ( $listing->listing_status === Listing::STATUS_OUT_OF_STOCK )
		{
			$listing->listing_status = Listing::STATUS_ACTIVE;
		}

		return [ 'priceChanged' => $priceChanged, 'stockChanged' => $stockChanged, 'priceDelta' => $delta ];
	}

	/**
	 * Any listing for this dealer whose last_seen_in_feed < $runStart is now
	 * stale and gets marked out of stock.
	 */
	protected static function markStale( int $dealerId, string $runStart ): void
	{
		\IPS\Db::i()->update(
			'gd_dealer_listings',
			[ 'listing_status' => Listing::STATUS_OUT_OF_STOCK, 'in_stock' => 0 ],
			[ 'dealer_id=? AND ( last_seen_in_feed IS NULL OR last_seen_in_feed < ? ) AND listing_status <> ?', $dealerId, $runStart, Listing::STATUS_SUSPENDED ]
		);
	}
}
