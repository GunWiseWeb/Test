<?php
namespace IPS\gdcatalog\setup\upg_10032;

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
		/* gdcatalog v1.0.32 - Lock visibility fixes.
		 *
		 * Three fixes for the v1.0.31 lock UI:
		 *
		 *   1. Add missing language string gdcatalog_save_manual - the bare
		 *      key was rendering as the save button label.
		 *
		 *   2. Fix the "Locked Fields" notice in edit() to read from the
		 *      Product->locked_fields JSON column (which v1.0.31 actually
		 *      writes to), NOT from the empty gd_field_locks table.
		 *
		 *   3. Inject "LOCKED" indicators inline with each form field so admins
		 *      can see lock state directly at the input, not just in a top
		 *      banner block.
		 *
		 * All fixes in PHP. This upgrade.php just bumps the version, inserts
		 * the new language strings into core_sys_lang_words (so they take
		 * effect immediately without waiting for the lang.xml installer),
		 * and flushes cache.
		 *
		 * Per CLAUDE.md rule #51: sanity check vs PREVIOUS version (10031). */

		/* Step 1: Sanity check */
		try
		{
			$row = \IPS\Db::i()->select(
				'app_long_version, app_version',
				'core_applications',
				[ 'app_directory=?', 'gdcatalog' ]
			)->first();

			$longVer = (int) ( $row['app_long_version'] ?? 0 );
			$msg = sprintf(
				'gdcatalog v1.0.32 sanity (pre-version-write): app_long_version=%d, app_version=%s',
				$longVer,
				(string) ( $row['app_version'] ?? '' )
			);
			try { \IPS\Log::log( $msg, 'gdcatalog_upg_10032' ); } catch ( \Throwable ) {}

			if ( $longVer < 10031 )
			{
				$warning = sprintf(
					'gdcatalog v1.0.32 WARNING: app_long_version=%d below 10031',
					$longVer
				);
				try { \IPS\Log::log( $warning, 'gdcatalog_upg_10032' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.32 sanity check failed: ' . $e->getMessage(), 'gdcatalog_upg_10032' ); } catch ( \Throwable ) {}
		}

		/* Step 2: Insert language strings directly into core_sys_lang_words.
		 *
		 * Per CLAUDE.md memory: core_sys_lang_words uses ONLY 6 columns
		 * (lang_id, word_app, word_key, word_default, word_js, word_export).
		 * Wrapped in per-row try/catch so a single failure doesn't abort. */
		$langStrings = [
			'gdcatalog_save_manual'           => 'Save Product',
			'gdcatalog_product_lock_warning'  => '🔒 This field is locked. Distributor imports cannot overwrite it.',
			'gdcatalog_bulk_lock_label'       => 'Bulk Lock',
			'gdcatalog_lock_all_fields'       => 'Lock All Populated Fields',
			'gdcatalog_unlock_all_fields'     => 'Unlock All Fields',
			'gdcatalog_add_product_btn'       => '+ Add Product',
		];

		try
		{
			foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langRow )
			{
				$langId = (int) $langRow['lang_id'];
				foreach ( $langStrings as $key => $default )
				{
					try
					{
						\IPS\Db::i()->replace( 'core_sys_lang_words', [
							'lang_id'      => $langId,
							'word_app'     => 'gdcatalog',
							'word_key'     => $key,
							'word_default' => $default,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
					catch ( \Throwable $e )
					{
						try { \IPS\Log::log( sprintf( 'gdcatalog v1.0.32 lang insert failed for %s (lang_id=%d): %s', $key, $langId, $e->getMessage() ), 'gdcatalog_upg_10032' ); } catch ( \Throwable ) {}
					}
				}
			}
			try { \IPS\Log::log( sprintf( 'gdcatalog v1.0.32 inserted %d language strings', count( $langStrings ) ), 'gdcatalog_upg_10032' ); } catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.32 lang_id select failed: ' . $e->getMessage(), 'gdcatalog_upg_10032' ); } catch ( \Throwable ) {}
		}

		/* Step 3: Cache invalidation */
		try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store' ); } catch ( \Throwable ) {}

		foreach ( glob( \IPS\ROOT_PATH . '/datastore/*.php' ) ?: [] as $f )
		{
			@unlink( $f );
		}
		foreach ( glob( \IPS\ROOT_PATH . '/static/templates/*.php' ) ?: [] as $f )
		{
			@unlink( $f );
		}

		try { unset( \IPS\Data\Store::i()->extensions );   } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll();            } catch ( \Throwable ) {}

		/* Rebuild lang cache so the new strings are picked up immediately */
		try
		{
			foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langRow )
			{
				try { \IPS\Lang::load( (int) $langRow['lang_id'] )->rebuild(); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable ) {}

		return TRUE;
	}

	public function step1CustomTitle()
	{
		return 'gdcatalog v1.0.32 - lock visibility fixes';
	}
}

class upgrade extends _upgrade {}
