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

	public static function ensureForumTopic( array $loadout ): ?int
	{
		$loadoutId = (int) ( $loadout['id'] ?? 0 );
		if ( !$loadoutId ) return null;

		$fresh = null;
		try { $fresh = Db::i()->select( 'forum_topic_id', 'gd_loadouts', [ 'id=?', $loadoutId ] )->first(); } catch ( \Throwable ) {}
		if ( $fresh && (int) $fresh > 0 )
		{
			return (int) $fresh;
		}

		if ( ( $loadout['visibility'] ?? '' ) === 'private' )
		{
			return null;
		}

		$forumId = 0;
		try { $forumId = (int) \IPS\Settings::i()->gdloadout_share_forum; } catch ( \Throwable ) {}
		if ( !$forumId ) return null;

		$forumsEnabled = false;
		try { $forumsEnabled = \IPS\Application::appIsEnabled( 'forums' ); } catch ( \Throwable ) {}
		if ( !$forumsEnabled ) return null;

		try
		{
			$forum = \IPS\forums\Forum::load( $forumId );
		}
		catch ( \Throwable ) { return null; }

		$owner = Member::load( (int) $loadout['member_id'] );
		$ownerName = $owner->name ?? 'Unknown';

		$viewUrl = (string) Url::internal(
			'app=gdloadout&module=loadouts&controller=hub&do=view&username=' . urlencode( $ownerName ) . '&slug=' . urlencode( $loadout['slug'] ?? '' ),
			'front', 'gdloadout_view'
		);

		$totalPrice = '';
		if ( (float) ( $loadout['total_min_price'] ?? 0 ) > 0 )
		{
			$totalPrice = ' | Est. $' . number_format( (float) $loadout['total_min_price'], 0 );
		}

		$topicTitle = ( $loadout['name'] ?? 'Loadout' ) . ' — Loadout by ' . $ownerName;

		$postBody = '<p><strong><a href="' . htmlspecialchars( $viewUrl, ENT_QUOTES, 'UTF-8' ) . '">'
			. htmlspecialchars( $loadout['name'] ?? '', ENT_QUOTES, 'UTF-8' ) . '</a></strong></p>'
			. '<p>' . htmlspecialchars( $loadout['description'] ?? '', ENT_QUOTES, 'UTF-8' ) . '</p>'
			. '<p><strong>Items:</strong> ' . (int) ( $loadout['total_items'] ?? 0 )
			. $totalPrice
			. ( ( $loadout['use_case'] ?? '' ) ? ' | ' . htmlspecialchars( $loadout['use_case'], ENT_QUOTES, 'UTF-8' ) : '' )
			. '</p>'
			. '<p><a href="' . htmlspecialchars( $viewUrl, ENT_QUOTES, 'UTF-8' ) . '">View full loadout on GunRack</a></p>';

		try
		{
			$topic = \IPS\forums\Topic::createItem( $owner, $owner->ip_address, \IPS\DateTime::create(), $forum, FALSE );
			$topic->title = $topicTitle;
			$topic->save();

			$post = \IPS\forums\Topic\Post::create( $topic, $postBody, TRUE, NULL, NULL, $owner );
			$topic->topic_firstpost = $post->pid;
			$topic->save();

			$topicId = (int) $topic->tid;

			Db::i()->update( 'gd_loadouts', [ 'forum_topic_id' => $topicId ], [ 'id=?', $loadoutId ] );

			try
			{
				Db::i()->insert( 'gd_loadout_forum_posts', [
					'loadout_id' => $loadoutId,
					'member_id'  => (int) $loadout['member_id'],
					'topic_id'   => $topicId,
					'posted_at'  => time(),
				] );
			}
			catch ( \Throwable ) {}

			return $topicId;
		}
		catch ( \Throwable $e )
		{
			\IPS\Log::log( $e, 'gdloadout_forum' );

			if ( isset( $topic ) && $topic->tid )
			{
				$recoveredId = (int) $topic->tid;
				try { Db::i()->update( 'gd_loadouts', [ 'forum_topic_id' => $recoveredId ], [ 'id=?', $loadoutId ] ); } catch ( \Throwable ) {}
				return $recoveredId;
			}

			return null;
		}
	}

	protected function enrichLoadout( array &$row ): void
	{
		$ownerName = 'Unknown';
		try { $ownerName = Member::load( (int) $row['member_id'] )->name; } catch ( \Throwable ) {}
		$row['owner_name'] = $ownerName;
		$row['view_url']   = (string) Url::internal(
			'app=gdloadout&module=loadouts&controller=hub&do=view&username=' . urlencode( $ownerName ) . '&slug=' . urlencode( $row['slug'] ),
			'front', 'gdloadout_view'
		);

		$baseUpc = null;
		try { $baseUpc = (string) Db::i()->select( 'upc', 'gd_loadout_items', [ 'loadout_id=? AND slot_type=?', (int) $row['id'], 'base_firearm' ], 'sort_order ASC', 1 )->first(); } catch ( \Throwable ) {}
		if ( !$baseUpc ) { try { $baseUpc = (string) Db::i()->select( 'upc', 'gd_loadout_items', [ 'loadout_id=? AND slot_type=?', (int) $row['id'], 'lower_receiver' ], 'sort_order ASC', 1 )->first(); } catch ( \Throwable ) {} }
		if ( !$baseUpc ) { try { $baseUpc = (string) Db::i()->select( 'upc', 'gd_loadout_items', [ 'loadout_id=?', (int) $row['id'] ], 'sort_order ASC', 1 )->first(); } catch ( \Throwable ) {} }
		$row['base_image'] = null;
		$row['base_title'] = null;
		if ( $baseUpc )
		{
			try
			{
				$cat = Db::i()->select( 'image_url, title', 'gd_catalog', [ 'upc=?', $baseUpc ] )->first();
				$row['base_image'] = $cat['image_url'] ?? null;
				$row['base_title'] = $cat['title'] ?? null;
			}
			catch ( \Throwable ) {}
		}

		$row['mode_label'] = match( $row['build_mode'] ?? '' ) {
			'complete_firearm' => 'Complete',
			'component_build'  => 'Component',
			default            => '',
		};
	}

	protected function manage(): void
	{
		$member      = Member::loggedIn();
		$prefix      = Db::i()->prefix;
		$activeUseCase = trim( Request::i()->use_case ?? '' );

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

		/* Featured */
		$featured = [];
		try
		{
			foreach ( Db::i()->select( '*', 'gd_loadouts', [ 'visibility=? AND featured=?', 'public', 1 ], 'featured_position ASC', [ 0, 6 ] ) as $row )
			{
				$this->enrichLoadout( $row );
				$featured[] = $row;
			}
		}
		catch ( \Throwable ) {}
		$sections['featured'] = $featured;

		$ucWhere  = '';
		$ucBinds  = [];
		if ( $activeUseCase !== '' )
		{
			$ucWhere = ' AND use_case=?';
			$ucBinds = [ $activeUseCase ];
		}

		/* Trending */
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
					$this->enrichLoadout( $row );
					$trending[] = $row;
				}

				$idOrder = array_flip( $trendingIds );
				usort( $trending, static function ( $a, $b ) use ( $idOrder ) {
					return ( $idOrder[ (int) $a['id'] ] ?? 999 ) <=> ( $idOrder[ (int) $b['id'] ] ?? 999 );
				} );
			}
		}
		catch ( \Throwable ) {}
		$sections['trending'] = $trending;

		/* Top Rated */
		$topRated = [];
		try
		{
			$where = array_merge(
				[ 'visibility=? AND upvotes >= ?' . $ucWhere, 'public', 10 ],
				$ucBinds
			);
			foreach ( Db::i()->select( '*', 'gd_loadouts', $where, 'upvotes DESC', [ 0, 8 ] ) as $row )
			{
				$this->enrichLoadout( $row );
				$topRated[] = $row;
			}
		}
		catch ( \Throwable ) {}
		$sections['top_rated'] = $topRated;

		/* Recently Updated */
		$recent = [];
		try
		{
			$where = array_merge(
				[ 'visibility=?' . $ucWhere, 'public' ],
				$ucBinds
			);
			foreach ( Db::i()->select( '*', 'gd_loadouts', $where, 'COALESCE(updated_at, created_at) DESC', [ 0, 8 ] ) as $row )
			{
				$this->enrichLoadout( $row );
				$recent[] = $row;
			}
		}
		catch ( \Throwable ) {}
		$sections['recent'] = $recent;

		/* Budget */
		$budget = [];
		try
		{
			$where = array_merge(
				[ 'visibility=? AND total_min_price > ? AND total_min_price < ?' . $ucWhere, 'public', 0, 500 ],
				$ucBinds
			);
			foreach ( Db::i()->select( '*', 'gd_loadouts', $where, 'total_min_price ASC', [ 0, 8 ] ) as $row )
			{
				$this->enrichLoadout( $row );
				$budget[] = $row;
			}
		}
		catch ( \Throwable ) {}
		$sections['budget'] = $budget;

		$canCreate     = $member->member_id ? \IPS\gdloadout\Loadout\Limits::canCreateLoadout( $member ) : false;
		$builderUrl    = (string) Url::internal( 'app=gdloadout&module=loadouts&controller=builder', 'front', 'gdloadout_builder' );
		$myLoadoutsUrl = (string) Url::internal( 'app=gdloadout&module=loadouts&controller=hub&do=mine', 'front', 'gdloadout_mine' );
		$copyUrl       = (string) Url::internal( 'app=gdloadout&module=loadouts&controller=hub&do=copy', 'front' );
		$csrfKey       = Session::i()->csrfKey;

		Output::i()->cssFiles = array_merge( Output::i()->cssFiles, Theme::i()->css( 'loadouts.css', 'gdloadout', 'interface' ) );
		Output::i()->title    = Member::loggedIn()->language()->addToStack( 'gdloadout_hub_title' );
		Output::i()->output   = Theme::i()->getTemplate( 'loadouts', 'gdloadout', 'front' )->hub( $sections, $canCreate, $builderUrl, $activeUseCase, $useCases, $myLoadoutsUrl, $copyUrl, $csrfKey );
	}

	protected function view(): void
	{
		$username = trim( Request::i()->username ?? '' );
		$slug     = trim( Request::i()->slug ?? '' );

		if ( $username === '' || $slug === '' )
		{
			Output::i()->error( 'gdloadout_err_not_found', '2GDL/1', 404 );
			return;
		}

		$owner = Member::load( $username, 'name' );
		if ( !(int) $owner->member_id )
		{
			Output::i()->error( 'gdloadout_err_not_found', '2GDL/1', 404 );
			return;
		}

		$memberId = (int) $owner->member_id;
		$ownerName = $owner->name ?? 'Unknown';

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

		if ( $loadout['visibility'] === 'private' && !$isOwner )
		{
			Output::i()->error( 'node_error', '2GDL/2', 403 );
			return;
		}

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

		$nfaCount = 0;
		$fflCount = 0;
		foreach ( $items as $it )
		{
			if ( !empty( $it['nfa_item'] ) ) $nfaCount++;
			if ( !empty( $it['requires_ffl'] ) ) $fflCount++;
		}
		$compliance = [
			'nfa_count'  => $nfaCount,
			'ffl_count'  => $fflCount,
			'has_issues' => ( $nfaCount + $fflCount ) > 0,
		];

		if ( !$isOwner )
		{
			try { Db::i()->update( 'gd_loadouts', 'view_count=view_count+1', [ 'id=?', (int) $loadout['id'] ] ); }
			catch ( \Throwable ) {}
		}

		$hasVoted    = false;
		$hasFollowed = false;
		if ( $member->member_id )
		{
			try { Db::i()->select( 'id', 'gd_loadout_votes', [ 'loadout_id=? AND member_id=?', (int) $loadout['id'], (int) $member->member_id ] )->first(); $hasVoted = true; } catch ( \Throwable ) {}
			try { Db::i()->select( 'id', 'gd_loadout_follows', [ 'loadout_id=? AND member_id=?', (int) $loadout['id'], (int) $member->member_id ] )->first(); $hasFollowed = true; } catch ( \Throwable ) {}
		}

		$editUrl      = $isOwner ? (string) Url::internal( 'app=gdloadout&module=loadouts&controller=builder&loadout_id=' . (int) $loadout['id'], 'front', 'gdloadout_builder_edit' ) : '';
		$upvoteUrl    = (string) Url::internal( 'app=gdloadout&module=loadouts&controller=hub&do=upvote', 'front' );
		$followUrl    = (string) Url::internal( 'app=gdloadout&module=loadouts&controller=hub&do=follow', 'front' );
		$wishlistUrl  = (string) Url::internal( 'app=gdloadout&module=loadouts&controller=hub&do=addAllToWishlist', 'front' );
		$alertUrl     = (string) Url::internal( 'app=gdloadout&module=loadouts&controller=hub&do=alertAllItems', 'front' );
		$csrfKey      = Session::i()->csrfKey;

		Output::i()->title = htmlspecialchars( $loadout['name'] ) . ' — Loadout | GunRack';

		$forumTopicUrl = '';
		try
		{
			if ( !empty( $loadout['forum_topic_id'] ) && (int) $loadout['forum_topic_id'] > 0 )
			{
				$topic = \IPS\forums\Topic::load( (int) $loadout['forum_topic_id'] );
				$forumTopicUrl = (string) $topic->url();
				$loadout['comment_count'] = max( 0, (int) ( $topic->posts ?? 1 ) - 1 );
			}
		}
		catch ( \Throwable ) {}

		$canSuggest = false;
		if ( $member->member_id && !$isOwner )
		{
			$canSuggest = \IPS\gdloadout\Loadout\Loadout::canSuggest( $member, $loadout );
		}

		$suggestions = [];
		$pendingSuggestionCount = 0;
		try
		{
			if ( $isOwner )
			{
				foreach ( Db::i()->select( '*', 'gd_loadout_suggestions', [ 'loadout_id=? AND status=?', (int) $loadout['id'], 'pending' ], 'created_at DESC' ) as $sug )
				{
					$sugFromName = 'Unknown';
					try { $sugFromName = Member::load( (int) $sug['from_member'] )->name; } catch ( \Throwable ) {}

					$sugProduct = [];
					try { $sugProduct = Db::i()->select( 'title, brand, image_url', 'gd_catalog', [ 'upc=?', $sug['suggested_upc'] ] )->first(); } catch ( \Throwable ) {}

					$sugPrice = null;
					try
					{
						$p = Db::i()->select( 'MIN(dealer_price) AS best_price', 'gd_dealer_listings', [ 'upc=? AND listing_status=?', $sug['suggested_upc'], 'active' ] )->first();
						if ( $p['best_price'] !== null && (float) $p['best_price'] > 0 ) $sugPrice = (float) $p['best_price'];
					}
					catch ( \Throwable ) {}

					$currentUpc = '';
					$currentProduct = [];
					try { $currentUpc = (string) Db::i()->select( 'upc', 'gd_loadout_items', [ 'loadout_id=? AND slot_type=?', (int) $loadout['id'], $sug['slot_type'] ] )->first(); } catch ( \Throwable ) {}
					if ( $currentUpc ) { try { $currentProduct = Db::i()->select( 'title, brand, image_url', 'gd_catalog', [ 'upc=?', $currentUpc ] )->first(); } catch ( \Throwable ) {} }

					$sug['from_name']       = $sugFromName;
					$sug['sug_title']       = $sugProduct['title'] ?? $sug['suggested_upc'];
					$sug['sug_brand']       = $sugProduct['brand'] ?? '';
					$sug['sug_image']       = $sugProduct['image_url'] ?? '';
					$sug['sug_price']       = $sugPrice;
					$sug['current_upc']     = $currentUpc;
					$sug['current_title']   = $currentProduct['title'] ?? $currentUpc;
					$sug['current_image']   = $currentProduct['image_url'] ?? '';
					$suggestions[] = $sug;
				}
				$pendingSuggestionCount = \count( $suggestions );
			}
		}
		catch ( \Throwable ) {}

		$filledSlots = [];
		foreach ( $items as $it )
		{
			$slotKey = $it['slot_type'] ?? 'extra';
			if ( !empty( $it['upc'] ) && $slotKey !== 'extra' )
			{
				$filledSlots[ $slotKey ] = $it['product_title'] ?: $it['upc'];
			}
		}

		$suggestUrl   = (string) Url::internal( 'app=gdloadout&module=loadouts&controller=hub&do=suggest', 'front' );
		$acceptSugUrl = (string) Url::internal( 'app=gdloadout&module=loadouts&controller=hub&do=acceptSuggestion', 'front' );
		$rejectSugUrl = (string) Url::internal( 'app=gdloadout&module=loadouts&controller=hub&do=rejectSuggestion', 'front' );
		$searchUrl    = (string) Url::internal( 'app=gdloadout&module=loadouts&controller=builder&do=search', 'front', 'gdloadout_builder' );

		$initData = json_encode( [
			'loadoutId'   => (int) $loadout['id'],
			'upvoteUrl'   => $upvoteUrl,
			'followUrl'   => $followUrl,
			'wishlistUrl' => $wishlistUrl,
			'alertUrl'    => $alertUrl,
			'suggestUrl'  => $suggestUrl,
			'searchUrl'   => $searchUrl,
			'csrfKey'     => $csrfKey,
			'hasVoted'    => $hasVoted,
			'hasFollowed' => $hasFollowed,
			'isLoggedIn'  => (bool) $member->member_id,
			'canSuggest'  => $canSuggest,
			'filledSlots' => $filledSlots,
		], JSON_HEX_TAG | JSON_HEX_AMP );

		$canCopy = ( (int) $member->member_id && !$isOwner );
		$copyUrl = (string) Url::internal( 'app=gdloadout&module=loadouts&controller=hub&do=copy&loadout_id=' . (int) $loadout['id'], 'front' );
		$startDiscussionUrl = (string) Url::internal( 'app=gdloadout&module=loadouts&controller=hub&do=startDiscussion&loadout_id=' . (int) $loadout['id'], 'front' );

		Output::i()->cssFiles = array_merge( Output::i()->cssFiles, Theme::i()->css( 'loadouts.css', 'gdloadout', 'interface' ) );
		Output::i()->jsFiles  = array_merge( Output::i()->jsFiles, Output::i()->js( 'loadout.js', 'gdloadout', 'interface' ) );
		Output::i()->output   = Theme::i()->getTemplate( 'loadouts', 'gdloadout', 'front' )->view(
			$loadout, $items, $ownerName, $isOwner, $editUrl, $compliance,
			$hasVoted, $hasFollowed, $initData, $forumTopicUrl,
			$canCopy, $copyUrl, $csrfKey,
			$canSuggest, $suggestions, $pendingSuggestionCount,
			$acceptSugUrl, $rejectSugUrl,
			$startDiscussionUrl
		);
	}

	protected function upvote(): void
	{
		Session::i()->csrfCheck();
		$member = Member::loggedIn();
		if ( !$member->member_id ) { Output::i()->json( [ 'error' => 'Login required' ], 403 ); return; }

		$loadoutId = (int) ( Request::i()->loadout_id ?? 0 );
		if ( !$loadoutId ) { Output::i()->json( [ 'error' => 'Invalid' ], 400 ); return; }

		$prefix  = Db::i()->prefix;
		$existed = false;
		try { Db::i()->select( 'id', 'gd_loadout_votes', [ 'loadout_id=? AND member_id=?', $loadoutId, (int) $member->member_id ] )->first(); $existed = true; } catch ( \Throwable ) {}

		if ( $existed )
		{
			Db::i()->delete( 'gd_loadout_votes', [ 'loadout_id=? AND member_id=?', $loadoutId, (int) $member->member_id ] );
			try { Db::i()->preparedQuery( "UPDATE `{$prefix}gd_loadouts` SET upvotes = GREATEST(0, upvotes - 1) WHERE id = ?", [ $loadoutId ] ); } catch ( \Throwable ) {}
		}
		else
		{
			try
			{
				Db::i()->insert( 'gd_loadout_votes', [ 'loadout_id' => $loadoutId, 'member_id' => (int) $member->member_id, 'voted_at' => time() ] );
				Db::i()->preparedQuery( "UPDATE `{$prefix}gd_loadouts` SET upvotes = upvotes + 1 WHERE id = ?", [ $loadoutId ] );
			}
			catch ( \Throwable ) {}

			try
			{
				$loadout = Db::i()->select( '*', 'gd_loadouts', [ 'id=?', $loadoutId ] )->first();
				$ownerId = (int) ( $loadout['member_id'] ?? 0 );
				if ( $ownerId && $ownerId !== (int) $member->member_id )
				{
					$owner = Member::load( $ownerId );
					$notification = new \IPS\Notification(
						\IPS\Application::load( 'gdloadout' ), 'loadout_upvoted', $owner, [ $owner ],
						[ 'loadout_name' => $loadout['name'] ?? '', 'voter_name' => $member->name, 'username' => $owner->name ?? 'Unknown', 'slug' => $loadout['slug'] ?? '' ]
					);
					$notification->recipients->attach( $owner );
					$notification->send();
				}
			}
			catch ( \Throwable ) {}
		}

		$newCount = 0;
		try { $newCount = (int) Db::i()->select( 'upvotes', 'gd_loadouts', [ 'id=?', $loadoutId ] )->first(); } catch ( \Throwable ) {}
		Output::i()->json( [ 'ok' => true, 'voted' => !$existed, 'count' => $newCount ] );
	}

	protected function follow(): void
	{
		Session::i()->csrfCheck();
		$member = Member::loggedIn();
		if ( !$member->member_id ) { Output::i()->json( [ 'error' => 'Login required' ], 403 ); return; }

		$loadoutId = (int) ( Request::i()->loadout_id ?? 0 );
		if ( !$loadoutId ) { Output::i()->json( [ 'error' => 'Invalid' ], 400 ); return; }

		$prefix  = Db::i()->prefix;
		$existed = false;
		try { Db::i()->select( 'id', 'gd_loadout_follows', [ 'loadout_id=? AND member_id=?', $loadoutId, (int) $member->member_id ] )->first(); $existed = true; } catch ( \Throwable ) {}

		if ( $existed )
		{
			Db::i()->delete( 'gd_loadout_follows', [ 'loadout_id=? AND member_id=?', $loadoutId, (int) $member->member_id ] );
			try { Db::i()->preparedQuery( "UPDATE `{$prefix}gd_loadouts` SET follow_count = GREATEST(0, follow_count - 1) WHERE id = ?", [ $loadoutId ] ); } catch ( \Throwable ) {}
		}
		else
		{
			try
			{
				Db::i()->insert( 'gd_loadout_follows', [ 'loadout_id' => $loadoutId, 'member_id' => (int) $member->member_id, 'followed_at' => time() ] );
				Db::i()->preparedQuery( "UPDATE `{$prefix}gd_loadouts` SET follow_count = follow_count + 1 WHERE id = ?", [ $loadoutId ] );
			}
			catch ( \Throwable ) {}

			try
			{
				$loadout = Db::i()->select( '*', 'gd_loadouts', [ 'id=?', $loadoutId ] )->first();
				$ownerId = (int) ( $loadout['member_id'] ?? 0 );
				if ( $ownerId && $ownerId !== (int) $member->member_id )
				{
					$owner = Member::load( $ownerId );
					$notification = new \IPS\Notification(
						\IPS\Application::load( 'gdloadout' ), 'loadout_followed', $owner, [ $owner ],
						[ 'loadout_name' => $loadout['name'] ?? '', 'follower_name' => $member->name, 'username' => $owner->name ?? 'Unknown', 'slug' => $loadout['slug'] ?? '' ]
					);
					$notification->recipients->attach( $owner );
					$notification->send();
				}
			}
			catch ( \Throwable ) {}
		}

		$newCount = 0;
		try { $newCount = (int) Db::i()->select( 'follow_count', 'gd_loadouts', [ 'id=?', $loadoutId ] )->first(); } catch ( \Throwable ) {}
		Output::i()->json( [ 'ok' => true, 'followed' => !$existed, 'count' => $newCount ] );
	}

	protected function mine(): void
	{
		$member = Member::loggedIn();
		if ( !$member->member_id )
		{
			Output::i()->redirect( Url::internal( 'app=core&module=system&controller=login', 'front', 'login' ) );
			return;
		}

		$loadouts = [];
		try
		{
			foreach ( Db::i()->select( '*', 'gd_loadouts', [ 'member_id=?', (int) $member->member_id ], 'COALESCE(updated_at, created_at) DESC' ) as $row )
			{
				$this->enrichLoadout( $row );
				$row['edit_url']   = (string) Url::internal( 'app=gdloadout&module=loadouts&controller=builder&loadout_id=' . (int) $row['id'], 'front', 'gdloadout_builder_edit' );
				$row['delete_url'] = (string) Url::internal( 'app=gdloadout&module=loadouts&controller=hub&do=deleteLoadout&loadout_id=' . (int) $row['id'], 'front' );
				$loadouts[] = $row;
			}
		}
		catch ( \Throwable ) {}

		$builderUrl = (string) Url::internal( 'app=gdloadout&module=loadouts&controller=builder', 'front', 'gdloadout_builder' );
		$csrfKey    = Session::i()->csrfKey;

		Output::i()->cssFiles = array_merge( Output::i()->cssFiles, Theme::i()->css( 'loadouts.css', 'gdloadout', 'interface' ) );
		Output::i()->title    = Member::loggedIn()->language()->addToStack( 'gdloadout_my_loadouts_title' );
		Output::i()->output   = Theme::i()->getTemplate( 'loadouts', 'gdloadout', 'front' )->myLoadouts( $loadouts, $builderUrl, $csrfKey );
	}

	protected function deleteLoadout(): void
	{
		Session::i()->csrfCheck();
		$member = Member::loggedIn();
		if ( !$member->member_id )
		{
			Output::i()->redirect( Url::internal( 'app=core&module=system&controller=login', 'front', 'login' ) );
			return;
		}

		$loadoutId = (int) ( Request::i()->loadout_id ?? 0 );
		if ( !$loadoutId )
		{
			Output::i()->error( 'gdloadout_err_not_found', '2GDL/5', 404 );
			return;
		}

		$loadout = null;
		try { $loadout = Db::i()->select( '*', 'gd_loadouts', [ 'id=? AND member_id=?', $loadoutId, (int) $member->member_id ] )->first(); } catch ( \Throwable ) {}
		if ( !$loadout )
		{
			Output::i()->error( 'gdloadout_err_not_found', '2GDL/5', 404 );
			return;
		}

		try { Db::i()->delete( 'gd_loadout_items', [ 'loadout_id=?', $loadoutId ] ); } catch ( \Throwable ) {}
		try { Db::i()->delete( 'gd_loadout_votes', [ 'loadout_id=?', $loadoutId ] ); } catch ( \Throwable ) {}
		try { Db::i()->delete( 'gd_loadout_follows', [ 'loadout_id=?', $loadoutId ] ); } catch ( \Throwable ) {}
		try { Db::i()->delete( 'gd_loadout_comments', [ 'loadout_id=?', $loadoutId ] ); } catch ( \Throwable ) {}
		try { Db::i()->delete( 'gd_loadouts', [ 'id=?', $loadoutId ] ); } catch ( \Throwable ) {}

		Output::i()->redirect( Url::internal( 'app=gdloadout&module=loadouts&controller=hub&do=mine', 'front', 'gdloadout_mine' ) );
	}

	protected function addAllToWishlist(): void
	{
		Session::i()->csrfCheck();
		$member = Member::loggedIn();
		if ( !$member->member_id ) { Output::i()->json( [ 'error' => 'Login required' ], 403 ); return; }

		$loadoutId = (int) ( Request::i()->loadout_id ?? 0 );
		if ( !$loadoutId ) { Output::i()->json( [ 'error' => 'Invalid' ], 400 ); return; }

		$added = 0; $skipped = 0; $totalItems = 0;
		try
		{
			foreach ( Db::i()->select( 'upc', 'gd_loadout_items', [ 'loadout_id=?', $loadoutId ] ) as $upc )
			{
				if ( empty( $upc ) ) continue;
				$totalItems++;
				try
				{
					$exists = (int) Db::i()->select( 'COUNT(*)', 'gd_wishlist', [ 'member_id=? AND upc=?', (int) $member->member_id, $upc ] )->first();
					if ( $exists > 0 ) { $skipped++; continue; }
					Db::i()->insert( 'gd_wishlist', [ 'member_id' => (int) $member->member_id, 'upc' => $upc, 'created' => time() ] );
					$added++;
				}
				catch ( \Throwable ) { $skipped++; }
			}
		}
		catch ( \Throwable ) {}

		Output::i()->json( [ 'ok' => true, 'added' => $added, 'skipped' => $skipped, 'total_items' => $totalItems ] );
	}

	protected function alertAllItems(): void
	{
		Session::i()->csrfCheck();
		$member = Member::loggedIn();
		if ( !$member->member_id ) { Output::i()->json( [ 'error' => 'Login required' ], 403 ); return; }

		$loadoutId = (int) ( Request::i()->loadout_id ?? 0 );
		if ( !$loadoutId ) { Output::i()->json( [ 'error' => 'Invalid' ], 400 ); return; }

		$set = 0; $totalItems = 0; $noPrice = 0;
		try
		{
			foreach ( Db::i()->select( 'upc', 'gd_loadout_items', [ 'loadout_id=?', $loadoutId ] ) as $upc )
			{
				if ( empty( $upc ) ) continue;
				$totalItems++;
				try
				{
					$bestPrice = NULL;
					try
					{
						$p = Db::i()->select( 'MIN(dealer_price) AS best_price', 'gd_dealer_listings', [ 'upc=? AND listing_status=?', $upc, 'active' ] )->first();
						if ( $p['best_price'] !== NULL && (float) $p['best_price'] > 0 ) $bestPrice = (float) $p['best_price'];
					}
					catch ( \Throwable ) {}
					if ( $bestPrice === NULL ) { $noPrice++; continue; }
					Db::i()->replace( 'gd_price_alerts', [ 'member_id' => (int) $member->member_id, 'upc' => $upc, 'threshold' => $bestPrice, 'created' => time() ] );
					$set++;
				}
				catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable ) {}

		Output::i()->json( [ 'ok' => true, 'set' => $set, 'total_items' => $totalItems, 'no_price' => $noPrice ] );
	}

	protected function shareToForum(): void
	{
		Session::i()->csrfCheck();
		$member = Member::loggedIn();
		if ( !$member->member_id ) { Output::i()->json( [ 'error' => 'Login required' ], 403 ); return; }

		$loadoutId = (int) ( Request::i()->loadout_id ?? 0 );
		if ( !$loadoutId ) { Output::i()->json( [ 'error' => 'Invalid' ], 400 ); return; }

		$loadout = NULL;
		try { $loadout = Db::i()->select( '*', 'gd_loadouts', [ 'id=?', $loadoutId ] )->first(); } catch ( \Throwable ) {}
		if ( !$loadout ) { Output::i()->json( [ 'error' => 'Not found' ], 404 ); return; }

		try
		{
			$topicId = self::ensureForumTopic( $loadout );
			if ( $topicId )
			{
				$topic = \IPS\forums\Topic::load( $topicId );
				Output::i()->json( [ 'ok' => true, 'topic_url' => (string) $topic->url() ] );
			}
			else
			{
				Output::i()->json( [ 'error' => 'Could not create forum topic' ], 400 );
			}
		}
		catch ( \Throwable )
		{
			Output::i()->json( [ 'error' => 'Failed to create forum topic' ], 500 );
		}
	}

	protected function startDiscussion(): void
	{
		Session::i()->csrfCheck();
		$member = Member::loggedIn();
		if ( !$member->member_id )
		{
			Output::i()->redirect( Url::internal( 'app=core&module=system&controller=login', 'front', 'login' ) );
			return;
		}

		$loadoutId = (int) ( Request::i()->loadout_id ?? 0 );
		if ( !$loadoutId )
		{
			Output::i()->error( 'gdloadout_err_not_found', '2GDL/8', 404 );
			return;
		}

		$loadout = null;
		try { $loadout = Db::i()->select( '*', 'gd_loadouts', [ 'id=? AND member_id=?', $loadoutId, (int) $member->member_id ] )->first(); } catch ( \Throwable ) {}
		if ( !$loadout )
		{
			Output::i()->error( 'gdloadout_err_not_found', '2GDL/8', 404 );
			return;
		}

		$topicId = self::ensureForumTopic( $loadout );
		if ( !$topicId )
		{
			Output::i()->error( 'gdloadout_discussion_no_forum', '2GDL/9', 400 );
			return;
		}

		$ownerName = $member->name ?? 'Unknown';
		Output::i()->redirect( Url::internal(
			'app=gdloadout&module=loadouts&controller=hub&do=view&username=' . urlencode( $ownerName ) . '&slug=' . urlencode( $loadout['slug'] ?? '' ),
			'front', 'gdloadout_view'
		) );
	}

	protected function copy(): void
	{
		Session::i()->csrfCheck();
		$member = Member::loggedIn();
		if ( !$member->member_id )
		{
			Output::i()->redirect( Url::internal( 'app=core&module=system&controller=login', 'front', 'login' ) );
			return;
		}

		$loadoutId = (int) ( Request::i()->loadout_id ?? 0 );
		if ( !$loadoutId )
		{
			Output::i()->error( 'gdloadout_err_not_found', '2GDL/3', 404 );
			return;
		}

		$source = null;
		try { $source = Db::i()->select( '*', 'gd_loadouts', [ 'id=?', $loadoutId ] )->first(); } catch ( \Throwable ) {}
		if ( !$source )
		{
			Output::i()->error( 'gdloadout_err_not_found', '2GDL/3', 404 );
			return;
		}

		if ( $source['visibility'] === 'private' && (int) $source['member_id'] !== (int) $member->member_id )
		{
			Output::i()->error( 'gdloadout_copy_private', '2GDL/4', 403 );
			return;
		}

		$newName  = 'Copy of ' . $source['name'];
		$slug     = \IPS\gdloadout\Loadout\Loadout::slugify( $newName );
		$slugBase = $slug;
		$counter  = 1;
		while ( true )
		{
			try
			{
				Db::i()->select( 'id', 'gd_loadouts', [ 'member_id=? AND slug=?', (int) $member->member_id, $slug ] )->first();
				$slug = $slugBase . '-' . ( ++$counter );
			}
			catch ( \Throwable ) { break; }
		}

		$newId = Db::i()->insert( 'gd_loadouts', [
			'member_id'         => (int) $member->member_id,
			'name'              => $newName,
			'slug'              => $slug,
			'description'       => $source['description'] ?? '',
			'build_mode'        => $source['build_mode'] ?? 'complete_firearm',
			'platform'          => $source['platform'] ?? '',
			'use_case'          => $source['use_case'] ?? '',
			'visibility'        => 'unlisted',
			'total_items'       => (int) ( $source['total_items'] ?? 0 ),
			'total_min_price'   => (float) ( $source['total_min_price'] ?? 0 ),
			'upvotes'           => 0,
			'view_count'        => 0,
			'follow_count'      => 0,
			'comment_count'     => 0,
			'featured'          => 0,
			'featured_position' => 0,
			'created_at'        => time(),
			'updated_at'        => null,
		] );

		try
		{
			foreach ( Db::i()->select( '*', 'gd_loadout_items', [ 'loadout_id=?', $loadoutId ], 'sort_order ASC' ) as $item )
			{
				try
				{
					Db::i()->insert( 'gd_loadout_items', [
						'loadout_id'   => (int) $newId,
						'upc'          => $item['upc'] ?? '',
						'slot_type'    => $item['slot_type'] ?? 'extra',
						'custom_label' => $item['custom_label'] ?? null,
						'sort_order'   => (int) ( $item['sort_order'] ?? 0 ),
						'notes'        => $item['notes'] ?? null,
					] );
				}
				catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable ) {}

		Output::i()->redirect( Url::internal( 'app=gdloadout&module=loadouts&controller=builder&loadout_id=' . (int) $newId, 'front', 'gdloadout_builder_edit' ) );
	}

	protected function suggest(): void
	{
		Session::i()->csrfCheck();
		$member = Member::loggedIn();
		if ( !$member->member_id ) { Output::i()->json( [ 'error' => 'Login required' ], 403 ); return; }

		$loadoutId = (int) ( Request::i()->loadout_id ?? 0 );
		if ( !$loadoutId ) { Output::i()->json( [ 'error' => 'Invalid' ], 400 ); return; }

		$loadout = null;
		try { $loadout = Db::i()->select( '*', 'gd_loadouts', [ 'id=?', $loadoutId ] )->first(); } catch ( \Throwable ) {}
		if ( !$loadout ) { Output::i()->json( [ 'error' => 'Not found' ], 404 ); return; }

		if ( !\IPS\gdloadout\Loadout\Loadout::canSuggest( $member, $loadout ) )
		{
			Output::i()->json( [ 'error' => 'Not eligible to suggest' ], 403 );
			return;
		}

		$slotType     = trim( (string) ( Request::i()->slot_type ?? '' ) );
		$suggestedUpc = trim( (string) ( Request::i()->suggested_upc ?? '' ) );
		$message      = trim( (string) ( Request::i()->message ?? '' ) );

		if ( $slotType === '' || $suggestedUpc === '' )
		{
			Output::i()->json( [ 'error' => 'Slot and product are required' ], 400 );
			return;
		}

		$completeSlots  = [ 'base_firearm', 'optic', 'weapon_light', 'laser', 'suppressor', 'sling', 'rail_mount', 'scope_rings' ];
		$componentSlots = [ 'lower_receiver', 'upper_receiver', 'barrel', 'handguard', 'muzzle', 'bcg', 'buffer', 'trigger', 'stock', 'grip', 'optic', 'scope_rings', 'rail_mount', 'weapon_light', 'laser', 'suppressor', 'sling' ];
		$extraSlots     = [ 'magazine', 'holster', 'ear_eye_pro', 'cleaning', 'bipod' ];
		$validForMode   = array_merge(
			( ( $loadout['build_mode'] ?? 'complete_firearm' ) === 'component_build' ) ? $componentSlots : $completeSlots,
			$extraSlots
		);
		if ( !\in_array( $slotType, $validForMode, true ) )
		{
			Output::i()->json( [ 'error' => 'Invalid slot for this build mode' ], 400 );
			return;
		}

		$catalogExists = false;
		try { Db::i()->select( 'upc', 'gd_catalog', [ 'upc=? AND record_status=?', $suggestedUpc, 'active' ] )->first(); $catalogExists = true; } catch ( \Throwable ) {}
		if ( !$catalogExists )
		{
			Output::i()->json( [ 'error' => 'Product not found in catalog' ], 400 );
			return;
		}

		if ( mb_strlen( $message ) > 500 ) $message = mb_substr( $message, 0, 500 );

		Db::i()->insert( 'gd_loadout_suggestions', [
			'loadout_id'    => $loadoutId,
			'from_member'   => (int) $member->member_id,
			'slot_type'     => $slotType,
			'suggested_upc' => $suggestedUpc,
			'message'       => $message ?: null,
			'status'        => 'pending',
			'created_at'    => time(),
			'resolved_at'   => null,
		] );

		try
		{
			$ownerId = (int) ( $loadout['member_id'] ?? 0 );
			if ( $ownerId && $ownerId !== (int) $member->member_id )
			{
				$owner = Member::load( $ownerId );
				$notification = new \IPS\Notification(
					\IPS\Application::load( 'gdloadout' ), 'suggestion_received', $owner, [ $owner ],
					[ 'loadout_name' => $loadout['name'] ?? '', 'suggester_name' => $member->name, 'username' => $owner->name ?? '', 'slug' => $loadout['slug'] ?? '' ]
				);
				$notification->recipients->attach( $owner );
				$notification->send();
			}
		}
		catch ( \Throwable ) {}

		Output::i()->json( [ 'ok' => true ] );
	}

	protected function acceptSuggestion(): void
	{
		Session::i()->csrfCheck();
		$member = Member::loggedIn();
		if ( !$member->member_id ) { Output::i()->json( [ 'error' => 'Login required' ], 403 ); return; }

		$sugId = (int) ( Request::i()->suggestion_id ?? 0 );
		if ( !$sugId ) { Output::i()->json( [ 'error' => 'Invalid' ], 400 ); return; }

		$sug = null;
		try { $sug = Db::i()->select( '*', 'gd_loadout_suggestions', [ 'id=? AND status=?', $sugId, 'pending' ] )->first(); } catch ( \Throwable ) {}
		if ( !$sug ) { Output::i()->json( [ 'error' => 'Suggestion not found' ], 404 ); return; }

		$loadout = null;
		try { $loadout = Db::i()->select( '*', 'gd_loadouts', [ 'id=? AND member_id=?', (int) $sug['loadout_id'], (int) $member->member_id ] )->first(); } catch ( \Throwable ) {}
		if ( !$loadout ) { Output::i()->json( [ 'error' => 'Not your loadout' ], 403 ); return; }

		Db::i()->update( 'gd_loadout_suggestions', [ 'status' => 'accepted', 'resolved_at' => time() ], [ 'id=?', $sugId ] );

		try
		{
			$suggester = Member::load( (int) $sug['from_member'] );
			if ( $suggester->member_id )
			{
				$ownerName = $member->name ?? 'Unknown';
				$notification = new \IPS\Notification(
					\IPS\Application::load( 'gdloadout' ), 'suggestion_resolved', $suggester, [ $suggester ],
					[ 'loadout_name' => $loadout['name'] ?? '', 'action' => 'accepted', 'username' => $ownerName, 'slug' => $loadout['slug'] ?? '' ]
				);
				$notification->recipients->attach( $suggester );
				$notification->send();
			}
		}
		catch ( \Throwable ) {}

		$builderUrl = (string) Url::internal(
			'app=gdloadout&module=loadouts&controller=builder&loadout_id=' . (int) $loadout['id']
			. '&apply_slot=' . urlencode( $sug['slot_type'] )
			. '&apply_upc=' . urlencode( $sug['suggested_upc'] ),
			'front', 'gdloadout_builder_edit'
		);

		Output::i()->redirect( Url::external( $builderUrl ) );
	}

	protected function rejectSuggestion(): void
	{
		Session::i()->csrfCheck();
		$member = Member::loggedIn();
		if ( !$member->member_id ) { Output::i()->json( [ 'error' => 'Login required' ], 403 ); return; }

		$sugId = (int) ( Request::i()->suggestion_id ?? 0 );
		if ( !$sugId ) { Output::i()->json( [ 'error' => 'Invalid' ], 400 ); return; }

		$sug = null;
		try { $sug = Db::i()->select( '*', 'gd_loadout_suggestions', [ 'id=? AND status=?', $sugId, 'pending' ] )->first(); } catch ( \Throwable ) {}
		if ( !$sug ) { Output::i()->json( [ 'error' => 'Not found' ], 404 ); return; }

		$loadout = null;
		try { $loadout = Db::i()->select( '*', 'gd_loadouts', [ 'id=? AND member_id=?', (int) $sug['loadout_id'], (int) $member->member_id ] )->first(); } catch ( \Throwable ) {}
		if ( !$loadout ) { Output::i()->json( [ 'error' => 'Not your loadout' ], 403 ); return; }

		Db::i()->update( 'gd_loadout_suggestions', [ 'status' => 'rejected', 'resolved_at' => time() ], [ 'id=?', $sugId ] );

		try
		{
			$suggester = Member::load( (int) $sug['from_member'] );
			if ( $suggester->member_id )
			{
				$ownerName = $member->name ?? 'Unknown';
				$notification = new \IPS\Notification(
					\IPS\Application::load( 'gdloadout' ), 'suggestion_resolved', $suggester, [ $suggester ],
					[ 'loadout_name' => $loadout['name'] ?? '', 'action' => 'rejected', 'username' => $ownerName, 'slug' => $loadout['slug'] ?? '' ]
				);
				$notification->recipients->attach( $suggester );
				$notification->send();
			}
		}
		catch ( \Throwable ) {}

		$ownerName = $member->name ?? 'Unknown';
		Output::i()->redirect( Url::internal(
			'app=gdloadout&module=loadouts&controller=hub&do=view&username=' . urlencode( $ownerName ) . '&slug=' . urlencode( $loadout['slug'] ?? '' ),
			'front', 'gdloadout_view'
		) );
	}

	protected function withdrawSuggestion(): void
	{
		Session::i()->csrfCheck();
		$member = Member::loggedIn();
		if ( !$member->member_id ) { Output::i()->json( [ 'error' => 'Login required' ], 403 ); return; }

		$sugId = (int) ( Request::i()->suggestion_id ?? 0 );
		if ( !$sugId ) { Output::i()->json( [ 'error' => 'Invalid' ], 400 ); return; }

		$sug = null;
		try { $sug = Db::i()->select( '*', 'gd_loadout_suggestions', [ 'id=? AND from_member=? AND status=?', $sugId, (int) $member->member_id, 'pending' ] )->first(); } catch ( \Throwable ) {}
		if ( !$sug ) { Output::i()->json( [ 'error' => 'Not found' ], 404 ); return; }

		Db::i()->update( 'gd_loadout_suggestions', [ 'status' => 'withdrawn', 'resolved_at' => time() ], [ 'id=?', $sugId ] );
		Output::i()->json( [ 'ok' => true ] );
	}

	protected function embed(): void
	{
		$loadoutId = (int) ( Request::i()->id ?? 0 );
		if ( !$loadoutId )
		{
			Output::i()->json( [ 'error' => 'Not found' ], 404 );
			return;
		}

		$loadout = NULL;
		try { $loadout = Db::i()->select( '*', 'gd_loadouts', [ 'id=? AND visibility=?', $loadoutId, 'public' ] )->first(); } catch ( \Throwable ) {}
		if ( !$loadout )
		{
			Output::i()->json( [ 'error' => 'Not found' ], 404 );
			return;
		}

		$ownerName = 'Unknown';
		try { $ownerName = Member::load( (int) $loadout['member_id'] )->name; } catch ( \Throwable ) {}

		$viewUrl = (string) Url::internal(
			'app=gdloadout&module=loadouts&controller=hub&do=view&username=' . urlencode( $ownerName ) . '&slug=' . urlencode( $loadout['slug'] ),
			'front', 'gdloadout_view'
		);

		Output::i()->sendOutput(
			Theme::i()->getTemplate( 'loadouts', 'gdloadout', 'front' )->loadoutEmbed( $loadout, $ownerName, $viewUrl ),
			200,
			'text/html'
		);
	}
}

class hub extends _hub {}
