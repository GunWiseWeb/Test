<?php
/**
 * @brief  GD Deals — upgrade 1.0.64 (CSS self-heal: importDevCss + write compiled file + inject set_css_map).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.64
 *   v1.0.63 seeded core_theme_templates rows correctly — templates
 *   worked. But the front page rendered without any gddeals CSS
 *   because IPS's CSS pipeline never registered gddeals's
 *   dev/css/front/deals.css into core_theme_css, never wrote a
 *   compiled file at uploads/css_built_1/gddeals_front_deals.css,
 *   and left the set_css_map entry for gddeals as `null` — so
 *   \IPS\Theme::i()->css('deals.css','gddeals','front') returned an
 *   empty array and browse.php never emitted a <link> tag.
 *
 *   Three-step fix, all idempotent:
 *
 *   1. \IPS\Theme\Dev\Theme::importDevCss('gddeals', 0) — populates
 *      core_theme_css from dev/css/*.
 *   2. For each core_theme_css row for gddeals, write the compiled
 *      file directly to uploads/css_built_1/<app>_<location>_<name>.css
 *      from the DB row's css_content. IPS's compileCss() was
 *      observed to silently skip gddeals rows, so we do it ourselves.
 *   3. Compute the same md5 hash IPS uses (via
 *      Theme::makeBuiltTemplateLookupHash) and inject
 *      hash => 'css_built_1/<file>' into core_themes.set_css_map so
 *      the runtime lookup returns the URL.
 *
 *   Same helper is now embedded in setup/install.php so fresh
 *   installs are self-healing. gddealer + gdloadout are NOT touched
 *   (they were already working — gddealer via a legacy install,
 *   gdloadout because its CSS is served raw from
 *   applications/gdloadout/interface/loadouts.css, bypassing the
 *   compile pipeline entirely).
 *
 * WHAT THIS UPGRADE DOES
 *   1. Runs the three-step CSS self-heal for gddeals.
 *   2. Cache / datastore / opcache purge.
 *
 * NO schema change. NO template touched. NO lang change.
 * Rule #79: upg_10063 removed, exactly one upg dir per app.
 */

namespace IPS\gddeals\setup\upg_10064;

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
		$app = 'gddeals';

		/* 1. Import dev/css → core_theme_css. */
		try
		{
			\IPS\Theme\Dev\Theme::importDevCss( $app, 0 );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10064 importDevCss: ' . $e->getMessage(), 'gddeals_upg_10064' ); } catch ( \Throwable ) {}
		}

		/* 2 + 3. Write compiled files + inject set_css_map entries. */
		try
		{
			$builtDir = \IPS\ROOT_PATH . '/uploads/css_built_1';
			if ( !is_dir( $builtDir ) ) { @mkdir( $builtDir, 0755, TRUE ); }

			$hashMethod = NULL;
			try
			{
				$rc = new \ReflectionClass( '\IPS\Theme' );
				if ( $rc->hasMethod( 'makeBuiltTemplateLookupHash' ) )
				{
					$hashMethod = $rc->getMethod( 'makeBuiltTemplateLookupHash' );
					$hashMethod->setAccessible( TRUE );
				}
			}
			catch ( \Throwable ) {}

			$mapRow = NULL;
			try { $mapRow = \IPS\Db::i()->select( 'set_id, set_css_map', 'core_themes', [ 'set_id=?', 1 ] )->first(); }
			catch ( \Throwable ) {}
			$map = ( $mapRow && !empty( $mapRow['set_css_map'] ) ) ? ( json_decode( $mapRow['set_css_map'], TRUE ) ?: [] ) : [];

			foreach ( \IPS\Db::i()->select( 'css_location, css_path, css_name, css_content', 'core_theme_css', [ 'css_app=?', $app ] ) as $cssRow )
			{
				$loc  = (string) $cssRow['css_location'];
				$name = (string) $cssRow['css_name'];
				if ( $loc === '' || $name === '' ) { continue; }

				$builtFile = $app . '_' . $loc . '_' . $name;
				$dest      = $builtDir . '/' . $builtFile;

				try
				{
					file_put_contents( $dest, (string) $cssRow['css_content'] );
					@chmod( $dest, 0644 );
				}
				catch ( \Throwable $e )
				{
					try { \IPS\Log::log( 'upg_10064 css write (' . $builtFile . '): ' . $e->getMessage(), 'gddeals_upg_10064' ); } catch ( \Throwable ) {}
				}

				if ( $hashMethod && $mapRow )
				{
					try
					{
						$key = $hashMethod->invoke( NULL, $app, $loc, './' . $name );
						$map[ $key ] = 'css_built_1/' . $builtFile;
					}
					catch ( \Throwable $e )
					{
						try { \IPS\Log::log( 'upg_10064 css hash (' . $builtFile . '): ' . $e->getMessage(), 'gddeals_upg_10064' ); } catch ( \Throwable ) {}
					}
				}
			}

			if ( $mapRow && $hashMethod )
			{
				try { \IPS\Db::i()->update( 'core_themes', [ 'set_css_map' => json_encode( $map ) ], [ 'set_id=?', 1 ] ); }
				catch ( \Throwable $e )
				{
					try { \IPS\Log::log( 'upg_10064 css_map update: ' . $e->getMessage(), 'gddeals_upg_10064' ); } catch ( \Throwable ) {}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10064 css sync loop: ' . $e->getMessage(), 'gddeals_upg_10064' ); } catch ( \Throwable ) {}
		}

		/* Cache / datastore / opcache purge. */
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
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
