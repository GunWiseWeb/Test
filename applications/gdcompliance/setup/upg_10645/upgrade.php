<?php
/**
 * @brief  GD Compliance — upgrade 1.6.45
 *
 * WHAT SHIPS IN 1.6.45 — API HARDENING: member-level block flag.
 *
 *   Per-key `suspended` is bypassable — a still-paying, in-group
 *   dealer can just visit mykey and regenerate a fresh ACTIVE key.
 *   Payment-lapse suspension IS un-bypassable (IPS removes the
 *   lapsed subscriber from the API group → 402), but MANUAL admin
 *   suspension of a still-paying dealer (abuse, key-sharing, ToS)
 *   needs a member-level un-bypassable block. v1.6.45 introduces:
 *
 *     * New table gd_compliance_api_blocked (member_id PK, reason,
 *       blocked_at, blocked_by).
 *     * api.php authenticate() short-circuits with 403
 *       access_suspended when the member is blocked (before the
 *       group gate and before the per-key status check).
 *     * mykey / mykeyAct render a suspension notice + hard-reject
 *       all state changes (defense in depth).
 *     * ACP apikeys list adds Block / Unblock row buttons + a
 *       reason form, plus a BLOCKED badge on affected members.
 *
 *   Precedence (documented in api.php):
 *     MEMBER BLOCK > Subscription group gate > Per-key status
 *
 *   Payment-lapse path is unchanged (still 402). Only manual
 *   admin blocks are covered by this table.
 *
 * SELF-CONTAINED (rule #79): the guarded createTable is
 * idempotent (checkForTable) so upgrading from any prior version
 * lands correctly. Fresh installs use install.php + schema.json.
 */

namespace IPS\gdcompliance\setup\upg_10645;

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
		/* ---------------------------------------------------------
		 * FIX 1 — new table: gd_compliance_api_blocked
		 * ---------------------------------------------------------
		 * Row-exists = member is blocked. PK on member_id makes the
		 * lookup a point-query in the authenticate() hot path.
		 */
		try
		{
			if ( !\IPS\Db::i()->checkForTable( 'gd_compliance_api_blocked' ) )
			{
				\IPS\Db::i()->createTable( [
					'name'    => 'gd_compliance_api_blocked',
					'columns' => [
						[ 'name' => 'member_id',  'type' => 'INT',  'length' => 10, 'allow_null' => false, 'unsigned' => true, 'comment' => 'PK / unique — the blocked member. Row-exists = blocked.' ],
						[ 'name' => 'reason',     'type' => 'TEXT', 'length' => 0,  'allow_null' => true,  'comment' => 'admin-entered reason for the block' ],
						[ 'name' => 'blocked_at', 'type' => 'INT',  'length' => 10, 'allow_null' => true,  'unsigned' => true ],
						[ 'name' => 'blocked_by', 'type' => 'INT',  'length' => 10, 'allow_null' => true,  'unsigned' => true, 'comment' => 'admin member_id who applied the block' ],
					],
					'indexes' => [
						[ 'type' => 'primary', 'name' => 'PRIMARY', 'columns' => [ 'member_id' ] ],
					],
				] );
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10645 createTable gd_compliance_api_blocked: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}

		/* ---------------------------------------------------------
		 * FIX 2 — re-seed the v1.6.45 lang strings so existing
		 * installs get the block UI labels + audit-log formats.
		 * Per rule #43 (IPS 5.0.18 schema is 6 columns only) and
		 * rule #44 (per-row try/catch).
		 * --------------------------------------------------------- */
		$v1645Strings = [
			'gdcompliance_acp_apikeys_action_block'   => 'Block API access',
			'gdcompliance_acp_apikeys_action_unblock' => 'Unblock API access',
			'gdcompliance_acp_apikeys_block_title'    => 'Block API access',
			'acplog__gdcompliance_apikey_block'       => 'Blocked compliance API access for member %s: %s',
			'acplog__gdcompliance_apikey_unblock'     => 'Unblocked compliance API access for member %s',
		];

		try
		{
			foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
			{
				foreach ( $v1645Strings as $key => $val )
				{
					try
					{
						\IPS\Db::i()->replace( 'core_sys_lang_words', [
							'lang_id'      => (int) $langId,
							'word_app'     => 'gdcompliance',
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

		/* ---------------------------------------------------------
		 * Cache purge — force IPS to reload extensions / apps /
		 * templates so the new authenticate() gate and new ACP
		 * buttons are picked up immediately.
		 * --------------------------------------------------------- */
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->canonical_templates ); } catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
