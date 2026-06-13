<?php

namespace IPS\gddealer\modules\front\dealers;

use IPS\gddealer\Dealer\Dealer;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _deals extends \IPS\Dispatcher\Controller
{
	use \IPS\gddealer\Traits\DealerShellTrait;

	public static bool $csrfProtected = TRUE;

	protected ?Dealer $dealer = null;

	public function execute(): void
	{
		$member = \IPS\Member::loggedIn();

		if ( !$member->member_id )
		{
			\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=join' ) );
			return;
		}

		if ( $member->isAdmin() )
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers', 'admin' )
			);
			return;
		}

		try
		{
			$this->dealer = Dealer::load( (int) $member->member_id );
		}
		catch ( \OutOfRangeException )
		{
			$this->dealer = null;
		}

		if ( $this->dealer === null && Dealer::isDealerMember( $member ) )
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=join&do=register' )
			);
			return;
		}

		if ( $this->dealer === null )
		{
			\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=join' ) );
			return;
		}

		parent::execute();
	}

	protected function manage(): void
	{
		$dealerId = (int) $this->dealer->dealer_id;
		$baseUrl  = 'app=gddealer&module=dealers&controller=deals';

		$createUrl = (string) \IPS\Http\Url::internal( $baseUrl . '&do=create', 'front', 'dealers_deals_action' );

		$activeListingsCount = 0;
		try
		{
			$activeListingsCount = (int) \IPS\Db::i()->select(
				'COUNT(*)', 'gd_dealer_listings',
				[ 'dealer_id=? AND listing_status=?', $dealerId, 'active' ]
			)->first();
		}
		catch ( \Throwable ) {}

		$canCreate = $activeListingsCount > 0;

		$deals = [];
		try
		{
			$now = time();
			foreach ( \IPS\Db::i()->select(
				'd.*, c.title AS product_title, c.image_url AS product_image_url, l.dealer_price AS current_dealer_price',
				[ 'gd_deals', 'd' ],
				[ 'd.dealer_id=? AND d.source=?', $dealerId, 'dealer' ],
				'd.created DESC'
			)->join( [ 'gd_catalog', 'c' ], 'c.upc = d.upc', 'LEFT' )
			 ->join( [ 'gd_dealer_listings', 'l' ], 'l.upc = d.upc AND l.dealer_id = d.dealer_id', 'LEFT' ) as $row )
			{
				$dealId    = (int) $row['deal_id'];
				$isActive  = (int) $row['is_active'];
				$startDate = $row['start_date'] !== null ? (int) $row['start_date'] : null;
				$endDate   = $row['end_date'] !== null ? (int) $row['end_date'] : null;

				if ( !$isActive )
				{
					$status = 'Off';
				}
				elseif ( $startDate !== null && $startDate > $now )
				{
					$status = 'Scheduled';
				}
				elseif ( $endDate !== null && $endDate < $now )
				{
					$status = 'Ended';
				}
				else
				{
					$status = 'Live';
				}

				$editUrl   = (string) \IPS\Http\Url::internal( $baseUrl . '&do=edit&deal_id=' . $dealId, 'front', 'dealers_deals_action' );
				$toggleUrl = (string) \IPS\Http\Url::internal( $baseUrl . '&do=toggle&deal_id=' . $dealId, 'front', 'dealers_deals_action' );
				$deleteUrl = (string) \IPS\Http\Url::internal( $baseUrl . '&do=delete&deal_id=' . $dealId, 'front', 'dealers_deals_action' );

				$deals[] = [
					'deal_id'              => $dealId,
					'upc'                  => (string) $row['upc'],
					'deal_type'            => (string) $row['deal_type'],
					'deal_price'           => $row['deal_price'] !== null ? (float) $row['deal_price'] : null,
					'deal_percent'         => $row['deal_percent'] !== null ? (float) $row['deal_percent'] : null,
					'title'                => htmlspecialchars( (string) ( $row['title'] ?? '' ), ENT_QUOTES, 'UTF-8' ),
					'blurb'                => htmlspecialchars( (string) ( $row['blurb'] ?? '' ), ENT_QUOTES, 'UTF-8' ),
					'start_date'           => $startDate,
					'end_date'             => $endDate,
					'is_active'            => $isActive,
					'status'               => $status,
					'created'              => (int) $row['created'],
					'product_title'        => htmlspecialchars( (string) ( $row['product_title'] ?? 'Unknown Product' ), ENT_QUOTES, 'UTF-8' ),
					'product_image_url'    => htmlspecialchars( (string) ( $row['product_image_url'] ?? '' ), ENT_QUOTES, 'UTF-8' ),
					'current_dealer_price' => $row['current_dealer_price'] !== null ? (float) $row['current_dealer_price'] : null,
					'edit_url'             => $editUrl,
					'toggle_url'           => $toggleUrl,
					'delete_url'           => $deleteUrl,
				];
			}
		}
		catch ( \Throwable $e ) {
			try { \IPS\Log::log( $e, 'gddealer_listings' ); } catch ( \Throwable ) {}
		}

		$csrfKey = (string) \IPS\Session::i()->csrfKey;

		$body = (string) \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )
			->dealsList( $deals, $canCreate, $createUrl, $csrfKey );

		$this->output( 'deals', $body );
	}

	protected function create(): void
	{
		$dealerId = (int) $this->dealer->dealer_id;
		$baseUrl  = 'app=gddealer&module=dealers&controller=deals';

		if ( \IPS\Request::i()->requestMethod() === 'GET' )
		{
			$listings = [];
			try
			{
				foreach ( \IPS\Db::i()->select(
					'l.upc, l.dealer_price, c.title AS product_title',
					[ 'gd_dealer_listings', 'l' ],
					[ 'l.dealer_id=? AND l.listing_status=?', $dealerId, 'active' ],
					'c.title ASC'
				)->join( [ 'gd_catalog', 'c' ], 'c.upc = l.upc', 'LEFT' ) as $row )
				{
					$listings[] = [
						'upc'           => (string) $row['upc'],
						'dealer_price'  => (float) $row['dealer_price'],
						'title'         => htmlspecialchars( (string) ( $row['product_title'] ?? $row['upc'] ), ENT_QUOTES, 'UTF-8' ),
					];
				}
			}
			catch ( \Throwable $e ) {
				try { \IPS\Log::log( $e, 'gddealer_listings' ); } catch ( \Throwable ) {}
			}

			$submitUrl = (string) \IPS\Http\Url::internal( $baseUrl . '&do=create', 'front', 'dealers_deals_action' );
			$cancelUrl = (string) \IPS\Http\Url::internal( $baseUrl, 'front', 'dealers_deals' );
			$csrfKey   = (string) \IPS\Session::i()->csrfKey;

			$body = (string) \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )
				->dealsForm( $listings, $submitUrl, $cancelUrl, $csrfKey, [], null );

			$this->output( 'deals', $body );
			return;
		}

		\IPS\Session::i()->csrfCheck();

		$req       = \IPS\Request::i();
		$upc       = trim( (string) ( $req->upc ?? '' ) );
		$dealType  = trim( (string) ( $req->deal_type ?? '' ) );
		$dealPrice = (float) ( $req->deal_price ?? 0 );
		$dealPct   = (float) ( $req->deal_percent ?? 0 );
		$title     = mb_substr( trim( (string) ( $req->title ?? '' ) ), 0, 150 );
		$blurb     = mb_substr( trim( (string) ( $req->blurb ?? '' ) ), 0, 500 );

		$startDate = null;
		if ( !empty( $req->start_date ) )
		{
			$parsed = strtotime( (string) $req->start_date );
			if ( $parsed !== false ) { $startDate = $parsed; }
		}
		$endDate = null;
		if ( !empty( $req->end_date ) )
		{
			$parsed = strtotime( (string) $req->end_date );
			if ( $parsed !== false ) { $endDate = $parsed; }
		}

		$errors = $this->validateDeal( $dealerId, $upc, $dealType, $dealPrice, $dealPct, $title, $blurb, $startDate, $endDate );

		if ( !empty( $errors ) )
		{
			\IPS\Output::i()->error( implode( ' ', $errors ), '1GDD_DEALS/1', 400 );
			return;
		}

		$insert = [
			'dealer_id'  => $dealerId,
			'upc'        => $upc,
			'deal_type'  => $dealType,
			'deal_price' => $dealType === 'price' ? $dealPrice : null,
			'deal_percent' => $dealType === 'percent' ? $dealPct : null,
			'title'      => $title !== '' ? $title : null,
			'blurb'      => $blurb !== '' ? $blurb : null,
			'start_date' => $startDate,
			'end_date'   => $endDate,
			'is_active'  => 1,
			'source'     => 'dealer',
			'created'    => time(),
			'updated'    => null,
		];

		try
		{
			\IPS\Db::i()->insert( 'gd_deals', $insert );
		}
		catch ( \Throwable $e )
		{
			\IPS\Output::i()->error( 'Failed to create deal.', '1GDD_DEALS/2', 500 );
			return;
		}

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( $baseUrl, 'front', 'dealers_deals' )
		);
	}

	protected function edit(): void
	{
		$dealerId = (int) $this->dealer->dealer_id;
		$dealId   = (int) ( \IPS\Request::i()->deal_id ?? 0 );
		$baseUrl  = 'app=gddealer&module=dealers&controller=deals';

		$deal = $this->loadDealOrError( $dealId, $dealerId );
		if ( $deal === null ) { return; }

		if ( \IPS\Request::i()->requestMethod() === 'GET' )
		{
			$listings = [];
			try
			{
				foreach ( \IPS\Db::i()->select(
					'l.upc, l.dealer_price, c.title AS product_title',
					[ 'gd_dealer_listings', 'l' ],
					[ 'l.dealer_id=? AND l.listing_status=?', $dealerId, 'active' ],
					'c.title ASC'
				)->join( [ 'gd_catalog', 'c' ], 'c.upc = l.upc', 'LEFT' ) as $row )
				{
					$listings[] = [
						'upc'           => (string) $row['upc'],
						'dealer_price'  => (float) $row['dealer_price'],
						'title'         => htmlspecialchars( (string) ( $row['product_title'] ?? $row['upc'] ), ENT_QUOTES, 'UTF-8' ),
					];
				}
			}
			catch ( \Throwable $e ) {
				try { \IPS\Log::log( $e, 'gddealer_listings' ); } catch ( \Throwable ) {}
			}

			$submitUrl = (string) \IPS\Http\Url::internal( $baseUrl . '&do=edit&deal_id=' . $dealId, 'front', 'dealers_deals_action' );
			$cancelUrl = (string) \IPS\Http\Url::internal( $baseUrl, 'front', 'dealers_deals' );
			$csrfKey   = (string) \IPS\Session::i()->csrfKey;

			$body = (string) \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )
				->dealsForm( $listings, $submitUrl, $cancelUrl, $csrfKey, [], $deal );

			$this->output( 'deals', $body );
			return;
		}

		\IPS\Session::i()->csrfCheck();

		$req       = \IPS\Request::i();
		$upc       = trim( (string) ( $req->upc ?? '' ) );
		$dealType  = trim( (string) ( $req->deal_type ?? '' ) );
		$dealPrice = (float) ( $req->deal_price ?? 0 );
		$dealPct   = (float) ( $req->deal_percent ?? 0 );
		$title     = mb_substr( trim( (string) ( $req->title ?? '' ) ), 0, 150 );
		$blurb     = mb_substr( trim( (string) ( $req->blurb ?? '' ) ), 0, 500 );

		$startDate = null;
		if ( !empty( $req->start_date ) )
		{
			$parsed = strtotime( (string) $req->start_date );
			if ( $parsed !== false ) { $startDate = $parsed; }
		}
		$endDate = null;
		if ( !empty( $req->end_date ) )
		{
			$parsed = strtotime( (string) $req->end_date );
			if ( $parsed !== false ) { $endDate = $parsed; }
		}

		$errors = $this->validateDeal( $dealerId, $upc, $dealType, $dealPrice, $dealPct, $title, $blurb, $startDate, $endDate, $dealId );

		if ( !empty( $errors ) )
		{
			\IPS\Output::i()->error( implode( ' ', $errors ), '1GDD_DEALS/3', 400 );
			return;
		}

		$update = [
			'upc'          => $upc,
			'deal_type'    => $dealType,
			'deal_price'   => $dealType === 'price' ? $dealPrice : null,
			'deal_percent' => $dealType === 'percent' ? $dealPct : null,
			'title'        => $title !== '' ? $title : null,
			'blurb'        => $blurb !== '' ? $blurb : null,
			'start_date'   => $startDate,
			'end_date'     => $endDate,
			'updated'      => time(),
		];

		try
		{
			\IPS\Db::i()->update( 'gd_deals', $update, [ 'deal_id=? AND dealer_id=?', $dealId, $dealerId ] );
		}
		catch ( \Throwable )
		{
			\IPS\Output::i()->error( 'Failed to update deal.', '1GDD_DEALS/4', 500 );
			return;
		}

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( $baseUrl, 'front', 'dealers_deals' )
		);
	}

	protected function toggle(): void
	{
		\IPS\Session::i()->csrfCheck();

		$dealerId = (int) $this->dealer->dealer_id;
		$dealId   = (int) ( \IPS\Request::i()->deal_id ?? 0 );

		$deal = $this->loadDealOrError( $dealId, $dealerId );
		if ( $deal === null ) { return; }

		$newActive = (int) $deal['is_active'] === 1 ? 0 : 1;

		try
		{
			\IPS\Db::i()->update( 'gd_deals', [
				'is_active' => $newActive,
				'updated'   => time(),
			], [ 'deal_id=? AND dealer_id=?', $dealId, $dealerId ] );
		}
		catch ( \Throwable )
		{
			\IPS\Output::i()->error( 'Failed to toggle deal.', '1GDD_DEALS/5', 500 );
			return;
		}

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=deals', 'front', 'dealers_deals' )
		);
	}

	protected function delete(): void
	{
		\IPS\Session::i()->csrfCheck();

		$dealerId = (int) $this->dealer->dealer_id;
		$dealId   = (int) ( \IPS\Request::i()->deal_id ?? 0 );

		$deal = $this->loadDealOrError( $dealId, $dealerId );
		if ( $deal === null ) { return; }

		try
		{
			\IPS\Db::i()->delete( 'gd_deals', [ 'deal_id=? AND dealer_id=?', $dealId, $dealerId ] );
		}
		catch ( \Throwable )
		{
			\IPS\Output::i()->error( 'Failed to delete deal.', '1GDD_DEALS/6', 500 );
			return;
		}

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=deals', 'front', 'dealers_deals' )
		);
	}

	/**
	 * Validate deal fields. Returns an array of error strings (empty = valid).
	 * Pass $excludeDealId to skip the overlap check for the deal being edited.
	 */
	private function validateDeal(
		int $dealerId,
		string $upc,
		string $dealType,
		float $dealPrice,
		float $dealPct,
		string $title,
		string $blurb,
		?int $startDate,
		?int $endDate,
		?int $excludeDealId = null
	): array
	{
		$errors = [];

		if ( $upc === '' )
		{
			$errors[] = 'UPC is required.';
			return $errors;
		}

		// Verify this UPC belongs to an active listing for this dealer
		$listingPrice = null;
		try
		{
			$listingPrice = (float) \IPS\Db::i()->select(
				'dealer_price', 'gd_dealer_listings',
				[ 'dealer_id=? AND upc=? AND listing_status=?', $dealerId, $upc, 'active' ]
			)->first();
		}
		catch ( \Throwable )
		{
			$errors[] = 'The selected product is not in your active listings.';
			return $errors;
		}

		if ( !in_array( $dealType, [ 'price', 'percent' ], true ) )
		{
			$errors[] = 'Deal type must be "price" or "percent".';
		}

		if ( $dealType === 'price' )
		{
			if ( $dealPrice <= 0 )
			{
				$errors[] = 'Deal price must be greater than $0.';
			}
			elseif ( $listingPrice !== null && $dealPrice >= $listingPrice )
			{
				$errors[] = 'Deal price ($' . number_format( $dealPrice, 2 ) . ') must be lower than your current listing price ($' . number_format( $listingPrice, 2 ) . ').';
			}
		}

		if ( $dealType === 'percent' )
		{
			if ( $dealPct < 1 || $dealPct > 90 )
			{
				$errors[] = 'Deal percent must be between 1% and 90%.';
			}
		}

		if ( mb_strlen( $title ) > 150 )
		{
			$errors[] = 'Title cannot exceed 150 characters.';
		}

		if ( mb_strlen( $blurb ) > 500 )
		{
			$errors[] = 'Blurb cannot exceed 500 characters.';
		}

		if ( $startDate !== null && $endDate !== null && $endDate <= $startDate )
		{
			$errors[] = 'End date must be after start date.';
		}

		// Overlap check: one active deal per (dealer_id, upc) with overlapping date window
		if ( empty( $errors ) )
		{
			$newStart = $startDate ?? 0;
			$newEnd   = $endDate ?? PHP_INT_MAX;

			$overlapWhere = [ 'dealer_id=? AND upc=? AND is_active=?', $dealerId, $upc, 1 ];

			try
			{
				foreach ( \IPS\Db::i()->select( '*', 'gd_deals', $overlapWhere ) as $existing )
				{
					$existingId = (int) $existing['deal_id'];
					if ( $excludeDealId !== null && $existingId === $excludeDealId )
					{
						continue;
					}

					$exStart = $existing['start_date'] !== null ? (int) $existing['start_date'] : 0;
					$exEnd   = $existing['end_date'] !== null ? (int) $existing['end_date'] : PHP_INT_MAX;

					// Two ranges overlap if one starts before the other ends and vice versa
					if ( $newStart < $exEnd && $exStart < $newEnd )
					{
						$conflictTitle = (string) ( $existing['title'] ?? 'Deal #' . $existingId );
						$errors[] = 'This product already has an active deal ("' . htmlspecialchars( $conflictTitle, ENT_QUOTES, 'UTF-8' ) . '") with an overlapping date range.';
						break;
					}
				}
			}
			catch ( \Throwable ) {}
		}

		return $errors;
	}

	/**
	 * Load a deal row and verify it belongs to this dealer. Returns the row
	 * as an associative array, or null after sending an error response.
	 */
	private function loadDealOrError( int $dealId, int $dealerId ): ?array
	{
		if ( $dealId <= 0 )
		{
			\IPS\Output::i()->error( 'Invalid deal ID.', '2GDD_DEALS/10', 400 );
			return null;
		}

		try
		{
			$row = \IPS\Db::i()->select( '*', 'gd_deals', [ 'deal_id=? AND dealer_id=?', $dealId, $dealerId ] )->first();
			return $row;
		}
		catch ( \Throwable )
		{
			\IPS\Output::i()->error( 'Deal not found.', '2GDD_DEALS/11', 404 );
			return null;
		}
	}
}

class deals extends _deals {}
