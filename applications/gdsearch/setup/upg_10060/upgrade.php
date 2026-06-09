<?php

namespace IPS\gdsearch\setup\upg_10060;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		$db = \IPS\Db::i();

		// Facet settings table (from v1.0.59, guarded)
		if ( !$db->checkForTable( 'gd_facet_settings' ) )
		{
			$db->createTable( [
				'name' => 'gd_facet_settings',
				'columns' => [
					[ 'name' => 'facet_key',   'type' => 'VARCHAR', 'length' => 40, 'allow_null' => false, 'default' => '' ],
					[ 'name' => 'hidden',      'type' => 'TINYINT', 'length' => 1,  'allow_null' => false, 'default' => 0 ],
					[ 'name' => 'category_id', 'type' => 'INT',     'length' => 11, 'allow_null' => false, 'default' => 0 ],
					[ 'name' => 'position',    'type' => 'INT',     'length' => 11, 'allow_null' => false, 'default' => 0 ],
				],
				'indexes' => [ [ 'type' => 'primary', 'name' => 'PRIMARY', 'columns' => [ 'facet_key' ] ] ],
			] );
		}

		$keys = [ 'brands','calibers','actions','capacities','barrel_present','casings','bullet_types','grain','velocity',
			'holster_types','holster_colors','holster_materials','holster_hands','apparel_patterns','apparel_sizes','apparel_materials',
			'blade_shapes','blade_lengths','blade_materials','blade_edges','knife_handles','hunt_call_types','hunt_games','optics_mags','optics_objs' ];
		foreach ( $keys as $k )
		{
			try { $db->insert( 'gd_facet_settings', [ 'facet_key' => $k, 'hidden' => 0, 'category_id' => 0, 'position' => 0 ], TRUE ); } catch ( \Throwable ) {}
		}

		// Lang strings (from v1.0.59)
		$newStrings = [
			'gdsearch_facets_title'          => 'Search Facets',
			'menu__gdsearch_search_facets'   => 'Facets',
			'r__facets_manage'               => 'Manage facets',
		];
		foreach ( $db->select( 'lang_id', 'core_sys_lang' ) as $langId )
		{
			foreach ( $newStrings as $key => $val )
			{
				try
				{
					$db->replace( 'core_sys_lang_words', [
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

		// Re-seed results template from dev/html if present
		$file = \IPS\ROOT_PATH . '/applications/gdsearch/dev/html/front/search/results.phtml';
		$raw  = @file_get_contents( $file );
		if ( $raw !== false && $raw !== '' )
		{
			$params = '';
			if ( preg_match( '/<ips:template\s+parameters="([^"]*)"\s*\/>/', $raw, $m ) ) { $params = $m[1]; }
			$content = preg_replace( '/<ips:template[^>]*\/>\s*/', '', $raw, 1 );

			try
			{
				$db->delete( 'core_theme_templates', [
					'template_set_id=? AND template_app=? AND template_location=? AND template_group=? AND template_name=?',
					1, 'gdsearch', 'front', 'search', 'results'
				] );
				$db->insert( 'core_theme_templates', [
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
		try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }            catch ( \Throwable ) {}

		return TRUE;
	}
}

class upgrade extends _upgrade {}
