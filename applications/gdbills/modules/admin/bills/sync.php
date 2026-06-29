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

		$runUrl = (string) \IPS\Http\Url::internal( 'app=gdbills&module=bills&controller=sync&do=run' )->csrf();
		$runBtn = '<p><a href="' . htmlspecialchars( $runUrl, ENT_QUOTES ) . '" class="ipsButton ipsButton_primary">'
			. htmlspecialchars( (string) $lang->addToStack( 'gdbills_acp_sync_button' ), ENT_QUOTES ) . '</a></p>';

		\IPS\Output::i()->title  = (string) $lang->addToStack( 'gdbills_acp_sync_title' );
		\IPS\Output::i()->output = '<p>' . htmlspecialchars( (string) $lang->addToStack( 'gdbills_acp_sync_intro' ), ENT_QUOTES ) . '</p>'
			. $messages
			. '<p><strong>' . htmlspecialchars( (string) $lang->addToStack( 'gdbills_acp_sync_last' ), ENT_QUOTES ) . ':</strong> ' . htmlspecialchars( $lastTxt, ENT_QUOTES ) . '</p>'
			. $runBtn;
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
}

class sync extends _sync {}
