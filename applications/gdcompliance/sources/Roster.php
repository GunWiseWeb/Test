<?php
/**
 * @brief  GD Compliance — CA DOJ Certified Handgun roster fetcher + 3-state classifier
 *
 * Phase 2 of gdcompliance. The CA DOJ requires handguns sold by an FFL to
 * civilians to appear on its Certified Handgun roster. Matching catalog
 * handguns to the roster is IMPERFECT (roster model names are finish/SKU-
 * specific and inconsistent: "17 (Black) / Steel, Polymer",
 * "P226R (Black) 226R-9-BSS-CA", "XD9101"). So we never auto-decide an
 * uncertain handgun. Each catalog handgun gets one of THREE outcomes:
 *
 *   on_roster        — confidently matched to a CURRENT roster entry → CA legal.
 *   off_roster       — confidently determined NOT on roster (or expired)  → flag.
 *   unmatched_review — could not confidently place either way → review queue.
 *
 * "on_roster" (which CLEARS the CA roster restriction) is only assigned on
 * EXACT or STRONG confidence WITH caliber agreement. A weak/fuzzy match
 * NEVER calls a handgun CA-legal — it either lands in off_roster (when
 * we're confident it's absent from the roster) or in the review queue.
 *
 * The fetcher is manual-trigger only. Never scheduled.
 */

namespace IPS\gdcompliance;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Roster
{
	const ROSTER_URL = 'https://oag.ca.gov/firearms/certified-handguns/search';

	const MAX_PAGES        = 30;
	const PAGE_THROTTLE_MS = 1000;     /* 1 s between pages */
	const PAGE_TIMEOUT_S   = 60;

	/* The three roster states gdcompliance covers — plus DC which is derived
	   from the union (no separate fetch). MD's "all models approved" blanket
	   semantics are handled via the `blanket` column. */
	const ROSTER_STATES = [ 'CA', 'MA', 'MD' ];

	/* Default MA roster PDF URL — date in filename rolls monthly so it's
	   ALSO exposed as setting gdcompliance_ma_roster_url. Derrick updates
	   the setting when a new edition lands. */
	const MA_ROSTER_URL_DEFAULT = 'https://www.mass.gov/doc/approved-handgun-roster-april-2026/download';

	/* Status constants — these are the three states everyone references. */
	const STATUS_ON      = 'on_roster';
	const STATUS_OFF     = 'off_roster';
	const STATUS_REVIEW  = 'unmatched_review';

	/* Manufacturer normalization. Maps every form a feed might present to a
	   single canonical key. The same map is applied to roster rows on
	   import AND to catalog rows at classify time, so they meet on common
	   ground. Extensible — just add a row. */
	const MFG_ALIASES = [
		'glock'           => [ 'glock', 'glock inc', 'glock ges.m.b.h', 'glock gmbh', 'gmbh' ],
		'smith-wesson'    => [ 'smith & wesson', 'smith&wesson', 'smith and wesson', 's&w', 'smith wesson', 'sw' ],
		'sig-sauer'       => [ 'sig sauer', 'sig', 'sigsauer', 'sig-sauer', 'sig arms' ],
		'ruger'           => [ 'ruger', 'sturm, ruger & co.', 'sturm ruger', 'sturm, ruger', 'sturm ruger & co' ],
		'beretta'         => [ 'beretta', 'beretta usa', 'beretta usa corp', 'pietro beretta' ],
		'colt'            => [ 'colt', 'colt manufacturing', 'colt mfg', 'colts manufacturing' ],
		'remington'       => [ 'remington', 'remington arms', 'remington arms company' ],
		'springfield'     => [ 'springfield armory', 'springfield', 'springfield-armory' ],
		'heckler-koch'    => [ 'heckler & koch', 'h&k', 'hk', 'heckler koch', 'heckler-koch' ],
		'walther'         => [ 'walther', 'walther arms', 'carl walther' ],
		'kimber'          => [ 'kimber', 'kimber mfg', 'kimber manufacturing' ],
		'cz'              => [ 'cz', 'cz-usa', 'cz usa', 'ceska zbrojovka', 'ceska' ],
		'fn'              => [ 'fn', 'fn herstal', 'fn america', 'fnh' ],
		'taurus'          => [ 'taurus', 'taurus international', 'forjas taurus' ],
		'kel-tec'         => [ 'kel-tec', 'kel tec', 'keltec', 'kel-tec cnc' ],
		'mossberg'        => [ 'mossberg', 'o.f. mossberg & sons', 'of mossberg' ],
		'browning'        => [ 'browning', 'browning arms' ],
		'kahr'            => [ 'kahr', 'kahr arms' ],
		'bersa'           => [ 'bersa' ],
		'eaa'             => [ 'eaa', 'european american armory' ],
		'rock-island'     => [ 'rock island armory', 'rock island', 'armscor' ],
		'iwi'             => [ 'iwi', 'iwi us', 'israel weapon industries' ],
		'magnum-research' => [ 'magnum research', 'magnum research inc' ],
		'north-american'  => [ 'north american arms', 'naa' ],
	];

	/* In-process roster cache populated by primeCache().
	   [ rosterState => [ mfg_norm => [rows...] ] ] so classifyHandgun()
	   doesn't hit the DB once per product, and we can look up just the
	   state we're matching against. */
	protected static ?array $cache = null;

	/* Per-state blanket-manufacturer cache (MD only currently).
	   [ rosterState => set<mfg_norm> ] */
	protected static array $blanket = [];

	/* ---------------------- Manufacturer / caliber / model normalization ---------------------- */

