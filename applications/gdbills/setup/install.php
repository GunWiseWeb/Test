<?php
/**
 * @brief  GD Bills — top-level install entrypoint.
 *
 * Delegates to the pre-existing setup/install/install.php for
 * lang / task / permission seeding (that file has been the working
 * install code — untouched), then seeds core_theme_templates from
 * dev/html so the site's templates are actually registered on
 * fresh install. Application.php::installOther() now requires
 * this file so IPS's install runner actually invokes it — that
 * hook was previously absent, which is why the app had zero
 * template rows and no lang seeding on fresh install.
 */

if ( !defined( '\\IPS\\SUITE_UNIQUE_KEY' ) ) { exit; }

/* Delegate to the pre-existing nested install for lang / task /
   permission rows — its logic is unchanged. */
require_once \IPS\ROOT_PATH . '/applications/gdbills/setup/install/install.php';

/* Seed core_theme_templates from dev/html/{location}/{group}/{name}.phtml.
   No IPS-native "sync dev/html → prod" call exists in this stack
   (rule #4 forbids theme.xml). Delete-then-insert keyed on
   (app, location, group, name, set_id=1) avoids duplicates.
   Rule #45 safe columns only. */
try
{
	$__gdRoot = \IPS\ROOT_PATH . '/applications/gdbills/dev/html';
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
				\IPS\Db::i()->replace( 'core_theme_templates', [
					'template_set_id'   => 1,
					'template_app'      => 'gdbills',
					'template_location' => $__gdLoc,
					'template_group'    => $__gdGrp,
					'template_name'     => $__gdName,
					'template_data'     => $__gdParams,
					'template_updated'  => time(),
					'template_version'  => '1.0.18',
					'template_content'  => (string) $__gdContent,
				] );
			}
			catch ( \Throwable $__gdE )
			{
				try { \IPS\Log::log( 'gdbills tpl sync (' . $__gdName . '): ' . $__gdE->getMessage(), 'gdbills_tpl_sync' ); } catch ( \Throwable ) {}
			}
		}
	}
}
catch ( \Throwable $__gdE )
{
	try { \IPS\Log::log( 'gdbills tpl sync loop: ' . $__gdE->getMessage(), 'gdbills_tpl_sync' ); } catch ( \Throwable ) {}
}

try { unset( \IPS\Data\Store::i()->themes ); }   catch ( \Throwable ) {}
foreach ( glob( \IPS\ROOT_PATH . '/datastore/template_*' ) ?: [] as $__gdCf ) { @unlink( $__gdCf ); }
try { \IPS\Data\Store::i()->clearAll(); }        catch ( \Throwable ) {}
try { \IPS\Data\Cache::i()->clearAll(); }        catch ( \Throwable ) {}
