<?php
namespace IPS\gdsearch\setup\upg_10033;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade
{
	public function step1(): bool
	{
		if ( !\IPS\Db::i()->checkForTable( 'gd_product_reports' ) )
		{
			\IPS\Db::i()->createTable( [
				'name'    => 'gd_product_reports',
				'columns' => [
					[ 'name'=>'id','type'=>'INT','length'=>10,'unsigned'=>true,'auto_increment'=>true,'allow_null'=>false ],
					[ 'name'=>'upc','type'=>'VARCHAR','length'=>64,'allow_null'=>false,'default'=>'' ],
					[ 'name'=>'member_id','type'=>'INT','length'=>10,'unsigned'=>true,'allow_null'=>false,'default'=>0 ],
					[ 'name'=>'reason','type'=>'VARCHAR','length'=>32,'allow_null'=>false,'default'=>'data' ],
					[ 'name'=>'details','type'=>'TEXT','allow_null'=>true ],
					[ 'name'=>'status','type'=>'VARCHAR','length'=>16,'allow_null'=>false,'default'=>'open' ],
					[ 'name'=>'admin_note','type'=>'TEXT','allow_null'=>true ],
					[ 'name'=>'created','type'=>'INT','length'=>10,'unsigned'=>true,'allow_null'=>false,'default'=>0 ],
					[ 'name'=>'handled_by','type'=>'INT','length'=>10,'unsigned'=>true,'allow_null'=>false,'default'=>0 ],
					[ 'name'=>'handled_at','type'=>'INT','length'=>10,'unsigned'=>true,'allow_null'=>false,'default'=>0 ],
				],
				'indexes' => [
					[ 'type'=>'primary','name'=>'PRIMARY','columns'=>[ 'id' ] ],
					[ 'type'=>'key','name'=>'status','columns'=>[ 'status' ] ],
					[ 'type'=>'key','name'=>'upc','columns'=>[ 'upc' ] ],
				],
			] );
		}

		try
		{
			$newStrings = [
				'gdsearch_reports_title'          => 'Product Reports',
				'menu__gdsearch_search_reports'   => 'Product Reports',
				'reports_manage'                  => 'Can manage product reports?',
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
		}
		catch ( \Throwable ) {}

		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Theme::deleteCompiledTemplate(); }        catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }            catch ( \Throwable ) {}
		return TRUE;
	}
}
class upgrade extends _upgrade {}
