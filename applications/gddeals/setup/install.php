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

/* ── Clear caches ── */
try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable ) {}
try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
try { \IPS\Data\Cache::i()->clearAll(); }             catch ( \Throwable ) {}
