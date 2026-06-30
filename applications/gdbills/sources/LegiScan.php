<?php
/**
 * @brief  GD Bills — LegiScan API engine (port of FBT_API)
 *
 * Search across all 50 states for the configured keywords; for each hit
 * fetch the bill and apply a relevance filter (firearms allowlist +
 * exclusion list). Matching bills are upserted via Bill::upsert.
 *
 * Notable expansion vs the original WP plugin: search + relevance lists
 * now include manufacturer/model/feature terms (Glock, SIG Sauer, S&W,
 * Ruger, Remington, Colt, Beretta, Springfield Armory, AR-15/AK-47,
 * auto sear, bump stock, ghost gun, pistol brace, large capacity
 * magazine, machine gun, suppressor, silencer, 50 caliber) — fixes the
 * Illinois "Glock switch" bill miss where the bill referenced the
 * manufacturer but not generic "gun"/"pistol".
 *
 * Keyword lists are also overridable per-install via the settings
 * gdbills_search_keywords, gdbills_relevance_keywords,
 * gdbills_exclusion_keywords (each newline-separated).
 */

namespace IPS\gdbills;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _LegiScan
{
	const API_BASE = 'https://api.legiscan.com/';

	const DEFAULT_SEARCH_KEYWORDS = [
		'firearm', 'gun', 'rifle', 'pistol', 'handgun', 'shotgun',
		'concealed carry', 'open carry', 'second amendment',
		'assault weapon', 'magazine capacity', 'background check firearm',
		'glock', 'sig sauer', 'smith wesson', 'ruger', 'remington', 'colt',
		'beretta', 'springfield armory', 'ar-15', 'ar15', 'ak-47', 'ak47',
		'glock switch', 'auto sear', 'bump stock', 'ghost gun', 'pistol brace',
		'large capacity magazine', 'machine gun', 'suppressor', 'silencer',
		'50 caliber',
	];

	const DEFAULT_RELEVANCE_KEYWORDS = [
		'firearm', 'gun', 'rifle', 'pistol', 'handgun', 'shotgun',
		'ammunition', 'ammo', 'bullet', 'cartridge', 'holster',
		'suppressor', 'silencer',
		'concealed carry', 'open carry', 'second amendment', '2nd amendment',
		'weapon', 'self defense', 'self-defense', 'hunting', 'sporting purpose',
		'ffl', 'federal firearms license',
		'glock', 'sig sauer', 'smith wesson', 'ruger', 'remington', 'colt',
		'beretta', 'springfield armory', 'ar-15', 'ar15', 'ak-47', 'ak47',
		'auto sear', 'bump stock', 'ghost gun', 'pistol brace',
		'large capacity magazine', 'machine gun', 'assault weapon', '50 caliber',
	];

	const DEFAULT_EXCLUSION_KEYWORDS = [
		'nuclear', 'chemical weapon', 'biological weapon',
		'knife', 'sword',
		'taser', 'stun gun', 'pepper spray',
		'school incident report', 'incident report only',
	];

	const STATES = [
		'AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA',
		'ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR',
		'PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY',
	];

