<?php
/**
 * @brief  GD Compliance — upgrade 1.6.13
 *
 * Landing:
 *   1. Lowers::classify() default flipped from 'flag' → 'review' for
 *      ambiguous cat154 lowers (no AR/AK brand or platform signal).
 *      Prevents false-flagging Tandemkross Cthulhu Ruger .22 pistol
 *      lowers and similar non-AR receivers. Ships in sources/Lowers.php.
 *   2. New tier-1 AR-lower brand list — Aero Precision, PSA, Anderson,
 *      Spike's, Shark Coast, Bravo Company, Noveske, etc. — plus an
 *      ambiguous-brand tier (Ruger, Colt) that only flags with AR
 *      context and no rimfire/pistol/revolver block token.
 *   3. Brand-level curated `tandemkross` force_clear seed (guarded —
 *      idempotent uq_pattern index catches duplicates). Replaces the
 *      per-UPC Cthulhu clears with one brand-level entry that catches
 *      current + future Tandemkross variants.
 *
 * Ambiguous rows land in the v1.6.12 Lowers review tab (review_type
 * = 'lower', suggested_status = 'lower_review', roster_state = '').
 *
 * DEFENSIVE — because this replaces the sole upg dir per rule #79,
 * carries the v1.6.12 gd_compliance_review.review_type column ADD
 * and the v1.6.10 gd_compliance_lowers CREATE forward for any
 * install skipping intermediate versions.
 */

namespace IPS\gdcompliance\setup\upg_10613;

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
		/* ---------- Defensive gd_compliance_lowers table (v1.6.10) ---------- */
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
			catch ( \Throwable ) { /* non-fatal — page rendering still works empty */ }
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
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10613 addColumn review_type: ' . $e->getMessage(), 'gdcompliance_upg_10613' ); }
				catch ( \Throwable ) {}
			}

			/* Backfill (only when column was freshly added — skips
			   the loop cost on installs already at v1.6.12+). */
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
					\IPS\Db::i()->update(
						'gd_compliance_review',
						[ 'review_type' => $type ],
						[ $whereFrag . ' AND review_type<>?', $type ]
					);
				}
				catch ( \Throwable ) {}
			}
		}

		/* ---------- Seed 'tandemkross' brand-level force_clear ---------- */
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
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10613 tandemkross seed: ' . $e->getMessage(), 'gdcompliance_upg_10613' ); }
			catch ( \Throwable ) {}
		}

		/* Warm Lowers so the new brand tables are live in this PHP
		   process without a class reload. */
		try
		{
			require_once \IPS\ROOT_PATH . '/applications/gdcompliance/sources/Lowers.php';
			\IPS\gdcompliance\Lowers::clearCache();
		}
		catch ( \Throwable ) {}

		/* ---------- Cache purges ---------- */
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
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
