<?php
/**
 * @brief       GD Dealer Manager — Dealer slug helper
 * @package     IPS Community Suite
 * @subpackage  GD Dealer Manager
 * @since       14 May 2026
 *
 * Centralized slug generation, uniqueness checks, regeneration, and history
 * recording. Single source of truth used by both admin and dealer-side
 * "Regenerate Slug" buttons and any other code that needs to touch
 * gd_dealer_feed_config.dealer_slug or gd_dealer_slug_history.
 */

namespace IPS\gddealer\Dealer;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Slug
{
	/**
	 * Generate a slug candidate from a dealer name. Matches the algorithm
	 * used in setup/install.php:3237 and setup/upg_10001/upgrade.php:31 -
	 * lowercase, alphanumeric only, dashes between word-runs, trimmed.
	 *
	 * Returns the candidate WITHOUT uniqueness handling - callers either
	 * use this directly when uniqueness isn't required, or pair it with
	 * makeUnique() below.
	 */
	public static function fromName( string $name, int $fallbackDealerId = 0 ): string
	{
		$slug = strtolower( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $name ) ) );
		$slug = trim( $slug, '-' );

		if ( $slug === '' )
		{
			$slug = 'dealer-' . max( 1, $fallbackDealerId );
		}

		/* Hard cap at varchar(100) of gd_dealer_feed_config.dealer_slug */
		if ( strlen( $slug ) > 100 )
		{
			$slug = substr( $slug, 0, 100 );
			$slug = rtrim( $slug, '-' );
		}

		return $slug;
	}

	/**
	 * Take a base slug and return a unique variant. Appends -1, -2, ...
	 * until the slug is free, matching the install.php pattern.
	 *
	 * Uniqueness is checked against BOTH gd_dealer_feed_config (current
	 * slugs) AND gd_dealer_slug_history (retired slugs) - we never want
	 * a new slug to collide with an old one that's still 301-redirecting
	 * somewhere else.
	 *
	 * @param string $base               The base slug from fromName()
	 * @param int    $excludeDealerId    Dealer ID to ignore in the current-slug
	 *                                   check (the dealer being renamed shouldn't
	 *                                   collide with itself)
	 * @return string
	 */
	public static function makeUnique( string $base, int $excludeDealerId = 0 ): string
	{
		$slug = $base;
		$i    = 1;

		while ( self::isTaken( $slug, $excludeDealerId ) )
		{
			$slug = $base . '-' . $i++;
			if ( $i > 9999 )
			{
				/* Defensive cap - shouldn't ever happen but avoid infinite loop. */
				$slug = $base . '-' . substr( md5( (string) microtime( true ) ), 0, 6 );
				break;
			}
		}

		return $slug;
	}

	/**
	 * Is this slug taken anywhere (current or retired)?
	 *
	 * @param string $slug              The slug to check
	 * @param int    $excludeDealerId   Dealer ID whose current slug shouldn't count
	 * @return bool
	 */
	public static function isTaken( string $slug, int $excludeDealerId = 0 ): bool
	{
		try
		{
			$conditions = $excludeDealerId > 0
				? [ 'dealer_slug=? AND dealer_id<>?', $slug, $excludeDealerId ]
				: [ 'dealer_slug=?', $slug ];

			$current = (int) \IPS\Db::i()->select(
				'COUNT(*)', 'gd_dealer_feed_config', $conditions
			)->first();

			if ( $current > 0 )
			{
				return true;
			}
		}
		catch ( \Throwable ) {}

		try
		{
			$retired = (int) \IPS\Db::i()->select(
				'COUNT(*)', 'gd_dealer_slug_history', [ 'old_slug=?', $slug ]
			)->first();

			if ( $retired > 0 )
			{
				return true;
			}
		}
		catch ( \Throwable )
		{
			/* gd_dealer_slug_history may not exist on pre-v182 upgrade paths,
			 * be defensive. */
		}

		return false;
	}

	/**
	 * Record an old slug in history. Idempotent - if old_slug already exists
	 * (e.g. multiple regenerate clicks), update the retired_at + reason.
	 *
	 * @param string $oldSlug       The slug being retired
	 * @param int    $dealerId      Which dealer it belonged to
	 * @param string $reason        Why it was retired
	 * @return bool                 true on success
	 */
	public static function recordHistory( string $oldSlug, int $dealerId, string $reason = 'manual_regenerate' ): bool
	{
		if ( $oldSlug === '' || $dealerId <= 0 )
		{
			return false;
		}

		$now = date( 'Y-m-d H:i:s' );

		try
		{
			$existing = \IPS\Db::i()->select(
				'id', 'gd_dealer_slug_history', [ 'old_slug=?', $oldSlug ]
			)->first();

			\IPS\Db::i()->update( 'gd_dealer_slug_history', [
				'dealer_id'      => $dealerId,
				'retired_at'     => $now,
				'retired_reason' => $reason,
			], [ 'id=?', (int) $existing ]);

			return true;
		}
		catch ( \UnderflowException )
		{
			try
			{
				\IPS\Db::i()->insert( 'gd_dealer_slug_history', [
					'old_slug'       => $oldSlug,
					'dealer_id'      => $dealerId,
					'retired_at'     => $now,
					'retired_reason' => $reason,
				]);
				return true;
			}
			catch ( \Throwable )
			{
				return false;
			}
		}
		catch ( \Throwable )
		{
			return false;
		}
	}

	/**
	 * Look up which dealer a retired slug belonged to. Used by the front-end
	 * profile route handler to 301-redirect old URLs to the current slug.
	 *
	 * @param string $oldSlug   The incoming (possibly retired) slug
	 * @return int|null         dealer_id if the slug is in history, null otherwise
	 */
	public static function dealerIdForRetiredSlug( string $oldSlug ): ?int
	{
		if ( $oldSlug === '' )
		{
			return null;
		}

		try
		{
			$dealerId = (int) \IPS\Db::i()->select(
				'dealer_id', 'gd_dealer_slug_history', [ 'old_slug=?', $oldSlug ]
			)->first();

			return $dealerId > 0 ? $dealerId : null;
		}
		catch ( \Throwable )
		{
			return null;
		}
	}

	/**
	 * Regenerate a dealer's slug from their current dealer_name. The dealer's
	 * old slug is recorded in history before being replaced. Atomic.
	 *
	 * @param int    $dealerId    The dealer to regenerate
	 * @param string $reason      Why we're regenerating
	 * @return array{success: bool, old_slug: string, new_slug: string, message: string}
	 */
	public static function regenerate( int $dealerId, string $reason = 'manual_regenerate' ): array
	{
		$result = [
			'success'  => false,
			'old_slug' => '',
			'new_slug' => '',
			'message'  => '',
		];

		try
		{
			$row = \IPS\Db::i()->select(
				'dealer_id, dealer_name, dealer_slug',
				'gd_dealer_feed_config',
				[ 'dealer_id=?', $dealerId ]
			)->first();
		}
		catch ( \Throwable )
		{
			$result['message'] = 'Dealer not found.';
			return $result;
		}

		$oldSlug    = (string) ( $row['dealer_slug'] ?? '' );
		$dealerName = (string) ( $row['dealer_name'] ?? '' );

		$result['old_slug'] = $oldSlug;

		$candidate = self::fromName( $dealerName, $dealerId );

		if ( $candidate === $oldSlug )
		{
			$result['new_slug'] = $oldSlug;
			$result['message']  = 'unchanged';
			return $result;
		}

		$newSlug = self::makeUnique( $candidate, $dealerId );

		$result['new_slug'] = $newSlug;

		try
		{
			/* Record old slug in history FIRST so it's there to 301-redirect
			 * even if the update partially succeeds. */
			if ( $oldSlug !== '' )
			{
				self::recordHistory( $oldSlug, $dealerId, $reason );
			}

			\IPS\Db::i()->update( 'gd_dealer_feed_config',
				[ 'dealer_slug' => $newSlug ],
				[ 'dealer_id=?', $dealerId ]
			);

			$result['success'] = true;
			$result['message'] = 'ok';
		}
		catch ( \Throwable $e )
		{
			$result['message'] = 'update_failed: ' . $e->getMessage();
		}

		return $result;
	}

	/**
	 * Preview what a regenerate would produce, without actually changing
	 * anything. Used by the confirmation modal to show "current → new" before
	 * the user clicks Confirm.
	 *
	 * @param int $dealerId
	 * @return array{old_slug: string, new_slug: string, would_change: bool}
	 */
	public static function previewRegenerate( int $dealerId ): array
	{
		try
		{
			$row = \IPS\Db::i()->select(
				'dealer_id, dealer_name, dealer_slug',
				'gd_dealer_feed_config',
				[ 'dealer_id=?', $dealerId ]
			)->first();
		}
		catch ( \Throwable )
		{
			return [ 'old_slug' => '', 'new_slug' => '', 'would_change' => false ];
		}

		$oldSlug    = (string) ( $row['dealer_slug'] ?? '' );
		$dealerName = (string) ( $row['dealer_name'] ?? '' );

		$candidate = self::fromName( $dealerName, $dealerId );

		if ( $candidate === $oldSlug )
		{
			return [
				'old_slug'     => $oldSlug,
				'new_slug'     => $oldSlug,
				'would_change' => false,
			];
		}

		$newSlug = self::makeUnique( $candidate, $dealerId );

		return [
			'old_slug'     => $oldSlug,
			'new_slug'     => $newSlug,
			'would_change' => true,
		];
	}
}

class Slug extends _Slug {}
