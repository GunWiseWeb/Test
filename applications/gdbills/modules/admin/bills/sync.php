<?php
namespace IPS\gdbills\modules\admin\bills;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _sync extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	const STATES = [
		'AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA',
		'ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR',
		'PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY',
	];

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'bills_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		$S    = \IPS\Settings::i();
		$lang = \IPS\Member::loggedIn()->language();

		$h = fn( string $k ) => htmlspecialchars( (string) $lang->addToStack( $k ), ENT_QUOTES, 'UTF-8' );

		$last    = (string) ( $S->gdbills_last_sync ?? '' );
		$lastTxt = $last !== '' ? htmlspecialchars( $last, ENT_QUOTES, 'UTF-8' ) : $h( 'gdbills_acp_sync_never' );

		/* Top-of-page warnings (use double-dash modifier per 5.0.18 ACP CSS,
		   verified against gdcatalog/products.php). Each ipsMessage wraps an
		   ipsBox_body for the panel padding. */
		$messages = '';
		if ( trim( (string) ( $S->gdbills_legiscan_key ?? '' ) ) === '' )
		{
			$messages .= '<div class="ipsMessage ipsMessage--warning"><div class="ipsBox_body ipsPad">'
				. $h( 'gdbills_acp_sync_no_key' ) . '</div></div>';
		}
		elseif ( (int) ( $S->gdbills_autosync_enabled ?? 1 ) === 0 )
		{
			$messages .= '<div class="ipsMessage ipsMessage--warning"><div class="ipsBox_body ipsPad">'
				. $h( 'gdbills_acp_sync_disabled' ) . '</div></div>';
		}

		$runUrl     = (string) \IPS\Http\Url::internal( 'app=gdbills&module=bills&controller=sync&do=run' )->csrf();
		$seedUrl    = (string) \IPS\Http\Url::internal( 'app=gdbills&module=bills&controller=sync&do=seedLaws' )->csrf();
		$reparseAct = (string) \IPS\Http\Url::internal( 'app=gdbills&module=bills&controller=sync&do=reparse' );

		/* Panel helper — wraps free-form content in the native ACP card chrome.
		   Pattern verified in gdcatalog/products.php (ipsBox + ipsBox_body + ipsPad). */
		$panel = function( string $titleKey, string $bodyHtml ) use ( $h ) {
			return '<div class="ipsBox" style="margin-bottom:16px">'
				. '<div class="ipsBox_body ipsPad">'
				. '<h2 class="ipsType_sectionHead" style="margin:0 0 10px">' . $h( $titleKey ) . '</h2>'
				. $bodyHtml
				. '</div></div>';
		};

		/* "Sync now" panel — manual run of the LegiScan fetch. */
		$lastLine = '<p style="margin:0 0 12px"><strong>' . $h( 'gdbills_acp_sync_last' ) . ':</strong> ' . $lastTxt . '</p>';
		$runBtn   = '<a href="' . htmlspecialchars( $runUrl, ENT_QUOTES, 'UTF-8' ) . '" class="ipsButton ipsButton--primary">'
			. $h( 'gdbills_acp_sync_button' ) . '</a>';
		$syncBody = '<p style="margin:0 0 10px">' . $h( 'gdbills_acp_sync_intro' ) . '</p>'
			. $lastLine . $runBtn;
		$syncPanel = $panel( 'gdbills_acp_sync_title', $syncBody );

		/* "Seed Existing Laws" — cheap, no API. */
		$seedBody  = '<p style="margin:0 0 10px">' . $h( 'gdbills_acp_seed_intro' ) . '</p>'
			. '<a href="' . htmlspecialchars( $seedUrl, ENT_QUOTES, 'UTF-8' ) . '" class="ipsButton ipsButton--secondary">'
			. $h( 'gdbills_acp_seed_button' ) . '</a>';
		$seedPanel = $panel( 'gdbills_acp_seed_title', $seedBody );

		/* "Detect Prior-Session Laws" — API-expensive; gate on key presence. */
		$detectBody = '<p style="margin:0 0 10px">' . $h( 'gdbills_acp_detect_intro' ) . '</p>'
			. '<div class="ipsMessage ipsMessage--warning" style="margin:0 0 12px"><div class="ipsBox_body ipsPad">'
			. $h( 'gdbills_acp_detect_warning' ) . '</div></div>';

		if ( trim( (string) ( $S->gdbills_legiscan_key ?? '' ) ) === '' )
		{
			$detectBody .= '<p style="margin:0;font-style:italic;color:#6b7480">' . $h( 'gdbills_acp_sync_no_key' ) . '</p>';
		}
		else
		{
			$opts = '<option value="">' . $h( 'gdbills_acp_detect_all_states' ) . '</option>';
			foreach ( self::STATES as $st )
			{
				$opts .= '<option value="' . $st . '">' . $st . '</option>';
			}
			$csrfKey   = \IPS\Session::i()->csrfKey;
			$actionUrl = (string) \IPS\Http\Url::internal( 'app=gdbills&module=bills&controller=sync&do=detectPriorLaws' );
			$detectBody .= '<form action="' . htmlspecialchars( $actionUrl, ENT_QUOTES, 'UTF-8' ) . '" method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">'
				. '<input type="hidden" name="csrfKey" value="' . htmlspecialchars( $csrfKey, ENT_QUOTES, 'UTF-8' ) . '">'
				. '<label style="display:inline-flex;align-items:center;gap:6px">'
				. $h( 'gdbills_acp_detect_state_label' ) . ':'
				. '<select name="state">' . $opts . '</select>'
				. '</label>'
				. '<button type="submit" class="ipsButton ipsButton--secondary">'
				. $h( 'gdbills_acp_detect_button' ) . '</button>'
				. '</form>';
		}
		$detectPanel = $panel( 'gdbills_acp_detect_title', $detectBody );

		/* "Re-parse stored bills" — zero-API: re-runs the corrected progress
		   logic over rows already in gd_bills. Fixes stage stalls (e.g. an
		   improved governor/signed matcher) without spending LegiScan quota. */
		$reparseOpts = '<option value="">' . $h( 'gdbills_acp_detect_all_states' ) . '</option>';
		foreach ( self::STATES as $st )
		{
			$reparseOpts .= '<option value="' . $st . '">' . $st . '</option>';
		}
		$reparseCsrf = \IPS\Session::i()->csrfKey;
		$reparseBody = '<p style="margin:0 0 10px">' . $h( 'gdbills_acp_reparse_intro' ) . '</p>'
			. '<form action="' . htmlspecialchars( $reparseAct, ENT_QUOTES, 'UTF-8' ) . '" method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">'
			. '<input type="hidden" name="csrfKey" value="' . htmlspecialchars( $reparseCsrf, ENT_QUOTES, 'UTF-8' ) . '">'
			. '<label style="display:inline-flex;align-items:center;gap:6px">'
			. $h( 'gdbills_acp_detect_state_label' ) . ':'
			. '<select name="state">' . $reparseOpts . '</select>'
			. '</label>'
			. '<button type="submit" class="ipsButton ipsButton--secondary">'
			. $h( 'gdbills_acp_reparse_button' ) . '</button>'
			. '</form>';
		$reparsePanel = $panel( 'gdbills_acp_reparse_title', $reparseBody );

		\IPS\Output::i()->title  = (string) $lang->addToStack( 'gdbills_acp_sync_title' );
		\IPS\Output::i()->output = $messages . $syncPanel . $seedPanel . $reparsePanel . $detectPanel;
	}

	protected function run(): void
	{
		\IPS\Session::i()->csrfCheck();

		$counts = [ 'processed' => 0, 'upserted' => 0, 'skipped' => 0, 'errors' => 0 ];
		try
		{
			$counts = \IPS\gdbills\LegiScan::fetchAllBills();
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'manual sync: ' . $e->getMessage(), 'gdbills' ); } catch ( \Throwable ) {}
		}

		$msg = (string) \IPS\Member::loggedIn()->language()->addToStack( 'gdbills_acp_sync_done', false, [
			'sprintf' => [ (int) $counts['processed'] ],
		] );
		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdbills&module=bills&controller=sync' ),
			$msg
		);
	}

	protected function seedLaws(): void
	{
		\IPS\Session::i()->csrfCheck();

		$counts = [ 'processed' => 0, 'upserted' => 0, 'skipped' => 0, 'errors' => 0 ];
		try
		{
			$counts = \IPS\gdbills\LegiScan::seedExistingLaws();
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'seed laws: ' . $e->getMessage(), 'gdbills' ); } catch ( \Throwable ) {}
		}

		$msg = (string) \IPS\Member::loggedIn()->language()->addToStack( 'gdbills_acp_seed_done', false, [
			'sprintf' => [ (int) $counts['upserted'], (int) $counts['processed'] ],
		] );
		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdbills&module=bills&controller=sync' ),
			$msg
		);
	}

	protected function reparse(): void
	{
		\IPS\Session::i()->csrfCheck();

		$state = strtoupper( trim( (string) ( \IPS\Request::i()->state ?? '' ) ) );
		if ( $state !== '' && !in_array( $state, self::STATES, true ) ) { $state = ''; }

		$counts = [ 'processed' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => 0 ];
		try
		{
			$counts = \IPS\gdbills\LegiScan::reparseStored( $state !== '' ? $state : null );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'reparse: ' . $e->getMessage(), 'gdbills' ); } catch ( \Throwable ) {}
		}

		$msg = (string) \IPS\Member::loggedIn()->language()->addToStack( 'gdbills_acp_reparse_done', false, [
			'sprintf' => [ (int) $counts['updated'], (int) $counts['processed'] ],
		] );
		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdbills&module=bills&controller=sync' ),
			$msg
		);
	}

	protected function detectPriorLaws(): void
	{
		\IPS\Session::i()->csrfCheck();

		$state = strtoupper( trim( (string) ( \IPS\Request::i()->state ?? '' ) ) );
		if ( $state !== '' && !in_array( $state, self::STATES, true ) )
		{
			$state = '';
		}

		$counts = [ 'processed' => 0, 'upserted' => 0, 'skipped' => 0, 'errors' => 0 ];
		try
		{
			$counts = \IPS\gdbills\LegiScan::detectPriorSessionLaws( $state !== '' ? $state : null );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'detect prior laws: ' . $e->getMessage(), 'gdbills' ); } catch ( \Throwable ) {}
		}

		$msg = (string) \IPS\Member::loggedIn()->language()->addToStack( 'gdbills_acp_detect_done', false, [
			'sprintf' => [ (int) $counts['upserted'], (int) $counts['processed'] ],
		] );
		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdbills&module=bills&controller=sync' ),
			$msg
		);
	}
}

class sync extends _sync {}
