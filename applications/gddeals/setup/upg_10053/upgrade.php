<?php
/**
 * @brief  GD Deals — upgrade 1.0.53
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.53 — cleanup of the non-functional v1.0.52
 * approval-queue hook artifacts. The functional fix is a MANUAL
 * DIRECT EDIT to core Unapproved.php on the live server; that is
 * NOT part of this tarball (see the commit message + gddeals notes
 * for the exact edit block).
 *
 * WHY THE HOOK WAS ABANDONED
 *   IPS 5 removed the classic app-bundled `_HOOK_CLASS_` hook
 *   compiler in favor of Listeners / UI Extensions / Loader
 *   Extensions. `core_hooks` does not exist as a table on this
 *   IPS 5.0.18 install (verified via direct query). The v1.0.52
 *   attempts (data/hooks.json + hooks/ApprovalPageSize.php) did
 *   not activate on the live site — approval queue still showed
 *   5 items regardless of the setting value. Per IPS's own
 *   developer community, direct core-file edits are the
 *   realistic path for this class of override in IPS 5.
 *
 * WHAT THIS UPGRADE DOES
 *   1. NOTHING to the DB — the gddeals_approval_queue_perpage
 *      setting from v1.0.52 stays exactly as-is (Derrick has
 *      already set it to 100). Reading the setting is the job
 *      of the core-file edit outside this tarball.
 *   2. Removes the dead hook file (applications/gddeals/hooks/
 *      ApprovalPageSize.php) and resets data/hooks.json to `{}`
 *      via the tarball layout — no upgrade-script action needed
 *      for that, IPS overwrites the file tree on tarball install.
 *   3. Clears hook / extensions / module caches so IPS drops any
 *      cached reference to the removed hook file.
 *
 * NO CanonicalTemplates::ensure() call.
 * Rule #79: upg_10052 removed, exactly one upg dir per app.
 */

namespace IPS\gddeals\setup\upg_10053;

use function defined;
use function function_exists;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		/* Cache / datastore clear so IPS drops any cached reference
		   to the removed hook. `hooks` key clear is defensive —
		   nothing else depends on it. */
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->hooks ); }              catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
