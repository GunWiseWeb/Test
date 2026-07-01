<?php
/**
 * @brief  GD Compliance — PicaModels backward-compat shim (v1.6.0+)
 *
 * The IL-only PICA matcher was generalized into AwbModels in v1.6.0.
 * This file remains as a thin subclass so any external caller of
 * PicaModels::match($product) — or of the CITATION_LISTED /
 * CITATION_FEATURE constants — still works.
 *
 * PicaModels::match() pre-scopes AwbModels::match() to state_code='IL'
 * and returns a compatible shape (tier / pattern / citation /
 * feature_hits), so existing consumers keep behaving the way they did
 * in v1.5.x.
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
	const CITATION_LISTED  = '720 ILCS 5/24-1.9(a)(1)(J)';
	const CITATION_FEATURE = '720 ILCS 5/24-1.9(a)(1)(A)';

	public static function normalize( string $s ): string
	{
		return \IPS\gdcompliance\AwbModels::normalize( $s );
	}

	/**
	 * Legacy IL-only matcher. Delegates to AwbModels::match($product,'IL')
	 * and normalizes the return shape:
	 *   - tier-3 (review) is folded into tier-2 for backward compatibility
	 *     with v1.5.x callers that only knew about 1/2
	 *   - null (no rule for IL) is folded into a tier-2 result with the
	 *     legacy feature-test citation.
	 *
	 * @return array{tier:int,pattern:?string,citation:string,feature_hits:array<int,string>}
	 */
	public static function match( array $product ): array
	{
		try
		{
			$r = \IPS\gdcompliance\AwbModels::match( $product, 'IL' );
		}
		catch ( \Throwable ) { $r = null; }

		if ( $r === null )
		{
			return [
				'tier'         => 2,
				'pattern'      => null,
				'citation'     => self::CITATION_FEATURE,
				'feature_hits' => [],
			];
		}

		/* Downgrade tier-3 (low-confidence review) to tier-2 for v1.5.x
		   consumers; they treat any non-tier-1 as "likely, verify". */
		if ( (int) $r['tier'] === 3 ) { $r['tier'] = 2; }
		return $r;
	}

	/**
	 * Legacy seeder — delegates to AwbModels which handles all states.
	 * @return array{inserted:int, skipped:int, failed:int}
	 */
	public static function seedMissingModels(): array
	{
		try
		{
			return \IPS\gdcompliance\AwbModels::seedMissingModels();
		}
		catch ( \Throwable )
		{
			return [ 'inserted' => 0, 'skipped' => 0, 'failed' => 0 ];
		}
	}

	/**
	 * Legacy statutory seed. Returns the IL-only subset so any tooling
	 * that specifically expected PICA rows keeps working.
	 * @return array<int, array<string, mixed>>
	 */
	public static function statutorySeed(): array
	{
		$out = [];
		try
		{
			foreach ( \IPS\gdcompliance\AwbModels::statutorySeed() as $r )
			{
				if ( strtoupper( (string) ( $r['state_code'] ?? '' ) ) === 'IL' )
				{
					$out[] = $r;
				}
			}
		}
		catch ( \Throwable ) {}
		return $out;
	}
}

class PicaModels extends _PicaModels {}
