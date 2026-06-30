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

		$last = (string) ( $S->gdbills_last_sync ?? '' );
		$lastTxt = $last !== '' ? $last : (string) $lang->addToStack( 'gdbills_acp_sync_never' );

		$messages = '';
		if ( trim( (string) ( $S->gdbills_legiscan_key ?? '' ) ) === '' )
		{
			$messages .= '<div class="ipsMessage ipsMessage_warning">' . htmlspecialchars( (string) $lang->addToStack( 'gdbills_acp_sync_no_key' ), ENT_QUOTES ) . '</div>';
		}
		elseif ( (int) ( $S->gdbills_autosync_enabled ?? 1 ) === 0 )
		{
			$messages .= '<div class="ipsMessage ipsMessage_warning">' . htmlspecialchars( (string) $lang->addToStack( 'gdbills_acp_sync_disabled' ), ENT_QUOTES ) . '</div>';
		}

		$runUrl    = (string) \IPS\Http\Url::internal( 'app=gdbills&module=bills&controller=sync&do=run' )->csrf();
		$seedUrl   = (string) \IPS\Http\Url::internal( 'app=gdbills&module=bills&controller=sync&do=seedLaws' )->csrf();

		$runBtn = '<p><a href="' . htmlspecialchars( $runUrl, ENT_QUOTES ) . '" class="ipsButton ipsButton_primary">'
			. htmlspecialchars( (string) $lang->addToStack( 'gdbills_acp_sync_button' ), ENT_QUOTES ) . '</a></p>';

		/* "Seed Existing Laws" — cheap, no API. Always available. */
		$seedSection = '<hr><h3>' . htmlspecialchars( (string) $lang->addToStack( 'gdbills_acp_seed_title' ), ENT_QUOTES ) . '</h3>'
			. '<p>' . htmlspecialchars( (string) $lang->addToStack( 'gdbills_acp_seed_intro' ), ENT_QUOTES ) . '</p>'
			. '<p><a href="' . htmlspecialchars( $seedUrl, ENT_QUOTES ) . '" class="ipsButton ipsButton_normal">'
			. htmlspecialchars( (string) $lang->addToStack( 'gdbills_acp_seed_button' ), ENT_QUOTES ) . '</a></p>';

		/* "Detect Prior-Session Laws" — API-expensive, requires key + state dropdown. */
		$detectSection = '<hr><h3>' . htmlspecialchars( (string) $lang->addToStack( 'gdbills_acp_detect_title' ), ENT_QUOTES ) . '</h3>'
			. '<p>' . htmlspecialchars( (string) $lang->addToStack( 'gdbills_acp_detect_intro' ), ENT_QUOTES ) . '</p>'
			. '<div class="ipsMessage ipsMessage_warning">' . htmlspecialchars( (string) $lang->addToStack( 'gdbills_acp_detect_warning' ), ENT_QUOTES ) . '</div>';

		if ( trim( (string) ( $S->gdbills_legiscan_key ?? '' ) ) === '' )
		{
			$detectSection .= '<p><em>' . htmlspecialchars( (string) $lang->addToStack( 'gdbills_acp_sync_no_key' ), ENT_QUOTES ) . '</em></p>';
		}
		else
		{
			$opts = '<option value="">' . htmlspecialchars( (string) $lang->addToStack( 'gdbills_acp_detect_all_states' ), ENT_QUOTES ) . '</option>';
			foreach ( self::STATES as $st )
			{
				$opts .= '<option value="' . $st . '">' . $st . '</option>';
			}
			$csrfKey = \IPS\Session::i()->csrfKey;
			$actionUrl = (string) \IPS\Http\Url::internal( 'app=gdbills&module=bills&controller=sync&do=detectPriorLaws' );
			$detectSection .= '<form action="' . htmlspecialchars( $actionUrl, ENT_QUOTES ) . '" method="post" style="margin-top:10px">'
				. '<input type="hidden" name="csrfKey" value="' . htmlspecialchars( $csrfKey, ENT_QUOTES ) . '">'
				. '<label style="display:inline-block;margin-right:8px">' . htmlspecialchars( (string) $lang->addToStack( 'gdbills_acp_detect_state_label' ), ENT_QUOTES ) . ':</label>'
				. '<select name="state" style="margin-right:8px">' . $opts . '</select>'
				. '<button type="submit" class="ipsButton ipsButton_normal">'
				. htmlspecialchars( (string) $lang->addToStack( 'gdbills_acp_detect_button' ), ENT_QUOTES ) . '</button>'
				. '</form>';
		}

		\IPS\Output::i()->title  = (string) $lang->addToStack( 'gdbills_acp_sync_title' );
		\IPS\Output::i()->output = '<p>' . htmlspecialchars( (string) $lang->addToStack( 'gdbills_acp_sync_intro' ), ENT_QUOTES ) . '</p>'
			. $messages
			. '<p><strong>' . htmlspecialchars( (string) $lang->addToStack( 'gdbills_acp_sync_last' ), ENT_QUOTES ) . ':</strong> ' . htmlspecialchars( $lastTxt, ENT_QUOTES ) . '</p>'
			. $runBtn
			. $seedSection
			. $detectSection;
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
