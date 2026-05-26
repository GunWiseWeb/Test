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

	/**
	 * v1.0.20: Lazy-loaded category attribute label map.
	 * Keyed by sportssouth catid (string) => array of attr_slot => label
	 * Example:
	 *   $sportsSouthCategoryAttrs['94'] = [
	 *     1 => 'Action Type',
	 *     2 => 'Caliber',
	 *     3 => 'Barrel Length',
	 *     ...
	 *   ]
	 * Built from gd_sportssouth_categories.raw_data JSON.
	 */
	protected ?array $sportsSouthCategoryAttrs = null;

	/**
	 * v1.0.20: Class constant mapping Sports South attribute label
	 * variants to canonical gd_catalog column names.
	 * Keys are lowercased label substrings. Comparison uses
	 * mb_strtolower($label) and looks for any of these strings as
	 * substring matches. First match wins.
	 */
	protected const SPORTS_SOUTH_ATTR_LABEL_MAP = [
		/* Order matters - first match wins.
		 * More specific labels must come BEFORE less specific ones.
		 *
		 * v1.0.27: Added safety/grips/sight/slide/frame material/gun type/weight.
		 * Note: 'type' must come LAST to avoid stealing 'frame type', 'barrel type', etc. */

		/* Caliber */
		'caliber'         => 'caliber',
		'cartridge'       => 'caliber',
		'gauge'           => 'caliber',

		/* Action type - 'action type' must precede bare 'action' */
		'action type'     => 'action_type',
		'operating system' => 'action_type',
		'action'          => 'action_type',

		/* Lengths - 'barrel length' before bare 'overall length' before 'oal' */
		'barrel length'   => 'barrel_length',
		'bbl length'      => 'barrel_length',
		'barrel len'      => 'barrel_length',
		'overall length'  => 'overall_length',
		'oal'             => 'overall_length',

		/* Capacity */
		'mag capacity'    => 'capacity',
		'capacity'        => 'capacity',
		'rounds'          => 'capacity',

		/* Finish / materials - 'frame material' must precede bare 'finish' */
		'frame material'  => 'frame_material',
		'stock finish'    => 'finish',
		'barrel finish'   => 'finish',
		'frame finish'    => 'finish',
		'finish'          => 'finish',

		/* v1.0.27 fields */
		'safety'              => 'safety_type',
		'grips'               => 'stock_type',
		'sight configuration' => 'sight_type',
		'sight'               => 'sight_type',
		'slide description'   => 'receiver_type',
		'slide'               => 'receiver_type',
		'weight'              => 'weight_oz',

		/* MUST come last - bare 'type' catches anything else with 'type' in label */
		'type'            => 'gun_type',
	];

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
		 * has something to work with on review.
		 *
		 * v1.0.27: ALSO track manufacturer separately. If IMFGNO and ITBRDNO
		 * resolve to DIFFERENT names, treat IMFGNO as manufacturer (the actual
		 * maker, e.g. "Miroku") and ITBRDNO as brand (consumer-facing, e.g.
		 * "Browning"). If they're the same, only brand gets set. */
		$mfgKey = (string) ( $record['IMFGNO']  ?? '' );
		$brdKey = (string) ( $record['ITBRDNO'] ?? '' );

		$mfgName = ( $mfgKey !== '' && isset( $this->sportsSouthBrandLookup[ $mfgKey ] ) )
			? $this->sportsSouthBrandLookup[ $mfgKey ]
			: $mfgKey;

		$brdName = ( $brdKey !== '' && isset( $this->sportsSouthBrandLookup[ $brdKey ] ) )
			? $this->sportsSouthBrandLookup[ $brdKey ]
			: $brdKey;

		/* Brand: prefer brdName, fall back to mfgName, fall back to empty */
		$brandResolved = $brdName !== '' ? $brdName : $mfgName;
		$record['_BRAND_NAME'] = $brandResolved;

		/* Manufacturer: set only if it differs from the brand */
		if ( $mfgName !== '' && $mfgName !== $brandResolved )
		{
			$record['_MANUFACTURER'] = $mfgName;
		}

		/* v1.0.27: MPN comes directly from MFGINO field (model number assigned
		 * by manufacturer). Sports South puts this in MFGINO. */
		$mfgPartNo = trim( (string) ( $record['MFGINO'] ?? '' ) );
		if ( $mfgPartNo !== '' )
		{
			$record['_MPN'] = $mfgPartNo;
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

		/* v1.0.20: Extract caliber/action/finish/etc from ITATR slots.
		 *
		 * Sports South stores per-product attribute VALUES in ITATR1..N.
		 * The LABEL for each slot is defined per-category in
		 * gd_sportssouth_categories.raw_data (ATTR1..N).
		 *
		 * For example, category "RIFLES CENTERFIRE" might define:
		 *   ATTR1 = 'Action Type'
		 *   ATTR2 = 'Caliber'
		 *   ATTR3 = 'Barrel Length'
		 *
		 * And a product in that category has:
		 *   ITATR1 = 'Bolt Action'
		 *   ITATR2 = '.308 Winchester'
		 *   ITATR3 = '24"'
		 *
		 * We match labels (case-insensitive substring) to canonical
		 * column names via SPORTS_SOUTH_ATTR_LABEL_MAP. Each matched
		 * value gets injected as _CALIBER, _ACTION_TYPE, etc. */
		if ( $this->sportsSouthCategoryAttrs === null )
		{
			$this->sportsSouthCategoryAttrs = [];
			try
			{
				foreach ( \IPS\Db::i()->select( 'catid, raw_data', 'gd_sportssouth_categories' ) as $catRow )
				{
					$rawJson = (string) ( $catRow['raw_data'] ?? '' );
					if ( $rawJson === '' )
					{
						continue;
					}
					$decoded = json_decode( $rawJson, true );
					if ( !is_array( $decoded ) )
					{
						continue;
					}

					$attrMap = [];
					/* ATTR0 through ATTR20 - capture all attribute label slots.
					 * Slot number is parsed from the key (ATTR0, ATTR1, etc). */
					foreach ( $decoded as $key => $value )
					{
						if ( preg_match( '/^ATTR(\d+)$/i', (string) $key, $m ) )
						{
							$slot = (int) $m[1];
							$label = trim( (string) $value );
							if ( $label !== '' )
							{
								$attrMap[ $slot ] = $label;
							}
						}
					}

					if ( !empty( $attrMap ) )
					{
						$this->sportsSouthCategoryAttrs[ (string) $catRow['catid'] ] = $attrMap;
					}
				}
			}
			catch ( \Throwable ) {}
		}

		/* Walk this product's category attribute labels and extract values */
		if ( $catKey !== '' && isset( $this->sportsSouthCategoryAttrs[ $catKey ] ) )
		{
			$labelMap = $this->sportsSouthCategoryAttrs[ $catKey ];

			foreach ( $labelMap as $slot => $label )
			{
				/* Build the ITATR key for this slot. Sports South uses ITATR0,
				 * ITATR1, etc paralleling the ATTR0..N labels. */
				$itatrKey = 'ITATR' . $slot;
				$itatrValue = trim( (string) ( $record[ $itatrKey ] ?? '' ) );
				if ( $itatrValue === '' )
				{
					continue;
				}

				/* Match label against the known label->column map.
				 * Case-insensitive substring match. First match wins. */
				$labelLower = mb_strtolower( $label );
				$targetColumn = null;
				foreach ( self::SPORTS_SOUTH_ATTR_LABEL_MAP as $needle => $col )
				{
					if ( str_contains( $labelLower, $needle ) )
					{
						$targetColumn = $col;
						break;
					}
				}

				if ( $targetColumn === null )
				{
					continue;
				}

				/* Apply per-column value parsing */
				$parsedValue = $itatrValue;
				if ( $targetColumn === 'barrel_length' )
				{
					/* Strip non-numeric chars except dot, take first match.
					 * "16\"" -> 16, "20.5\"" -> 20.5, "16 in" -> 16 */
					if ( preg_match( '/(\d+(?:\.\d+)?)/', $itatrValue, $m ) )
					{
						$parsedValue = $m[1];
					}
					else
					{
						continue;
					}
				}
				elseif ( $targetColumn === 'capacity' )
				{
					/* Parse first integer. "30+1" -> 30, "5+1" -> 5, "10" -> 10 */
					if ( preg_match( '/(\d+)/', $itatrValue, $m ) )
					{
						$parsedValue = $m[1];
					}
					else
					{
						continue;
					}
				}
				elseif ( $targetColumn === 'overall_length' )
				{
					/* v1.0.27: Same parser as barrel_length. "47.5\"" -> 47.5 */
					if ( preg_match( '/(\d+(?:\.\d+)?)/', $itatrValue, $m ) )
					{
						$parsedValue = $m[1];
					}
					else
					{
						continue;
					}
				}
				elseif ( $targetColumn === 'weight_oz' )
				{
					/* v1.0.27: Parse weight, convert to oz if needed.
					 * "22.4 oz" -> 22.4
					 * "1.4 lbs" -> 22.4 (1.4 * 16)
					 * "0.85 lb" -> 13.6
					 * "20" -> 20 (assume oz when unit absent)
					 *
					 * Sports South ATTR9 for pistols is typically oz already
					 * but rifles use lbs - support both. */
					if ( preg_match( '/(\d+(?:\.\d+)?)\s*(oz|lbs?|pounds?)?/i', $itatrValue, $m ) )
					{
						$numericVal = (float) $m[1];
						$unit = strtolower( trim( $m[2] ?? '' ) );

						if ( $unit === 'lb' || $unit === 'lbs' || $unit === 'pound' || $unit === 'pounds' )
						{
							$numericVal *= 16.0;
						}

						$parsedValue = number_format( $numericVal, 2, '.', '' );
					}
					else
					{
						continue;
					}
				}

				/* Inject as synthetic field. Uppercase for consistency
				 * with _BRAND_NAME, _CATEGORY_ID patterns. */
				$syntheticKey = '_' . strtoupper( $targetColumn );

				/* First match wins - don't overwrite if already set.
				 * (Some categories may have e.g. 'Stock Finish' AND
				 * 'Barrel Finish' both mapping to finish.) */
				if ( !isset( $record[ $syntheticKey ] ) )
				{
					$record[ $syntheticKey ] = $parsedValue;
				}
			}
		}

		/* Transform PICREF to full image URL. */
		$picref = (string) ( $record['PICREF'] ?? '' );
		$itemno = (string) ( $record['ITEMNO'] ?? '' );
		if ( $picref !== '' || $itemno !== '' )
		{
			$record['PICREF'] = \IPS\gdcatalog\Feed\Distributor\SportsSouthClient::imageUrlForPicref( $picref, $itemno );
		}

		/* Extract ITATR attributes using category-aware position map */
		$catId = (string) ( $record['CATID'] ?? '' );
		if ( $catId !== '' )
		{
			try
			{
				$catRow = \IPS\Db::i()->select( 'slug', 'gd_categories', [ 'id=?', (int) $catId ] )->first();
				$catSlug = (string) $catRow;
				for ( $i = 1; $i <= 20; $i++ )
				{
					$key = $i === 10 ? 'ITATR0' : 'ITATR' . $i;
					$val = trim( (string) ( $record[ $key ] ?? '' ) );
					if ( $val === '' )
					{
						continue;
					}
					$col = \IPS\gdcatalog\Feed\Distributor\SportsSouthAttributeMap::resolve( $catSlug, $i );
					if ( $col !== null )
					{
						$record[ '_ATTR_' . $col ] = $val;
					}
				}
			}
			catch ( \Throwable ) {}
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

			/* Merge ITATR-extracted attributes from enrichment */
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
		static $validColumns = null;
		if ( $validColumns === null )
		{
			try
			{
				$validColumns = array_keys( \IPS\Db::i()->getTableDefinition( 'gd_catalog' )['columns'] );
			}
			catch ( \Throwable )
			{
				$validColumns = [];
			}
		}
		foreach ( $mapped as $field => $value )
		{
			if ( $value !== null && $value !== '' && in_array( $field, $validColumns, true ) )
			{
				$product->$field = $value;
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
	protected function updateProduct( Product $product, array $mapped, array $rawRecord = [] ): int
	{
		$conflictCount = 0;
		$changed       = false;

		/* Delegate to ConflictResolver for each field */
		$resolver = new \IPS\gdcatalog\Feed\ConflictResolver(
			$product,
			$this->feed,
			$this->log
		);

		static $validColumns = null;
		if ( $validColumns === null )
		{
			try
			{
				$validColumns = array_keys( \IPS\Db::i()->getTableDefinition( 'gd_catalog' )['columns'] );
			}
			catch ( \Throwable )
			{
				$validColumns = [];
			}
		}
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
}
