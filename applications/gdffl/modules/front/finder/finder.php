<?php
/**
 * @brief  GD FFL Finder — public finder page + JSON search endpoint.
 *
 * Two actions:
 *   manage()  →  GET /ffl-finder/ — HTML page with the search
 *                form + empty results container. Guest-viewable.
 *   search()  →  GET /ffl-finder/search?zip=&radius=&types=&page=
 *                — JSON endpoint used by the page's JS and by
 *                Stage 3's product-page embed.
 *
 * Read-only across the board. gd_ffl and gd_zip_geo are the only
 * tables touched, and both accesses are SELECT-only. Nothing from
 * another app is read or written.
 */

namespace IPS\gdffl\modules\front\finder;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _finder extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = FALSE;

	/**
	 * ATF LIC_TYPE code → human label. Small in-code map so the
	 * JSON payload carries readable text without a client-side
	 * lookup table. Every FFL row's lic_type is a 2-char code
	 * from this set; anything unknown falls through with the
	 * raw code as its own label.
	 */
	private const LIC_TYPE_LABELS = [
		'01' => 'Dealer',
		'02' => 'Pawnbroker',
		'03' => 'Collector',
		'06' => 'Manufacturer of Ammunition',
		'07' => 'Manufacturer of Firearms',
		'08' => 'Importer',
		'09' => 'Dealer in Destructive Devices',
		'10' => 'Manufacturer of Destructive Devices',
		'11' => 'Importer of Destructive Devices',
	];

	/**
	 * Radius options shown in the UI. Kept short + intentional
	 * so the buyer isn't dropped into a 500-mile default.
	 */
	private const RADIUS_OPTIONS = [ 10, 25, 50, 100 ];

	/**
	 * Rows returned per page — clamped to the ACP setting.
	 */
	private function perPage(): int
	{
		$n = (int) \IPS\Settings::i()->gdffl_per_page;
		if ( $n < 5 )   { $n = 20; }
		if ( $n > 200 ) { $n = 200; }
		return $n;
	}

	private function defaultRadius(): int
	{
		$r = (int) \IPS\Settings::i()->gdffl_default_radius;
		if ( $r < 1 || $r > 500 ) { $r = 25; }
		return $r;
	}

	private function transferCapableTypes(): array
	{
		$raw = trim( (string) \IPS\Settings::i()->gdffl_default_types );
		if ( $raw === '' ) { return [ '01', '02' ]; }
		$out = [];
		foreach ( explode( ',', $raw ) as $t )
		{
			$t = preg_replace( '/[^0-9]/', '', trim( $t ) );
			if ( $t !== '' && strlen( $t ) <= 2 ) { $out[] = str_pad( $t, 2, '0', STR_PAD_LEFT ); }
		}
		return $out ?: [ '01', '02' ];
	}

	/**
	 * GET /ffl-finder/ — the HTML page. Guest-viewable. Renders
	 * the form; results load via the JS finder.js against the
	 * search() JSON endpoint.
	 */
	protected function manage(): void
	{
		$member = \IPS\Member::loggedIn();
		$lang   = $member->language();
		$esc    = fn( string $s ) => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
		$L      = fn( string $k ) => $esc( (string) $lang->addToStack( $k ) );

		$searchUrl = (string) \IPS\Http\Url::internal(
			'app=gdffl&module=finder&controller=finder&do=search',
			'front'
		);

		try { \IPS\Output::i()->cssFiles = array_merge( \IPS\Output::i()->cssFiles, \IPS\Output::i()->css( 'finder.css', 'gdffl', 'interface' ) ); } catch ( \Throwable ) {}
		try { \IPS\Output::i()->jsFiles  = array_merge( \IPS\Output::i()->jsFiles,  \IPS\Output::i()->js(  'finder.js',  'gdffl', 'interface' ) ); } catch ( \Throwable ) {}

		$defaultRadius = $this->defaultRadius();
		$defaultTypes  = $this->transferCapableTypes();

		$typeRows = '';
		foreach ( self::LIC_TYPE_LABELS as $code => $label )
		{
			$checked = in_array( $code, $defaultTypes, TRUE ) ? ' checked' : '';
			$typeRows .= '<label class="gdffl-type"><input type="checkbox" name="type" value="' . $esc( $code ) . '"' . $checked . '> ' . $esc( $code ) . ' — ' . $esc( $label ) . '</label>';
		}

		$radiusOpts = '';
		foreach ( self::RADIUS_OPTIONS as $r )
		{
			$sel = ( $r === $defaultRadius ) ? ' selected' : '';
			$radiusOpts .= '<option value="' . $r . '"' . $sel . '>' . $r . ' mi</option>';
		}

		$init = json_encode( [
			'searchUrl'      => $searchUrl,
			'defaultRadius'  => $defaultRadius,
			'defaultTypes'   => $defaultTypes,
			'radiusOptions'  => self::RADIUS_OPTIONS,
			'labels'         => [
				'searching'    => (string) $lang->addToStack( 'gdffl_finder_searching' ),
				'no_results'   => (string) $lang->addToStack( 'gdffl_finder_no_results' ),
				'zip_bad'      => (string) $lang->addToStack( 'gdffl_finder_zip_bad' ),
				'zip_notfound' => (string) $lang->addToStack( 'gdffl_finder_zip_notfound' ),
				'error'        => (string) $lang->addToStack( 'gdffl_finder_error' ),
				'distance'     => (string) $lang->addToStack( 'gdffl_finder_distance' ),
				'no_phone'     => (string) $lang->addToStack( 'gdffl_finder_no_phone' ),
				'load_more'    => (string) $lang->addToStack( 'gdffl_finder_load_more' ),
			],
		], JSON_HEX_TAG | JSON_HEX_AMP );

		$html  = '<script type="application/json" id="gdffl-finder-init">' . $init . '</script>';
		$html .= '<div class="gdffl-wrap">';
		$html .= '<h1 class="gdffl-title">' . $L( 'gdffl_finder_title' ) . '</h1>';
		$html .= '<p class="gdffl-lead">' . $L( 'gdffl_finder_lead' ) . '</p>';

		$html .= '<form class="gdffl-form" id="gdfflForm">'
			. '<div class="gdffl-row">'
			. '<label>' . $L( 'gdffl_finder_zip' ) . ' <input type="text" id="gdffl-zip" inputmode="numeric" maxlength="10" pattern="[0-9\-]*" required></label>'
			. '<label>' . $L( 'gdffl_finder_radius' ) . ' <select id="gdffl-radius">' . $radiusOpts . '</select></label>'
			. '<button type="submit" class="gdffl-btn">' . $L( 'gdffl_finder_submit' ) . '</button>'
			. '</div>'
			. '<details class="gdffl-typewrap">'
			. '<summary>' . $L( 'gdffl_finder_types' ) . '</summary>'
			. '<div class="gdffl-typelist">' . $typeRows . '</div>'
			. '<label class="gdffl-alltypes"><input type="checkbox" id="gdffl-alltypes"> ' . $L( 'gdffl_finder_all_types' ) . '</label>'
			. '</details>'
			. '</form>';

		$html .= '<div class="gdffl-status" id="gdfflStatus"></div>';
		$html .= '<div class="gdffl-results" id="gdfflResults" role="list"></div>';
		$html .= '<button type="button" class="gdffl-more" id="gdfflMore" hidden>' . $L( 'gdffl_finder_load_more' ) . '</button>';
		$html .= '</div>';

		\IPS\Output::i()->title  = $lang->addToStack( 'gdffl_finder_title' );
		\IPS\Output::i()->output = $html;
	}

	/**
	 * GET /ffl-finder/search?zip=&radius=&types=&page= — JSON.
	 *
	 * Distance flow: bounding-box + type filter run in SQL via
	 * \IPS\Db::i()->select() (which works on hosts without the
	 * mysqlnd driver — the raw-mysqli path was replaced in
	 * v1.0.8); each row is decorated with a haversine-derived
	 * distance_miles column returned by MySQL. PHP then drops
	 * rows whose distance > radius, sorts ASC by distance, and
	 * slices for pagination. Buyer lat/lng are interpolated
	 * into the SELECT expression as float literals (zero
	 * injection surface); every WHERE value stays a bound
	 * parameter. Guest-callable — no login required. Read-only.
	 */
	protected function search(): void
	{
		$zip = preg_replace( '/[^0-9]/', '', (string) ( \IPS\Request::i()->zip ?? '' ) );
		if ( strlen( $zip ) < 5 )
		{
			\IPS\Output::i()->json( [ 'error' => 'zip_bad' ], 400 );
			return;
		}
		$zip = substr( $zip, 0, 5 );

		$radius = (int) ( \IPS\Request::i()->radius ?? 0 );
		if ( $radius < 1 )   { $radius = $this->defaultRadius(); }
		if ( $radius > 500 ) { $radius = 500; }

		$page = max( 1, (int) ( \IPS\Request::i()->page ?? 1 ) );
		$per  = $this->perPage();
		$off  = ( $page - 1 ) * $per;

		/* Type filter: passed via CSV or ?types[]=. Empty = default
		   transfer-capable. Special "all" token skips the filter. */
		$rawTypes = \IPS\Request::i()->types ?? '';
		$typesArr = [];
		if ( is_array( $rawTypes ) )
		{
			$typesArr = $rawTypes;
		}
		elseif ( is_string( $rawTypes ) && $rawTypes !== '' )
		{
			$typesArr = explode( ',', $rawTypes );
		}
		$typesArr = array_values( array_filter( array_map( function( $t ) {
			$t = preg_replace( '/[^0-9a-zA-Z]/', '', trim( (string) $t ) );
			if ( strtolower( $t ) === 'all' ) { return 'all'; }
			return $t !== '' ? str_pad( $t, 2, '0', STR_PAD_LEFT ) : '';
		}, (array) $typesArr ), fn( $t ) => $t !== '' ) );

		$allTypes = in_array( 'all', $typesArr, TRUE );
		if ( !$allTypes && empty( $typesArr ) )
		{
			$typesArr = $this->transferCapableTypes();
		}

		/* Resolve buyer ZIP → lat/lng. */
		try
		{
			$geo = \IPS\Db::i()->select( 'lat, lng', 'gd_zip_geo', [ 'zip=?', $zip ] )->first();
		}
		catch ( \Throwable )
		{
			\IPS\Output::i()->json( [ 'error' => 'zip_not_found', 'zip' => $zip ] );
			return;
		}
		$blat = (float) $geo['lat'];
		$blng = (float) $geo['lng'];

		/* Bounding-box math. 1 deg latitude ≈ 69 miles; longitude
		   scaled by cos(lat) to shrink at higher latitudes. */
		$latDelta = $radius / 69.0;
		$cosLat   = max( 0.01, cos( deg2rad( $blat ) ) );
		$lngDelta = $radius / ( 69.0 * $cosLat );

		$latMin = $blat - $latDelta;
		$latMax = $blat + $latDelta;
		$lngMin = $blng - $lngDelta;
		$lngMax = $blng + $lngDelta;

		/* Haversine returned directly by MySQL. $blat / $blng come
		   from gd_zip_geo lat/lng (DECIMAL(10,7)) and are cast to
		   float — no injection risk from a float literal. Everything
		   else in the SELECT is a plain column reference. */
		$blatSql = number_format( $blat, 7, '.', '' );
		$blngSql = number_format( $blng, 7, '.', '' );

		$distExpr = "( 3959 * ACOS( LEAST( 1.0, GREATEST( -1.0,"
			. " COS( RADIANS( {$blatSql} ) ) * COS( RADIANS( lat ) )"
			. " * COS( RADIANS( lng ) - RADIANS( {$blngSql} ) )"
			. " + SIN( RADIANS( {$blatSql} ) ) * SIN( RADIANS( lat ) )"
			. " ) ) )";

		$cols = "lic_number, lic_type, license_name, business_name,"
			. " premise_street, premise_city, premise_state, premise_zip, voice_phone,"
			. " lat, lng, {$distExpr} AS distance_miles";

		/* WHERE — IPS select() supports the "flat" form
		     [ "sql AND sql AND sql...", ...binds ]
		   with `?` placeholders for the bound params. The bounding
		   box is a cheap prefilter that uses idx_latlng; the exact
		   distance filter runs in PHP against the returned rows,
		   which avoids HAVING + LIMIT/OFFSET (both of which trip
		   the "commands out of sync" / no-mysqlnd stack on this
		   server). */
		$sqlWhere = "lat IS NOT NULL AND lat BETWEEN ? AND ? AND lng BETWEEN ? AND ?";
		$binds    = [ $latMin, $latMax, $lngMin, $lngMax ];

		if ( !$allTypes && !empty( $typesArr ) )
		{
			$placeholders = implode( ',', array_fill( 0, count( $typesArr ), '?' ) );
			$sqlWhere    .= " AND lic_type IN ({$placeholders})";
			foreach ( $typesArr as $t ) { $binds[] = $t; }
		}

		$whereParam = array_merge( [ $sqlWhere ], $binds );

		/* Iterate via IPS's select() iterator. This path uses the
		   codebase's tested Db\Select cursor — it does NOT rely
		   on the mysqli-native fetch stack (which requires the
		   mysqlnd driver, absent on this host). Bounding box
		   keeps the row count small so PHP-side distance-filter
		   + sort + paginate is cheap. */
		$radiusF = (float) $radius;
		$all     = [];

		try
		{
			foreach ( \IPS\Db::i()->select( $cols, 'gd_ffl', $whereParam ) as $row )
			{
				$dist = (float) ( $row['distance_miles'] ?? 0.0 );
				if ( $dist > $radiusF ) { continue; }

				$biz = trim( (string) ( $row['business_name'] ?? '' ) );
				if ( $biz === '' ) { $biz = trim( (string) ( $row['license_name'] ?? '' ) ); }
				$code = (string) ( $row['lic_type'] ?? '' );

				$all[] = [
					'lic_number'      => (string) ( $row['lic_number'] ?? '' ),
					'business_name'   => $biz,
					'license_name'    => (string) ( $row['license_name'] ?? '' ),
					'street'          => (string) ( $row['premise_street'] ?? '' ),
					'city'            => (string) ( $row['premise_city']   ?? '' ),
					'state'           => (string) ( $row['premise_state']  ?? '' ),
					'zip'             => (string) ( $row['premise_zip']    ?? '' ),
					'phone'           => (string) ( $row['voice_phone']    ?? '' ),
					'lic_type'        => $code,
					'lic_type_label'  => self::LIC_TYPE_LABELS[ $code ] ?? $code,
					'distance_miles'  => round( $dist, 1 ),
					'_dist_raw'       => $dist,
				];
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdffl search: ' . $e->getMessage(), 'gdffl' ); } catch ( \Throwable ) {}
			\IPS\Output::i()->json( [ 'error' => 'server_error' ], 500 );
			return;
		}

		/* Sort by raw distance ASC, then slice for pagination. */
		usort( $all, fn( array $a, array $b ) => $a['_dist_raw'] <=> $b['_dist_raw'] );
		$totalWithin = count( $all );

		$per     = max( 1, (int) $per );
		$off     = max( 0, (int) $off );
		$results = array_slice( $all, $off, $per );
		foreach ( $results as &$r ) { unset( $r['_dist_raw'] ); }
		unset( $r );

		\IPS\Output::i()->json( [
			'zip'     => $zip,
			'radius'  => $radius,
			'page'    => $page,
			'per'     => $per,
			'count'   => count( $results ),
			'results' => $results,
		] );
	}
}

class finder extends _finder {}
