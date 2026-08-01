<?php
/**
 * @brief  GD Search — upgrade 1.0.85 (Handgun "Type" facet).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.85
 *   New plain-language "Type" facet for the Handguns category —
 *   Pistol / Revolver / Derringer / Single-Shot Pistol / Flare
 *   Pistol. Values derived from CATEGORY (gd_categories parent_id=1
 *   → child rows 2-6), NOT from the unreliable gd_catalog.gun_type
 *   column (which has calibers and marketing phrases mixed in with
 *   real type values per Derrick's verified sample).
 *
 *   Complements — does NOT replace — the existing "Action" facet,
 *   which stays as the detailed technical filter (SAO/DA/SA/etc)
 *   for shoppers who know their action types.
 *
 *   Three-layer wiring (all in the tarball):
 *
 *   1. sources/Search/Searcher.php
 *      * New scoped `handgun_types` OpenSearch agg on
 *        `subcategory.keyword` filtered by
 *        `category.keyword = 'Handguns'`. Bucket keys are the
 *        raw plural subcategory names from the DB (Pistols,
 *        Revolvers, ...) — display labels are singularised at
 *        the template layer, keeping bucket keys index-authoritative
 *        for the round-trip filter.
 *      * New facet filter — `handgun_type` request param maps
 *        to the same underlying `subcategory.keyword` field
 *        through a separate closure invocation, so it doesn't
 *        step on the pre-existing generic `subcategory` filter.
 *
 *   2. modules/front/search/results.php
 *      * `handgun_type` added to $filters and the pagination QS.
 *      * `handgun_types` added to both agg-processing loops
 *        (bucket lift + empty-key filter).
 *      * `$handgunTypeLabels` map (plural → singular) passed to
 *        the template.
 *
 *   3. dev/html/front/search/results.phtml
 *      * New facet block above the existing Actions block, using
 *        the same details/checkbox/count pattern as every other
 *        facet. Renders singular labels via
 *        {$handgunTypeLabels[$b['key']] ?? $b['key']}.
 *      * New template parameter `$handgunTypeLabels=[]` with a
 *        default so any theme that hasn't been reseeded yet keeps
 *        rendering the plural bucket keys instead of erroring.
 *
 *   4. setup/install.php
 *      * `handgun_types` added to the $keys array in the
 *        $gdsearchSeedFacetSettings closure so fresh installs
 *        register the facet_key with hidden=0 (matching every
 *        other seeded facet's convention on this app).
 *
 * WHAT THIS UPGRADE DOES
 *   1. INSERT IGNORE the facet_settings row (hidden=0 default —
 *      the scoped agg's Handguns-only filter already prevents the
 *      facet from showing on non-handgun pages, so a global hidden
 *      flag isn't needed; Derrick can still hide it via
 *      gd_facet_settings if he wants to).
 *   2. Re-seed the results.phtml template row by invoking the
 *      install.php $gdsearchSeedResultsTemplate closure — it reads
 *      the freshly-shipped dev/html/front/search/results.phtml
 *      file and replaces the core_theme_templates row, the same
 *      way install.php does on a fresh install.
 *   3. Re-seed the new lang key across every lang_id (Rule
 *      #43/#44 — 6-column core_sys_lang_words shape, per-row
 *      try/catch).
 *   4. Cache clear.
 *
 * Rule #79: upg_10084 removed, exactly one upg dir per app.
 */

namespace IPS\gdsearch\setup\upg_10085;

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
		/* 1. Register the new facet_key row (idempotent — INSERT
		     IGNORE via last-arg true on IPS's insert()). */
		try
		{
			\IPS\Db::i()->insert( 'gd_facet_settings', [
				'facet_key'   => 'handgun_types',
				'hidden'      => 0,
				'category_id' => 0,
				'position'    => 0,
			], TRUE );
		}
		catch ( \Throwable $e ) { try { \IPS\Log::log( 'gdsearch upg_10085 facet seed: ' . $e->getMessage(), 'gdsearch_upg_10085' ); } catch ( \Throwable ) {} }

		/* 2. Reseed the results.phtml template by re-invoking
		     install.php (its $gdsearchSeedResultsTemplate closure
		     reads dev/html/front/search/results.phtml and
		     replace()s the core_theme_templates row). */
		try
		{
			$installPath = \IPS\ROOT_PATH . '/applications/gdsearch/setup/install.php';
			if ( is_file( $installPath ) )
			{
				require_once $installPath;
			}
		}
		catch ( \Throwable $e ) { try { \IPS\Log::log( 'gdsearch upg_10085 template reseed: ' . $e->getMessage(), 'gdsearch_upg_10085' ); } catch ( \Throwable ) {} }

		/* 3. Reseed lang key across every lang_id. */
		$strings = [
			'gdsearch_facet_handgun_type' => 'Type',
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
							'word_app'     => 'gdsearch',
							'word_key'     => $key,
							'word_default' => $val,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
					catch ( \Throwable $e ) { try { \IPS\Log::log( 'gdsearch upg_10085 lang ' . $key . ': ' . $e->getMessage(), 'gdsearch_upg_10085' ); } catch ( \Throwable ) {} }
				}
			}
		}
		catch ( \Throwable $e ) { try { \IPS\Log::log( 'gdsearch upg_10085 lang loop: ' . $e->getMessage(), 'gdsearch_upg_10085' ); } catch ( \Throwable ) {} }

		/* 4. Cache purge — template caches + module + settings +
		     opcache so the new PHP and template reach the browser
		     on the next request. */
		try { \IPS\Theme::deleteCompiledTemplate(); }              catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->themes ); }             catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
