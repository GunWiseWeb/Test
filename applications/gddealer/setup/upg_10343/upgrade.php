<?php
/**
 * @brief  GD Dealer Manager — upgrade 1.0.343
 *         Unmatched UPCs: snapshot fallback on direct Add-to-Catalog,
 *         status stays visible on the list, backfill orphan empty rows.
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 * Rule #33 (standing session): do NOT call CanonicalTemplates::ensure().
 *
 * WHAT SHIPS IN 1.0.343
 *   Follow-up to 1.0.342. Two problems the admin reported after
 *   deploying that version:
 *
 *   1. Direct "Add to Catalog" click on the Unmatched UPCs list
 *      created a Review Queue row with just the UPC and every
 *      other field empty ("(no title)" in the queue). The button
 *      submits no form body, so addToCatalog was reading blank
 *      request fields for title/brand/model/etc. and array_filter
 *      dropped them all before insert.
 *
 *   2. v1.0.342 hid already-added rows from the Unmatched list by
 *      default. The admin wanted to SEE them, with a visible
 *      indicator ("Pending Review") on the button so they could
 *      track progress at a glance without rows disappearing.
 *
 *   Code changes:
 *
 *   - modules/admin/dealers/unmatched.php::addToCatalog()
 *       Now loads snapshot_json first and uses each snapshot key
 *       as the default value, then lets any request field
 *       override it. Direct list-button click gets the dealer's
 *       original data (title, brand, MPN, image, msrp, category,
 *       etc.); Review-form submission still overrides on save.
 *       Brand falls back to snapshot['manufacturer'] when the
 *       snapshot only carries the long form.
 *
 *   - modules/admin/dealers/unmatched.php::manage()
 *       Inverted the show/hide default. hide_added=1 declutters;
 *       default keeps added rows visible so the admin sees status
 *       inline on the same list.
 *
 *   - dev/html/admin/dealers/unmatchedList.phtml
 *       Rows with is_added render with muted grey background + a
 *       green "In Review Queue" badge in Status column, and the
 *       Actions column shows a "Pending Review →" pill that
 *       links straight to gdcatalog's product edit form.
 *       Header count wording updated. Toggle button becomes
 *       "Hide already added (N)" when they're visible.
 *
 *   Data change (this upgrade, ONE-TIME):
 *
 *   - Retroactive backfill on gd_catalog rows that were created
 *     by the buggy direct-add path: any row where
 *     primary_source='admin', title IS NULL / empty, AND a
 *     matching gd_unmatched_upcs row exists with a non-empty
 *     snapshot_json — copy title / brand / model / mpn / caliber
 *     / image_url / description / msrp / category_id from the
 *     snapshot into the gd_catalog row. Fills the ones already
 *     sitting in your Review Queue as (no title) so you don't
 *     have to hand-retype what the dealer already told us.
 *     Idempotent — subsequent runs find no more rows because
 *     title !== ''.
 *
 *   NO schema change. NO extension change. NO new lang key.
 *
 * WHAT THIS UPGRADE DOES
 *   1. Retroactive snapshot backfill on gd_catalog stubs created
 *      by the pre-1.0.343 addToCatalog bug.
 *   2. Walks dev/html/*.phtml and replaces every row in
 *      core_theme_templates (same pattern as recent upgrades).
 *   3. Full datastore / template-store / opcache purge + rotate
 *      set_cache_key so compiled classes rebuild.
 *
 * Rule #79: upg_10342 removed, exactly one upg dir per app.
 */

namespace IPS\gddealer\setup\upg_10343;

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
		$version = '1.0.343';
		$root    = \IPS\ROOT_PATH . '/applications/' . $app . '/dev/html';

		/* -------- Retroactive snapshot backfill on empty admin stubs -------- */
		try
		{
			if ( \IPS\Db::i()->checkForTable( 'gd_catalog' )
				&& \IPS\Db::i()->checkForTable( 'gd_unmatched_upcs' ) )
			{
				$fixed = 0;
				/* Only pick admin-sourced rows with blank titles — those
				 * are the direct-add stubs. Skip any row that already
				 * has a title (an admin may have edited it since). */
				$rs = \IPS\Db::i()->select(
					'upc, title, brand, model, mpn, caliber, image_url, description, msrp, category_id',
					'gd_catalog',
					[ "primary_source=? AND ( title IS NULL OR title='' )", 'admin' ]
				);
				foreach ( $rs as $cat )
				{
					$upc = (string) $cat['upc'];
					if ( $upc === '' ) { continue; }
					try
					{
						$snapshotJson = (string) \IPS\Db::i()->select(
							'snapshot_json', 'gd_unmatched_upcs', [ 'upc=?', $upc ]
						)->first();
					}
					catch ( \Throwable ) { continue; }
					if ( $snapshotJson === '' ) { continue; }
					try { $snapshot = json_decode( $snapshotJson, true ) ?: []; }
					catch ( \Throwable ) { $snapshot = []; }
					if ( empty( $snapshot ) ) { continue; }

					$update = [];
					$map = [
						'title'       => 'title',
						'brand'       => 'brand',
						'model'       => 'model',
						'mpn'         => 'mpn',
						'caliber'     => 'caliber',
						'image_url'   => 'image_url',
						'description' => 'description',
					];
					foreach ( $map as $catCol => $snapKey )
					{
						/* Only write when the catalog cell is empty and
						 * the snapshot has something. Preserves any
						 * admin edits made since the buggy insert. */
						$catVal = (string) ( $cat[ $catCol ] ?? '' );
						if ( $catVal !== '' ) { continue; }
						$snapVal = trim( (string) ( $snapshot[ $snapKey ] ?? '' ) );
						if ( $snapVal === '' && $catCol === 'brand' )
						{
							$snapVal = trim( (string) ( $snapshot['manufacturer'] ?? '' ) );
						}
						if ( $snapVal === '' ) { continue; }
						$update[ $catCol ] = $snapVal;
					}
					/* msrp / category_id are numeric — separate handling
					 * because empty string vs 0 differs. */
					if ( ( (float) ( $cat['msrp'] ?? 0 ) ) <= 0 )
					{
						$msrp = (float) ( $snapshot['msrp'] ?? 0 );
						if ( $msrp > 0 ) { $update['msrp'] = $msrp; }
					}
					if ( ( (int) ( $cat['category_id'] ?? 0 ) ) === 0 )
					{
						$cid = (int) ( $snapshot['category_id'] ?? 0 );
						if ( $cid > 0 ) { $update['category_id'] = $cid; }
					}

					if ( !empty( $update ) )
					{
						$update['last_updated'] = date( 'Y-m-d H:i:s' );
						try
						{
							\IPS\Db::i()->update( 'gd_catalog', $update, [ 'upc=?', $upc ] );
							$fixed++;
						}
						catch ( \Throwable $e )
						{
							try { \IPS\Log::log( 'upg_10343 backfill upc=' . $upc . ': ' . $e->getMessage(), 'gddealer_upg_10343' ); } catch ( \Throwable ) {}
						}
					}
				}
				try { \IPS\Log::log( 'upg_10343 snapshot backfill: fixed ' . $fixed . ' rows', 'gddealer_upg_10343' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10343 backfill outer: ' . $e->getMessage(), 'gddealer_upg_10343' ); } catch ( \Throwable ) {}
		}

		/* -------- Template resync from dev/html/ -------- */
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
						try { \IPS\Log::log( 'upg_10343 tpl (' . $name . '): ' . $e->getMessage(), 'gddealer_upg_10343' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10343 tpl loop: ' . $e->getMessage(), 'gddealer_upg_10343' ); } catch ( \Throwable ) {}
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
