<?php
// gdsearch install — no DB tables needed at v1.0.1
// Settings are seeded via data/settings.json automatically by IPS.

require_once __DIR__ . '/templates_10046.php';

$gdsearchSeedResultsTemplate = function (): void
{
	$file = \IPS\ROOT_PATH . '/applications/gdsearch/dev/html/front/search/results.phtml';
	$raw  = @file_get_contents( $file );
	if ( $raw === false || $raw === '' ) { return; }

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

	try { \IPS\Theme::deleteCompiledTemplate(); } catch ( \Throwable ) {}
	try { \IPS\Data\Cache::i()->clearAll(); }     catch ( \Throwable ) {}
};
$gdsearchSeedResultsTemplate();
