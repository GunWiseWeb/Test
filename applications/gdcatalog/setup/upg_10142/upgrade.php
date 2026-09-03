<?php
/**
 * @brief  GD Master Catalog — upgrade 1.0.142
 *         UPC/identity audit: 5 new gd_catalog columns + auto-flag
 *         at import time + Review Queue surface + AI round-trip.
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 *
 * WHAT SHIPS IN 1.0.142
 *   User surfaced that dealer feeds sometimes carry structurally bad
 *   UPCs (checksum failures, "brand-prefix + 000000" placeholders)
 *   and wrong identities (title says one product, MPN points at
 *   another). The AI enrichment step already produces an audit CSV
 *   with per-row status/notes/suggested_upc/verified_mpn/source; we
 *   need somewhere to persist that AND we need to automatically
 *   flag the obvious structural failures without waiting on the AI.
 *
 *   SCHEMA (5 new gd_catalog columns + 1 index — all nullable):
 *     - upc_audit_status       VARCHAR(64)  — short label
 *     - upc_audit_notes        TEXT         — free-form AI explanation
 *     - suggested_correct_upc  VARCHAR(32)  — AI's proposed real UPC
 *     - verified_mpn           VARCHAR(64)  — AI-verified MPN
 *     - upc_audit_source       TEXT         — URL(s) AI used to audit
 *     - idx_upc_audit_status   KEY(32)      — for the "flagged only" filter
 *
 *   CODE:
 *     - sources/Feed/UpcValidator.php
 *         PRESERVES existing normalize / validateCheckDigit / isSuspicious
 *         / normalizeAndFlag (still called from Importer, GenericImport,
 *         SportsSouthImport, Phase10SsDiscontinuationTest). ADDS
 *         static classify(?string $upc): ?string returning a short
 *         label for the audit column ("Invalid UPC-A checksum",
 *         "Invalid EAN-13 checksum", "Placeholder UPC") or null when
 *         the UPC passes. Placeholder detection covers all-zeros and
 *         6+ trailing zeros. ADDS static isPlaceholder() and
 *         validateEan13() helpers.
 *     - sources/Feed/Importer.php
 *         processRecord now calls UpcValidator::classify on the
 *         incoming UPC. If the AI didn't already supply an
 *         upc_audit_status (i.e. this is a fresh import from a
 *         distributor feed), the auto-label is injected. When any
 *         audit status is present that doesn't look like an
 *         all-clear ("Verified", "Valid"), a synthetic
 *         _force_admin_review flag is set — createProduct honours
 *         it to flip record_status to admin_review even when
 *         mark_imports_as_review is off on the source. updateProduct
 *         drops the synthetic key before writing so it can't hit
 *         the DB.
 *     - sources/Feed/FieldMapper.php
 *         VALID_FIELDS extended with the five audit columns so the
 *         docstring reflects reality.
 *     - modules/admin/catalog/reviewqueue.php
 *         CSV_EXPORT_COLUMNS extended with the five audit columns —
 *         the AI reads current auto-flag, can add richer status/
 *         notes/suggested_upc, and the re-import persists everything.
 *         manage() gains a `flagged=1` query filter, computes a
 *         flaggedCount for the toggle label, and passes audit_status/
 *         audit_notes/audit_flagged/suggested_upc into each row.
 *     - modules/admin/catalog/feeds.php
 *         MANUAL_UPLOAD_DEFAULT_FIELD_MAPPING extended with the same
 *         five columns so new manual sources round-trip audit data
 *         out of the box.
 *     - dev/html/admin/catalog/reviewQueue.phtml
 *         New parameters: flaggedOnly, flaggedCount, flaggedToggleUrl.
 *         New "⚠ Flagged only (N)" toggle next to the filter form.
 *         Per-row: red badge under the UPC when audit_flagged, with
 *         audit_notes on hover and suggested_correct_upc shown
 *         underneath in green.
 *
 * WHAT THIS UPGRADE DOES (idempotent, safe to re-run)
 *   1. Idempotent 1.0.130 schema hoist.
 *   2. Adds the 5 new gd_catalog columns + audit index (guarded by
 *      checkForColumn / checkForIndex).
 *   3. Retroactive auto-classify pass on existing gd_catalog rows
 *      where upc_audit_status IS NULL: runs UpcValidator::classify
 *      per row, writes the label + flips to admin_review when
 *      flagged. Cheap SELECT (only null rows), skipped on re-run.
 *   4. Seeds the four accumulated lang keys.
 *   5. Re-seeds every dev/html/*.phtml.
 *   6. Cache / datastore / opcache purge.
 *
 * Rule #79: upg_10141 removed, exactly one upg dir per app.
 */

