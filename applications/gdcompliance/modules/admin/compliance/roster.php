<?php
namespace IPS\gdcompliance\modules\admin\compliance;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _roster extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'compliance_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		$lang = \IPS\Member::loggedIn()->language();
		$h    = fn( string $k ) => htmlspecialchars( (string) $lang->addToStack( $k ), ENT_QUOTES, 'UTF-8' );

		$total = $current = $expired = 0;
		try { $total   = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_compliance_ca_roster' )->first(); }                       catch ( \Throwable ) {}
		try { $current = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_compliance_ca_roster', [ 'is_current=1' ] )->first(); } catch ( \Throwable ) {}
		$expired = max( 0, $total - $current );

		$lastFetched = '';
		try { $lastFetched = (string) \IPS\Db::i()->select( 'MAX(fetched_at)', 'gd_compliance_ca_roster' )->first(); } catch ( \Throwable ) {}
		$lastFetchedTxt = $lastFetched ? date( 'Y-m-d H:i:s', (int) $lastFetched ) : $h( 'gdcompliance_acp_roster_never' );

		$refreshUrl = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=roster&do=refresh' )->csrf();

		$rosterUrl = htmlspecialchars( \IPS\gdcompliance\Roster::ROSTER_URL, ENT_QUOTES, 'UTF-8' );
		$intro     = '<div class="ipsBox" style="margin-bottom:16px"><div class="ipsBox_body ipsPad">'
			. '<h2 class="ipsType_sectionHead" style="margin:0 0 10px">' . $h( 'gdcompliance_acp_roster_title' ) . '</h2>'
			. '<p style="margin:0 0 10px">' . $h( 'gdcompliance_acp_roster_intro' ) . '</p>'
			. '<p style="margin:0 0 6px"><strong>' . $h( 'gdcompliance_acp_roster_source' ) . ':</strong> '
			. '<a href="' . $rosterUrl . '" target="_blank" rel="noopener" style="word-break:break-all">' . $rosterUrl . '</a></p>'
			. '<p style="margin:0 0 6px"><strong>' . $h( 'gdcompliance_acp_roster_last' ) . ':</strong> ' . $lastFetchedTxt . '</p>'
			. '<p style="margin:0 0 14px">'
			. '<strong>' . $h( 'gdcompliance_acp_roster_total' ) . ':</strong> ' . number_format( $total ) . ' &middot; '
			. '<strong>' . $h( 'gdcompliance_acp_roster_current' ) . ':</strong> ' . number_format( $current ) . ' &middot; '
			. '<strong>' . $h( 'gdcompliance_acp_roster_expired' ) . ':</strong> ' . number_format( $expired )
			. '</p>'
			. '<a href="' . htmlspecialchars( $refreshUrl, ENT_QUOTES, 'UTF-8' ) . '" class="ipsButton ipsButton--primary">'
			. $h( 'gdcompliance_acp_roster_refresh' ) . '</a>'
			. '</div></div>';

		/* The browser table — sortable native ACP chrome over the roster table. */
		$baseUrl = \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=roster' );
		$table   = new \IPS\Helpers\Table\Db( 'gd_compliance_ca_roster', $baseUrl );
		$table->langPrefix    = 'gdcompliance_acp_roster_col_';
		$table->include       = [ 'manufacturer', 'model_raw', 'caliber', 'gun_type', 'barrel', 'expired_date', 'is_current' ];
		$table->sortBy        = $table->sortBy ?: 'manufacturer';
		$table->sortDirection = $table->sortDirection ?: 'asc';

		$table->parsers = [
			'manufacturer' => function( $v ) { return '<strong>' . htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) . '</strong>'; },
			'model_raw'    => function( $v ) { return '<span style="font-family:ui-monospace,monospace;font-size:12px">' . htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) . '</span>'; },
			'caliber'      => function( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); },
			'gun_type'     => function( $v ) { return '<span style="color:#475569">' . htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) . '</span>'; },
			'barrel'       => function( $v ) { return $v ? htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) : '<span style="color:#cbd5e1">—</span>'; },
			'expired_date' => function( $v ) { return $v ? htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) : '<span style="color:#cbd5e1">—</span>'; },
			'is_current'   => function( $v ) {
				return ( (int) $v === 1 )
					? '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:#dcfce7;color:#14532d">CURRENT</span>'
					: '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:#fee2e2;color:#991b1b">EXPIRED</span>';
			},
		];

		\IPS\Output::i()->title  = $lang->addToStack( 'gdcompliance_acp_roster_title' );
		\IPS\Output::i()->output = $intro . (string) $table;
	}

	protected function refresh(): void
	{
		\IPS\Session::i()->csrfCheck();

		$counts = [ 'rows' => 0, 'pages' => 0, 'current' => 0, 'expired' => 0, 'errors' => [], 'duration_ms' => 0 ];
		try
		{
			$counts = \IPS\gdcompliance\Roster::fetchAndParse();
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'roster refresh: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}

		$msg = (string) \IPS\Member::loggedIn()->language()->addToStack( 'gdcompliance_acp_roster_done', false, [
			'sprintf' => [ (int) $counts['rows'], (int) $counts['current'], (int) $counts['expired'], (int) $counts['pages'] ],
		] );
		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=roster' ),
			$msg
		);
	}
}

class roster extends _roster {}
