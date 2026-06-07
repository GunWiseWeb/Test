<?php
namespace IPS\gdloadout\setup;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }

$hubContent = <<<'TEMPLATE_EOT'
<div class="ipsBox ipsPad" style="text-align:center">
	<h1>{lang="gdloadout_hub_title"}</h1>
	<p class="ipsType_light">{lang="gdloadout_hub_empty"}</p>
</div>
TEMPLATE_EOT;

try
{
	\IPS\Db::i()->replace( 'core_theme_templates', [
		'template_set_id'        => 1,
		'template_app'           => 'gdloadout',
		'template_location'      => 'front',
		'template_group'         => 'loadouts',
		'template_name'          => 'hub',
		'template_data'          => '',
		'template_content'       => $hubContent,
		'template_updated'       => time(),
		'template_version'       => '1.0.0',
		'template_master_key'    => '',
		'template_has_hookpoints' => 0,
	] );
}
catch ( \Throwable ) {}
