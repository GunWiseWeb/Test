<?php
/**
 * @brief  GD Catalog — upgrade 1.0.113 (PlatformClassifier).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.113
 *   Tiered title-based classifier for category-1 (Handguns)
 *   products that are actually rifles or shotguns. Mirrors the
 *   structure of gdcompliance/sources/Lowers.php (Derrick-approved
 *   pattern for this kind of work). Five layers:
 *     1. Curated overrides (gd_catalog_platform_overrides — admin
 *        corrections win permanently over auto-classification)
 *     2. Handgun override signals (checked FIRST as a gate —
 *        "pistol" with negative lookahead against "pistol grip",
 *        revolver/derringer/SAA/arm-brace+short-barrel/
 *        cylinder-count+grips-no-stock)
 *     3. Decisive shotgun signal (gauge tokens: N Gauge / N ga /
 *        .410 / 410 bore)
 *     4. Decisive rifle signal (brand contains "Rifles" | rifle-
 *        exclusive caliber alone | rifle-action language + long
 *        barrel)
 *     5. Everything else → REVIEW (gd_catalog_platform_review)
 *
 *   DRY-RUN mode reports counts without writing; LIVE-RUN commits
 *   the reclassifications and logs every one to
 *   gd_catalog_platform_reclass_log for audit / rollback. Both
 *   triggered via ACP → Catalog → Platform Review.
 *
 *   Ambiguous rows (Colt M4 5.56 11.50" — could be rifle or AR-
 *   pistol depending on details not in the title) route to a
 *   review queue where Derrick reassigns per-row.
 *
 * WHAT THIS UPGRADE DOES
 *   1. Guarded CREATE TABLE IF NOT EXISTS for the three new
 *      tables (platform_overrides, platform_review,
 *      platform_reclass_log). checkForTable-first — idempotent
 *      on re-run.
 *   2. Re-seed the new lang keys across every lang_id
 *      (Rule #43/#44 — 6-column core_sys_lang_words, per-row
 *      try/catch).
 *   3. Cache/module/opcache purge so the new controller +
 *      classifier PHP + template body load on next request.
 *
 * NO destructive change to existing tables. Live-run reclassification
 * is Derrick-triggered from the ACP after a dry-run review, NOT
 * automatically here. Rule #79: upg_10112 removed, exactly one
 * upg dir per app.
 */

