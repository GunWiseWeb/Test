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
   Matches gddealer's proven working seed pattern EXACTLY (9 columns,
   \IPS\Db::i()->replace() — v1.0.61 previously added template_master_key=''
   and template_has_hookpoints=0 as extras, which broke IPS's theme
   hierarchy resolution and crashed core/front/global/globalTemplate.
   template_master_key='' in IPS specifically means "this row IS a
   master template", which conflicts with the core theme's own
   masters. Removing those two columns fixes it — IPS provides
   its own safe defaults for anything not explicitly set.
   Rule #45 safe columns only; never write template_user_ columns. */
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
				\IPS\Db::i()->replace( 'core_theme_templates', [
					'template_set_id'   => 1,
					'template_app'      => 'gddeals',
					'template_location' => $__gdLoc,
					'template_group'    => $__gdGrp,
					'template_name'     => $__gdName,
					'template_data'     => $__gdParams,
					'template_updated'  => time(),
					'template_version'  => '1.0.64',
					'template_content'  => (string) $__gdContent,
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

/* ── CSS self-heal: import dev/css → core_theme_css → write compiled
      file → inject set_css_map entry ──

   Backstory: on prod the standard install pipeline left gddeals with
   ZERO rows in core_theme_css AND no compiled file at
   uploads/css_built_1/gddeals_front_deals.css AND a `null`-valued
   set_css_map entry — so \IPS\Theme::i()->css('deals.css','gddeals','front')
   returned an empty array and browse.php never linked the stylesheet.
   Result: front-page rendered raw HTML with no gddeals styling.

   Fix, three steps, all idempotent:

   1. \IPS\Theme\Dev\Theme::importDevCss('gddeals', 0) — populates
      core_theme_css from dev/css/*. Safe to re-run.
   2. Write each CSS row's content to
      uploads/css_built_1/<app>_<location>_<name>.css directly. IPS's
      own compileCss() has been observed to silently skip rows in
      this app's case, so we do it ourselves from the DB row content.
   3. Compute the same md5 hash IPS uses (via
      Theme::makeBuiltTemplateLookupHash) and inject
      hash => 'css_built_1/<file>' into core_themes.set_css_map so
      \IPS\Theme::i()->css() returns the URL on lookup. */
try
{
	\IPS\Theme\Dev\Theme::importDevCss( 'gddeals', 0 );
}
catch ( \Throwable $__gdE )
{
	try { \IPS\Log::log( 'gddeals css importDevCss: ' . $__gdE->getMessage(), 'gddeals_css_sync' ); } catch ( \Throwable ) {}
}

try
{
	$__gdBuiltDir = \IPS\ROOT_PATH . '/uploads/css_built_1';
	if ( !is_dir( $__gdBuiltDir ) ) { @mkdir( $__gdBuiltDir, 0755, TRUE ); }

	$__gdHashMethod = NULL;
	try
	{
		$__gdRc = new \ReflectionClass( '\IPS\Theme' );
		if ( $__gdRc->hasMethod( 'makeBuiltTemplateLookupHash' ) )
		{
			$__gdHashMethod = $__gdRc->getMethod( 'makeBuiltTemplateLookupHash' );
			$__gdHashMethod->setAccessible( TRUE );
		}
	}
	catch ( \Throwable ) {}

	$__gdMapRow = NULL;
	try { $__gdMapRow = \IPS\Db::i()->select( 'set_id, set_css_map', 'core_themes', [ 'set_id=?', 1 ] )->first(); }
	catch ( \Throwable ) {}
	$__gdMap = ( $__gdMapRow && !empty( $__gdMapRow['set_css_map'] ) ) ? ( json_decode( $__gdMapRow['set_css_map'], TRUE ) ?: [] ) : [];

	foreach ( \IPS\Db::i()->select( 'css_location, css_path, css_name, css_content', 'core_theme_css', [ 'css_app=?', 'gddeals' ] ) as $__gdCssRow )
	{
		$__gdLoc  = (string) $__gdCssRow['css_location'];
		$__gdName = (string) $__gdCssRow['css_name'];
		if ( $__gdLoc === '' || $__gdName === '' ) { continue; }

		$__gdBuiltFile = 'gddeals_' . $__gdLoc . '_' . $__gdName;
		$__gdDest      = $__gdBuiltDir . '/' . $__gdBuiltFile;
		try
		{
			file_put_contents( $__gdDest, (string) $__gdCssRow['css_content'] );
			@chmod( $__gdDest, 0644 );
		}
		catch ( \Throwable $__gdE )
		{
			try { \IPS\Log::log( 'gddeals css write (' . $__gdBuiltFile . '): ' . $__gdE->getMessage(), 'gddeals_css_sync' ); } catch ( \Throwable ) {}
		}

		if ( $__gdHashMethod && $__gdMapRow )
		{
			try
			{
				$__gdKey = $__gdHashMethod->invoke( NULL, 'gddeals', $__gdLoc, './' . $__gdName );
				$__gdMap[ $__gdKey ] = 'css_built_1/' . $__gdBuiltFile;
			}
			catch ( \Throwable $__gdE )
			{
				try { \IPS\Log::log( 'gddeals css map hash (' . $__gdBuiltFile . '): ' . $__gdE->getMessage(), 'gddeals_css_sync' ); } catch ( \Throwable ) {}
			}
		}
	}

	if ( $__gdMapRow && $__gdHashMethod )
	{
		try { \IPS\Db::i()->update( 'core_themes', [ 'set_css_map' => json_encode( $__gdMap ) ], [ 'set_id=?', 1 ] ); }
		catch ( \Throwable $__gdE )
		{
			try { \IPS\Log::log( 'gddeals css_map update: ' . $__gdE->getMessage(), 'gddeals_css_sync' ); } catch ( \Throwable ) {}
		}
	}
}
catch ( \Throwable $__gdE )
{
	try { \IPS\Log::log( 'gddeals css sync loop: ' . $__gdE->getMessage(), 'gddeals_css_sync' ); } catch ( \Throwable ) {}
}

/* ── Clear caches ── */
try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable ) {}
try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
try { unset( \IPS\Data\Store::i()->themes ); }       catch ( \Throwable ) {}
foreach ( glob( \IPS\ROOT_PATH . '/datastore/template_*' ) ?: [] as $__gdCf ) { @unlink( $__gdCf ); }
try { \IPS\Data\Store::i()->clearAll(); }             catch ( \Throwable ) {}
try { \IPS\Data\Cache::i()->clearAll(); }             catch ( \Throwable ) {}
