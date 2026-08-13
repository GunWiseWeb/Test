<?php
/**
 * @brief       GD Deals — Install routine
 * @package     IPS Community Suite
 * @subpackage  GD Deals
 * @since       15 Jun 2026
 *
 * Runs after schema.json tables are created.
 * Seeds the 10 deal categories, language strings for category names,
 * and default permission rows in core_permission_index.
 */

$categories = [
	1  => [ 'name_seo' => 'handguns',         'position' => 1,  'bitoptions' => 3 ],
	2  => [ 'name_seo' => 'rifles',            'position' => 2,  'bitoptions' => 3 ],
	3  => [ 'name_seo' => 'shotguns',          'position' => 3,  'bitoptions' => 3 ],
	4  => [ 'name_seo' => 'ammunition',        'position' => 4,  'bitoptions' => 3 ],
	5  => [ 'name_seo' => 'optics',            'position' => 5,  'bitoptions' => 3 ],
	6  => [ 'name_seo' => 'gun-accessories',   'position' => 6,  'bitoptions' => 3 ],
	7  => [ 'name_seo' => 'holsters',          'position' => 7,  'bitoptions' => 3 ],
	8  => [ 'name_seo' => 'safes',             'position' => 8,  'bitoptions' => 3 ],
	9  => [ 'name_seo' => 'nfa',               'position' => 9,  'bitoptions' => 3 ],
	10 => [ 'name_seo' => 'knives',            'position' => 10, 'bitoptions' => 3 ],
];

$categoryNames = [
	1  => 'Handguns',
	2  => 'Rifles',
	3  => 'Shotguns',
	4  => 'Ammunition',
	5  => 'Optics',
	6  => 'Gun Accessories',
	7  => 'Holsters',
	8  => 'Safes',
	9  => 'NFA',
	10 => 'Knives',
];

/* ── Seed categories ── */
foreach ( $categories as $catId => $row )
{
	$existing = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_deal_categories', [ 'id=?', $catId ] )->first();
	if ( $existing === 0 )
	{
		try
		{
			\IPS\Db::i()->insert( 'gd_deal_categories', [
				'id'         => $catId,
				'parent_id'  => 0,
				'position'   => $row['position'],
				'open'       => 1,
				'name_seo'   => $row['name_seo'],
				'bitoptions' => $row['bitoptions'],
				'deal_count' => 0,
			] );
		}
		catch ( \Throwable ) {}
	}
}

/* ── Seed category lang strings for every installed language ── */
try
{
	$langIds = iterator_to_array( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) );
}
catch ( \Throwable )
{
	$langIds = [ 1 ];
}

foreach ( $langIds as $langId )
{
	foreach ( $categoryNames as $catId => $catName )
	{
		try
		{
			\IPS\Db::i()->replace( 'core_sys_lang_words', [
				'lang_id'      => (int) $langId,
				'word_app'     => 'gddeals',
				'word_key'     => 'gddeals_cat_' . $catId,
				'word_default' => $catName,
				'word_js'      => 0,
				'word_export'  => 1,
			] );
		}
		catch ( \Throwable ) {}
	}
}

/* ── Seed default permission rows in core_permission_index ── */
/* IPS Node\Permissions looks up core_permission_index for app=gddeals, perm_type=category.
 * view=*, read=*, add=* (all groups), reply=* (all groups).
 * perm_type_id = category node ID. */
foreach ( array_keys( $categories ) as $catId )
{
	$existing = (int) \IPS\Db::i()->select( 'COUNT(*)', 'core_permission_index', [
		'app=? AND perm_type=? AND perm_type_id=?', 'gddeals', 'category', $catId
	] )->first();

	if ( $existing === 0 )
	{
		try
		{
			\IPS\Db::i()->insert( 'core_permission_index', [
				'app'          => 'gddeals',
				'perm_type'    => 'category',
				'perm_type_id' => $catId,
				'perm_view'    => '*',
				'perm_2'       => '*',
				'perm_3'       => '*',
				'perm_4'       => '*',
			] );
		}
		catch ( \Throwable ) {}
	}
}

