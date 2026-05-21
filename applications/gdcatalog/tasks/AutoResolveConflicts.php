<?php
/**
 * @brief       GD Master Catalog — AutoResolveConflicts Scheduled Task
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 * @since       12 Apr 2026
 *
 * Runs hourly. Finds feed conflicts past their 48-hour auto_resolve_at
 * deadline and applies the incoming value automatically.
 * Sends admin digest email summarising auto-resolved conflicts.
 */

namespace IPS\gdcatalog\tasks;

/* To prevent PHP errors (extending class does not exist) revealing path */

use IPS\gdcatalog\Conflict\FeedConflict;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class AutoResolveConflicts extends \IPS\Task
{
	/**
	 * Execute the task.
	 *
	 * @return string|null
	 */
	public function execute(): mixed
	{
		$expired  = FeedConflict::loadExpired();
		$resolved = 0;

		if ( empty( $expired ) )
		{
			return null;
		}

		$summaryLines = [];

		foreach ( $expired as $conflict )
		{
			try
			{
				$conflict->autoResolve();
				$resolved++;

				$summaryLines[] = sprintf(
					'[%s] %s: "%s" → "%s"',
					$conflict->upc,
					$conflict->field_name,
					mb_substr( $conflict->current_value, 0, 80 ),
					mb_substr( $conflict->incoming_value, 0, 80 )
				);
			}
			catch ( \Throwable $e )
			{
				\IPS\Log::log(
					'Auto-resolve failed for conflict #' . $conflict->id . ': ' . $e->getMessage(),
					'gdcatalog_autoresolve'
				);
			}
		}

		/* Send admin digest email if any were resolved */
		if ( $resolved > 0 )
		{
			$this->sendDigest( $resolved, $summaryLines );
		}

		return "Auto-resolved {$resolved} expired feed conflict(s)";
	}

	/**
	 * Send digest email to admins listing auto-resolved conflicts.
	 *
	 * @param  int   $count
	 * @param  array $lines
	 * @return void
	 */
	protected function sendDigest( int $count, array $lines ): void
	{
		$subject = "GD Catalog: {$count} feed conflict(s) auto-resolved";
		$body    = "The following feed conflicts were automatically accepted after the " .
			\IPS\Settings::i()->gdcatalog_auto_resolve_hours . "-hour review window:\n\n" .
			implode( "\n", $lines );

		/* Send to all admins with ACP access */
		foreach ( \IPS\Db::i()->select( '*', 'core_members', [ 'member_group_id=?', \IPS\Settings::i()->admin_group ] ) as $admin )
		{
			try
			{
				$member = \IPS\Member::constructFromData( $admin );
				$email  = \IPS\Email::buildFromContent(
					$subject,
					nl2br( htmlspecialchars( $body, ENT_QUOTES, 'UTF-8' ) ),
					$body
				);
				$email->send( $member );
			}
			catch ( \Throwable )
			{
				/* Don't let email failure break the task */
			}
		}
	}
}
