<?php
/**
 * @brief  GD Dealer Manager — upgrade 1.0.339 (fix ACP email edit save; correct schema).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.339
 *   Dealer Settings → Welcome / Trial Expiring / Trial Expired
 *   subject+body form fields NEVER persisted a save. Root cause,
 *   verified against emails.xml + every buildFromTemplate() call
 *   site + the working FFL email flow:
 *
 *     - core_email_templates has NO template_subject or
 *       template_body columns. Real columns: template_id,
 *       template_app, template_name, template_content_html,
 *       template_content_plaintext, template_data, template_parent,
 *       template_key, template_edited, template_pinned.
 *     - IPS 5 resolves email SUBJECT at send time from lang key
 *       {templateName}_email_subject — NOT from any per-row column.
 *       Confirmed by the FFL flow: template name
 *       gddealer_ffl_verified pairs with lang key
 *       gddealer_ffl_verified_email_subject; send code never passes
 *       a subject arg to buildFromTemplate.
 *     - The ACP save closure was writing to template_subject /
 *       template_body — nonexistent columns — inside a silent
 *       catch(\Exception){}. Every save threw "Unknown column"
 *       invisibly and the settings page reloaded blank.
 *
 *   In this version modules/admin/dealers/settings.php:
 *     - Reads subject from core_sys_lang_words for the default
 *       lang under key {tpl}_email_subject.
 *     - Reads body from core_email_templates.template_content_html.
 *     - Writes subject via replace() into core_sys_lang_words for
 *       every lang_id (Rule #43/#44 6-col schema, per-row try/catch).
 *     - Writes body via update() to core_email_templates
 *       .template_content_html (mirrored plaintext with tags
 *       stripped for the plaintext MIME part).
 *     - Upserts the template row so a missing row (emails.xml
 *       parse-fail installs — rule #18) doesn't silently no-op.
 *     - Silent catch(\Exception){} REMOVED — every write path logs
 *       to core_error_logs via \IPS\Log::log(..., 'gddealer') on
 *       failure so future breakage surfaces immediately.
 *     - Post-save datastore/cache clear so freshly-saved subject
 *       lang key is used on the next email send.
 *
 * WHAT THIS UPGRADE DOES
 *   1. For each of dealerWelcome / trialExpiringSoon / trialExpired:
 *      - Guarantees the template row exists in core_email_templates
 *        (idempotent — check-then-insert with defaults sourced from
 *        DealerEmails.php via strip_tags() for a sensible starting
 *        plaintext body).
 *      - Seeds the initial subject lang key
 *        ({tpl}_email_subject) for every lang_id if not already
 *        present (per-row try/catch, all lang_ids).
 *   2. Full cache / datastore / opcache purge so the ACP settings
 *      page picks up the updated settings.php controller AND the
 *      first email send after upgrade resolves the new subject
 *      lang keys.
 *
 * NO schema change (all writes go to REAL existing columns).
 * NO template file touched.
 * Rule #79: upg_10338 removed, exactly one upg dir per app.
 */

namespace IPS\gddealer\setup\upg_10339;

use function defined;
use function function_exists;
use function strip_tags;
use function trim;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		/* Defaults sourced from extensions/core/EmailTemplates/DealerEmails.php
		   so a brand-new install without any prior admin edits still
		   sends a sensible email out of the box. */
		$defaults = [
			'dealerWelcome' => [
				'subject' => 'Welcome to GunRack.deals — Your dealer account is ready',
				'html'    => "<p>Hi {\$params['name']},</p><p>Welcome to GunRack.deals as a dealer!</p>",
			],
			'trialExpiringSoon' => [
				'subject' => 'Your GunRack.deals trial expires soon',
				'html'    => "<p>Hi {\$params['name']},</p><p>Your trial expires in {\$params['days']} days.</p>",
			],
			'trialExpired' => [
				'subject' => 'Your GunRack.deals dealer trial has expired',
				'html'    => "<p>Hi {\$params['name']},</p><p>Your trial has expired.</p>",
			],
		];

		/* 1. Ensure each template row exists (idempotent). */
		foreach ( $defaults as $tpl => $def )
		{
			try
			{
				$exists = FALSE;
				try
				{
					$exists = (bool) \IPS\Db::i()->select( 'template_id', 'core_email_templates',
						[ 'template_app=? AND template_name=?', 'gddealer', $tpl ]
					)->first();
				}
				catch ( \UnderflowException ) { $exists = FALSE; }

				if ( !$exists )
				{
					$plaintext = trim( strip_tags( $def['html'] ) );
					\IPS\Db::i()->insert( 'core_email_templates', [
						'template_app'               => 'gddealer',
						'template_name'              => $tpl,
						'template_content_html'      => $def['html'],
						'template_content_plaintext' => $plaintext,
						'template_data'              => '',
					] );
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10339 email template seed (' . $tpl . '): ' . $e->getMessage(), 'gddealer_upg_10339' ); } catch ( \Throwable ) {}
			}
		}

		/* 2. Seed default subject lang key for every lang_id (idempotent —
		   only insert when the row doesn't already exist so we don't
		   trample any subject an admin has already customized). */
		try
		{
			foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
			{
				foreach ( $defaults as $tpl => $def )
				{
					try
					{
						$key = $tpl . '_email_subject';
						$exists = FALSE;
						try
						{
							$exists = (bool) \IPS\Db::i()->select( 'word_id', 'core_sys_lang_words',
								[ 'word_key=? AND lang_id=?', $key, (int) $langId ]
							)->first();
						}
						catch ( \UnderflowException ) { $exists = FALSE; }

						if ( !$exists )
						{
							\IPS\Db::i()->insert( 'core_sys_lang_words', [
								'lang_id'      => (int) $langId,
								'word_app'     => 'gddealer',
								'word_key'     => $key,
								'word_default' => $def['subject'],
								'word_js'      => 0,
								'word_export'  => 1,
							] );
						}
					}
					catch ( \Throwable $e )
					{
						try { \IPS\Log::log( 'upg_10339 subject lang seed (' . $tpl . ' lang ' . (int) $langId . '): ' . $e->getMessage(), 'gddealer_upg_10339' ); } catch ( \Throwable ) {}
					}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10339 subject lang loop: ' . $e->getMessage(), 'gddealer_upg_10339' ); } catch ( \Throwable ) {}
		}

		/* 3. Cache purge. */
		try { \IPS\Db::i()->delete( 'core_cache' ); }                                                                catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'theme_%' OR store_key LIKE 'template_%'" ] ); } catch ( \Throwable ) {}
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/template_*' ) ?: [] as $f ) { @unlink( $f ); }
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