/* ── Seed core_theme_templates from every .phtml file under dev/html ──
   Production doesn't read dev/html at runtime; every template
   needs a real row in core_theme_templates or IN_DEV=false pages
   throw ErrorException: template_store_missing (0). No IPS-native
   sync-dev-to-prod call exists in this stack (rule #4 forbids the
   theme.xml route), so this app reads its own dev/html tree here.
   DELETE-then-INSERT keyed on (app, location, group, name,
   set_id=1) avoids duplicate rows and doesn't require a unique
   constraint that may or may not be present on 5.0.18.
   Rule #45 columns only — never write template_user_ columns. */
try
{
	$__gdRoot = \IPS\ROOT_PATH . '/applications/gddeals/dev/html';
	if ( is_dir( $__gdRoot ) )
	{
		$__gdIt = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $__gdRoot, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $__gdIt as $__gdF )
		{
			if ( !$__gdF->isFile() || strtolower( $__gdF->getExtension() ) !== 'phtml' ) { continue; }
			$__gdRel = trim( str_replace( $__gdRoot, '', $__gdF->getPathname() ), "/\\" );
			$__gdParts = preg_split( '#[/\\\\]#', $__gdRel );
			if ( count( $__gdParts ) < 3 ) { continue; }
			$__gdLoc   = (string) $__gdParts[0];
			$__gdGrp   = (string) $__gdParts[1];
			$__gdName  = pathinfo( (string) end( $__gdParts ), PATHINFO_FILENAME );
			$__gdRaw   = (string) @file_get_contents( $__gdF->getPathname() );
			if ( $__gdRaw === '' ) { continue; }
			$__gdParams = '';
			if ( preg_match( '#<ips:template\s+parameters="([^"]*)"\s*/>#', $__gdRaw, $__gdM ) )
			{
				$__gdParams = (string) $__gdM[1];
			}
			$__gdContent = preg_replace( '#^\s*<ips:template[^>]*/>\s*\r?\n?#', '', $__gdRaw, 1 );
			try
			{
				\IPS\Db::i()->delete( 'core_theme_templates', [
					'template_app=? AND template_location=? AND template_group=? AND template_name=? AND template_set_id=?',
					'gddeals', $__gdLoc, $__gdGrp, $__gdName, 1
				] );
			}
			catch ( \Throwable ) {}
			try
			{
				\IPS\Db::i()->insert( 'core_theme_templates', [
					'template_set_id'         => 1,
					'template_app'            => 'gddeals',
					'template_location'       => $__gdLoc,
					'template_group'          => $__gdGrp,
					'template_name'           => $__gdName,
					'template_data'           => $__gdParams,
					'template_content'        => (string) $__gdContent,
					'template_updated'        => time(),
					'template_version'        => '1.0.61',
					'template_master_key'     => '',
					'template_has_hookpoints' => 0,
				] );
			}
			catch ( \Throwable $__gdE )
			{
				try { \IPS\Log::log( 'gddeals tpl sync (' . $__gdName . '): ' . $__gdE->getMessage(), 'gddeals_tpl_sync' ); } catch ( \Throwable ) {}
			}
		}
	}
}
catch ( \Throwable $__gdE )
{
	try { \IPS\Log::log( 'gddeals tpl sync loop: ' . $__gdE->getMessage(), 'gddeals_tpl_sync' ); } catch ( \Throwable ) {}
}

/* ── Clear caches ── */
try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable ) {}
try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
try { unset( \IPS\Data\Store::i()->themes ); }       catch ( \Throwable ) {}
foreach ( glob( \IPS\ROOT_PATH . '/datastore/template_*' ) ?: [] as $__gdCf ) { @unlink( $__gdCf ); }
try { \IPS\Data\Store::i()->clearAll(); }             catch ( \Throwable ) {}
try { \IPS\Data\Cache::i()->clearAll(); }             catch ( \Throwable ) {}
