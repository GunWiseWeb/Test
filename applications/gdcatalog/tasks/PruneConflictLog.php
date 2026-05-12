<?php
/**
 * @brief       GD Master Catalog — PruneConflictLog Scheduled Task
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 * @since       11 May 2026
 *
 * Runs daily. Deletes auto-resolved conflict log entries
 * (admin_override = 0) older than `gdcatalog_conflict_log_retention_days`
 * days. Manual overrides (admin_override = 1) are preserved.
 *
 * Default retention: 14 days. Setting accepts 1..365.
 */

namespace IPS\gdcatalog\tasks;

/* To prevent PHP errors (extending class does not exist) revealing path */

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _PruneConflictLog extends \IPS\Task
{
	/**
	 * Execute the task.
	 *
	 * @return string|null  Message to log, or NULL
	 */
	public function execute(): mixed
	{
		/* Resolve retention setting with safe fallback. */
		try
		{
			$days = (int) \IPS\Settings::i()->gdcatalog_conflict_log_retention_days;
		}
		catch ( \Throwable )
		{
			$days = 14;
		}

		if ( $days < 1 )
		{
			$days = 14;
		}
		if ( $days > 365 )
		{
			$days = 365;
		}

		$cutoff = date( 'Y-m-d H:i:s', time() - ( 86400 * $days ) );

		try
		{
			$deleted = (int) \IPS\Db::i()->delete( 'gd_conflict_log', [
				'admin_override = 0 AND resolved_at < ?',
				$cutoff
			] );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'PruneConflictLog failed: ' . $e->getMessage(), 'gdcatalog_prune' ); } catch ( \Throwable ) {}
			return null;
		}

		if ( $deleted > 0 )
		{
			$msg = sprintf(
				'Pruned %d auto-resolved conflict log entries older than %d days (cutoff: %s)',
				$deleted,
				$days,
				$cutoff
			);
			try { \IPS\Log::log( $msg, 'gdcatalog_prune' ); } catch ( \Throwable ) {}
			return $msg;
		}

		return null;
	}
}

class PruneConflictLog extends _PruneConflictLog {}
