<?php
/**
 * @brief  GD Bills — Daily LegiScan sync task
 *
 * Runs once per day (registered in install). Wrapped so a failure can
 * never block other IPS tasks. Returns mixed (REQUIRED — otherwise IPS
 * freezes all tasks).
 */

namespace IPS\gdbills\tasks;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _syncBills extends \IPS\Task
{
	public function execute(): mixed
	{
		try
		{
			if ( (int) ( \IPS\Settings::i()->gdbills_autosync_enabled ?? 1 ) !== 1 )
			{
				return null;
			}
			$key = trim( (string) ( \IPS\Settings::i()->gdbills_legiscan_key ?? '' ) );
			if ( $key === '' )
			{
				return null;
			}
			\IPS\gdbills\LegiScan::fetchAllBills();
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'syncBills task: ' . $e->getMessage(), 'gdbills' ); } catch ( \Throwable ) {}
		}
		return null;
	}
}

class syncBills extends _syncBills {}