	public static function normalizeMfg( string $raw ): string
	{
		$raw = strtolower( trim( $raw ) );
		if ( $raw === '' ) { return ''; }
		/* Strip very common suffixes that vary across feeds. */
		$raw = preg_replace( '/[,.]/', ' ', $raw );
		$raw = preg_replace( '/\b(inc|corp|llc|usa|company|co|ltd|gmbh|mfg|manufacturing|arms)\b/', '', $raw );
		$raw = trim( preg_replace( '/\s+/', ' ', $raw ) );

		foreach ( self::MFG_ALIASES as $canonical => $aliases )
		{
			foreach ( $aliases as $a )
			{
				$a = preg_replace( '/[,.]/', ' ', strtolower( $a ) );
				$a = trim( preg_replace( '/\s+/', ' ', $a ) );
				if ( $a === $raw || strpos( $raw, $a ) !== false || strpos( $a, $raw ) !== false )
				{
					return $canonical;
				}
			}
		}
		/* Unknown manufacturer — fall back to the cleaned form so a Glock
		   row meets a Glock row even without an alias. */
		return $raw;
	}

	public static function normalizeCaliber( string $raw ): string
	{
		$raw = strtolower( trim( $raw ) );
		if ( $raw === '' ) { return ''; }
		$raw = str_replace( [ ' ', '.', '_' ], '', $raw );
		/* "auto" / "acp" are equivalent suffixes. */
		$raw = preg_replace( '/automatic|auto(matic)?\b/', 'auto', $raw );
		/* Common variants. */
		$map = [
			'9mmluger' => '9mm', '9x19' => '9mm', '9mmpara' => '9mm', '9mmparabellum' => '9mm', '9luger' => '9mm', '9mm' => '9mm', '9' => '9mm',
			'45acp' => '45acp', '45auto' => '45acp', '45ap' => '45acp',
			'40sw' => '40sw', '40s&w' => '40sw', '40' => '40sw',
			'380acp' => '380acp', '380auto' => '380acp', '380' => '380acp',
			'357mag' => '357mag', '357magnum' => '357mag', '357' => '357mag',
			'38special' => '38spl', '38spl' => '38spl', '38spc' => '38spl', '38' => '38spl',
			'22lr' => '22lr', '22longrifle' => '22lr', '22' => '22lr',
			'10mm' => '10mm', '10mmauto' => '10mm',
		];
		if ( isset( $map[ $raw ] ) ) { return $map[ $raw ]; }
		/* Match against the keys with a startswith for messy variants. */
		foreach ( $map as $needle => $canonical )
		{
			if ( strpos( $raw, $needle ) === 0 ) { return $canonical; }
		}
		return $raw;
	}

	/**
	 * Normalize a model string into a canonical comparison token.
	 *
	 *   "17 (Black) / Steel, Polymer"                → "17"
	 *   "P226R (Black) 226R-9-BSS-CA"                → "p226r 226r9bssca"
	 *   "XD9101"                                     → "xd9101"
	 *   "M&P 9 Compact (Black)"                      → "mp9compact"
	 *
	 * Lowercases, strips finish/material after '/', drops parenthetical
	 * color/finish text, collapses whitespace, keeps alphanumerics +
	 * dashes (later flattened by extractSku).
	 */
	public static function normalizeModelCore( string $raw ): string
	{
		$s = strtolower( trim( $raw ) );
		if ( $s === '' ) { return ''; }
		/* Drop trailing finish/material list after '/'. */
		if ( ( $slash = strpos( $s, '/' ) ) !== false ) { $s = substr( $s, 0, $slash ); }
		/* Strip parenthetical "(black)", "(stainless)", "(two-tone)" etc. */
		$s = preg_replace( '/\([^)]*\)/', ' ', $s );
		/* Collapse non-alphanumerics → space, then trim. */
		$s = preg_replace( '/[^a-z0-9]+/', ' ', $s );
		$s = trim( preg_replace( '/\s+/', ' ', (string) $s ) );
		return $s;
	}

	/**
	 * Extract the SKU-like token from a model string. Roster entries often
	 * carry a model number after the descriptive part ("P226R … 226R-9-BSS-CA"
	 * → "226r9bssca"). For catalog rows we typically use the mpn or model
	 * itself. Lowercase + strip all non-alphanumerics so "226R-9-BSS-CA" and
	 * "226R9BSSCA" compare equal.
	 */
	public static function extractSku( string $raw ): string
	{
		$s = strtolower( $raw );
		if ( $s === '' ) { return ''; }
		/* Pick the LAST token after whitespace if it looks SKU-ish (has digits). */
		$tokens = preg_split( '/\s+/', $s );
		if ( $tokens )
		{
			$last = end( $tokens );
			$flat = preg_replace( '/[^a-z0-9]+/', '', (string) $last );
			if ( strlen( $flat ) >= 4 && preg_match( '/\d/', $flat ) )
			{
				return $flat;
			}
		}
		/* Fall back to a flattened form of the whole string. */
		return preg_replace( '/[^a-z0-9]+/', '', $s );
	}

	/* ---------------------- Fetch + parse — CA ---------------------- */

