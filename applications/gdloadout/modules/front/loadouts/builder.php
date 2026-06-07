<?php
namespace IPS\gdloadout\modules\front\loadouts;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }

class _builder extends \IPS\Dispatcher\Controller
{
	public function execute(): void { parent::execute(); }

	protected function manage(): void
	{
		$member = \IPS\Member::loggedIn();
		if ( !$member->member_id )
		{
			\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=core&module=system&controller=login', 'front', 'login' ) );
		}

		$limits = \IPS\gdloadout\Loadout\Limits::forMember( $member );
		$isVip  = \IPS\gdloadout\Loadout\Limits::isVip( $member );

		$loadout = null;
		$items   = [];
		$editId  = (int) \IPS\Request::i()->id;

		if ( $editId )
		{
			try
			{
				$row = \IPS\Db::i()->select( '*', 'gd_loadouts', [ 'id=? AND member_id=?', $editId, (int) $member->member_id ] )->first();
				$loadout = $row;
				foreach ( \IPS\Db::i()->select( '*', 'gd_loadout_items', [ 'loadout_id=?', $editId ], 'sort_order ASC' ) as $it )
				{
					$upc = $it['upc'];
					$catRow = null;
					try
					{
						$catRow = \IPS\Db::i()->select( 'name, image_url, category_slug', 'gd_catalog', [ 'upc=?', $upc ] )->first();
					}
					catch ( \Throwable ) {}

					$bestPrice = null;
					try
					{
						$bestPrice = \IPS\Db::i()->select( 'MIN(dealer_price)', 'gd_dealer_listings', [ 'upc=? AND listing_status=? AND in_stock=?', $upc, 'active', 1 ] )->first();
					}
					catch ( \Throwable ) {}

					$items[] = [
						'upc'          => $upc,
						'slot_type'    => $it['slot_type'],
						'custom_label' => $it['custom_label'] ?? '',
						'sort_order'   => (int) $it['sort_order'],
						'notes'        => $it['notes'] ?? '',
						'title'        => $catRow['name'] ?? $upc,
						'image_url'    => $catRow['image_url'] ?? '',
						'best_price'   => $bestPrice !== null ? (float) $bestPrice : null,
					];
				}
			}
			catch ( \Throwable )
			{
				$loadout = null;
			}
		}
		else
		{
			if ( $limits['max_loadouts'] > 0 )
			{
				try
				{
					$count = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_loadouts', [ 'member_id=?', (int) $member->member_id ] )->first();
					if ( $count >= $limits['max_loadouts'] )
					{
						\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gdloadout_builder_title' );
						\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'loadouts', 'gdloadout', 'front' )->hub();
						return;
					}
				}
				catch ( \Throwable ) {}
			}
		}

		$slots = \IPS\gdloadout\Loadout\Slots::all();

		$searchUrl = (string) \IPS\Http\Url::internal( 'app=gdloadout&module=loadouts&controller=builder&do=search', 'front' );
		$saveUrl   = (string) \IPS\Http\Url::internal( 'app=gdloadout&module=loadouts&controller=builder&do=save', 'front' );
		$deleteUrl = (string) \IPS\Http\Url::internal( 'app=gdloadout&module=loadouts&controller=builder&do=delete', 'front' );

		$initData = [
			'loadout'   => $loadout ? [
				'id'          => (int) $loadout['id'],
				'name'        => (string) $loadout['name'],
				'description' => (string) ( $loadout['description'] ?? '' ),
				'use_case'    => (string) ( $loadout['use_case'] ?? '' ),
				'visibility'  => (string) $loadout['visibility'],
				'slug'        => (string) $loadout['slug'],
			] : null,
			'items'     => $items,
			'slots'     => $slots,
			'limits'    => $limits,
			'isVip'     => $isVip,
			'csrfKey'   => \IPS\Session::i()->csrfKey,
			'searchUrl' => $searchUrl,
			'saveUrl'   => $saveUrl,
			'deleteUrl' => $deleteUrl,
		];

		$initJson = json_encode( $initData );

