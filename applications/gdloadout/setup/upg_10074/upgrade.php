<?php
/**
 * @brief  GD Loadout — upgrade 1.0.74
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.74 — orphaned-loadout cleanup story.
 *
 *   1. sources/Loadout/Loadout.php gains two new static helpers:
 *        deleteCascade( int $loadoutId )
 *        deleteAllForMember( int $memberId )
 *      Single source of truth for loadout deletion. Cascade
 *      purges EVERY child table (items, votes, comments,
 *      follows, suggestions, forum_posts) — the frontend
 *      delete() used to miss comments + suggestions.
 *
 *   2. modules/front/loadouts/builder.php delete() refactored
 *      to call Loadout::deleteCascade() instead of its own
 *      hand-rolled 5-table purge. Fixes the missed-comments +
 *      missed-suggestions orphan-child-rows bug.
 *
 *   3. NEW ACP page: modules/admin/manage/loadouts.php.
 *      Lists every gd_loadouts row regardless of owner (so
 *      orphaned / guest-owned loadouts can be found + purged),
 *      with a name/slug search, an "orphaned only" filter,
 *      a rich pager (First / Prev / jump-to-page / Next /
 *      Last), and a Delete action per row that calls the
 *      shared cascade helper. Restriction: loadouts_manage.
 *      Registered in:
 *        data/acpmenu.json         (new "loadouts" entry)
 *        data/acprestrictions.json (new "loadouts_manage")
 *        dev/lang.php + data/lang.xml (menu label, r__ perm,
 *          + full set of gdloadout_acp_loadouts_* strings)
 *      This upgrade re-seeds every new lang key into
 *      core_sys_lang_words per lang_id (rule #43, per-row
 *      try/catch per rule #44).
 *
 *   4. NEW extension:
 *      extensions/core/MemberSync/Loadouts.php with
 *      onDelete( \IPS\Member $member ) — hooks into IPS's
 *      member-deletion flow so a deleted member's loadouts
 *      are auto-purged going forward (no NEW orphans).
 *      Registered under core.MemberSync.Loadouts in
 *      data/extensions.json. Every operation guarded so a
 *      cascade failure cannot block member deletion.
 *
 * NO schema changes. NO CanonicalTemplates re-seed call.
 * Cache clear at end so the module dispatcher picks up the
 * new admin controller, the new extension is discovered,
 * and language store re-resolves.
 */

namespace IPS\gdloadout\setup\upg_10074;

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
		$this->seedLangStrings();
		$this->clearCaches();
		return TRUE;
	}

	/**
	 * Seed the new v1.0.74 lang keys into core_sys_lang_words
	 * for every installed language. 6-col shape (rule #43).
	 * Per-row try/catch (rule #44).
	 */
	protected function seedLangStrings(): void
	{
		$strings = [
			'menu__gdloadout_manage_loadouts'       => 'All Loadouts',
			'r__loadouts_manage'                    => 'Manage all loadouts (site-wide)',

			'gdloadout_acp_loadouts_title'          => 'All Loadouts',
			'gdloadout_acp_loadouts_intro'          => 'Site-wide loadout management. Every gd_loadouts row regardless of owner, so orphaned (owner deleted) and guest-owned loadouts can be found and purged. Delete cascades through every child table (items, votes, comments, follows, suggestions, forum posts).',
			'gdloadout_acp_loadouts_total'          => 'loadouts total',
			'gdloadout_acp_loadouts_orphans'        => 'orphaned (deleted owner)',
			'gdloadout_acp_loadouts_orphans_only'   => 'Orphaned only',
			'gdloadout_acp_loadouts_search_ph'      => 'Search by name or slug…',
			'gdloadout_acp_loadouts_filter'         => 'Filter',
			'gdloadout_acp_loadouts_clear'          => 'Clear',
			'gdloadout_acp_loadouts_none'           => 'No loadouts match your filters.',
			'gdloadout_acp_loadouts_col_id'         => 'ID',
			'gdloadout_acp_loadouts_col_name'       => 'Name',
			'gdloadout_acp_loadouts_col_owner'      => 'Owner',
			'gdloadout_acp_loadouts_col_visibility' => 'Visibility',
			'gdloadout_acp_loadouts_col_items'      => 'Items',
			'gdloadout_acp_loadouts_col_upvotes'    => 'Upvotes',
			'gdloadout_acp_loadouts_col_created'    => 'Created',
			'gdloadout_acp_loadouts_delete'         => 'Delete',
			'gdloadout_acp_loadouts_delete_confirm' => 'Delete this loadout and every child row (items, votes, comments, follows, suggestions, forum posts)? This cannot be undone.',
			'gdloadout_acp_loadouts_deleted'        => 'Loadout deleted.',
			'gdloadout_acp_loadouts_guest'          => 'Guest / no owner',
			'gdloadout_acp_loadouts_deleted_member' => 'Deleted member',
			'gdloadout_acp_loadouts_page'           => 'Page',
			'gdloadout_acp_loadouts_jump'           => 'Jump to page',
			'gdloadout_acp_loadouts_go'             => 'Go',
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
							'word_app'     => 'gdloadout',
							'word_key'     => $key,
							'word_default' => $val,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
					catch ( \Throwable $e )
					{
						try { \IPS\Log::log( 'upg_10074 lang ' . $key . ': ' . $e->getMessage(), 'gdloadout_upg_10074' ); } catch ( \Throwable ) {}
					}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10074 lang loop: ' . $e->getMessage(), 'gdloadout_upg_10074' ); } catch ( \Throwable ) {}
		}
	}

	/**
	 * Cache purge — modules_admin so the new loadouts controller
	 * is registered, extensions so the MemberSync hook fires on
	 * the next member deletion, applications/settings/etc. for
	 * good measure. NO CanonicalTemplates::ensure().
	 */
	protected function clearCaches(): void
	{
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->acp_notifications ); }  catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }
	}
}
class upgrade extends _upgrade {}
