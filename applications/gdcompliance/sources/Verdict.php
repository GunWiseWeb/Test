<?php
/**
 * @brief  GD Compliance — Verdict helper (single source of truth)
 *
 * Structured verdict for one UPC + state. Used by:
 *   - the machine-to-machine API endpoint (modules/front/api/api.php)
 *   - future adapters (widget snippets etc.)
 *
 * The query flow matches the public /state-lookup/ (Stage 1) EXACTLY:
 *   1) gd_catalog WHERE upc=?       — resolve product
 *   2) gd_compliance_flags WHERE upc=? AND state_code=?  — get flags
 *   3) classify: any restrict-type → restricted; else advisory → advisory;
 *      else available. Not-in-catalog → unknown.
 *
 * IPS-native select()->join(); no raw preparedQuery (rule #34/#36).
 * All failures return safe defaults — this is called from a public API
 * where an exception must not leak.
 */

namespace IPS\gdcompliance;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Verdict
{
	/**
	 * Build a verdict array for one UPC + state.
	 *
	 * Returned shape (a superset of the JSON API shape — the API drops
	 * or renames fields for its wire format):
	 *
	 * [
	 *   'upc'          => (string) the queried UPC,
	 *   'state'        => (string) the 2-char state code,
	 *   'status'       => 'restricted'|'advisory'|'available'|'unknown',
	 *   'product'      => (?string) "Brand — Title" or null on unknown,
	 *   'restrictions' => [ [ 'type'=>slug, 'reason'=>..., 'citation'=>... ], ... ],
	 *   'advisories'   => [ [ 'reason'=>..., 'citation'=>... ], ... ],
	 * ]
	 *
	 * $upc is trusted only in the SELECT (parameterized). $stateCode is
	 * expected to already be uppercased and whitelist-validated by the
	 * caller — the API controller does that before invoking us.
	 */
	public static function for( string $upc, string $stateCode ): array
	{
		$upc       = trim( $upc );
		$stateCode = strtoupper( trim( $stateCode ) );

		$base = [
			'upc'          => $upc,
			'state'        => $stateCode,
			'status'       => 'unknown',
			'product'      => null,
			'restrictions' => [],
			'advisories'   => [],
		];

		if ( $upc === '' || $stateCode === '' ) { return $base; }

		/* 1) Resolve product. UPC-only match — MPN is out of scope for
		   API Stage 1 to keep the auth-metered call inexpensive.
		   Best-effort — DB failure = "unknown" (not an API error). */
		$product = null;
		try
		{
			$product = \IPS\Db::i()->select(
				'upc, title, brand, manufacturer',
				'gd_catalog',
				[ 'upc=?', $upc ]
			)->first();
		}
		catch ( \Throwable ) { $product = null; }

		if ( !is_array( $product ) )
		{
			return $base; /* status=unknown */
		}

		$brand = trim( (string) ( $product['brand'] ?? '' ) );
		if ( $brand === '' ) { $brand = trim( (string) ( $product['manufacturer'] ?? '' ) ); }
		$title = trim( (string) ( $product['title'] ?? '' ) );
		$productName = trim( ( $brand !== '' ? $brand . ' — ' : '' ) . $title );
		if ( $productName === '' ) { $productName = null; }

		/* 2) Flags for this UPC + state. */
		$flagRows = [];
		try
		{
			foreach ( \IPS\Db::i()->select(
				'firearm_type, reason, citation',
				'gd_compliance_flags',
				[ 'upc=? AND state_code=?', $upc, $stateCode ],
				'firearm_type ASC'
			) as $r )
			{
				$flagRows[] = $r;
			}
		}
		catch ( \Throwable ) {}

		/* 3) Classify. Advisory is a separate bucket (buyer requirement,
		   still available). Restrict-type flags flip status to
		   'restricted' regardless of any advisories present. */
		$restrictions = [];
		$advisories   = [];
		foreach ( $flagRows as $f )
		{
			$ftype  = (string) ( $f['firearm_type'] ?? '' );
			$reason = (string) ( $f['reason']       ?? '' );
			$cite   = (string) ( $f['citation']     ?? '' );

			if ( $ftype === 'advisory' )
			{
				$advisories[] = [
					'reason'   => $reason,
					'citation' => $cite,
				];
			}
			else
			{
				$restrictions[] = [
					'type'         => self::typeSlug( $ftype ),
					'firearm_type' => $ftype,
					'reason'       => $reason,
					'citation'     => $cite,
				];
			}
		}

		$status = !empty( $restrictions ) ? 'restricted'
			: ( !empty( $advisories ) ? 'advisory' : 'available' );

		return [
			'upc'          => $upc,
			'state'        => $stateCode,
			'status'       => $status,
			'product'      => $productName,
			'restrictions' => $restrictions,
			'advisories'   => $advisories,
		];
	}

	/**
	 * Human-facing type slug for the API payload. Groups all AWB
	 * variants under 'awb', magazine-capacity flags under 'capacity',
	 * etc. Keep this list in sync with lookup.php::flagTypeLabel().
	 */
	public static function typeSlug( string $ftype ): string
	{
		return match( true ) {
			strncmp( $ftype, 'awb_', 4 ) === 0  => 'awb',
			strncmp( $ftype, 'pica_', 5 ) === 0 => 'awb',
			$ftype === 'awb_lower'              => 'awb',
			$ftype === 'magazine'               => 'capacity',
			$ftype === 'melting_point'          => 'melting_point',
			$ftype === 'rate_of_fire'           => 'rate_of_fire',
			$ftype === 'manual'                 => 'override',
			$ftype === 'advisory'               => 'advisory',
			in_array( $ftype, [ 'handgun', 'rifle', 'shotgun' ], true ) => 'capacity',
			default                             => 'restriction',
		};
	}
}

class Verdict extends _Verdict {}