	/**
	 * Run a full sync: every keyword × every state. Each iteration is
	 * wrapped so one failure can never abort the whole run.
	 *
	 * @return array{processed:int,upserted:int,skipped:int,errors:int}
	 */
	public static function fetchAllBills( ?array $states = null ): array
	{
		$counts = [ 'processed' => 0, 'upserted' => 0, 'skipped' => 0, 'errors' => 0 ];

		$key = trim( (string) ( \IPS\Settings::i()->gdbills_legiscan_key ?? '' ) );
		if ( $key === '' )
		{
			try { \IPS\Log::log( 'LegiScan: no API key configured; abort sync', 'gdbills' ); } catch ( \Throwable ) {}
			return $counts;
		}

		$searchKw   = self::keywordsFromSetting( 'gdbills_search_keywords',    self::DEFAULT_SEARCH_KEYWORDS );
		$allowKw    = self::keywordsFromSetting( 'gdbills_relevance_keywords', self::DEFAULT_RELEVANCE_KEYWORDS );
		$excludeKw  = self::keywordsFromSetting( 'gdbills_exclusion_keywords', self::DEFAULT_EXCLUSION_KEYWORDS );

		/* Per-hit LegiScan relevance threshold (0-100). Skip detail fetch +
		   storage for low-relevance matches — kills most incidental-mention
		   junk AND saves API quota. */
		$threshold = (int) ( \IPS\Settings::i()->gdbills_relevance_threshold ?? 50 );
		if ( $threshold < 0 )   { $threshold = 0; }
		if ( $threshold > 100 ) { $threshold = 100; }

		$states = $states ?: self::STATES;

		foreach ( $states as $state )
		{
			foreach ( $searchKw as $kw )
			{
				try
				{
					$found = self::getSearch( $key, $state, $kw );
				}
				catch ( \Throwable $e )
				{
					$counts['errors']++;
					try { \IPS\Log::log( "LegiScan search {$state}/{$kw}: " . $e->getMessage(), 'gdbills' ); } catch ( \Throwable ) {}
					continue;
				}

				foreach ( $found as $hit )
				{
					$counts['processed']++;
					$billId = isset( $hit['bill_id'] ) ? (int) $hit['bill_id'] : 0;
					if ( $billId <= 0 ) { $counts['skipped']++; continue; }

					/* Relevance gate (Fix A). LegiScan's op=getSearch carries
					   a relevance score 0-100. Skip detail fetch + storage when
					   below threshold. Missing field → treat as passing (don't
					   drop hits without a score; the title filter is the backstop). */
					if ( isset( $hit['relevance'] ) && (int) $hit['relevance'] < $threshold )
					{
						$counts['skipped']++;
						continue;
					}

					try
					{
						$detail = self::getBill( $key, $billId );
					}
					catch ( \Throwable $e )
					{
						$counts['errors']++;
						try { \IPS\Log::log( "LegiScan getBill {$billId}: " . $e->getMessage(), 'gdbills' ); } catch ( \Throwable ) {}
						continue;
					}

					if ( !$detail )
					{
						$counts['skipped']++;
						continue;
					}

					if ( !self::isFirearmsRelated( $detail, $allowKw, $excludeKw ) )
					{
						$counts['skipped']++;
						continue;
					}

					$parsed = self::parseBill( $detail, $state );
					$res    = Bill::upsert( $parsed );
					if ( $res['action'] === 'insert' || $res['action'] === 'update' )
					{
						$counts['upserted']++;
					}
					else
					{
						$counts['skipped']++;
					}

					/* Modest rate-limit pause between bill detail fetches. */
					usleep( 150000 );
				}

				/* Pause between keyword searches per state. */
				usleep( 200000 );

				Bill::setMeta( 'sync_progress', json_encode( [
					'state'    => $state,
					'keyword'  => $kw,
					'counts'   => $counts,
					'updated'  => date( 'Y-m-d H:i:s' ),
				] ) );
			}

			Bill::setMeta( 'last_update_' . $state, date( 'Y-m-d H:i:s' ) );
		}

		Bill::setMeta( 'last_update_global', date( 'Y-m-d H:i:s' ) );
		try { \IPS\Settings::i()->changeValues( [ 'gdbills_last_sync' => date( 'Y-m-d H:i:s' ) ] ); } catch ( \Throwable ) {}

		try { \IPS\Log::log( 'LegiScan sync complete: ' . json_encode( $counts ), 'gdbills' ); } catch ( \Throwable ) {}

		return $counts;
	}

	/* ---------------------- Existing-laws ingestion ---------------------- */

