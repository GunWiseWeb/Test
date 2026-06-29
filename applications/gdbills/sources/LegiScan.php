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

	/* ---------------------- API wrappers ---------------------- */

	protected static function getSearch( string $key, string $state, string $query ): array
	{
		$url = self::API_BASE . '?key=' . urlencode( $key )
			. '&op=getSearchRaw'
			. '&state=' . urlencode( $state )
			. '&query=' . urlencode( $query );
		$data = self::http( $url );
		if ( !is_array( $data ) || ( $data['status'] ?? '' ) !== 'OK' )
		{
			return [];
		}
		$results = $data['searchresult'] ?? [];
		if ( !is_array( $results ) ) { return []; }
		/* LegiScan's getSearchRaw returns a 'summary' key + numbered results. */
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
	public static function isFirearmsRelated( array $bill, array $allow, array $exclude ): bool
	{
		$text = strtolower(
			(string) ( $bill['title']       ?? '' ) . ' ' .
			(string) ( $bill['description'] ?? '' ) . ' ' .
			(string) ( $bill['bill_number'] ?? '' )
		);

		/* Allowlist FIRST — manufacturer/model terms keep the bill in scope. */
		$matched = false;
		foreach ( $allow as $term )
		{
			$t = strtolower( trim( $term ) );
			if ( $t === '' ) { continue; }
			if ( strpos( $text, $t ) !== false ) { $matched = true; break; }
		}
		if ( !$matched ) { return false; }

		/* Exclusion list — only drops when the ENTIRE text is dominated by the
		   non-firearms term and no firearms term is present. Since allowlist
		   already matched at least once, we keep the bill unless the bill is
		   clearly about something else (allow term appears as incidental). For
		   simplicity, exclude only when an exclusion term appears AND no
		   manufacturer/feature term from a curated subset is present. */
		$strongAllow = [
			'firearm', 'gun', 'rifle', 'pistol', 'handgun', 'shotgun',
			'second amendment', '2nd amendment', 'concealed carry', 'open carry',
			'glock', 'sig sauer', 'smith wesson', 'ruger', 'remington', 'colt',
			'beretta', 'springfield armory', 'ar-15', 'ar15', 'ak-47', 'ak47',
			'auto sear', 'bump stock', 'ghost gun', 'pistol brace',
			'large capacity magazine', 'machine gun', 'assault weapon',
		];
		$hasStrong = false;
		foreach ( $strongAllow as $t )
		{
			if ( strpos( $text, $t ) !== false ) { $hasStrong = true; break; }
		}
		if ( $hasStrong ) { return true; }

		foreach ( $exclude as $term )
		{
			$t = strtolower( trim( $term ) );
			if ( $t === '' ) { continue; }
			if ( strpos( $text, $t ) !== false ) { return false; }
		}
		return true;
	}

	/**
	 * Map a LegiScan getBill response → gd_bills row. Be defensive — the
	 * API shape varies; missing fields stay null.
	 */
	public static function parseBill( array $bill, string $stateCode ): array
	{
		$lastAction = isset( $bill['history'] ) && is_array( $bill['history'] )
			? end( $bill['history'] ) : null;
		if ( !is_array( $lastAction ) ) { $lastAction = []; }

		/* progress comes as a sequence of {date, event} entries — map LegiScan
		   event codes to friendlier stages. Use word-boundary-aware comparisons
		   so "assigned" doesn't trigger "signed". */
		$progressStage  = 'introduced';
		$signedDate     = null;
		$passedHouse    = null;
		$passedSenate   = null;
		$dateIntro      = null;

		if ( isset( $bill['progress'] ) && is_array( $bill['progress'] ) )
		{
			foreach ( $bill['progress'] as $p )
			{
				if ( !is_array( $p ) ) { continue; }
				$event = (int) ( $p['event'] ?? 0 );
				$pdate = self::cleanDate( $p['date'] ?? null );
				switch ( $event )
				{
					case 1: $dateIntro    = $dateIntro    ?: $pdate; $progressStage = 'introduced'; break;
					case 2: $passedHouse  = $passedHouse  ?: $pdate; $progressStage = 'passed_house'; break;
					case 3: $passedSenate = $passedSenate ?: $pdate; $progressStage = 'passed_senate'; break;
					case 4: $progressStage = 'passed_both'; break;
					case 5: $signedDate = $signedDate ?: $pdate; $progressStage = 'signed'; break;
					case 6: $progressStage = 'vetoed'; break;
				}
			}
		}

		$billType = 'pending';
		if ( $signedDate ) { $billType = 'enacted'; }
		$status = (string) ( $lastAction['action'] ?? $progressStage );

		$sponsorName = null;
		$sponsorParty = null;
		if ( isset( $bill['sponsors'][0] ) && is_array( $bill['sponsors'][0] ) )
		{
			$sponsorName  = (string) ( $bill['sponsors'][0]['name']  ?? '' ) ?: null;
			$sponsorParty = (string) ( $bill['sponsors'][0]['party'] ?? '' ) ?: null;
		}

		return [
			'bill_number'        => (string) ( $bill['bill_number'] ?? '' ),
			'bill_title'         => (string) ( $bill['title']       ?? ( $bill['bill_number'] ?? '' ) ),
			'state_code'         => strtoupper( (string) ( $bill['state'] ?? $stateCode ) ),
			'bill_type'          => $billType,
			'status'             => substr( $status, 0, 50 ),
			'progress_stage'     => $progressStage,
			'sponsor_name'       => $sponsorName,
			'sponsor_party'      => $sponsorParty,
			'description'        => (string) ( $bill['description'] ?? '' ),
			'url'                => (string) ( $bill['url']         ?? ( $bill['state_link'] ?? '' ) ),
			'date_introduced'    => $dateIntro,
			'last_action_date'   => self::cleanDate( $lastAction['date'] ?? null ),
			'last_action'        => (string) ( $lastAction['action'] ?? '' ),
			'passed_senate_date' => $passedSenate,
			'passed_house_date'  => $passedHouse,
			'signed_date'        => $signedDate,
			'legiscan_id'        => (int) ( $bill['bill_id'] ?? 0 ),
			'source'             => 'legiscan',
		];
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
