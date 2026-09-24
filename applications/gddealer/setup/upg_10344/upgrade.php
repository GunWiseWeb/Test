<?php
/**
 * @brief  GD Dealer Manager — upgrade 1.0.344
 *         Unmatched UPCs: bulk-add + bulk-exclude selected UPCs.
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 * Rule #33 (standing session): do NOT call CanonicalTemplates::ensure().
 *
 * WHAT SHIPS IN 1.0.344
 *   Admin was doing one-click-at-a-time Add-to-Catalog on hundreds
 *   of dealer UPCs. This ships bulk actions on the Unmatched UPCs
 *   admin list: check the pending rows you want, click "Add
 *   Selected to Review Queue" (or "Exclude Selected"), and they're
 *   all processed in one POST.
 *
 *   Code changes:
 *
 *   - modules/admin/dealers/unmatched.php
 *       NEW protected _promoteToCatalog(int $id, bool $publishNow,
 *       array $overrides): array — shared helper that does the DB
 *       work of promoting one gd_unmatched_upcs row to a gd_catalog
 *       Review Queue row. Returns {status, upc, message} instead of
 *       redirecting so callers can aggregate stats across many rows.
 *       Snapshot fallback (v1.0.343) and admin_review default
 *       (v1.0.341) preserved.
 *
 *       addToCatalog() refactored to collect Review-form overrides
 *       into an array and delegate to _promoteToCatalog. Redirect
 *       behaviour unchanged for single-row callers.
 *
 *       NEW protected bulkAdd() — CSRF-protected POST endpoint.
 *       Reads ids[] and optional publish_now=1, iterates through
 *       up to 200 per request (PHP timeout safety on huge feeds),
 *       calls _promoteToCatalog for each, redirects with a summary
 *       flash: "Added N, already in catalog M, errors K." When the
 *       selection exceeds the cap the flash notes how many are left.
 *
 *       NEW protected bulkExclude() — companion for cleaning junk
 *       UPCs off the list in one click. Sets admin_excluded=1 on
 *       each selected row. Does NOT touch gd_catalog.
 *
 *   - dev/html/admin/dealers/unmatchedList.phtml
 *       Table wrapped in a form pointing at bulkAdd. NEW checkbox
 *       column at the far left (hidden on already-added rows). NEW
 *       bulk-action toolbar above the table with "Select all
 *       pending on this page", a running "N selected" counter, a
 *       "Publish immediately (skip Review Queue)" toggle, and two
 *       buttons: "Add Selected to Review Queue" (formaction =
 *       bulkAdd, positive) and "Exclude Selected" (formaction =
 *       bulkExclude, negative). Both buttons prompt for confirm
 *       before submitting. Inline JS handles the select-all sync
 *       and count display — no jQuery dependency.
 *
 *       colspan on the empty-state row bumped to 9 to match the
 *       new checkbox column.
 *
 *   Controller passes two new template params: bulkAddUrl (csrf-
 *   baked, POST-and-redirect so it's rule #62 clean) and
 *   bulkExcludeUrl.
 *
 *   NO schema change. NO extension change. NO new lang key.
 *
 * WHAT THIS UPGRADE DOES
 *   1. Walks dev/html/*.phtml and replaces every row in
 *      core_theme_templates (same pattern as recent upgrades).
 *      Picks up the new unmatchedList body on existing installs.
 *   2. Full datastore / template-store / opcache purge + rotate
 *      set_cache_key so compiled classes rebuild.
 *
 * Rule #79: upg_10343 removed, exactly one upg dir per app.
 */

namespace IPS\gddealer\setup\upg_10344;

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
		$version = '1.0.344';
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
						try { \IPS\Log::log( 'upg_10344 tpl (' . $name . '): ' . $e->getMessage(), 'gddealer_upg_10344' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10344 tpl loop: ' . $e->getMessage(), 'gddealer_upg_10344' ); } catch ( \Throwable ) {}
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
