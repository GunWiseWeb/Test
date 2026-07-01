<?php
/**
 * @brief  GD Compliance — Illinois PICA (720 ILCS 5/24-1.9) model list + matcher
 *
 * Holds the canonical statutory named-model list AND the fast per-product
 * matcher. The matcher takes a catalog row and returns:
 *
 *   ['tier' => 1, 'pattern' => 'M&P15', 'citation' => '720 ILCS 5/24-1.9(a)(1)(J)', 'feature_hits' => []]
 *     when a NAMED MODEL matched (high confidence)
 *
 *   ['tier' => 2, 'pattern' => null, 'citation' => '720 ILCS 5/24-1.9(a)(1)', 'feature_hits' => ['folding/telescoping stock']]
 *     when NO named model matched (Tier 2 — likely PICA, verify)
 *
 * Engine::computeFlags gates this behind: type='rifle' AND action_type
 * contains 'semi' (bolt/lever/pump/break/single-shot/muzzleloader excluded
 * per statutory (a)(2)). PicaModels never runs on non-rifles.
 *
 * The named-model list is stored in gd_compliance_pica_models (editable
 * by Derrick via ACP). This class SEEDS the initial statutory set
 * NON-DESTRUCTIVELY — existing rows keyed on pattern_norm are preserved
 * so admin edits survive re-seeds.
 */

namespace IPS\gdcompliance;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _PicaModels
{
	const CITATION_LISTED = '720 ILCS 5/24-1.9(a)(1)(J)';
	const CITATION_FEATURE = '720 ILCS 5/24-1.9(a)(1)(A)';

	/** @var array<int, array{pattern:string,pattern_norm:string,platform_group:string,citation:string}>|null */
	protected static ?array $cache = null;

