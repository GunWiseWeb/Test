<?php
namespace IPS\gdsearch\setup\upg_10058;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }

class _upgrade
{
    public function step1(): bool
    {
        $newStrings = [
            'gdsearch_facet_optic_magnification' => 'Magnification',
            'gdsearch_facet_optic_objective'     => 'Objective Size',
        ];

        foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
        {
            foreach ( $newStrings as $key => $val )
            {
                try
                {
                    \IPS\Db::i()->replace( 'core_sys_lang_words', [
                        'lang_id'      => (int) $langId,
                        'word_app'     => 'gdsearch',
                        'word_key'     => $key,
                        'word_default' => $val,
                        'word_js'      => 0,
                        'word_export'  => 1,
                    ] );
                }
                catch ( \Throwable ) {}
            }
        }

        $file = \IPS\ROOT_PATH . '/applications/gdsearch/dev/html/front/search/results.phtml';
        $raw  = @file_get_contents( $file );
        if ( $raw !== false && $raw !== '' )
        {
            $params = '';
            if ( preg_match( '/<ips:template\s+parameters="([^"]*)"\s*\/>/', $raw, $m ) ) { $params = $m[1]; }
            $content = preg_replace( '/<ips:template[^>]*\/>\s*/', '', $raw, 1 );

            try
            {
                \IPS\Db::i()->delete( 'core_theme_templates', [
                    'template_set_id=? AND template_app=? AND template_location=? AND template_group=? AND template_name=?',
                    1, 'gdsearch', 'front', 'search', 'results'
                ] );
                \IPS\Db::i()->insert( 'core_theme_templates', [
                    'template_set_id'  => 1,
                    'template_app'     => 'gdsearch',
                    'template_location'=> 'front',
                    'template_group'   => 'search',
                    'template_name'    => 'results',
                    'template_data'    => $params,
                    'template_content' => $content,
                    'template_updated' => time(),
                ] );
            }
            catch ( \Throwable ) {}
        }

        try { \IPS\Theme::deleteCompiledTemplate(); } catch ( \Throwable ) {}
        try { \IPS\Data\Cache::i()->clearAll(); }     catch ( \Throwable ) {}

        return TRUE;
    }
}
class upgrade extends _upgrade {}
