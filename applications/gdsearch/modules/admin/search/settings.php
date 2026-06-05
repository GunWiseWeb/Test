<?php
namespace IPS\gdsearch\modules\admin\search;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _settings extends \IPS\Dispatcher\Controller
{
    public static function canManage(): bool
    {
        return \IPS\Member::loggedIn()->hasAcpRestriction( 'gdsearch', 'search', 'search_manage' );
    }

    protected function manage(): void
    {
        \IPS\Dispatcher::i()->checkAcpPermission( 'search_manage' );

        $form = new \IPS\Helpers\Form;
        $form->add( new \IPS\Helpers\Form\Number( 'gdsearch_results_per_page', \IPS\Settings::i()->gdsearch_results_per_page ?: 24, TRUE ) );
        $form->add( new \IPS\Helpers\Form\YesNo( 'gdsearch_show_out_of_stock', \IPS\Settings::i()->gdsearch_show_out_of_stock ?? 1 ) );

        if ( $values = $form->values() )
        {
            $form->saveAsSettings( $values );
            \IPS\Output::i()->redirect(
                \IPS\Http\Url::internal( 'app=gdsearch&module=search&controller=settings' ),
                'saved'
            );
        }

        \IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gdsearch_settings_title' );
        \IPS\Output::i()->output = (string) $form;
    }
}
class settings extends _settings {}
