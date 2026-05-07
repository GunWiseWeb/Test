<?php
namespace IPS\gddealer\setup\upg_10171;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		/* v1.0.171 - Fix URL routing collision between gddealer's dealer profile
		 * pattern and IPS core's member profile pattern.
		 *
		 * The dealers_profile FURL was registered as "profile/{@dealer_slug}"
		 * which IPS routed to gddealer for /profile/1-gunrack/?do=edit, intercepting
		 * IPS native member profiles. Symptom: every IPS member profile page threw
		 * "dealer_not_found" because gddealer's profile controller was looking up a
		 * dealer with slug "1-gunrack" (the IPS member URL slug).
		 *
		 * Fix: change the URL pattern from "profile/{@dealer_slug}" to
		 * "dealer/{@dealer_slug}". Result: dealer profiles live at
		 * /dealers/dealer/{slug}/ instead of /dealers/profile/{slug}/. No path
		 * collision with IPS core's /profile/{id}-{slug}/.
		 *
		 * The new furl.json ships in the tarball - IPS rebuilds the URL routing
		 * table on app upgrade automatically.
		 *
		 * The "overview" admin template (dealer dashboard) had a hardcoded
		 * display string showing the dealer their public URL. We surgically
		 * UPDATE that one line in the existing template_content via
		 * REPLACE() rather than reseeding the whole 200-line template.
		 *
		 * REPLACE() with literal search+replace strings is idempotent and
		 * safe to re-run. Not the regex-injection pattern CLAUDE.md rule #38
		 * warns against (that's about wrapping/stacking content, not single-token
		 * substitution on stable literal strings). */

		try
		{
			\IPS\Db::i()->preparedQuery(
				"UPDATE " . \IPS\Db::i()->prefix . "core_theme_templates
				 SET template_content = REPLACE( template_content, ?, ? ),
				     template_updated = ?
				 WHERE template_app = ?
				   AND template_name = ?
				   AND template_content LIKE ?",
				[
					'gunrack.deals/dealers/profile/',
					'gunrack.deals/dealers/dealer/',
					time(),
					'gddealer',
					'overview',
					'%gunrack.deals/dealers/profile/%',
				]
			);
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'v1.0.171 overview template URL update failed: ' . $e->getMessage(), 'gddealer_upg_10171' ); } catch ( \Throwable ) {}
			/* Non-fatal: dealer's help page just shows the old URL string until
			 * fixed manually. The actual URL routing still works correctly via furl.json. */
		}

		/* Clear theme/template/URL caches so the new furl.json + updated template
		 * take effect on the next request. */
		try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'theme_%' OR store_key LIKE 'template_%' OR store_key LIKE 'furl%'" ] ); } catch ( \Throwable ) {}

		foreach ( glob( \IPS\ROOT_PATH . '/datastore/template_*' ) ?: [] as $f )
		{
			@unlink( $f );
		}
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/furl*' ) ?: [] as $f )
		{
			@unlink( $f );
		}

		try { unset( \IPS\Data\Store::i()->extensions );   } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->furl );         } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll();            } catch ( \Throwable ) {}

		return TRUE;
	}

	public function step1CustomTitle()
	{
		return 'v1.0.171 - dealer profile URL pattern (/dealers/dealer/{slug} - no longer collides with IPS member profiles)';
	}
}

class upgrade extends _upgrade {}
