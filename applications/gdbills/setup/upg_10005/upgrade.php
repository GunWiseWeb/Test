<?php
/**
 * @brief  GD Bills — upgrade 1.0.5
 *
 * Existing Laws feature lands:
 *  - data/existing_laws.json (curated marquee state laws)
 *  - LegiScan::seedExistingLaws() — bundled-JSON importer (no API)
 *  - LegiScan::detectPriorSessionLaws() — opt-in admin action only
 *  - ACP sync page exposes "Seed Existing Laws" + "Detect Prior-Session Laws"
 *  - /bills/ state view + modal show an "Existing Laws" section
 *
 * Upgrade actions (idempotent, every step wrapped):
 *  1) Re-seed dev/lang.php into core_sys_lang_words for every language so
 *     the new gdbills_law_heading / gdbills_acp_seed_* / gdbills_acp_detect_*
 *     keys land on existing installs (rule #43/#44 — 6-col format,
 *     per-row try/catch).
 *  2) Run seedExistingLaws() to populate the bundled marquee laws right
 *     now — bill_type='law' rows visible on /bills/?state=XX immediately.
 *  3) Cache + opcache clear so the new template + controller bodies replace
 *     the cached old versions.
 *
 * Does NOT run detectPriorSessionLaws — that's API-expensive and stays
 * admin-triggered only.
 *
 * Self-contained per rule #79 (exactly one upg dir; supersedes upg_10004).
 */

namespace IPS\gdbills\setup\upg_10005;

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
		/* (1) Re-seed lang for new keys. */
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

		/* (2) Seed the bundled existing-laws JSON. Wrapped so a missing
		   file or a single bad row never aborts the upgrade. */
		try
		{
			$res = \IPS\gdbills\LegiScan::seedExistingLaws();
			try { \IPS\Log::log( 'upg_10005 seedExistingLaws: ' . json_encode( $res ), 'gdbills_upgrade' ); } catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10005 seedExistingLaws: ' . $e->getMessage(), 'gdbills_upgrade' ); } catch ( \Throwable ) {}
		}

		/* (3) Caches + opcache so new template/controller bodies land. */
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
