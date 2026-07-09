<?php
/**
 * @brief  GD Contact — install routine.
 *
 * IPS creates gd_contact_fields from data/schema.json before this
 * runs. Here we:
 *   * Self-heal the table (defensive).
 *   * Seed the four default fields (Name, Email, Phone, Message)
 *     — idempotent; won't duplicate on re-install.
 *   * Seed dev/lang.php into core_sys_lang_words per language
 *     (rules #43 / #44 shape).
 *   * Purge the extension / application / acpMenu datastore caches.
 */

if ( !defined( '\\IPS\\SUITE_UNIQUE_KEY' ) ) { exit; }

/* -----------------------------------------------------------
 * Self-heal — schema.json creates the table but log if not.
 * ----------------------------------------------------------- */
try
{
	if ( !\IPS\Db::i()->checkForTable( 'gd_contact_fields' ) )
	{
		try { \IPS\Log::log( 'gdcontact install: gd_contact_fields missing after schema pass', 'gdcontact_install' ); } catch ( \Throwable ) {}
	}
}
catch ( \Throwable ) {}

/* -----------------------------------------------------------
 * Seed defaults — only inserts rows for keys that don't
 * already exist so re-installs don't create duplicates.
 * ----------------------------------------------------------- */
$defaults = [
	[ 'field_key' => 'name',    'label' => 'Your name',        'type' => 'text',     'required' => 1, 'position' => 10 ],
	[ 'field_key' => 'email',   'label' => 'Email address',    'type' => 'email',    'required' => 1, 'position' => 20 ],
	[ 'field_key' => 'phone',   'label' => 'Phone (optional)', 'type' => 'phone',    'required' => 0, 'position' => 30, 'placeholder' => '(555) 555-5555' ],
	[ 'field_key' => 'message', 'label' => 'Message',          'type' => 'textarea', 'required' => 1, 'position' => 40 ],
];
foreach ( $defaults as $row )
{
	try
	{
		$existing = 0;
		try
		{
			$existing = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_contact_fields', [ 'field_key=?', $row['field_key'] ] )->first();
		}
		catch ( \Throwable ) {}

		if ( $existing === 0 )
		{
			\IPS\Db::i()->insert( 'gd_contact_fields', [
				'field_key'   => $row['field_key'],
				'label'       => $row['label'],
				'type'        => $row['type'],
				'required'    => (int) $row['required'],
				'position'    => (int) $row['position'],
				'options'     => null,
				'placeholder' => $row['placeholder'] ?? null,
				'help_text'   => null,
				'enabled'     => 1,
			] );
		}
	}
	catch ( \Throwable ) {}
}

/* -----------------------------------------------------------
 * Lang seed — dev/lang.php → core_sys_lang_words per language.
 * Rules #43 (IPS 5.0.18 6-column schema) + #44 (per-row catch).
 * ----------------------------------------------------------- */
$langFile = \IPS\ROOT_PATH . '/applications/gdcontact/dev/lang.php';
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
							'word_app'     => 'gdcontact',
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

/* -----------------------------------------------------------
 * Cache purge — so IPS re-parses modules_admin / furl on the
 * next request and the ACP menu appears immediately.
 * ----------------------------------------------------------- */
try { unset( \IPS\Data\Store::i()->applications ); }  catch ( \Throwable ) {}
try { unset( \IPS\Data\Store::i()->extensions ); }    catch ( \Throwable ) {}
try { unset( \IPS\Data\Store::i()->modules_admin ); } catch ( \Throwable ) {}
try { unset( \IPS\Data\Store::i()->modules_front ); } catch ( \Throwable ) {}
try { unset( \IPS\Data\Store::i()->furl ); }          catch ( \Throwable ) {}
try { unset( \IPS\Data\Store::i()->acpMenu ); }       catch ( \Throwable ) {}
try { unset( \IPS\Data\Store::i()->settings ); }      catch ( \Throwable ) {}
try { \IPS\Data\Store::i()->clearAll(); }             catch ( \Throwable ) {}
try { \IPS\Data\Cache::i()->clearAll(); }             catch ( \Throwable ) {}
if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }
