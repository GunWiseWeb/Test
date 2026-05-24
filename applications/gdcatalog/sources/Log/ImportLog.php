<?php
/**
 * @brief       GD Master Catalog — Import Log Model
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 * @since       12 Apr 2026
 *
 * ActiveRecord for gd_import_log. One row per feed import run.
 */

namespace IPS\gdcatalog\Log;

/* To prevent PHP errors (extending class does not exist) revealing path */

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}
class ImportLog extends \IPS\Patterns\ActiveRecord
{
	public static ?string $databaseTable    = 'gd_import_log';
	public static string $databaseColumnId = 'id';
	public static string $databasePrefix   = '';
	protected static array $multitons      = [];

	/**
	 * Start a new import run.
	 *
	 * @param  int    $feedId
	 * @param  string $distributor
	 * @return static
	 */
	public static function startRun( int $feedId, string $distributor ): static
	{
		$log = new static;
		$log->feed_id          = $feedId;
		$log->distributor      = $distributor;
		$log->run_start        = date( 'Y-m-d H:i:s' );
		$log->run_end          = null;
		$log->records_total    = 0;
		$log->records_created  = 0;
		$log->records_updated  = 0;
		$log->records_skipped  = 0;
		$log->records_errored  = 0;
		$log->conflicts_logged = 0;
		$log->error_log        = null;
		$log->status           = 'running';
		$log->save();
		return $log;
	}

	/**
	 * Complete the run with final stats.
	 *
	 * @param  array $stats  Keys: total, created, updated, skipped, errored, conflicts
	 * @return void
	 */
	public function complete( array $stats ): void
	{
		$this->run_end            = date( 'Y-m-d H:i:s' );
		$this->records_total      = $stats['total']       ?? 0;
		$this->records_created    = $stats['created']     ?? 0;
		$this->records_updated    = $stats['updated']     ?? 0;
		$this->records_skipped    = $stats['skipped']     ?? 0;
		$this->records_errored    = $stats['errored']     ?? 0;
		$this->conflicts_logged   = $stats['conflicts']   ?? 0;
		$this->upc_invalid_count  = $stats['upc_invalid'] ?? 0;
		$this->upc_flagged_count  = $stats['upc_flagged'] ?? 0;
		$this->status             = 'completed';
		$this->save();
	}

	/**
	 * Mark the run as failed.
	 *
	 * @param  string $errorMessage
	 * @return void
	 */
	public function fail( string $errorMessage ): void
	{
		$this->run_end   = date( 'Y-m-d H:i:s' );
		$this->status    = 'failed';
		$this->error_log = $errorMessage;
		$this->save();
	}

	/**
	 * Append an error message to the error log.
	 *
	 * @param  string $message
	 * @return void
	 */
	public function appendError( string $message ): void
	{
		$existing = $this->error_log ?? '';
		$this->error_log = $existing !== ''
			? $existing . "\n" . $message
			: $message;
	}
}
