<?php
/**
 * @brief       GD Master Catalog — ValidateProductImages Scheduled Task
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 * @since       12 May 2026
 *
 * Runs hourly. HEAD-checks product image URLs to detect broken (404) or
 * otherwise unreachable image URLs. Processes the OLDEST-validated batch
 * each run, so all 58k+ products get cycled through over time.
 *
 * Batch size from setting gdcatalog_image_validation_batch_size (default 2000).
 * Revalidation cadence from gdcatalog_image_validation_revalidate_days (default 30).
 *
 * Selection order:
 *   1. image_validated IS NULL (never checked) - first
 *   2. image_validated_at < NOW() - revalidate_days (oldest checks first)
 *
 * Skips rows where image_url IS NULL or empty.
 *
 * Per-request: HEAD request with 8-second timeout, 3-second connect timeout.
 * Records http_status and updates image_validated=1, image_validated_at=NOW(),
 * image_http_status=<code>.
 *
 * Network errors (timeout, DNS fail) record as status 0 - filterable as
 * "broken" alongside 4xx/5xx.
 */

namespace IPS\gdcatalog\tasks;

/* To prevent PHP errors (extending class does not exist) revealing path */

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _ValidateProductImages extends \IPS\Task
{
	/**
	 * Execute the task.
	 *
	 * @return string|null  Message to log, or NULL
	 */
	public function execute(): mixed
	{
		/* Resolve settings with safe fallbacks. */
		try
		{
			$batchSize = (int) \IPS\Settings::i()->gdcatalog_image_validation_batch_size;
		}
		catch ( \Throwable )
		{
			$batchSize = 2000;
		}
		if ( $batchSize < 1 )    { $batchSize = 2000; }
		if ( $batchSize > 10000 ) { $batchSize = 10000; }

		try
		{
			$revalidateDays = (int) \IPS\Settings::i()->gdcatalog_image_validation_revalidate_days;
		}
		catch ( \Throwable )
		{
			$revalidateDays = 30;
		}
		if ( $revalidateDays < 1 )   { $revalidateDays = 30; }
		if ( $revalidateDays > 365 ) { $revalidateDays = 365; }

		$revalidateCutoff = date( 'Y-m-d H:i:s', time() - ( 86400 * $revalidateDays ) );

		/* Find products that need validation. Priority:
		 *   1. NULL image_validated (never checked) - first
		 *   2. image_validated_at older than cutoff (oldest first) */
		$where = [
			[
				'(image_url IS NOT NULL AND image_url != ?) AND (image_validated IS NULL OR image_validated_at < ?)',
				'',
				$revalidateCutoff
			]
		];

		$rows = [];
		try
		{
			foreach (
				\IPS\Db::i()->select(
					'upc, image_url, image_validated_at',
					'gd_catalog',
					$where,
					'image_validated_at IS NULL DESC, image_validated_at ASC',
					[ 0, $batchSize ]
				) as $row
			)
			{
				$rows[] = $row;
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'ValidateProductImages SELECT failed: ' . $e->getMessage(), 'gdcatalog_imgcheck' ); } catch ( \Throwable ) {}
			return null;
		}

		if ( empty( $rows ) )
		{
			return 'No images need validation';
		}

		if ( !function_exists( 'curl_init' ) )
		{
			try { \IPS\Log::log( 'ValidateProductImages skipped: PHP curl extension not available', 'gdcatalog_imgcheck' ); } catch ( \Throwable ) {}
			return 'curl not available';
		}

		$totalChecked = 0;
		$totalOk      = 0;
		$totalBroken  = 0;
		$totalErrored = 0;
		$startTime    = microtime( true );

		/* Use a single curl handle, reset per-request for performance.
		 * Sports South CDN handles concurrent requests fine but we serialize
		 * for simplicity and to be polite. */
		$ch = curl_init();
		curl_setopt_array( $ch, [
			CURLOPT_NOBODY         => TRUE,           /* HEAD request */
			CURLOPT_RETURNTRANSFER => TRUE,
			CURLOPT_FOLLOWLOCATION => TRUE,
			CURLOPT_MAXREDIRS      => 3,
			CURLOPT_CONNECTTIMEOUT => 3,
			CURLOPT_TIMEOUT        => 8,
			CURLOPT_USERAGENT      => 'GunRack.deals/1.0 ImageValidator',
			CURLOPT_SSL_VERIFYPEER => TRUE,
			CURLOPT_SSL_VERIFYHOST => 2,
		] );

		foreach ( $rows as $row )
		{
			$upc = (string) ( $row['upc'] ?? '' );
			$url = (string) ( $row['image_url'] ?? '' );

			if ( $upc === '' || $url === '' )
			{
				continue;
			}

			curl_setopt( $ch, CURLOPT_URL, $url );

			$response = curl_exec( $ch );
			$httpCode = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			$curlErr  = curl_errno( $ch );

			if ( $curlErr !== 0 )
			{
				/* Network error - record as status 0 */
				$httpCode = 0;
				$totalErrored++;
			}
			elseif ( $httpCode >= 200 && $httpCode < 400 )
			{
				$totalOk++;
			}
			else
			{
				$totalBroken++;
			}

			try
			{
				\IPS\Db::i()->update( 'gd_catalog',
					[
						'image_validated'    => 1,
						'image_validated_at' => date( 'Y-m-d H:i:s' ),
						'image_http_status'  => $httpCode,
					],
					[ 'upc=?', $upc ]
				);
				$totalChecked++;
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( sprintf( 'ValidateProductImages update failed for upc=%s: %s', $upc, $e->getMessage() ), 'gdcatalog_imgcheck' ); } catch ( \Throwable ) {}
			}
		}

		curl_close( $ch );

		$duration = round( microtime( true ) - $startTime, 1 );

		$msg = sprintf(
			'Validated %d images: %d OK, %d broken, %d network errors (%.1fs)',
			$totalChecked, $totalOk, $totalBroken, $totalErrored, $duration
		);

		try { \IPS\Log::log( $msg, 'gdcatalog_imgcheck' ); } catch ( \Throwable ) {}

		return $msg;
	}
}

class ValidateProductImages extends _ValidateProductImages {}
