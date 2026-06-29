<?php
namespace IPS\gdbills\widgets;

use IPS\Helpers\Form;
use IPS\Helpers\Form\Number;
use IPS\Helpers\Form\Text;
use IPS\Output;
use IPS\Theme;
use IPS\Widget\Customizable;
use IPS\Widget\PermissionCache;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class billList extends PermissionCache implements Customizable
{
	public string $key = 'billList';
	public string $app = 'gdbills';

	public function init(): void
	{
		Output::i()->cssFiles = array_merge( Output::i()->cssFiles, Theme::i()->css( 'bills.css', 'gdbills', 'interface' ) );
		parent::init();
	}

	public function configuration( Form &$form = null ): Form
	{
		$form = parent::configuration( $form );
		$form->add( new Text(   'gdbills_w_state', (string) ( $this->configuration['gdbills_w_state'] ?? '' ), FALSE, [ 'maxLength' => 2 ] ) );
		$form->add( new Text(   'gdbills_w_type',  (string) ( $this->configuration['gdbills_w_type']  ?? '' ), FALSE, [ 'maxLength' => 20 ] ) );
		$form->add( new Number( 'gdbills_w_limit', (int)    ( $this->configuration['gdbills_w_limit'] ?? 10 ), FALSE, [ 'min' => 1, 'max' => 50 ] ) );
		return $form;
	}

	public function render(): string
	{
		$state = strtoupper( (string) ( $this->configuration['gdbills_w_state'] ?? '' ) );
		$type  = (string) ( $this->configuration['gdbills_w_type'] ?? '' );
		$limit = (int)    ( $this->configuration['gdbills_w_limit'] ?? 10 );
		if ( $limit < 1 ) { $limit = 10; }

		$rows = [];
		try
		{
			$rows = \IPS\gdbills\Bill::getAll( [ 'state' => $state, 'type' => $type, 'limit' => $limit ] );
		}
		catch ( \Throwable ) {}

		ob_start();
		echo '<div class="gd-bills"><div class="gd-bills__section"><div class="gd-bills__list">';
		foreach ( $rows as $b )
		{
			echo (string) Theme::i()->getTemplate( 'bills', 'gdbills', 'front' )->billRow( $b );
		}
		if ( empty( $rows ) )
		{
			echo '<p class="gd-bills__empty">' . htmlspecialchars( (string) \IPS\Member::loggedIn()->language()->addToStack( 'gdbills_no_bills' ), ENT_QUOTES ) . '</p>';
		}
		echo '</div></div></div>';
		return (string) ob_get_clean();
	}
}