	/**
	 * Seed the curated existing-laws JSON (data/existing_laws.json) into
	 * gd_bills as bill_type='law'. Idempotent — Bill::upsert matches on
	 * (bill_number, state_code) so re-running just updates rows in place.
	 * No API calls, no LegiScan quota.
	 *
	 * @return array{processed:int,upserted:int,skipped:int,errors:int}
	 */
	public static function seedExistingLaws(): array
	{
		$counts = [ 'processed' => 0, 'upserted' => 0, 'skipped' => 0, 'errors' => 0 ];
		$file   = \IPS\ROOT_PATH . '/applications/gdbills/data/existing_laws.json';
		if ( !is_readable( $file ) )
		{
			try { \IPS\Log::log( 'seedExistingLaws: file unreadable ' . $file, 'gdbills' ); } catch ( \Throwable ) {}
			return $counts;
		}

		$rows = json_decode( (string) @file_get_contents( $file ), true );
		if ( !is_array( $rows ) )
		{
			try { \IPS\Log::log( 'seedExistingLaws: invalid JSON', 'gdbills' ); } catch ( \Throwable ) {}
			return $counts;
		}

		foreach ( $rows as $row )
		{
			if ( !is_array( $row ) ) { continue; }
			$counts['processed']++;
			try
			{
				$payload = [
					'bill_number'      => (string) ( $row['bill_number'] ?? '' ),
					'bill_title'       => (string) ( $row['bill_title']  ?? ( $row['bill_number'] ?? '' ) ),
					'state_code'       => strtoupper( (string) ( $row['state_code'] ?? '' ) ),
					'bill_type'        => 'law',
					'status'           => (string) ( $row['status']         ?? 'enacted' ),
					'progress_stage'   => (string) ( $row['progress_stage'] ?? 'became_law' ),
					'description'      => (string) ( $row['description']    ?? '' ),
					'url'              => (string) ( $row['url']            ?? '' ),
					'signed_date'      => isset( $row['signed_date'] ) ? self::cleanDate( $row['signed_date'] ) : null,
					'last_action'      => (string) ( $row['last_action']    ?? '' ),
					'last_action_date' => isset( $row['signed_date'] ) ? self::cleanDate( $row['signed_date'] ) : null,
					'sponsor_name'     => isset( $row['sponsor_name'] )  ? (string) $row['sponsor_name']  : null,
					'sponsor_party'    => isset( $row['sponsor_party'] ) ? (string) $row['sponsor_party'] : null,
					'source'           => 'seed',
				];
				$res = Bill::upsert( $payload );
				if ( $res['action'] === 'insert' || $res['action'] === 'update' )
				{
					$counts['upserted']++;
				}
				else
				{
					$counts['skipped']++;
				}
			}
			catch ( \Throwable $e )
			{
				$counts['errors']++;
				try { \IPS\Log::log( 'seedExistingLaws row: ' . $e->getMessage(), 'gdbills' ); } catch ( \Throwable ) {}
			}
		}

		try { \IPS\Log::log( 'seedExistingLaws: ' . json_encode( $counts ), 'gdbills' ); } catch ( \Throwable ) {}
		return $counts;
	}

	/**
	 * Opt-in admin action: query LegiScan for status=4 (Passed) firearms bills
	 * from PRIOR years (default 2021-2024) and tag them bill_type='law' so they
	 * show as existing laws. API-EXPENSIVE — admin triggered only, never wired
	 * to the daily task. Optional $oneState narrows scope to control quota.
	 *
	 * Resumable progress writes to gd_bills_meta key 'detect_prior_progress'
	 * so an interrupted run leaves a breadcrumb. Throttled with longer
	 * sleeps than fetchAllBills (single keyword per search, but year matrix).
	 *
	 * @return array{processed:int,upserted:int,skipped:int,errors:int}
	 */
	public static function detectPriorSessionLaws( ?string $oneState = null, array $years = [2021,2022,2023,2024] ): array
	{
		$counts = [ 'processed' => 0, 'upserted' => 0, 'skipped' => 0, 'errors' => 0 ];

		$key = trim( (string) ( \IPS\Settings::i()->gdbills_legiscan_key ?? '' ) );
		if ( $key === '' )
		{
			try { \IPS\Log::log( 'detectPriorSessionLaws: no API key', 'gdbills' ); } catch ( \Throwable ) {}
			return $counts;
		}

		$searchKw = self::keywordsFromSetting( 'gdbills_search_keywords',    self::DEFAULT_SEARCH_KEYWORDS );
		$allowKw  = self::keywordsFromSetting( 'gdbills_relevance_keywords', self::DEFAULT_RELEVANCE_KEYWORDS );
		$exclKw   = self::keywordsFromSetting( 'gdbills_exclusion_keywords', self::DEFAULT_EXCLUSION_KEYWORDS );

		$threshold = (int) ( \IPS\Settings::i()->gdbills_relevance_threshold ?? 50 );
		if ( $threshold < 0 )   { $threshold = 0; }
		if ( $threshold > 100 ) { $threshold = 100; }

		$states = $oneState !== null
			? [ strtoupper( $oneState ) ]
			: self::STATES;

		foreach ( $states as $state )
		{
			foreach ( $years as $year )
			{
				foreach ( $searchKw as $kw )
				{
					try
					{
						$found = self::getSearch( $key, $state, $kw, (int) $year );
					}
					catch ( \Throwable $e )
					{
						$counts['errors']++;
						try { \IPS\Log::log( "detectPrior search {$state}/{$year}/{$kw}: " . $e->getMessage(), 'gdbills' ); } catch ( \Throwable ) {}
						continue;
					}

					foreach ( $found as $hit )
					{
						$counts['processed']++;
						$billId = isset( $hit['bill_id'] ) ? (int) $hit['bill_id'] : 0;
						if ( $billId <= 0 ) { $counts['skipped']++; continue; }

						/* Relevance gate (Fix A) — also saves quota here, where
						   the year matrix multiplies the search cost. */
						if ( isset( $hit['relevance'] ) && (int) $hit['relevance'] < $threshold )
						{
							$counts['skipped']++;
							continue;
						}

						try
						{
							$detail = self::getBill( $key, $billId );
						}
						catch ( \Throwable $e )
						{
							$counts['errors']++;
							try { \IPS\Log::log( "detectPrior getBill {$billId}: " . $e->getMessage(), 'gdbills' ); } catch ( \Throwable ) {}
							continue;
						}

						if ( !$detail || (int) ( $detail['status'] ?? 0 ) !== 4 )
						{
							$counts['skipped']++;
							continue;
						}

						if ( !self::isFirearmsRelated( $detail, $allowKw, $exclKw ) )
						{
							$counts['skipped']++;
							continue;
						}

						$parsed = self::parseBill( $detail, $state );
						/* Re-tag as existing law since these are prior-year enacted bills. */
						$parsed['bill_type'] = 'law';
						$parsed['source']    = 'legiscan_prior';

						$res = Bill::upsert( $parsed );
						if ( $res['action'] === 'insert' || $res['action'] === 'update' )
						{
							$counts['upserted']++;
						}
						else
						{
							$counts['skipped']++;
						}

						usleep( 250000 ); /* heavier throttle than fetchAllBills */
					}

					usleep( 300000 );

					Bill::setMeta( 'detect_prior_progress', json_encode( [
						'state'   => $state,
						'year'    => $year,
						'keyword' => $kw,
						'counts'  => $counts,
						'updated' => date( 'Y-m-d H:i:s' ),
					] ) );
				}
			}
		}

		try { \IPS\Log::log( 'detectPriorSessionLaws complete: ' . json_encode( $counts ), 'gdbills' ); } catch ( \Throwable ) {}
		return $counts;
	}

