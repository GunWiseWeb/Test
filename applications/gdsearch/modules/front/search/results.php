<?php
namespace IPS\gdsearch\modules\front\search;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _results extends \IPS\Dispatcher\Controller
{
    protected function manage(): void
    {
        $query = trim( (string) ( \IPS\Request::i()->q ?? '' ) );

        \IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gdsearch_results_title' );
        \IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'search', 'gdsearch', 'front' )->results(
            $query,
            [],
            0,
            ''
        );
    }
}
class results extends _results {}