	/**
	 * Pull all roster pages from the CA DOJ site, parse them, and replace
	 * the CA rows in gd_compliance_roster. Returns the per-run counts +
	 * any errors. Other states' rows are untouched.
	 *
	 * @return array{rows:int,pages:int,current:int,expired:int,errors:array<int,string>,duration_ms:int}
	 */
	public static function fetchAndParse(): array
	{
		$result = [
			'rows' => 0, 'pages' => 0, 'current' => 0, 'expired' => 0,
			'errors' => [], 'duration_ms' => 0,
		];
		$start = microtime( true );

		$collected = [];
		$lastCount = -1;
		for ( $page = 0; $page < self::MAX_PAGES; $page++ )
		{
			$url = $page === 0 ? self::ROSTER_URL : self::ROSTER_URL . '?page=' . $page;

			try
			{
				$body = (string) \IPS\Http\Url::external( $url )->request( self::PAGE_TIMEOUT_S )->get();
			}
			catch ( \Throwable $e )
			{
				$result['errors'][] = "page {$page}: " . $e->getMessage();
				break;
			}

			if ( trim( $body ) === '' )
			{
				/* Empty body → assume past last page. */
				break;
			}

			$rows = self::parseRosterHtml( $body );
			if ( empty( $rows ) )
			{
				/* No table-rows on this page → past the last paginated page. */
				if ( $page === 0 )
				{
					$result['errors'][] = 'page 0 produced 0 rows — page structure may have changed';
				}
				break;
			}

			foreach ( $rows as $r ) { $collected[] = $r; }

			/* Stop heuristics: the same number of rows back-to-back means the
			   site returned the same page twice (broken pager), or a stable
			   "all on one page" rendering. Detect by tracking the running
			   total. */
			if ( count( $collected ) === $lastCount )
			{
				break;
			}
			$lastCount = count( $collected );

			usleep( self::PAGE_THROTTLE_MS * 1000 );
		}

		$result['pages'] = $page;

		/* Drop fully-duplicate rows (manufacturer + model_raw + caliber +
		   expired_date) — guards against the pager double-serving the
		   same page. */
		$seen = [];
		$unique = [];
		foreach ( $collected as $r )
		{
			$key = $r['manufacturer'] . '|' . $r['model_raw'] . '|' . ( $r['caliber'] ?? '' ) . '|' . ( $r['expired_date'] ?? '' );
			if ( isset( $seen[ $key ] ) ) { continue; }
			$seen[ $key ] = true;
			$unique[] = $r;
		}

		/* Replace ONLY the CA rows. Wipe + insert in chunks. Other states'
		   rows stay untouched. */
		try
		{
			\IPS\Db::i()->delete( 'gd_compliance_roster', [ 'roster_state=?', 'CA' ] );
		}
		catch ( \Throwable $e ) { $result['errors'][] = 'delete: ' . $e->getMessage(); }

		/* Stamp roster_state on every row before insert. */
		foreach ( $unique as &$row ) { $row['roster_state'] = 'CA'; }
		unset( $row );

		foreach ( array_chunk( $unique, 250 ) as $chunk )
		{
			try
			{
				\IPS\Db::i()->insert( 'gd_compliance_roster', $chunk );
			}
			catch ( \Throwable $e )
			{
				$result['errors'][] = 'insert: ' . $e->getMessage();
			}
		}

		$result['rows']    = count( $unique );
		$result['current'] = count( array_filter( $unique, fn( $r ) => (int) ( $r['is_current'] ?? 0 ) === 1 ) );
		$result['expired'] = $result['rows'] - $result['current'];
		$result['duration_ms'] = (int) ( ( microtime( true ) - $start ) * 1000 );

		try { \IPS\Log::log( 'Roster::fetchAndParse complete: ' . json_encode( [
			'rows' => $result['rows'], 'pages' => $result['pages'], 'errors' => count( $result['errors'] ),
		] ), 'gdcompliance' ); } catch ( \Throwable ) {}

		/* Cache invalidated. */
		self::$cache = null;
		return $result;
	}

	/**
	 * Parse one rendered roster page. Defensive — uses DOMDocument + XPath,
	 * finds the table that has a "Manufacturer" column header, then walks
	 * every body row. Returns a list of insert-ready row arrays.
	 */
	protected static function parseRosterHtml( string $html ): array
	{
		$out = [];

		libxml_use_internal_errors( true );
		$doc = new \DOMDocument();
		/* LIBXML_NONET — never auto-fetch DTDs (rule #4). */
		@$doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();

		$xpath  = new \DOMXPath( $doc );
		$tables = $xpath->query( '//table' );
		if ( !$tables ) { return $out; }

		$fetchedAt = time();
		foreach ( $tables as $table )
		{
			/* Read headers either from thead/tr/th or the first tr's th cells. */
			$headers = [];
			foreach ( $xpath->query( './/thead//th | ./tr[1]//th', $table ) as $th )
			{
				$headers[] = strtolower( trim( $th->textContent ) );
			}
			if ( !in_array( 'manufacturer', $headers, true ) ) { continue; }

			$hMap = [];
			foreach ( $headers as $i => $h ) { $hMap[ $h ] = $i; }

			$bodyRows = $xpath->query( './/tbody/tr', $table );
			if ( !$bodyRows || $bodyRows->length === 0 )
			{
				$bodyRows = $xpath->query( './/tr[position() > 1]', $table );
			}

			foreach ( $bodyRows as $tr )
			{
				$cells = [];
				foreach ( $xpath->query( './td', $tr ) as $td )
				{
					/* Collapse newlines/whitespace in cell text. */
					$txt     = preg_replace( '/\s+/', ' ', trim( $td->textContent ) );
					$cells[] = $txt;
				}
				if ( count( $cells ) < 4 ) { continue; }

				$manufacturer = $cells[ $hMap['manufacturer'] ?? 0 ] ?? '';
				$modelRaw     = $cells[ $hMap['model']        ?? 1 ] ?? '';
				$gunType      = $cells[ $hMap['gun type']     ?? 2 ] ?? '';
				$barrel       = $cells[ $hMap['barrel length'] ?? 3 ] ?? '';
				$caliber      = $cells[ $hMap['caliber']      ?? 4 ] ?? '';
				$expired      = $cells[ $hMap['expired date'] ?? 5 ] ?? '';

				if ( $manufacturer === '' || $modelRaw === '' ) { continue; }

				$boland = 0;
				if ( $modelRaw !== '' && $modelRaw[0] === '*' )
				{
					$boland   = 1;
					$modelRaw = ltrim( $modelRaw, '* ' );
				}

				$expiredDate = self::parseExpiredDate( $expired );
				$isCurrent   = ( $expiredDate === null || $expiredDate >= date( 'Y-m-d' ) ) ? 1 : 0;

				$out[] = [
					'manufacturer'      => substr( $manufacturer, 0, 120 ),
					'manufacturer_norm' => substr( self::normalizeMfg( $manufacturer ), 0, 120 ),
					'model_raw'         => substr( $modelRaw, 0, 255 ),
					'model_core'        => substr( self::normalizeModelCore( $modelRaw ), 0, 255 ),
					'model_sku'         => substr( self::extractSku( $modelRaw ), 0, 120 ),
					'gun_type'          => substr( $gunType, 0, 20 ),
					'barrel'            => substr( $barrel, 0, 40 ),
					'caliber'           => substr( $caliber, 0, 60 ),
					'caliber_norm'      => substr( self::normalizeCaliber( $caliber ), 0, 40 ),
					'expired_date'      => $expiredDate,
					'is_current'        => $isCurrent,
					'boland_added'      => $boland,
					'fetched_at'        => $fetchedAt,
				];
			}
			/* First matching table wins. */
			break;
		}

		return $out;
	}

