<?php
/**
 * @brief  GD Dealer Manager — upgrade 1.0.345
 *         Flagged UPCs: AJAX Submit for Review — row updates inline,
 *         stays visible on the New tab with a "Just submitted" marker.
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 * Rule #33 (standing session): do NOT call CanonicalTemplates::ensure().
 *
 * WHAT SHIPS IN 1.0.345
 *   On the dealer-side Flagged UPCs page, clicking "Submit for
 *   Review" previously fired a GET → mutate → redirect to the New
 *   tab, which then re-filtered the just-submitted row OUT of the
 *   view. The dealer had no visual confirmation on the New tab —
 *   they had to switch to the Submitted tab to track what they had
 *   already done.
 *
 *   Fix: convert the Submit button to an AJAX action.
 *
 *   - modules/front/dealers/dashboard.php::submitDataFlag()
 *       Detects AJAX requests via \IPS\Request::i()->isAjax().
 *       On AJAX: returns JSON { status, flag_id, already, new_count }
 *       so the frontend can swap the row in place and decrement the
 *       header "N new" badge. On a regular GET (JS disabled, old
 *       browsers): existing redirect behaviour preserved.
 *       "Already submitted" treated as success so a double-click
 *       doesn't flash a false error.
 *
 *   - dev/html/front/dealers/dataFlags.phtml
 *       Rows gain id="gddf-row-{id}" + data-gddf-id. Submit button
 *       gets a data-gddf-submit-url attribute. Status and Actions
 *       cells get stable class names (.gddf-status-cell /
 *       .gddf-action-cell) so inline JS can retarget them without
 *       fragile selectors. Header "N new" badge wrapped so the
 *       count element can update in place.
 *
 *       NEW inline JS (no $-prefixed JS vars per rule #46):
 *         - Delegated click handler on #gddf-container
 *         - Confirmation prompt matches the prior data-confirm text
 *         - Busy-flag prevents double-submit
 *         - On success: status cell badge flips neutral→warning
 *           "Submitted"; action cell swaps to a green "✓ Just
 *           submitted" marker; row background pulses light green
 *           for 1.6s then fades to normal; header new-count badge
 *           updates (hides when it hits 0)
 *         - On error: button text restored, alert raised
 *
 *   Dealer stays on the New tab, sees the row transition visually,
 *   knows which rows they handled THIS session — exactly the UX
 *   they asked for.
 *
 *   NO schema change. NO extension change. NO new lang key. CSS
 *   handled by inline styles on the elements the JS creates.
 *
 * WHAT THIS UPGRADE DOES
 *   1. Walks dev/html/*.phtml and replaces every row in
 *      core_theme_templates (same pattern as recent upgrades).
 *   2. Full datastore / template-store / opcache purge + rotate
 *      set_cache_key so compiled classes rebuild.
 *
 * Rule #79: upg_10344 removed, exactly one upg dir per app.
 */

namespace IPS\gddealer\setup\upg_10345;

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
		$version = '1.0.345';
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
						try { \IPS\Log::log( 'upg_10345 tpl (' . $name . '): ' . $e->getMessage(), 'gddealer_upg_10345' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10345 tpl loop: ' . $e->getMessage(), 'gddealer_upg_10345' ); } catch ( \Throwable ) {}
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
