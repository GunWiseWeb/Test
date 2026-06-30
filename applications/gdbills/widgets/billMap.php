<?php
namespace IPS\gdbills\widgets;

use IPS\Output;
use IPS\Theme;
use IPS\Widget\PermissionCache;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class billMap extends PermissionCache
{
	public string $key = 'billMap';
	public string $app = 'gdbills';

	public function init(): void
	{
		Output::i()->cssFiles = array_merge( Output::i()->cssFiles, Theme::i()->css( 'bills.css', 'gdbills', 'interface' ) );
		Output::i()->jsFiles  = array_merge( Output::i()->jsFiles,  Output::i()->js( 'map.js', 'gdbills', 'interface' ) );
		parent::init();
	}

	public function render(): string
	{
		$states = [
			'AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA',
			'ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR',
			'PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY',
		];
		$counts = [];
		try { $counts = \IPS\gdbills\Bill::getCountsByState(); } catch ( \Throwable ) {}
		$total = (int) array_sum( $counts );

		$pageUrl      = (string) \IPS\Http\Url::internal( 'app=gdbills&module=bills&controller=bills', 'front', 'gdbills_page' );
		/* AJAX endpoints used to ship via a 'gdbills_action' FURL whose
		   bare "{@do}" pattern shadowed core profile / edit-profile URLs.
		   Pretty URLs aren't useful for internal AJAX — fall back to the
		   plain query-string Url::internal() with no seoTemplate. */
		$ajaxStateUrl = (string) \IPS\Http\Url::internal( 'app=gdbills&module=bills&controller=bills&do=stateBills' );
		$ajaxMapUrl   = (string) \IPS\Http\Url::internal( 'app=gdbills&module=bills&controller=bills&do=mapData' );

		$enacted = []; $pending = [];
		return (string) Theme::i()->getTemplate( 'bills', 'gdbills', 'front' )->page(
			$counts, $total, $states, $enacted, $pending,
			'', '', $pageUrl, $ajaxStateUrl, $ajaxMapUrl,
			(string) ( \IPS\Settings::i()->gdbills_session_note ?? '' )
		);
	}
}