	/* ---------------------- API wrappers ---------------------- */

	protected static function getSearch( string $key, string $state, string $query, ?int $year = null ): array
	{
		$url = self::API_BASE . '?key=' . urlencode( $key )
			. '&op=getSearch'
			. '&state=' . urlencode( $state )
			. '&query=' . urlencode( $query );
		if ( $year !== null && $year > 0 )
		{
			$url .= '&year=' . (int) $year;
		}
		$data = self::http( $url );
		if ( !is_array( $data ) || ( $data['status'] ?? '' ) !== 'OK' )
		{
			return [];
		}
		$results = $data['searchresult'] ?? [];
		if ( !is_array( $results ) ) { return []; }
		/* LegiScan's getSearch returns a 'summary' key + numeric-indexed associative
		   hits (bill_id, bill_number, state, title, last_action_date, url, ...). */
		unset( $results['summary'] );
		return array_values( array_filter( $results, 'is_array' ) );
	}

	protected static function getBill( string $key, int $billId ): ?array
	{
		$url = self::API_BASE . '?key=' . urlencode( $key ) . '&op=getBill&id=' . $billId;
		$data = self::http( $url );
		if ( !is_array( $data ) || ( $data['status'] ?? '' ) !== 'OK' )
		{
			return null;
		}
		return ( $data['bill'] ?? null ) ?: null;
	}

