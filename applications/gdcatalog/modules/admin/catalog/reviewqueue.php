<?php
/**
 * @brief  GD Master Catalog — Review Queue Controller (v1.0.130)
 *
 * Lists gd_catalog products with record_status='admin_review' — the
 * products that landed via a source configured with
 * `mark_imports_as_review=1` (typically a low-quality dealer/backfill
 * feed). Each row shows a canonical-field completeness heat-map so
 * an admin can see at a glance which important fields are missing,
 * click through to the existing product-edit UI to fill them in,
 * then Promote the row to `record_status='active'` — at which point
 * it becomes visible to the front-end.
 *
 * Reuses:
 *   - Product::STATUS_ADMIN_REVIEW status (pre-existing).
 *   - Product::save() + existing product-edit URL (whatever controller
 *     owns per-product edit).
 *   - gd_reindex_queue for OpenSearch reindexing on promote.
 *
 * Actions:
 *   manage()        — paginated queue list.
 *   promote()       — one product to active (CSRF-protected POST).
 *   promoteBulk()   — many products at once via ids[] POST param.
 */

namespace IPS\gdcatalog\modules\admin\catalog;

use IPS\Output;
use IPS\Request;
use IPS\gdcatalog\Catalog\Product;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _reviewqueue extends \IPS\Dispatcher\Controller
{
	public static $csrfProtected = TRUE;

	/**
	 * Canonical fields considered "critical" for completeness. Rows
	 * missing any of these show up highlighted in the queue. Keep
	 * this list tight — a firearm doesn't need every attribute
	 * populated to be catalog-worthy. Order matters for the display.
	 */
	protected const CRITICAL_FIELDS = [
		'upc', 'title', 'brand', 'model', 'category_id', 'image_url', 'caliber',
	];

	protected const PER_PAGE = 25;

	public function execute(): void
	{
		parent::execute();
	}

	protected function manage(): void
	{
		$lang    = \IPS\Member::loggedIn()->language();
		$page    = max( 1, (int) ( Request::i()->page ?? 1 ) );
		$offset  = ( $page - 1 ) * self::PER_PAGE;
		$sourceFilter = trim( (string) ( Request::i()->source ?? '' ) );

		$where = [ 'record_status=?' ];
		$args  = [ Product::STATUS_ADMIN_REVIEW ];
		if ( $sourceFilter !== '' )
		{
			$where[] = 'FIND_IN_SET(?, distributor_sources) > 0';
			$args[]  = $sourceFilter;
		}
		$whereSql = [ implode( ' AND ', $where ), ...$args ];

		$total = 0;
		try { $total = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_catalog', $whereSql )->first(); } catch ( \Throwable ) {}

		$rows = [];
		try
		{
			$rs = \IPS\Db::i()->select(
				'*', 'gd_catalog', $whereSql,
				'last_updated DESC',
				[ $offset, self::PER_PAGE ]
			);
			foreach ( $rs as $row )
			{
				$missing = [];
				$present = [];
				foreach ( self::CRITICAL_FIELDS as $f )
				{
					$v = $row[$f] ?? null;
					$has = ( $v !== null && $v !== '' && $v !== 0 && $v !== '0' );
					if ( $has ) { $present[] = $f; } else { $missing[] = $f; }
				}
				$completeness = (int) round( ( count( $present ) / count( self::CRITICAL_FIELDS ) ) * 100 );
				$rows[] = [
					'upc'            => (string) $row['upc'],
					'title'          => (string) ( $row['title'] ?? '' ),
					'brand'          => (string) ( $row['brand'] ?? '' ),
					'caliber'        => (string) ( $row['caliber'] ?? '' ),
					'image_url'      => (string) ( $row['image_url'] ?? '' ),
					'category_id'    => (int)    ( $row['category_id'] ?? 0 ),
					'primary_source' => (string) ( $row['primary_source'] ?? '' ),
					'last_updated'   => (string) ( $row['last_updated'] ?? '' ),
					'present'        => $present,
					'missing'        => $missing,
					'completeness'   => $completeness,
					'edit_url'       => (string) \IPS\Http\Url::internal(
						'app=gdcatalog&module=catalog&controller=products&do=edit&upc=' . urlencode( (string) $row['upc'] )
					),
					'promote_url'    => (string) \IPS\Http\Url::internal(
						'app=gdcatalog&module=catalog&controller=reviewqueue&do=promote&upc=' . urlencode( (string) $row['upc'] )
					)->csrf(),
				];
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'reviewqueue::manage select failed: ' . $e->getMessage(), 'gdcatalog_reviewqueue' ); } catch ( \Throwable ) {}
		}

		/* Source options for the filter dropdown — populated from
		 * distinct primary_source values on admin_review products so
		 * the list only offers filters that would actually return rows. */
		$sources = [ '' => 'All sources' ];
		try
		{
			foreach ( \IPS\Db::i()->select(
				'DISTINCT primary_source', 'gd_catalog',
				[ 'record_status=? AND primary_source != ?', Product::STATUS_ADMIN_REVIEW, '' ]
			) as $r )
			{
				$slug = (string) $r;
				if ( $slug !== '' ) { $sources[ $slug ] = $slug; }
			}
		}
		catch ( \Throwable ) {}

		$totalPages = max( 1, (int) ceil( $total / self::PER_PAGE ) );

		$prevUrl = $page > 1 ? (string) \IPS\Http\Url::internal(
			'app=gdcatalog&module=catalog&controller=reviewqueue&page=' . ( $page - 1 )
			. ( $sourceFilter !== '' ? '&source=' . urlencode( $sourceFilter ) : '' )
		) : '';
		$nextUrl = $page < $totalPages ? (string) \IPS\Http\Url::internal(
			'app=gdcatalog&module=catalog&controller=reviewqueue&page=' . ( $page + 1 )
			. ( $sourceFilter !== '' ? '&source=' . urlencode( $sourceFilter ) : '' )
		) : '';

		$promoteBulkUrl = (string) \IPS\Http\Url::internal(
			'app=gdcatalog&module=catalog&controller=reviewqueue&do=promoteBulk'
		)->csrf();

		Output::i()->title  = 'Review Queue';
		Output::i()->output = \IPS\Theme::i()->getTemplate( 'catalog', 'gdcatalog', 'admin' )->reviewQueue(
			$rows,
			$total,
			$page,
			$totalPages,
			$prevUrl,
			$nextUrl,
			$sources,
			$sourceFilter,
			$promoteBulkUrl,
			self::CRITICAL_FIELDS
		);
	}

	/**
	 * Promote one product from admin_review → active. CSRF-protected.
	 * Queues the product for OpenSearch reindex through the existing
	 * gd_reindex_queue path — no synchronous OpenSearch HTTP.
	 */
	protected function promote(): void
	{
		\IPS\Session::i()->csrfCheck();

		$backUrl = \IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=reviewqueue' );
		$upc     = (string) ( Request::i()->upc ?? '' );

		if ( $upc === '' )
		{
			Output::i()->redirect( $backUrl, 'Missing UPC.' );
			return;
		}

		try
		{
			$product = Product::load( $upc );
			if ( $product->record_status !== Product::STATUS_ADMIN_REVIEW )
			{
				Output::i()->redirect( $backUrl, 'Product is not in admin review.' );
				return;
			}
			$product->record_status = Product::STATUS_ACTIVE;
			$product->last_updated  = date( 'Y-m-d H:i:s' );
			$product->save();

			try
			{
				\IPS\Db::i()->replace( 'gd_reindex_queue', [
					'upc'       => $upc,
					'queued_at' => date( 'Y-m-d H:i:s' ),
				] );
			}
			catch ( \Throwable ) {}

			Output::i()->redirect( $backUrl, 'Product promoted to active.' );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'reviewqueue::promote failed upc=' . $upc . ': ' . $e->getMessage(), 'gdcatalog_reviewqueue' ); } catch ( \Throwable ) {}
			Output::i()->redirect( $backUrl, 'Promote failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Promote many products at once from an `ids[]` POST array of
	 * UPCs. CSRF-protected. Skips UPCs that are not in admin_review
	 * so a stale form submission cannot flip active products.
	 */
	protected function promoteBulk(): void
	{
		\IPS\Session::i()->csrfCheck();

		$backUrl = \IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=reviewqueue' );
		$upcs    = Request::i()->ids ?? [];
		if ( !is_array( $upcs ) || empty( $upcs ) )
		{
			Output::i()->redirect( $backUrl, 'No products selected.' );
			return;
		}

		$promoted = 0;
		$skipped  = 0;
		foreach ( $upcs as $upc )
		{
			$upc = (string) $upc;
			if ( $upc === '' ) { continue; }
			try
			{
				$product = Product::load( $upc );
				if ( $product->record_status !== Product::STATUS_ADMIN_REVIEW ) { $skipped++; continue; }
				$product->record_status = Product::STATUS_ACTIVE;
				$product->last_updated  = date( 'Y-m-d H:i:s' );
				$product->save();
				try
				{
					\IPS\Db::i()->replace( 'gd_reindex_queue', [
						'upc'       => $upc,
						'queued_at' => date( 'Y-m-d H:i:s' ),
					] );
				}
				catch ( \Throwable ) {}
				$promoted++;
			}
			catch ( \Throwable ) { $skipped++; }
		}

		Output::i()->redirect(
			$backUrl,
			sprintf( 'Promoted %d product(s), skipped %d.', $promoted, $skipped )
		);
	}
}

class reviewqueue extends _reviewqueue {}
