<?php
/**
 * @brief  GD Master Catalog — upgrade 1.0.143
 *         Product edit form: allow 14-digit GTIN-14 UPCs.
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 *
 * WHAT SHIPS IN 1.0.143
 *   The product edit form's UPC regex was `^[0-9]{8,13}$` — it
 *   rejected legitimate 14-digit GTIN-14 barcodes with "That
 *   value is not allowed" on save. Case-pack ammunition (e.g.
 *   Federal Champion 40 S&W 180gr FMJ bulk 400/1 loose ==
 *   50004544689672) uses GTIN-14 as its retail code. Admin
 *   couldn't edit those rows at all — every save bounced.
 *
 *   Valid retail barcode lengths that MUST be accepted:
 *     UPC-E = 8, UPC-A = 12, EAN-13 = 13, GTIN-14 = 14.
 *
 *   Fix: broaden the regex to `^[0-9]{8,14}$` on the edit form.
 *
 *   NO schema change. NO extension change. NO new lang key.
 *   NO importer/adapter/queue behaviour change. Source-file
 *   only — the tarball ships the corrected controller.
 *
 * WHAT THIS UPGRADE DOES (idempotent, safe to re-run)
 *   1. Idempotent 1.0.130 schema hoist (mark_imports_as_review).
 *   2. Seeds the four accumulated lang keys.
 *   3. Re-seeds every dev/html/*.phtml (belt-and-suspenders — no
 *      template changed in this version).
 *   4. Cache / datastore / opcache purge.
 *
 * Rule #79: upg_10142 removed, exactly one upg dir per app.
 */

namespace IPS\gdcatalog\setup\upg_10143;

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
		$version = '1.0.143';
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
			try { \IPS\Log::log( 'upg_10143 addColumn mark_imports_as_review: ' . $e->getMessage(), 'gdcatalog_upg_10143' ); } catch ( \Throwable ) {}
		}

		/* -------- v1.0.142 audit columns on gd_catalog (idempotent) -------- */
		try
		{
			if ( \IPS\Db::i()->checkForTable( 'gd_catalog' ) )
			{
				$auditCols = [
					'upc_audit_status'      => [ 'type' => 'VARCHAR', 'length' => 64 ],
					'upc_audit_notes'       => [ 'type' => 'TEXT',    'length' => 0  ],
					'suggested_correct_upc' => [ 'type' => 'VARCHAR', 'length' => 32 ],
					'verified_mpn'          => [ 'type' => 'VARCHAR', 'length' => 64 ],
					'upc_audit_source'      => [ 'type' => 'TEXT',    'length' => 0  ],
				];
				foreach ( $auditCols as $colName => $meta )
				{
					try
					{
						if ( !\IPS\Db::i()->checkForColumn( 'gd_catalog', $colName ) )
						{
							\IPS\Db::i()->addColumn( 'gd_catalog', [
								'name'       => $colName,
								'type'       => $meta['type'],
								'length'     => $meta['length'],
								'allow_null' => true,
								'default'    => null,
							] );
						}
					}
					catch ( \Throwable $e )
					{
						try { \IPS\Log::log( 'upg_10143 addColumn ' . $colName . ': ' . $e->getMessage(), 'gdcatalog_upg_10143' ); } catch ( \Throwable ) {}
					}
				}

				try
				{
					if ( !\IPS\Db::i()->checkForIndex( 'gd_catalog', 'idx_upc_audit_status' ) )
					{
						\IPS\Db::i()->addIndex( 'gd_catalog', [
							'type'    => 'key',
							'name'    => 'idx_upc_audit_status',
							'columns' => [ 'upc_audit_status' ],
							'length'  => [ 32 ],
						] );
					}
				}
				catch ( \Throwable $e )
				{
					try { \IPS\Log::log( 'upg_10143 addIndex idx_upc_audit_status: ' . $e->getMessage(), 'gdcatalog_upg_10143' ); } catch ( \Throwable ) {}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10143 audit column bootstrap: ' . $e->getMessage(), 'gdcatalog_upg_10143' ); } catch ( \Throwable ) {}
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
						try { \IPS\Log::log( 'upg_10143 lang (' . $key . '): ' . $e->getMessage(), 'gdcatalog_upg_10143' ); } catch ( \Throwable ) {}
					}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10143 lang loop: ' . $e->getMessage(), 'gdcatalog_upg_10143' ); } catch ( \Throwable ) {}
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
						try { \IPS\Log::log( 'upg_10143 tpl (' . $name . '): ' . $e->getMessage(), 'gdcatalog_upg_10143' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10143 tpl loop: ' . $e->getMessage(), 'gdcatalog_upg_10143' ); } catch ( \Throwable ) {}
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
