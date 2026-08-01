<?php
/**
 * @brief  GD Catalog — upgrade 1.0.114 (PlatformClassifier dual-use caliber fix).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.114
 *   Follow-up to v1.0.113's PlatformClassifier. Derrick's dry-run
 *   found three confirmed misclassifications, all the same root
 *   cause: dual-use pistol/rifle calibers (5.7x28, 7.62x39) were
 *   in the "rifle-decisive caliber" list, so pistol platforms
 *   chambered in them were wrongly auto-classified as rifles:
 *     - Kel-Tec P50 5.7x28 9.60"     → RIFLE (WRONG — AR-pistol)
 *     - Maxim Defense PDX 7.62x39    → RIFLE (WRONG — pistol line)
 *     - Zastava ZPAP 92 7.62x39 10"  → RIFLE (WRONG — AK-pistol)
 *
 *   Two-part fix in sources/Catalog/PlatformClassifier.php:
 *
 *   1. PRUNED dual-use calibers from RIFLE_CALIBER_PATTERNS.
 *      Removed: 5.7x28, 7.62x39, 7.62x51, 6mm ARC, .30 Carbine,
 *      .17 HMR, .22 WMR / .22 Mag. Kept only calibers with
 *      essentially zero commercial pistol variants (6.5 Creedmoor,
 *      .308 Win, .30-06, .350 Legend, .450 Bushmaster, etc.).
 *      A caliber match in the pruned list now implicitly means
 *      "not dual-use", so the Savage 110 10.5" bolt-carbine in
 *      6.5 Creedmoor still auto-classifies as rifle correctly
 *      (no regression).
 *
 *   2. ADDED product-line HANDGUN overrides in Layer 2 for the
 *      three confirmed pistol families PLUS related known
 *      AK-pistol lines (Century Draco / Mini-Draco / Krinkov
 *      pistol). These fire in Layer 2 BEFORE any caliber-based
 *      logic reaches Layer 4, so even if the dual-use caliber
 *      list ever gains one back accidentally, these families
 *      still can't be mis-rifle-classified.
 *
 *   BONUS: added barrel-length parsing from the title text
 *   (regex over patterns like `9.60"`, `5.50" Barrel`) as a
 *   fallback when the structured column is empty — which is the
 *   default state on the cat-1 rows this classifier targets.
 *
 *   NO regression on the previously-correct matches (traced
 *   through all 12 test cases logically before shipping — Savage
 *   110, Bergara Rifles, Maverick 88 Cruiser, Henry Axe 410, plus
 *   the original 8 all still classify correctly).
 *
 * WHAT THIS UPGRADE DOES
 *   1. Idempotent CREATE TABLE IF NOT EXISTS guards for the three
 *      platform-classifier tables (in case a fresh install jumps
 *      from a pre-v1.0.113 version straight to v1.0.114 — the
 *      previous upg_10113 has been removed per rule #79 so this
 *      version must still be self-contained).
 *   2. Re-seed the platform-classifier lang keys (unchanged
 *      strings but re-seeded defensively across every lang_id).
 *   3. Cache purge so the updated classifier PHP loads next
 *      request.
 *
 * NO destructive change. Live-run reclassification is still
 * Derrick-triggered from ACP after a fresh dry-run review.
 * Rule #79: upg_10113 removed, exactly one upg dir per app.
 */

namespace IPS\gdcatalog\setup\upg_10114;

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

		/* 1. Guarded CREATE TABLE — same three tables as upg_10113,
		     needed here for the pre-10113→10114 jump case. */
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
			catch ( \Throwable $e ) { try { \IPS\Log::log( 'upg_10114 create overrides: ' . $e->getMessage(), 'gdcatalog_upg_10114' ); } catch ( \Throwable ) {} }
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
			catch ( \Throwable $e ) { try { \IPS\Log::log( 'upg_10114 create review: ' . $e->getMessage(), 'gdcatalog_upg_10114' ); } catch ( \Throwable ) {} }
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
			catch ( \Throwable $e ) { try { \IPS\Log::log( 'upg_10114 create reclass_log: ' . $e->getMessage(), 'gdcatalog_upg_10114' ); } catch ( \Throwable ) {} }
		}

		/* 2. Re-seed platform-classifier lang keys (unchanged from
		     v1.0.113 but re-seeded defensively in case an install
		     ran with a bad lang state). */
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
					catch ( \Throwable $e ) { try { \IPS\Log::log( 'upg_10114 lang ' . $key . ': ' . $e->getMessage(), 'gdcatalog_upg_10114' ); } catch ( \Throwable ) {} }
				}
			}
		}
		catch ( \Throwable $e ) { try { \IPS\Log::log( 'upg_10114 lang loop: ' . $e->getMessage(), 'gdcatalog_upg_10114' ); } catch ( \Throwable ) {} }

		/* 3. Cache purge so the updated classifier PHP loads. */
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