		try
		{
			\IPS\Output::i()->cssFiles = array_merge(
				\IPS\Output::i()->cssFiles,
				\IPS\Theme::i()->css( 'loadouts.css', 'gdloadout', 'front' )
			);
		}
		catch ( \Throwable ) {}

		try
		{
			\IPS\Output::i()->jsFiles = array_merge(
				\IPS\Output::i()->jsFiles,
				\IPS\Output::i()->js( 'builder.js', 'gdloadout', 'interface' )
			);
		}
		catch ( \Throwable ) {}

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( $editId ? 'gdloadout_builder_edit_title' : 'gdloadout_builder_title' );
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'loadouts', 'gdloadout', 'front' )->builder( $initJson );
	}

	protected function search(): void
	{
		\IPS\Session::i()->csrfCheck();

		$member = \IPS\Member::loggedIn();
		if ( !$member->member_id )
		{
			\IPS\Output::i()->json( [ 'results' => [] ], 403 );
			return;
		}

		$q = trim( (string) \IPS\Request::i()->q );
		if ( $q === '' )
		{
			\IPS\Output::i()->json( [ 'results' => [] ] );
			return;
		}

		try
		{
			$searcher = new \IPS\gdsearch\Search\Searcher();
			$data     = $searcher->search( $q, [ 'in_stock' => true ], 'relevance', 1, 24 );

			$results = [];
			foreach ( $data['results'] as $r )
			{
				$results[] = [
					'upc'        => (string) ( $r['upc'] ?? '' ),
					'title'      => (string) ( $r['name'] ?? '' ),
					'image_url'  => (string) ( $r['image_url'] ?? '' ),
					'best_price' => isset( $r['best_price'] ) ? (float) $r['best_price'] : null,
					'category'   => (string) ( $r['category'] ?? '' ),
					'caliber'    => (string) ( $r['caliber'] ?? '' ),
				];
			}

			\IPS\Output::i()->json( [ 'results' => $results ] );
		}
		catch ( \Throwable )
		{
			\IPS\Output::i()->json( [ 'results' => [] ] );
		}
	}

	protected function save(): void
	{
		\IPS\Session::i()->csrfCheck();

		$member = \IPS\Member::loggedIn();
		if ( !$member->member_id )
		{
			\IPS\Output::i()->json( [ 'ok' => false, 'error' => 'Not logged in.' ], 403 );
			return;
		}

		$raw = file_get_contents( 'php://input' );
		$input = json_decode( $raw, true );
		if ( !$input )
		{
			\IPS\Output::i()->json( [ 'ok' => false, 'error' => 'Invalid request.' ], 400 );
			return;
		}

		$name        = trim( (string) ( $input['name'] ?? '' ) );
		$description = trim( (string) ( $input['description'] ?? '' ) );
		$useCase     = trim( (string) ( $input['use_case'] ?? '' ) );
		$visibility  = (string) ( $input['visibility'] ?? 'unlisted' );
		$inputItems  = (array) ( $input['items'] ?? [] );
		$editId      = (int) ( $input['id'] ?? 0 );

		if ( $name === '' )
		{
			\IPS\Output::i()->json( [ 'ok' => false, 'error' => 'A name is required.' ], 400 );
			return;
		}

		$limits = \IPS\gdloadout\Loadout\Limits::forMember( $member );
		$isVip  = \IPS\gdloadout\Loadout\Limits::isVip( $member );

		if ( !$isVip && $visibility === 'private' )
		{
			$visibility = 'unlisted';
		}
		if ( !\in_array( $visibility, [ 'public', 'unlisted', 'private' ], true ) )
		{
			$visibility = 'unlisted';
		}

		if ( $limits['max_slots'] > 0 && \count( $inputItems ) > $limits['max_slots'] )
		{
			\IPS\Output::i()->json( [ 'ok' => false, 'error' => 'Slot limit exceeded.' ], 400 );
			return;
		}

		$memberId = (int) $member->member_id;

		if ( !$editId && $limits['max_loadouts'] > 0 )
		{
			try
			{
				$count = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_loadouts', [ 'member_id=?', $memberId ] )->first();
				if ( $count >= $limits['max_loadouts'] )
				{
					\IPS\Output::i()->json( [ 'ok' => false, 'error' => 'Loadout limit reached.' ], 400 );
					return;
				}
			}
			catch ( \Throwable ) {}
		}

		if ( $editId )
		{
			try
			{
				$existing = \IPS\Db::i()->select( '*', 'gd_loadouts', [ 'id=? AND member_id=?', $editId, $memberId ] )->first();
			}
			catch ( \Throwable )
			{
				\IPS\Output::i()->json( [ 'ok' => false, 'error' => 'Loadout not found.' ], 404 );
				return;
			}
		}

		$slug = $this->generateSlug( $name, $memberId, $editId );

		$validSlotTypes = array_keys( \IPS\gdloadout\Loadout\Slots::CORE );
		$validSlotTypes[] = 'extra';

		$cleanItems = [];
		$order = 0;
		foreach ( $inputItems as $it )
		{
			$upc      = trim( (string) ( $it['upc'] ?? '' ) );
			$slotType = (string) ( $it['slot_type'] ?? 'extra' );
			if ( $upc === '' ) continue;
			if ( !\in_array( $slotType, $validSlotTypes, true ) )
			{
				$slotType = 'extra';
			}

			$notes = '';
			if ( $isVip )
			{
				$notes = mb_substr( trim( (string) ( $it['notes'] ?? '' ) ), 0, 300 );
			}

			$cleanItems[] = [
				'upc'          => mb_substr( $upc, 0, 20 ),
				'slot_type'    => $slotType,
				'custom_label' => mb_substr( trim( (string) ( $it['custom_label'] ?? '' ) ), 0, 100 ),
				'sort_order'   => $order++,
				'notes'        => $notes,
				'added_at'     => time(),
			];
		}

		$totalMinPrice = 0.0;
		$hasNfa        = false;
		$hasState      = false;
		$upcs = array_column( $cleanItems, 'upc' );

		if ( !empty( $upcs ) )
		{
			try
			{
				foreach ( \IPS\Db::i()->select(
					'upc, MIN(dealer_price) as bp',
					'gd_dealer_listings',
					array_merge( [ \IPS\Db::i()->in( 'upc', $upcs ) ], [ [ 'listing_status=?', 'active' ], [ 'in_stock=?', 1 ] ] ),
					null, null, 'upc'
				) as $pr )
				{
					if ( $pr['bp'] !== null )
					{
						$totalMinPrice += (float) $pr['bp'];
					}
				}
			}
			catch ( \Throwable ) {}

			foreach ( $cleanItems as $ci )
			{
				if ( $ci['slot_type'] === 'suppressor' )
				{
					$hasNfa = true;
				}
			}

			try
			{
				$nfaCount = (int) \IPS\Db::i()->select(
					'COUNT(*)', 'gd_catalog',
					array_merge( [ \IPS\Db::i()->in( 'upc', $upcs ) ], [ [ "category_slug LIKE ?", '%nfa%' ] ] )
				)->first();
				if ( $nfaCount > 0 ) { $hasNfa = true; }
			}
			catch ( \Throwable ) {}
		}

		$now = time();

		if ( $editId )
		{
			\IPS\Db::i()->update( 'gd_loadouts', [
				'name'                 => mb_substr( $name, 0, 150 ),
				'slug'                 => $slug,
				'description'          => $description,
				'use_case'             => $useCase ?: null,
				'visibility'           => $visibility,
				'total_items'          => \count( $cleanItems ),
				'total_min_price'      => $totalMinPrice > 0 ? round( $totalMinPrice, 2 ) : null,
				'has_nfa_item'         => $hasNfa ? 1 : 0,
				'has_state_restriction' => $hasState ? 1 : 0,
				'updated_at'           => $now,
			], [ 'id=?', $editId ] );

			$loadoutId = $editId;

			\IPS\Db::i()->delete( 'gd_loadout_items', [ 'loadout_id=?', $loadoutId ] );
		}
		else
		{
			$loadoutId = \IPS\Db::i()->insert( 'gd_loadouts', [
				'member_id'            => $memberId,
				'name'                 => mb_substr( $name, 0, 150 ),
				'slug'                 => $slug,
				'description'          => $description,
				'use_case'             => $useCase ?: null,
				'visibility'           => $visibility,
				'upvotes'              => 0,
				'comment_count'        => 0,
				'follow_count'         => 0,
				'view_count'           => 0,
				'total_items'          => \count( $cleanItems ),
				'total_min_price'      => $totalMinPrice > 0 ? round( $totalMinPrice, 2 ) : null,
				'has_nfa_item'         => $hasNfa ? 1 : 0,
				'has_state_restriction' => $hasState ? 1 : 0,
				'created_at'           => $now,
				'updated_at'           => $now,
			] );
		}

		foreach ( $cleanItems as $ci )
		{
			try
			{
				\IPS\Db::i()->insert( 'gd_loadout_items', array_merge( $ci, [ 'loadout_id' => (int) $loadoutId ] ) );
			}
			catch ( \Throwable ) {}
		}

		$viewUrl = (string) \IPS\Http\Url::internal( 'app=gdloadout&module=loadouts&controller=hub&do=view', 'front', 'gdloadout_view', [ $member->name, $slug ] );

		\IPS\Output::i()->json( [ 'ok' => true, 'redirect' => $viewUrl ] );
	}

	protected function delete(): void
	{
		\IPS\Session::i()->csrfCheck();

		$member = \IPS\Member::loggedIn();
		if ( !$member->member_id )
		{
			\IPS\Output::i()->json( [ 'ok' => false, 'error' => 'Not logged in.' ], 403 );
			return;
		}

		$raw   = file_get_contents( 'php://input' );
		$input = json_decode( $raw, true );
		$id    = (int) ( $input['id'] ?? 0 );

		if ( !$id )
		{
			\IPS\Output::i()->json( [ 'ok' => false, 'error' => 'Missing ID.' ], 400 );
			return;
		}

		try
		{
			\IPS\Db::i()->select( 'id', 'gd_loadouts', [ 'id=? AND member_id=?', $id, (int) $member->member_id ] )->first();
		}
		catch ( \Throwable )
		{
			\IPS\Output::i()->json( [ 'ok' => false, 'error' => 'Not found.' ], 404 );
			return;
		}

		\IPS\Db::i()->delete( 'gd_loadout_items',   [ 'loadout_id=?', $id ] );
		\IPS\Db::i()->delete( 'gd_loadout_votes',   [ 'loadout_id=?', $id ] );
		\IPS\Db::i()->delete( 'gd_loadout_follows',  [ 'loadout_id=?', $id ] );
		\IPS\Db::i()->delete( 'gd_loadout_forum_posts', [ 'loadout_id=?', $id ] );
		\IPS\Db::i()->delete( 'gd_loadouts',         [ 'id=?', $id ] );

		$hubUrl = (string) \IPS\Http\Url::internal( 'app=gdloadout&module=loadouts&controller=hub', 'front', 'gdloadout_hub' );
		\IPS\Output::i()->json( [ 'ok' => true, 'redirect' => $hubUrl ] );
	}

	protected function generateSlug( string $name, int $memberId, int $excludeId = 0 ): string
	{
		$slug = mb_strtolower( trim( preg_replace( '/[^a-z0-9]+/i', '-', $name ), '-' ) );
		if ( $slug === '' )
		{
			$slug = 'loadout';
		}
		$slug = mb_substr( $slug, 0, 150 );

		$base    = $slug;
		$suffix  = 1;
		$where   = [ 'member_id=? AND slug=?', $memberId, $slug ];

		if ( $excludeId )
		{
			$where = [ 'member_id=? AND slug=? AND id!=?', $memberId, $slug, $excludeId ];
		}

		while ( true )
		{
			try
			{
				$exists = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_loadouts', $where )->first();
				if ( !$exists ) break;
			}
			catch ( \Throwable ) { break; }

			$suffix++;
			$slug = $base . '-' . $suffix;

			if ( $excludeId )
			{
				$where = [ 'member_id=? AND slug=? AND id!=?', $memberId, $slug, $excludeId ];
			}
			else
			{
				$where = [ 'member_id=? AND slug=?', $memberId, $slug ];
			}
		}

		return $slug;
	}
}
class builder extends _builder {}
