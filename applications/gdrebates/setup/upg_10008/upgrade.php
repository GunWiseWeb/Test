<?php
/**
 * @brief  GD Rebates — upgrade 1.0.8
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.8 — three regressions from v1.0.7 fixed.
 *
 *   Bug 1 (CRITICAL): dev/html/front/rebates/browse.phtml line 44
 *   introduced a `{expression="..."}` tag whose PHP contained
 *   backslash-escaped double-quotes:
 *     {expression="htmlspecialchars( ..., $r[\"eligible_models\"], ... )"}
 *   The IPS template compiler cannot tokenize \" inside an
 *   expression tag — it prematurely closed the tag on the first
 *   \" and choked on the trailing ] with
 *     unexpected token "\", expecting "]"
 *   The entire /rebates/ page went to a Whoops error page.
 *
 *   v1.0.8 fix: single-quoted PHP string literals inside the
 *   expression (the tag itself is delimited with ", so inner PHP
 *   uses '). No backslash-escaped quotes anywhere in browse.phtml
 *   — verified by grep (0 occurrences of \" in the shipped file)
 *   and by running php -l over each expression tag's extracted
 *   PHP contents at build time (all 3 tags parse cleanly).
 *
 *   Bug 2: `.gdrb-card__mfr` rendered "Springfield Armory" as a
 *   vertical stack — one character per line. Root cause: the
 *   amount pill `.gdrb-card__amt` was flex:0 0 auto +
 *   white-space:nowrap and its natural width (with the long real
 *   "FREE XD-M(R) Elite 10mm (XDME94510BHCOSP)" text) exceeded the
 *   ~280px grid column. That forced the mfr span to near-zero
 *   width and the browser wrapped its text one character per
 *   line.
 *
 *   Bug 3: same pill also overflowed OFF the right edge of the
 *   card since it refused to shrink or wrap.
 *
 *   Both fixed in dev/css/front/rebates.css:
 *     * `.gdrb-card__head` gains flex-wrap:wrap + min-width:0
 *       so the amount pill can drop to its own line when needed.
 *     * `.gdrb-card__mfr` gains white-space:nowrap +
 *       overflow:hidden + text-overflow:ellipsis + flex:1 1 auto
 *       + min-width:0. Mfr text NEVER wraps mid-character.
 *     * `.gdrb-card__amt` changes flex:0 0 auto -> flex:0 1 auto,
 *       white-space:nowrap -> white-space:normal +
 *       overflow-wrap:break-word, plus max-width:100%. Long
 *       amounts wrap INSIDE the pill; short amounts unaffected.
 *     * `.gdrb-card` gains min-width:0 + overflow:hidden as a
 *       safety net so nothing can visually escape the card
 *       regardless.
 *     * `.gdrb-card__logo` gains flex:0 0 auto so the logo isn't
 *       squished when it shares the head row.
 *
 * step1() also purges any stale canonical .tpl override from
 * data/canonical_templates/ (per project standing rule — a stale
 * .tpl can win over dev/html even in IN_DEV, hiding the fix).
 * Every clearCaches call likewise runs Theme::deleteCompiledTemplate
 * so the fixed browse.phtml body re-compiles.
 *
 * No lang changes. No PHP controller changes. No schema.
 *
 * Rule #79: upg_10007 removed, exactly one upg dir per app.
 */

namespace IPS\gdrebates\setup\upg_10008;

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
		$this->purgeStaleCanonicalTemplate();
		$this->clearCaches();
		return TRUE;
	}

	/**
	 * If there is a stale canonical .tpl cache file for browse.phtml,
	 * delete it so IPS re-compiles from the fixed dev/html source.
	 * Non-fatal if the directory/file is missing (the common case).
	 */
	protected function purgeStaleCanonicalTemplate(): void
	{
		try
		{
			$dir = \IPS\ROOT_PATH . '/applications/gdrebates/data/canonical_templates';
			if ( !is_dir( $dir ) ) { return; }
			foreach ( glob( $dir . '/*browse*' ) ?: [] as $stale )
			{
				try
				{
					if ( is_file( $stale ) && is_writable( $stale ) )
					{
						@unlink( $stale );
						try { \IPS\Log::log( 'upg_10008 purged canonical: ' . basename( $stale ), 'gdrebates_upg_10008' ); } catch ( \Throwable ) {}
					}
				}
				catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10008 canonical purge: ' . $e->getMessage(), 'gdrebates_upg_10008' ); } catch ( \Throwable ) {}
		}
	}

	protected function clearCaches(): void
	{
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->interface_files ); }    catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->themes ); }             catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->canonical_templates ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'theme_%' OR store_key LIKE 'template_%'" ] ); } catch ( \Throwable ) {}
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/template_*' ) ?: [] as $f ) { @unlink( $f ); }
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Theme::deleteCompiledTemplate(); }              catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }
	}
}
class upgrade extends _upgrade {}
