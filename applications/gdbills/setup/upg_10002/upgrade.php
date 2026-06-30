<?php
/**
 * @brief  GD Bills — upgrade 1.0.2
 *
 * - Reconcile progress_stage vocabulary so the new 5-step tracker template
 *   lights up for existing rows without a full re-sync:
 *     'signed'      → 'became_law'
 *     'passed_both' → 'to_governor'
 *   Also set status='vetoed' on any row where progress_stage='vetoed' so the
 *   front template's failed-notice branch triggers (parseBill now forces this
 *   on new ingests, but existing rows wrote the human-readable last_action
 *   text into status).
 * - Re-seed lang keys added in 1.0.2 (gdbills_stage_* / gdbills_failed /
 *   gdbills_vetoed) into core_sys_lang_words for every language.
 * - Clear caches + opcache so the new LegiScan vocabulary and template body
 *   replace the cached old ones.
 *
 * Self-contained: also re-applies the 1.0.1 cache-clear in case this upgrade
 * is being run from a fresh-install-of-1.0.0 state (rule #79 — exactly one
 * upg dir on disk, must cover everything since previous shipped version).
 */

namespace IPS\gdbills\setup\upg_10002;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		/* Remap stored progress_stage values to the tracker vocabulary. */
		try { \IPS\Db::i()->update( 'gd_bills', [ 'progress_stage' => 'became_law' ],  [ 'progress_stage=?', 'signed' ] ); }      catch ( \Throwable ) {}
		try { \IPS\Db::i()->update( 'gd_bills', [ 'progress_stage' => 'to_governor' ], [ 'progress_stage=?', 'passed_both' ] ); } catch ( \Throwable ) {}

		/* Flip status to 'vetoed' for rows where the stage already says vetoed,
		   so the failed-notice branch in billRow.phtml triggers. */
		try { \IPS\Db::i()->update( 'gd_bills', [ 'status' => 'vetoed' ], [ 'progress_stage=?', 'vetoed' ] ); } catch ( \Throwable ) {}

		/* Re-seed lang for new keys (and refresh existing ones). dev/lang.php
		   is the source of truth — seed every key it contains into every
		   language. Per-row try/catch so a single odd row never aborts. */
		$langFile = \IPS\ROOT_PATH . '/applications/gdbills/dev/lang.php';
		if ( is_readable( $langFile ) )
		{
			$lang = [];
			include $langFile;
			if ( is_array( $lang ) && !empty( $lang ) )
			{
				try
				{
					foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
					{
						foreach ( $lang as $key => $val )
						{
							try
							{
								\IPS\Db::i()->replace( 'core_sys_lang_words', [
									'lang_id'      => (int) $langId,
									'word_app'     => 'gdbills',
									'word_key'     => (string) $key,
									'word_default' => (string) $val,
									'word_js'      => 0,
									'word_export'  => 1,
								] );
							}
							catch ( \Throwable ) {}
						}
					}
				}
				catch ( \Throwable ) {}
			}
		}

		/* Caches + opcache so the new method body and template content
		   replace the opcached/datastored old versions. */
		try { unset( \IPS\Data\Store::i()->acpmenu ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }            catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }            catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
