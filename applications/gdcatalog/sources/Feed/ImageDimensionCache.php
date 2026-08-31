<?php
/**
 * @brief       GD Master Catalog — Image Dimension Cache
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 * @since       27 Aug 2026
 *
 * Phase 11 of the source-adapter refactor plan.
 *
 * Persistent per-URL image-dimension cache backing the async
 * highest_res conflict rule. The import path performs ONLY local
 * cache lookups (no HTTP, no FTP) — dimension fetching happens
 * asynchronously in the FetchImageDimensions queue extension.
 *
 * The cache understands two shortcuts before consulting the DB:
 *
 *   1. URL-hint parse: URLs that already contain their dimensions
 *      (e.g. "/img/foo_800x600.jpg") return their pixel count
 *      instantly without any DB read. This preserves the fast path
 *      the pre-Phase-11 synchronous getImageResolution used.
 *   2. Cache hit (status='ready' + fresh): DB row provides
 *      width * height immediately.
 *
 * When neither shortcut applies, pixelsFor() returns null (meaning
 * "unknown — the caller must defer") and enqueue() queues a
 * background worker to fetch the dimensions. On the next import
 * batch (or from a manual re-eval) the cache will have populated
 * data and the deferred conflict can be resolved.
 *
 * Rule #1 dual-class wrapper, guard header. Rule #7 ActiveRecord
 * property shape (only if extended to ActiveRecord — here we use
 * static helpers rather than a per-row model).
 */

namespace IPS\gdcatalog\Feed;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _ImageDimensionCache
{
	public const STATUS_PENDING = 'pending';
	public const STATUS_READY   = 'ready';
	public const STATUS_FAILED  = 'failed';

	/**
	 * A ready dimension row younger than this is considered fresh.
	 * The URL's remote resource can theoretically change; we
	 * conservatively assume it does not more often than monthly.
	 */
	public const FRESHNESS_DAYS = 30;

	/**
	 * A failed dimension row younger than this is NOT retried. Keeps
	 * a bad URL from being re-fetched on every import for a week.
	 */
	public const FAILED_RETRY_DAYS = 7;

	/**
	 * A pending dimension row younger than this is not re-queued —
	 * an already-in-flight worker is presumed sufficient.
	 */
	public const PENDING_STALE_SECONDS = 3600;

	/**
	 * Only these URL schemes are honoured for remote dimension
	 * fetching. Everything else is rejected up-front (rule #3 SSRF
	 * hygiene — even though the input is a distributor-provided URL,
	 * we still guard the scheme).
	 */
	protected const ALLOWED_SCHEMES = [ 'http', 'https' ];

	/**
	 * SHA-256 hex of the URL. 64-char primary key on
	 * gd_image_dimensions; keeps the index compact and avoids
	 * VARCHAR(2000) key length limits.
	 */
	public static function hashOf( string $url ): string
	{
		return hash( 'sha256', $url );
	}

	/**
	 * Parse dimensions from an in-URL hint like "_800x600.jpg" or
	 * "-1024x768.png". Returns 0 when no hint is present. Used for
	 * the instant-shortcut path in pixelsFor().
	 */
	public static function pixelsFromHint( string $url ): int
	{
		if ( preg_match( '/[_\-x](\d{2,5})x(\d{2,5})\./i', $url, $m ) )
		{
			return (int) $m[1] * (int) $m[2];
		}
		return 0;
	}

	/**
	 * True when a URL is a shape we are willing to remote-fetch.
	 * Rejects empty, non-http/https, and blatantly private-host
	 * URLs. Not exhaustive SSRF protection — but a reasonable guard
	 * given the input comes from configured catalog sources.
	 */
	public static function isValidHttpUrl( string $url ): bool
	{
		if ( $url === '' ) { return false; }
		$parts = @parse_url( $url );
		if ( !is_array( $parts ) ) { return false; }
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( !in_array( $scheme, self::ALLOWED_SCHEMES, true ) ) { return false; }
		$host = (string) ( $parts['host'] ?? '' );
		if ( $host === '' ) { return false; }
		/* Reject localhost + RFC 1918 private ranges + IPv6 link-local
		 * — quick guard against SSRF via a distributor URL that
		 * accidentally points at internal infrastructure. */
		if ( preg_match( '/^(localhost|127\.|10\.|192\.168\.|169\.254\.|::1|fe80:|fc00:|fd00:)/i', $host ) )
		{
			return false;
		}
		if ( preg_match( '/^172\.(1[6-9]|2\d|3[01])\./i', $host ) ) { return false; }
		return true;
	}

	/**
	 * Read the cache row for a URL. Returns null when the URL has
	 * no cache entry yet. Never performs HTTP.
	 *
	 * @return array{width:?int, height:?int, status:string, checked_at:?int}|null
	 */
	public static function lookup( string $url ): ?array
	{
		$hash = self::hashOf( $url );
		try
		{
			$row = \IPS\Db::i()->select( '*', 'gd_image_dimensions', [ 'url_hash=?', $hash ] )->first();
			return [
				'width'      => isset( $row['width']  ) ? (int) $row['width']  : null,
				'height'     => isset( $row['height'] ) ? (int) $row['height'] : null,
				'status'     => (string) ( $row['status'] ?? '' ),
				'checked_at' => (int)    ( $row['checked_at'] ?? 0 ),
			];
		}
		catch ( \Throwable )
		{
			return null;
		}
	}

