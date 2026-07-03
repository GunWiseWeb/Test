<?php
/**
 * @brief  GD Search — upgrade 1.0.80
 *
 * Frontend companion to gdcompliance v1.6.17:
 *   - product.phtml gains a distinct YELLOW buyer-permit advisory
 *     block above the red "cannot ship to:" restriction banner, plus
 *     its own popup markup. Advisories are NOT restrictions.
 *   - restrictpopup.js now supports two scopes — .gdsp-restrict (red)
 *     and .gdsp-advisory (yellow) — via a single initScope() helper.
 *   - results.php splits Flag::forUpc() into $restrictionRows +
 *     $advisoryRows and hands both to the template.
 *
 * Template + JS only — re-runs templates_10046.php to reseed the
 * product template body into core_theme_templates so existing
 * installs pick up the new markup.
 */

namespace IPS\gdsearch\setup\upg_10080;

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
		try { require_once \IPS\ROOT_PATH . '/applications/gdsearch/setup/templates_10046.php'; }
		catch ( \Throwable ) {}

		try { unset( \IPS\Data\Store::i()->canonical_templates ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }            catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }        catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }          catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                   catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                   catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
