<?php
/**
 * @brief  GD Contact — upgrade 1.0.2 (honeypot hidden + lang seeded).
 *
 * Rule #79 — one upg_* dir per app. Rule #27 — dual class
 * wrapper, guard header.
 *
 * WHY v1.0.2 EXISTS:
 *   v1.0.0/v1.0.1 added the honeypot input via
 *     $form->add( new \IPS\Helpers\Form\Text(
 *       'gdcontact_hp_website', '', FALSE, [], null, null, null,
 *       'gdcontact_hp_website'
 *     ) );
 *   IPS renders every Form\Text as a labelled, visible input
 *   inside an <ul>/<li> row — so the honeypot showed up on the
 *   public /contact-us/ page as a normal field, with its raw
 *   lang key `gdcontact_hp_website` as the label (no matching
 *   row existed in core_sys_lang_words). Real users saw a
 *   "gdcontact_hp_website" field they had to leave blank; bots
 *   ignored it because it looked identical to every other input.
 *   The whole trap was inverted.
 *
 *   v1.0.2 fixes both halves:
 *     1. modules/front/contact/contact.php no longer adds the
 *        honeypot to the IPS Form. Instead it (a) intercepts
 *        the raw $_POST['gdcontact_hp_website'] before
 *        $form->values() runs and silent-redirects on a hit
 *        (bot sees "success"; no email sent), and (b) injects
 *        the honeypot HTML into the rendered form output right
 *        before </form> so it POSTs alongside the real inputs.
 *     2. The injected wrapper carries `.gdcontact-hp` +
 *        `aria-hidden="true"` + `tabindex="-1"` plus inline
 *        off-screen positioning (position:absolute; left:-9999px;
 *        top:-9999px; height/width:0; overflow:hidden). Bots
 *        that scan the DOM see a plausible <input name="…hp…">;
 *        humans and screen readers never see or focus it. CSS
 *        (interface/contact.css .gdcontact-hp rule) doubles
 *        the guarantee if inline styles were ever stripped.
 *     3. dev/lang.php + data/lang.xml declare
 *          gdcontact_hp_website = "Website"
 *        and this upgrade seeds it into core_sys_lang_words per
 *        language (rules #43 / #44 shape) so no raw key ever
 *        appears in DOM inspectors either.
 *
 *   The honeypot is NOT admin-editable — it's a built-in trap,
 *   independent of the ACP field builder.
 *
 * No schema, no template changes.
 */

namespace IPS\gdcontact\setup\upg_10002;

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
		 * 1. Seed the honeypot lang word. Rules #43 (6-col shape) +
		 *    #44 (per-row try/catch).
		 * ------------------------------------------------------------ */
		try
		{
			foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
			{
				try
				{
					\IPS\Db::i()->replace( 'core_sys_lang_words', [
						'lang_id'      => (int) $langId,
						'word_app'     => 'gdcontact',
						'word_key'     => 'gdcontact_hp_website',
						'word_default' => 'Website',
						'word_js'      => 0,
						'word_export'  => 1,
					] );
				}
				catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable ) {}

		/* ------------------------------------------------------------
		 * 2. Cache purge — controller changed + new CSS + new lang
		 *    row. Force the compiled asset URLs to re-resolve.
		 * ------------------------------------------------------------ */
		try { unset( \IPS\Data\Store::i()->furl_configuration ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->furl ); }               catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->acpMenu ); }            catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->interface_files ); }    catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->themes ); }             catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