	/**
	 * Roster page shows expired_date as "MM/DD/YY" (sometimes "MM/DD/YYYY").
	 * Return YYYY-MM-DD or null on garbage.
	 */
	protected static function parseExpiredDate( ?string $v ): ?string
	{
		if ( $v === null ) { return null; }
		$v = trim( $v );
		if ( $v === '' || strtoupper( $v ) === 'N/A' ) { return null; }

		if ( preg_match( '#^(\d{1,2})/(\d{1,2})/(\d{2,4})$#', $v, $m ) )
		{
			$year = (int) $m[3];
			if ( $year < 100 ) { $year += 2000; }
			return sprintf( '%04d-%02d-%02d', $year, (int) $m[1], (int) $m[2] );
		}
		$ts = strtotime( $v );
		return $ts === false ? null : date( 'Y-m-d', $ts );
	}

	/* ---------------------- Fetch + parse — MA ---------------------- */

	/**
	 * Pull the MA EOPSS Approved Handgun Roster PDF, extract text, parse,
	 * and replace gd_compliance_roster rows for MA. URL comes from
	 * setting gdcompliance_ma_roster_url (default points at the current
	 * known monthly edition — Derrick updates the setting when MA
	 * publishes a new file).
	 *
	 * Text-extraction strategy is layered:
	 *   1. shell_exec('pdftotext -layout - -')  — preferred (clean output)
	 *   2. \Smalot\PdfParser if available       — pure-PHP fallback
	 *   3. raw regex over PDF text operators    — last-ditch crude scan
	 *
	 * The line parser walks the extracted text, recognizes
	 *   "<Manufacturer> <Model> <Caliber> [MM/DD/YY]"
	 * per line, using a known-manufacturer prefix match to split mfg vs
	 * model, then a caliber pattern to locate the caliber token.
	 *
	 * @return array{rows:int,current:int,errors:array<int,string>,duration_ms:int,url:string,extractor:string}
	 */
	public static function fetchMA(): array
	{
		$result = [ 'rows' => 0, 'current' => 0, 'errors' => [], 'duration_ms' => 0, 'url' => '', 'extractor' => '' ];
		$start  = microtime( true );

		$url = trim( (string) ( \IPS\Settings::i()->gdcompliance_ma_roster_url ?? '' ) );
		if ( $url === '' ) { $url = self::MA_ROSTER_URL_DEFAULT; }
		$result['url'] = $url;

		/* (1) Download PDF bytes. */
		$bytes = '';
		try
		{
			$bytes = (string) \IPS\Http\Url::external( $url )->request( self::PAGE_TIMEOUT_S )->get();
		}
		catch ( \Throwable $e )
		{
			$result['errors'][] = 'download: ' . $e->getMessage();
			$result['duration_ms'] = (int) ( ( microtime( true ) - $start ) * 1000 );
			return $result;
		}
		if ( $bytes === '' || strncmp( $bytes, '%PDF', 4 ) !== 0 )
		{
			$result['errors'][] = 'response is not a PDF (first bytes: ' . substr( bin2hex( $bytes ), 0, 16 ) . ')';
			$result['duration_ms'] = (int) ( ( microtime( true ) - $start ) * 1000 );
			return $result;
		}

		/* (2) Extract text. */
		[ $text, $extractor ] = self::extractPdfText( $bytes );
		$result['extractor']  = $extractor;
		if ( $text === '' )
		{
			$result['errors'][] = 'pdf text extraction returned empty';
			$result['duration_ms'] = (int) ( ( microtime( true ) - $start ) * 1000 );
			return $result;
		}

		/* (3) Line parse. */
		$rows = self::parseMaRosterText( $text );

		/* Replace ONLY the MA rows. */
		try { \IPS\Db::i()->delete( 'gd_compliance_roster', [ 'roster_state=?', 'MA' ] ); }
		catch ( \Throwable $e ) { $result['errors'][] = 'delete: ' . $e->getMessage(); }

		foreach ( array_chunk( $rows, 250 ) as $chunk )
		{
			try { \IPS\Db::i()->insert( 'gd_compliance_roster', $chunk ); }
			catch ( \Throwable $e ) { $result['errors'][] = 'insert: ' . $e->getMessage(); }
		}

		$result['rows']        = count( $rows );
		$result['current']     = count( array_filter( $rows, fn( $r ) => (int) ( $r['is_current'] ?? 0 ) === 1 ) );
		$result['duration_ms'] = (int) ( ( microtime( true ) - $start ) * 1000 );
		self::$cache   = null;
		self::$blanket = [];

		try { \IPS\Log::log( 'Roster::fetchMA complete: ' . json_encode( [
			'rows' => $result['rows'], 'extractor' => $extractor, 'errors' => count( $result['errors'] ),
		] ), 'gdcompliance' ); } catch ( \Throwable ) {}

		return $result;
	}