namespace IPS\gdcatalog\setup\upg_10113;

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
		$db = \IPS\Db::i();

		/* 1. Guarded CREATE TABLE — three new tables. */
		if ( !$db->checkForTable( 'gd_catalog_platform_overrides' ) )
		{
			try
			{
				$db->createTable( [
					'name'    => 'gd_catalog_platform_overrides',
					'columns' => [
						[ 'name' => 'id',                  'type' => 'INT', 'length' => 10, 'allow_null' => false, 'auto_increment' => true, 'unsigned' => true ],
						[ 'name' => 'pattern',             'type' => 'VARCHAR', 'length' => 191, 'allow_null' => false, 'default' => '' ],
						[ 'name' => 'target_category_id', 'type' => 'INT', 'length' => 10, 'allow_null' => false, 'default' => 0, 'unsigned' => true ],
						[ 'name' => 'note',                'type' => 'VARCHAR', 'length' => 255, 'allow_null' => true, 'default' => null ],
						[ 'name' => 'created_at',          'type' => 'INT', 'length' => 10, 'allow_null' => true, 'default' => null, 'unsigned' => true ],
					],
					'indexes' => [
						[ 'type' => 'primary', 'name' => 'PRIMARY', 'columns' => [ 'id' ] ],
						[ 'type' => 'unique',  'name' => 'uq_pattern', 'columns' => [ 'pattern' ], 'length' => [ 191 ] ],
					],
				] );
			}
			catch ( \Throwable $e ) { try { \IPS\Log::log( 'upg_10113 create overrides: ' . $e->getMessage(), 'gdcatalog_upg_10113' ); } catch ( \Throwable ) {} }
		}

		if ( !$db->checkForTable( 'gd_catalog_platform_review' ) )
		{
			try
			{
				$db->createTable( [
					'name'    => 'gd_catalog_platform_review',
					'columns' => [
						[ 'name' => 'id',                    'type' => 'INT', 'length' => 10, 'allow_null' => false, 'auto_increment' => true, 'unsigned' => true ],
						[ 'name' => 'upc',                   'type' => 'VARCHAR', 'length' => 50, 'allow_null' => false, 'default' => '' ],
						[ 'name' => 'current_category_id',   'type' => 'INT', 'length' => 10, 'allow_null' => false, 'default' => 0, 'unsigned' => true ],
						[ 'name' => 'suggested_category_id', 'type' => 'INT', 'length' => 10, 'allow_null' => true, 'default' => null, 'unsigned' => true ],
						[ 'name' => 'reason_hint',           'type' => 'VARCHAR', 'length' => 255, 'allow_null' => true, 'default' => null ],
						[ 'name' => 'title_snapshot',        'type' => 'VARCHAR', 'length' => 255, 'allow_null' => true, 'default' => null ],
						[ 'name' => 'brand_snapshot',        'type' => 'VARCHAR', 'length' => 120, 'allow_null' => true, 'default' => null ],
						[ 'name' => 'resolved',              'type' => 'TINYINT', 'length' => 1, 'allow_null' => false, 'default' => 0, 'unsigned' => true ],
						[ 'name' => 'created_at',            'type' => 'INT', 'length' => 10, 'allow_null' => true, 'default' => null, 'unsigned' => true ],
					],
					'indexes' => [
						[ 'type' => 'primary', 'name' => 'PRIMARY',      'columns' => [ 'id' ] ],
						[ 'type' => 'unique',  'name' => 'uq_upc',       'columns' => [ 'upc' ] ],
						[ 'type' => 'key',     'name' => 'idx_resolved', 'columns' => [ 'resolved' ] ],
					],
				] );
			}
			catch ( \Throwable $e ) { try { \IPS\Log::log( 'upg_10113 create review: ' . $e->getMessage(), 'gdcatalog_upg_10113' ); } catch ( \Throwable ) {} }
		}

		if ( !$db->checkForTable( 'gd_catalog_platform_reclass_log' ) )
		{
			try
			{
				$db->createTable( [
					'name'    => 'gd_catalog_platform_reclass_log',
					'columns' => [
						[ 'name' => 'id',              'type' => 'INT', 'length' => 10, 'allow_null' => false, 'auto_increment' => true, 'unsigned' => true ],
						[ 'name' => 'upc',             'type' => 'VARCHAR', 'length' => 50, 'allow_null' => false, 'default' => '' ],
						[ 'name' => 'old_category_id', 'type' => 'INT', 'length' => 10, 'allow_null' => false, 'default' => 0, 'unsigned' => true ],
						[ 'name' => 'new_category_id', 'type' => 'INT', 'length' => 10, 'allow_null' => false, 'default' => 0, 'unsigned' => true ],
						[ 'name' => 'source',          'type' => 'VARCHAR', 'length' => 40, 'allow_null' => false, 'default' => '' ],
						[ 'name' => 'signal',          'type' => 'VARCHAR', 'length' => 255, 'allow_null' => true, 'default' => null ],
						[ 'name' => 'created_at',      'type' => 'INT', 'length' => 10, 'allow_null' => true, 'default' => null, 'unsigned' => true ],
					],
					'indexes' => [
						[ 'type' => 'primary', 'name' => 'PRIMARY', 'columns' => [ 'id' ] ],
						[ 'type' => 'key',     'name' => 'idx_upc', 'columns' => [ 'upc' ] ],
					],
				] );
			}
			catch ( \Throwable $e ) { try { \IPS\Log::log( 'upg_10113 create reclass_log: ' . $e->getMessage(), 'gdcatalog_upg_10113' ); } catch ( \Throwable ) {} }
		}

		/* 2. Re-seed lang keys across every lang_id. */
		$strings = [
			'menu__gdcatalog_catalog_platformreview' => 'Platform Review',
			'gdcatalog_platform_title'               => 'Platform Classifier — Category 1 (Handguns) cleanup',
			'gdcatalog_platform_run_header'          => 'Run classifier on category 1 (Handguns)',
			'gdcatalog_platform_run_help'            => 'DRY RUN reports the counts without writing anything. LIVE RUN commits the reclassifications and populates the review queue for ambiguous rows. Every live reclassification is logged to <code>gd_catalog_platform_reclass_log</code> for audit / rollback.',
			'gdcatalog_platform_dryrun_btn'          => 'Dry Run (report only)',
			'gdcatalog_platform_run_btn'             => 'Live Run (commit reclassifications)',
			'gdcatalog_platform_log_count'           => 'Reclassifications logged all-time',
			'gdcatalog_platform_review_header'       => 'Review Queue (ambiguous rows)',
			'gdcatalog_platform_review_empty'        => 'Review queue is empty. Run the classifier first, or every row has been resolved.',
			'gdcatalog_platform_overrides_header'    => 'Curated Overrides',
			'gdcatalog_platform_overrides_help'      => 'Add a pattern (title/model/UPC substring, case-insensitive) that ALWAYS wins over auto-classification.',
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
							'word_app'     => 'gdcatalog',
							'word_key'     => $key,
							'word_default' => $val,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
					catch ( \Throwable $e ) { try { \IPS\Log::log( 'upg_10113 lang ' . $key . ': ' . $e->getMessage(), 'gdcatalog_upg_10113' ); } catch ( \Throwable ) {} }
				}
			}
		}
		catch ( \Throwable $e ) { try { \IPS\Log::log( 'upg_10113 lang loop: ' . $e->getMessage(), 'gdcatalog_upg_10113' ); } catch ( \Throwable ) {} }

		/* 3. Cache purge. */
		try { \IPS\Db::i()->delete( 'core_cache' ); }                                                                catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'theme_%' OR store_key LIKE 'template_%'" ] ); } catch ( \Throwable ) {}
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/template_*' ) ?: [] as $f ) { @unlink( $f ); }
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
