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
use IPS\gdcatalog\Feed\SourceAdapter\SourceAdapterInterface;
use IPS\gdcatalog\Feed\SourceAdapter\SportsSouthAdapter;
use IPS\gdcatalog\Feed\SourceAdapter\StructuredFeedAdapter;
use IPS\gdcatalog\Feed\UpcValidator;
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
	protected static ?array $gdCatalogColumns = null;

	public static function catalogColumns(): array
	{
		if ( static::$gdCatalogColumns === null )
		{
			$cols = [];
			try
			{
				foreach ( \IPS\Db::i()->query( "SHOW COLUMNS FROM `" . \IPS\Db::i()->prefix . "gd_catalog`" ) as $row )
				{
					$cols[] = $row['Field'];
				}
			}
			catch ( \Throwable ) {}
			static::$gdCatalogColumns = $cols;
		}
		return static::$gdCatalogColumns;
	}

	protected static ?array $gdCatalogColumnLengths = null;

	protected static function catalogColumnLengths(): array
	{
		if ( static::$gdCatalogColumnLengths === null )
		{
			$lens = [];
			try
			{
				foreach ( \IPS\Db::i()->query( "SHOW COLUMNS FROM `" . \IPS\Db::i()->prefix . "gd_catalog`" ) as $row )
				{
					if ( preg_match( '/(?:var)?char\((\d+)\)/i', (string) $row['Type'], $m ) )
					{
						$lens[ $row['Field'] ] = (int) $m[1];
					}
				}
			}
			catch ( \Throwable ) {}
			static::$gdCatalogColumnLengths = $lens;
		}
		return static::$gdCatalogColumnLengths;
	}

	protected static function clampToColumn( string $field, $value )
	{
		if ( !is_string( $value ) ) { return $value; }
		$lens = static::catalogColumnLengths();
		if ( isset( $lens[ $field ] ) && mb_strlen( $value ) > $lens[ $field ] )
		{
			return mb_substr( $value, 0, $lens[ $field ] );
		}
		return $value;
	}

	/**
	 * v1.0.119 (Phase 3): ACCESSORY_ATTR_MAP, accessoryAttrsFor(), and
	 * topSlugForCategoryId() + $topSlugByCatId cache moved to
	 * SportsSouthAdapter. Slot meaning is Sports South-specific (ITATR1
	 * as "holster type" only makes sense against SS's per-CATID slot
	 * convention) and the top-slug lookup was only ever called to feed
	 * accessoryAttrsFor. Zero external callers verified via grep before
	 * removal (see Phase 3 audit — no other module references these
	 * symbols). Their behaviour is preserved: the adapter now writes
	 * `_ATTR_<col>` sentinels that the generic `_ATTR_*` merge in
	 * processRecord picks up automatically.
	 */

	/**
	 * v1.0.10: Safety cap on rows processed per import run. Sports South's
	 * full catalog is 58,000+ products. Until proper paging/background
	 * tasking ships in v1.0.13, we cap each run to avoid PHP timeouts and
	 * runaway DB writes. Raise this carefully as paging gets implemented.
	 */
	public const MAX_RECORDS_PER_RUN = 1000;

	/** Canonical category IDs that ARE ammunition (Ammunition subtree). Excludes Air Gun Ammo (140). */
	const AMMO_CATEGORY_IDS = [ 23, 24, 25, 26, 27, 28, 29, 30 ];

	/**
	 * v1.0.118 (Phase 2): Sports South enrichment lookup state
	 * (previously four lazy-loaded properties + SPORTS_SOUTH_ATTR_LABEL_MAP
	 * on this class) has been moved into SportsSouthAdapter. This
	 * property holds the per-run adapter instance so lazy-loaded lookup
	 * tables are shared across every record in one import run — same
	 * lifetime behaviour as before, just relocated. See
	 * SportsSouthAdapter and the enrichSportsSouthRecord delegating
	 * wrapper below.
	 */
	protected ?SportsSouthAdapter $sportsSouthAdapter = null;

	/**
	 * v1.0.120 (Phase 4): generic structured-feed adapter (CSV / JSON /
	 * XML / manual upload / basic HTTP / FTP). Constructed once per
	 * import run, same lifetime rule the Sports South adapter follows —
	 * FieldMapper injected at construction is the per-feed instance
	 * this Importer already owns. See getStructuredFeedAdapter() and
	 * resolveAdapter() below.
	 */
	protected ?StructuredFeedAdapter $structuredFeedAdapter = null;

	protected Distributor $feed;
	protected FieldMapper $fieldMapper;
	protected CategoryMapper $categoryMapper;
	protected ImportLog $log;

	/** @var array UPCs seen in this run — for discontinuation tracking */
	protected array $seenUpcs = [];

	/** @var array Running stats for import log */
	protected array $stats = [
		'total'         => 0,
		'created'       => 0,
		'updated'       => 0,
		'skipped'       => 0,
		'errored'       => 0,
		'conflicts'     => 0,
		'upc_invalid'   => 0,
		'upc_flagged'   => 0,
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
	 * v1.0.122 (Phase 6): non-destructive sample fetch for the "Test
	 * Source" AdminCP action. Runs the existing per-feed fetch +
	 * parse pipeline against the same Distributor row a real import
	 * would use, returns the first N parsed raw records, and does
	 * nothing else — no product create/update, no ConflictResolver
	 * write, no ImportLog write, no OpenSearch queue, no
	 * discontinuation, no markRunning. This is the ONLY entry point
	 * the Test Source action uses, so the AdminCP controller never
	 * has to duplicate fetch or parser behaviour.
	 *
	 * The Sports South branch of fetchFeed pulls a small first page
	 * (per MAX_RECORDS_PER_RUN) and pre-enriches via the SS adapter
	 * — the sample slice below caps that further so we never render
	 * a full page's worth of SS raw. For CSV/JSON/XML/HTTP/FTP the
	 * whole feed body is fetched (same as a live run) and then
	 * sliced; a feed larger than a few thousand rows will therefore
	 * be paid for at fetch time. That is a known trade-off; the
	 * alternative — a range-limited fetch — would duplicate live
	 * fetchFeed logic and risk drift.
	 *
	 * @param  Distributor $feed  Feed configuration to sample
	 * @param  int         $limit Maximum records to return (>=1)
	 * @return array<int, array<string, mixed>> First $limit raw records
	 */
	public static function sampleRecords( Distributor $feed, int $limit = 5 ): array
	{
		if ( $limit < 1 )
		{
			$limit = 1;
		}
		$importer = new static( $feed );
		$content  = $importer->fetchFeed();
		$records  = $importer->parseFeed( $content );
		return array_slice( $records, 0, $limit );
	}

	/**
	 * v1.0.123 (Phase 7): one-time fetch + parse for the resumable
	 * GenericImport queue extension's preQueueData step. Splits the
	 * pre-Phase-7 fetch → parse → foreach-processRecord sequence at
	 * the parse boundary so a background queue can stage the parsed
	 * records once and iterate them across multiple bounded run()
	 * invocations without re-fetching the source per batch. Used
	 * only from GenericImport::preQueueData — synchronous imports
	 * (Importer::run) continue to fetch + parse + process in one
	 * pass, unchanged.
	 *
	 * @return array<int, array<string, mixed>> All parsed raw records
	 */
	public static function fetchAndParse( Distributor $feed ): array
	{
		$importer = new static( $feed );
		return $importer->parseFeed( $importer->fetchFeed() );
	}

	/**
	 * v1.0.123 (Phase 7): resumable-import bridge into the existing
	 * discontinuation algorithm. Same rules, same thresholds, same
	 * 80%-coverage safety guard as processDiscontinuations() — only
	 * the source of $seenUpcs differs: this variant takes them from
	 * an argument (an array<string, true> the queue extension has
	 * accumulated across every batch), instead of $this->seenUpcs
	 * (populated within a single Importer instance's run). Never
	 * calls processRecord/processNormalizedRecord/FieldMapper; only
	 * scans gd_catalog for products the source used to carry that
	 * were absent from this import cycle. Safe to call from the
	 * queue's postComplete once ALL batches have finished.
	 *
	 * @param  array<string, true> $seenUpcs UPCs seen during the full job
	 * @return void
	 */
	public static function processDiscontinuationsForSeenUpcs( Distributor $feed, array $seenUpcs ): void
	{
		$importer = new static( $feed );
		$importer->seenUpcs = $seenUpcs;
		$importer->processDiscontinuations();
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

			/* 3. Process each record.
			 * v1.0.120 (Phase 4): source dispatch.
			 *   - auth_type='sportssouth': records were pre-enriched
			 *     record-by-record in fetchFeed()'s SS branch (each
			 *     call to enrichSportsSouthRecord ran the SS adapter),
			 *     so downstream we use the legacy processRecord path.
			 *     That path calls FieldMapper exactly once, then hands
			 *     off to the shared processNormalizedRecord tail.
			 *   - every other auth_type routes through resolveAdapter()
			 *     — StructuredFeedAdapter — which maps the parsed raw
			 *     row once and hands the pre-mapped NormalizedRecord
			 *     straight to processNormalizedRecord. No re-mapping,
			 *     no SS-specific coupling. */
			$isSportsSouth = ( $this->feed->auth_type === 'sportssouth' );
			foreach ( $records as $record )
			{
				if ( $isSportsSouth )
				{
					$this->processRecord( $record );
				}
				else
				{
					$this->processNormalizedRecord(
						$this->resolveAdapter()->normalize( $record )
					);
				}
			}

			/* 4. Handle discontinuation — products not seen in this run */
			$this->processDiscontinuations();

			/* 5. Complete */
			$this->log->complete( $this->stats );
			$this->feed->markCompleted( $this->stats['total'] );
		}
		catch ( \Throwable $e )
		{
			$this->log->fail( $e->getMessage() );
			$this->feed->markFailed();
		}

		return $this->log;
	}

	/**
	 * v1.0.24: Process a pre-fetched chunk of Sports South records.
	 *
	 * Called from the SportsSouthImport Queue extension's run() method
	 * once per chunk. The queue manages fetch + pagination via the
	 * dailyItemUpdate LastItem parameter; this method just runs the
	 * normal enrichment + mapping + create/update pipeline on the
	 * supplied records.
	 *
	 * Bypasses MAX_RECORDS_PER_RUN cap (queue controls chunk size
	 * externally) and skips processDiscontinuations (which needs full
	 * catalog visibility - runs only in the queue's postComplete).
	 *
	 * @param  Distributor $feed           Feed entity (auth_type='sportssouth')
	 * @param  array       $rawRecords     Raw records returned by SportsSouthClient::dailyItemUpdate
	 * @return array{total:int, created:int, updated:int, skipped:int, errored:int, conflicts:int}
	 */
	public static function runChunk( Distributor $feed, array $rawRecords ): array
	{
		/* Instantiate a normal Importer for the feed; bypasses the
		 * fetchFeed/parseFeed/MAX_RECORDS_PER_RUN path of execute(). */
		$importer = new static( $feed );

		/* processRecord() requires $this->log->id and may call
		 * $this->log->appendError(). $this->log is a non-nullable typed
		 * property, so we MUST assign before invoking processRecord.
		 * If startRun fails the chunk can't run safely - bail with all-errored. */
		try
		{
			$importer->log = ImportLog::startRun( (int) $feed->id, $feed->distributor );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'Importer::runChunk failed to start ImportLog: ' . $e->getMessage(), 'gdcatalog_importer' ); } catch ( \Throwable ) {}
			return [
				'total'     => count( $rawRecords ),
				'created'   => 0,
				'updated'   => 0,
				'skipped'   => 0,
				'errored'   => count( $rawRecords ),
				'conflicts' => 0,
			];
		}

		/* Enrich each record (brand, category, ITATR attrs, PICREF transform). */
		$enriched = [];
		foreach ( $rawRecords as $raw )
		{
			$enriched[] = $importer->enrichSportsSouthRecord( $raw );
		}

		$importer->stats['total'] = count( $enriched );

		/* Run each through processRecord - existing per-record path that
		 * handles create vs update via UPC lookup, conflict resolution, etc. */
		foreach ( $enriched as $record )
		{
			try
			{
				$importer->processRecord( $record );
			}
			catch ( \Throwable $e )
			{
				$importer->stats['errored']++;
				try { \IPS\Log::log( 'Importer::runChunk processRecord error: ' . $e->getMessage(), 'gdcatalog_importer' ); } catch ( \Throwable ) {}
			}
		}

		/* Mark this chunk's ImportLog complete (so it doesn't show as
		 * "running" forever). Aggregate stats are accumulated by the
		 * queue extension across all chunks. */
		try { $importer->log->complete( $importer->stats ); } catch ( \Throwable ) {}

		return $importer->stats;
	}

	/**
	 * Fetch the feed content from the configured URL.
	 *
	 * @return string  Raw feed content
	 * @throws \RuntimeException
	 */
	protected function fetchFeed(): string
	{
		$authType = $this->feed->auth_type;

		if ( $authType === 'manual_upload' )
		{
			$filePath = (string) ( $this->feed->uploaded_file_path ?? '' );
			if ( $filePath === '' || !file_exists( $filePath ) )
			{
				throw new \RuntimeException( 'No uploaded file found for manual_upload feed' );
			}
			$content = file_get_contents( $filePath );
			if ( $content === false )
			{
				throw new \RuntimeException( 'Failed to read uploaded file: ' . $filePath );
			}
			return $content;
		}

		$url = $this->feed->feed_url;

		if ( empty( $url ) )
		{
			throw new \RuntimeException( 'Feed URL is not configured' );
		}

		$creds = null;

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

		/* v1.0.129: IPS\Http\Response::$httpResponseCode is a STRING
		 * ("200", not 200). The pre-1.0.129 strict !== 200 comparison
		 * ALWAYS threw "Feed fetch failed: HTTP 200" for any generic
		 * source, because "200" !== 200 in PHP. SportsSouthClient +
		 * FetchImageDimensions both already cast to (int) before
		 * comparing; align this call site with that pattern. */
		if ( (int) $response->httpResponseCode !== 200 )
		{
			throw new \RuntimeException(
				'Feed fetch failed: HTTP ' . (int) $response->httpResponseCode
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
	/**
	 * v1.0.120 (Phase 4): lazy-load the generic structured-feed adapter
	 * (once per import run). The FieldMapper is the exact per-feed
	 * instance this Importer already owns — mapping happens inside the
	 * adapter, and processNormalizedRecord consumes the canonical map
	 * without re-mapping.
	 */
	protected function getStructuredFeedAdapter(): StructuredFeedAdapter
	{
		if ( $this->structuredFeedAdapter === null )
		{
			$this->structuredFeedAdapter = new StructuredFeedAdapter( $this->feed, $this->fieldMapper );
		}
		return $this->structuredFeedAdapter;
	}

	/**
	 * v1.0.120 (Phase 4): source dispatch. Returns the SS adapter for
	 * `auth_type='sportssouth'`, the generic StructuredFeedAdapter for
	 * every other current auth_type (none / basic / apikey / ftp /
	 * manual_upload). Small explicit switch on the existing Distributor
	 * configuration — no plugin registry, no factory — because the
	 * codebase currently has exactly two adapter kinds. A third kind
	 * (a new source-specific adapter) grows this switch by one line.
	 */
	protected function resolveAdapter(): SourceAdapterInterface
	{
		if ( $this->feed->auth_type === 'sportssouth' )
		{
			return $this->getSportsSouthAdapter();
		}
		return $this->getStructuredFeedAdapter();
	}

	protected function getSportsSouthAdapter(): SportsSouthAdapter
	{
		if ( $this->sportsSouthAdapter === null )
		{
			/* v1.0.119 (Phase 3): the adapter now owns the raw-CATID →
			 * CategoryMapper::resolve override AND the accessory ITATR
			 * slot interpretation (was Importer::accessoryAttrsFor + the
			 * ACCESSORY_ATTR_MAP const). Both need this importer's
			 * per-feed CategoryMapper to preserve exact-current output,
			 * so inject it at construction. Adapter instance is still
			 * per-import-run, matching the pre-refactor lookup-cache
			 * lifetime. */
			$this->sportsSouthAdapter = new SportsSouthAdapter( $this->feed, $this->categoryMapper );
		}
		return $this->sportsSouthAdapter;
	}

	/**
	 * Phase 2 delegating wrapper (v1.0.118).
	 *
	 * The enrichment body — plus the four lazy-loaded lookup properties
	 * and the SPORTS_SOUTH_ATTR_LABEL_MAP constant that back it — moved
	 * verbatim to SportsSouthAdapter::enrich(). This wrapper is
	 * preserved so any subclass of Importer that overrode this method,
	 * and both existing internal call sites (Importer::runChunk :395
	 * and Importer::fetchFeed :500), continue to work unchanged.
	 *
	 * The adapter instance is per-Importer-run (see
	 * getSportsSouthAdapter above), so lazy-loaded lookup caches have
	 * the same lifetime they had before this refactor.
	 *
	 * @param  array $record  Raw record from SportsSouthClient
	 * @return array  Enriched record (identical shape to pre-Phase-2 output)
	 */
	protected function enrichSportsSouthRecord( array $record ): array
	{
		return $this->getSportsSouthAdapter()->normalize( $record )->getRaw();
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
			/* v1.0.120 (Phase 4): map distributor fields → canonical
			 * fields ONCE, right here. This is the SS legacy entry
			 * point — SportsSouthAdapter's Phase-2 contract is to
			 * enrich the raw payload and defer FieldMapper to the
			 * Importer, so mapping happens here. Records that reach
			 * this method via runChunk (SS) or via processRecord's
			 * subclass overrides continue to work unchanged.
			 * The generic structured-feed path (StructuredFeedAdapter)
			 * bypasses this wrapper entirely — the adapter maps
			 * upstream in execute()'s loop and calls
			 * processNormalizedRecord directly with the pre-mapped
			 * canonical, so a record is never mapped twice regardless
			 * of entry point. */
			$mapped = $this->fieldMapper->mapRecord( $rawRecord );
			$mapped = FieldMapper::castTypes( $mapped );

			/* Delegate to the shared tail. `_ATTR_*` merge (was inline
			 * here pre-Phase-4) moved into processNormalizedRecord so it
			 * runs exactly once for both entry paths. */
			$this->processNormalizedRecord(
				NormalizedRecord::fromMapped( $mapped, $rawRecord, $this->feed )
			);
		}
		catch ( \Throwable $e )
		{
			$this->stats['errored']++;
			$this->log->appendError( 'Record error: ' . $e->getMessage() );
		}
	}

	/**
	 * v1.0.120 (Phase 4): shared generic-catalog tail. Called from two
	 * entry paths:
	 *   1. processRecord( array ) — SS legacy path (runChunk + any
	 *      subclass override). Wraps the mapped canonical in a
	 *      NormalizedRecord and hands it here.
	 *   2. execute()'s per-record loop for non-SS auth_types —
	 *      resolveAdapter()->normalize() maps once in
	 *      StructuredFeedAdapter and hands the pre-mapped
	 *      NormalizedRecord here directly.
	 *
	 * This method does NOT re-run FieldMapper — its input is always a
	 * NormalizedRecord whose canonical map has already been produced
	 * by exactly one FieldMapper::mapRecord + castTypes call
	 * upstream. That satisfies the Phase 4 "no double mapping"
	 * invariant.
	 *
	 * The generic `_ATTR_<col>` sentinel merge — pre-Phase-4 inline in
	 * processRecord — lives here so it runs exactly once regardless of
	 * entry path. Adapters emit `_ATTR_*` sentinels on the raw payload
	 * (SportsSouthAdapter writes them from ITATR interpretation; a
	 * generic feed can ship pre-computed `_ATTR_<col>` columns
	 * verbatim); this merge is the one convergence point.
	 */
	protected function processNormalizedRecord( NormalizedRecord $normalized ): void
	{
		try
		{
			$mapped    = $normalized->toArray();
			$rawRecord = $normalized->getRaw();

			/* Generic `_ATTR_<col>` sentinel merge — the one convergence
			 * point (see method docblock). Non-empty guard mirrors the
			 * pre-Phase-4 inline merge in processRecord. */
			foreach ( $rawRecord as $k => $v )
			{
				if ( str_starts_with( (string) $k, '_ATTR_' ) )
				{
					$col = substr( $k, 6 );
					if ( $v !== '' && $v !== null )
					{
						$mapped[ $col ] = $v;
					}
				}
			}

			/* Extract UPC — skip if missing (Section 2.6) */
			$rawUpc = $this->fieldMapper->extractUpc( $rawRecord );
			if ( $rawUpc === null )
			{
				$this->stats['skipped']++;
				return;
			}

			$upc = UpcValidator::normalize( $rawUpc );
			if ( $upc === null )
			{
				$this->stats['upc_invalid']++;
				$this->log->appendError( 'UPC invalid after normalization: ' . $rawUpc );
				return;
			}

			if ( strlen( $upc ) === 12 && !UpcValidator::validateCheckDigit( $upc ) )
			{
				$this->stats['upc_flagged']++;
				try
				{
					FlagProcessor::createAdminFlag( $upc, null, 'upc_check_digit_mismatch',
						'original: ' . $rawUpc . ' → normalized: ' . $upc, 0 );
				}
				catch ( \Throwable ) {}
			}

			if ( UpcValidator::isSuspicious( $upc ) )
			{
				$this->stats['upc_flagged']++;
				try
				{
					FlagProcessor::createAdminFlag( $upc, null, 'upc_suspicious',
						'original: ' . $rawUpc . ' → normalized: ' . $upc, 0 );
				}
				catch ( \Throwable ) {}
			}

			$this->seenUpcs[$upc] = true;

			/* Map category. v1.0.136: only set category_id when the
			 * mapper resolves to a real id. Prior behaviour wrote 0 on
			 * a mapper miss, which for the UPDATE branch silently
			 * overwrote an existing valid category_id with 0 whenever
			 * an incoming record had an unresolvable category name or
			 * an empty `category` cell. Leaving the key absent means
			 * the update loop skips category_id entirely (null-guard
			 * on line 993 of updateProduct) — existing value stays,
			 * fresh inserts get schema default 0. */
			$categoryRaw = trim( (string) ( $mapped['category'] ?? '' ) );
			if ( $categoryRaw !== '' )
			{
				$categoryId = $this->categoryMapper->map( $categoryRaw );
				if ( $categoryId !== null )
				{
					$mapped['category_id'] = $categoryId;
				}
			}
			unset( $mapped['category'] );

			/* v1.0.119 (Phase 3): $sTitle was set only inside
			 * enrichSportsSouthRecord (a different method's scope) —
			 * accessing it here fataled on PHP 8 for non-SS feeds. Use
			 * the mapped canonical title, which is what
			 * refineCategoryByTitle actually reads for its title
			 * heuristics. */
			$mapped['category_id'] = $this->refineCategoryByTitle( (int) ( $mapped['category_id'] ?? 0 ), (string) ( $mapped['title'] ?? '' ) );

			/* Category-based ammo flag (Sports South sends none). Ammunition subtree => is_ammo. */
			$mapped['is_ammo'] = in_array( (int) ( $mapped['category_id'] ?? 0 ), self::AMMO_CATEGORY_IDS, true ) ? 1 : 0;

			if ( isset( $mapped['action_type'] ) )
			{
				$mapped['action_type'] = TitleParser::cleanAction( (string) $mapped['action_type'] );
			}

			/* v1.0.140: collapse multi-space runs in single-line text
			 * fields (title/brand/model). Distributors often pad
			 * fixed-width columns and concatenate them, leaving titles
			 * like "Burris Droptine, Bur 200077   Droptne 4.5-14x42".
			 * description is intentionally excluded — real distributor
			 * line breaks in descriptions are worth keeping. */
			foreach ( [ 'title', 'brand', 'model' ] as $wsField )
			{
				if ( isset( $mapped[ $wsField ] ) && is_string( $mapped[ $wsField ] ) )
				{
					$mapped[ $wsField ] = TitleParser::normalizeWhitespace( $mapped[ $wsField ] );
				}
			}

			/* v1.0.142: automatic UPC/identity audit. classify() returns
			 * a short label ("Invalid UPC-A checksum", "Placeholder UPC",
			 * "Invalid EAN-13 checksum") when the UPC fails a structural
			 * check, or null when it looks clean. We ONLY inject the
			 * auto-flag when the incoming mapped record didn't already
			 * carry an audit status (i.e. AI enrichment via CSV
			 * round-trip populated one) — respecting AI's judgment,
			 * which is richer than our checksum-only view. When any
			 * audit issue is present (auto or AI), flip the row to
			 * admin_review so it surfaces in the Review Queue. */
			$hasIncomingAuditStatus = isset( $mapped['upc_audit_status'] )
				&& is_string( $mapped['upc_audit_status'] )
				&& trim( $mapped['upc_audit_status'] ) !== '';
			if ( !$hasIncomingAuditStatus )
			{
				$autoStatus = UpcValidator::classify( $upc );
				if ( $autoStatus !== null )
				{
					$mapped['upc_audit_status'] = $autoStatus;
				}
			}
			$flaggedForReview = isset( $mapped['upc_audit_status'] )
				&& is_string( $mapped['upc_audit_status'] )
				&& trim( $mapped['upc_audit_status'] ) !== ''
				&& stripos( $mapped['upc_audit_status'], 'verified' ) === false
				&& stripos( $mapped['upc_audit_status'], 'valid' ) === false;
			if ( $flaggedForReview )
			{
				/* Only relevant on create — updateProduct doesn't flip
				 * record_status (v1.0.130 contract). createProduct
				 * already reads mark_imports_as_review; we tack an
				 * OR-branch onto the feed-flag by overriding to
				 * admin_review here via a synthetic mapped key that
				 * createProduct honours (added below). */
				$mapped['_force_admin_review'] = true;
			}

			/* Check if UPC exists */
			$existing = $this->loadProduct( $upc );

			if ( $existing === null )
			{
				$this->createProduct( $upc, $mapped, $rawRecord );
				$this->stats['created']++;
			}
			else
			{
				$conflictsFound = $this->updateProduct( $existing, $mapped, $rawRecord );
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
		catch ( \Throwable $e )
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
	protected function createProduct( string $upc, array $mapped, array $rawRecord = [] ): void
	{
		$product = new Product;
		$product->upc = $upc;

		/* Apply all mapped values — only set columns that actually exist in gd_catalog */
		$validColumns = static::catalogColumns();
		foreach ( $mapped as $field => $value )
		{
			if ( $value !== null && $value !== '' && in_array( $field, $validColumns, true ) )
			{
				$product->$field = static::clampToColumn( $field, $value );
			}
		}

		/* v1.0.27: Store raw distributor record JSON so we can re-extract
		 * attributes later without re-pulling from the API. Strip synthetic
		 * "_X" fields we already extracted; keep only the raw Sports South
		 * keys (CATID, ITBRDNO, MFGINO, ITATR1..N, etc). */
		if ( !empty( $rawRecord ) )
		{
			$cleanRaw = [];
			foreach ( $rawRecord as $k => $v )
			{
				if ( !str_starts_with( (string) $k, '_' ) )
				{
					$cleanRaw[ $k ] = $v;
				}
			}
			try
			{
				$product->raw_distributor_data = json_encode( $cleanRaw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			}
			catch ( \Throwable ) {}
		}

		$product->distributor_sources = $this->feed->distributor;
		$product->primary_source      = $this->feed->distributor;
		/* v1.0.130: per-source "mark imports as review" flag. When
		 * an admin sets this on a low-quality source (e.g. a dealer
		 * XML backfill), newly-created products land as admin_review
		 * instead of active — the Review Queue admin UI shows them
		 * with a completeness heat-map and a Promote action so an
		 * admin can fill in missing canonical fields before the
		 * product goes live on the front-end. Existing catalog
		 * products updated by this source keep their current
		 * record_status — the flag ONLY affects the create branch. */
		/* v1.0.142: also flip to admin_review when the row carries an
		 * audit flag (auto-detected bad UPC, or AI-flagged identity
		 * issue). Either signal is sufficient — union, not intersection. */
		$forceReview = !empty( $mapped['_force_admin_review'] );
		$product->record_status       = ( $forceReview || (int) ( $this->feed->mark_imports_as_review ?? 0 ) === 1 )
			? Product::STATUS_ADMIN_REVIEW
			: Product::STATUS_ACTIVE;
		unset( $mapped['_force_admin_review'] );
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
	protected function updateProduct( Product $product, array $mapped, array $rawRecord = [] ): int
	{
		$conflictCount = 0;
		$changed       = false;

		/* v1.0.142: drop synthetic control keys that only createProduct
		 * consumes. Without this the loop below tries to write
		 * `_force_admin_review` as a column and it's ignored by
		 * catalogColumns filter — cheap, but the unset keeps the map
		 * clean for the resolver. */
		unset( $mapped['_force_admin_review'] );

		/* Delegate to ConflictResolver for each field */
		$resolver = new \IPS\gdcatalog\Feed\ConflictResolver(
			$product,
			$this->feed,
			$this->log
		);

		$validColumns = static::catalogColumns();
		foreach ( $mapped as $field => $incomingValue )
		{
			if ( $field === 'upc' || $incomingValue === null )
			{
				continue;
			}

			if ( !in_array( $field, $validColumns, true ) )
			{
				continue;
			}

			$incomingValue = static::clampToColumn( $field, $incomingValue );

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

		/* v1.0.27: Store raw distributor record JSON for future re-extraction. */
		if ( !empty( $rawRecord ) )
		{
			$cleanRaw = [];
			foreach ( $rawRecord as $k => $v )
			{
				if ( !str_starts_with( (string) $k, '_' ) )
				{
					$cleanRaw[ $k ] = $v;
				}
			}
			try
			{
				$product->raw_distributor_data = json_encode( $cleanRaw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			}
			catch ( \Throwable ) {}
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
	 * Products from this distributor not seen for N consecutive runs
	 * are set to Discontinued if no other distributor still carries them.
	 *
	 * v1.0.29 SAFETY GUARD: If $this->seenUpcs is empty or suspiciously
	 * small, do NOT run discontinuation. An empty seenUpcs set means the
	 * import was aborted before processing any products (e.g. "offset stuck"
	 * abort, API failure, credential failure). Without this guard, an
	 * aborted import would mark EVERY product as missed and discontinue
	 * them all once they hit the threshold. The v1.0.27 reimport caused
	 * exactly this: 57,326 products were wrongly marked discontinued after
	 * an aborted run.
	 *
	 * @return void
	 */
	protected function processDiscontinuations(): void
	{
		/* v1.0.33: Safety guard rewritten. The v1.0.29 guard (seenCount < 100)
		 * was insufficient: Importer::execute() truncates to 1000 records via
		 * MAX_RECORDS_PER_RUN, then ran discontinue on that 1000-UPC sample.
		 * With a 58,338-product catalog, that marked 57,338 products as
		 * "missed" every scheduled cron run.
		 *
		 * The new guard compares seenCount to the total active catalog for
		 * this distributor. If seen < 80% of catalog, the import obviously
		 * didn't see a representative sample - skip discontinue entirely.
		 *
		 * This makes the scheduled cron path (sees 1000 records of 58k = 1.7%)
		 * always skip - which is correct, since it's structurally incapable
		 * of seeing the full catalog. The queue full-catalog path (sees ~58k
		 * of 58k ≈ 99%) runs normally.
		 *
		 * Hard floor of 100 still applies (covers edge case of a new feed
		 * with 0 active products in catalog yet). */
		$seenCount = is_array( $this->seenUpcs ) ? count( $this->seenUpcs ) : 0;

		if ( $seenCount < 100 )
		{
			try { \IPS\Log::log( sprintf( 'processDiscontinuations SKIPPED for %s: only %d UPCs seen (hard floor of 100)', $this->feed->distributor, $seenCount ), 'gdcatalog_discontinue' ); } catch ( \Throwable ) {}
			return;
		}

		try
		{
			$totalActive = (int) \IPS\Db::i()->select(
				'COUNT(*)',
				'gd_catalog',
				[
					[ 'FIND_IN_SET(?, distributor_sources)', $this->feed->distributor ],
					[ 'record_status=?', Product::STATUS_ACTIVE ],
				]
			)->first();
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( sprintf( 'processDiscontinuations SKIPPED for %s: failed to query total active count: %s', $this->feed->distributor, $e->getMessage() ), 'gdcatalog_discontinue' ); } catch ( \Throwable ) {}
			return;
		}

		/* If the catalog has very few existing products (<500), use the hard
		 * floor of 100 already enforced above. Otherwise require 80% coverage. */
		if ( $totalActive >= 500 )
		{
			$minSeen = (int) ceil( $totalActive * 0.80 );
			if ( $seenCount < $minSeen )
			{
				try { \IPS\Log::log( sprintf( 'processDiscontinuations SKIPPED for %s: saw %d UPCs but catalog has %d active (need >=%d, 80%% threshold). This usually means the scheduled cron path ran instead of the full-catalog queue path.', $this->feed->distributor, $seenCount, $totalActive, $minSeen ), 'gdcatalog_discontinue' ); } catch ( \Throwable ) {}
				return;
			}
		}

		try { \IPS\Log::log( sprintf( 'processDiscontinuations RUNNING for %s: saw %d of %d active (%.1f%% coverage)', $this->feed->distributor, $seenCount, $totalActive, $totalActive > 0 ? ( $seenCount / $totalActive * 100 ) : 0 ), 'gdcatalog_discontinue' ); } catch ( \Throwable ) {}

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
		try
		{
			$queueCount = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_reindex_queue' )->first();
			if ( $queueCount > 10000 )
			{
				return;
			}
			\IPS\Db::i()->replace( 'gd_reindex_queue', [
				'upc'        => $upc,
				'queued_at'  => date( 'Y-m-d H:i:s' ),
			] );
		}
		catch ( \Throwable ) {}
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

	/** @var array<string,int> */
	protected array $refineCatIds = [];

	protected function catIdByName( string $name ): int
	{
		if ( isset( $this->refineCatIds[ $name ] ) )
		{
			return $this->refineCatIds[ $name ];
		}
		try
		{
			$id = (int) \IPS\Db::i()->select( 'id', 'gd_categories', [ 'name=?', $name ] )->first();
		}
		catch ( \Throwable )
		{
			$id = 0;
		}
		return $this->refineCatIds[ $name ] = $id;
	}

	protected function refineCategoryByTitle( int $categoryId, string $title ): int
	{
		if ( $categoryId === 0 || $title === '' )
		{
			return $categoryId;
		}

		$t = strtolower( $title );
		$railsMounts = $this->catIdByName( 'Rails & Mounts' );
		$framesRecv  = $this->catIdByName( 'Frames & Receivers' );
		$partsAcc    = $this->catIdByName( 'Parts & Accessories' );

		if ( $categoryId === $railsMounts )
		{
			$isRing = ( str_contains( $t, 'scope ring' )
				|| str_contains( $t, 'opti-lock ring' )
				|| str_contains( $t, 'scp ring' )
				|| str_contains( $t, 'matchring' )
				|| str_contains( $t, 'ring combo' )
				|| str_contains( $t, 'rings' ) );
			$isMount = ( str_contains( $t, 'ringmount' ) || str_contains( $t, 'ring mount' ) );
			if ( $isRing && !$isMount )
			{
				$id = $this->catIdByName( 'Scope Rings' );
				if ( $id ) { return $id; }
			}
		}

		if ( $categoryId === $framesRecv )
		{
			if ( str_contains( $t, 'upper' ) )
			{
				$id = $this->catIdByName( 'Upper Receivers' );
				if ( $id ) { return $id; }
			}
			if ( str_contains( $t, 'lower' ) )
			{
				$id = $this->catIdByName( 'Lower Receivers' );
				if ( $id ) { return $id; }
			}
		}

		if ( $categoryId === $partsAcc && str_contains( $t, 'trigger' )
			&& !str_contains( $t, 'trigger guard' ) && !str_contains( $t, 'trigger lock' ) )
		{
			$id = $this->catIdByName( 'Triggers & Trigger Groups' );
			if ( $id ) { return $id; }
		}

		$weaponLights = $this->catIdByName( 'Weapon Lights' );
		if ( $categoryId === $weaponLights )
		{
			$isGeneral = ( str_contains( $t, 'headlamp' ) || str_contains( $t, 'head lamp' )
				|| str_contains( $t, 'penlight' ) || str_contains( $t, 'pen light' )
				|| str_contains( $t, 'lantern' ) || str_contains( $t, 'work light' )
				|| str_contains( $t, 'spotlight' )
				|| ( str_contains( $t, 'flashlight' )
					&& !str_contains( $t, 'rail' ) && !str_contains( $t, 'pistol' )
					&& !str_contains( $t, 'weapon' ) && !str_contains( $t, 'scout' )
					&& !str_contains( $t, 'mount' ) && !str_contains( $t, 'picatinny' )
					&& !str_contains( $t, 'm-lok' ) ) );
			if ( $isGeneral )
			{
				$id = $this->catIdByName( 'Flashlights & Headlamps' );
				if ( $id ) { return $id; }
			}
		}

		return $categoryId;
	}
}