	/**
	 * Tiered PDF text extractor. Returns [text, extractor_label].
	 */
	protected static function extractPdfText( string $bytes ): array
	{
		/* (1) shell pdftotext if available. Write to a temp file then run
		   "pdftotext -layout <file> -" to stdout. */
		if ( function_exists( 'shell_exec' ) && function_exists( 'proc_open' ) )
		{
			$tmp = @tempnam( sys_get_temp_dir(), 'gdcompma_' );
			if ( $tmp !== false )
			{
				@file_put_contents( $tmp, $bytes );
				$cmd  = 'pdftotext -layout ' . escapeshellarg( $tmp ) . ' - 2>/dev/null';
				$text = (string) @shell_exec( $cmd );
				@unlink( $tmp );
				if ( strlen( $text ) > 200 )
				{
					return [ $text, 'pdftotext' ];
				}
			}
		}

		/* (2) Pure-PHP fallback — Smalot/PdfParser if it has been
		   autoloaded by another app. */
		if ( class_exists( '\\Smalot\\PdfParser\\Parser' ) )
		{
			try
			{
				$parser = new \Smalot\PdfParser\Parser();
				$pdf    = $parser->parseContent( $bytes );
				$text   = (string) $pdf->getText();
				if ( strlen( $text ) > 200 )
				{
					return [ $text, 'smalot' ];
				}
			}
			catch ( \Throwable ) {}
		}

		/* (3) Crude regex fallback — extracts strings shown via the Tj
		   operator from uncompressed content streams. This won't work on
		   flate-compressed streams; it's a last resort that at least gives
		   Derrick something visible in the error path. */
		$text = '';
		if ( preg_match_all( '/\(((?:\\\\.|[^()\\\\])*)\)\s*Tj/', $bytes, $m ) )
		{
			$text = implode( "\n", array_map( fn( $s ) => stripcslashes( $s ), $m[1] ) );
		}
		return [ $text, $text !== '' ? 'regex-tj' : 'none' ];
	}

	/**
	 * Parse the extracted MA PDF text into per-row inserts.
	 *
	 * MA format (per line): "<Manufacturer> <Model> <Caliber> [MM/DD/YY]"
	 * - Manufacturer may be multi-word ("Smith & Wesson", "Sig Sauer").
	 * - Caliber matches the caliber regex.
	 * - Trailing date is optional (only on recently added models;
	 *   absence = approved long ago, still current).
	 * - Page headers / footers / preamble are skipped (lines that don't
	 *   match the shape).
	 */
	protected static function parseMaRosterText( string $text ): array
	{
		$out       = [];
		$fetchedAt = time();

		$caliberRe = '/(?:^|\s)(\.?\d{1,3}(?:\.\d+)?\s?(?:mm|MM|ACP|acp|LR|lr|Magnum|Mag|magnum|mag|Special|Spl|Sig|SIG|Auto|GAP|Luger|S&W|x\d+|long|short))\b/u';

		foreach ( preg_split( "/\r\n|\r|\n/", $text ) as $line )
		{
			$line = trim( preg_replace( '/\s+/', ' ', (string) $line ) );
			if ( $line === '' ) { continue; }
			/* Skip page headers / EOPSS letterhead / preamble. Cheap heuristics. */
			if ( stripos( $line, 'Approved Firearms Roster' ) !== false ) { continue; }
			if ( preg_match( '/^Page\s+\d+\s+of\s+\d+/i', $line ) )       { continue; }
			if ( stripos( $line, 'Executive Office' ) !== false )         { continue; }
			if ( stripos( $line, 'Department of Criminal' ) !== false )   { continue; }
			if ( strlen( $line ) < 8 )                                    { continue; }
			if ( !preg_match( '/[A-Za-z]/', $line ) )                     { continue; }

			/* Find a manufacturer prefix. Walk the alias map matching the
			   longest prefix that's in the line. */
			$mfgRaw  = '';
			$mfgNorm = '';
			$rest    = '';
			$lower   = strtolower( $line );
			$bestLen = 0;
			foreach ( self::MFG_ALIASES as $canonical => $aliases )
			{
				foreach ( $aliases as $alias )
				{
					$a = strtolower( trim( $alias ) );
					if ( $a === '' || strlen( $a ) <= $bestLen ) { continue; }
					if ( strncmp( $lower, $a, strlen( $a ) ) === 0 )
					{
						$bestLen = strlen( $a );
						$mfgRaw  = substr( $line, 0, strlen( $a ) );
						$mfgNorm = $canonical;
						$rest    = trim( substr( $line, strlen( $a ) ) );
					}
				}
			}
			if ( $mfgNorm === '' )
			{
				/* If no canonical alias matches, take the first 1-2 capitalized
				   tokens as the manufacturer name — better than dropping the
				   row. The matcher's manufacturer_norm pipeline will still
				   group same-mfg rows. */
				if ( preg_match( '/^([A-Z][A-Za-z0-9&.\-]*(?:\s+[A-Z][A-Za-z0-9&.\-]*)?)\s+(.+)$/', $line, $m ) )
				{
					$mfgRaw  = $m[1];
					$mfgNorm = self::normalizeMfg( $mfgRaw );
					$rest    = $m[2];
				}
				else
				{
					continue;
				}
			}

			/* Strip trailing date if present. */
			$dateApproved = null;
			if ( preg_match( '#(\d{1,2}/\d{1,2}/\d{2,4})\s*$#', $rest, $dm ) )
			{
				$dateApproved = self::parseExpiredDate( $dm[1] );
				$rest         = trim( substr( $rest, 0, -strlen( $dm[0] ) ) );
			}

			/* Find caliber. Take the last caliber-like token. */
			$caliber = '';
			if ( preg_match_all( $caliberRe, $rest, $cm ) )
			{
				$caliber = trim( end( $cm[1] ) );
				/* Snip caliber + everything after from the rest → model. */
				$pos = strrpos( $rest, $caliber );
				if ( $pos !== false ) { $rest = trim( substr( $rest, 0, $pos ) ); }
			}
			$model = trim( $rest );
			if ( $model === '' ) { continue; }

			$out[] = [
				'roster_state'      => 'MA',
				'manufacturer'      => substr( $mfgRaw, 0, 120 ),
				'manufacturer_norm' => substr( $mfgNorm, 0, 120 ),
				'model_raw'         => substr( $model, 0, 255 ),
				'model_core'        => substr( self::normalizeModelCore( $model ), 0, 255 ),
				'model_sku'         => substr( self::extractSku( $model ), 0, 120 ),
				'blanket'           => 0,
				'gun_type'          => null,
				'barrel'            => null,
				'caliber'           => substr( $caliber, 0, 60 ),
				'caliber_norm'      => substr( self::normalizeCaliber( $caliber ), 0, 40 ),
				'expired_date'      => null,
				'date_approved'     => $dateApproved,
				'is_current'        => 1,
				'boland_added'      => 0,
				'fetched_at'        => $fetchedAt,
			];
		}

		return $out;
	}

