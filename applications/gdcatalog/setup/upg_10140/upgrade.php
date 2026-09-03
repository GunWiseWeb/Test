<?php
/**
 * @brief  GD Master Catalog — upgrade 1.0.140
 *         Normalise multi-space runs in title/brand/model on import
 *         + one-off retroactive cleanup for existing rows.
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 *
 * WHAT SHIPS IN 1.0.140
 *   Distributor feeds (esp. Sports South, some XML dealers) pad
 *   fixed-width columns and concatenate them, leaving titles like:
 *     "Burris Droptine, Bur 200077   Droptne 4.5-14x42    Bplx Mt"
 *   Multi-space runs are always noise in single-line text fields.
 *
 *   Code changes:
 *     - MOD  sources/Feed/TitleParser.php
 *            — NEW public static normalizeWhitespace(?string): ?string.
 *              Collapses \s+ (including NBSP) to a single ASCII space
 *              and trims. Returns null for empty/null input.
 *     - MOD  sources/Feed/Importer.php
 *            — Importer::processRecord now applies
 *              TitleParser::normalizeWhitespace to title / brand /
 *              model in the mapped canonical record before it
 *              reaches create/update. description is intentionally
 *              excluded — distributor line breaks there are worth
 *              keeping.
 *
 *   Data change (this upgrade, ONE-TIME):
 *     - Retroactively normalises whitespace on existing gd_catalog
 *       rows whose title, brand, or model contain a run of two or
 *       more consecutive whitespace characters. Uses REGEXP to
 *       filter and PHP-side rewrite so the query works on both
 *       MySQL 8+ and older MariaDB regardless of REGEXP_REPLACE
 *       availability. Idempotent — re-running does nothing because
 *       the WHERE-filter finds no more rows.
 *
 *   NO schema change. NO extension/task registration change. NO
 *   AdminCP menu change. NO new lang key.
 *
 * WHAT THIS UPGRADE DOES (idempotent, safe to re-run)
 *   1. Idempotent 1.0.130 schema hoist.
 *   2. Seeds the four accumulated lang keys.
 *   3. Retroactive whitespace cleanup on gd_catalog (title/brand/model).
 *   4. Re-seeds every dev/html/*.phtml.
 *   5. Cache / datastore / opcache purge.
 *
 * Rule #79: upg_10139 removed, exactly one upg dir per app.
 */

namespace IPS\gdcatalog\setup\upg_10140;

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
		$version = '1.0.140';
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
			try { \IPS\Log::log( 'upg_10140 addColumn: ' . $e->getMessage(), 'gdcatalog_upg_10140' ); } catch ( \Throwable ) {}
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
						try { \IPS\Log::log( 'upg_10140 lang (' . $key . '): ' . $e->getMessage(), 'gdcatalog_upg_10140' ); } catch ( \Throwable ) {}
					}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10140 lang loop: ' . $e->getMessage(), 'gdcatalog_upg_10140' ); } catch ( \Throwable ) {}
		}

		/* -------- Retroactive whitespace cleanup on gd_catalog -------- */
		try
		{
			if ( \IPS\Db::i()->checkForTable( 'gd_catalog' ) )
			{
				$fixed = 0;
				/* MariaDB / MySQL 5.7+ support REGEXP 'a[[:space:]]{2,}b'
				 * uniformly via POSIX char classes. Match any row where
				 * one of the three fields has 2+ consecutive whitespace
				 * chars (spaces/tabs) — we deliberately ignore NBSP in
				 * the filter (would require a byte-level scan) since
				 * ASCII multi-space is the common case and normalizer
				 * strips NBSP on next update anyway. */
				$rs = \IPS\Db::i()->select(
					'upc, title, brand, model',
					'gd_catalog',
					"title REGEXP '[[:space:]]{2,}' OR brand REGEXP '[[:space:]]{2,}' OR model REGEXP '[[:space:]]{2,}'"
				);
				foreach ( $rs as $row )
				{
					$update = [];
					foreach ( [ 'title', 'brand', 'model' ] as $f )
					{
						$orig = (string) ( $row[ $f ] ?? '' );
						$clean = preg_replace( '/\s+/u', ' ', str_replace( "\xC2\xA0", ' ', $orig ) );
						$clean = trim( (string) $clean );
						if ( $clean !== $orig )
						{
							$update[ $f ] = $clean;
						}
					}
					if ( !empty( $update ) )
					{
						try
						{
							\IPS\Db::i()->update( 'gd_catalog', $update, [ 'upc=?', (string) $row['upc'] ] );
							$fixed++;
						}
						catch ( \Throwable $e )
						{
							try { \IPS\Log::log( 'upg_10140 cleanup upc=' . $row['upc'] . ': ' . $e->getMessage(), 'gdcatalog_upg_10140' ); } catch ( \Throwable ) {}
						}
					}
				}
				try { \IPS\Log::log( 'upg_10140 whitespace cleanup: fixed ' . $fixed . ' rows', 'gdcatalog_upg_10140' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10140 cleanup select: ' . $e->getMessage(), 'gdcatalog_upg_10140' ); } catch ( \Throwable ) {}
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
						try { \IPS\Log::log( 'upg_10140 tpl (' . $name . '): ' . $e->getMessage(), 'gdcatalog_upg_10140' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10140 tpl loop: ' . $e->getMessage(), 'gdcatalog_upg_10140' ); } catch ( \Throwable ) {}
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