	protected static function http( string $url ): ?array
	{
		try
		{
			$res  = \IPS\Http\Url::external( $url )->request( 30 )->get();
			$body = (string) $res;
			if ( $body === '' ) { return null; }
			$json = json_decode( $body, true );
			return is_array( $json ) ? $json : null;
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'LegiScan http: ' . $e->getMessage(), 'gdbills' ); } catch ( \Throwable ) {}
			return null;
		}
	}

	/* ---------------------- Filter + parse ---------------------- */

	/**
	 * Decide whether a LegiScan bill detail is firearms-related.
	 * - First excludes on the exclusion list (clear non-firearms weapons).
	 * - Then includes if ANY allowlist term is present.
	 * Manufacturer/model/feature terms in the allowlist win over generic
	 * exclusions, so a Glock-switch bill is kept even if the description
	 * mentions a knife in passing.
	 */
	/**
	 * Title-weighted firearms-related test (Fix B — backstop for incidental
	 * mentions). The bill PASSES when:
	 *
	 *   (A) ANY allow term appears in the TITLE (the bill is actually ABOUT
	 *       firearms), unless an exclusion term also appears in the title
	 *       and no STRONG phrase is present anywhere — or
	 *   (B) A STRONG multi-word firearms phrase (concealed carry, assault
	 *       weapon, ghost gun, glock, etc.) appears anywhere in
	 *       title + description.
	 *
	 * Otherwise it FAILS. This drops the incidental-mention junk like
	 * KS HB2329 (juvenile-justice bill that mentions "firearm" once) and
	 * KS SB82 (tax-credit bill that mentions "lockable gun storage") —
	 * both have unrelated TITLES and no strong phrase in the body.
	 *
	 * Allow + exclude lists are still user-controllable via the ACP
	 * settings (gdbills_relevance_keywords / gdbills_exclusion_keywords).
	 */
	const STRONG_PHRASES = [
		/* Multi-word firearms-specific phrases that don't appear incidentally. */
		'concealed carry', 'open carry', 'assault weapon', 'large capacity magazine',
		'machine gun', 'ghost gun', 'auto sear', 'bump stock', 'pistol brace',
		'second amendment', '2nd amendment',
		'firearm dealer', 'firearms dealer', 'gun dealer', 'ffl',
		'red flag', 'extreme risk protection', 'universal background check', 'waiting period',
		/* Manufacturer + model terms (unambiguous when they appear). */
		'glock', 'sig sauer', 'smith wesson', 'ruger', 'remington', 'colt',
		'beretta', 'springfield armory', 'ar-15', 'ar15', 'ak-47', 'ak47',
		'glock switch',
	];

	public static function isFirearmsRelated( array $bill, array $allow, array $exclude ): bool
	{
		$title = strtolower( (string) ( $bill['title']       ?? '' ) );
		$desc  = strtolower( (string) ( $bill['description'] ?? '' ) );
		$combined = $title . ' ' . $desc;

		/* Detect a STRONG firearms signal anywhere — this lets a real firearms
		   bill survive even when an exclusion term appears in passing. */
		$hasStrong = false;
		foreach ( self::STRONG_PHRASES as $phrase )
		{
			if ( strpos( $combined, $phrase ) !== false ) { $hasStrong = true; break; }
		}

		/* (A) TITLE allow check — any allow term in the title means the bill
		   is actually about firearms. Subject to exclusion-without-strong. */
		$titleMatch = false;
		foreach ( $allow as $term )
		{
			$t = strtolower( trim( (string) $term ) );
			if ( $t === '' ) { continue; }
			if ( strpos( $title, $t ) !== false ) { $titleMatch = true; break; }
		}

		if ( $titleMatch )
		{
			if ( !$hasStrong )
			{
				foreach ( $exclude as $term )
				{
					$t = strtolower( trim( (string) $term ) );
					if ( $t === '' ) { continue; }
					if ( strpos( $combined, $t ) !== false ) { return false; }
				}
			}
			return true;
		}

		/* (B) STRONG phrase anywhere → pass. Honors the exclusion list only
		   when there's no strong signal (handled above; here strong wins). */
		if ( $hasStrong ) { return true; }

		/* No title hit and no strong phrase — this is the incidental-mention
		   case (a single bare "firearm"/"gun" buried in an unrelated body).
		   Drop it; this is the fix vs the prior loose substring filter. */
		return false;
	}

	/**
	 * Single source of truth for status / progress derivation. Both the live
	 * parseBill (full LegiScan response) and reparseStored (DB-only re-run
	 * over stored history JSON) call this. Returns the six fields that
	 * progress + tracker rendering depend on.
	 *
	 *   $statusCode      LegiScan status (1-6); 0 = none / let history decide
	 *   $history         array of {date, action} entries (oldest → newest)
	 *   $signedDateHint  fallback signed_date when status_code=4 has no
	 *                    explicit signed-in-history entry yet
	 *
	 * The advance-only invariant (a became_law/vetoed/failed terminal state
	 * is never downgraded by later history entries) lives here, so the
	 * sync and the re-parse button can never disagree.
	 *
	 * @return array{progress_stage:string,status:string,bill_type:string,signed_date:?string,passed_house_date:?string,passed_senate_date:?string}
	 */
	public static function deriveProgress( int $statusCode, array $history, ?string $signedDateHint = null ): array
	{
		$progressStage    = 'introduced';
		$status           = 'introduced';
		$billType         = 'pending';
		$signedDate       = null;
		$passedHouseDate  = null;
		$passedSenateDate = null;

		/* (1) status_code → coarse classification.
		 *   1=Intro, 2=Engrossed, 3=Enrolled — leave pending, let history refine.
		 *   4=Passed/Signed, 5=Vetoed, 6=Failed — terminal, win over history. */
		if ( $statusCode === 4 )
		{
			$billType      = 'enacted';
			$status        = 'enacted';
			$progressStage = 'became_law';
			$signedDate    = $signedDateHint;
		}
		elseif ( $statusCode === 5 )
		{
			$status        = 'vetoed';
			$progressStage = 'vetoed';
		}
		elseif ( $statusCode === 6 )
		{
			$status        = 'failed';
			$progressStage = 'failed';
		}

		$isTerminal = in_array( $progressStage, [ 'became_law', 'vetoed', 'failed' ], true );

		/* (2) Walk history actions oldest→newest. Capture chamber-pass dates
		 *     and an explicit signed-by-governor event (with the assigned
		 *     guard) so we can refine status_code 1/2/3 bills. */
		foreach ( $history as $h )
		{
			if ( !is_array( $h ) ) { continue; }
			$actionText = strtolower( (string) ( $h['action'] ?? '' ) );
			$actionDate = self::cleanDate( $h['date'] ?? null );
			if ( $actionText === '' ) { continue; }

			$mentionsSenate = ( strpos( $actionText, 'senate' ) !== false );
			$mentionsHouse  = ( strpos( $actionText, 'house' )  !== false );
			$thirdReadPass  = ( strpos( $actionText, 'third reading passed' ) !== false );

			/* Senate-pass — filler-tolerant. */
			if (
				preg_match( '/\bsenate\b.{0,15}\b(passed|adopted|concurred)\b/i', $actionText )
				|| preg_match( '/\b(passed|adopted)\b.{0,15}\bsenate\b/i', $actionText )
				|| ( $thirdReadPass && $mentionsSenate )
			)
			{
				$passedSenateDate = $passedSenateDate ?: $actionDate;
				if ( !$isTerminal && in_array( $progressStage, [ 'introduced', 'in_committee' ], true ) )
				{
					$progressStage = 'passed_senate';
				}
			}

			/* House-pass — same filler-tolerant pattern. */
			if (
				preg_match( '/\bhouse\b.{0,15}\b(passed|adopted|concurred)\b/i', $actionText )
				|| preg_match( '/\b(passed|adopted)\b.{0,15}\bhouse\b/i', $actionText )
				|| ( $thirdReadPass && $mentionsHouse )
			)
			{
				$passedHouseDate = $passedHouseDate ?: $actionDate;
				if ( !$isTerminal && in_array( $progressStage, [ 'introduced', 'in_committee', 'passed_senate' ], true ) )
				{
					$progressStage = 'passed_house';
				}
			}

			/* To-governor — delivered / sent / presented / transmitted /
			   forwarded to the governor (filler "to" / "to the" / "to the
			   honorable" allowed; fixes IL HB5136 case). */
			if (
				preg_match( '/\b(sent|delivered|presented|transmitted|forwarded)\b.{0,20}\bgovernor\b/i', $actionText )
				|| preg_match( "/\bto\b.{0,8}\bgovernor(?:'s)?\b.{0,8}\bdesk\b/i", $actionText )
			)
			{
				if ( !$isTerminal )
				{
					$progressStage = 'to_governor';
				}
			}

			/* Signed-into-law — filler-tolerant variants + CRITICAL guard
			   (excludes assigned/reassigned/designated). "public act" included
			   because IL stamps "Public Act N-NNN" only after signature. */
			$looksSigned = (
				preg_match( '/\bsigned\b.{0,20}\bgovernor\b/i',      $actionText )
				|| preg_match( '/\bgovernor\b.{0,20}\bsigned\b/i',   $actionText )
				|| preg_match( '/\bapproved\b.{0,20}\bgovernor\b/i', $actionText )
				|| preg_match( '/\bgovernor\b.{0,20}\bapproved\b/i', $actionText )
				|| preg_match( '/\bsigned into law\b/i',             $actionText )
				|| preg_match( '/\bbecame law\b/i',                  $actionText )
				|| preg_match( '/\bpublic act\b/i',                  $actionText )
			);
			$assignedGuard = (
				strpos( $actionText, 'assigned' )   !== false
				|| strpos( $actionText, 'reassigned' ) !== false
				|| strpos( $actionText, 'designated' ) !== false
			);
			if ( $looksSigned && !$assignedGuard )
			{
				$signedDate    = $signedDate ?: $actionDate;
				$progressStage = 'became_law';
				$billType      = 'enacted';
				$status        = 'enacted';
				$isTerminal    = true;
			}
		}

		return [
			'progress_stage'     => $progressStage,
			'status'             => $status,
			'bill_type'          => $billType,
			'signed_date'        => $signedDate,
			'passed_house_date'  => $passedHouseDate,
			'passed_senate_date' => $passedSenateDate,
		];
	}

	/**
	 * Map a LegiScan getBill response → gd_bills row. Be defensive — the
	 * API shape varies; missing fields stay null. Persists the raw history
	 * array as JSON in the `history` column so future parser fixes can be
	 * applied offline via reparseStored (no API calls).
	 */
	public static function parseBill( array $bill, string $stateCode ): array
	{
		$history    = ( isset( $bill['history'] ) && is_array( $bill['history'] ) ) ? $bill['history'] : [];
		$lastAction = $history ? end( $history ) : [];
		if ( !is_array( $lastAction ) ) { $lastAction = []; }

		$statusCode      = (int) ( $bill['status'] ?? 0 );
		$statusDate      = self::cleanDate( $bill['status_date'] ?? null );
		$signedDateHint  = ( $statusCode === 4 ) ? $statusDate : null;
		$dateIntroduced  = $statusDate;
		if ( !$dateIntroduced && isset( $history[0]['date'] ) )
		{
			$dateIntroduced = self::cleanDate( $history[0]['date'] );
		}

		$derived = self::deriveProgress( $statusCode, $history, $signedDateHint );

		/* Sponsor — primary is the entry with sponsor_type_id === 1; falls
		   back to sponsors[0] only if no entry is explicitly primary. */
		$sponsorName  = null;
		$sponsorParty = null;
		if ( isset( $bill['sponsors'] ) && is_array( $bill['sponsors'] ) )
		{
			foreach ( $bill['sponsors'] as $sp )
			{
				if ( !is_array( $sp ) ) { continue; }
				if ( (int) ( $sp['sponsor_type_id'] ?? 0 ) === 1 )
				{
					$sponsorName  = (string) ( $sp['name']  ?? '' ) ?: null;
					$sponsorParty = (string) ( $sp['party'] ?? '' ) ?: null;
					break;
				}
			}
			if ( !$sponsorName && isset( $bill['sponsors'][0] ) && is_array( $bill['sponsors'][0] ) )
			{
				$sponsorName  = (string) ( $bill['sponsors'][0]['name']  ?? '' ) ?: null;
				$sponsorParty = (string) ( $bill['sponsors'][0]['party'] ?? '' ) ?: null;
			}
		}

		return [
			'bill_number'        => (string) ( $bill['bill_number'] ?? '' ),
			'bill_title'         => (string) ( $bill['title']       ?? ( $bill['bill_number'] ?? '' ) ),
			'state_code'         => strtoupper( (string) ( $bill['state'] ?? $stateCode ) ),
			'bill_type'          => $derived['bill_type'],
			'status'             => substr( $derived['status'], 0, 50 ),
			'progress_stage'     => $derived['progress_stage'],
			'sponsor_name'       => $sponsorName,
			'sponsor_party'      => $sponsorParty,
			'description'        => (string) ( $bill['description'] ?? '' ),
			'url'                => (string) ( $bill['state_link'] ?? ( $bill['url'] ?? '' ) ),
			'date_introduced'    => $dateIntroduced,
			'last_action_date'   => self::cleanDate( $lastAction['date'] ?? null ),
			'last_action'        => (string) ( $lastAction['action'] ?? '' ),
			'passed_senate_date' => $derived['passed_senate_date'],
			'passed_house_date'  => $derived['passed_house_date'],
			'signed_date'        => $derived['signed_date'],
			'legiscan_id'        => (int) ( $bill['bill_id'] ?? 0 ),
			'source'             => 'legiscan',
			/* Persist the raw history array so reparseStored() can re-run the
			   parser offline (no LegiScan quota) when a future logic fix lands. */
			'history'            => $history ? json_encode( $history ) : null,
		];
	}

	/**
	 * DB-only re-parse of stored bills using the corrected deriveProgress
	 * logic. NO API calls — just decode stored history JSON (or fall back
	 * to last_action text on rows synced before v1.0.13), re-derive, and
	 * UPDATE rows whose progress stage has ADVANCED. Advance-only: a
	 * became_law/vetoed/failed terminal state is never downgraded.
	 *
	 * @param  string|null $oneState  Optional 2-letter state code to limit scope.
	 * @return array{processed:int,updated:int,unchanged:int,errors:int}
	 */
	public static function reparseStored( ?string $oneState = null ): array
	{
		$counts = [ 'processed' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => 0 ];

		$where = [];
		if ( $oneState !== null )
		{
			$st = strtoupper( trim( $oneState ) );
			if ( preg_match( '/^[A-Z]{2}$/', $st ) ) { $where[] = [ 'state_code=?', $st ]; }
		}

		/* Rank chain — higher means later. became_law/vetoed/failed all
		   share the terminal rank so a became_law row can't be reset to
		   vetoed and vice versa via a reparse. */
		$ranks = [
			'introduced'    => 0,
			'in_committee'  => 0,
			'passed_senate' => 1,
			'passed_house'  => 2,
			'to_governor'   => 3,
			'became_law'    => 4,
			'vetoed'        => 4,
			'failed'        => 4,
		];

		try
		{
			foreach ( \IPS\Db::i()->select(
				'id, state_code, bill_type, status, progress_stage, history, last_action, last_action_date, passed_house_date, passed_senate_date, signed_date',
				'gd_bills',
				$where ?: null
			) as $row )
			{
				$counts['processed']++;
				try
				{
					/* Build the history array for deriveProgress. Newly synced
					   rows carry the full LegiScan history JSON. Older rows
					   fall back to a one-entry synthesized history from
					   last_action — enough to fix the visible stage stall
					   (full intermediate-stage accuracy needs a re-sync). */
					$history = [];
					if ( !empty( $row['history'] ) )
					{
						$decoded = json_decode( (string) $row['history'], true );
						if ( is_array( $decoded ) ) { $history = $decoded; }
					}
					if ( empty( $history ) && !empty( $row['last_action'] ) )
					{
						$history = [ [
							'date'   => $row['last_action_date'] ?? null,
							'action' => (string) $row['last_action'],
						] ];
					}

					/* Synthesize a status_code from the existing terminal state
					   so deriveProgress can't drop us out of became_law/vetoed/
					   failed (the row arrived there by LegiScan status_code,
					   which isn't stored separately). */
					$synthCode = 0;
					$curStatus = (string) ( $row['status'] ?? '' );
					$curStage  = (string) ( $row['progress_stage'] ?? '' );
					$curType   = (string) ( $row['bill_type'] ?? '' );
					if     ( $curStatus === 'vetoed' || $curStage === 'vetoed' ) { $synthCode = 5; }
					elseif ( $curStatus === 'failed' || $curStage === 'failed' ) { $synthCode = 6; }
					elseif ( $curType === 'enacted' || $curStage === 'became_law' || $curStatus === 'enacted' ) { $synthCode = 4; }

					$derived = self::deriveProgress( $synthCode, $history, $row['signed_date'] ?? null );

					$oldRank = $ranks[ $curStage ]               ?? -1;
					$newRank = $ranks[ $derived['progress_stage'] ] ?? -1;

					if ( $newRank > $oldRank )
					{
						$update = [
							'progress_stage' => $derived['progress_stage'],
							'status'         => substr( $derived['status'], 0, 50 ),
							'bill_type'      => $derived['bill_type'],
						];
						if ( $derived['passed_senate_date'] && empty( $row['passed_senate_date'] ) ) { $update['passed_senate_date'] = $derived['passed_senate_date']; }
						if ( $derived['passed_house_date']  && empty( $row['passed_house_date'] ) )  { $update['passed_house_date']  = $derived['passed_house_date']; }
						if ( $derived['signed_date']        && empty( $row['signed_date'] ) )        { $update['signed_date']        = $derived['signed_date']; }
						\IPS\Db::i()->update( 'gd_bills', $update, [ 'id=?', (int) $row['id'] ] );
						$counts['updated']++;
					}
					else
					{
						$counts['unchanged']++;
					}
				}
				catch ( \Throwable $e )
				{
					$counts['errors']++;
					try { \IPS\Log::log( 'reparseStored row ' . ( $row['id'] ?? 0 ) . ': ' . $e->getMessage(), 'gdbills' ); } catch ( \Throwable ) {}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'reparseStored: ' . $e->getMessage(), 'gdbills' ); } catch ( \Throwable ) {}
		}

		try { \IPS\Log::log( 'reparseStored complete: ' . json_encode( $counts ), 'gdbills' ); } catch ( \Throwable ) {}
		return $counts;
	}

	protected static function keywordsFromSetting( string $key, array $default ): array
	{
		$raw = (string) ( \IPS\Settings::i()->$key ?? '' );
		if ( trim( $raw ) === '' ) { return $default; }
		$lines = preg_split( '/\r?\n/', $raw );
		$out = [];
		foreach ( $lines as $l )
		{
			$l = trim( (string) $l );
			if ( $l !== '' ) { $out[] = $l; }
		}
		return $out ?: $default;
	}

	protected static function cleanDate( $v ): ?string
	{
		if ( $v === null || $v === '' || $v === '0000-00-00' ) { return null; }
		$ts = strtotime( (string) $v );
		if ( $ts === false ) { return null; }
		return date( 'Y-m-d', $ts );
	}
}

class LegiScan extends _LegiScan {}