	/* ---------------------- Manual CSV import — MD ---------------------- */

	/**
	 * Import the MD Maryland State Police roster from a CSV upload.
	 *
	 * MD's live roster is in a Tableau dashboard (not fetchable) and uses
	 * "All models approved" blanket entries per manufacturer (post-2021
	 * approval scheme). So MD is MANUAL CSV ONLY, with blanket semantics.
	 *
	 * Accepted CSV columns (case-insensitive, header row required):
	 *   manufacturer   (required)
	 *   model          (literal "ALL" / "*" / blank → blanket-approved
	 *                   for the whole manufacturer)
	 *   caliber        (optional)
	 *
	 * Replaces ALL MD rows on each import — re-importing is the way to
	 * update.
	 *
	 * @return array{rows:int,blanket:int,errors:array<int,string>,duration_ms:int}
	 */
	public static function importMD( string $csvText ): array
	{
		$result = [ 'rows' => 0, 'blanket' => 0, 'errors' => [], 'duration_ms' => 0 ];
		$start  = microtime( true );

		$lines = preg_split( "/\r\n|\r|\n/", trim( $csvText ) );
		if ( !$lines || count( $lines ) < 2 )
		{
			$result['errors'][] = 'csv has no data rows';
			return $result;
		}

		/* Header row. */
		$header = str_getcsv( (string) array_shift( $lines ) );
		$header = array_map( fn( $h ) => strtolower( trim( (string) $h ) ), $header );
		$idx    = [];
		foreach ( $header as $i => $h ) { $idx[ $h ] = $i; }
		if ( !isset( $idx['manufacturer'] ) )
		{
			$result['errors'][] = "csv missing 'manufacturer' column";
			return $result;
		}

		$now  = time();
		$rows = [];
		foreach ( $lines as $line )
		{
			$line = trim( (string) $line );
			if ( $line === '' ) { continue; }

			$cells       = str_getcsv( $line );
			$rawMfg      = (string) ( $cells[ $idx['manufacturer'] ] ?? '' );
			$rawModel    = (string) ( $cells[ $idx['model']        ?? -1 ] ?? '' );
			$rawCaliber  = (string) ( $cells[ $idx['caliber']      ?? -1 ] ?? '' );

			$rawMfg = trim( $rawMfg );
			if ( $rawMfg === '' ) { continue; }

			$isBlanket = false;
			$modelTrim = trim( $rawModel );
			if ( $modelTrim === '' || strtoupper( $modelTrim ) === 'ALL' || $modelTrim === '*' )
			{
				$isBlanket = true;
				$rawModel  = '*';
			}

			$rows[] = [
				'roster_state'      => 'MD',
				'manufacturer'      => substr( $rawMfg, 0, 120 ),
				'manufacturer_norm' => substr( self::normalizeMfg( $rawMfg ), 0, 120 ),
				'model_raw'         => substr( $rawModel, 0, 255 ),
				'model_core'        => $isBlanket ? '*' : substr( self::normalizeModelCore( $rawModel ), 0, 255 ),
				'model_sku'         => $isBlanket ? null : substr( self::extractSku( $rawModel ), 0, 120 ),
				'blanket'           => $isBlanket ? 1 : 0,
				'gun_type'          => null,
				'barrel'             => null,
				'caliber'           => $rawCaliber !== '' ? substr( $rawCaliber, 0, 60 ) : null,
				'caliber_norm'      => $rawCaliber !== '' ? substr( self::normalizeCaliber( $rawCaliber ), 0, 40 ) : null,
				'expired_date'      => null,
				'date_approved'     => null,
				'is_current'        => 1,
				'boland_added'      => 0,
				'fetched_at'        => $now,
			];
		}

		/* Replace ONLY the MD rows. */
		try { \IPS\Db::i()->delete( 'gd_compliance_roster', [ 'roster_state=?', 'MD' ] ); }
		catch ( \Throwable $e ) { $result['errors'][] = 'delete: ' . $e->getMessage(); }

		foreach ( array_chunk( $rows, 250 ) as $chunk )
		{
			try { \IPS\Db::i()->insert( 'gd_compliance_roster', $chunk ); }
			catch ( \Throwable $e ) { $result['errors'][] = 'insert: ' . $e->getMessage(); }
		}

		$result['rows']        = count( $rows );
		$result['blanket']     = count( array_filter( $rows, fn( $r ) => (int) $r['blanket'] === 1 ) );
		$result['duration_ms'] = (int) ( ( microtime( true ) - $start ) * 1000 );

		self::$cache   = null;
		self::$blanket = [];

		try { \IPS\Log::log( 'Roster::importMD complete: ' . json_encode( $result ), 'gdcompliance' ); } catch ( \Throwable ) {}

		return $result;
	}

