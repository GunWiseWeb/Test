<?php
/**
 * @brief  GD Rebates — top-level install entrypoint.
 *
 * Application.php::installOther() now requires this file so IPS's
 * install runner actually invokes it — that hook was previously
 * absent, so this app had zero template rows on fresh install.
 * The only thing this app currently needs at install time is
 * template registration (schema.json handles table creation,
 * data/lang.xml handles lang, no scheduled tasks or permission
 * rows required); seed core_theme_templates from dev/html here.
 */

if ( !defined( '\\IPS\\SUITE_UNIQUE_KEY' ) ) { exit; }

/* Seed core_theme_templates from dev/html/{location}/{group}/{name}.phtml.
   No IPS-native "sync dev/html → prod" call exists in this stack
   (rule #4 forbids theme.xml). Delete-then-insert keyed on
   (app, location, group, name, set_id=1) avoids duplicates.
   Rule #45 safe columns only. */
try
{
	$__gdRoot = \IPS\ROOT_PATH . '/applications/gdrebates/dev/html';
	if ( is_dir( $__gdRoot ) )
	{
		$__gdIt = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $__gdRoot, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $__gdIt as $__gdF )
		{
			if ( !$__gdF->isFile() || strtolower( $__gdF->getExtension() ) !== 'phtml' ) { continue; }
			$__gdRel = trim( str_replace( $__gdRoot, '', $__gdF->getPathname() ), "/\\" );
			$__gdParts = preg_split( '#[/\\\\]#', $__gdRel );
			if ( count( $__gdParts ) < 3 ) { continue; }
			$__gdLoc  = (string) $__gdParts[0];
			$__gdGrp  = (string) $__gdParts[1];
			$__gdName = pathinfo( (string) end( $__gdParts ), PATHINFO_FILENAME );
			$__gdRaw  = (string) @file_get_contents( $__gdF->getPathname() );
			if ( $__gdRaw === '' ) { continue; }
			$__gdParams = '';
			if ( preg_match( '#<ips:template\s+parameters="([^"]*)"\s*/>#', $__gdRaw, $__gdM ) )
			{
				$__gdParams = (string) $__gdM[1];
			}
			$__gdContent = preg_replace( '#^\s*<ips:template[^>]*/>\s*\r?\n?#', '', $__gdRaw, 1 );
			try
			{
				\IPS\Db::i()->delete( 'core_theme_templates', [
					'template_app=? AND template_location=? AND template_group=? AND template_name=? AND template_set_id=?',
					'gdrebates', $__gdLoc, $__gdGrp, $__gdName, 1
				] );
			}
			catch ( \Throwable ) {}
			try
			{
				\IPS\Db::i()->insert( 'core_theme_templates', [
					'template_set_id'         => 1,
					'template_app'            => 'gdrebates',
					'template_location'       => $__gdLoc,
					'template_group'          => $__gdGrp,
					'template_name'           => $__gdName,
					'template_data'           => $__gdParams,
					'template_content'        => (string) $__gdContent,
					'template_updated'        => time(),
					'template_version'        => '1.0.15',
					'template_master_key'     => '',
					'template_has_hookpoints' => 0,
				] );
			}
			catch ( \Throwable $__gdE )
			{
				try { \IPS\Log::log( 'gdrebates tpl sync (' . $__gdName . '): ' . $__gdE->getMessage(), 'gdrebates_tpl_sync' ); } catch ( \Throwable ) {}
			}
		}
	}
}
catch ( \Throwable $__gdE )
{
	try { \IPS\Log::log( 'gdrebates tpl sync loop: ' . $__gdE->getMessage(), 'gdrebates_tpl_sync' ); } catch ( \Throwable ) {}
}

try { unset( \IPS\Data\Store::i()->themes ); }   catch ( \Throwable ) {}
foreach ( glob( \IPS\ROOT_PATH . '/datastore/template_*' ) ?: [] as $__gdCf ) { @unlink( $__gdCf ); }
try { \IPS\Data\Store::i()->clearAll(); }        catch ( \Throwable ) {}
try { \IPS\Data\Cache::i()->clearAll(); }        catch ( \Throwable ) {}
