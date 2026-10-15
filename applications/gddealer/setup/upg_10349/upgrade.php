<?php
/**
 * @brief  GD Dealer Manager — upgrade 1.0.349
 *         Max tier pill: use a bold solid purple so it's clearly
 *         visible against the white hero card.
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 * Rule #33 (standing session): do NOT call CanonicalTemplates::ensure().
 * Rule #60 — re-import dev/css + recompile dealer.css after any CSS edit.
 *
 * WHAT SHIPS IN 1.0.349
 *   Admin screenshot showed the "MAX" pill rendering with no visible
 *   background against the white Subscription hero. v1.0.348 CSS gave
 *   it background #EDE9FE (pale lavender) which is nearly identical to
 *   the surrounding white card — the base .gdTierBadge rules
 *   (uppercase + padding + border-radius) applied correctly, but the
 *   fill was essentially invisible. Admin reasonably read it as
 *   "the pill didn't work".
 *
 *   Fix: bump .gdTierBadge--max to solid #7C3AED (purple-600) with
 *   white text. Reads as a premium top-tier badge, clearly
 *   distinct from the amber FOUNDER badge sitting next to it on
 *   founder-trial accounts. Updated in both CSS blocks (lines
 *   ~462 variable-form and ~2411 explicit-hex-form) so neither
 *   cascade wins with the old pale color.
 *
 *   NO schema change. NO template change. NO controller change.
 *   NO new lang key. CSS-only.
 *
 * WHAT THIS UPGRADE DOES
 *   1. Re-imports dev/css into core_theme_css and recompiles
 *      dealer.css to served URLs (rule #60).
 *   2. Walks dev/html/*.phtml and replaces every row in
 *      core_theme_templates (standard belt-and-suspenders).
 *   3. Full datastore / template-store / opcache purge + rotate
 *      set_cache_key so compiled classes rebuild AND the browser
 *      busts its dealer.css cache.
 *
 * Rule #79: upg_10348 removed, exactly one upg dir per app.
 */

namespace IPS\gddealer\setup\upg_10349;

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
		$version = '1.0.349';
		$root    = \IPS\ROOT_PATH . '/applications/' . $app . '/dev/html';

		/* Rule #60 — re-import dev/css + recompile dealer.css. */
		try
		{
			if ( class_exists( '\\IPS\\Theme\\Dev\\Theme' ) )
			{
				\IPS\Theme\Dev\Theme::importDevCss( 'gddealer', 0 );
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10349 importDevCss: ' . $e->getMessage(), 'gddealer_upg_10349' ); } catch ( \Throwable ) {}
		}
		try
		{
			foreach ( \IPS\Theme::themes() as $theme )
			{
				try
				{
					if ( method_exists( $theme, 'compileCss' ) )
					{
						$theme->compileCss( 'gddealer', 'front', '.', 'dealer.css' );
					}
				}
				catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10349 compileCss: ' . $e->getMessage(), 'gddealer_upg_10349' ); } catch ( \Throwable ) {}
		}

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
						try { \IPS\Log::log( 'upg_10349 tpl (' . $name . '): ' . $e->getMessage(), 'gddealer_upg_10349' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10349 tpl loop: ' . $e->getMessage(), 'gddealer_upg_10349' ); } catch ( \Throwable ) {}
			}
		}

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
