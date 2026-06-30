<?php
/**
 * @brief  GD Bills — Front controller (/bills/)
 *
 * manage()    → renders the page (map + list).
 * mapData()   → JSON: getCountsByState() for the map.
 * stateBills()→ HTML fragment: enacted + pending bills for one state
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

	protected function manage(): void
	{
		\IPS\Output::i()->cssFiles = array_merge( \IPS\Output::i()->cssFiles,
			\IPS\Theme::i()->css( 'bills.css', 'gdbills', 'interface' )
		);
		\IPS\Output::i()->jsFiles = array_merge( \IPS\Output::i()->jsFiles,
			\IPS\Output::i()->js( 'map.js', 'gdbills', 'interface' )
		);

		$state = strtoupper( trim( (string) ( \IPS\Request::i()->state ?? '' ) ) );
		$type  = (string) ( \IPS\Request::i()->type ?? '' );

		$counts = \IPS\gdbills\Bill::getCountsByState();
		$total  = (int) array_sum( $counts );

		if ( $state !== '' && preg_match( '/^[A-Z]{2}$/', $state ) )
		{
			/* Deep-linked state view (/bills/?state=IL) — render server-side
			   so search engines and direct links see the bill list. */
			$enacted = \IPS\gdbills\Bill::getByState( $state, 'enacted' );
			$pending = \IPS\gdbills\Bill::getByState( $state, 'pending' );
		}
		else
		{
			/* Landing view — map only. Bills load on tile click via the
			   stateBills AJAX endpoint into the modal. */
			$enacted = [];
			$pending = [];
		}

		$ajaxStateUrl = (string) \IPS\Http\Url::internal(
			'app=gdbills&module=bills&controller=bills&do=stateBills',
			'front', 'gdbills_action', [ 'stateBills' ]
		);
		$ajaxMapUrl = (string) \IPS\Http\Url::internal(
			'app=gdbills&module=bills&controller=bills&do=mapData',
			'front', 'gdbills_action', [ 'mapData' ]
		);
		$pageUrl = (string) \IPS\Http\Url::internal(
			'app=gdbills&module=bills&controller=bills', 'front', 'gdbills_page'
		);

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gdbills_page_title' );
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'bills', 'gdbills', 'front' )->page(
			$counts, $total, self::STATES, $enacted, $pending,
			$state, $type, $pageUrl, $ajaxStateUrl, $ajaxMapUrl,
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
		$enacted = \IPS\gdbills\Bill::getByState( $state, 'enacted' );
		$pending = \IPS\gdbills\Bill::getByState( $state, 'pending' );
		$html = (string) \IPS\Theme::i()->getTemplate( 'bills', 'gdbills', 'front' )->stateModal(
			$state, $enacted, $pending
		);
		\IPS\Output::i()->json( [ 'ok' => true, 'state' => $state, 'html' => $html ] );
	}
}

class bills extends _bills {}
