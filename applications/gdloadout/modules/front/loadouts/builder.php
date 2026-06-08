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

class _builder extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		if ( !Member::loggedIn()->member_id )
		{
			Output::i()->error( 'no_module_permission', '2GL02/1', 403 );
			return;
		}
		parent::execute();
	}

	protected function isVip( Member $member ): bool
	{
		try
		{
			$vipGroupIds = [];
			foreach ( \IPS\Member\Group::groups( TRUE, FALSE ) as $g )
			{
				if ( mb_stripos( (string) $g->name, 'VIP' ) !== FALSE )
				{
					$vipGroupIds[] = (int) $g->g_id;
				}
			}
			if ( \in_array( (int) $member->member_group_id, $vipGroupIds, true ) ) return true;
			$secondary = $member->mgroup_others ?? '';
			if ( $secondary )
			{
				foreach ( explode( ',', $secondary ) as $sg )
				{
					if ( \in_array( (int) trim( $sg ), $vipGroupIds, true ) ) return true;
				}
			}
		}
		catch ( \Throwable ) {}
		return false;
	}

	protected function manage(): void
	{
		$member = Member::loggedIn();
		$editId = (int) ( Request::i()->id ?? 0 );
		if ( !$editId )
		{
			$editId = (int) ( Request::i()->loadout_id ?? 0 );
		}
		$loadout = NULL;
		$items   = [];

		if ( $editId )
		{
			try
			{
				$loadout = Db::i()->select( '*', 'gd_loadouts', [ 'id=? AND member_id=?', $editId, (int) $member->member_id ] )->first();
				foreach ( Db::i()->select( '*', 'gd_loadout_items', [ 'loadout_id=?', $editId ], 'sort_order ASC' ) as $item )
				{
					if ( !empty( $item['upc'] ) )
					{
						try
						{
							$cat = Db::i()->select( 'title, brand', 'gd_catalog', [ 'upc=?', $item['upc'] ] )->first();
							$item['title'] = $cat['title'] ?? '';
							$item['brand'] = $cat['brand'] ?? '';
						}
						catch ( \UnderflowException ) { $item['title'] = ''; }
						try
						{
							$item['price_snapshot'] = (float) Db::i()->select( 'MIN(dealer_price)', 'gd_dealer_listings', [ 'upc=? AND listing_status=?', $item['upc'], 'active' ] )->first();
						}
						catch ( \Throwable ) { $item['price_snapshot'] = null; }
					}
					$items[] = $item;
				}
			}
			catch ( \Throwable )
			{
				Output::i()->error( 'gdloadout_err_not_found', '2GL02/2', 404 );
				return;
			}
		}
		else
		{
			if ( !\IPS\gdloadout\Loadout\Limits::canCreateLoadout( $member ) )
			{
				Output::i()->error( 'gdloadout_err_limit_loadouts', '2GL02/3', 403 );
				return;
			}
		}

		$limits  = \IPS\gdloadout\Loadout\Limits::forMember( $member );
		$isVip   = $this->isVip( $member );

		$saveUrl   = (string) Url::internal( 'app=gdloadout&module=loadouts&controller=builder&do=save', 'front', 'gdloadout_builder' );
		$deleteUrl = (string) Url::internal( 'app=gdloadout&module=loadouts&controller=builder&do=delete', 'front', 'gdloadout_builder' );
		$searchUrl = (string) Url::internal( 'app=gdloadout&module=loadouts&controller=builder&do=search', 'front', 'gdloadout_builder' );
		$csrfKey   = Session::i()->csrfKey;

		$coreSlots = \IPS\gdloadout\Loadout\Slots::CORE_SLOTS;
		$extraLib  = array_values( \IPS\gdloadout\Loadout\Slots::EXTRA_LIBRARY );

		$initData = json_encode( [
			'loadout'     => $loadout,
			'items'       => $items,
			'coreSlots'   => $coreSlots,
			'extraLib'    => $extraLib,
			'limits'      => $limits,
			'isVip'       => $isVip,
			'saveUrl'     => $saveUrl,
			'deleteUrl'   => $deleteUrl,
			'searchUrl'   => $searchUrl,
			'csrfKey'     => $csrfKey,
		], JSON_HEX_TAG | JSON_HEX_AMP );

		Output::i()->cssFiles = array_merge( Output::i()->cssFiles, Theme::i()->css( 'loadouts.css', 'gdloadout', 'interface' ) );
		Output::i()->jsFiles  = array_merge( Output::i()->jsFiles, Output::i()->js( 'builder.js', 'gdloadout', 'interface' ) );
		Output::i()->title    = Member::loggedIn()->language()->addToStack( 'gdloadout_builder_title' );
		Output::i()->output   = Theme::i()->getTemplate( 'loadouts', 'gdloadout', 'front' )->builder( $initData );
	}

	protected function save(): void
	{
		Session::i()->csrfCheck();

		$member = Member::loggedIn();
		$editId = (int) ( Request::i()->loadout_id ?? 0 );
		$name   = trim( Request::i()->loadout_name ?? '' );

		if ( $name === '' )
		{
			Output::i()->json( [ 'error' => Member::loggedIn()->language()->addToStack( 'gdloadout_err_name_required' ) ], 400 );
			return;
		}

		$slug        = \IPS\gdloadout\Loadout\Loadout::slugify( $name );
		$description = trim( Request::i()->loadout_description ?? '' );
		$useCase     = trim( Request::i()->loadout_use_case ?? '' );
		$visibility  = Request::i()->loadout_visibility ?? 'unlisted';

		if ( !\in_array( $visibility, [ 'public', 'unlisted', 'private' ], true ) )
		{
			$visibility = 'unlisted';
		}

		$isVip = $this->isVip( $member );
		if ( $visibility === 'private' && !$isVip )
		{
			$visibility = 'unlisted';
		}

		$slotsJson = Request::i()->loadout_slots ?? '[]';
		$slots     = json_decode( $slotsJson, true );
		if ( !\is_array( $slots ) ) $slots = [];

		$limits = \IPS\gdloadout\Loadout\Limits::forMember( $member );
		if ( $limits['max_slots'] > 0 && \count( $slots ) > $limits['max_slots'] )
		{
			Output::i()->json( [ 'error' => Member::loggedIn()->language()->addToStack( 'gdloadout_err_limit_slots' ) ], 400 );
			return;
		}

		if ( $editId )
		{
			try
			{
				$existing = Db::i()->select( '*', 'gd_loadouts', [ 'id=? AND member_id=?', $editId, (int) $member->member_id ] )->first();
			}
			catch ( \Throwable )
			{
				Output::i()->json( [ 'error' => Member::loggedIn()->language()->addToStack( 'gdloadout_err_not_found' ) ], 404 );
				return;
			}

			$slotsWithUpc = 0;
			foreach ( $slots as $s )
			{
				if ( !empty( $s['upc'] ) ) $slotsWithUpc++;
			}
			if ( $slotsWithUpc === 0 )
			{
				Output::i()->json( [ 'error' => 'No items to save — cannot overwrite existing build with empty loadout.' ], 400 );
				return;
			}

			$uniqueSlug = $slug;
			$counter = 1;
			while ( true )
			{
				try
				{
					Db::i()->select( 'id', 'gd_loadouts', [ 'member_id=? AND slug=? AND id!=?', (int) $member->member_id, $uniqueSlug, $editId ] )->first();
					$counter++;
					$uniqueSlug = $slug . '-' . $counter;
				}
				catch ( \Throwable ) { break; }
			}

			Db::i()->update( 'gd_loadouts', [
				'name' => $name, 'slug' => $uniqueSlug,
				'description' => $description ?: NULL, 'use_case' => $useCase ?: NULL,
				'visibility' => $visibility, 'updated_at' => time(),
			], [ 'id=?', $editId ] );

			$loadoutId = $editId;

			if ( \in_array( $visibility, [ 'public', 'unlisted' ], true ) )
			{
				try
				{
					$followers = [];
					foreach ( Db::i()->select( 'member_id', 'gd_loadout_follows', [ 'loadout_id=?', $editId ] ) as $fid )
					{
						if ( (int) $fid !== (int) $member->member_id ) $followers[] = (int) $fid;
					}
					if ( $followers )
					{
						$notification = new \IPS\Notification(
							\IPS\Application::load( 'gdloadout' ), 'loadout_updated', $member, [],
							[ 'loadout_name' => $name, 'author_name' => $member->name, 'username' => $member->name, 'slug' => $uniqueSlug ]
						);
						foreach ( $followers as $fMemberId )
						{
							try { $notification->recipients->attach( Member::load( $fMemberId ) ); } catch ( \Throwable ) {}
						}
						$notification->send();
					}
				}
				catch ( \Throwable ) {}
			}

			Db::i()->delete( 'gd_loadout_items', [ 'loadout_id=?', $loadoutId ] );
		}
		else
		{
			if ( !\IPS\gdloadout\Loadout\Limits::canCreateLoadout( $member ) )
			{
				Output::i()->json( [ 'error' => Member::loggedIn()->language()->addToStack( 'gdloadout_err_limit_loadouts' ) ], 403 );
				return;
			}

			$uniqueSlug = $slug;
			$counter = 1;
			while ( true )
			{
				try
				{
					Db::i()->select( 'id', 'gd_loadouts', [ 'member_id=? AND slug=?', (int) $member->member_id, $uniqueSlug ] )->first();
					$counter++;
					$uniqueSlug = $slug . '-' . $counter;
				}
				catch ( \Throwable ) { break; }
			}

			$loadoutId = Db::i()->insert( 'gd_loadouts', [
				'member_id' => (int) $member->member_id, 'name' => $name, 'slug' => $uniqueSlug,
				'description' => $description ?: NULL, 'use_case' => $useCase ?: NULL,
				'visibility' => $visibility, 'created_at' => time(),
			] );
		}

		$validSlotTypes = [ 'base_firearm', 'optic', 'weapon_light', 'laser', 'suppressor', 'foregrip', 'rail_mount', 'trigger', 'stock', 'sling', 'holster', 'ammo', 'cleaning', 'extra' ];
		$totalCost = 0; $totalItems = 0; $order = 0;

		foreach ( $slots as $slot )
		{
			if ( empty( $slot['upc'] ) ) continue;
			$slotType = $slot['slot_type'] ?? 'extra';
			if ( !\in_array( $slotType, $validSlotTypes, true ) ) $slotType = 'extra';

			$notes = NULL;
			if ( $isVip && !empty( $slot['notes'] ) ) $notes = substr( trim( $slot['notes'] ), 0, 300 );

			Db::i()->insert( 'gd_loadout_items', [
				'loadout_id' => (int) $loadoutId, 'upc' => substr( trim( $slot['upc'] ), 0, 20 ),
				'slot_type' => $slotType, 'custom_label' => !empty( $slot['custom_label'] ) ? substr( trim( $slot['custom_label'] ), 0, 100 ) : NULL,
				'sort_order' => $order, 'notes' => $notes, 'added_at' => time(),
			] );
			$totalItems++; $order++;
			if ( isset( $slot['price'] ) && (float) $slot['price'] > 0 ) $totalCost += (float) $slot['price'];
		}

		Db::i()->update( 'gd_loadouts', [
			'total_items' => $totalItems,
			'total_min_price' => $totalCost > 0 ? round( $totalCost, 2 ) : NULL,
		], [ 'id=?', (int) $loadoutId ] );

		$ownerName = $member->name ?? 'user';
		$loadoutSlug = $uniqueSlug ?? $slug;
		$viewUrl = (string) Url::internal(
			'app=gdloadout&module=loadouts&controller=hub&do=view&username=' . urlencode( $ownerName ) . '&slug=' . urlencode( $loadoutSlug ),
			'front', 'gdloadout_view'
		);

		Output::i()->json( [ 'ok' => true, 'loadout_id' => (int) $loadoutId, 'redirect' => $viewUrl ] );
	}

	protected function delete(): void
	{
		Session::i()->csrfCheck();
		$member = Member::loggedIn();
		$id = (int) ( Request::i()->loadout_id ?? 0 );

		try { Db::i()->select( 'id', 'gd_loadouts', [ 'id=? AND member_id=?', $id, (int) $member->member_id ] )->first(); }
		catch ( \Throwable ) { Output::i()->json( [ 'error' => Member::loggedIn()->language()->addToStack( 'gdloadout_err_not_found' ) ], 404 ); return; }

		Db::i()->delete( 'gd_loadout_items', [ 'loadout_id=?', $id ] );
		Db::i()->delete( 'gd_loadout_votes', [ 'loadout_id=?', $id ] );
		Db::i()->delete( 'gd_loadout_follows', [ 'loadout_id=?', $id ] );
		Db::i()->delete( 'gd_loadout_forum_posts', [ 'loadout_id=?', $id ] );
		Db::i()->delete( 'gd_loadouts', [ 'id=?', $id ] );
		Output::i()->json( [ 'ok' => true ] );
	}

	protected function search(): void
	{
		$query = trim( (string) ( Request::i()->q ?? '' ) );
		$page  = max( 1, (int) ( Request::i()->page ?? 1 ) );

		if ( mb_strlen( $query ) < 2 )
		{
			Output::i()->json( [ 'total' => 0, 'results' => [] ] );
			return;
		}

		try
		{
			$matchedUpcs = [];

			if ( preg_match( '/^[0-9]{8,14}$/', $query ) )
			{
				try
				{
					$u = (string) Db::i()->select( 'upc', 'gd_catalog', [ 'upc=? AND record_status=?', $query, 'active' ] )->first();
					if ( $u !== '' ) $matchedUpcs[] = $u;
				}
				catch ( \UnderflowException ) {}
			}

			if ( !$matchedUpcs )
			{
				try
				{
					foreach ( Db::i()->select( 'upc', 'gd_catalog', [ 'mpn=? AND record_status=?', $query, 'active' ], 'id ASC', 24 ) as $u )
					{
						$matchedUpcs[] = (string) $u;
					}
				}
				catch ( \Throwable ) {}
			}

			if ( $matchedUpcs )
			{
				$out = [];
				foreach ( $matchedUpcs as $u )
				{
					try
					{
						$row = Db::i()->select( '*', 'gd_catalog', [ 'upc=?', $u ] )->first();
						$price = NULL; $dealers = 0;
						try
						{
							$p = Db::i()->select( 'MIN(dealer_price) AS best_price, COUNT(DISTINCT dealer_id) AS dealer_count', 'gd_dealer_listings', [ 'upc=? AND listing_status=?', $u, 'active' ] )->first();
							$price   = ( $p['best_price'] !== NULL && (float) $p['best_price'] > 0 ) ? (float) $p['best_price'] : NULL;
							$dealers = (int) $p['dealer_count'];
						}
						catch ( \Throwable ) {}
						$out[] = [
							'upc' => $u, 'title' => $row['title'] ?? '', 'brand' => $row['brand'] ?? '',
							'best_price' => $price, 'dealer_count' => $dealers, 'in_stock' => $dealers > 0,
							'category' => $row['category'] ?? '', 'caliber' => $row['caliber'] ?? '',
						];
					}
					catch ( \Throwable ) {}
				}
				Output::i()->json( [ 'total' => \count( $out ), 'results' => $out ] );
				return;
			}

			$searcher = new \IPS\gdsearch\Search\Searcher();
			$result   = $searcher->search( $query, [], 'relevance', $page, 24 );

			$out = [];
			foreach ( ( $result['results'] ?? [] ) as $r )
			{
				$out[] = [
					'upc' => $r['upc'] ?? '', 'title' => $r['title'] ?? '', 'brand' => $r['brand'] ?? '',
					'best_price' => $r['best_price'] ?? null, 'dealer_count' => $r['dealer_count'] ?? 0,
					'in_stock' => $r['in_stock'] ?? false, 'category' => $r['category'] ?? '', 'caliber' => $r['caliber'] ?? '',
				];
			}
			Output::i()->json( [ 'total' => $result['total'] ?? 0, 'results' => $out ] );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( $e, 'gdloadout_search' ); } catch ( \Throwable ) {}
			Output::i()->json( [ 'total' => 0, 'results' => [] ] );
		}
	}
}

class builder extends _builder {}
