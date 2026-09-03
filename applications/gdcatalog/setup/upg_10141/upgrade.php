<?php
/**
 * @brief  GD Master Catalog — upgrade 1.0.141
 *         Expand CSV export + default field_mapping to cover firearm/
 *         shotgun/ammo/optic detail fields.
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 *
 * WHAT SHIPS IN 1.0.141
 *   The gd_catalog schema already had columns for magnification,
 *   objective_mm, reticle, tube_diameter, eye_relief, features,
 *   gun_type, safety_type, trigger_type, metal_finish, frame_finish,
 *   stock_material, stock_type, sight_type, grips, hammer_style,
 *   receiver_type/desc, frame_material, slide_material, gauge,
 *   choke_config, chamber, bullet_type, bullet_weight,
 *   muzzle_velocity, muzzle_energy, boxes_per_case, casing_material,
 *   mpn, weight_lbs — but the Review Queue CSV export and the
 *   default manual-upload field_mapping only listed a firearm-
 *   general subset. Result: enriching an optic (or any non-general
 *   product type) through the AI CSV round-trip left the type-
 *   specific edit-form fields empty because there was no column
 *   in the CSV for the AI to write into, and even if it added
 *   one the default mapping would drop it.
 *
 *   Code changes:
 *     - MOD  modules/admin/catalog/reviewqueue.php
 *            — CSV_EXPORT_COLUMNS expanded from 26 to 57 columns.
 *              Grouped by identity / common / flags / firearm /
 *              shotgun / ammo / optic / informational.
 *     - MOD  modules/admin/catalog/feeds.php
 *            — MANUAL_UPLOAD_DEFAULT_FIELD_MAPPING expanded to the
 *              same canonical set (informational trailers omitted).
 *              Applies to NEW manual sources on first display of
 *              the edit form. Existing sources with a saved mapping
 *              are unchanged (design intent — don't overwrite
 *              admin's saved config).
 *     - MOD  sources/Feed/FieldMapper.php
 *            — VALID_FIELDS docstring constant expanded to reflect
 *              reality. Documentation only; the actual import
 *              filter is Importer::catalogColumns() which reads
 *              from schema.json.
 *
 *   ACTION REQUIRED for existing manual sources:
 *     Because the default pre-fill only applies when field_mapping
 *     is empty, existing manual sources (like AI Data Sheet CSV)
 *     will keep their old smaller mapping. To pick up the new
 *     columns: Sources → Edit → clear the Field Mapping textarea
 *     → Save → Edit again (pre-fill loads new default) → Save.
 *     Or paste the expanded JSON directly.
 *
 *   NO schema change. NO extension/task registration change. NO
 *   new lang key. NO importer/adapter/queue behaviour change.
 *
 * WHAT THIS UPGRADE DOES (idempotent, safe to re-run)
 *   1. Idempotent 1.0.130 schema hoist.
 *   2. Seeds the four accumulated lang keys.
 *   3. Re-seeds every dev/html/*.phtml.
 *   4. Cache / datastore / opcache purge.
 *
 * Rule #79: upg_10140 removed, exactly one upg dir per app.
 */

namespace IPS\gdcatalog\setup\upg_10141;

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
		$app     = 'gdcatalog';
		$version = '1.0.141';
		$root    = \IPS\ROOT_PATH . '/applications/' . $app . '/dev/html';

		/* -------- 1.0.130 schema hoist (idempotent) -------- */
		try
		{
			if ( \IPS\Db::i()->checkForTable( 'gd_distributor_feeds' )
				&& !\IPS\Db::i()->checkForColumn( 'gd_distributor_feeds', 'mark_imports_as_review' ) )
			{
				\IPS\Db::i()->addColumn( 'gd_distributor_feeds', [
					'name'       => 'mark_imports_as_review',
					'type'       => 'TINYINT',
					'length'     => 1,
					'allow_null' => false,
					'default'    => 0,
					'unsigned'   => true,
				] );
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10141 addColumn: ' . $e->getMessage(), 'gdcatalog_upg_10141' ); } catch ( \Throwable ) {}
		}

		/* -------- Lang seed (accumulated from 1.0.130 + 1.0.132) -------- */
		$newStrings = [
			'gdcatalog_feed_mark_imports_as_review'      => 'Send new products to Review Queue',
			'gdcatalog_feed_mark_imports_as_review_desc' => "When ON, products this source creates are held with record_status='admin_review' and hidden from the front-end until an admin promotes them via the Review Queue admin page. Existing catalog products updated by this source are unaffected. Use for low-quality dealer/backfill feeds.",
			'menu__gdcatalog_catalog_reviewqueue'        => 'Review Queue',
			'menu__gdcatalog_catalog_categorize'         => 'Categorize',
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
							'word_app'     => $app,
							'word_key'     => $key,
							'word_default' => $val,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
					catch ( \Throwable $e )
					{
						try { \IPS\Log::log( 'upg_10141 lang (' . $key . '): ' . $e->getMessage(), 'gdcatalog_upg_10141' ); } catch ( \Throwable ) {}
					}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10141 lang loop: ' . $e->getMessage(), 'gdcatalog_upg_10141' ); } catch ( \Throwable ) {}
		}

		/* -------- Template resync (rule #52 + #79) -------- */
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
						try { \IPS\Log::log( 'upg_10141 tpl (' . $name . '): ' . $e->getMessage(), 'gdcatalog_upg_10141' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10141 tpl loop: ' . $e->getMessage(), 'gdcatalog_upg_10141' ); } catch ( \Throwable ) {}
			}
		}

		/* -------- Cache / datastore / opcache purge (rule #40) -------- */
		try { \IPS\Db::i()->delete( 'core_cache' ); }                                                                catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'theme_%' OR store_key LIKE 'template_%' OR store_key LIKE 'acpmenu%' OR store_key LIKE 'menu_%' OR store_key LIKE 'lang_%'" ] ); } catch ( \Throwable ) {}
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/template_*' ) ?: [] as $x ) { @unlink( $x ); }
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/acpmenu_*' ) ?: [] as $x ) { @unlink( $x ); }
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/lang_*' ) ?: [] as $x ) { @unlink( $x ); }
		try { unset( \IPS\Data\Store::i()->themes ); }             catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->acpMenu ); }            catch ( \Throwable ) {}
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
