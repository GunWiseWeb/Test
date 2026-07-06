<?php
/**
 * @brief  GD Dealer — upgrade 1.0.324
 *
 * WHAT SHIPS IN 1.0.324 — additive-only "API Access" dashboard tab.
 *
 *   * ONE new nav item in sources/Traits/DealerShellTrait::sidebarNav()
 *     (gated by gdcompliance API-access group; hidden otherwise).
 *   * ONE new controller method dashboard.php::api() that renders
 *     the dealer shell with an IFRAME of the sibling
 *     /api/compliance/mykey page. No template touches, no shared
 *     code with existing tabs.
 *   * Lang keys: gddealer_api_nav, gddealer_api_dashboard_title,
 *     and the not-subscribed gate message.
 *
 * SAFETY CONSTRAINTS FOR THIS SHIP:
 *   * The canonical-template helpers are DELIBERATELY NOT invoked
 *     from this upgrade. Template rows are not overwritten. Only
 *     the language datastore is refreshed + a targeted cache clear
 *     runs at the end.
 *   * Nothing else about the dashboard is touched.
 */

namespace IPS\gddealer\setup\upg_10324;

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
		/* --- Lang seed for the new API Access nav item + gate copy.
		   6-col schema (rule #43); per-row try/catch (rule #44). --- */
		$newStrings = [
			'gddealer_api_nav'              => 'API Access',
			'gddealer_api_dashboard_title'  => 'API Access',
			'gddealer_api_gate_title'       => 'API access is not part of your current plan',
			'gddealer_api_gate_msg'         => 'The Compliance API is a subscription-only integration. Subscribe to get an API key, register domains, and embed the compliance widget on your product pages.',
			'gddealer_api_gate_cta'         => 'View subscription',
		];

		try
		{
			foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
			{
				foreach ( $newStrings as $key => $val )
				{
					try
					{
						\IPS\Db::i()->replace( 'core_sys_lang_words', [
							'lang_id'      => (int) $langId,
							'word_app'     => 'gddealer',
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

		/* --- Cache clear ONLY. Deliberately not calling any
		   template-management helper (see docblock at the top). --- */
		try { unset( \IPS\Data\Store::i()->lang ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }             catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }             catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