	/**
	 * The primary import-time entry point. Returns:
	 *
	 *   int > 0     — known pixel count, use it in a highest_res comparison
	 *   0           — the URL failed a prior dimension fetch (permanent-ish
	 *                 failure — treat as low resolution, matches the pre-
	 *                 Phase-11 synchronous behaviour when HTTP got a
	 *                 non-200)
	 *   null        — not-yet-known. Caller must DEFER the comparison.
	 *
	 * Never performs HTTP. Reads from the URL-hint fast path first
	 * (no DB round trip), then from the cache.
	 */
	public static function pixelsFor( string $url ): ?int
	{
		if ( $url === '' ) { return null; }
		$hint = self::pixelsFromHint( $url );
		if ( $hint > 0 ) { return $hint; }

		$row = self::lookup( $url );
		if ( $row === null ) { return null; }

		if ( $row['status'] === self::STATUS_READY && $row['width'] !== null && $row['height'] !== null )
		{
			return (int) $row['width'] * (int) $row['height'];
		}
		if ( $row['status'] === self::STATUS_FAILED )
		{
			/* Match sync-path semantics: an HTTP failure returned 0
			 * pre-Phase-11, so the OTHER image (if any) would win.
			 * We keep that behaviour so failed dimensions do not
			 * indefinitely block a highest_res decision. */
			return 0;
		}
		/* pending or unknown — caller must defer. */
		return null;
	}

	/**
	 * Ensure a URL has a pending cache row and a queued background
	 * worker. Idempotent + deduplicated — repeated calls with the
	 * same URL are cheap (no duplicate rows, no duplicate workers
	 * until the row ages out per the freshness / retry / stale
	 * constants).
	 *
	 * v1.0.127 (Phase 11) — the ONLY caller-side path that touches
	 * gd_image_dimensions during import. Never performs HTTP.
	 */
	public static function enqueue( string $url ): void
	{
		if ( !self::isValidHttpUrl( $url ) ) { return; }

		$hash = self::hashOf( $url );
		$now  = time();

		$existing = self::lookup( $url );
		if ( $existing !== null )
		{
			$age = $now - (int) ( $existing['checked_at'] ?? 0 );
			$status = (string) $existing['status'];
			if ( $status === self::STATUS_READY   && $age < self::FRESHNESS_DAYS    * 86400 ) { return; }
			if ( $status === self::STATUS_PENDING && $age < self::PENDING_STALE_SECONDS    ) { return; }
			if ( $status === self::STATUS_FAILED  && $age < self::FAILED_RETRY_DAYS * 86400 ) { return; }
		}

		try
		{
			\IPS\Db::i()->replace( 'gd_image_dimensions', [
				'url_hash'   => $hash,
				'url'        => $url,
				'width'      => $existing['width']  ?? null,
				'height'     => $existing['height'] ?? null,
				'status'     => self::STATUS_PENDING,
				'checked_at' => $now,
				'last_error' => null,
			] );
		}
		catch ( \Throwable )
		{
			return;
		}

		try
		{
			\IPS\Task::queue( 'gdcatalog', 'FetchImageDimensions', [ 'url' => $url ] );
		}
		catch ( \Throwable ) {}
	}

	/**
	 * Persist a successful dimension probe. Called by the queue
	 * extension after a successful HTTP fetch + getimagesize().
	 */
	public static function store( string $url, int $width, int $height ): void
	{
		if ( $url === '' || $width <= 0 || $height <= 0 ) { return; }
		try
		{
			\IPS\Db::i()->replace( 'gd_image_dimensions', [
				'url_hash'   => self::hashOf( $url ),
				'url'        => $url,
				'width'      => $width,
				'height'     => $height,
				'status'     => self::STATUS_READY,
				'checked_at' => time(),
				'last_error' => null,
			] );
		}
		catch ( \Throwable ) {}
	}

	/**
	 * Persist a failed dimension probe with an error message. Called
	 * by the queue extension on any of the tolerated failure modes:
	 * timeout, 404/403, malformed content, oversized response, DNS
	 * error, etc.
	 */
	public static function markFailed( string $url, string $error ): void
	{
		if ( $url === '' ) { return; }
		try
		{
			\IPS\Db::i()->replace( 'gd_image_dimensions', [
				'url_hash'   => self::hashOf( $url ),
				'url'        => $url,
				'width'      => null,
				'height'     => null,
				'status'     => self::STATUS_FAILED,
				'checked_at' => time(),
				'last_error' => mb_substr( $error, 0, 60000 ),
			] );
		}
		catch ( \Throwable ) {}
	}
}

class ImageDimensionCache extends _ImageDimensionCache {}
