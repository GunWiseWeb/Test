<?php
namespace IPS\gdrebates\modules\front\rebates;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _browse extends \IPS\Dispatcher\Controller
{
	protected function manage(): void
	{
		\IPS\Output::i()->cssFiles = array_merge( \IPS\Output::i()->cssFiles, \IPS\Theme::i()->css( 'rebates.css', 'gdrebates', 'front' ) );

		$now = time();

		/* v1.0.9 — respect gdrebates_show_expired setting.
		     ON  (default): include expired rebates in the result set;
		                    template renders them grayed out with an
		                    "Expired" tag and sorts them last.
		     OFF          : exclude rebates whose end_date is in the past
		                    entirely at the query layer. */
		$showExpired = (bool) ( \IPS\Settings::i()->gdrebates_show_expired ?? 1 );

		$where = [ [ 'status=?', 'approved' ] ];
		if ( !$showExpired )
		{
			$where[] = [ '( end_date IS NULL OR end_date >= ? )', $now ];
		}

		/* Sort at the DB layer so active/non-expired first (soonest
		   end_date at the top; null end_date at the very bottom of
		   the active section), expired last. The template's inline
		   JS respects this ordering when the user hasn't changed
		   the sort dropdown. */
		$orderBy = '('
			. ' CASE WHEN end_date IS NOT NULL AND end_date < ' . (int) $now . ' THEN 1 ELSE 0 END'
			. ' ) ASC,'
			. ' CASE WHEN end_date IS NULL THEN 1 ELSE 0 END ASC,'
			. ' end_date ASC';

		$rebates = [];
		foreach ( \IPS\Db::i()->select( '*', 'gd_rebates', $where, $orderBy ) as $r )
		{
			$rebates[] = $r;
		}

		$logos = [];
		foreach ( \IPS\Db::i()->select( 'manufacturer, logo_url', 'gd_rebate_logos' ) as $l )
		{
			if ( trim( (string) $l['logo_url'] ) !== '' )
			{
				$logos[ mb_strtolower( trim( (string) $l['manufacturer'] ) ) ] = (string) $l['logo_url'];
			}
		}

		foreach ( $rebates as &$rr )
		{
			$rr['_logo'] = $logos[ mb_strtolower( trim( (string) $rr['manufacturer'] ) ) ] ?? '';

			/* v1.0.9 Part 2 — split eligible_models into a chip list.
			   Parser emits ", " (comma+space) between distinct model
			   entries; a naive explode on ", " matches the real
			   Springfield Armory data exactly. Individual entries may
			   themselves contain commas in descriptive text; that's
			   fine — the chips still render one per parser-emitted
			   entry, which is the intended granularity. */
			$rr['_models_list'] = [];
			$rawModels          = trim( (string) ( $rr['eligible_models'] ?? '' ) );
			if ( $rawModels !== '' )
			{
				$parts = preg_split( '/,\s+/', $rawModels );
				foreach ( (array) $parts as $p )
				{
					$p = trim( (string) $p );
					if ( $p !== '' ) { $rr['_models_list'][] = $p; }
				}
			}

			/* v1.0.9 Part 3 — days-left + expired computation.
			   Uses end_date (the same field the card labels "Ends"),
			   so the countdown matches what the user sees below it.
			   * _is_expired    : end_date is set AND in the past
			   * _days_left     : integer days remaining (null when
			                      no end_date); negative when expired */
			$endTs = isset( $rr['end_date'] ) && $rr['end_date'] ? (int) $rr['end_date'] : 0;
			$rr['_is_expired'] = FALSE;
			$rr['_days_left']  = NULL;
			if ( $endTs > 0 )
			{
				$secondsLeft       = $endTs - $now;
				$rr['_days_left']  = (int) floor( $secondsLeft / 86400 );
				$rr['_is_expired'] = $secondsLeft < 0;
			}
		}
		unset( $rr );

		$mfrs = [];
		foreach ( $rebates as $r ) { if ( $r['manufacturer'] !== '' ) { $mfrs[ $r['manufacturer'] ] = true; } }
		$mfrs = array_keys( $mfrs );
		sort( $mfrs );

		\IPS\Output::i()->title = \IPS\Member::loggedIn()->language()->addToStack( 'gdrebates_page_title' );
		\IPS\Output::i()->breadcrumb[] = [ NULL, \IPS\Member::loggedIn()->language()->addToStack( 'gdrebates_page_title' ) ];
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'rebates', 'gdrebates', 'front' )->browse( $rebates, $mfrs );
	}
}
class browse extends _browse {}
