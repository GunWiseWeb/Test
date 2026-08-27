<?php
/**
 * @brief    Background Task — Fetch Image Dimensions (Phase 11)
 * @since    v1.0.127
 *
 * One queue row = one product-image URL. The queue extension:
 *
 *   1. HEAD/GET the URL with a conservative timeout.
 *   2. Determine width/height with getimagesize() (works on
 *      response body OR remote path).
 *   3. Store dimensions via ImageDimensionCache::store OR
 *      markFailed on any failure.
 *   4. Ask ConflictResolver to re-evaluate any deferred image
 *      conflicts that involve this URL. If the re-evaluation
 *      finds a definitive winner it applies it to the product
 *      and queues a reindex through the existing pathway —
 *      no OpenSearch HTTP here.
 *
 * ONE queue row = ONE URL. No batching; the URL is transferred in
 * $data['url'] and run() exits after a single fetch. IPS's own
 * queue runner iterates rows.
 *
 * Safety:
 *   - URL scheme validated by ImageDimensionCache::isValidHttpUrl
 *     before enqueue.
 *   - Response cap via HTTP request timeout (existing IPS Http\Url
 *     conventions); getimagesize() itself refuses non-images.
 *   - Every failure mode routes to markFailed with a descriptive
 *     error and NEVER crashes the queue.
 */

namespace IPS\gdcatalog\extensions\core\Queue;

use IPS\Extensions\QueueAbstract;
use IPS\gdcatalog\Feed\ConflictResolver;
use IPS\gdcatalog\Feed\ImageDimensionCache;
use IPS\Log;
use IPS\Task\Queue\OutOfRangeException as QueueOutOfRangeException;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _FetchImageDimensions extends QueueAbstract
{
	protected const HTTP_TIMEOUT_SECONDS = 10;

	public function preQueueData( array $data ): ?array
	{
		$url = (string) ( $data['url'] ?? '' );
		if ( !ImageDimensionCache::isValidHttpUrl( $url ) )
		{
			try { Log::log( 'FetchImageDimensions: invalid URL rejected: ' . $url, 'gdcatalog_image_dim' ); } catch ( \Throwable ) {}
			return null;
		}
		return $data;
	}

	/**
	 * @param  array $data
	 * @param  int   $offset
	 * @return int   ignored — always throws QueueOutOfRangeException
	 */
	public function run( array &$data, int $offset ): int
	{
		$url = (string) ( $data['url'] ?? '' );
		if ( $url === '' )
		{
			throw new QueueOutOfRangeException;
		}

		$tmp = null;
		try
		{
			$response = \IPS\Http\Url::external( $url )
				->request( self::HTTP_TIMEOUT_SECONDS )
				->get();

			$code = (int) $response->httpResponseCode;
			if ( $code !== 200 )
			{
				ImageDimensionCache::markFailed( $url, 'HTTP ' . $code );
				try { Log::log( 'FetchImageDimensions: HTTP ' . $code . ' url=' . $url, 'gdcatalog_image_dim' ); } catch ( \Throwable ) {}
				throw new QueueOutOfRangeException;
			}

			$body = (string) $response;
			if ( $body === '' )
			{
				ImageDimensionCache::markFailed( $url, 'empty body' );
				throw new QueueOutOfRangeException;
			}

			$tmp = tempnam( sys_get_temp_dir(), 'gd_img_' );
			@file_put_contents( $tmp, $body );

			$size = @getimagesize( $tmp );
			if ( $size === false || !isset( $size[0], $size[1] ) )
			{
				ImageDimensionCache::markFailed( $url, 'not an image / malformed' );
				throw new QueueOutOfRangeException;
			}

			$w = (int) $size[0];
			$h = (int) $size[1];
			if ( $w <= 0 || $h <= 0 )
			{
				ImageDimensionCache::markFailed( $url, 'zero dimensions' );
				throw new QueueOutOfRangeException;
			}

			ImageDimensionCache::store( $url, $w, $h );
			try { Log::log( 'FetchImageDimensions: stored ' . $w . 'x' . $h . ' url=' . $url, 'gdcatalog_image_dim' ); } catch ( \Throwable ) {}

			/* Kick off any deferred highest_res comparisons that
			 * were waiting on this URL. Runs entirely against the
			 * local DB — no OpenSearch HTTP, no product-write
			 * duplication (goes through the existing product save
			 * + queueReindex pathway inside ConflictResolver::
			 * reevaluateForUrl). */
			try
			{
				$reevaluated = ConflictResolver::reevaluateForUrl( $url );
				if ( $reevaluated > 0 )
				{
					try { Log::log( 'FetchImageDimensions: reevaluated ' . $reevaluated . ' deferred conflict(s) for url=' . $url, 'gdcatalog_image_dim' ); } catch ( \Throwable ) {}
				}
			}
			catch ( \Throwable $e )
			{
				try { Log::log( 'FetchImageDimensions: reevaluate failed url=' . $url . ': ' . $e->getMessage(), 'gdcatalog_image_dim' ); } catch ( \Throwable ) {}
			}
		}
		catch ( QueueOutOfRangeException $e )
		{
			throw $e;
		}
		catch ( \Throwable $e )
		{
			ImageDimensionCache::markFailed( $url, $e->getMessage() );
			try { Log::log( 'FetchImageDimensions: failure url=' . $url . ': ' . $e->getMessage(), 'gdcatalog_image_dim' ); } catch ( \Throwable ) {}
		}
		finally
		{
			if ( $tmp !== null && is_file( $tmp ) ) { @unlink( $tmp ); }
		}

		throw new QueueOutOfRangeException;
	}

	public function getProgress( mixed $data, int $offset ): array
	{
		$url = (string) ( $data['url'] ?? '' );
		return [
			'text'     => 'Fetching image dimensions: ' . $url,
			'complete' => 50,
		];
	}

	public function postComplete( array $data, bool $processed = TRUE ): void
	{
		/* All work happens inside run(); nothing to finalise here. */
	}
}

class FetchImageDimensions extends _FetchImageDimensions {}
