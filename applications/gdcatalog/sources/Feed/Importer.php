<?php
/**
 * @brief       GD Master Catalog — Feed Importer
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 * @since       12 Apr 2026
 *
 * Core import engine implementing Section 2.6.
 * For each distributor feed: fetch → parse → upsert with conflict
 * resolution → queue OpenSearch reindex → write import log.
 *
 * This class does NOT contain conflict resolution logic — that lives
 * in ConflictResolver (Step 5). This class orchestrates the pipeline.
 */

namespace IPS\gdcatalog\Feed;

/* To prevent PHP errors (extending class does not exist) revealing path */

use IPS\gdcatalog\Catalog\Product;
use IPS\gdcatalog\Feed\Distributor;
use IPS\gdcatalog\Feed\FieldMapper;
use IPS\gdcatalog\Feed\CategoryMapper;
use IPS\gdcatalog\Feed\Parser\XmlParser;
use IPS\gdcatalog\Feed\Parser\JsonParser;
use IPS\gdcatalog\Feed\Parser\CsvParser;
use IPS\gdcatalog\Feed\Distributor\SportsSouthClient;
use IPS\gdcatalog\Log\ImportLog;
use IPS\gdcatalog\Compliance\FlagProcessor;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class Importer
{
	/**
	 * v1.0.10: Safety cap on rows processed per import run. Sports South's
	 * full catalog is 58,000+ products. Until proper paging/background
	 * tasking ships in v1.0.13, we cap each run to avoid PHP timeouts and
	 * runaway DB writes. Raise this carefully as paging gets implemented.
	 */
	public const MAX_RECORDS_PER_RUN = 1000;

	/**
	 * v1.0.11: Lazy-loaded brand lookup for Sports South enrichment.
	 * Keyed by brdno (string) => brdnam.
	 */
	protected ?array $sportsSouthBrandLookup = null;

	/**
	 * v1.0.11: Lazy-loaded category lookup for Sports South enrichment.
	 * Keyed by catid (string) => catdes.
	 */
	protected ?array $sportsSouthCategoryLookup = null;

	/**
	 * v1.0.15: Lazy-loaded Sports South CATID -> gd_categories.id mapping.
	 * Keyed by sportssouth catid (string) => gd_category_id (int).
	 */
	protected ?array $sportsSouthCategoryMap = null;

	protected Distributor $feed;
	protected FieldMapper $fieldMapper;
	protected CategoryMapper $categoryMapper;
	protected ImportLog $log;

	/** @var array UPCs seen in this run — for discontinuation tracking */
	protected array $seenUpcs = [];

	/** @var array Running stats for import log */
	protected array $stats = [
		'total'     => 0,
		'created'   => 0,
		'updated'   => 0,
		'skipped'   => 0,
		'errored'   => 0,
		'conflicts' => 0,
	];

	/**
	 * Run a full import for a single distributor feed.
	 *
	 * @param  Distributor $feed
	 * @return ImportLog
	 */
	public static function run( Distributor $feed ): ImportLog
	{
		$importer = new static( $feed );
		return $importer->execute();
	}

	/**
	 * Constructor.
	 *
	 * @param  Distributor $feed
	 */
	public function __construct( Distributor $feed )
	{
		$this->feed           = $feed;
		$this->fieldMapper    = new FieldMapper( $feed->field_mapping );
		$this->categoryMapper = new CategoryMapper( $feed->getCategoryMappingJson() );
	}

	/**
	 * Execute the full import pipeline.
	 *
	 * @return ImportLog
	 */
	public function execute(): ImportLog
	{
		$this->log = ImportLog::startRun( (int) $this->feed->id, $this->feed->distributor );
		$this->feed->markRunning();

		try
		{
			/* 1. Fetch feed content */
			$content = $this->fetchFeed();

			/* 2. Parse into records */
			$records = $this->parseFeed( $content );

			/* v1.0.10: Cap records per run. Sports South's full catalog
			 * is too large for synchronous processing. Real paging arrives
			 * in v1.0.13. */
			$originalCount = \count( $records );
			if ( $originalCount > static::MAX_RECORDS_PER_RUN )
			{
				$records = array_slice( $records, 0, static::MAX_RECORDS_PER_RUN );
				try
				{
					\IPS\Log::log( sprintf(
						'Importer: feed_id=%d truncated %d records to %d (MAX_RECORDS_PER_RUN). v1.0.13 will add proper paging.',
						(int) $this->feed->id,
						$originalCount,
						static::MAX_RECORDS_PER_RUN
					), 'gdcatalog_importer' );
				}
				catch ( \Throwable ) {}
			}

			$this->stats['total'] = \count( $records );

			/* 3. Process each record */
			foreach ( $records as $record )
			{
				$this->processRecord( $record );
			}

			/* 4. Handle discontinuation — products not seen in this run */
			$this->processDiscontinuations();

			/* 5. Complete */
			$this->log->complete( $this->stats );
			$this->feed->markCompleted( $this->stats['total'] );
		}
		catch ( \Exception $e )
		{
			$this->log->fail( $e->getMessage() );
			$this->feed->markFailed();
		}

		return $this->log;
	}

	/**
	 * Fetch the feed content from the configured URL.
	 *
	 * @return string  Raw feed content
	 * @throws \RuntimeException
	 */
	protected function fetchFeed(): string
	{
		$url = $this->feed->feed_url;

		if ( empty( $url ) )
		{
			throw new \RuntimeException( 'Feed URL is not configured' );
		}

		$authType = $this->feed->auth_type;
		$creds    = null;

		if ( $authType !== 'none' )
		{
			$credsRaw = $this->feed->getCredentials();
			$creds    = $credsRaw ? json_decode( $credsRaw, true ) : null;
		}

		/* FTP fetch */
		if ( $authType === 'ftp' )
		{
			return $this->fetchFtp( $url, $creds );
		}

		/* v1.0.10: Sports South .asmx web service fetch.
		 * Goes through SportsSouthClient (POST form-encoded) rather than the
		 * generic HTTP GET path used for normal feeds. The "feed content"
		 * we return is the raw XML from the .asmx response; parseFeed() will
		 * then dispatch back to SportsSouthClient::parseTableRows. */
		if ( $authType === 'sportssouth' )
		{
			$client = SportsSouthClient::fromDistributor( $this->feed );

			$credErrors = $client->validate();
			if ( !empty( $credErrors ) )
			{
				throw new \RuntimeException(
					'Sports South credentials invalid: ' . implode( '; ', $credErrors )
				);
			}

			/* For now, hardcode the "since" date to 30 days ago to keep
			 * responses manageable. v1.0.13 will track last_run timestamp
			 * per feed and pass that here. LastItem=0 starts paging from
			 * the beginning; we only pull the first page (1000 rows) per
			 * MAX_RECORDS_PER_RUN. */
			$sinceDate = date( 'n/j/Y', strtotime( '-30 days' ) );

			$products = $client->dailyItemUpdate( $sinceDate, 0 );

			/* v1.0.11: Enrich each record with brand name + image URL
			 * before passing to FieldMapper. */
			$enriched = [];
			foreach ( $products as $rawRecord )
			{
				$enriched[] = $this->enrichSportsSouthRecord( $rawRecord );
			}

			/* Re-encode as JSON for parseFeed() to handle. We bypass the
			 * normal XML/JSON/CSV parser since SportsSouthClient already
			 * returned parsed records. This is a clean handoff: parseFeed()
			 * detects auth_type='sportssouth' and just JSON-decodes. */
			return json_encode( $enriched );
		}

		/* HTTP fetch */
		$request = \IPS\Http\Url::external( $url )->request( 120 );

		if ( $authType === 'basic' && $creds )
		{
			$request = $request->login(
				$creds['username'] ?? '',
				$creds['password'] ?? ''
			);
		}
		elseif ( $authType === 'apikey' && $creds )
		{
			$apiKey = $creds['api_key'] ?? $creds['key'] ?? '';
			$headerName = $creds['header'] ?? 'X-API-Key';
			$request = $request->setHeaders( [ $headerName => $apiKey ] );
		}

		$response = $request->get();

		if ( $response->httpResponseCode !== 200 )
		{
			throw new \RuntimeException(
				'Feed fetch failed: HTTP ' . $response->httpResponseCode
			);
		}

		return (string) $response;
	}

	/**
	 * Fetch a feed via FTP.
	 *
	 * @param  string     $url
	 * @param  array|null $creds
	 * @return string
	 * @throws \RuntimeException
	 */
	protected function fetchFtp( string $url, ?array $creds ): string
	{
		$host = $creds['host'] ?? parse_url( $url, PHP_URL_HOST );
		$user = $creds['username'] ?? 'anonymous';
		$pass = $creds['password'] ?? '';
		$path = $creds['path'] ?? parse_url( $url, PHP_URL_PATH );

		$conn = ftp_connect( $host );
		if ( !$conn || !ftp_login( $conn, $user, $pass ) )
		{
			throw new \RuntimeException( 'FTP connection failed: ' . $host );
		}

		ftp_pasv( $conn, true );

		$tmpFile = tempnam( sys_get_temp_dir(), 'gd_feed_' );
		if ( !ftp_get( $conn, $tmpFile, $path, FTP_BINARY ) )
		{
			ftp_close( $conn );
			throw new \RuntimeException( 'FTP download failed: ' . $path );
		}

		ftp_close( $conn );
		$content = file_get_contents( $tmpFile );
		unlink( $tmpFile );

		return $content;
	}

	/**
	 * Parse raw feed content into an array of records.
	 *
	 * @param  string $content
	 * @return array<int, array<string, string>>
	 */
	protected function parseFeed( string $content ): array
	{
		/* v1.0.10: For sportssouth auth_type, fetchFeed() already returned
		 * pre-parsed records as a JSON array. Just decode and pass through. */
		if ( $this->feed->auth_type === 'sportssouth' )
		{
			$decoded = json_decode( $content, true );
			return is_array( $decoded ) ? $decoded : [];
		}

		return match ( $this->feed->feed_format )
		{
			'xml'  => XmlParser::parse( $content ),
			'json' => JsonParser::parse( $content ),
			'csv'  => CsvParser::parse( $content ),
			default => throw new \RuntimeException( 'Unknown feed format: ' . $this->feed->feed_format ),
		};
	}

	/**
	 * v1.0.11: Enrich a Sports South raw record before field mapping.
	 *
	 * Transforms:
	 *   - PICREF (e.g. "1533") → full image URL (https://media.../large/1533.jpg)
	 *   - IMFGNO / ITBRDNO (e.g. "275") → brand name (e.g. "Henry")
	 *   - CATID → category description (stored but not directly mapped yet)
	 *
	 * Brand and category lookups come from gd_sportssouth_brands /
	 * gd_sportssouth_categories tables seeded by feeds.php::refreshLookups.
	 * If a lookup miss occurs (e.g. lookups haven't been refreshed yet),
	 * the original ID is kept as the brand string - admin can review.
	 *
	 * @param  array $record  Raw record from SportsSouthClient
	 * @return array  Enriched record (still keyed by Sports South field names)
	 */
	protected function enrichSportsSouthRecord( array $record ): array
	{
		/* Lazy-load brand lookup once per import run */
		if ( $this->sportsSouthBrandLookup === null )
		{
			$this->sportsSouthBrandLookup = [];
			try
			{
				foreach ( \IPS\Db::i()->select( 'brdno, brdnam', 'gd_sportssouth_brands' ) as $brandRow )
				{
					$this->sportsSouthBrandLookup[ (string) $brandRow['brdno'] ] = (string) $brandRow['brdnam'];
				}
			}
			catch ( \Throwable ) {}
		}

		/* Lazy-load category lookup once per import run */
		if ( $this->sportsSouthCategoryLookup === null )
		{
			$this->sportsSouthCategoryLookup = [];
			try
			{
				foreach ( \IPS\Db::i()->select( 'catid, catdes', 'gd_sportssouth_categories' ) as $catRow )
				{
					$this->sportsSouthCategoryLookup[ (string) $catRow['catid'] ] = (string) $catRow['catdes'];
				}
			}
			catch ( \Throwable ) {}
		}

		/* Resolve brand: try IMFGNO first, fall back to ITBRDNO. If neither
		 * resolves, leave the original IMFGNO value as brand string so admin
		 * has something to work with on review. */
		$brandKey = (string) ( $record['IMFGNO'] ?? $record['ITBRDNO'] ?? '' );
		if ( $brandKey !== '' && isset( $this->sportsSouthBrandLookup[ $brandKey ] ) )
		{
			/* Inject a synthetic field that field_mapping can pick up */
			$record['_BRAND_NAME'] = $this->sportsSouthBrandLookup[ $brandKey ];
		}
		else
		{
			$record['_BRAND_NAME'] = $brandKey;
		}

		/* Lazy-load category map once per import run */
		if ( $this->sportsSouthCategoryMap === null )
		{
			$this->sportsSouthCategoryMap = [];
			try
			{
				foreach ( \IPS\Db::i()->select( 'sportssouth_catid, gd_category_id', 'gd_sportssouth_category_map' ) as $mapRow )
				{
					$gdCatId = (int) $mapRow['gd_category_id'];
					if ( $gdCatId > 0 )
					{
						$this->sportsSouthCategoryMap[ (string) $mapRow['sportssouth_catid'] ] = $gdCatId;
					}
				}
			}
			catch ( \Throwable ) {}
		}

		/* Resolve category description (informational) */
		$catKey = (string) ( $record['CATID'] ?? '' );
		if ( $catKey !== '' && isset( $this->sportsSouthCategoryLookup[ $catKey ] ) )
		{
			$record['_CATEGORY_DESC'] = $this->sportsSouthCategoryLookup[ $catKey ];
		}

		/* v1.0.15: Resolve gd_categories.id from Sports South CATID via mapping table.
		 * Inject _CATEGORY_ID for the FieldMapper to pick up. */
		if ( $catKey !== '' && isset( $this->sportsSouthCategoryMap[ $catKey ] ) )
		{
			$record['_CATEGORY_ID'] = (string) $this->sportsSouthCategoryMap[ $catKey ];
		}

		/* Transform PICREF to full image URL. */
		$picref = (string) ( $record['PICREF'] ?? '' );
		$itemno = (string) ( $record['ITEMNO'] ?? '' );
		if ( $picref !== '' || $itemno !== '' )
		{
			$record['PICREF'] = \IPS\gdcatalog\Feed\Distributor\SportsSouthClient::imageUrlForPicref( $picref, $itemno );
		}

		return $record;
	}

	/**
	 * Process a single feed record: map fields, upsert product.
	 *
	 * @param  array<string, string> $rawRecord
	 * @return void
	 */
	protected function processRecord( array $rawRecord ): void
	{
		try
		{
			/* Map distributor fields → canonical fields */
			$mapped = $this->fieldMapper->mapRecord( $rawRecord );
			$mapped = FieldMapper::castTypes( $mapped );

			/* Extract UPC — skip if missing (Section 2.6) */
			$upc = $this->fieldMapper->extractUpc( $rawRecord );
			if ( $upc === null )
			{
				$this->stats['skipped']++;
				return;
			}

			$this->seenUpcs[$upc] = true;

			/* Map category */
			$categoryRaw = $mapped['category'] ?? null;
			if ( $categoryRaw !== null )
			{
				$categoryId = $this->categoryMapper->map( (string) $categoryRaw );
				$mapped['category_id'] = $categoryId ?? 0;
			}
			unset( $mapped['category'] );

			/* Check if UPC exists */
			$existing = $this->loadProduct( $upc );

			if ( $existing === null )
			{
				$this->createProduct( $upc, $mapped );
				$this->stats['created']++;
			}
			else
			{
				$conflictsFound = $this->updateProduct( $existing, $mapped );
				$this->stats['updated']++;
				$this->stats['conflicts'] += $conflictsFound;
			}

			/* Detect compliance fields in raw record */
			$complianceFields = $this->fieldMapper->detectComplianceFields( $rawRecord );
			if ( !empty( $complianceFields ) )
			{
				FlagProcessor::processFromFeed(
					$upc,
					(int) $this->feed->id,
					$complianceFields,
					(int) $this->log->id
				);
			}
		}
		catch ( \Exception $e )
		{
			$this->stats['errored']++;
			$this->log->appendError( 'Record error: ' . $e->getMessage() );
		}
	}

	/**
	 * Load an existing product by UPC, or return null.
	 *
	 * @param  string $upc
	 * @return Product|null
	 */
	protected function loadProduct( string $upc ): ?Product
	{
		try
		{
			return Product::load( $upc );
		}
		catch ( \OutOfRangeException )
		{
			return null;
		}
	}

	/**
	 * Create a new product record.
	 *
	 * @param  string $upc
	 * @param  array  $mapped  Canonical field => value
	 * @return void
	 */
	protected function createProduct( string $upc, array $mapped ): void
	{
		$product = new Product;
		$product->upc = $upc;

		/* Apply all mapped values */
		foreach ( $mapped as $field => $value )
		{
			if ( $value !== null && $value !== '' )
			{
				$product->$field = $value;
			}
		}

		$product->distributor_sources = $this->feed->distributor;
		$product->primary_source      = $this->feed->distributor;
		$product->record_status       = Product::STATUS_ACTIVE;
		$product->last_updated        = date( 'Y-m-d H:i:s' );

		/* Track this distributor */
		$product->markSeenByDistributor( $this->feed->distributor, (int) $this->log->id );

		$product->save();

		/* Queue OpenSearch reindex */
		$this->queueReindex( $upc );
	}

	/**
	 * Update an existing product with incoming data, applying conflict resolution.
	 * Delegates actual resolution logic to ConflictResolver (Step 5).
	 *
	 * @param  Product $product
	 * @param  array   $mapped   Canonical field => incoming value
	 * @return int     Number of conflicts detected
	 */
	protected function updateProduct( Product $product, array $mapped ): int
	{
		$conflictCount = 0;
		$changed       = false;

		/* Delegate to ConflictResolver for each field */
		$resolver = new \IPS\gdcatalog\Feed\ConflictResolver(
			$product,
			$this->feed,
			$this->log
		);

		foreach ( $mapped as $field => $incomingValue )
		{
			if ( $field === 'upc' || $incomingValue === null )
			{
				continue;
			}

			$result = $resolver->resolve( $field, $incomingValue );

			if ( $result['changed'] )
			{
				$changed = true;
			}
			if ( $result['conflict'] )
			{
				$conflictCount++;
			}
		}

		/* Update distributor tracking */
		$product->addDistributorSource( $this->feed->distributor );
		$product->markSeenByDistributor( $this->feed->distributor, (int) $this->log->id );

		/* Recalculate primary_source */
		if ( $resolver->getFieldWins() > 0 )
		{
			$product->primary_source = $this->feed->distributor;
		}

		if ( $changed )
		{
			$product->last_updated = date( 'Y-m-d H:i:s' );
			$product->save();
			$this->queueReindex( $product->upc );
		}
		else
		{
			/* Still save distributor tracking changes */
			$product->save();
		}

		return $conflictCount;
	}

	/**
	 * Handle discontinuation logic — Section 2.6.
	 * Products from this distributor not seen for 3 consecutive runs
	 * are set to Discontinued if no other distributor still carries them.
	 *
	 * @return void
	 */
	protected function processDiscontinuations(): void
	{
		$threshold = (int) \IPS\Settings::i()->gdcatalog_discontinue_threshold ?: 3;

		/* Find all products that list this distributor in their sources */
		$where = [
			[ 'FIND_IN_SET(?, distributor_sources)', $this->feed->distributor ],
			[ 'record_status != ?', Product::STATUS_DISCONTINUED ],
		];

		foreach ( \IPS\Db::i()->select( '*', 'gd_catalog', $where ) as $row )
		{
			$product = Product::constructFromData( $row );

			/* Was this UPC seen in the current run? */
			if ( isset( $this->seenUpcs[ $product->upc ] ) )
			{
				continue;
			}

			$misses = $product->incrementMiss( $this->feed->distributor );

			if ( $misses >= $threshold )
			{
				/* Remove this distributor from the product's sources */
				$product->removeDistributorSource( $this->feed->distributor );

				/* If no other distributor carries it, discontinue */
				if ( !$product->hasActiveDistributors() )
				{
					$product->record_status = Product::STATUS_DISCONTINUED;
					$product->last_updated  = date( 'Y-m-d H:i:s' );
					$this->queueReindex( $product->upc );
				}
			}

			$product->save();
		}
	}

	/**
	 * Queue a product for OpenSearch reindexing.
	 *
	 * @param  string $upc
	 * @return void
	 */
	protected function queueReindex( string $upc ): void
	{
		/* Store UPCs to reindex — batch processed after import completes.
		   The OpenSearchIndexer (Step 7) will consume this queue. */
		\IPS\Db::i()->replace( 'gd_reindex_queue', [
			'upc'        => $upc,
			'queued_at'  => date( 'Y-m-d H:i:s' ),
		] );
	}

	/**
	 * Get the running stats.
	 *
	 * @return array
	 */
	public function getStats(): array
	{
		return $this->stats;
	}
}
