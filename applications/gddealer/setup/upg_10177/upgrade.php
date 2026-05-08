<?php
namespace IPS\gddealer\setup\upg_10177;

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
		/* v1.0.177 - My Dealers profile tab.
		 *
		 * New extension at extensions/core/Profile/MyDealers.php that
		 * adds a tab to public IPS member profiles listing dealers the
		 * member follows. IPS picks it up automatically on next request
		 * once the extensions cache is flushed.
		 *
		 * Lang strings:
		 *   core_profile_mydealers - tab title displayed in the profile
		 *
		 * Per CLAUDE.md rule #5/#39: lang.xml only fires on fresh install.
		 * For existing installs (Derrick's case) we seed core_sys_lang_words
		 * directly here per CLAUDE.md rule #43 (6-column schema) and rule #44
		 * (per-row try/catch).
		 *
		 * Per CLAUDE.md rule #16: extensions cache must be cleared so IPS
		 * picks up the new MyDealers extension. */

		$newStrings = [
			'core_profile_mydealers' => 'My Dealers',
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
							'word_key'     => $key,
							'word_default' => $val,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
					catch ( \Throwable $rowException )
					{
						try { \IPS\Log::log( 'v1.0.177 lang seed failed key=' . $key . ': ' . $rowException->getMessage(), 'gddealer_upg_10177' ); } catch ( \Throwable ) {}
					}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'v1.0.177 lang seed outer failed: ' . $e->getMessage(), 'gddealer_upg_10177' ); } catch ( \Throwable ) {}
		}

		/* Cache invalidation - extensions cache must be cleared so IPS
		 * picks up the new MyDealers Profile extension. */
		try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'extensions%' OR store_key LIKE 'theme_%' OR store_key LIKE 'template_%'" ] ); } catch ( \Throwable ) {}

		try { unset( \IPS\Data\Store::i()->extensions );   } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings );     } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll();            } catch ( \Throwable ) {}

		return TRUE;
	}

	public function step1CustomTitle()
	{
		return 'v1.0.177 - My Dealers profile tab + extension cache flush';
	}
}

class upgrade extends _upgrade {}
