<?php
/**
 * @brief  GD Compliance — top-level install entrypoint.
 *
 * Delegates to the pre-existing setup/install/install.php for
 * ruleset / AWB model / lang / notification / permission seeding
 * (that file has been the working install code — untouched), then
 * seeds core_theme_templates from dev/html so the front-side
 * restriction-badge / restriction-notice templates are actually
 * registered on fresh install. Application.php::installOther()
 * now requires this file so IPS's install runner actually invokes
 * it — that hook was previously absent.
 */

if ( !defined( '\\IPS\\SUITE_UNIQUE_KEY' ) ) { exit; }

/* Delegate to the pre-existing nested install for ruleset / lang /
   notification / permission rows — its logic is unchanged. */
require_once \IPS\ROOT_PATH . '/applications/gdcompliance/setup/install/install.php';

/* Seed core_theme_templates from dev/html/{location}/{group}/{name}.phtml.
   No IPS-native "sync dev/html → prod" call exists in this stack
   (rule #4 forbids theme.xml). Delete-then-insert keyed on
   (app, location, group, name, set_id=1) avoids duplicates.
   Rule #45 safe columns only. */
try
{
	$__gdRoot = \IPS\ROOT_PATH . '/applications/gdcompliance/dev/html';
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
					'gdcompliance', $__gdLoc, $__gdGrp, $__gdName, 1
				] );
			}
			catch ( \Throwable ) {}
			try
			{
				\IPS\Db::i()->insert( 'core_theme_templates', [
					'template_set_id'         => 1,
					'template_app'            => 'gdcompliance',
					'template_location'       => $__gdLoc,
					'template_group'          => $__gdGrp,
					'template_name'           => $__gdName,
					'template_data'           => $__gdParams,
					'template_content'        => (string) $__gdContent,
					'template_updated'        => time(),
					'template_version'        => '1.6.53',
					'template_master_key'     => '',
					'template_has_hookpoints' => 0,
				] );
			}
			catch ( \Throwable $__gdE )
			{
				try { \IPS\Log::log( 'gdcompliance tpl sync (' . $__gdName . '): ' . $__gdE->getMessage(), 'gdcompliance_tpl_sync' ); } catch ( \Throwable ) {}
			}
		}
	}
}
catch ( \Throwable $__gdE )
{
	try { \IPS\Log::log( 'gdcompliance tpl sync loop: ' . $__gdE->getMessage(), 'gdcompliance_tpl_sync' ); } catch ( \Throwable ) {}
}

try { unset( \IPS\Data\Store::i()->themes ); }   catch ( \Throwable ) {}
foreach ( glob( \IPS\ROOT_PATH . '/datastore/template_*' ) ?: [] as $__gdCf ) { @unlink( $__gdCf ); }
try { \IPS\Data\Store::i()->clearAll(); }        catch ( \Throwable ) {}
try { \IPS\Data\Cache::i()->clearAll(); }        catch ( \Throwable ) {}
