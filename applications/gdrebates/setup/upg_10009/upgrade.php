<?php
/**
 * @brief  GD Rebates — upgrade 1.0.9
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.9 — three-part feature.
 *
 *   Part 1 — Manual "Add Rebate" ACP tool
 *     New controller modules/admin/rebates/manualadd.php. Full
 *     form (manufacturer/title/type/amount/dates/models/URLs)
 *     that INSERTs into gd_rebates with status='approved',
 *     source_id=NULL, approved_by=admin's member_id, approved_at
 *     = now. Skips the parser + review queue entirely. Uses the
 *     SAME dedupe_hash formula the parser uses
 *     (sha1( mfr . '|' . title . '|' . end_date_yyyy-mm-dd )),
 *     so manual entries participate in dedupe. Registered in
 *     data/acpmenu.json (as "Add Rebate" under the Rebates tab)
 *     and data/acprestrictions.json (new manualadd_manage
 *     restriction).
 *
 *   Part 2 — eligible_models as chips
 *     Controller (modules/front/rebates/browse.php) now splits
 *     each rebate's eligible_models on ", " into
 *     $r['_models_list']. Template (dev/html/front/rebates/
 *     browse.phtml) renders each entry as a .gdrb-chip with the
 *     first 5 visible and the rest hidden behind a "+N more"
 *     button (JS handler in the same file). CSS adds .gdrb-chip,
 *     .gdrb-chip--hidden, .gdrb-chip--more; drops the v1.0.7
 *     .gdrb-card__models line-clamp block (replaced by chips).
 *
 *   Part 3 — countdown banner + expired handling + new setting
 *     Controller computes $r['_days_left'] + $r['_is_expired']
 *     from end_date vs. now. Template renders one of:
 *       * .gdrb-card__expired-tag  when _is_expired
 *       * .gdrb-countdown--urgent  "Last day!" (0-1 day left)
 *       * .gdrb-countdown--urgent  "N days left" (2-7 days)
 *       * .gdrb-countdown--soon    "N days left" (8-30 days)
 *       * plain "Ends {date}"      (>30 days, existing behavior)
 *     (Mutually exclusive per card.) Expired cards get
 *     .gdrb-card--expired: opacity .55 + subtle grayscale, muted
 *     amount pill, sorted last (both at the DB layer and in the
 *     client-side re-sort — expired always drops to the bottom
 *     regardless of sort dropdown value).
 *
 *     New setting gdrebates_show_expired (default 1 = ON). When
 *     ON, expired rebates stay visible with the treatment above.
 *     When OFF, they are excluded at the query layer entirely.
 *     Added to the Settings ACP form as a YesNo toggle.
 *
 * step1() does:
 *   1. Seed gdrebates_show_expired in core_sys_conf_settings
 *      (default '1') via IPS's setting-persist path. Idempotent
 *      — existing rows are updated in place; missing rows are
 *      created.
 *   2. Re-seed every new lang key (chip, countdown, expired,
 *      manual-add form) per lang_id into core_sys_lang_words
 *      (rule #43 6-col shape, rule #44 per-row try/catch).
 *   3. Purge any stale canonical .tpl overrides for browse (per
 *      project standing rule — a stale .tpl can win over
 *      dev/html even in IN_DEV, hiding the fix).
 *   4. Cache purge (modules, applications, extensions, settings,
 *      interface_files, themes, canonical_templates Store keys;
 *      DELETE FROM core_store WHERE store_key LIKE 'theme_%' /
 *      'template_%'; @unlink datastore/template_*; Store +
 *      Cache clearAll; Theme::deleteCompiledTemplate;
 *      opcache_reset).
 *
 * Rule #79: upg_10008 removed, exactly one upg dir per app.
 * NO CanonicalTemplates::ensure() call.
 */

