<?php
/**
 * @brief  GD Rebates — upgrade 1.0.10
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.10 — two front-page fixes.
 *
 *   Fix 1: resilient manufacturer-logo matching.
 *     modules/front/rebates/browse.php now resolves each rebate's
 *     _logo through three tiers instead of one:
 *       1. exact case-insensitive match against gd_rebate_logos
 *          (existing behavior)
 *       2. strip a trailing NUMERIC-anchored short token —
 *          " 1", " 2", " #1", " (2026)", " #Spring2026" — from
 *          the rebate's manufacturer, retry. Regex REQUIRES the
 *          trailing token to begin with a digit or "#" so it
 *          never eats a legitimate word like "Wesson", "Sauer",
 *          "Armory". Verified against a 12-case fixture.
 *       3. prefix match — any known logo whose key + " "
 *          prefixes this rebate's manufacturer (catches
 *          "H&K - Spring" -> H&K where the regex tier wouldn't
 *          fire).
 *     Rebates with manufacturer "H&K 1" and "H&K 2" (Derrick's
 *     convention for two simultaneous promos from one brand)
 *     now resolve to the "H&K" logo instead of falling back to
 *     text.
 *
 *   Fix 2: front-page "Hide expired" checkbox.
 *     dev/html/front/rebates/browse.phtml gains a checkbox in
 *     the filter bar, rendered ONLY when the ACP
 *     gdrebates_show_expired setting is ON (i.e. expired
 *     rebates can appear at all — if OFF the checkbox would be
 *     meaningless and is not rendered). Client-side filter JS
 *     composes it with the existing type/mfr/amount filters
 *     via the same apply() function.
 *     Template signature gains $showExpired (bool); the
 *     controller passes it.
 *
 * No PHP controller changes beyond browse.php. No schema. New
 * lang key gdrebates_hide_expired seeded per lang_id (rule #43
 * 6-col shape, rule #44 per-row try/catch).
 *
 * NO CanonicalTemplates::ensure() call.
 * Rule #79: upg_10009 removed, exactly one upg dir per app.
 */

namespace IPS\gdrebates\setup\upg_10010;

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
		$this->seedLangStrings();
		$this->purgeStaleCanonicalTemplate();
		$this->clearCaches();
		return TRUE;
	}

	protected function seedLangStrings(): void
	{
		$strings = [
			'gdrebates_hide_expired' => 'Hide expired',
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
							'word_app'     => 'gdrebates',
							'word_key'     => $key,
							'word_default' => $val,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
					catch ( \Throwable $e )
					{
						try { \IPS\Log::log( 'upg_10010 lang ' . $key . ': ' . $e->getMessage(), 'gdrebates_upg_10010' ); } catch ( \Throwable ) {}
					}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10010 lang loop: ' . $e->getMessage(), 'gdrebates_upg_10010' ); } catch ( \Throwable ) {}
		}
	}

	protected function purgeStaleCanonicalTemplate(): void
	{
		try
		{
			$dir = \IPS\ROOT_PATH . '/applications/gdrebates/data/canonical_templates';
			if ( !is_dir( $dir ) ) { return; }
			foreach ( glob( $dir . '/*browse*' ) ?: [] as $stale )
			{
				try { if ( is_file( $stale ) && is_writable( $stale ) ) { @unlink( $stale ); } } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable ) {}
	}

	protected function clearCaches(): void
	{
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->interface_files ); }    catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->themes ); }             catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->canonical_templates ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'theme_%' OR store_key LIKE 'template_%'" ] ); } catch ( \Throwable ) {}
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/template_*' ) ?: [] as $f ) { @unlink( $f ); }
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Theme::deleteCompiledTemplate(); }              catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }
	}
}
class upgrade extends _upgrade {}