	/* ---------------------- Classification ---------------------- */

	/**
	 * Load every roster state into memory once per run. Keyed by
	 *   [ rosterState => [ manufacturer_norm => [rows...] ] ]
	 * so classifyHandgun(<state>) does O(1) candidate lookup. Also builds
	 * the blanket-manufacturer index per state for MD.
	 */
	public static function primeCache(): void
	{
		if ( self::$cache !== null ) { return; }
		$cache   = [];
		$blanket = [];
		try
		{
			foreach ( \IPS\Db::i()->select( '*', 'gd_compliance_roster' ) as $r )
			{
				$state = (string) ( $r['roster_state'] ?? '' );
				$mfg   = (string) ( $r['manufacturer_norm'] ?? '' );
				if ( $state === '' || $mfg === '' ) { continue; }
				$cache[ $state ][ $mfg ][] = $r;
				if ( (int) ( $r['blanket'] ?? 0 ) === 1 )
				{
					$blanket[ $state ][ $mfg ] = true;
				}
			}
		}
		catch ( \Throwable ) {}
		self::$cache   = $cache;
		self::$blanket = $blanket;
	}

	public static function clearCache(): void
	{
		self::$cache   = null;
		self::$blanket = [];
	}

	/**
	 * Which states currently have roster rows loaded? Used by Engine to
	 * decide whether to run the roster pass at all per state.
	 *
	 * @return string[]
	 */
	public static function availableStates(): array
	{
		self::primeCache();
		return array_values( array_filter( self::ROSTER_STATES, fn( $s ) => !empty( self::$cache[ $s ] ?? [] ) ) );
	}

	/**
	 * Classify a single catalog handgun against the in-memory roster for
	 * ONE state (CA, MA, or MD).
	 *
	 *   MD BLANKET (MD only, evaluated FIRST) — the state's roster has a
	 *            blanket=1 row for this manufacturer (post-2021 MD approval
	 *            scheme: "all models approved") → on_roster.
	 *   EXACT  — manufacturer + (SKU OR model_core hit) + caliber agree
	 *            (current entry → on_roster, expired entry → off_roster).
	 *   STRONG — manufacturer + model_core substring + caliber agree.
	 *   WEAK   — manufacturer matches roster entries but NO model match
	 *            anywhere with caliber agreement → off_roster (low conf).
	 *   REVIEW — no manufacturer match, or ambiguous → unmatched_review
	 *            (NEVER auto-call CA-legal on a guess).
	 *
	 * @param  array  $product       catalog row (manufacturer, model, caliber, mpn, title, upc)
	 * @param  string $rosterState   one of self::ROSTER_STATES
	 * @return array{status:string,reason:string,confidence:string,matched_roster_id:?int,candidates:array<int,array<string,mixed>>}
	 */
	public static function classifyHandgun( array $product, string $rosterState = 'CA' ): array
	{
		self::primeCache();

		$rosterState = strtoupper( $rosterState );
		if ( !in_array( $rosterState, self::ROSTER_STATES, true ) ) { $rosterState = 'CA'; }

		$rawMfg     = (string) ( $product['manufacturer'] ?? '' );
		$rawModel   = (string) ( $product['model'] ?? '' );
		$rawTitle   = (string) ( $product['title'] ?? '' );
		$rawCaliber = (string) ( $product['caliber'] ?? '' );
		$rawMpn     = (string) ( $product['mpn'] ?? '' );

		$mfg       = self::normalizeMfg( $rawMfg );
		$modelCore = self::normalizeModelCore( $rawModel !== '' ? $rawModel : $rawTitle );
		$cal       = self::normalizeCaliber( $rawCaliber );
		$skuToken  = self::extractSku( $rawMpn !== '' ? $rawMpn : $rawModel );

		/* MD blanket FIRST — if MD approves the manufacturer wholesale, this
		   handgun is on_roster regardless of model. */
		if ( $rosterState === 'MD' && $mfg !== '' && !empty( self::$blanket['MD'][ $mfg ] ) )
		{
			return [
				'status'            => self::STATUS_ON,
				'reason'            => 'MD blanket approval (all models of manufacturer)',
				'confidence'        => 'exact',
				'matched_roster_id' => null,
				'candidates'        => [],
			];
		}

		$noManufacturer = ( $mfg === '' );
		$candidates     = self::$cache[ $rosterState ][ $mfg ] ?? [];

		if ( $noManufacturer || empty( $candidates ) )
		{
			return [
				'status'            => self::STATUS_REVIEW,
				'reason'            => $noManufacturer ? 'no manufacturer on product' : "manufacturer not on {$rosterState} roster",
				'confidence'        => 'none',
				'matched_roster_id' => null,
				'candidates'        => [],
			];
		}

		/* (1) EXACT — SKU token equality wins outright when caliber agrees. */
		if ( $skuToken !== '' )
		{
			foreach ( $candidates as $c )
			{
				if ( (string) ( $c['model_sku'] ?? '' ) !== $skuToken ) { continue; }
				if ( !self::caliberMatches( $cal, (string) ( $c['caliber_norm'] ?? '' ) ) ) { continue; }
				return self::tierFromCandidate( $c, 'exact SKU + caliber match', 'exact' );
			}
		}

		/* (2) STRONG — model_core substring (either direction) + caliber agree. */
		$strong = [];
		if ( $modelCore !== '' )
		{
			foreach ( $candidates as $c )
			{
				$rosterCore = (string) ( $c['model_core'] ?? '' );
				if ( $rosterCore === '' ) { continue; }
				if ( strpos( $rosterCore, $modelCore ) === false && strpos( $modelCore, $rosterCore ) === false )
				{
					continue;
				}
				if ( !self::caliberMatches( $cal, (string) ( $c['caliber_norm'] ?? '' ) ) ) { continue; }
				$strong[] = $c;
			}
		}
		if ( !empty( $strong ) )
		{
			/* Prefer a current entry when one exists. */
			$current = array_filter( $strong, fn( $c ) => (int) ( $c['is_current'] ?? 0 ) === 1 );
			$pick    = !empty( $current ) ? reset( $current ) : reset( $strong );
			return self::tierFromCandidate( $pick, 'strong model+caliber match', 'strong' );
		}

		/* (3) WEAK NEGATIVE — manufacturer matched, no model AT ALL agreed
		   on caliber. Lean off_roster, but only when caliber is present on
		   product (otherwise we can't tell). */
		if ( $cal !== '' )
		{
			$mfgHasAnyCurrent = false;
			foreach ( $candidates as $c )
			{
				if ( (int) ( $c['is_current'] ?? 0 ) === 1 ) { $mfgHasAnyCurrent = true; break; }
			}
			if ( $mfgHasAnyCurrent )
			{
				return [
					'status'            => self::STATUS_OFF,
					'reason'            => 'manufacturer on roster but this model+caliber not present',
					'confidence'        => 'weak',
					'matched_roster_id' => null,
					'candidates'        => self::summarizeCandidates( $candidates ),
				];
			}
		}

		/* (4) Anything else → review. Surface up to 10 near-miss entries so
		   the human deciding has context. */
		return [
			'status'            => self::STATUS_REVIEW,
			'reason'            => "manufacturer matched on {$rosterState} but model + caliber too ambiguous",
			'confidence'        => 'low',
			'matched_roster_id' => null,
			'candidates'        => self::summarizeCandidates( $candidates ),
		];
	}

