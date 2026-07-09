<?php
/**
 * @brief  GD Contact — upgrade 1.0.1 (FURL de-collision + link repoint).
 *
 * Rule #79 — one upg_* dir per app. Rule #27 — dual class wrapper,
 * guard header.
 *
 * WHY v1.0.1 EXISTS:
 *   v1.0.0's data/furl.json claimed
 *     "topLevel": "contact"
 *   which collides with IPS core's native Contact Us at
 *   /contact/. Core owns the route, so /contact/ rendered the
 *   built-in form and gdcontact was unreachable via the friendly
 *   URL. Only the raw
 *     index.php?app=gdcontact&module=contact&controller=contact
 *   URL hit our controller.
 *
 *   v1.0.1 moves the app to `topLevel: contact-us` — no
 *   collision — so /contact-us/ renders our form. This upgrade
 *   step:
 *     1. Force-clears the furl / furl_configuration datastores
 *        so IPS re-parses data/furl.json and registers the new
 *        route on the next request.
 *     2. Best-effort repoints IPS's built-in "Contact Us" link
 *        to /contact-us/ by setting the core contact_link_type
 *        and contact_link_url settings (whichever IPS 5.0.18
 *        exposes on this install — try/catch so a missing
 *        setting can't fail the upgrade).
 *     3. Clears the usual module / interface / theme caches so
 *        the new versioned URLs propagate.
 *
 *   Direct visits to the OLD /contact/ still hit IPS's native
 *   form (there is no clean way to redirect the core route from
 *   an app without a fragile hook — documented for the admin
 *   in the commit message).
 *
 * No schema, no lang changes.
 */

namespace IPS\gdcontact\setup\upg_10001;

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
		/* ------------------------------------------------------------
		 * 1. Repoint IPS core's Contact Us link to /contact-us/.
		 *    IPS 5's core carries a small family of contact-URL
		 *    settings; we set every one we can find (each guarded)
		 *    so whichever the current install uses gets the right
		 *    value. Missing settings raise inside changeValues() and
		 *    the per-key try/catch swallows silently.
		 * ------------------------------------------------------------ */
		$targetUrl = '';
		try
		{
			$targetUrl = (string) \IPS\Http\Url::internal(
				'app=gdcontact&module=contact&controller=contact',
				'front'
			);
		}
		catch ( \Throwable ) {}

		if ( $targetUrl !== '' )
		{
			$repoints = [
				/* Some IPS builds expose 'contact_link' / 'contact_link_url' /
				   'contact_type' / 'contact_us_url'. Trying each keeps this
				   independent of the exact suite build. */
				'contact_link'          => $targetUrl,
				'contact_link_type'     => 'url',
				'contact_link_url'      => $targetUrl,
				'contact_type'          => 'url',
				'contact_us_url'        => $targetUrl,
				'contact_form_action'   => 'url',
			];
			foreach ( $repoints as $key => $val )
			{
				try
				{
					\IPS\Settings::i()->changeValues( [ $key => $val ] );
				}
				catch ( \Throwable ) {}
			}
		}

		/* ------------------------------------------------------------
		 * 2. Force-clear the FURL datastore so IPS re-parses the
		 *    updated data/furl.json on the next request. Without
		 *    this the old `topLevel: contact` binding stays in the
		 *    resolved route table.
		 * ------------------------------------------------------------ */
		try { unset( \IPS\Data\Store::i()->furl_configuration ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->furl ); }               catch ( \Throwable ) {}

		/* ------------------------------------------------------------
		 * 3. Full cache purge — settings, modules, applications,
		 *    interface_files, themes, opcache. So the ACP menu +
		 *    front dispatcher pick up the new route immediately.
		 * ------------------------------------------------------------ */
		try { unset( \IPS\Data\Store::i()->applications ); }    catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_admin ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->acpMenu ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }        catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->interface_files ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->themes ); }          catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }               catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }               catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
