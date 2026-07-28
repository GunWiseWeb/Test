<?php
/**
 * @brief  GD Deals — upgrade 1.0.52
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.52 — Mod CP Approval Queue page-size override.
 *
 *   Core IPS hardcodes `$table->limit = 5;` in
 *   applications/core/extensions/core/ModCp/Unapproved::manage(),
 *   making /modcp/approval/ impractical with a ~294-page backlog.
 *
 *   This version adds:
 *     * setting gddeals_approval_queue_perpage (int, default 50,
 *       range 5–200) — surfaced in ACP → Deals → Settings under
 *       a new "Moderation" header.
 *     * code hook applications/gddeals/hooks/ApprovalPageSize.php
 *       + registration in data/hooks.json, targeting
 *       \IPS\Helpers\Table\Content::__toString(). The hook uses
 *       debug_backtrace to detect when it's being called from
 *       ModCp\Unapproved::manage() and only then bumps the limit
 *       — every other Table\Content usage on the site is untouched.
 *
 *   The seed here:
 *     1) Guarantees the setting row exists at the default (50)
 *        so any read hits a real value, not NULL.
 *     2) Re-seeds all new lang keys across every lang_id.
 *     3) Best-effort: forces IPS to re-scan hooks.json via
 *        \IPS\Application::load('gddeals')->installHooks() if
 *        that method exists, then falls through gracefully if
 *        IPS 5.0.18 has removed it.
 *     4) Cache clear + opcache reset.
 *
 * NO CanonicalTemplates::ensure() call. No template touched.
 * Rule #79: upg_10051 removed, exactly one upg dir per app.
 */

namespace IPS\gddeals\setup\upg_10052;

use function defined;
use function function_exists;
use function method_exists;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		/* 1. Seed setting default (idempotent — replace uses primary
		     key on conf_key). */
		try
		{
			\IPS\Db::i()->replace( 'core_sys_conf_settings', [
				'conf_key'          => 'gddeals_approval_queue_perpage',
				'conf_value'        => '50',
				'conf_default'      => '50',
				'conf_app'          => 'gddeals',
				'conf_report'       => 'full',
			] );
		}
		catch ( \Throwable $e )
		{
			/* Some installs may only allow a subset of columns; retry
			   with the minimum viable payload. */
			try
			{
				\IPS\Db::i()->replace( 'core_sys_conf_settings', [
					'conf_key'   => 'gddeals_approval_queue_perpage',
					'conf_value' => '50',
				] );
			}
			catch ( \Throwable $e2 ) { try { \IPS\Log::log( 'gddeals upg_10052 setting seed: ' . $e2->getMessage(), 'gddeals' ); } catch ( \Throwable ) {} }
		}

		/* 2. Re-seed lang strings across every lang_id (Rule #43/#44 —
		     6-column core_sys_lang_words shape, per-row try/catch). */
		$strings = [
			'gddeals_settings_moderation'         => 'Moderation',
			'gddeals_approval_queue_perpage'      => 'Approval queue page size',
			'gddeals_approval_queue_perpage_desc' => 'How many items to show per page on /modcp/approval/. Core IPS hardcodes 5, which makes a large backlog impractical to work through. Range 5&ndash;200 (default 50). Requires the ApprovalPageSize hook to be active (installed automatically when this app is installed/upgraded).',
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
					catch ( \Throwable $e ) { try { \IPS\Log::log( 'gddeals upg_10052 lang ' . $key . ': ' . $e->getMessage(), 'gddeals' ); } catch ( \Throwable ) {} }
				}
			}
		}
		catch ( \Throwable $e ) { try { \IPS\Log::log( 'gddeals upg_10052 lang loop: ' . $e->getMessage(), 'gddeals' ); } catch ( \Throwable ) {} }

		/* 3. Best-effort hook rescan. IPS 4 (and early IPS 5) apps
		     re-register their bundled hooks via
		     \IPS\Application::installHooks(). If IPS 5.0.18 has
		     removed that method, we skip silently — the hook file +
		     data/hooks.json still ship, ready for whatever mechanism
		     the platform provides. */
		try
		{
			$app = \IPS\Application::load( 'gddeals' );
			if ( method_exists( $app, 'installHooks' ) )
			{
				$app->installHooks();
			}
			elseif ( method_exists( $app, 'installOther' ) )
			{
				/* Some IPS 5 branches wrap installHooks() into installOther() */
				$app->installOther();
			}
		}
		catch ( \Throwable $e ) { try { \IPS\Log::log( 'gddeals upg_10052 installHooks: ' . $e->getMessage(), 'gddeals' ); } catch ( \Throwable ) {} }

		/* 4. Cache / datastore clear so the updated hook + PHP load
		     on the next request. */
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->hooks ); }              catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
