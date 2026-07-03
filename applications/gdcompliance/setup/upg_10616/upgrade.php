<?php
/**
 * @brief  GD Compliance — upgrade 1.6.16
 *
 * ACP-display-only. Fixes the Lowers page pagination that shipped
 * broken in v1.6.14/v1.6.15:
 *
 * ROOT CAUSE — the flagged-lowers count query was
 *   \IPS\Db::i()->select( 'COUNT(DISTINCT upc)', 'gd_compliance_flags',
 *       [ 'f.firearm_type=? [ AND f.state_code=? ]', … ] )
 * The table was passed as a bare string (no alias) while the WHERE
 * referenced the `f.` alias. MySQL raised "Unknown column
 * 'f.firearm_type' in 'where clause'", which the surrounding
 * try/catch swallowed. $listCount stayed at 0, so the pager
 * conditional (`if ( $listCount > $per )`) never fired, and Prev/
 * Next never rendered — the admin couldn't reach page 2.
 *
 * FIX — count query now uses the aliased-table form
 *   [ 'gd_compliance_flags', 'f' ]
 * matching the list SELECT + magazines.php's line 112 pattern.
 * $per remains 50 (same as Magazines). Pager preserves the state
 * filter via array_filter([...'state'=>$stateFilter...]).
 *
 * No engine changes. No schema changes. No recompute required.
 *
 * DEFENSIVE — carries the v1.6.10 gd_compliance_lowers CREATE, the
 * v1.6.12 gd_compliance_review.review_type ADD + backfill, and the
 * v1.6.13 tandemkross force_clear seed forward for skip-upgrades
 * from 1.6.9 → 1.6.16.
 */

