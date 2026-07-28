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

		/* v1.0.13 — DB-level sort order:
		     1) expired ALWAYS last (regardless of featured/sort_order)
		     2) featured=1 above featured=0 within the active section
		     3) manual sort_order ASC (Derrick's drag-order / up-down
		        arrows in the ACP)
		     4) end_date null-last, then end_date ASC (existing fallback)
		   The template's inline JS respects the same ordering when
		   the user hasn't changed the client-side sort dropdown. */
		$orderBy = '('
			. ' CASE WHEN end_date IS NOT NULL AND end_date < ' . (int) $now . ' THEN 1 ELSE 0 END'
			. ' ) ASC,'
			. ' featured DESC,'
			. ' sort_order ASC,'
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
			/* v1.0.10 — resilient logo match. Rebate manufacturer can
			   carry a trailing distinguishing suffix ("H&K 1", "H&K 2"
			   for two simultaneous promos from one brand) that isn't
			   in gd_rebate_logos. Three tiers:
			     1) exact case-insensitive match (current behavior)
			     2) strip a trailing NUMERIC-anchored short token —
			        e.g. " 1", " 2", " #1", " (2026)", " #Spring2026" —
			        then retry. The regex REQUIRES the trailing token
			        to begin with a digit or "#" so it never eats a
			        legitimate word like "Wesson", "Sauer", "Armory".
			     3) prefix match — any known logo whose key + " "
			        prefixes this rebate's manufacturer (catches
			        "H&K - Spring" -> H&K logo where the regex tier
			        wouldn't fire). */
			$logoMfr = trim( (string) ( $rr['manufacturer'] ?? '' ) );
			$logoKey = mb_strtolower( $logoMfr );
			$logo    = $logos[ $logoKey ] ?? '';

			if ( $logo === '' && $logoMfr !== '' )
			{
				$stripped = preg_replace( '/\s+[\(\[]?[#\d][\w#-]{0,15}[\)\]]?\s*$/u', '', $logoMfr );
				$baseKey  = mb_strtolower( trim( (string) $stripped ) );
				if ( $baseKey !== '' && $baseKey !== $logoKey )
				{
					$logo = $logos[ $baseKey ] ?? '';
				}
			}

			if ( $logo === '' && $logoKey !== '' )
			{
				foreach ( $logos as $knownKey => $knownUrl )
				{
					if ( $knownKey !== '' && str_starts_with( $logoKey, $knownKey . ' ' ) )
					{
						$logo = $knownUrl;
						break;
					}
				}
			}

			$rr['_logo'] = $logo;

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
		/* v1.0.10 — pass the ACP show-expired flag so the template
		   only renders the "Hide expired" checkbox when expired
		   rebates can actually appear in the DOM. */
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'rebates', 'gdrebates', 'front' )->browse( $rebates, $mfrs, $showExpired );
	}
}
class browse extends _browse {}
