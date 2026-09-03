<?php
namespace IPS\gdcatalog\Feed;

use IPS\gdcatalog\Compliance\FlagProcessor;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class UpcValidator
{
	public static function normalize( string $raw ): ?string
	{
		$cleaned = preg_replace( '/[^0-9]/', '', trim( $raw ) );

		if ( $cleaned === '' )
		{
			return null;
		}

		$len = strlen( $cleaned );

		if ( $len === 10 || $len === 11 )
		{
			$cleaned = str_pad( $cleaned, 12, '0', STR_PAD_LEFT );
			$len = 12;
		}

		if ( $len !== 8 && $len !== 12 && $len !== 13 )
		{
			return null;
		}

		return $cleaned;
	}

	public static function validateCheckDigit( string $upc ): bool
	{
		if ( strlen( $upc ) !== 12 )
		{
			return true;
		}

		$oddSum  = 0;
		$evenSum = 0;
		for ( $i = 0; $i < 11; $i++ )
		{
			$digit = (int) $upc[$i];
			if ( ( $i % 2 ) === 0 )
			{
				$oddSum += $digit;
			}
			else
			{
				$evenSum += $digit;
			}
		}

		$total      = ( $oddSum * 3 ) + $evenSum;
		$checkDigit = ( 10 - ( $total % 10 ) ) % 10;

		return $checkDigit === (int) $upc[11];
	}

	public static function isSuspicious( string $upc ): bool
	{
		if ( preg_match( '/^(\d)\1+$/', $upc ) )
		{
			return true;
		}

		if ( $upc === '012345678901' )
		{
			return true;
		}

		if ( preg_match( '/^0+$/', $upc ) )
		{
			return true;
		}

		return false;
	}

	public static function normalizeAndFlag(
		string $raw,
		?int $distributorId = null,
		?string $upcForProduct = null
	): ?string
	{
		$normalized = self::normalize( $raw );

		if ( $normalized === null )
		{
			return null;
		}

		if ( strlen( $normalized ) === 12 && !self::validateCheckDigit( $normalized ) )
		{
			try
			{
				FlagProcessor::createAdminFlag(
					$upcForProduct ?? $normalized,
					null,
					'upc_check_digit_mismatch',
					'original: ' . $raw . ' → normalized: ' . $normalized,
					0
				);
			}
			catch ( \Throwable ) {}
		}

		if ( self::isSuspicious( $normalized ) )
		{
			try
			{
				FlagProcessor::createAdminFlag(
					$upcForProduct ?? $normalized,
					null,
					'upc_suspicious',
					'original: ' . $raw . ' → normalized: ' . $normalized,
					0
				);
			}
			catch ( \Throwable ) {}
		}

		return $normalized;
	}

	/**
	 * v1.0.142: classify a UPC for the Review Queue audit column.
	 * Returns a short label suitable for gd_catalog.upc_audit_status,
	 * or null when the UPC passes both structural checks.
	 *
	 * Order matters: placeholder detection runs first because a
	 * placeholder like 000000000000 happens to also pass the check
	 * digit, but the more actionable label is "Placeholder UPC".
	 *
	 * This is advisory — the row still saves and imports normally.
	 * The Review Queue renders the label as a red badge so admins
	 * can prioritise fixing the underlying feed data.
	 */
	public static function classify( ?string $upc ): ?string
	{
		$digits = preg_replace( '/\D+/', '', (string) $upc ) ?? '';

		if ( $digits === '' )
		{
			return null;
		}

		if ( self::isPlaceholder( $digits ) )
		{
			return 'Placeholder UPC';
		}

		if ( strlen( $digits ) === 12 && !self::validateCheckDigit( $digits ) )
		{
			return 'Invalid UPC-A checksum';
		}

		if ( strlen( $digits ) === 13 && !self::validateEan13( $digits ) )
		{
			return 'Invalid EAN-13 checksum';
		}

		return null;
	}

	/**
	 * Placeholder patterns beyond what isSuspicious() catches. Used
	 * only by classify() so we don't change flag emission behaviour
	 * for existing normalizeAndFlag() callers.
	 *
	 *  - Any all-zero UPC (also caught by isSuspicious).
	 *  - 6+ trailing zeros — the "brand prefix + 000000" pattern
	 *    vendors use when they mint a fake code (e.g. 619835000000,
	 *    643478000000). Real UPCs almost never end in that many
	 *    zeros.
	 */
	public static function isPlaceholder( string $digits ): bool
	{
		if ( $digits === '' )                    { return false; }
		if ( preg_match( '/^0+$/', $digits ) )   { return true; }
		if ( preg_match( '/0{6,}$/', $digits ) ) { return true; }
		return false;
	}

	/**
	 * EAN-13 checksum: even-indexed digits (0-based, positions 0..11)
	 * are summed as-is when 0/2/4/…, weighted ×3 at 1/3/5/…
	 * We already have UPC-A in validateCheckDigit; keep them separate
	 * so existing behaviour is untouched.
	 */
	public static function validateEan13( string $digits ): bool
	{
		if ( strlen( $digits ) !== 13 )
		{
			return true;
		}

		$sum = 0;
		for ( $i = 0; $i < 12; $i++ )
		{
			$n = (int) $digits[ $i ];
			$sum += ( $i % 2 === 0 ) ? $n : $n * 3;
		}
		$expected = ( 10 - ( $sum % 10 ) ) % 10;
		return $expected === (int) $digits[ 12 ];
	}
}
