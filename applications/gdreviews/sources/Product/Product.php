<?php
/**
 * @brief  GD Reviews — Product Content Item (Stage 1 of 4).
 *
 * A thin IPS Content Item that represents "a reviewable product,"
 * keyed by catalog UPC and backed by the gdreviews_products shadow
 * table (which we own). The product's display data (title, image,
 * category) is read live from gd_catalog by UPC on demand — never
 * written. gd_catalog is READ-ONLY forever from this app.
 *
 * Why a shadow table:
 *   IPS's Content Item + Review pattern (mirroring Downloads and
 *   Forums) requires the Item to be backed by its own ActiveRecord
 *   table so reviews can FK to a stable numeric id. We can't add
 *   a review-relation to gd_catalog. So gdreviews_products is a
 *   lightweight join row: (product_id PK, product_upc UNIQUE) —
 *   nothing more than an id that maps to a UPC. The row is created
 *   lazily on first-review-write via loadByUpc(). Product title /
 *   image continue to come from gd_catalog at render time.
 *
 * STAGE 1 SCOPE — class is defined and the shadow table exists,
 * but the Item is NOT yet registered via ContentRouter. Stage 2
 * adds the review-submission form + registers the class as content
 * so it participates in moderation / streams / activity.
 */

namespace IPS\gdreviews\Product;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Product extends \IPS\Content\Item
{
	use \IPS\Content\Reviewable;

	public static ?string $databaseTable   = 'gdreviews_products';
	public static string  $databaseColumnId = 'product_id';
	public static string  $databasePrefix   = 'product_';

	public static string $application = 'gdreviews';
	public static string $module      = 'reviews';

	public static array $databaseColumnMap = [
		'author'    => 'last_review_by',
		'date'      => 'date',
		'title'     => 'title',
		'num_reviews' => 'review_count',
		'last_review'      => 'last_review',
		'last_review_by'   => 'last_review_by',
		'last_review_name' => 'last_review_name',
		'rating'   => 'rating',
	];

	public static string $title = 'gdreviews_product';
	public static string $formLangPrefix = 'gdreviews_review_';

	public static ?string $reviewClass = 'IPS\gdreviews\Product\Review';

	/**
	 * Load or lazily create a Product row for a given UPC. This is
	 * how callers turn a UPC (the natural key that gdsearch and
	 * dealer listings use) into an IPS Content Item. The row is
	 * a thin shadow — no product data lives here beyond a cached
	 * title snapshot. Product title / image come from gd_catalog
	 * (READ-ONLY) at render time.
	 *
	 * Stage 2 uses this from the review-submission form; Stage 3
	 * uses it from the gdsearch product-page tab to render the
	 * review thread.
	 */
	public static function loadByUpc( string $upc, bool $createIfMissing = false ): ?self
	{
		$upc = trim( $upc );
		if ( $upc === '' ) { return null; }

		try
		{
			$row = \IPS\Db::i()->select( '*', 'gdreviews_products', [ 'product_upc=?', $upc ] )->first();
			return static::constructFromData( $row );
		}
		catch ( \UnderflowException )
		{
			if ( !$createIfMissing ) { return null; }
		}

		/* Lazy create — pull the title snapshot from gd_catalog
		   (READ-ONLY SELECT, never write). */
		$title = '';
		try
		{
			$title = (string) \IPS\Db::i()->select( 'title', 'gd_catalog', [ 'upc=?', $upc ] )->first();
		}
		catch ( \Throwable ) {}

		try
		{
			$id = (int) \IPS\Db::i()->insert( 'gdreviews_products', [
				'product_upc'          => $upc,
				'product_title'        => ( $title !== '' ) ? $title : null,
				'product_review_count' => 0,
				'product_rating_real'  => 0.00,
				'product_rating_hits'  => 0,
				'product_rating'       => null,
				'product_date'         => time(),
				'product_last_review'  => 0,
				'product_last_review_by'   => 0,
				'product_last_review_name' => null,
			] );
			return static::load( $id );
		}
		catch ( \Throwable )
		{
			return null;
		}
	}

	/**
	 * URL to this product's review thread. Stage 3 wires the actual
	 * route; for now returns the internal query-string form so any
	 * caller Stage 1 already writes at least gets a valid URL back
	 * instead of null.
	 */
	public function url( ?string $action = null ): \IPS\Http\Url
	{
		$url = \IPS\Http\Url::internal(
			'app=gdreviews&module=reviews&controller=product&upc=' . urlencode( (string) $this->upc ),
			'front'
		);
		if ( $action ) { $url = $url->setQueryString( 'do', $action ); }
		return $url;
	}

	public static function canCreate( \IPS\Member $member, ?\IPS\Node\Model $container = null, bool $showError = false, ?array $source = null ): bool
	{
		/* Products are lazily created by the review-submission flow;
		   an end user does not "create a product." Stage 2 wires the
		   real check via the review form. */
		return false;
	}

	public static function canView( ?\IPS\Member $member = null ): bool
	{
		return true;
	}
}

class Product extends _Product {}