	/**
	 * The statutory NAMED-MODEL LIST for PICA (a)(1)(J), rifles.
	 * Seeded once per install; then owned/edited by Derrick via ACP.
	 *
	 * We deliberately DO NOT seed bare ambiguous tokens like "AK" or
	 * "M4" (would false-match). Every entry here is >= 3 alphanumeric
	 * characters when normalized.
	 *
	 * @return array<int, array{pattern:string,platform_group:string,citation:string,enabled:int}>
	 */
	public static function statutorySeed(): array
	{
		$cite = self::CITATION_LISTED;
		$rows = [];
		$add = function ( string $pat, string $group ) use ( &$rows, $cite ) {
			$rows[] = [
				'pattern'        => $pat,
				'platform_group' => $group,
				'citation'       => $cite,
				'enabled'        => 1,
			];
		};

		/* ── AK-pattern rifles ─────────────────────────────────────── */
		foreach ( [
			'AK47', 'AK-47', 'AK47S', 'AK-74', 'AKM', 'AKS',
			'MAK90', 'MISR', 'NHM90', 'NHM91',
			'SA85', 'SA93', 'VEPR', 'WASR-10', 'WASR10', 'WUM',
			'Saiga AK', 'MAADI',
			'Norinco 56S', 'Norinco 84S', 'Norinco 86S',
			'Poly Tech AK',
		] as $p ) { $add( $p, 'AK' ); }

		/* ── SKS with detachable magazine ──────────────────────────── */
		$add( 'SKS Detachable', 'SKS' );

		/* ── AR-15 pattern rifles + named copies ───────────────────── */
		foreach ( [
			'AR-10', 'AR10', 'AR-15', 'AR15',
			'Alexander Arms Overmatch',
			'Armalite M15',
			'Barrett REC7',
			'Beretta AR-70', 'Beretta AR70',
			'Black Rain Recon Scout',
			'Bushmaster ACR', 'Bushmaster Carbon 15', 'Bushmaster MOE', 'Bushmaster XM15',
			'Chiappa MFour',
			'Colt Match Target',
			'CORE15', 'CORE 15',
			'Daniel Defense M4A1',
			'Devil Dog 15',
			'Diamondback DB15',
			'DoubleStar AR',
			'DPMS Tactical',
			'DSA ZM-4', 'DSA ZM4',
			'HK MR556', 'HK-MR556',
			'High Standard HSA-15', 'HSA-15',
			'Jesse James Nomad',
			"Knight's SR-15", 'SR-15', 'SR15',
			'Lancer L15',
			'MGI Hydra',
			'Mossberg MMR Tactical',
			'Noreen BN36',
			'Olympic Arms',
			'POF P415',
			'Precision Firearms AR',
			'Remington R-15', 'Remington R15',
			'Rhino Arms AR',
			'Rock River LAR-15', 'Rock River LAR-47', 'LAR-15', 'LAR-47',
			'SIG SIG516', 'SIG 516', 'SIG516',
			'SIG MCX', 'MCX',
			'Smith & Wesson M&P15', 'M&P15', 'MP15',
			'Stag Arms AR',
			'Ruger SR556', 'SR556', 'SR-556',
			'Ruger AR-556', 'AR-556', 'AR556',
			'Uselton Air-Lite M-4',
			'Windham Weaponry AR',
			'WMD Big Beast',
			'YHM-15', 'YHM15',
		] as $p ) { $add( $p, 'AR-15' ); }

		/* ── .50 BMG centerfire rifles ─────────────────────────────── */
		foreach ( [
			'Barrett M107A1', 'M107A1',
			'Barrett M82A1', 'M82A1',
		] as $p ) { $add( $p, '.50 BMG' ); }

		/* ── Other named rifles (statute (a)(1)(J)) ────────────────── */
		foreach ( [
			'Beretta CX4 Storm', 'CX4 Storm',
			'Calico Liberty',
			'CETME Sporter',
			'Daewoo K1', 'Daewoo K2', 'Daewoo Max', 'Daewoo AR100', 'Daewoo AR110C',
			'FN FAL', 'FN LAR', 'FN FNC', 'FN L1A1', 'FN PS90', 'FN SCAR', 'FN FS2000',
			'SCAR', 'PS90', 'FS2000',
			'Feather AT-9',
			'Galil AR', 'Galil ARM',
			'Hi-Point Carbine',
			'HK-91', 'HK 91', 'HK-93', 'HK 93', 'HK-94', 'HK 94', 'HK-PSG-1', 'PSG-1',
			'HK USC',
			'IWI Tavor', 'Tavor',
			'IWI Galil ACE', 'Galil ACE',
			'Kel-Tec Sub-2000', 'Sub-2000', 'Kel-Tec SU-16', 'SU-16', 'Kel-Tec RFB', 'RFB',
			'SIG AMT', 'SIG PE-57', 'SIG SG550', 'SG550', 'SIG SG551', 'SG551',
			'Springfield SAR-48', 'SAR-48',
			'Steyr AUG',
			'Ruger Mini-14 Tactical', 'Mini-14 Tactical', 'Mini-14/20CF', 'M-14/20CF',
			'Thompson',
			'UMAREX UZI', 'UZI Carbine',
			'Valmet M62S', 'Valmet M71S', 'Valmet M78',
			'Vector UZI',
			'Weaver Nighthawk',
			'Wilkinson Linda',
		] as $p ) { $add( $p, 'Other Named' ); }

		return $rows;
	}

	/**
	 * Aggressive normalizer: lowercase + strip every non-alphanumeric.
	 * Both patterns and match-text are run through this so the surface
	 * "M&P15", "M&P-15", "M P 15", and "MP15" all collide to "mp15".
	 */
	public static function normalize( string $s ): string
	{
		$s = strtolower( $s );
		return (string) preg_replace( '/[^a-z0-9]/', '', $s );
	}

	/**
	 * Seed any MISSING patterns — idempotent, non-destructive per row.
	 * Preserves any admin edits / disable-toggles on existing rows.
	 *
	 * @return array{inserted:int, skipped:int, failed:int}
	 */
	public static function seedMissingModels(): array
	{
		$counts = [ 'inserted' => 0, 'skipped' => 0, 'failed' => 0 ];
		$now    = time();

		foreach ( self::statutorySeed() as $rule )
		{
			$pattern = trim( (string) ( $rule['pattern'] ?? '' ) );
			if ( $pattern === '' ) { $counts['failed']++; continue; }

			$norm = self::normalize( $pattern );
			if ( $norm === '' || strlen( $norm ) < 3 ) { $counts['failed']++; continue; }

			try
			{
				$exists = (int) \IPS\Db::i()->select(
					'COUNT(*)',
					'gd_compliance_pica_models',
					[ 'pattern_norm=?', $norm ]
				)->first();

				if ( $exists > 0 ) { $counts['skipped']++; continue; }

				\IPS\Db::i()->insert( 'gd_compliance_pica_models', [
					'pattern'        => substr( $pattern, 0, 120 ),
					'pattern_norm'   => substr( $norm, 0, 120 ),
					'platform_group' => substr( (string) ( $rule['platform_group'] ?? '' ), 0, 40 ),
					'citation'       => substr( (string) ( $rule['citation'] ?? '' ), 0, 255 ),
					'enabled'        => (int) ( $rule['enabled'] ?? 1 ),
					'updated_at'     => $now,
				] );
				$counts['inserted']++;
			}
			catch ( \Throwable $e )
			{
				$counts['failed']++;
				try { \IPS\Log::log( 'PicaModels::seed ' . $pattern . ': ' . $e->getMessage(), 'gdcompliance_seed' ); } catch ( \Throwable ) {}
			}
		}

		try { self::$cache = null; } catch ( \Throwable ) {}
		return $counts;
	}