namespace IPS\gdcompliance\setup\upg_10616;

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
		/* ---------- Defensive gd_compliance_lowers CREATE (v1.6.10) ---------- */
		$hasLowers = FALSE;
		try
		{
			$hasLowers = (bool) \IPS\Db::i()->checkForTable( 'gd_compliance_lowers' );
		}
		catch ( \Throwable ) { $hasLowers = FALSE; }
		if ( !$hasLowers )
		{
			try
			{
				\IPS\Db::i()->createTable( [
					'name'    => 'gd_compliance_lowers',
					'columns' => [
						'id'         => [ 'name' => 'id', 'type' => 'INT', 'length' => 10, 'decimals' => null, 'allow_null' => FALSE, 'default' => null, 'auto_increment' => TRUE, 'binary' => FALSE, 'unsigned' => TRUE, 'zerofill' => FALSE, 'values' => [], 'comment' => '' ],
						'pattern'    => [ 'name' => 'pattern', 'type' => 'VARCHAR', 'length' => 191, 'decimals' => null, 'allow_null' => FALSE, 'default' => '', 'auto_increment' => FALSE, 'binary' => FALSE, 'unsigned' => FALSE, 'zerofill' => FALSE, 'values' => [], 'comment' => 'title/model/MPN/UPC substring, case-insensitive' ],
						'platform'   => [ 'name' => 'platform', 'type' => 'VARCHAR', 'length' => 40, 'decimals' => null, 'allow_null' => TRUE, 'default' => null, 'auto_increment' => FALSE, 'binary' => FALSE, 'unsigned' => FALSE, 'zerofill' => FALSE, 'values' => [], 'comment' => '' ],
						'action'     => [ 'name' => 'action', 'type' => 'VARCHAR', 'length' => 20, 'decimals' => null, 'allow_null' => FALSE, 'default' => 'force_flag', 'auto_increment' => FALSE, 'binary' => FALSE, 'unsigned' => FALSE, 'zerofill' => FALSE, 'values' => [], 'comment' => '' ],
						'note'       => [ 'name' => 'note', 'type' => 'VARCHAR', 'length' => 255, 'decimals' => null, 'allow_null' => TRUE, 'default' => null, 'auto_increment' => FALSE, 'binary' => FALSE, 'unsigned' => FALSE, 'zerofill' => FALSE, 'values' => [], 'comment' => '' ],
						'created_at' => [ 'name' => 'created_at', 'type' => 'INT', 'length' => 10, 'decimals' => null, 'allow_null' => TRUE, 'default' => null, 'auto_increment' => FALSE, 'binary' => FALSE, 'unsigned' => TRUE, 'zerofill' => FALSE, 'values' => [], 'comment' => '' ],
					],
					'indexes' => [
						'PRIMARY'    => [ 'type' => 'primary', 'name' => 'PRIMARY',    'length' => [ null ], 'columns' => [ 'id' ] ],
						'uq_pattern' => [ 'type' => 'unique',  'name' => 'uq_pattern', 'length' => [ 191 ],  'columns' => [ 'pattern' ] ],
						'idx_action' => [ 'type' => 'key',     'name' => 'idx_action', 'length' => [ null ], 'columns' => [ 'action' ] ],
					],
				] );
			}
			catch ( \Throwable ) { /* non-fatal */ }
		}

		/* ---------- Defensive gd_compliance_review.review_type (v1.6.12) ---------- */
		$hasCol = FALSE;
		try
		{
			$hasCol = (bool) \IPS\Db::i()->checkForColumn( 'gd_compliance_review', 'review_type' );
		}
		catch ( \Throwable ) { $hasCol = FALSE; }
		if ( !$hasCol )
		{
			try
			{
				\IPS\Db::i()->addColumn( 'gd_compliance_review', [
					'name'           => 'review_type',
					'type'           => 'VARCHAR',
					'length'         => 20,
					'decimals'       => null,
					'allow_null'     => FALSE,
					'default'        => 'roster',
					'auto_increment' => FALSE,
					'binary'         => FALSE,
					'unsigned'       => FALSE,
					'zerofill'       => FALSE,
					'values'         => [],
					'comment'        => 'roster|awb_firearm|lower|magazine — the review category',
				] );

				$backfills = [
					[ 'awb_firearm', "suggested_status LIKE 'awb\\_review\\_%'" ],
					[ 'awb_firearm', "suggested_status LIKE 'awb\\_tier2\\_%'" ],
					[ 'awb_firearm', "suggested_status LIKE 'awb\\_%' AND review_type='roster'" ],
					[ 'lower',       "suggested_status LIKE 'lower\\_%'" ],
					[ 'magazine',    "suggested_status LIKE 'magazine\\_%'" ],
					[ 'roster',      "suggested_status='unmatched_review'" ],
				];
				foreach ( $backfills as [ $type, $whereFrag ] )
				{
					try
					{
						\IPS\Db::i()->update( 'gd_compliance_review',
							[ 'review_type' => $type ],
							[ $whereFrag . ' AND review_type<>?', $type ] );
					}
					catch ( \Throwable ) {}
				}
			}
			catch ( \Throwable ) {}
		}

		/* ---------- Defensive tandemkross force_clear seed (v1.6.13) ---------- */
		try
		{
			$existing = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_compliance_lowers',
				[ 'pattern=?', 'tandemkross' ] )->first();
			if ( $existing === 0 )
			{
				\IPS\Db::i()->insert( 'gd_compliance_lowers', [
					'pattern'    => 'tandemkross',
					'platform'   => null,
					'action'     => 'force_clear',
					'note'       => 'Ruger .22 pistol/rimfire lower maker (Cthulhu, etc.) — not AWB. Brand-level clear supersedes per-UPC entries.',
					'created_at' => time(),
				] );
			}
		}
		catch ( \Throwable ) {}

		try
		{
			require_once \IPS\ROOT_PATH . '/applications/gdcompliance/sources/Lowers.php';
			\IPS\gdcompliance\Lowers::clearCache();
		}
		catch ( \Throwable ) {}

		/* ---------- Cache purges (ACP template change) ---------- */
		try { unset( \IPS\Data\Store::i()->acpmenu ); }             catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }            catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }        catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }          catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->canonical_templates ); } catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                   catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                   catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
