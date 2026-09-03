<?php
/**
 * @brief  GD Master Catalog — Review Queue Controller (v1.0.135)
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

	/**
	 * Full canonical field set exported by exportCsv. Order is
	 * preserved as the CSV column order. `upc` MUST come first
	 * because the round-trip re-import keys on it. Trailing columns
	 * beyond FieldMapper::VALID_FIELDS (primary_source,
	 * record_status, completeness_pct, missing_fields) are
	 * informational only — a manual-upload CSV source's
	 * field_mapping simply omits them, and the importer's mapRecord
	 * drops any unmapped columns automatically.
	 */
	protected const CSV_EXPORT_COLUMNS = [
		/* identity */
		'upc', 'title', 'brand', 'model', 'mpn',
		'category', 'category_id', 'subcategory',
		/* common attributes */
		'caliber', 'action_type',
		'barrel_length', 'capacity', 'finish',
		'weight_oz', 'weight_lbs', 'overall_length',
		'msrp', 'description', 'image_url', 'additional_images', 'features',
		/* flags */
		'nfa_item', 'requires_ffl', 'is_ammo',
		/* firearm-specific (v1.0.141) */
		'gun_type', 'safety_type', 'trigger_type',
		'metal_finish', 'frame_finish',
		'stock_material', 'stock_type',
		'sight_type', 'grips', 'hammer_style',
		'receiver_type', 'receiver_desc', 'frame_material', 'slide_material',
		/* shotgun-specific (v1.0.141) */
		'gauge', 'choke_config', 'chamber',
		/* ammo-specific (v1.0.141) */
		'rounds_per_box', 'bullet_type', 'bullet_weight',
		'muzzle_velocity', 'muzzle_energy', 'boxes_per_case', 'casing_material',
		/* optic-specific (v1.0.141) — user reported these were missing */
		'magnification', 'objective_mm', 'reticle', 'tube_diameter', 'eye_relief',
		/* informational, ignored on re-import (fall outside VALID_FIELDS) */
		'primary_source', 'record_status', 'completeness_pct', 'missing_fields',
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
		/* v1.0.135: UPC search — strip to digits so LIKE wildcards
		 * ('%', '_') can't inject and blow up the filter. UPCs are
		 * numeric per spec (12–14 digits). Empty after strip = no
		 * filter applied. */
		$upcFilter = preg_replace( '/\D+/', '', (string) ( Request::i()->upc_search ?? '' ) ) ?? '';

		$where = [ 'record_status=?' ];
		$args  = [ Product::STATUS_ADMIN_REVIEW ];
		if ( $sourceFilter !== '' )
		{
			$where[] = 'FIND_IN_SET(?, distributor_sources) > 0';
			$args[]  = $sourceFilter;
		}
		if ( $upcFilter !== '' )
		{
			$where[] = 'upc LIKE ?';
			$args[]  = '%' . $upcFilter . '%';
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

		$filterQs = ( $sourceFilter !== '' ? '&source=' . urlencode( $sourceFilter ) : '' )
			. ( $upcFilter !== '' ? '&upc_search=' . urlencode( $upcFilter ) : '' );

		$prevUrl = $page > 1 ? (string) \IPS\Http\Url::internal(
			'app=gdcatalog&module=catalog&controller=reviewqueue&page=' . ( $page - 1 ) . $filterQs
		) : '';
		$nextUrl = $page < $totalPages ? (string) \IPS\Http\Url::internal(
			'app=gdcatalog&module=catalog&controller=reviewqueue&page=' . ( $page + 1 ) . $filterQs
		) : '';

		$promoteBulkUrl = (string) \IPS\Http\Url::internal(
			'app=gdcatalog&module=catalog&controller=reviewqueue&do=promoteBulk'
		)->csrf();

		$exportCsvUrl = (string) \IPS\Http\Url::internal(
			'app=gdcatalog&module=catalog&controller=reviewqueue&do=exportCsv' . $filterQs
		);

		$exportCategoriesUrl = (string) \IPS\Http\Url::internal(
			'app=gdcatalog&module=catalog&controller=reviewqueue&do=exportCategoriesCsv'
		);

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
			self::CRITICAL_FIELDS,
			$exportCsvUrl,
			$exportCategoriesUrl,
			$upcFilter
		);
	}

	/**
	 * v1.0.133: export every gd_catalog row in admin_review (optionally
	 * filtered by source slug) as a CSV download. Rows that need
	 * enrichment are streamed with columns aligned to FieldMapper's
	 * canonical VALID_FIELDS plus four informational columns
	 * (primary_source, record_status, completeness_pct, missing_fields)
	 * so a downstream AI enrichment step can prioritise gaps.
	 *
	 * Round-trip contract: the informational trailing columns are
	 * deliberately named to fall OUTSIDE FieldMapper::VALID_FIELDS,
	 * so a manual-upload CSV source's field_mapping can simply omit
	 * them and mapRecord drops them on re-import. The keyed lookup
	 * is on `upc` — every row must retain its UPC or the re-import
	 * cannot match the existing catalog row.
	 *
	 * GET-only, read-only. No CSRF check (per CLAUDE.md rule #62 —
	 * ->csrf() must never appear on GET URLs that render responses).
	 */
	protected function exportCsv(): void
	{
		$sourceFilter = trim( (string) ( Request::i()->source ?? '' ) );
		$upcFilter    = preg_replace( '/\D+/', '', (string) ( Request::i()->upc_search ?? '' ) ) ?? '';

		$where = [ 'record_status=?' ];
		$args  = [ Product::STATUS_ADMIN_REVIEW ];
		if ( $sourceFilter !== '' )
		{
			$where[] = 'FIND_IN_SET(?, distributor_sources) > 0';
			$args[]  = $sourceFilter;
		}
		if ( $upcFilter !== '' )
		{
			$where[] = 'upc LIKE ?';
			$args[]  = '%' . $upcFilter . '%';
		}
		$whereSql = [ implode( ' AND ', $where ), ...$args ];

		$filename = 'review_queue_'
			. ( $sourceFilter !== '' ? preg_replace( '/[^a-z0-9_-]+/i', '', $sourceFilter ) . '_' : '' )
			. ( $upcFilter !== '' ? 'upc' . $upcFilter . '_' : '' )
			. date( 'Ymd_His' ) . '.csv';

		$fh = fopen( 'php://memory', 'w+' );
		fputcsv( $fh, self::CSV_EXPORT_COLUMNS );

		try
		{
			$rs = \IPS\Db::i()->select(
				'*', 'gd_catalog', $whereSql,
				'last_updated DESC'
			);
			foreach ( $rs as $row )
			{
				$missing = [];
				$present = 0;
				foreach ( self::CRITICAL_FIELDS as $cf )
				{
					$v = $row[$cf] ?? null;
					if ( $v === null || $v === '' || $v === 0 || $v === '0' )
					{
						$missing[] = $cf;
					}
					else
					{
						$present++;
					}
				}
				$completeness = (int) round( ( $present / count( self::CRITICAL_FIELDS ) ) * 100 );

				$line = [];
				foreach ( self::CSV_EXPORT_COLUMNS as $col )
				{
					switch ( $col )
					{
						case 'completeness_pct':
							$line[] = $completeness;
							break;
						case 'missing_fields':
							$line[] = implode( '|', $missing );
							break;
						default:
							$v = $row[ $col ] ?? '';
							$line[] = is_scalar( $v ) ? (string) $v : '';
					}
				}
				fputcsv( $fh, $line );
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'reviewqueue::exportCsv failed: ' . $e->getMessage(), 'gdcatalog_reviewqueue' ); } catch ( \Throwable ) {}
		}

		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh );

		Output::i()->sendOutput(
			$csv,
			200,
			'text/csv; charset=utf-8',
			[ 'Content-Disposition' => 'attachment; filename="' . $filename . '"' ]
		);
	}

	/**
	 * v1.0.134: export the full canonical category list as a CSV so it
	 * can be handed to an AI enrichment step alongside the Review Queue
	 * CSV. The AI is instructed to use ONLY names from this list in the
	 * `category` column of the enriched CSV; the manual-upload CSV
	 * source's category_mapping JSON resolves name → id at import.
	 *
	 * Columns: id, name, slug, parent_id, parent_name, full_path.
	 * `full_path` is the hierarchical breadcrumb ("Handguns > Pistols")
	 * so the AI can disambiguate leaf categories that share names.
	 *
	 * GET-only, read-only, no CSRF (rule #62).
	 */
	protected function exportCategoriesCsv(): void
	{
		$filename = 'catalog_categories_' . date( 'Ymd_His' ) . '.csv';

		/* Build id → [name, parent_id] index so full_path breadcrumbs
		 * resolve without an N+1 lookup per row. */
		$byId = [];
		try
		{
			foreach ( \IPS\Db::i()->select( 'id, name, slug, parent_id', 'gd_categories' ) as $r )
			{
				$byId[ (int) $r['id'] ] = [
					'name'      => (string) $r['name'],
					'slug'      => (string) $r['slug'],
					'parent_id' => (int)    ( $r['parent_id'] ?? 0 ),
				];
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'reviewqueue::exportCategoriesCsv select failed: ' . $e->getMessage(), 'gdcatalog_reviewqueue' ); } catch ( \Throwable ) {}
		}

		$fullPath = static function ( int $id ) use ( $byId ): string {
			$segments = [];
			$guard    = 0;
			$cur      = $id;
			while ( $cur > 0 && isset( $byId[ $cur ] ) && $guard++ < 16 )
			{
				array_unshift( $segments, $byId[ $cur ]['name'] );
				$cur = $byId[ $cur ]['parent_id'];
			}
			return implode( ' > ', $segments );
		};

		$fh = fopen( 'php://memory', 'w+' );
		fputcsv( $fh, [ 'id', 'name', 'slug', 'parent_id', 'parent_name', 'full_path' ] );

		/* Order roots first, then children by parent, then alphabetical —
		 * mirrors the CLI dump ordering so pasting into an AI prompt
		 * groups siblings together. */
		uksort( $byId, static function ( $a, $b ) use ( $byId ) {
			$pa = $byId[ $a ]['parent_id'];
			$pb = $byId[ $b ]['parent_id'];
			if ( $pa !== $pb ) { return $pa <=> $pb; }
			return strcasecmp( $byId[ $a ]['name'], $byId[ $b ]['name'] );
		} );

		foreach ( $byId as $id => $row )
		{
			$parentName = ( $row['parent_id'] > 0 && isset( $byId[ $row['parent_id'] ] ) )
				? $byId[ $row['parent_id'] ]['name']
				: '';
			fputcsv( $fh, [
				$id,
				$row['name'],
				$row['slug'],
				$row['parent_id'] > 0 ? $row['parent_id'] : '',
				$parentName,
				$fullPath( (int) $id ),
			] );
		}

		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh );

		Output::i()->sendOutput(
			$csv,
			200,
			'text/csv; charset=utf-8',
			[ 'Content-Disposition' => 'attachment; filename="' . $filename . '"' ]
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
