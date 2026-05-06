<?php
/**
 * @brief       GD Dealer Manager — Scheduled Feed Import Task
 * @package     IPS Community Suite
 * @subpackage  GD Dealer Manager
 * @since       15 Apr 2026
 *
 * Runs every 15 minutes (tasks.json). Each invocation loads all dealers
 * whose next-import window has elapsed given their subscription tier
 * schedule, then runs Importer::run() for each one.
 */

namespace IPS\gddealer\tasks;

use IPS\gddealer\Dealer\Dealer;
use IPS\gddealer\Feed\Importer;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _DealerImportFeeds extends \IPS\Task
{
	/**
	 * @return string|null  Log line, or null when no dealers ran
	 */
	public function execute(): mixed
	{
		$due = Dealer::loadDueForImport();
		if ( !$due )
		{
			return null;
		}

		$ran = 0;
		$ok  = 0;
		foreach ( $due as $dealer )
		{
			$log = Importer::run( $dealer );
			$ran++;
			if ( $log->status === 'completed' )
			{
				$ok++;
			}
		}

		return "DealerImportFeeds ran {$ran} dealer(s); {$ok} completed";
	}

	/**
	 * Background task timeout override — feeds can be large.
	 */
	public function cleanup()
	{
		/* Nothing to clean — listings and logs are managed by Importer::run */
	}
}

class DealerImportFeeds extends _DealerImportFeeds {}
