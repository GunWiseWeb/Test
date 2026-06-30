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

	/* In-process roster cache populated by primeCache(). [mfg_norm => [rows...]]
	   so classifyHandgun() doesn't hit the DB once per product. */
	protected static ?array $cache = null;

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

	/* ---------------------- Fetch + parse ---------------------- */

	/**
	 * Pull all roster pages from the DOJ site, parse them, and replace
	 * gd_compliance_ca_roster. Returns the per-run counts + any errors.
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

		/* Replace the table. Wipe + insert in chunks. */
		try
		{
			\IPS\Db::i()->delete( 'gd_compliance_ca_roster' );
		}
		catch ( \Throwable $e ) { $result['errors'][] = 'delete: ' . $e->getMessage(); }

		foreach ( array_chunk( $unique, 250 ) as $chunk )
		{
			try
			{
				\IPS\Db::i()->insert( 'gd_compliance_ca_roster', $chunk );
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

	/* ---------------------- Classification ---------------------- */

	/**
	 * Load the roster into memory once per run. Keyed by manufacturer_norm
	 * so classifyHandgun() can pick candidate rows in O(1) per product.
	 */
	public static function primeCache(): void
	{
		if ( self::$cache !== null ) { return; }
		$cache = [];
		try
		{
			foreach ( \IPS\Db::i()->select( '*', 'gd_compliance_ca_roster' ) as $r )
			{
				$key = (string) ( $r['manufacturer_norm'] ?? '' );
				if ( $key === '' ) { continue; }
				$cache[ $key ][] = $r;
			}
		}
		catch ( \Throwable ) {}
		self::$cache = $cache;
	}

	public static function clearCache(): void
	{
		self::$cache = null;
	}

	/**
	 * Classify a single catalog handgun against the in-memory roster.
	 *
	 *   EXACT  — manufacturer + (SKU OR model_core hit) + caliber agree
	 *            (current entry → on_roster, expired entry → off_roster).
	 *   STRONG — manufacturer + model_core substring + caliber agree.
	 *   WEAK   — manufacturer matches roster entries but NO model match
	 *            anywhere for that manufacturer with caliber agreement
	 *            → off_roster (low confidence — manufacturer is on roster
	 *            but this particular model isn't).
	 *   REVIEW — no manufacturer match, or ambiguous → unmatched_review
	 *            (NEVER auto-call CA-legal on a guess).
	 *
	 * @param  array $product  catalog row (manufacturer, model, caliber, mpn, title, upc)
	 * @return array{status:string,reason:string,confidence:string,matched_roster_id:?int,candidates:array<int,array<string,mixed>>}
	 */
	public static function classifyHandgun( array $product ): array
	{
		self::primeCache();

		$rawMfg     = (string) ( $product['manufacturer'] ?? '' );
		$rawModel   = (string) ( $product['model'] ?? '' );
		$rawTitle   = (string) ( $product['title'] ?? '' );
		$rawCaliber = (string) ( $product['caliber'] ?? '' );
		$rawMpn     = (string) ( $product['mpn'] ?? '' );

		$mfg       = self::normalizeMfg( $rawMfg );
		$modelCore = self::normalizeModelCore( $rawModel !== '' ? $rawModel : $rawTitle );
		$cal       = self::normalizeCaliber( $rawCaliber );
		$skuToken  = self::extractSku( $rawMpn !== '' ? $rawMpn : $rawModel );

		$noManufacturer = ( $mfg === '' );
		$candidates     = self::$cache[ $mfg ] ?? [];

		if ( $noManufacturer || empty( $candidates ) )
		{
			return [
				'status'            => self::STATUS_REVIEW,
				'reason'            => $noManufacturer ? 'no manufacturer on product' : 'manufacturer not in roster',
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
			'reason'            => 'manufacturer matched but model + caliber too ambiguous',
			'confidence'        => 'low',
			'matched_roster_id' => null,
			'candidates'        => self::summarizeCandidates( $candidates ),
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
