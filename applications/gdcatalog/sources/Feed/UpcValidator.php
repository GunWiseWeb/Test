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
}
