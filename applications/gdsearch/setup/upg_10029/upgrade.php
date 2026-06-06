<?php
namespace IPS\gdsearch\setup\upg_10029;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade
{
	public function step1(): bool
	{
		if ( !\IPS\Db::i()->checkForTable( 'gd_wishlist' ) )
		{
			\IPS\Db::i()->createTable( [
				'name'    => 'gd_wishlist',
				'columns' => [
					[ 'name'=>'id','type'=>'INT','length'=>10,'unsigned'=>true,'auto_increment'=>true,'allow_null'=>false ],
					[ 'name'=>'member_id','type'=>'INT','length'=>10,'unsigned'=>true,'allow_null'=>false,'default'=>0 ],
					[ 'name'=>'upc','type'=>'VARCHAR','length'=>64,'allow_null'=>false,'default'=>'' ],
					[ 'name'=>'created','type'=>'INT','length'=>10,'unsigned'=>true,'allow_null'=>false,'default'=>0 ],
				],
				'indexes' => [
					[ 'type'=>'primary','name'=>'PRIMARY','columns'=>[ 'id' ] ],
					[ 'type'=>'unique','name'=>'member_upc','columns'=>[ 'member_id','upc' ] ],
					[ 'type'=>'key','name'=>'member_id','columns'=>[ 'member_id' ] ],
				],
			] );
		}
		try { \IPS\Theme::deleteCompiledTemplate(); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }     catch ( \Throwable ) {}
		return TRUE;
	}
}
class upgrade extends _upgrade {}
