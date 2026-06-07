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

	protected function manage(): void
	{
		$member = Member::loggedIn();
		$editId = (int) ( Request::i()->id ?? 0 );
		$loadout = NULL;
		$items   = [];

		if ( $editId )
		{
			try
			{
				$loadout = Db::i()->select( '*', 'gd_loadouts', [ 'id=? AND member_id=?', $editId, (int) $member->member_id ] )->first();

				foreach ( Db::i()->select( '*', 'gd_loadout_items', [ 'loadout_id=?', $editId ], 'sort_order ASC' ) as $item )
				{
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

		$limits = \IPS\gdloadout\Loadout\Limits::forMember( $member );

		$saveUrl   = (string) Url::internal( 'app=gdloadout&module=loadouts&controller=builder&do=save', 'front', 'gdloadout_builder' );
		$deleteUrl = (string) Url::internal( 'app=gdloadout&module=loadouts&controller=builder&do=delete', 'front', 'gdloadout_builder' );
		$searchUrl = (string) Url::internal( 'app=gdloadout&module=loadouts&controller=builder&do=search', 'front', 'gdloadout_builder' );
		$csrfKey   = Session::i()->csrfKey;

		$coreSlots = \IPS\gdloadout\Loadout\Slots::CORE_SLOTS;
		$extraLib  = \IPS\gdloadout\Loadout\Slots::EXTRA_LIBRARY;

		Output::i()->jsFiles  = array_merge( Output::i()->jsFiles, Output::i()->js( 'builder.js', 'gdloadout', 'interface' ) );
		Output::i()->title    = Member::loggedIn()->language()->addToStack( 'gdloadout_builder_title' );
		Output::i()->output   = Theme::i()->getTemplate( 'loadouts', 'gdloadout', 'front' )->builder(
			$loadout,
			$items,
			$coreSlots,
			$extraLib,
			$limits,
			$saveUrl,
			$deleteUrl,
			$searchUrl,
			$csrfKey
		);
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

		$slotsJson = Request::i()->loadout_slots ?? '[]';
		$slots     = json_decode( $slotsJson, true );
		if ( !\is_array( $slots ) )
		{
			$slots = [];
		}

		$limits = \IPS\gdloadout\Loadout\Limits::forMember( $member );

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
				catch ( \Throwable )
				{
					break;
				}
			}

			Db::i()->update( 'gd_loadouts', [
				'name'        => $name,
				'slug'        => $uniqueSlug,
				'description' => $description ?: NULL,
				'use_case'    => $useCase ?: NULL,
				'visibility'  => $visibility,
				'updated_at'  => date( 'Y-m-d H:i:s' ),
			], [ 'id=?', $editId ] );

			$loadoutId = $editId;

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
				catch ( \Throwable )
				{
					break;
				}
			}

			$loadoutId = Db::i()->insert( 'gd_loadouts', [
				'member_id'   => (int) $member->member_id,
				'name'        => $name,
				'slug'        => $uniqueSlug,
				'description' => $description ?: NULL,
				'use_case'    => $useCase ?: NULL,
				'visibility'  => $visibility,
				'created_at'  => date( 'Y-m-d H:i:s' ),
			] );
		}

		$validSlotTypes = [ 'base_firearm', 'optic', 'weapon_light', 'laser', 'suppressor', 'foregrip', 'sling', 'holster', 'ammo', 'cleaning', 'extra' ];
		$totalCost  = 0;
		$totalItems = 0;
		$order      = 0;

		foreach ( $slots as $slot )
		{
			if ( empty( $slot['upc'] ) )
			{
				continue;
			}

			if ( $limits['max_slots'] > 0 && $totalItems >= $limits['max_slots'] )
			{
				break;
			}

			$slotType = $slot['slot_type'] ?? 'extra';
			if ( !\in_array( $slotType, $validSlotTypes, true ) )
			{
				$slotType = 'extra';
			}

			Db::i()->insert( 'gd_loadout_items', [
				'loadout_id'   => (int) $loadoutId,
				'upc'          => substr( trim( $slot['upc'] ), 0, 20 ),
				'slot_type'    => $slotType,
				'custom_label' => !empty( $slot['custom_label'] ) ? substr( trim( $slot['custom_label'] ), 0, 100 ) : NULL,
				'sort_order'   => $order,
				'notes'        => NULL,
				'added_at'     => date( 'Y-m-d H:i:s' ),
			] );

			$totalItems++;
			$order++;

			if ( isset( $slot['price'] ) && (float) $slot['price'] > 0 )
			{
				$totalCost += (float) $slot['price'];
			}
		}

		Db::i()->update( 'gd_loadouts', [
			'total_items'     => $totalItems,
			'total_min_price' => $totalCost > 0 ? round( $totalCost, 2 ) : NULL,
		], [ 'id=?', (int) $loadoutId ] );

		$viewUrl = (string) Url::internal( 'app=gdloadout&module=loadouts&controller=builder&id=' . $loadoutId, 'front', 'gdloadout_builder_edit' );

		Output::i()->json( [
			'ok'         => true,
			'loadout_id' => (int) $loadoutId,
			'url'        => $viewUrl,
		] );
	}

	protected function delete(): void
	{
		Session::i()->csrfCheck();

		$member = Member::loggedIn();
		$id     = (int) ( Request::i()->loadout_id ?? 0 );

		try
		{
			Db::i()->select( 'id', 'gd_loadouts', [ 'id=? AND member_id=?', $id, (int) $member->member_id ] )->first();
		}
		catch ( \Throwable )
		{
			Output::i()->json( [ 'error' => Member::loggedIn()->language()->addToStack( 'gdloadout_err_not_found' ) ], 404 );
			return;
		}

		Db::i()->delete( 'gd_loadout_items', [ 'loadout_id=?', $id ] );
		Db::i()->delete( 'gd_loadout_votes', [ 'loadout_id=?', $id ] );
		Db::i()->delete( 'gd_loadout_follows', [ 'loadout_id=?', $id ] );
		Db::i()->delete( 'gd_loadout_forum_posts', [ 'loadout_id=?', $id ] );
		Db::i()->delete( 'gd_loadouts', [ 'id=?', $id ] );

		Output::i()->json( [ 'ok' => true ] );
	}

	protected function search(): void
	{
		$query = trim( Request::i()->q ?? '' );
		$page  = max( 1, (int) ( Request::i()->page ?? 1 ) );

		if ( mb_strlen( $query ) < 2 )
		{
			Output::i()->json( [ 'total' => 0, 'results' => [] ] );
			return;
		}

		try
		{
			$searcher = new \IPS\gdsearch\Search\Searcher();
			$result   = $searcher->search( $query, [ 'in_stock' => true ], 'relevance', $page, 12 );

			$out = [];
			foreach ( $result['results'] as $r )
			{
				$out[] = [
					'upc'          => $r['upc'] ?? '',
					'title'        => $r['title'] ?? '',
					'brand'        => $r['brand'] ?? '',
					'best_price'   => $r['best_price'] ?? null,
					'dealer_count' => $r['dealer_count'] ?? 0,
					'in_stock'     => $r['in_stock'] ?? false,
					'category'     => $r['category'] ?? '',
				];
			}

			Output::i()->json( [ 'total' => $result['total'] ?? 0, 'results' => $out ] );
		}
		catch ( \Throwable )
		{
			Output::i()->json( [ 'total' => 0, 'results' => [] ] );
		}
	}
}

class builder extends _builder {}
