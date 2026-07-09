<?php
/**
 * @brief  GD Dealer Manager — upgrade 1.0.328 (Deals author).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHY v1.0.328 EXISTS:
 *   sources/Deals/DealPublisher.php authored auto-deals with
 *     $author = \IPS\Member::load( 0 );
 *     $author = new \IPS\Member;
 *   Both branches produce a member with no member_id, so every
 *   deal rendered "by Guest" in the community feed.
 *
 *   v1.0.328 does three things:
 *     1. Registers a new setting `gddealer_deals_author_id`
 *        (INT) — the member shown as the byline for auto-
 *        published deals. Surfaced in ACP settings as a
 *        Form\Member picker.
 *     2. Creates a system member named "AutoDeals" the first
 *        time this upgrade runs. Idempotent — if a member
 *        with that name already exists, its ID is reused
 *        instead of a duplicate being created.
 *     3. Points gddealer_deals_author_id at the AutoDeals
 *        member so deals immediately start bylining
 *        correctly. The admin can rename AutoDeals or point
 *        the setting at a different member later.
 *
 *   DealPublisher's author lookup now falls back to Guest
 *   only when the configured member has been deleted, so
 *   publishing never dies on a bad setting.
 *
 * DO NOT call \IPS\gddealer\Setup\CanonicalTemplates::ensure()
 * here — a prior version's identical call wiped the dealer
 * dashboard. No templates need re-seeding in v1.0.328.
 *
 * No schema changes.
 */

namespace IPS\gddealer\setup\upg_10328;

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
		 * 1. Reseed the two new lang words per rule #43 / #44.
		 * ------------------------------------------------------------ */
		$strings = [
			'gddealer_deals_author_id'      => 'Deals author',
			'gddealer_deals_author_id_desc' => 'Member shown as the author of auto-published deals. Defaults to the AutoDeals system account created at upgrade time. Leave empty to publish deals as Guest.',
		];
		try
		{
			foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
			{
				foreach ( $strings as $key => $val )
				{
					try
					{
						\IPS\Db::i()->replace( 'core_sys_lang_words', [
							'lang_id'      => (int) $langId,
							'word_app'     => 'gddealer',
							'word_key'     => $key,
							'word_default' => $val,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
					catch ( \Throwable ) {}
				}
			}
		}
		catch ( \Throwable ) {}

		/* ------------------------------------------------------------
		 * 2. Ensure the AutoDeals system member exists and get its ID.
		 *    Idempotent — re-running the upgrade doesn't create a
		 *    duplicate account. If creation fails for any reason we
		 *    leave the setting at 0 (deals fall back to Guest but
		 *    publishing never breaks — see DealPublisher guard).
		 * ------------------------------------------------------------ */
		$autoDealsId = 0;
		try
		{
			/* First: honour any existing configured author. If the
			   admin already set a member, don't clobber it. */
			$existingSetting = (int) \IPS\Settings::i()->gddealer_deals_author_id;
			if ( $existingSetting )
			{
				try
				{
					$m = \IPS\Member::load( $existingSetting );
					if ( $m->member_id ) { $autoDealsId = (int) $m->member_id; }
				}
				catch ( \Throwable ) {}
			}

			/* Second: look for an existing AutoDeals-named member so
			   we don't create a duplicate on re-run. Match by exact
			   name. */
			if ( !$autoDealsId )
			{
				try
				{
					$hit = \IPS\Db::i()->select( 'member_id', 'core_members', [ 'name=?', 'AutoDeals' ] )->first();
					if ( $hit )
					{
						$autoDealsId = (int) $hit;
					}
				}
				catch ( \Throwable ) {}
			}

			/* Third: create fresh. Uses the default new-registrant
			   group so the account is a normal (non-admin) member.
			   Sets a non-routable email placeholder — IPS requires
			   emails to be unique so we include the member epoch;
			   the account never receives mail (it doesn't log in). */
			if ( !$autoDealsId )
			{
				try
				{
					$groupId = 0;
					try { $groupId = (int) \IPS\Settings::i()->member_group; } catch ( \Throwable ) {}
					if ( !$groupId ) { $groupId = 3; }   /* IPS default Members group */

					$m                  = new \IPS\Member;
					$m->name            = 'AutoDeals';
					$m->email           = 'autodeals+' . time() . '@gunrack.deals.local';
					$m->member_group_id = $groupId;
					$m->joined          = \IPS\DateTime::create();
					$m->allow_admin_mails = 0;
					$m->members_bitoptions['validating'] = FALSE;
					$m->save();
					$autoDealsId = (int) $m->member_id;
				}
				catch ( \Throwable $e )
				{
					try { \IPS\Log::log( 'gddealer AutoDeals member create: ' . $e->getMessage(), 'gddealer_upg_10328' ); } catch ( \Throwable ) {}
				}
			}

			/* Finally: store the resolved ID in the setting. Leaving
			   it at 0 is a safe fallback — DealPublisher then
			   bylines as Guest but publishing still succeeds. */
			if ( $autoDealsId )
			{
				try
				{
					\IPS\Settings::i()->changeValues( [
						'gddealer_deals_author_id' => (int) $autoDealsId,
					] );
				}
				catch ( \Throwable $e )
				{
					try { \IPS\Log::log( 'gddealer set author setting: ' . $e->getMessage(), 'gddealer_upg_10328' ); } catch ( \Throwable ) {}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gddealer AutoDeals block: ' . $e->getMessage(), 'gddealer_upg_10328' ); } catch ( \Throwable ) {}
		}

		/* ------------------------------------------------------------
		 * 3. Cache purge. NO CanonicalTemplates::ensure() call — a
		 *    prior version's identical call wiped the dealer
		 *    dashboard. Nothing template-shaped changes in v1.0.328,
		 *    so we don't need to re-seed any templates.
		 * ------------------------------------------------------------ */
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
