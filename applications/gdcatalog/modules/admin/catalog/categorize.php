<?php
/**
 * @brief  Categorize Uncategorized products + manage hidden categories.
 */
namespace IPS\gdcatalog\modules\admin\catalog;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _categorize extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'catalog_manage' );
		parent::execute();
	}

	protected function uncatId(): int
	{
		try { return (int) \IPS\Db::i()->select( 'id', 'gd_categories', [ 'name=? AND parent_id=?', 'Uncategorized', 0 ] )->first(); }
		catch ( \Throwable ) { return 0; }
	}

	/** Build [ id => "Parent ▸ Name" ] for all categories, ordered for a <select>. */
	protected function categoryOptions(): array
	{
		$names = [];
		$parent = [];
		foreach ( \IPS\Db::i()->select( 'id, name, parent_id', 'gd_categories', [], 'name ASC' ) as $c )
		{
			$names[ (int) $c['id'] ]  = $c['name'];
			$parent[ (int) $c['id'] ] = (int) $c['parent_id'];
		}
		$opts = [];
		foreach ( $names as $id => $nm )
		{
			$opts[ $id ] = ( $parent[ $id ] && isset( $names[ $parent[ $id ] ] ) ) ? ( $names[ $parent[ $id ] ] . ' ▸ ' . $nm ) : $nm;
		}
		asort( $opts );
		return $opts;
	}

	public function manage(): void
	{
		$uncat = $this->uncatId();

		/* Uncategorized grouped by stored CATID */
		$groups = [];
		if ( $uncat )
		{
			$P = 'JSON_VALUE(raw_distributor_data, \'$.CATID\')';
			foreach ( \IPS\Db::i()->query(
				"SELECT $P AS catid, COUNT(*) AS n, MAX(title) AS sample
				 FROM gd_catalog WHERE record_status='active' AND category_id=" . (int) $uncat . "
				 GROUP BY $P ORDER BY n DESC"
			) as $r )
			{
				$groups[] = [ 'catid' => (string) $r['catid'], 'n' => (int) $r['n'], 'sample' => (string) $r['sample'] ];
			}
		}

		/* All categories with hidden flag, for the show/hide list */
		$cats = [];
		foreach ( \IPS\Db::i()->select( 'id, name, parent_id, hidden, product_count', 'gd_categories', [], 'name ASC' ) as $c )
		{
			$cats[] = $c;
		}

		/* First 300 uncategorized products for per-product override */
		$products = [];
		if ( $uncat )
		{
			foreach ( \IPS\Db::i()->select( 'upc, title, brand', 'gd_catalog', [ "record_status='active' AND category_id=?", $uncat ], 'title ASC', [ 0, 300 ] ) as $p )
			{
				$products[] = $p;
			}
		}

		$options     = $this->categoryOptions();
		$bulkUrl      = (string) \IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=categorize&do=applyBulk' );
		$hiddenUrl    = (string) \IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=categorize&do=saveHidden' );
		$productUrl   = (string) \IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=categorize&do=applyProduct' );
		$csrfKey      = \IPS\Session::i()->csrfKey;

		\IPS\Output::i()->title  = 'Categorize & Hide';
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'catalog', 'gdcatalog', 'admin' )->categorize(
			$groups, $cats, $options, $uncat, $bulkUrl, $hiddenUrl, $productUrl, $csrfKey, $products
		);
	}

	/** Reassign whole CATID groups out of Uncategorized. */
	protected function applyBulk(): void
	{
		\IPS\Session::i()->csrfCheck();
		$uncat = $this->uncatId();
		$map   = \IPS\Request::i()->cat ?? [];   // [ catid => targetCategoryId ]
		$moved = 0;
		$touchedUpcs = [];

		if ( $uncat && is_array( $map ) )
		{
			$P = 'JSON_VALUE(raw_distributor_data, \'$.CATID\')';
			foreach ( $map as $catid => $target )
			{
				$target = (int) $target;
				if ( $target <= 0 || $target === $uncat ) { continue; }
				$where = [ "record_status='active' AND category_id=? AND $P = ?", $uncat, (string) $catid ];
				foreach ( \IPS\Db::i()->select( 'upc', 'gd_catalog', $where ) as $upc ) { $touchedUpcs[] = $upc; }
				$moved += (int) \IPS\Db::i()->update( 'gd_catalog', [ 'category_id' => $target ], $where );
			}
		}

		$this->refreshCountsAndIndex( $touchedUpcs );
		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=categorize' ),
			"Reassigned {$moved} product(s)."
		);
	}

	/** Per-product override. */
	protected function applyProduct(): void
	{
		\IPS\Session::i()->csrfCheck();
		$upc    = (string) ( \IPS\Request::i()->upc ?? '' );
		$target = (int) ( \IPS\Request::i()->category_id ?? 0 );
		if ( $upc !== '' && $target > 0 )
		{
			\IPS\Db::i()->update( 'gd_catalog', [ 'category_id' => $target ], [ 'upc=?', $upc ] );
			$this->refreshCountsAndIndex( [ $upc ] );
		}
		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=categorize' ),
			'Product recategorized.'
		);
	}

	/** Save the hidden flags for all categories. */
	protected function saveHidden(): void
	{
		\IPS\Session::i()->csrfCheck();
		$hidden = \IPS\Request::i()->hidden ?? [];   // [ categoryId => 1 ]
		$hidden = is_array( $hidden ) ? array_map( 'intval', array_keys( $hidden ) ) : [];
		foreach ( \IPS\Db::i()->select( 'id', 'gd_categories' ) as $id )
		{
			\IPS\Db::i()->update( 'gd_categories', [ 'hidden' => ( in_array( (int) $id, $hidden, TRUE ) ? 1 : 0 ) ], [ 'id=?', (int) $id ] );
		}
		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=categorize' ),
			'Visibility saved.'
		);
	}

	/** Refresh product_count for all categories and reindex the touched products. */
	protected function refreshCountsAndIndex( array $upcs ): void
	{
		try {
			\IPS\Db::i()->update( 'gd_categories', [ 'product_count' => 0 ] );
			foreach ( \IPS\Db::i()->select( 'category_id, COUNT(*) AS n', 'gd_catalog', "record_status='active'", null, null, 'category_id' ) as $r )
			{
				\IPS\Db::i()->update( 'gd_categories', [ 'product_count' => (int) $r['n'] ], [ 'id=?', (int) $r['category_id'] ] );
			}
		} catch ( \Throwable ) {}

		if ( $upcs )
		{
			try {
				$idx = new \IPS\gdcatalog\Search\OpenSearchIndexer();
				foreach ( array_unique( $upcs ) as $upc )
				{
					/* v1.0.121 (Phase 5): correct Product class is
					 * \IPS\gdcatalog\Catalog\Product — the bare
					 * \IPS\gdcatalog\Product referenced pre-Phase-5 does
					 * not exist, and the surrounding catch(\Throwable)
					 * was silently swallowing the resulting Error, so
					 * every follow-up reindex from an admin categorize
					 * action was a no-op. */
					try { $idx->indexProduct( \IPS\gdcatalog\Catalog\Product::load( $upc ) ); } catch ( \Throwable ) {}
				}
			} catch ( \Throwable ) {}
		}
	}
}

class categorize extends _categorize {}
