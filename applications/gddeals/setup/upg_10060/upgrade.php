<?php
/**
 * @brief  GD Deals — upgrade 1.0.60 (fix ONLY_FULL_GROUP_BY violation in leaderboard).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.60
 *   modules/front/leaderboard/leaderboard.php had two raw SQL
 *   queries — queryTopDealers() and queryBestValueDealers() —
 *   that selected d.dealer_name / d.dealer_slug while only
 *   GROUP BYing d.dealer_id. Under MySQL/MariaDB strict
 *   ONLY_FULL_GROUP_BY mode this throws
 *   "'gunrack_deals.d.dealer_name' isn't in GROUP BY", killing
 *   the front page with a generic "Something went wrong" once
 *   IN_DEV is off. IN_DEV=true masks the fail through a
 *   different execution/caching path — the bug is genuinely
 *   pre-existing, not caused by the dev-mode flip.
 *
 *   Fix: wrap d.dealer_name and d.dealer_slug in MAX() in both
 *   queries' SELECT lists. MAX() is safe because
 *   dealer_name/dealer_slug are functionally dependent on
 *   dealer_id (one dealer has exactly one name and slug), so
 *   MAX just picks the one correct value per group. This is the
 *   same pattern already used correctly in this file's own
 *   queryTopMembers() (MAX(author_name)) and matches the
 *   project's standing MariaDB 10.6 rule (use MAX(), not
 *   ANY_VALUE()).
 *
 *   Everything else in both queries (ORDER BY, subqueries, JOINs,
 *   WHERE clauses, LIMIT) is unchanged.
 *
 *   Audit performed across gddeals and gddealer for the same
 *   pattern — no other raw-SQL GROUP BY queries with un-grouped
 *   un-aggregated select columns exist. DealEngine.php in
 *   gddealer has GROUP BY queries but all select columns are
 *   either grouped or aggregated. Only these two leaderboard
 *   queries were affected.
 *
 * WHAT THIS UPGRADE DOES
 *   Cache / datastore / opcache purge so the updated leaderboard
 *   controller loads on the next request.
 *
 * NO schema change. NO template touched. NO lang change.
 * Rule #79: upg_10059 removed, exactly one upg dir per app.
 */

namespace IPS\gddeals\setup\upg_10060;

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
