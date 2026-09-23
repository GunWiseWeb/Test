<?php
/**
 * @brief  GD Dealer Manager — upgrade 1.0.341
 *         Fix Add-to-Catalog schema mismatch + default new items to Review Queue.
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 * Rule #33 (standing session): do NOT call CanonicalTemplates::ensure().
 *
 * WHAT SHIPS IN 1.0.341
 *   Two related fixes for the Unmatched UPCs → Add to Catalog flow:
 *
 *   1. modules/admin/dealers/unmatched.php::addToCatalog()
 *        The insert was writing created_at + updated_at, which do
 *        NOT exist on gd_catalog (schema uses last_updated).
 *        Every Add-to-Catalog attempt hit:
 *          2GDD/4 Unknown column 'created_at' in 'INSERT INTO'
 *        Fix: replace the two bogus fields with last_updated=$now.
 *
 *   2. Same method now defaults record_status='admin_review' so
 *        newly-added products land in gdcatalog's Review Queue for
 *        a completeness/category verification pass before going
 *        live on the front-end — addressing "unmatched UPCs aren't
 *        showing up in the Review Queue." An admin who wants a
 *        product to publish immediately checks the new
 *        "Publish immediately (skip Review Queue)" box added to
 *        the Add-to-Catalog form.
 *
 *   Template change:
 *     dev/html/admin/dealers/unmatchedUpcReview.phtml gains a
 *     publish_now checkbox above the submit button. Default off
 *     (i.e. new products go to Review Queue).
 *
 *   NO schema change. NO extension change. NO lang key change
 *   (the checkbox label is plain string in the template).
 *
 * WHAT THIS UPGRADE DOES
 *   1. Walks dev/html/*.phtml and replaces every row in
 *      core_theme_templates. This is the same pattern upg_10340
 *      used and ensures the updated unmatchedUpcReview template
 *      lands on existing installs.
 *   2. Full datastore / template-store / opcache purge + rotate
 *      set_cache_key so compiled template classes rebuild.
 *
 * Rule #79: upg_10340 removed, exactly one upg dir per app.
 */

namespace IPS\gddealer\setup\upg_10341;

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
		$app     = 'gddealer';
		$version = '1.0.341';
		$root    = \IPS\ROOT_PATH . '/applications/' . $app . '/dev/html';

		if ( is_dir( $root ) )
		{
			try
			{
				$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
				foreach ( $it as $f )
				{
					if ( !$f->isFile() || strtolower( $f->getExtension() ) !== 'phtml' ) { continue; }
					$rel = trim( str_replace( $root, '', $f->getPathname() ), "/\\" );
					$parts = preg_split( '#[/\\\\]#', $rel );
					if ( count( $parts ) < 3 ) { continue; }
					$location = (string) $parts[0];
					$group    = (string) $parts[1];
					$name     = pathinfo( (string) end( $parts ), PATHINFO_FILENAME );
					$raw      = (string) @file_get_contents( $f->getPathname() );
					if ( $raw === '' ) { continue; }
					$params = '';
					if ( preg_match( '#<ips:template\s+parameters="([^"]*)"\s*/>#', $raw, $m ) )
					{
						$params = (string) $m[1];
					}
					$content = preg_replace( '#^\s*<ips:template[^>]*/>\s*\r?\n?#', '', $raw, 1 );

					try
					{
						\IPS\Db::i()->replace( 'core_theme_templates', [
							'template_set_id'   => 1,
							'template_app'      => $app,
							'template_location' => $location,
							'template_group'    => $group,
							'template_name'     => $name,
							'template_data'     => $params,
							'template_updated'  => time(),
							'template_version'  => $version,
							'template_content'  => (string) $content,
						] );
					}
					catch ( \Throwable $e )
					{
						try { \IPS\Log::log( 'upg_10341 tpl (' . $name . '): ' . $e->getMessage(), 'gddealer_upg_10341' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10341 tpl loop: ' . $e->getMessage(), 'gddealer_upg_10341' ); } catch ( \Throwable ) {}
			}
		}

		/* Cache / datastore / opcache purge + rotate set_cache_key. */
		try { \IPS\Db::i()->delete( 'core_cache' ); }                                                                catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'theme_%' OR store_key LIKE 'template_%'" ] ); } catch ( \Throwable ) {}
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/template_*' ) ?: [] as $x ) { @unlink( $x ); }
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->themes ); }             catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Db::i()->update( 'core_themes', [ 'set_cache_key' => md5( microtime() . mt_rand() ) ] ); } catch ( \Throwable ) {}
		try { \IPS\Theme::deleteCompiledTemplate(); } catch ( \Throwable ) {}
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/theme_*' ) ?: [] as $x ) { @unlink( $x ); }
		try { \IPS\Theme::master()->recompileTemplates(); } catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
