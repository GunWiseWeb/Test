<?php

namespace IPS\gdloadout\modules\front\loadouts;

use IPS\Db;
use IPS\Http\Url;
use IPS\Member;
use IPS\Output;
use IPS\Request;
use IPS\Session;
use IPS\Theme;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _hub extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		parent::execute();
	}

	/**
	 * Hub page — five curated sections of public loadouts
	 */
	protected function manage(): void
	{
		$member      = Member::loggedIn();
		$prefix      = Db::i()->prefix;
		$activeUseCase = trim( Request::i()->use_case ?? '' );

		/* Use-case options for the filter dropdown */
		$useCases = [
			'Home Defense',
			'Concealed Carry',
			'Competition',
			'Hunting',
			'Long Range',
			'Plinking',
			'Duty',
			'Bug Out',
			'Custom',
		];

		$sections = [];

		/* ---------- 1. Featured (no use_case filter) ---------- */
		$featured = [];
		try
		{
			foreach ( Db::i()->select( '*', 'gd_loadouts', [ 'visibility=? AND featured=?', 'public', 1 ], 'featured_position ASC', [ 0, 6 ] ) as $row )
			{
				$ownerName = 'Unknown';
				try { $ownerName = Member::load( (int) $row['member_id'] )->name; } catch ( \Throwable ) {}
				$row['owner_name'] = $ownerName;
				$row['view_url']   = (string) Url::internal(
					'app=gdloadout&module=loadouts&controller=hub&do=view&username=' . urlencode( $ownerName ) . '&slug=' . urlencode( $row['slug'] ),
					'front',
					'gdloadout_view'
				);
				$featured[] = $row;
			}
		}
		catch ( \Throwable ) {}
		$sections['featured'] = $featured;

		/* Use-case WHERE fragment for sections 2-5 */
		$ucWhere  = '';
		$ucBinds  = [];
		if ( $activeUseCase !== '' )
		{
			$ucWhere = ' AND use_case=?';
			$ucBinds = [ $activeUseCase ];
		}

		/* ---------- 2. Trending (most votes in last 7 days) ---------- */
		$trending = [];
		try
		{
			$trendingIds = [];
			$sevenDaysAgo = time() - ( 7 * 86400 );
			$stmt = Db::i()->preparedQuery(
				"SELECT loadout_id, COUNT(*) AS vote_count FROM `{$prefix}gd_loadout_votes` WHERE voted_at >= ? GROUP BY loadout_id ORDER BY vote_count DESC LIMIT 8",
				[ $sevenDaysAgo ]
			);
			while ( $tRow = $stmt->fetch_assoc() )
			{
				$trendingIds[] = (int) $tRow['loadout_id'];
			}

			if ( $trendingIds )
			{
				$placeholders = implode( ',', array_fill( 0, \count( $trendingIds ), '?' ) );
				$where = array_merge(
					[ "visibility=? AND id IN({$placeholders})" . $ucWhere, 'public' ],
					$trendingIds,
					$ucBinds
				);
				foreach ( Db::i()->select( '*', 'gd_loadouts', $where ) as $row )
				{
					$ownerName = 'Unknown';
					try { $ownerName = Member::load( (int) $row['member_id'] )->name; } catch ( \Throwable ) {}
					$row['owner_name'] = $ownerName;
					$row['view_url']   = (string) Url::internal(
						'app=gdloadout&module=loadouts&controller=hub&do=view&username=' . urlencode( $ownerName ) . '&slug=' . urlencode( $row['slug'] ),
						'front',
						'gdloadout_view'
					);
					$trending[] = $row;
				}

				/* Preserve the trending-order from the subquery */
				$idOrder = array_flip( $trendingIds );
				usort( $trending, static function ( $a, $b ) use ( $idOrder ) {
					return ( $idOrder[ (int) $a['id'] ] ?? 999 ) <=> ( $idOrder[ (int) $b['id'] ] ?? 999 );
				} );
			}
		}
		catch ( \Throwable ) {}
		$sections['trending'] = $trending;

		/* ---------- 3. Top Rated ---------- */
		$topRated = [];
		try
		{
			$where = array_merge(
				[ 'visibility=? AND upvotes >= ?' . $ucWhere, 'public', 10 ],
				$ucBinds
			);
			foreach ( Db::i()->select( '*', 'gd_loadouts', $where, 'upvotes DESC', [ 0, 8 ] ) as $row )
			{
				$ownerName = 'Unknown';
				try { $ownerName = Member::load( (int) $row['member_id'] )->name; } catch ( \Throwable ) {}
				$row['owner_name'] = $ownerName;
				$row['view_url']   = (string) Url::internal(
					'app=gdloadout&module=loadouts&controller=hub&do=view&username=' . urlencode( $ownerName ) . '&slug=' . urlencode( $row['slug'] ),
					'front',
					'gdloadout_view'
				);
				$topRated[] = $row;
			}
		}
		catch ( \Throwable ) {}
		$sections['top_rated'] = $topRated;

		/* ---------- 4. Recently Updated ---------- */
		$recent = [];
		try
		{
			$where = array_merge(
				[ 'visibility=?' . $ucWhere, 'public' ],
				$ucBinds
			);
			foreach ( Db::i()->select( '*', 'gd_loadouts', $where, 'COALESCE(updated_at, created_at) DESC', [ 0, 8 ] ) as $row )
			{
				$ownerName = 'Unknown';
				try { $ownerName = Member::load( (int) $row['member_id'] )->name; } catch ( \Throwable ) {}
				$row['owner_name'] = $ownerName;
				$row['view_url']   = (string) Url::internal(
					'app=gdloadout&module=loadouts&controller=hub&do=view&username=' . urlencode( $ownerName ) . '&slug=' . urlencode( $row['slug'] ),
					'front',
					'gdloadout_view'
				);
				$recent[] = $row;
			}
		}
		catch ( \Throwable ) {}
		$sections['recent'] = $recent;

		/* ---------- 5. Budget (under $500) ---------- */
		$budget = [];
		try
		{
			$where = array_merge(
				[ 'visibility=? AND total_min_price > ? AND total_min_price < ?' . $ucWhere, 'public', 0, 500 ],
				$ucBinds
			);
			foreach ( Db::i()->select( '*', 'gd_loadouts', $where, 'total_min_price ASC', [ 0, 8 ] ) as $row )
			{
				$ownerName = 'Unknown';
				try { $ownerName = Member::load( (int) $row['member_id'] )->name; } catch ( \Throwable ) {}
				$row['owner_name'] = $ownerName;
				$row['view_url']   = (string) Url::internal(
					'app=gdloadout&module=loadouts&controller=hub&do=view&username=' . urlencode( $ownerName ) . '&slug=' . urlencode( $row['slug'] ),
					'front',
					'gdloadout_view'
				);
				$budget[] = $row;
			}
		}
		catch ( \Throwable ) {}
		$sections['budget'] = $budget;

		/* ---------- Build page ---------- */
		$canCreate  = $member->member_id ? \IPS\gdloadout\Loadout\Limits::canCreateLoadout( $member ) : false;
		$builderUrl = (string) Url::internal( 'app=gdloadout&module=loadouts&controller=builder', 'front', 'gdloadout_builder' );

		Output::i()->cssFiles = array_merge( Output::i()->cssFiles, Theme::i()->css( 'loadouts.css', 'gdloadout', 'interface' ) );
		Output::i()->title    = Member::loggedIn()->language()->addToStack( 'gdloadout_hub_title' );
		Output::i()->output   = Theme::i()->getTemplate( 'loadouts', 'gdloadout', 'front' )->hub( $sections, $canCreate, $builderUrl, $activeUseCase, $useCases );
	}

	/**
	 * View a single loadout (public page)
	 */
	protected function view(): void
	{
		$username = trim( Request::i()->username ?? '' );
		$slug     = trim( Request::i()->slug ?? '' );

		if ( $username === '' || $slug === '' )
		{
			Output::i()->error( 'gdloadout_err_not_found', '2GDL/1', 404 );
			return;
		}

		/* Resolve username to member_id */
		$owner = Member::load( $username, 'name' );
		if ( !(int) $owner->member_id )
		{
			Output::i()->error( 'gdloadout_err_not_found', '2GDL/1', 404 );
			return;
		}

		$memberId = (int) $owner->member_id;
		$ownerName = $owner->name ?? 'Unknown';

		/* Load the loadout */
		$loadout = NULL;
		try
		{
			$loadout = Db::i()->select( '*', 'gd_loadouts', [ 'slug=? AND member_id=?', $slug, $memberId ] )->first();
		}
		catch ( \Throwable ) {}

		if ( !$loadout )
		{
			Output::i()->error( 'gdloadout_err_not_found', '2GDL/1', 404 );
			return;
		}

		$member  = Member::loggedIn();
		$isOwner = (int) $member->member_id === (int) $loadout['member_id'];

		/* Visibility enforcement */
		if ( $loadout['visibility'] === 'private' && !$isOwner )
		{
			Output::i()->error( 'node_error', '2GDL/2', 403 );
			return;
		}

		/* Load items — separate queries to avoid fatal on missing catalog columns */
		$items  = [];
		$prefix = Db::i()->prefix;

		$rawItems = [];
		try
		{
			foreach ( Db::i()->select( '*', 'gd_loadout_items', [ 'loadout_id=?', (int) $loadout['id'] ], 'sort_order ASC' ) as $row )
			{
				$rawItems[] = $row;
			}
		}
		catch ( \Throwable ) {}

		$upcs = [];
		foreach ( $rawItems as $ri )
		{
			if ( !empty( $ri['upc'] ) )
			{
				$upcs[] = $ri['upc'];
			}
		}

		$catalogMap = [];
		if ( $upcs )
		{
			try
			{
				$ph = implode( ',', array_fill( 0, \count( $upcs ), '?' ) );
				foreach ( Db::i()->select( '*', 'gd_catalog', array_merge( [ "upc IN({$ph})" ], $upcs ) ) as $cr )
				{
					$catalogMap[ $cr['upc'] ] = $cr;
				}
			}
			catch ( \Throwable ) {}
		}

		$priceMap = [];
		if ( $upcs )
		{
			try
			{
				$ph = implode( ',', array_fill( 0, \count( $upcs ), '?' ) );
				$stmt = Db::i()->preparedQuery(
					"SELECT upc, MIN(dealer_price) AS best_price, COUNT(DISTINCT dealer_id) AS dealer_count "
					. "FROM `{$prefix}gd_dealer_listings` "
					. "WHERE upc IN({$ph}) AND listing_status='active' GROUP BY upc",
					$upcs
				);
				while ( $pr = $stmt->fetch_assoc() )
				{
					$priceMap[ $pr['upc'] ] = $pr;
				}
			}
			catch ( \Throwable ) {}
		}

		foreach ( $rawItems as $ri )
		{
			$u       = $ri['upc'] ?? '';
			$cat     = $catalogMap[ $u ] ?? [];
			$pricing = $priceMap[ $u ] ?? [];

			$items[] = [
				'id'                  => $ri['id'] ?? 0,
				'loadout_id'          => $ri['loadout_id'] ?? 0,
				'upc'                 => $u,
				'slot_type'           => $ri['slot_type'] ?? 'extra',
				'custom_label'        => $ri['custom_label'] ?? null,
				'sort_order'          => $ri['sort_order'] ?? 0,
				'notes'               => $ri['notes'] ?? null,
				'product_title'       => $cat['title'] ?? null,
				'brand'               => $cat['brand'] ?? null,
				'caliber'             => $cat['caliber'] ?? null,
				'mpn'                 => $cat['mpn'] ?? null,
				'image_url'           => $cat['image_url'] ?? null,
				'nfa_item'            => $cat['nfa_item'] ?? 0,
				'requires_ffl'        => $cat['requires_ffl'] ?? 0,
				'is_ammo'             => $cat['is_ammo'] ?? 0,
				'category_id'         => $cat['category_id'] ?? 0,
				'live_price'          => ( !empty( $pricing['best_price'] ) && (float) $pricing['best_price'] > 0 ) ? (float) $pricing['best_price'] : null,
				'active_dealer_count' => (int) ( $pricing['dealer_count'] ?? 0 ),
			];
		}

		/* Compliance summary */
		$nfaCount        = 0;
		$fflCount        = 0;
		$ammoCount       = 0;
		$restrictedCount = 0;
		foreach ( $items as $it )
		{
			if ( !empty( $it['nfa_item'] ) )
			{
				$nfaCount++;
			}
			if ( !empty( $it['requires_ffl'] ) )
			{
				$fflCount++;
			}
			if ( !empty( $it['is_ammo'] ) )
			{
				$ammoCount++;
			}
		}
		$compliance = [
			'nfa_count'        => $nfaCount,
			'ffl_count'        => $fflCount,
			'ammo_count'       => $ammoCount,
			'restricted_count' => $restrictedCount,
			'has_issues'       => ( $nfaCount + $fflCount + $restrictedCount ) > 0,
		];

		/* Increment view count (not for owner) */
		if ( !$isOwner )
		{
			try
			{
				Db::i()->update( 'gd_loadouts', 'view_count=view_count+1', [ 'id=?', (int) $loadout['id'] ] );
			}
			catch ( \Throwable ) {}
		}

		/* Check if viewer has voted / followed */
		$hasVoted    = false;
		$hasFollowed = false;
		if ( $member->member_id )
		{
			try
			{
				Db::i()->select( 'id', 'gd_loadout_votes', [ 'loadout_id=? AND member_id=?', (int) $loadout['id'], (int) $member->member_id ] )->first();
				$hasVoted = true;
			}
			catch ( \Throwable ) {}

			try
			{
				Db::i()->select( 'id', 'gd_loadout_follows', [ 'loadout_id=? AND member_id=?', (int) $loadout['id'], (int) $member->member_id ] )->first();
				$hasFollowed = true;
			}
			catch ( \Throwable ) {}
		}

		/* Load comments */
		$comments = [];
		try
		{
			foreach ( Db::i()->select( '*', 'gd_loadout_comments', [ 'loadout_id=?', (int) $loadout['id'] ], 'created_at ASC', [ 0, 100 ] ) as $c )
			{
				$c['member_name'] = '';
				try
				{
					$c['member_name'] = Member::load( (int) $c['member_id'] )->name;
				}
				catch ( \Throwable ) {}
				$comments[] = $c;
			}
		}
		catch ( \Throwable ) {}

		/* Build URLs — no ->csrf() on these since they're for AJAX POST (#48, #62) */
		$editUrl      = $isOwner ? (string) Url::internal( 'app=gdloadout&module=loadouts&controller=builder&id=' . (int) $loadout['id'], 'front', 'gdloadout_builder_edit' ) : '';
		$upvoteUrl    = (string) Url::internal( 'app=gdloadout&module=loadouts&controller=hub&do=upvote', 'front' );
		$followUrl    = (string) Url::internal( 'app=gdloadout&module=loadouts&controller=hub&do=follow', 'front' );
		$commentUrl   = (string) Url::internal( 'app=gdloadout&module=loadouts&controller=hub&do=comment', 'front' );
		$wishlistUrl  = (string) Url::internal( 'app=gdloadout&module=loadouts&controller=hub&do=addAllToWishlist', 'front' );
		$alertUrl     = (string) Url::internal( 'app=gdloadout&module=loadouts&controller=hub&do=alertAllItems', 'front' );
		$csrfKey      = Session::i()->csrfKey;

		/* SEO */
		$jsonLd = null;
		if ( $loadout['visibility'] === 'public' )
		{
			/* Build SEO title from items */
			$baseFirearmTitle = '';
			$opticTitle       = '';
			$accessoryCount   = 0;
			$baseFirearmBrand = '';

			foreach ( $items as $it )
			{
				if ( ( $it['slot_type'] ?? '' ) === 'base_firearm' )
				{
					$baseFirearmTitle = $it['product_title'] ?? $it['custom_label'] ?? '';
					$baseFirearmBrand = $it['brand'] ?? '';
				}
				if ( ( $it['slot_type'] ?? '' ) === 'optic' )
				{
					$opticTitle = $it['product_title'] ?? $it['custom_label'] ?? '';
				}
				$accessoryCount++;
			}

			$seoUseCase = $loadout['use_case'] ?: 'Custom';
			$seoTitle   = htmlspecialchars( $loadout['name'] ) . ' — ' . $seoUseCase . ' Build';
			if ( $baseFirearmBrand )
			{
				$seoTitle .= ' — ' . $baseFirearmBrand;
			}
			$seoTitle .= ' | GunRack';
			Output::i()->title = $seoTitle;

			/* Meta description */
			$descParts   = [];
			$descParts[] = $seoUseCase . ' build';
			if ( $baseFirearmTitle )
			{
				$descParts[] = 'featuring ' . $baseFirearmTitle;
			}
			if ( $opticTitle )
			{
				$descParts[] = 'with ' . $opticTitle;
			}
			$descParts[] = 'and ' . max( 0, $accessoryCount - 2 ) . ' accessories';
			if ( $loadout['total_min_price'] )
			{
				$descParts[] = 'Est. total cost: $' . number_format( (float) $loadout['total_min_price'], 2 );
			}
			$dealerCount = 0;
			foreach ( $items as $it )
			{
				$dealerCount = max( $dealerCount, (int) ( $it['active_dealer_count'] ?? 0 ) );
			}
			if ( $dealerCount > 0 )
			{
				$descParts[] = 'Prices from ' . $dealerCount . ' dealers';
			}
			Output::i()->metaTags['description'] = implode( '. ', $descParts ) . '.';

			/* JSON-LD ItemList */
			$jsonLdItems = [];
			$pos = 1;
			foreach ( $items as $it )
			{
				$itemName = $it['product_title'] ?? $it['custom_label'] ?? ( $it['upc'] ?? '' );
				$itemUrl  = (string) Url::internal( 'app=gdsearch&module=search&controller=results&do=product&upc=' . urlencode( $it['upc'] ?? '' ), 'front' );
				$jsonLdItems[] = [
					'@type'    => 'ListItem',
					'position' => $pos,
					'name'     => $itemName,
					'url'      => $itemUrl,
				];
				$pos++;
			}
			$jsonLd = json_encode( [
				'@context'        => 'https://schema.org',
				'@type'           => 'ItemList',
				'name'            => $loadout['name'],
				'numberOfItems'   => \count( $items ),
				'itemListElement' => $jsonLdItems,
			], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG );
		}
		else
		{
			/* Private or unlisted — noindex, basic title */
			Output::i()->title = htmlspecialchars( $loadout['name'] ) . ' — Loadout';
			Output::i()->metaTags['robots'] = 'noindex';
		}

		/* Init data for JS interactions */
		$initData = json_encode( [
			'loadoutId'   => (int) $loadout['id'],
			'upvoteUrl'   => $upvoteUrl,
			'followUrl'   => $followUrl,
			'commentUrl'  => $commentUrl,
			'wishlistUrl' => $wishlistUrl,
			'alertUrl'    => $alertUrl,
			'csrfKey'     => $csrfKey,
			'hasVoted'    => $hasVoted,
			'hasFollowed' => $hasFollowed,
			'isLoggedIn'  => (bool) $member->member_id,
		], JSON_HEX_TAG | JSON_HEX_AMP );

		/* Enqueue CSS + JS */
		Output::i()->cssFiles = array_merge( Output::i()->cssFiles, Theme::i()->css( 'loadouts.css', 'gdloadout', 'interface' ) );
		Output::i()->jsFiles  = array_merge( Output::i()->jsFiles, Output::i()->js( 'loadout.js', 'gdloadout', 'interface' ) );

		/* Render */
		$html = Theme::i()->getTemplate( 'loadouts', 'gdloadout', 'front' )->view(
			$loadout, $items, $ownerName, $isOwner, $editUrl, $compliance,
			$hasVoted, $hasFollowed, $comments, $initData
		);

		if ( $jsonLd !== null )
		{
			$html = '<script type="application/ld+json">' . $jsonLd . '</script>' . $html;
		}

		Output::i()->output = $html;
	}

	/**
	 * AJAX toggle upvote on a loadout
	 */
	protected function upvote(): void
	{
		Session::i()->csrfCheck();

		$member = Member::loggedIn();
		if ( !$member->member_id )
		{
			Output::i()->json( [ 'error' => 'Login required' ], 403 );
			return;
		}

		$loadoutId = (int) ( Request::i()->loadout_id ?? 0 );
		if ( !$loadoutId )
		{
			Output::i()->json( [ 'error' => 'Invalid' ], 400 );
			return;
		}

		$prefix  = Db::i()->prefix;
		$existed = false;
		try
		{
			Db::i()->select( 'id', 'gd_loadout_votes', [ 'loadout_id=? AND member_id=?', $loadoutId, (int) $member->member_id ] )->first();
			$existed = true;
		}
		catch ( \Throwable ) {}

		if ( $existed )
		{
			Db::i()->delete( 'gd_loadout_votes', [ 'loadout_id=? AND member_id=?', $loadoutId, (int) $member->member_id ] );
			try
			{
				Db::i()->preparedQuery(
					"UPDATE `{$prefix}gd_loadouts` SET upvotes = GREATEST(0, upvotes - 1) WHERE id = ?",
					[ $loadoutId ]
				);
			}
			catch ( \Throwable ) {}
		}
		else
		{
			try
			{
				Db::i()->insert( 'gd_loadout_votes', [
					'loadout_id' => $loadoutId,
					'member_id'  => (int) $member->member_id,
					'voted_at'   => time(),
				] );
				Db::i()->preparedQuery(
					"UPDATE `{$prefix}gd_loadouts` SET upvotes = upvotes + 1 WHERE id = ?",
					[ $loadoutId ]
				);
			}
			catch ( \Throwable ) {}

			try
			{
				$loadout = Db::i()->select( '*', 'gd_loadouts', [ 'id=?', $loadoutId ] )->first();
				$ownerId = (int) ( $loadout['member_id'] ?? 0 );
				if ( $ownerId && $ownerId !== (int) $member->member_id )
				{
					$owner    = Member::load( $ownerId );
					$ownerName = $owner->name ?? 'Unknown';
					$notification = new \IPS\Notification(
						\IPS\Application::load( 'gdloadout' ),
						'loadout_upvoted',
						$owner,
						[ $owner ],
						[ 'loadout_name' => $loadout['name'] ?? '', 'voter_name' => $member->name, 'username' => $ownerName, 'slug' => $loadout['slug'] ?? '' ]
					);
					$notification->recipients->attach( $owner );
					$notification->send();
				}
			}
			catch ( \Throwable ) {}
		}

		$newCount = 0;
		try
		{
			$newCount = (int) Db::i()->select( 'upvotes', 'gd_loadouts', [ 'id=?', $loadoutId ] )->first();
		}
		catch ( \Throwable ) {}

		Output::i()->json( [ 'ok' => true, 'voted' => !$existed, 'count' => $newCount ] );
	}

	/**
	 * AJAX toggle follow on a loadout
	 */
	protected function follow(): void
	{
		Session::i()->csrfCheck();

		$member = Member::loggedIn();
		if ( !$member->member_id )
		{
			Output::i()->json( [ 'error' => 'Login required' ], 403 );
			return;
		}

		$loadoutId = (int) ( Request::i()->loadout_id ?? 0 );
		if ( !$loadoutId )
		{
			Output::i()->json( [ 'error' => 'Invalid' ], 400 );
			return;
		}

		$prefix  = Db::i()->prefix;
		$existed = false;
		try
		{
			Db::i()->select( 'id', 'gd_loadout_follows', [ 'loadout_id=? AND member_id=?', $loadoutId, (int) $member->member_id ] )->first();
			$existed = true;
		}
		catch ( \Throwable ) {}

		if ( $existed )
		{
			Db::i()->delete( 'gd_loadout_follows', [ 'loadout_id=? AND member_id=?', $loadoutId, (int) $member->member_id ] );
			try
			{
				Db::i()->preparedQuery(
					"UPDATE `{$prefix}gd_loadouts` SET follow_count = GREATEST(0, follow_count - 1) WHERE id = ?",
					[ $loadoutId ]
				);
			}
			catch ( \Throwable ) {}
		}
		else
		{
			try
			{
				Db::i()->insert( 'gd_loadout_follows', [
					'loadout_id'  => $loadoutId,
					'member_id'   => (int) $member->member_id,
					'followed_at' => time(),
				] );
				Db::i()->preparedQuery(
					"UPDATE `{$prefix}gd_loadouts` SET follow_count = follow_count + 1 WHERE id = ?",
					[ $loadoutId ]
				);
			}
			catch ( \Throwable ) {}

			try
			{
				$loadout = Db::i()->select( '*', 'gd_loadouts', [ 'id=?', $loadoutId ] )->first();
				$ownerId = (int) ( $loadout['member_id'] ?? 0 );
				if ( $ownerId && $ownerId !== (int) $member->member_id )
				{
					$owner     = Member::load( $ownerId );
					$ownerName = $owner->name ?? 'Unknown';
					$notification = new \IPS\Notification(
						\IPS\Application::load( 'gdloadout' ),
						'loadout_followed',
						$owner,
						[ $owner ],
						[ 'loadout_name' => $loadout['name'] ?? '', 'follower_name' => $member->name, 'username' => $ownerName, 'slug' => $loadout['slug'] ?? '' ]
					);
					$notification->recipients->attach( $owner );
					$notification->send();
				}
			}
			catch ( \Throwable ) {}
		}

		$newCount = 0;
		try
		{
			$newCount = (int) Db::i()->select( 'follow_count', 'gd_loadouts', [ 'id=?', $loadoutId ] )->first();
		}
		catch ( \Throwable ) {}

		Output::i()->json( [ 'ok' => true, 'followed' => !$existed, 'count' => $newCount ] );
	}

	/**
	 * AJAX post a comment on a loadout
	 */
	protected function comment(): void
	{
		Session::i()->csrfCheck();

		$member = Member::loggedIn();
		if ( !$member->member_id )
		{
			Output::i()->json( [ 'error' => 'Login required' ], 403 );
			return;
		}

		$loadoutId = (int) ( Request::i()->loadout_id ?? 0 );
		$text      = trim( Request::i()->comment_text ?? '' );

		if ( !$loadoutId || $text === '' )
		{
			Output::i()->json( [ 'error' => 'Invalid' ], 400 );
			return;
		}

		$text = substr( $text, 0, 2000 );

		$commentId = Db::i()->insert( 'gd_loadout_comments', [
			'loadout_id' => $loadoutId,
			'member_id'  => (int) $member->member_id,
			'comment'    => $text,
			'created_at' => time(),
		] );

		$prefix = Db::i()->prefix;
		try
		{
			Db::i()->preparedQuery(
				"UPDATE `{$prefix}gd_loadouts` SET comment_count = comment_count + 1 WHERE id = ?",
				[ $loadoutId ]
			);
		}
		catch ( \Throwable ) {}

		Output::i()->json( [
			'ok'      => true,
			'comment' => [
				'id'          => (int) $commentId,
				'member_name' => $member->name,
				'comment'     => htmlspecialchars( $text ),
				'created_at'  => time(),
			],
		] );
	}
	/**
	 * AJAX — add all items from a loadout to the viewer's wishlist
	 */
	protected function addAllToWishlist(): void
	{
		Session::i()->csrfCheck();

		$member = Member::loggedIn();
		if ( !$member->member_id )
		{
			Output::i()->json( [ 'error' => 'Login required' ], 403 );
			return;
		}

		$loadoutId = (int) ( Request::i()->loadout_id ?? 0 );
		if ( !$loadoutId )
		{
			Output::i()->json( [ 'error' => 'Invalid' ], 400 );
			return;
		}

		$loadout = NULL;
		try
		{
			$loadout = Db::i()->select( '*', 'gd_loadouts', [ 'id=?', $loadoutId ] )->first();
		}
		catch ( \Throwable ) {}

		if ( !$loadout )
		{
			Output::i()->json( [ 'error' => 'Not found' ], 404 );
			return;
		}

		$isOwner = (int) $member->member_id === (int) $loadout['member_id'];
		if ( $loadout['visibility'] === 'private' && !$isOwner )
		{
			Output::i()->json( [ 'error' => 'Forbidden' ], 403 );
			return;
		}

		$added   = 0;
		$skipped = 0;

		try
		{
			foreach ( Db::i()->select( 'upc', 'gd_loadout_items', [ 'loadout_id=?', $loadoutId ] ) as $upc )
			{
				if ( empty( $upc ) )
				{
					continue;
				}

				try
				{
					$exists = (int) Db::i()->select( 'COUNT(*)', 'gd_wishlist', [ 'member_id=? AND upc=?', (int) $member->member_id, $upc ] )->first();
					if ( $exists > 0 )
					{
						$skipped++;
						continue;
					}

					Db::i()->insert( 'gd_wishlist', [
						'member_id' => (int) $member->member_id,
						'upc'       => $upc,
						'created'   => time(),
					] );
					$added++;
				}
				catch ( \Throwable )
				{
					$skipped++;
				}
			}
		}
		catch ( \Throwable ) {}

		Output::i()->json( [ 'ok' => true, 'added' => $added, 'skipped' => $skipped ] );
	}

	/**
	 * AJAX — set price alerts on all items in a loadout
	 */
	protected function alertAllItems(): void
	{
		Session::i()->csrfCheck();

		$member = Member::loggedIn();
		if ( !$member->member_id )
		{
			Output::i()->json( [ 'error' => 'Login required' ], 403 );
			return;
		}

		$loadoutId = (int) ( Request::i()->loadout_id ?? 0 );
		if ( !$loadoutId )
		{
			Output::i()->json( [ 'error' => 'Invalid' ], 400 );
			return;
		}

		$loadout = NULL;
		try
		{
			$loadout = Db::i()->select( '*', 'gd_loadouts', [ 'id=?', $loadoutId ] )->first();
		}
		catch ( \Throwable ) {}

		if ( !$loadout )
		{
			Output::i()->json( [ 'error' => 'Not found' ], 404 );
			return;
		}

		$isOwner = (int) $member->member_id === (int) $loadout['member_id'];
		if ( $loadout['visibility'] === 'private' && !$isOwner )
		{
			Output::i()->json( [ 'error' => 'Forbidden' ], 403 );
			return;
		}

		$prefix = Db::i()->prefix;
		$set    = 0;

		try
		{
			foreach ( Db::i()->select( 'upc', 'gd_loadout_items', [ 'loadout_id=?', $loadoutId ] ) as $upc )
			{
				if ( empty( $upc ) )
				{
					continue;
				}

				try
				{
					$bestPrice = NULL;
					try
					{
						$p = Db::i()->select( 'MIN(dealer_price) AS best_price', 'gd_dealer_listings', [ 'upc=? AND listing_status=?', $upc, 'active' ] )->first();
						if ( $p['best_price'] !== NULL && (float) $p['best_price'] > 0 )
						{
							$bestPrice = (float) $p['best_price'];
						}
					}
					catch ( \Throwable ) {}

					if ( $bestPrice === NULL )
					{
						continue;
					}

					Db::i()->replace( 'gd_price_alerts', [
						'member_id' => (int) $member->member_id,
						'upc'       => $upc,
						'threshold' => $bestPrice,
						'created'   => time(),
					] );
					$set++;
				}
				catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable ) {}

		Output::i()->json( [ 'ok' => true, 'set' => $set ] );
	}
}

class hub extends _hub {}
