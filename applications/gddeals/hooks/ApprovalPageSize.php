//<?php
/**
 * gddeals — Approval Queue page-size hook.
 *
 * WHY THIS EXISTS
 *   Core IPS hardcodes the approval-queue table limit to 5 in
 *   applications/core/extensions/core/ModCp/Unapproved::manage(),
 *   which makes the /modcp/approval/ backlog impractical to work
 *   through (~294 pages of 5 = ~1470 items). This hook overrides
 *   the effective limit at render time using the ACP setting
 *   `gddeals_approval_queue_perpage` (default 50, min 5, max 200).
 *
 * WHY THIS TARGET, NOT UNAPPROVED DIRECTLY
 *   Reproducing Unapproved::manage() verbatim would be fragile —
 *   IPS core changes across point releases would silently drift
 *   away from our hook and require constant re-syncing. Instead
 *   we hook `\IPS\Helpers\Table\Content::__toString()` (called at
 *   the exact `(string) $table` cast inside manage()), detect that
 *   we're being called from ModCp\Unapproved via debug_backtrace,
 *   and only then bump the limit from 5 to the setting value.
 *   Every other Table\Content usage on the site is completely
 *   untouched.
 *
 * IPS 5 CAVEAT
 *   IPS 5 removed the classic _HOOK_CLASS_ compiler for PLUGINS
 *   (ACP-installable .xml). App-bundled hooks under
 *   applications/<app>/hooks/ registered via data/hooks.json are
 *   in a gray area — this repo ships an empty hooks.json in
 *   gdcontact/gdffl/gdreviews, so the file path is at least
 *   recognized by the build tools, but no working app hook has
 *   been shipped from this repo yet. The companion upg_10052
 *   step inserts the hook row directly into core_hooks as a
 *   defensive fallback so it lands regardless of whether IPS's
 *   Application::installHooks() runs on upgrade.
 *
 * Rule #27: guard header (this file's `//<?php` opener is the
 * IPS-standard hook obfuscation — IPS's hook compiler reads and
 * eval()s the content; the raw file is not directly loadable).
 */

if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

abstract class gddeals_hook_ApprovalPageSize extends _HOOK_CLASS_
{
	public function __toString(): string
	{
		try
		{
			if ( (int) ( $this->limit ?? 0 ) === 5 && static::gddealsApprovalContext() )
			{
				$perPage = 0;
				try { $perPage = (int) \IPS\Settings::i()->gddeals_approval_queue_perpage; } catch ( \Throwable ) {}
				if ( $perPage < 5 )   { $perPage = 50; }
				if ( $perPage > 200 ) { $perPage = 200; }
				$this->limit = $perPage;
			}
		}
		catch ( \Throwable ) { /* never break the page — fall through to parent */ }

		return parent::__toString();
	}

	protected static function gddealsApprovalContext(): bool
	{
		try
		{
			$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 12 );
			foreach ( $trace as $frame )
			{
				$cls = $frame['class'] ?? '';
				$fn  = $frame['function'] ?? '';
				if ( $fn === 'manage'
					&& is_string( $cls )
					&& ( str_ends_with( $cls, 'ModCp\\Unapproved' ) || str_contains( $cls, 'core\\extensions\\core\\ModCp\\Unapproved' ) ) )
				{
					return TRUE;
				}
			}
		}
		catch ( \Throwable ) {}
		return FALSE;
	}
}
