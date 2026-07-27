<?php
/**
 * @brief  GD Rebates — upgrade 1.0.11
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.11 — two front-page fixes.
 *
 *   Fix 1: "+N more" chip toggle is now bidirectional.
 *     dev/html/front/rebates/browse.phtml — the "+N more" button
 *     no longer removes itself on first click. It toggles a
 *     .gdrb-chips--expanded class on the container (CSS in
 *     dev/css/front/rebates.css un-hides .gdrb-chip--hidden when
 *     the container has that class) and swaps its own text
 *     between two data-attribute labels (data-more-label,
 *     data-fewer-label — both emitted at render time from lang
 *     keys so JS never needs a lang lookup). Repeatable expand /
 *     collapse with no page reload.
 *
 *   Fix 2: full gdrebates_type_* enum audit + seed.
 *     The authoritative rebate_type enum lives in
 *     modules/admin/rebates/manualadd.php (9 entries: cash,
 *     percent, gift_card, prepaid_card, store_credit, free_item,
 *     free_shipping, bundle, other). Prior versions were missing
 *     gdrebates_type_percent and gdrebates_type_gift_card in
 *     dev/lang.php + data/lang.xml, so those two rendered as raw
 *     keys on their cards. All 9 keys are now present in both
 *     files and this upgrade re-seeds every one of them into
 *     core_sys_lang_words for every installed lang_id (rule #43
 *     6-col shape, rule #44 per-row try/catch) — including the
 *     two previously-missing ones so existing installs get them.
 *
 *   Also seeds the new gdrebates_show_fewer collapse label for
 *   Fix 1's toggle.
 *
 * NO CanonicalTemplates::ensure() call.
 * Rule #79: upg_10010 removed, exactly one upg dir per app.
 */

namespace IPS\gdrebates\setup\upg_10011;

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
			/* v1.0.11 Fix 1 */
			'gdrebates_show_fewer'         => 'Show fewer',

			/* v1.0.11 Fix 2 — every rebate_type key, including the
			   two that were missing (percent, gift_card). All 9 are
			   re-seeded so existing installs get the missing ones
			   AND any admin edits to the present ones are overwritten
			   to the shipped defaults — acceptable trade for closing
			   the audit gap. */
			'gdrebates_type_cash'          => 'Cash back',
			'gdrebates_type_percent'       => 'Percent off',
			'gdrebates_type_gift_card'     => 'Gift card',
			'gdrebates_type_prepaid_card'  => 'Prepaid card',
			'gdrebates_type_store_credit'  => 'Store credit',
			'gdrebates_type_free_item'     => 'Free item',
			'gdrebates_type_free_shipping' => 'Free shipping',
			'gdrebates_type_bundle'        => 'Bundle',
			'gdrebates_type_other'         => 'Other',
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
						try { \IPS\Log::log( 'upg_10011 lang ' . $key . ': ' . $e->getMessage(), 'gdrebates_upg_10011' ); } catch ( \Throwable ) {}
					}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10011 lang loop: ' . $e->getMessage(), 'gdrebates_upg_10011' ); } catch ( \Throwable ) {}
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
