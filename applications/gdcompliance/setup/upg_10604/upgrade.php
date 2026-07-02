<?php
/**
 * @brief  GD Compliance — upgrade 1.6.4 (fast compute + timeout-proof ACP)
 *
 * Code-only release. No schema changes, no data touched.
 *
 * What ships:
 *   - Engine::computeFlags starts with @set_time_limit(0) +
 *     @ignore_user_abort(true) + @ini_set('memory_limit','512M'), so
 *     the 58k-row scan can't die at the 30s Db.php cap
 *   - AWB enabled-state lists are preloaded once before the foreach and
 *     passed into the loop, so the per-row enabledStates() call goes
 *     away (was the cheapest of the preload wins, but real)
 *   - AwbModels::isCenterfire / detectFeatures / parseOverallLengthIn
 *     memoize by product UPC (or raw string) with an 8k-entry rolling
 *     cap — the big preload win. detectFeatures is 6 preg_match calls
 *     on ~3KB of description text; before v1.6.4 it ran ONCE PER STATE
 *     per rifle (10× redundant across the enabled AWB states), now runs
 *     ONCE per rifle.
 *   - Compute controller's preview + run also raise the same PHP
 *     limits, belt-and-braces
 *
 * Roster::primeCache / classifyHandgun already read from in-memory
 * self::$cache with zero per-row DB queries — verified during audit,
 * no change needed there.
 *
 * upg_10604 just reseeds lang + purges caches. Never touches data.
 */

namespace IPS\gdcompliance\setup\upg_10604;

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
		/* Lang reseed — no new keys this version, but keeps existing rows
		   converged so a re-install always matches source. */
		$langFile = \IPS\ROOT_PATH . '/applications/gdcompliance/dev/lang.php';
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
									'word_app'     => 'gdcompliance',
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

		try { unset( \IPS\Data\Store::i()->settings ); }             catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->acpmenu ); }              catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }           catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->canonical_templates ); }  catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                    catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                    catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