	/**
	 * Cache the enabled pattern list per-request.
	 * @return array<int, array{pattern:string,pattern_norm:string,platform_group:string,citation:string}>
	 */
	protected static function loadPatterns(): array
	{
		if ( static::$cache !== null ) { return static::$cache; }

		$out = [];
		try
		{
			foreach ( \IPS\Db::i()->select(
				'pattern, pattern_norm, platform_group, citation',
				'gd_compliance_pica_models',
				[ 'enabled=1' ]
			) as $row )
			{
				$norm = (string) ( $row['pattern_norm'] ?? '' );
				if ( strlen( $norm ) < 3 ) { continue; }
				$out[] = [
					'pattern'        => (string) ( $row['pattern'] ?? '' ),
					'pattern_norm'   => $norm,
					'platform_group' => (string) ( $row['platform_group'] ?? '' ),
					'citation'       => (string) ( $row['citation'] ?? '' ),
				];
			}
		}
		catch ( \Throwable ) { $out = []; }

		return static::$cache = $out;
	}

	/**
	 * Run the two-tier detector against a catalog row. Only call this
	 * on rifles whose action_type has already been verified as
	 * semi-automatic — this method does NOT re-check that.
	 *
	 * @param array<string, mixed> $product  gd_catalog row
	 * @return array{tier:int,pattern:?string,citation:string,feature_hits:array<int,string>}
	 */
	public static function match( array $product ): array
	{
		/* Merge every text field the model list could hit against, then
		   normalize once. Description gets a hard length cap to bound
		   the substring scan on long product blurbs. */
		$parts = [
			(string) ( $product['title']        ?? '' ),
			(string) ( $product['brand']        ?? '' ),
			(string) ( $product['manufacturer'] ?? '' ),
			(string) ( $product['model']        ?? '' ),
			substr( (string) ( $product['description'] ?? '' ), 0, 2000 ),
		];
		$text = self::normalize( implode( ' ', $parts ) );

		if ( $text !== '' )
		{
			foreach ( self::loadPatterns() as $p )
			{
				if ( strpos( $text, $p['pattern_norm'] ) !== false )
				{
					return [
						'tier'         => 1,
						'pattern'      => $p['pattern'],
						'citation'     => $p['citation'] !== '' ? $p['citation'] : self::CITATION_LISTED,
						'feature_hits' => [],
					];
				}
			}
		}

		/* Tier 2 — no named match. Detect the ONE structured feature
		   the catalog exposes (folding stock via stock_material). Other
		   PICA features (pistol grip, flash suppressor, barrel shroud,
		   protruding grip, grenade launcher) aren't structured — Derrick
		   verifies via the review queue. */
		$features = [];
		$stock    = strtolower( trim( (string) ( $product['stock_material'] ?? '' ) ) );
		$stockTy  = strtolower( trim( (string) ( $product['stock_type']     ?? '' ) ) );
		if ( $stock === 'folding' || strpos( $stock, 'fold' ) !== false || strpos( $stockTy, 'fold' ) !== false || strpos( $stockTy, 'telescop' ) !== false || strpos( $stockTy, 'collaps' ) !== false )
		{
			$features[] = 'folding/telescoping stock';
		}

		return [
			'tier'         => 2,
			'pattern'      => null,
			'citation'     => self::CITATION_FEATURE,
			'feature_hits' => $features,
		];
	}
}

class PicaModels extends _PicaModels {}
