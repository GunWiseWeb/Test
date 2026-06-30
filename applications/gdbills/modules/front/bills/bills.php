<?php
/**
 * @brief  GD Bills — Front controller (/bills/)
 *
 * manage()    → renders the page (map + filter bar + list).
 * mapData()   → JSON: getCountsByState() for the map.
 * stateBills()→ HTML fragment: laws + enacted + pending for one state
 *               (used as the modal content).
 */

namespace IPS\gdbills\modules\front\bills;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _bills extends \IPS\Dispatcher\Controller
{
	const STATES = [
		'AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA',
		'ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR',
		'PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY',
	];

	const VALID_TYPES = [ 'all', 'law', 'enacted', 'pending' ];

	protected function manage(): void
	{
		\IPS\Output::i()->cssFiles = array_merge( \IPS\Output::i()->cssFiles,
			\IPS\Theme::i()->css( 'bills.css', 'gdbills', 'interface' )
		);
		\IPS\Output::i()->jsFiles = array_merge( \IPS\Output::i()->jsFiles,
			\IPS\Output::i()->js( 'map.js', 'gdbills', 'interface' )
		);

		$state    = strtoupper( trim( (string) ( \IPS\Request::i()->state ?? '' ) ) );
		if ( !preg_match( '/^[A-Z]{2}$/', $state ) ) { $state = ''; }

		$type     = strtolower( trim( (string) ( \IPS\Request::i()->type ?? '' ) ) );
		if ( $type === '' ) { $type = 'all'; }
		if ( !in_array( $type, self::VALID_TYPES, true ) ) { $type = 'all'; }

		$dateFrom = self::cleanRequestDate( (string) ( \IPS\Request::i()->date_from ?? '' ) );
		$dateTo   = self::cleanRequestDate( (string) ( \IPS\Request::i()->date_to   ?? '' ) );

		$hasFilter = ( $type !== 'all' || $dateFrom !== '' || $dateTo !== '' );
		$showLists = ( $state !== '' || $hasFilter );

		$counts = \IPS\gdbills\Bill::getCountsByState();
		$total  = (int) array_sum( $counts );

		$laws    = [];
		$enacted = [];
		$pending = [];
		if ( $showLists )
		{
			$buckets = \IPS\gdbills\Bill::getThreeBuckets(
				$state !== '' ? $state : null,
				$dateFrom !== '' ? $dateFrom : null,
				$dateTo   !== '' ? $dateTo   : null
			);
			$laws    = $buckets['law']     ?? [];
			$enacted = $buckets['enacted'] ?? [];
			$pending = $buckets['pending'] ?? [];
		}

		/* Visible count respects the active type filter — what the user actually sees. */
		$shownCount = 0;
		if ( $type === 'all' || $type === 'law' )     { $shownCount += count( $laws ); }
		if ( $type === 'all' || $type === 'enacted' ) { $shownCount += count( $enacted ); }
		if ( $type === 'all' || $type === 'pending' ) { $shownCount += count( $pending ); }
		$shownLabel = (string) \IPS\Member::loggedIn()->language()->addToStack( 'gdbills_showing_count', FALSE, [
			'sprintf' => [ (int) $shownCount ],
		] );

		/* "Last Updated" — Bill::setMeta('last_update_global', date('Y-m-d H:i:s')) is
		   written at the end of each fetchAllBills run. Format for display as M/D/YYYY. */
		$lastUpdatedRaw     = (string) ( \IPS\gdbills\Bill::getMeta( 'last_update_global' ) ?? '' );
		$lastUpdatedDisplay = '';
		if ( $lastUpdatedRaw !== '' )
		{
			$ts = strtotime( $lastUpdatedRaw );
			if ( $ts !== false ) { $lastUpdatedDisplay = date( 'n/j/Y', $ts ); }
		}

		$pageUrl = (string) \IPS\Http\Url::internal(
			'app=gdbills&module=bills&controller=bills', 'front', 'gdbills_page'
		);
		$ajaxStateUrl = (string) \IPS\Http\Url::internal(
			'app=gdbills&module=bills&controller=bills&do=stateBills',
			'front', 'gdbills_action', [ 'stateBills' ]
		);
		$ajaxMapUrl = (string) \IPS\Http\Url::internal(
			'app=gdbills&module=bills&controller=bills&do=mapData',
			'front', 'gdbills_action', [ 'mapData' ]
		);

		/* Build the 4 type-button URLs + a clear-dates URL, preserving the other
		   active filter params. Templates only render plain {$var} interpolation. */
		$base = [];
		if ( $state    !== '' ) { $base['state']     = $state; }
		if ( $dateFrom !== '' ) { $base['date_from'] = $dateFrom; }
		if ( $dateTo   !== '' ) { $base['date_to']   = $dateTo; }

		$mkUrl = function( array $overrides ) use ( $pageUrl, $base ) {
			$q = array_filter( array_merge( $base, $overrides ), fn( $v ) => $v !== '' && $v !== null );
			return empty( $q ) ? $pageUrl : ( $pageUrl . '?' . http_build_query( $q ) );
		};
		$typeUrls = [
			'all'     => $mkUrl( [ 'type' => 'all' ] ),
			'law'     => $mkUrl( [ 'type' => 'law' ] ),
			'enacted' => $mkUrl( [ 'type' => 'enacted' ] ),
			'pending' => $mkUrl( [ 'type' => 'pending' ] ),
		];
		/* Clear: drop date params, keep state + type. */
		$clearBase = [];
		if ( $state !== '' )    { $clearBase['state'] = $state; }
		if ( $type  !== 'all' ) { $clearBase['type']  = $type; }
		$clearUrl = empty( $clearBase ) ? $pageUrl : ( $pageUrl . '?' . http_build_query( $clearBase ) );

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gdbills_page_title' );
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'bills', 'gdbills', 'front' )->page(
			$counts, $total, self::STATES,
			$laws, $enacted, $pending,
			$state, $type, $dateFrom, $dateTo,
			$lastUpdatedDisplay, $shownLabel,
			$pageUrl, $typeUrls, $clearUrl,
			$ajaxStateUrl, $ajaxMapUrl,
			(string) ( \IPS\Settings::i()->gdbills_session_note ?? '' )
		);
	}

	protected function mapData(): void
	{
		$counts = \IPS\gdbills\Bill::getCountsByState();
		\IPS\Output::i()->json( [ 'counts' => $counts ] );
	}

	protected function stateBills(): void
	{
		$state = strtoupper( trim( (string) ( \IPS\Request::i()->state ?? '' ) ) );
		if ( !preg_match( '/^[A-Z]{2}$/', $state ) )
		{
			\IPS\Output::i()->json( [ 'ok' => false, 'error' => 'bad_state' ] );
			return;
		}
		$buckets = \IPS\gdbills\Bill::getThreeBuckets( $state );
		$html = (string) \IPS\Theme::i()->getTemplate( 'bills', 'gdbills', 'front' )->stateModal(
			$state, $buckets['law'], $buckets['enacted'], $buckets['pending']
		);
		\IPS\Output::i()->json( [ 'ok' => true, 'state' => $state, 'html' => $html ] );
	}

	/* YYYY-MM-DD only — empty string passes through; invalid input is rejected. */
	protected static function cleanRequestDate( string $v ): string
	{
		$v = trim( $v );
		if ( $v === '' ) { return ''; }
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
	}
}

class bills extends _bills {}