	/**
	 * Derive the DC roster outcome from per-state CA/MA/MD outcomes.
	 *   on_roster        if ANY of the three is on_roster (DC accepts CA+MA+MD)
	 *   off_roster       if ALL three are confidently off_roster
	 *   unmatched_review otherwise (one or more is uncertain, none are on)
	 *
	 * @param  array<string, string> $perState  ['CA'=>status,'MA'=>status,'MD'=>status]
	 * @return array{status:string,reason:string,confidence:string}
	 */
	public static function deriveDC( array $perState ): array
	{
		$states = [ 'CA', 'MA', 'MD' ];
		$counts = [ self::STATUS_ON => 0, self::STATUS_OFF => 0, self::STATUS_REVIEW => 0, 'missing' => 0 ];
		foreach ( $states as $s )
		{
			$st = (string) ( $perState[ $s ] ?? '' );
			if ( $st === '' )                                { $counts['missing']++; }
			elseif ( $st === self::STATUS_ON )               { $counts[ self::STATUS_ON ]++; }
			elseif ( $st === self::STATUS_OFF )              { $counts[ self::STATUS_OFF ]++; }
			else                                             { $counts[ self::STATUS_REVIEW ]++; }
		}

		if ( $counts[ self::STATUS_ON ] >= 1 )
		{
			return [ 'status' => self::STATUS_ON, 'reason' => 'on at least one of CA/MA/MD', 'confidence' => 'derived' ];
		}
		if ( $counts[ self::STATUS_OFF ] === 3 )
		{
			return [ 'status' => self::STATUS_OFF, 'reason' => 'off all of CA/MA/MD', 'confidence' => 'derived' ];
		}
		return [
			'status'     => self::STATUS_REVIEW,
			'reason'     => 'derived from CA/MA/MD; need at least one on or all off',
			'confidence' => 'derived',
		];
	}

	protected static function tierFromCandidate( array $c, string $reason, string $confidence ): array
	{
		$id = (int) ( $c['id'] ?? 0 );
		if ( (int) ( $c['is_current'] ?? 0 ) === 1 )
		{
			return [
				'status'            => self::STATUS_ON,
				'reason'            => $reason,
				'confidence'        => $confidence,
				'matched_roster_id' => $id,
				'candidates'        => [],
			];
		}
		return [
			'status'            => self::STATUS_OFF,
			'reason'            => 'roster certification expired (' . $reason . ')',
			'confidence'        => $confidence,
			'matched_roster_id' => $id,
			'candidates'        => [],
		];
	}

	/**
	 * Caliber agreement. Missing-on-either-side does NOT count as agreement
	 * for an on_roster decision — that's the conservative bias.
	 */
	protected static function caliberMatches( string $product, string $roster ): bool
	{
		if ( $product === '' || $roster === '' ) { return false; }
		return $product === $roster;
	}

	protected static function summarizeCandidates( array $rows, int $limit = 10 ): array
	{
		$out   = [];
		$count = 0;
		foreach ( $rows as $r )
		{
			if ( $count++ >= $limit ) { break; }
			$out[] = [
				'id'         => (int) ( $r['id'] ?? 0 ),
				'model'      => (string) ( $r['model_raw'] ?? '' ),
				'caliber'    => (string) ( $r['caliber'] ?? '' ),
				'is_current' => (int) ( $r['is_current'] ?? 0 ),
			];
		}
		return $out;
	}
}

class Roster extends _Roster {}
