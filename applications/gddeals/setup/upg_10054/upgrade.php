<?php
/**
 * @brief  GD Deals — upgrade 1.0.54
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.54 — fix the misleading ACP description for
 * gddeals_approval_queue_perpage.
 *
 * The v1.0.52 description text referenced a non-functional
 * app-bundled hook that was deleted in v1.0.53 after both hook
 * attempts failed to activate on IPS 5.0.18 (IPS 5 removed the
 * classic app-bundled hook system; see the v1.0.53 commit for
 * full context). The real mechanism is now a DIRECT MANUAL EDIT
 * to core file applications/core/extensions/core/ModCp/Unapproved.php
 * line ~115.
 *
 * The new description tells the ACP admin exactly that, and warns
 * that a future IPS core update may overwrite the edit and reset
 * the limit back to 5 — at which point changing THIS setting alone
 * won't restore functionality; the core edit must be re-applied.
 *
 * WHAT THIS UPGRADE DOES
 *   1. Re-seeds the description lang key across every lang_id
 *      (Rule #43/#44 — 6-column core_sys_lang_words shape,
 *      per-row try/catch). The lang.xml install path only fires
 *      on fresh installs; existing installs get the new text
 *      through this reseed.
 *   2. Cache / datastore clear so the ACP picks up the new
 *      description text on next request.
 *
 * NO schema change. NO setting change (setting exists from
 * v1.0.52, currently 100 in production). NO template touched.
 * NO CanonicalTemplates::ensure() call.
 * Rule #79: upg_10053 removed, exactly one upg dir per app.
 */

namespace IPS\gddeals\setup\upg_10054;

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
		/* 1. Re-seed the description lang key across every lang_id.
		     Keep the same text as data/lang.xml (byte-for-byte) so
		     fresh installs and upgrades converge on the same value. */
		$strings = [
			'gddeals_approval_queue_perpage'      => 'Approval queue page size',
			'gddeals_approval_queue_perpage_desc' => 'How many items to show per page on /modcp/approval/. Core IPS hardcodes this to 5, which makes a large backlog impractical to work through. Range 5&ndash;200 (default 50). <br><br>This setting is read by a <b>direct edit to IPS core file</b> <code>applications/core/extensions/core/ModCp/Unapproved.php</code> (line ~115) &mdash; IPS 5 does not support a reliable app-bundled hook mechanism for this kind of override. <br><br><b>IMPORTANT:</b> a future IPS core update may overwrite that edit and reset the limit to 5; if so, the edit must be manually re-applied (see that file&rsquo;s inline comment for the exact change). Changing THIS setting alone will not restore functionality if the core edit has been overwritten.',
		];
		try
		{
			foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
			{
				foreach ( $strings as $key => $val )
				{
					try
					{
						\IPS\Db::i()->replace( 'core_sys_lang_words', [
							'lang_id'      => (int) $langId,
							'word_app'     => 'gddeals',
							'word_key'     => $key,
							'word_default' => $val,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
					catch ( \Throwable $e ) { try { \IPS\Log::log( 'gddeals upg_10054 lang ' . $key . ': ' . $e->getMessage(), 'gddeals' ); } catch ( \Throwable ) {} }
				}
			}
		}
		catch ( \Throwable $e ) { try { \IPS\Log::log( 'gddeals upg_10054 lang loop: ' . $e->getMessage(), 'gddeals' ); } catch ( \Throwable ) {} }

		/* 2. Cache / datastore clear so the ACP re-renders with the
		     new description text on the next request. */
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
