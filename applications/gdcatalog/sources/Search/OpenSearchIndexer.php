<?php
/**
 * @brief       GD Master Catalog — OpenSearch Indexer
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 * @since       12 Apr 2026
 *
 * Manages the gunrack_products OpenSearch index. Connects directly to
 * http://localhost:9200 (same server, no auth, no proxy).
 *
 * Index mapping uses "dynamic": "strict" per Appendix C.
 * All queries use the parameterised query builder — never string-
 * interpolate user input into query DSL.
 */

namespace IPS\gdcatalog\Search;

/* To prevent PHP errors (extending class does not exist) revealing path */

use IPS\gdcatalog\Catalog\Product;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class OpenSearchIndexer
{
	/**
	 * @brief OpenSearch host — read from settings, defaults to localhost
	 */
	protected string $host;

	/**
	 * @brief Index name — read from settings
	 */
	protected string $index;

	/**
	 * Constructor.
	 */
	public function __construct()
	{
		$this->host  = \IPS\Settings::i()->gdcatalog_opensearch_host  ?: 'http://localhost:9200';
		$this->index = \IPS\Settings::i()->gdcatalog_opensearch_index ?: 'gunrack_products';
	}

	/**
	 * Get a singleton instance.
	 *
	 * @return static
	 */
	public static function i(): static
	{
		static $instance = null;
		if ( $instance === null )
		{
			$instance = new static;
		}
		return $instance;
	}

	/* ================================================================
	 *  Index Management
	 * ================================================================ */

	/**
	 * Create the index with strict mapping (Section 2.8 + Appendix C).
	 *
	 * @return bool
	 */
	public function createIndex(): bool
	{
		$mapping = [
			'settings' => [
				'number_of_shards'   => 1,
				'number_of_replicas' => 0,
				'analysis' => [
					'analyzer' => [
						'product_analyzer' => [
							'type'      => 'custom',
							'tokenizer' => 'standard',
							'filter'    => [ 'lowercase', 'asciifolding' ],
						],
					],
				],
			],
			'mappings' => [
				'dynamic'    => 'strict',
				'properties' => [
					'upc'          => [ 'type' => 'keyword' ],
					'title'        => [ 'type' => 'text', 'analyzer' => 'product_analyzer', 'fields' => [
						'raw' => [ 'type' => 'keyword' ],
					]],
					'brand'        => [ 'type' => 'keyword' ],
					'model'        => [ 'type' => 'text', 'analyzer' => 'product_analyzer' ],
					'mpn'          => [ 'type' => 'text', 'analyzer' => 'product_analyzer', 'fields' => [ 'keyword' => [ 'type' => 'keyword' ] ] ],
					'category'     => [ 'type' => 'keyword' ],
					'subcategory'  => [ 'type' => 'keyword' ],
					'caliber'      => [ 'type' => 'keyword' ],
					'action_type'  => [ 'type' => 'keyword' ],
					'barrel_length'=> [ 'type' => 'float' ],
					'capacity'     => [ 'type' => 'integer' ],
					'msrp'         => [ 'type' => 'float' ],
					'nfa_item'     => [ 'type' => 'boolean' ],
					'requires_ffl' => [ 'type' => 'boolean' ],
					'is_ammo'      => [ 'type' => 'boolean' ],
					'grain'        => [ 'type' => 'integer' ],
					'case_type'    => [ 'type' => 'keyword' ],
					'bullet_type'  => [ 'type' => 'keyword' ],
					'muzzle_velocity' => [ 'type' => 'integer' ],
					'record_status'=> [ 'type' => 'keyword' ],
					'image_url'    => [ 'type' => 'keyword', 'index' => false ],
					'description'  => [ 'type' => 'text', 'analyzer' => 'product_analyzer' ],
					'holster_type'     => [ 'type' => 'keyword' ],
					'holster_color'    => [ 'type' => 'keyword' ],
					'holster_material' => [ 'type' => 'keyword' ],
					'holster_hand'     => [ 'type' => 'keyword' ],
					'apparel_pattern'  => [ 'type' => 'keyword' ],
					'apparel_size'     => [ 'type' => 'keyword' ],
					'apparel_material' => [ 'type' => 'keyword' ],
					'hunt_call_type'   => [ 'type' => 'keyword' ],
					'hunt_game'        => [ 'type' => 'keyword' ],
					'blade_shape'      => [ 'type' => 'keyword' ],
					'blade_length'     => [ 'type' => 'keyword' ],
					'blade_material'   => [ 'type' => 'keyword' ],
					'blade_edge'       => [ 'type' => 'keyword' ],
					'knife_handle'     => [ 'type' => 'keyword' ],
					'optic_magnification' => [ 'type' => 'keyword' ],
					'optic_objective'     => [ 'type' => 'keyword' ],
				],
			],
		];

		try
		{
			$response = $this->request( 'PUT', '/' . $this->index, $mapping );
			return isset( $response['acknowledged'] ) && $response['acknowledged'] === true;
		}
		catch ( \RuntimeException $e )
		{
			$msg = $e->getMessage();
			if ( str_contains( $msg, 'create-index blocked' ) || str_contains( $msg, 'already_exists' ) || str_contains( $msg, 'already exists' ) )
			{
				return true;
			}
			throw $e;
		}
	}

	/**
	 * Delete the index entirely.
	 *
	 * @return bool
	 */
	public function deleteIndex(): bool
	{
		$response = $this->request( 'DELETE', '/' . $this->index );
		return isset( $response['acknowledged'] ) && $response['acknowledged'] === true;
	}

	/**
	 * Check whether the index exists.
	 *
	 * @return bool
	 */
	public function indexExists(): bool
	{
		try
		{
			$this->request( 'HEAD', '/' . $this->index );
			return true;
		}
		catch ( \RuntimeException )
		{
			return false;
		}
	}

	/**
	 * Get index stats: document count, size.
	 *
	 * @return array{doc_count: int, size_bytes: int}
	 */
	public function getStats(): array
	{
		try
		{
			$response = $this->request( 'GET', '/' . $this->index . '/_stats' );
			$total    = $response['_all']['primaries'] ?? [];

			return [
				'doc_count'  => $total['docs']['count'] ?? 0,
				'size_bytes' => $total['store']['size_in_bytes'] ?? 0,
			];
		}
		catch ( \RuntimeException )
		{
			return [ 'doc_count' => 0, 'size_bytes' => 0 ];
		}
	}

	/* ================================================================
	 *  Document CRUD
	 * ================================================================ */

	/**
	 * Index a single product (create or update).
	 *
	 * @param  Product $product
	 * @return void
	 */
	public function indexProduct( Product $product ): void
	{
		$doc = $this->productToDocument( $product );
		$this->request( 'PUT', '/' . $this->index . '/_doc/' . urlencode( $product->upc ), $doc );
	}

	/**
	 * Delete a single product from the index.
	 *
	 * @param  string $upc
	 * @return void
	 */
	public function deleteProduct( string $upc ): void
	{
		try
		{
			$this->request( 'DELETE', '/' . $this->index . '/_doc/' . urlencode( $upc ) );
		}
		catch ( \RuntimeException )
		{
			/* Document may not exist — ignore */
		}
	}

	/**
	 * Process the reindex queue — index all products queued since last run.
	 *
	 * @param  int $batchSize  Max documents per batch
	 * @return int Number of documents indexed
	 */
	public function processQueue( int $batchSize = 500 ): int
	{
		$count = 0;

		$rows = \IPS\Db::i()->select(
			'upc', 'gd_reindex_queue',
			null, 'queued_at ASC', [ 0, $batchSize ]
		);

		$upcs = [];
		foreach ( $rows as $row )
		{
			$upcs[] = $row;
		}

		if ( empty( $upcs ) )
		{
			return 0;
		}

		/* Bulk index */
		$bulkBody = '';
		foreach ( $upcs as $upc )
		{
			try
			{
				$product = Product::load( $upc );
				$doc     = $this->productToDocument( $product );

				$bulkBody .= json_encode( [ 'index' => [ '_index' => $this->index, '_id' => $upc ] ] ) . "\n";
				$bulkBody .= json_encode( $doc ) . "\n";
				$count++;
			}
			catch ( \OutOfRangeException )
			{
				/* Product deleted — remove from index */
				$bulkBody .= json_encode( [ 'delete' => [ '_index' => $this->index, '_id' => $upc ] ] ) . "\n";
			}
		}

		if ( $bulkBody !== '' )
		{
			$this->request( 'POST', '/_bulk', null, $bulkBody );
		}

		/* Remove processed items from queue */
		$placeholders = implode( ',', array_fill( 0, \count( $upcs ), '?' ) );
		\IPS\Db::i()->delete( 'gd_reindex_queue', [ 'upc IN(' . $placeholders . ')', ...$upcs ] );

		return $count;
	}

	/**
	 * Full rebuild — delete index, recreate, and reindex all active products.
	 *
	 * @return int  Number of documents indexed
	 */
	/**
	 * Delete + recreate the index (empty) with the current mapping, and clear the reindex queue.
	 */
	public function recreateIndex(): void
	{
		if ( $this->indexExists() )
		{
			$this->deleteIndex();
		}
		try
		{
			$this->createIndex();
		}
		catch ( \RuntimeException $e )
		{
			$msg = $e->getMessage();
			if ( !( str_contains( $msg, 'create-index blocked' ) || str_contains( $msg, 'already_exists' ) || str_contains( $msg, 'already exists' ) ) )
			{
				throw $e;
			}
		}
		try { \IPS\Db::i()->delete( 'gd_reindex_queue' ); } catch ( \Throwable ) {}
	}

	/**
	 * Bulk-index one batch of active products by offset. Returns the number indexed.
	 * Used by the RebuildSearchIndex background queue so a full rebuild never blocks/times out.
	 */
	public function indexBatch( int $offset, int $batchSize = 250 ): int
	{
		$indexColumns = 'upc, title, brand, model, mpn, category_id, subcategory, caliber, action_type, barrel_length, capacity, msrp, nfa_item, requires_ffl, is_ammo, grain, case_type, bullet_type, muzzle_velocity, record_status, image_url, description, holster_type, holster_color, holster_material, holster_hand, apparel_pattern, apparel_size, apparel_material, hunt_call_type, hunt_game, blade_shape, blade_length, blade_material, blade_edge, knife_handle, optic_magnification, optic_objective';
		$rows = \IPS\Db::i()->select(
			$indexColumns,
			'gd_catalog',
			[ 'record_status=?', Product::STATUS_ACTIVE ],
			'upc ASC',
			[ $offset, $batchSize ]
		);

		$bulkBody = '';
		$count    = 0;
		foreach ( $rows as $row )
		{
			$product = Product::constructFromData( $row );
			$doc     = $this->productToDocument( $product );
			$bulkBody .= json_encode( [ 'index' => [ '_index' => $this->index, '_id' => $product->upc ] ] ) . "\n";
			$bulkBody .= json_encode( $doc ) . "\n";
			$count++;
		}
		if ( $bulkBody !== '' )
		{
			$this->request( 'POST', '/_bulk', null, $bulkBody );
		}
		return $count;
	}

	public function rebuildIndex(): int
	{
		/* Drop and recreate */
		if ( $this->indexExists() )
		{
			$this->deleteIndex();
		}

		try
		{
			$this->createIndex();
		}
		catch ( \RuntimeException $e )
		{
			$msg = $e->getMessage();
			if ( str_contains( $msg, 'create-index blocked' ) || str_contains( $msg, 'already_exists' ) || str_contains( $msg, 'already exists' ) )
			{
				try { \IPS\Log::log( 'gdcatalog rebuildIndex: skipping createIndex — ' . $msg, 'gdcatalog_opensearch' ); } catch ( \Throwable ) {}
			}
			else
			{
				throw $e;
			}
		}

		/* Clear the queue */
		\IPS\Db::i()->delete( 'gd_reindex_queue' );

		/* Bulk index all active products — paginated to limit memory */
		$previousMemLimit = ini_get( 'memory_limit' );
		ini_set( 'memory_limit', '256M' );

		$count    = 0;
		$bulkBody = '';
		$batchMax = 250;
		$offset   = 0;

		$indexColumns = 'upc, title, brand, model, mpn, category_id, subcategory, caliber, action_type, barrel_length, capacity, msrp, nfa_item, requires_ffl, is_ammo, grain, case_type, bullet_type, muzzle_velocity, record_status, image_url, description, holster_type, holster_color, holster_material, holster_hand, apparel_pattern, apparel_size, apparel_material, hunt_call_type, hunt_game, blade_shape, blade_length, blade_material, blade_edge, knife_handle, optic_magnification, optic_objective';

		while ( TRUE )
		{
			$rows = \IPS\Db::i()->select(
				$indexColumns,
				'gd_catalog',
				[ 'record_status=?', Product::STATUS_ACTIVE ],
				'upc ASC',
				[ $offset, $batchMax ]
			);

			$batchCount = 0;
			foreach ( $rows as $row )
			{
				$product = Product::constructFromData( $row );
				$doc     = $this->productToDocument( $product );

				$bulkBody .= json_encode( [ 'index' => [ '_index' => $this->index, '_id' => $product->upc ] ] ) . "\n";
				$bulkBody .= json_encode( $doc ) . "\n";
				$count++;
				$batchCount++;
			}

			if ( $bulkBody !== '' )
			{
				$this->request( 'POST', '/_bulk', null, $bulkBody );
				$bulkBody = '';
			}

			unset( $rows );
			gc_collect_cycles();

			if ( $batchCount < $batchMax ) break;
			$offset += $batchMax;
		}

		ini_set( 'memory_limit', $previousMemLimit );

		return $count;
	}

	/* ================================================================
	 *  Document Mapping
	 * ================================================================ */

	/**
	 * Convert a Product model to an OpenSearch document.
	 *
	 * @param  Product $product
	 * @return array
	 */
	protected function productToDocument( Product $product ): array
	{
		$category    = $product->category();
		$catName     = '';
		$subcatName  = '';

		if ( $category !== null )
		{
			if ( $category->isTopLevel() )
			{
				$catName = $category->name;
			}
			else
			{
				$parent = $category->parent();
				$catName    = $parent ? $parent->name : '';
				$subcatName = $category->name;
			}
		}

		return [
			'upc'           => $product->upc,
			'title'         => $product->title ?? '',
			'brand'         => $product->brand ?? '',
			'model'         => $product->model ?? '',
			'mpn'           => $product->mpn ?? '',
			'category'      => $catName,
			'subcategory'   => $subcatName ?: ( $product->subcategory ?? '' ),
			'caliber'       => $product->caliber ?? '',
			'action_type'   => $product->action_type ?? '',
			'barrel_length' => $product->barrel_length ? (float) $product->barrel_length : null,
			'capacity'      => $product->capacity ? (int) $product->capacity : null,
			'msrp'          => $product->msrp ? (float) $product->msrp : null,
			'nfa_item'      => (bool) $product->nfa_item,
			'requires_ffl'  => (bool) $product->requires_ffl,
			'is_ammo'       => (bool) $product->is_ammo,
			'grain'         => $product->grain ? (int) $product->grain : null,
			'case_type'     => $product->case_type ?? '',
			'bullet_type'   => $product->bullet_type ?? '',
			'muzzle_velocity' => $product->muzzle_velocity ? (int) $product->muzzle_velocity : null,
			'record_status' => $product->record_status ?? 'active',
			'image_url'     => $product->image_url ?? '',
			'description'   => $product->description ? mb_substr( $product->description, 0, 5000 ) : '',
			'holster_type'     => $product->holster_type ?? '',
			'holster_color'    => $product->holster_color ?? '',
			'holster_material' => $product->holster_material ?? '',
			'holster_hand'     => $product->holster_hand ?? '',
			'apparel_pattern'  => $product->apparel_pattern ?? '',
			'apparel_size'     => $product->apparel_size ?? '',
			'apparel_material' => $product->apparel_material ?? '',
			'hunt_call_type'   => $product->hunt_call_type ?? '',
			'hunt_game'        => $product->hunt_game ?? '',
			'blade_shape'      => $product->blade_shape ?? '',
			'blade_length'     => $product->blade_length ?? '',
			'blade_material'   => $product->blade_material ?? '',
			'blade_edge'       => $product->blade_edge ?? '',
			'knife_handle'     => $product->knife_handle ?? '',
			'optic_magnification' => $product->optic_magnification ?? '',
			'optic_objective'     => $product->optic_objective ?? '',
		];
	}

	/* ================================================================
	 *  HTTP Transport
	 * ================================================================ */

	/**
	 * Send a request to OpenSearch.
	 *
	 * @param  string      $method   HTTP method
	 * @param  string      $path     URL path (e.g. /gunrack_products/_doc/123)
	 * @param  array|null  $body     JSON body (for PUT/POST with structured data)
	 * @param  string|null $rawBody  Raw body string (for _bulk NDJSON)
	 * @return array  Decoded JSON response
	 * @throws \RuntimeException on connection or HTTP error
	 */
	protected function request( string $method, string $path, ?array $body = null, ?string $rawBody = null ): array
	{
		$url = $this->host . $path;

		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, $method );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 30 );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 5 );

		/* v1.0.30: HEAD requests must set CURLOPT_NOBODY=true. Without it,
		 * PHP curl waits for a response body that never arrives and times
		 * out after CURLOPT_TIMEOUT (30s). This was making indexExists()
		 * unusable - the dashboard hardcoded $osExists = FALSE to avoid
		 * the hang. */
		if ( $method === 'HEAD' )
		{
			curl_setopt( $ch, CURLOPT_NOBODY, true );
			/* For HEAD we don't need 30s - if it doesn't respond fast, it
			 * never will. Keep a short timeout so the dashboard doesn't
			 * hang on a misconfigured server. */
			curl_setopt( $ch, CURLOPT_TIMEOUT, 3 );
		}

		$headers = [];

		if ( $rawBody !== null )
		{
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $rawBody );
			$headers[] = 'Content-Type: application/x-ndjson';
		}
		elseif ( $body !== null )
		{
			$jsonBody = json_encode( $body );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $jsonBody );
			$headers[] = 'Content-Type: application/json';
		}

		if ( !empty( $headers ) )
		{
			curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
		}

		$responseBody = curl_exec( $ch );
		$httpCode     = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$error        = curl_error( $ch );
		curl_close( $ch );

		if ( $responseBody === false )
		{
			throw new \RuntimeException( 'OpenSearch connection failed: ' . $error );
		}

		/* HEAD requests return no body */
		if ( $method === 'HEAD' )
		{
			if ( $httpCode >= 400 )
			{
				throw new \RuntimeException( 'OpenSearch HEAD request failed: HTTP ' . $httpCode );
			}
			return [];
		}

		$decoded = json_decode( $responseBody, true );

		if ( $httpCode >= 400 )
		{
			$errorMsg = $decoded['error']['reason'] ?? $responseBody;
			throw new \RuntimeException( 'OpenSearch error (HTTP ' . $httpCode . '): ' . $errorMsg );
		}

		return $decoded ?? [];
	}
}
