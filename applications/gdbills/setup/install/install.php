<?php
/**
 * @brief  GD Bills — Install routine
 *
 * Runs after schema.json tables are created. Seeds:
 *   - lang words into core_sys_lang_words (per language)
 *   - syncBills task into core_tasks (daily)
 *   - ACP permission row (bills_manage)
 */

if ( !defined( '\\IPS\\SUITE_UNIQUE_KEY' ) ) { exit; }

/* Seed every dev/lang.php key into core_sys_lang_words per language. */
$langFile = \IPS\ROOT_PATH . '/applications/gdbills/dev/lang.php';
if ( is_readable( $langFile ) )
{
	$lang = [];
	include $langFile;
	if ( is_array( $lang ) && !empty( $lang ) )
	{
		try
		{
			foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
			{
				foreach ( $lang as $key => $val )
				{
					try
					{
						\IPS\Db::i()->replace( 'core_sys_lang_words', [
							'lang_id'      => (int) $langId,
							'word_app'     => 'gdbills',
							'word_key'     => (string) $key,
							'word_default' => (string) $val,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
					catch ( \Throwable ) {}
				}
			}
		}
		catch ( \Throwable ) {}
	}
}

/* Register the daily syncBills task. */
try
{
	$exists = (bool) \IPS\Db::i()->select( 'COUNT(*)', 'core_tasks',
		[ 'app=? AND `key`=?', 'gdbills', 'syncBills' ] )->first();
	if ( !$exists )
	{
		\IPS\Db::i()->insert( 'core_tasks', [
			'app'       => 'gdbills',
			'key'       => 'syncBills',
			'frequency' => 'P1D',
			'next_run'  => time() + 3600,
			'enabled'   => 1,
		] );
	}
}
catch ( \Throwable $e )
{
	try { \IPS\Log::log( 'gdbills install task: ' . $e->getMessage(), 'gdbills_install' ); } catch ( \Throwable ) {}
}

/* Register the ACP permission so the menu items + controllers are reachable. */
try
{
	$apps = (int) \IPS\Db::i()->select( 'COUNT(*)', 'core_admin_permission_rows',
		[ 'app=? AND `key`=?', 'gdbills', 'bills_manage' ] )->first();
	if ( !$apps )
	{
		\IPS\Db::i()->insert( 'core_admin_permission_rows', [
			'app'    => 'gdbills',
			'key'    => 'bills_manage',
			'tab'    => 'gdbills',
		] );
	}
}
catch ( \Throwable ) {}

try { unset( \IPS\Data\Store::i()->acpmenu ); } catch ( \Throwable ) {}
try { \IPS\Data\Store::i()->clearAll(); } catch ( \Throwable ) {}
try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
