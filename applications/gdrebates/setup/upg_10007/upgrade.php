<?php
/**
 * @brief  GD Rebates — upgrade 1.0.7
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.7 — rebate-card overflow + missing-CTA fix.
 *
 *   The v1.0.6 parser fix (script/style stripping + 350k-char budget)
 *   let Claude extract COMPLETE eligible_models lists — which
 *   surfaced two card-layout gaps on /rebates/:
 *
 *   1. `.gdrb-card__models` (dev/css/front/rebates.css) had no
 *      overflow constraint, so a 500+ char eligible_models list
 *      (verified: Springfield Armory Model 2020 rebate lists ~10
 *      SKU variants) stretched the card. v1.0.7 clamps the block
 *      to 3 lines with ellipsis via -webkit-line-clamp + a
 *      companion `.is-expanded` state that removes the clamp on
 *      click. Full list also reachable via native title=""
 *      tooltip (zero-JS baseline).
 *
 *   2. dev/html/front/rebates/browse.phtml action row promoted
 *      `redemption_url` to the primary button and `source_url`
 *      only to a soft/secondary button. Real rebates parsed from
 *      generic manufacturer landing pages often have no explicit
 *      redemption_url — those cards showed only a muted "Details"
 *      button. Restructured: if redemption_url is present the
 *      chrome is unchanged (primary Submit + soft Details); if
 *      absent BUT source_url is present, the source_url is
 *      promoted to primary with a new "View Offer" label so every
 *      card has a prominent CTA.
 *
 *   Template also gained title="{eligible_models}" and an
 *   onclick="this.classList.toggle('is-expanded')" on the
 *   .gdrb-card__models block.
 *
 * New lang key:
 *   gdrebates_view_offer  =  'View Offer'
 *
 * step1() re-seeds the new lang key per lang_id (rule #43 6-col
 * shape, rule #44 per-row try/catch) and clears caches so the
 * updated browse.phtml + rebates.css resolve on the next hit.
 *
 * No schema. No PHP controller changes.
 */

namespace IPS\gdrebates\setup\upg_10007;

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

	protected function seedLangStrings(): void
	{
		$strings = [
			'gdrebates_view_offer' => 'View Offer',
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
						try { \IPS\Log::log( 'upg_10007 lang ' . $key . ': ' . $e->getMessage(), 'gdrebates_upg_10007' ); } catch ( \Throwable ) {}
					}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10007 lang loop: ' . $e->getMessage(), 'gdrebates_upg_10007' ); } catch ( \Throwable ) {}
		}
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
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Theme::deleteCompiledTemplate(); }              catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }
	}
}
class upgrade extends _upgrade {}
