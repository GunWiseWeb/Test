<?php
/**
 * @brief  GD Rebates — upgrade 1.0.6
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.6 — Parser HTML preprocessing + missing lang key.
 *
 *   sources/Parser.php callAnthropic()
 *     Previous pipeline: strip_tags($html), truncate at 80,000 chars.
 *     strip_tags() removed TAGS but left the CONTENTS of <script>
 *     and <style> blocks (minified JS/CSS) as raw text, which bloated
 *     the character count with useless content. On the Springfield
 *     Armory "Gear Up 2026 Model 2020" page the actual word "rebate"
 *     sat at position ~97,256 in the cleaned text — past the 80,000
 *     truncation. Claude never saw it and correctly returned an empty
 *     array, so the parser logged the misleading
 *       last_status='ok', last_message='Parsed 0 rebate(s), inserted 0'
 *     with no visible error.
 *
 *     v1.0.6 preprocessing:
 *       * preg_replace <script>...</script> blocks (contents + tags)
 *       * preg_replace <style>...</style> blocks (contents + tags)
 *       * preg_replace <!-- ... --> HTML comments
 *       * strip_tags() on the remainder
 *       * collapse runs of tabs/spaces to single spaces
 *       * collapse 3+ blank lines to 2
 *       * mb_substr(..., 0, 350000)  — raised from 80,000. That's
 *         ~90-100K tokens — well within Claude Sonnet's context
 *         window with headroom for prompt + response, and 3x this
 *         100K-char cleaned example. Pages that still exceed the
 *         new budget will simply truncate (existing behavior).
 *
 *   dev/lang.php + data/lang.xml
 *     Added module__gdrebates_rebates = "Rebates" (missing key was
 *     rendering as the raw key on breadcrumbs since the front/admin
 *     `rebates` modules have no matching module__ row in
 *     core_sys_lang_words on existing installs). Seeded here for
 *     every language via core_sys_lang_words (rule #43, 6-col shape;
 *     per-row try/catch per rule #44).
 *
 * No schema. No template body changes. Cache clear so the module
 * dispatcher + lang store re-resolve.
 */

namespace IPS\gdrebates\setup\upg_10006;

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
		$this->clearCaches();
		return TRUE;
	}

	/**
	 * Seed the new module__gdrebates_rebates lang key into
	 * core_sys_lang_words for every installed language. 6-col shape
	 * (rule #43). Per-row try/catch (rule #44) so an odd row can't
	 * abort the whole seed.
	 */
	protected function seedLangStrings(): void
	{
		$strings = [
			'module__gdrebates_rebates' => 'Rebates',
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
						try { \IPS\Log::log( 'upg_10006 lang ' . $key . ': ' . $e->getMessage(), 'gdrebates_upg_10006' ); } catch ( \Throwable ) {}
					}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10006 lang loop: ' . $e->getMessage(), 'gdrebates_upg_10006' ); } catch ( \Throwable ) {}
		}
	}

	protected function clearCaches(): void
	{
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }
	}
}
class upgrade extends _upgrade {}