namespace IPS\gdcatalog\setup\upg_10142;

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
		$version = '1.0.142';
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
			try { \IPS\Log::log( 'upg_10142 addColumn mark_imports_as_review: ' . $e->getMessage(), 'gdcatalog_upg_10142' ); } catch ( \Throwable ) {}
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
						try { \IPS\Log::log( 'upg_10142 addColumn ' . $colName . ': ' . $e->getMessage(), 'gdcatalog_upg_10142' ); } catch ( \Throwable ) {}
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
					try { \IPS\Log::log( 'upg_10142 addIndex idx_upc_audit_status: ' . $e->getMessage(), 'gdcatalog_upg_10142' ); } catch ( \Throwable ) {}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10142 audit column bootstrap: ' . $e->getMessage(), 'gdcatalog_upg_10142' ); } catch ( \Throwable ) {}
		}

		/* -------- Retroactive UpcValidator::classify pass -------- */
		try
		{
			if ( \IPS\Db::i()->checkForTable( 'gd_catalog' )
				&& \IPS\Db::i()->checkForColumn( 'gd_catalog', 'upc_audit_status' )
				&& class_exists( '\IPS\gdcatalog\Feed\UpcValidator' ) )
			{
				$flagged = 0;
				$rs = \IPS\Db::i()->select(
					'upc, record_status',
					'gd_catalog',
					'upc_audit_status IS NULL'
				);
				foreach ( $rs as $row )
				{
					$label = \IPS\gdcatalog\Feed\UpcValidator::classify( (string) $row['upc'] );
					if ( $label === null ) { continue; }
					$update = [ 'upc_audit_status' => $label ];
					/* Flip active rows to admin_review so the flag surfaces.
					 * Discontinued rows are left alone — no point re-queuing
					 * them for admin attention. */
					if ( (string) $row['record_status'] === 'active' )
					{
						$update['record_status'] = 'admin_review';
					}
					try
					{
						\IPS\Db::i()->update( 'gd_catalog', $update, [ 'upc=?', (string) $row['upc'] ] );
						$flagged++;
					}
					catch ( \Throwable $e )
					{
						try { \IPS\Log::log( 'upg_10142 retro-classify upc=' . $row['upc'] . ': ' . $e->getMessage(), 'gdcatalog_upg_10142' ); } catch ( \Throwable ) {}
					}
				}
				try { \IPS\Log::log( 'upg_10142 retro-classify: flagged ' . $flagged . ' rows', 'gdcatalog_upg_10142' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10142 retro-classify outer: ' . $e->getMessage(), 'gdcatalog_upg_10142' ); } catch ( \Throwable ) {}
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
						try { \IPS\Log::log( 'upg_10142 lang (' . $key . '): ' . $e->getMessage(), 'gdcatalog_upg_10142' ); } catch ( \Throwable ) {}
					}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10142 lang loop: ' . $e->getMessage(), 'gdcatalog_upg_10142' ); } catch ( \Throwable ) {}
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
						try { \IPS\Log::log( 'upg_10142 tpl (' . $name . '): ' . $e->getMessage(), 'gdcatalog_upg_10142' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10142 tpl loop: ' . $e->getMessage(), 'gdcatalog_upg_10142' ); } catch ( \Throwable ) {}
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
