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

		$now   = time();
		$where = [ [ 'status=?', 'approved' ], [ '( end_date IS NULL OR end_date >= ? )', $now ] ];

		$rebates = [];
		foreach ( \IPS\Db::i()->select( '*', 'gd_rebates', $where, 'CASE WHEN end_date IS NULL THEN 1 ELSE 0 END ASC, end_date ASC' ) as $r )
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
