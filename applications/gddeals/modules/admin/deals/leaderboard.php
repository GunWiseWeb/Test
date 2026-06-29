<?php
/**
 * @brief  GD Deals — Leaderboard ACP settings
 *
 * Controls the front-end /top/ leaderboard: enable/disable, default
 * window, per-board enables, members board source filter.
 */

namespace IPS\gddeals\modules\admin\deals;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _leaderboard extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'settings_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		$S    = \IPS\Settings::i();
		$form = new \IPS\Helpers\Form;

		$form->addHeader( 'gddeals_lb_general' );
		$form->add( new \IPS\Helpers\Form\YesNo( 'gddeals_lb_enabled', (int) ( $S->gddeals_lb_enabled ?? 1 ) ) );
		$form->add( new \IPS\Helpers\Form\Select( 'gddeals_lb_default_window',
			(string) ( $S->gddeals_lb_default_window ?: 'month' ), FALSE,
			[ 'options' => [ 'week' => 'This Week', 'month' => 'This Month', 'all' => 'All Time' ] ] ) );
		$form->add( new \IPS\Helpers\Form\Number( 'gddeals_lb_per_board', (int) ( $S->gddeals_lb_per_board ?: 25 ), FALSE, [ 'min' => 1, 'max' => 200 ] ) );

		$form->addHeader( 'gddeals_lb_boards' );
		$form->add( new \IPS\Helpers\Form\YesNo( 'gddeals_lb_show_top_deals',    (int) ( $S->gddeals_lb_show_top_deals ?? 1 ) ) );
		$form->add( new \IPS\Helpers\Form\YesNo( 'gddeals_lb_show_best_savings', (int) ( $S->gddeals_lb_show_best_savings ?? 1 ) ) );
		$form->add( new \IPS\Helpers\Form\YesNo( 'gddeals_lb_show_most_clicked', (int) ( $S->gddeals_lb_show_most_clicked ?? 1 ) ) );
		$form->add( new \IPS\Helpers\Form\YesNo( 'gddeals_lb_show_top_dealers',  (int) ( $S->gddeals_lb_show_top_dealers ?? 1 ) ) );
		$form->add( new \IPS\Helpers\Form\YesNo( 'gddeals_lb_show_best_value',   (int) ( $S->gddeals_lb_show_best_value ?? 1 ) ) );
		$form->add( new \IPS\Helpers\Form\YesNo( 'gddeals_lb_show_top_members',  (int) ( $S->gddeals_lb_show_top_members ?? 1 ) ) );
		$form->add( new \IPS\Helpers\Form\YesNo( 'gddeals_lb_members_exclude_auto', (int) ( $S->gddeals_lb_members_exclude_auto ?? 1 ) ) );

		if ( $values = $form->values() )
		{
			$form->saveAsSettings( $values );
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddeals&module=deals&controller=leaderboard' ),
				'saved'
			);
			return;
		}

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gddeals_lb_acp_title' );
		\IPS\Output::i()->output = (string) $form;
	}
}

class leaderboard extends _leaderboard {}
