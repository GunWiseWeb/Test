<?php
/**
 * @brief  GD Rebates — upgrade 1.0.13
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.13 — ACP queue improvements + sort_order + featured.
 *
 *   modules/admin/rebates/queue.php gains: an 'expired' tab
 *   (virtual filter on end_date < now), up/down arrow buttons +
 *   featured-star toggle in rowButtons, and an AJAX reorder
 *   endpoint (do=reorder&ids=1,2,3) intended for future drag-and-
 *   drop UI. The 'all' tab was already present but is now easier
 *   to reach next to the new 'expired' tab.
 *
 *   New columns on gd_rebates:
 *     * sort_order   INT UNSIGNED NOT NULL DEFAULT 0
 *     * featured     TINYINT UNSIGNED NOT NULL DEFAULT 0
 *   Both are added via IPS's guarded checkForColumn/addColumn so
 *   the migration is safe to re-run.
 *
 *   sort_order is bootstrapped to rebate_id on any row where it is
 *   still 0, giving every existing rebate a unique starting order
 *   so the swap-with-neighbor logic in queue.php works correctly
 *   on day one (without this, all rows share sort_order=0 and the
 *   swap query finds no neighbor).
 *
 *   modules/front/rebates/browse.php ORDER BY now goes:
 *     1) expired last (existing rule)
 *     2) featured DESC
 *     3) sort_order ASC
 *     4) end_date null-last then end_date ASC (existing fallback)
 *   The template's inline JS mirrors the same order.
 *
 *   Lang keys added (idempotent replace across every lang_id):
 *     gdrebates_rb_featured, gdrebates_rb_sort_order,
 *     gdrebates_feature, gdrebates_unfeature,
 *     gdrebates_move_up, gdrebates_move_down, gdrebates_featured.
 *
 *   Cache clear so the updated PHP + template load on the next
 *   request / task run.
 *
 * NO CanonicalTemplates::ensure() call.
 * Rule #79: upg_10012 removed, exactly one upg dir per app.
 */

namespace IPS\gdrebates\setup\upg_10013;

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
		/* 1. Guarded ALTER — add sort_order + featured columns. */
		try
		{
			if ( !\IPS\Db::i()->checkForColumn( 'gd_rebates', 'sort_order' ) )
			{
				\IPS\Db::i()->addColumn( 'gd_rebates', [
					'name'       => 'sort_order',
					'type'       => 'INT',
					'length'     => 10,
					'allow_null' => FALSE,
					'default'    => 0,
					'unsigned'   => TRUE,
				] );
			}
		}
		catch ( \Throwable $e ) { try { \IPS\Log::log( 'gdrebates upg_10013 sort_order add: ' . $e->getMessage(), 'gdrebates' ); } catch ( \Throwable ) {} }

		try
		{
			if ( !\IPS\Db::i()->checkForColumn( 'gd_rebates', 'featured' ) )
			{
				\IPS\Db::i()->addColumn( 'gd_rebates', [
					'name'       => 'featured',
					'type'       => 'TINYINT',
					'length'     => 1,
					'allow_null' => FALSE,
					'default'    => 0,
					'unsigned'   => TRUE,
				] );
			}
		}
		catch ( \Throwable $e ) { try { \IPS\Log::log( 'gdrebates upg_10013 featured add: ' . $e->getMessage(), 'gdrebates' ); } catch ( \Throwable ) {} }

		/* 2. Bootstrap sort_order = rebate_id where it is still 0 so
		     the ACP up/down swap can find neighbors on day one. */
		try
		{
			\IPS\Db::i()->preparedQuery( 'UPDATE ' . \IPS\Db::i()->prefix . 'gd_rebates SET sort_order = rebate_id WHERE sort_order = 0', [] );
		}
		catch ( \Throwable $e ) { try { \IPS\Log::log( 'gdrebates upg_10013 sort_order bootstrap: ' . $e->getMessage(), 'gdrebates' ); } catch ( \Throwable ) {} }

		/* 3. Idempotent (schema.json parity) — add the featured+sort_order
		     composite index if the underlying table doesn't already have it.
		     Safe: `addIndex` doesn't exist on all IPS versions, so we use
		     preparedQuery with a probe. */
		try
		{
			$hasIdx = FALSE;
			foreach ( \IPS\Db::i()->query( 'SHOW INDEX FROM ' . \IPS\Db::i()->prefix . 'gd_rebates' ) as $row )
			{
				if ( isset( $row['Key_name'] ) && $row['Key_name'] === 'idx_sort' ) { $hasIdx = TRUE; break; }
			}
			if ( !$hasIdx && \IPS\Db::i()->checkForColumn( 'gd_rebates', 'sort_order' ) && \IPS\Db::i()->checkForColumn( 'gd_rebates', 'featured' ) )
			{
				\IPS\Db::i()->preparedQuery( 'ALTER TABLE ' . \IPS\Db::i()->prefix . 'gd_rebates ADD INDEX idx_sort (featured, sort_order)', [] );
			}
		}
		catch ( \Throwable $e ) { try { \IPS\Log::log( 'gdrebates upg_10013 idx_sort add: ' . $e->getMessage(), 'gdrebates' ); } catch ( \Throwable ) {} }

		/* 4. Re-seed lang strings across every lang_id (Rule #43/#44 —
		     6-column core_sys_lang_words shape, per-row try/catch). */
		$strings = [
			'gdrebates_rb_featured'   => 'Featured',
			'gdrebates_rb_sort_order' => 'Sort order',
			'gdrebates_feature'       => 'Feature',
			'gdrebates_unfeature'     => 'Unfeature',
			'gdrebates_move_up'       => 'Move up',
			'gdrebates_move_down'     => 'Move down',
			'gdrebates_featured'      => 'Featured',
			/* also re-seed the pre-existing status label just in case any
			   older install is missing it (queue.php's expired tab needs it) */
			'gdrebates_status_expired' => 'Expired',
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
					catch ( \Throwable $e ) { try { \IPS\Log::log( 'gdrebates upg_10013 lang ' . $key . ': ' . $e->getMessage(), 'gdrebates' ); } catch ( \Throwable ) {} }
				}
			}
		}
		catch ( \Throwable $e ) { try { \IPS\Log::log( 'gdrebates upg_10013 lang seed loop: ' . $e->getMessage(), 'gdrebates' ); } catch ( \Throwable ) {} }

		/* 5. Cache / datastore clear so the updated PHP loads next
		     request. No templates to reseed in this version. */
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