namespace IPS\gdrebates\setup\upg_10009;

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
		$this->seedShowExpiredSetting();
		$this->seedLangStrings();
		$this->purgeStaleCanonicalTemplate();
		$this->clearCaches();
		return TRUE;
	}

	/**
	 * Seed gdrebates_show_expired in core_sys_conf_settings if it
	 * doesn't already exist. Default '1' (ON). Uses the same
	 * settings row shape IPS installs from data/settings.json.
	 */
	protected function seedShowExpiredSetting(): void
	{
		try
		{
			$exists = 0;
			try
			{
				$exists = (int) \IPS\Db::i()->select( 'COUNT(*)', 'core_sys_conf_settings',
					[ 'conf_key=?', 'gdrebates_show_expired' ] )->first();
			}
			catch ( \Throwable ) {}

			if ( $exists === 0 )
			{
				\IPS\Db::i()->insert( 'core_sys_conf_settings', [
					'conf_key'          => 'gdrebates_show_expired',
					'conf_value'        => '1',
					'conf_default'      => '1',
					'conf_app'          => 'gdrebates',
					'conf_report'       => 'full',
				] );
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10009 seed show_expired: ' . $e->getMessage(), 'gdrebates_upg_10009' ); } catch ( \Throwable ) {}
		}
	}

	protected function seedLangStrings(): void
	{
		$strings = [
			'gdrebates_chip_more'               => 'more',

			'gdrebates_last_day'                => 'Last day!',
			'gdrebates_days_left'               => '%s days left',
			'gdrebates_expired'                 => 'Expired',
			'gdrebates_show_expired'            => 'Show expired rebates on the front page',
			'gdrebates_show_expired_desc'       => 'When on, expired rebates stay visible on /rebates/ (grayed out with an "Expired" tag, sorted last). When off, expired rebates are hidden from the front page entirely.',

			'menu__gdrebates_rebates_manualadd' => 'Add Rebate',
			'r__manualadd_manage'               => 'Manually add rebates',
			'gdrebates_manual_h_required'       => 'Required',
			'gdrebates_manual_h_amount'         => 'Amount',
			'gdrebates_manual_h_dates'          => 'Dates (optional)',
			'gdrebates_manual_h_models'         => 'Eligible models',
			'gdrebates_manual_h_urls'           => 'URLs (optional)',
			'gdrebates_manual_manufacturer'     => 'Manufacturer',
			'gdrebates_manual_title'            => 'Rebate title',
			'gdrebates_manual_rebate_type'      => 'Type',
			'gdrebates_manual_amount'           => 'Dollar amount (numeric — leave blank for FREE/other)',
			'gdrebates_manual_amount_na'        => 'Not applicable',
			'gdrebates_manual_amount_text'      => 'Amount label (shown on the pill)',
			'gdrebates_manual_start_date'       => 'Start date',
			'gdrebates_manual_end_date'         => 'End date',
			'gdrebates_manual_submit_by'        => 'Submit by',
			'gdrebates_manual_eligible_models'  => 'Eligible models — comma-space separated ("Model A, Model B") to match the parser format that drives the chip list',
			'gdrebates_manual_redemption_url'   => 'Redemption URL',
			'gdrebates_manual_source_url'       => 'Source page URL',
			'gdrebates_manual_image_url'        => 'Image URL',
			'gdrebates_manual_pdf_url'          => 'PDF URL',
			'gdrebates_manual_save'             => 'Add rebate (goes live immediately)',
			'gdrebates_manual_saved'            => 'Rebate added and published. <a href="%s" target="_blank" rel="noopener">View on /rebates/</a>',
			'gdrebates_manual_err_required'     => 'Manufacturer and title are required.',
			'gdrebates_manual_err_dupe'         => 'A rebate with the same manufacturer + title + end date already exists (matched by dedupe hash). Change the title to disambiguate.',
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
						try { \IPS\Log::log( 'upg_10009 lang ' . $key . ': ' . $e->getMessage(), 'gdrebates_upg_10009' ); } catch ( \Throwable ) {}
					}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10009 lang loop: ' . $e->getMessage(), 'gdrebates_upg_10009' ); } catch ( \Throwable ) {}
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
				try
				{
					if ( is_file( $stale ) && is_writable( $stale ) )
					{
						@unlink( $stale );
					}
				}
				catch ( \Throwable ) {}
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
