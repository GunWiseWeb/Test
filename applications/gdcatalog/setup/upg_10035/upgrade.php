<?php
namespace IPS\gdcatalog\setup\upg_10035;

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
		/* gdcatalog v1.0.35 - Missing image_url language strings.
		 *
		 * v1.0.34 added image_url to edit()'s editableFields array so admins
		 * can fix broken image URLs from the edit form. But the form field
		 * renders 'gdcatalog_product_image_url' as its label and that lang
		 * key was never defined - the bare key was showing on screen.
		 *
		 * Same issue v1.0.32 fixed for 'gdcatalog_save_manual'. Fixing this
		 * one too plus adding the _desc helper string.
		 *
		 * No PHP changes. No template changes. Just lang strings inserted
		 * directly into core_sys_lang_words for immediate effect.
		 *
		 * Per CLAUDE.md memory: core_sys_lang_words uses ONLY 6 columns
		 * (lang_id, word_app, word_key, word_default, word_js, word_export).
		 * Wrapped in per-row try/catch.
		 *
		 * Per CLAUDE.md rule #51: sanity check vs PREVIOUS version (10034). */

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
				'gdcatalog v1.0.35 sanity (pre-version-write): app_long_version=%d, app_version=%s',
				$longVer,
				(string) ( $row['app_version'] ?? '' )
			);
			try { \IPS\Log::log( $msg, 'gdcatalog_upg_10035' ); } catch ( \Throwable ) {}

			if ( $longVer < 10034 )
			{
				$warning = sprintf(
					'gdcatalog v1.0.35 WARNING: app_long_version=%d below 10034',
					$longVer
				);
				try { \IPS\Log::log( $warning, 'gdcatalog_upg_10035' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.35 sanity check failed: ' . $e->getMessage(), 'gdcatalog_upg_10035' ); } catch ( \Throwable ) {}
		}

		/* Step 2: Insert lang strings into core_sys_lang_words. */
		$langStrings = [
			'gdcatalog_product_image_url'      => 'Image URL',
			'gdcatalog_product_image_url_desc' => 'URL of product image. Use "View full size" at the top of this page to preview after saving. Must start with http:// or https://.',
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
						try { \IPS\Log::log( sprintf( 'gdcatalog v1.0.35 lang insert failed for %s (lang_id=%d): %s', $key, $langId, $e->getMessage() ), 'gdcatalog_upg_10035' ); } catch ( \Throwable ) {}
					}
				}
			}
			try { \IPS\Log::log( sprintf( 'gdcatalog v1.0.35 inserted %d language strings', count( $langStrings ) ), 'gdcatalog_upg_10035' ); } catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.35 lang_id select failed: ' . $e->getMessage(), 'gdcatalog_upg_10035' ); } catch ( \Throwable ) {}
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

		/* Rebuild lang cache */
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
		return 'gdcatalog v1.0.35 - missing image_url language strings';
	}
}

class upgrade extends _upgrade {}
