<?php
/**
 * @brief  GD Dealer Manager — upgrade 1.0.342
 *         Unmatched UPCs list: status column, "already added" toggle,
 *         hide-processed-by-default.
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 * Rule #33 (standing session): do NOT call CanonicalTemplates::ensure().
 *
 * WHAT SHIPS IN 1.0.342
 *   Follow-up to 1.0.341. That version fixed the Add-to-Catalog
 *   bug + routed new items into the Review Queue, but the
 *   Unmatched UPCs admin list still showed already-added UPCs
 *   with the same Add-to-Catalog / Review / Exclude buttons —
 *   admins couldn't visually tell which UPCs still needed work.
 *
 *   Changes:
 *
 *   1. sources/Unmatched/UnmatchedUpc.php
 *        loadAll() gains a $includeAdded parameter (default false)
 *        that filters out rows whose status is 'added_to_catalog'.
 *        New static countAdded() + countPending() helpers back the
 *        list header numbers and the toggle-button label.
 *
 *   2. modules/admin/dealers/unmatched.php::manage()
 *        Reads show_added=1 query param, passes to loadAll, exposes
 *        status + is_added + catalog_edit_url on each row, computes
 *        addedCount / toggle URLs and passes them to the template.
 *
 *   3. dev/html/admin/dealers/unmatchedList.phtml
 *        New Status column (green "Added to catalog" badge or grey
 *        "Pending" badge). Rows with is_added=true render on a muted
 *        grey background. Actions column swaps Review/Add/Exclude
 *        for View-in-Catalog/Exclude on added rows. New toggle
 *        button "Show already added (N)" / "Hide already added"
 *        next to the existing All / Dealer-reported filter buttons.
 *
 *   Behaviour: by default admins now see only pending UPCs — the
 *   ones that still need to be reviewed or added. Toggling "Show
 *   already added" includes historical rows for reference.
 *
 *   NO schema change. NO extension change. NO new lang key. The
 *   `status` column on gd_unmatched_upcs already existed and was
 *   already being written by addToCatalog — this version just
 *   surfaces it in the UI.
 *
 * WHAT THIS UPGRADE DOES
 *   1. Walks dev/html/*.phtml and replaces every row in
 *      core_theme_templates (same pattern upg_10340 / upg_10341
 *      used). Ensures the updated unmatchedList template lands
 *      on existing installs.
 *   2. Full datastore / template-store / opcache purge + rotate
 *      set_cache_key so compiled classes rebuild.
 *
 * Rule #79: upg_10341 removed, exactly one upg dir per app.
 */

namespace IPS\gddealer\setup\upg_10342;

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
		$version = '1.0.342';
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
						try { \IPS\Log::log( 'upg_10342 tpl (' . $name . '): ' . $e->getMessage(), 'gddealer_upg_10342' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10342 tpl loop: ' . $e->getMessage(), 'gddealer_upg_10342' ); } catch ( \Throwable ) {}
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
