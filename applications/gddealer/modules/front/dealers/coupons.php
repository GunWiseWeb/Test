<?php
/**
 * @brief       GD Dealer Manager — Frontend Dealer Coupons
 * @package     IPS Community Suite
 * @subpackage  GD Dealer Manager
 * @since       12 Jun 2026
 *
 * Dealer dashboard controller for managing coupons (gd_dealer_coupons table).
 * Provides CRUD operations: list, create, edit, toggle active, delete.
 */

namespace IPS\gddealer\modules\front\dealers;

use IPS\gddealer\Dealer\Dealer;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _coupons extends \IPS\Dispatcher\Controller
{
	use \IPS\gddealer\Traits\DealerShellTrait;

	public static bool $csrfProtected = TRUE;

	/** Current dealer loaded from the logged-in member */
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

		\IPS\Output::i()->jsFiles = array_merge(
			\IPS\Output::i()->jsFiles,
			\IPS\Output::i()->js( 'couponForm.js', 'gddealer', 'interface' )
		);

		parent::execute();
	}

	/* ------------------------------------------------------------------ */
	/*  List coupons                                                      */
	/* ------------------------------------------------------------------ */

	protected function manage()
	{
		$dealer  = $this->dealer;
		$now     = time();
		$coupons = [];

		$baseUrl = 'app=gddealer&module=dealers&controller=coupons';

		$createUrl = (string) \IPS\Http\Url::internal( $baseUrl . '&do=create', 'front', 'dealers_coupons_action' );

		try
		{
			foreach ( \IPS\Db::i()->select( '*', 'gd_dealer_coupons', [ 'dealer_id=?', (int) $dealer->dealer_id ], 'created DESC' ) as $row )
			{
				$couponId = (int) $row['coupon_id'];

				/* Compute display status */
				if ( !(int) $row['is_active'] )
				{
					$status = 'Off';
				}
				elseif ( $row['expiry'] !== null && (int) $row['expiry'] > 0 && (int) $row['expiry'] < $now )
				{
					$status = 'Expired';
				}
				else
				{
					$status = 'Active';
				}

				$editUrl   = (string) \IPS\Http\Url::internal( $baseUrl . '&do=edit&coupon_id=' . $couponId, 'front', 'dealers_coupons_action' );
				$toggleUrl = (string) \IPS\Http\Url::internal( $baseUrl . '&do=toggle&coupon_id=' . $couponId, 'front', 'dealers_coupons_action' );
				$deleteUrl = (string) \IPS\Http\Url::internal( $baseUrl . '&do=delete&coupon_id=' . $couponId, 'front', 'dealers_coupons_action' );

				$coupons[] = [
					'coupon_id'      => $couponId,
					'code'           => (string) $row['code'],
					'description'    => (string) ( $row['description'] ?? '' ),
					'discount_type'  => (string) $row['discount_type'],
					'discount_value' => (float) $row['discount_value'],
					'min_purchase'   => $row['min_purchase'] !== null ? (float) $row['min_purchase'] : null,
					'terms'          => (string) ( $row['terms'] ?? '' ),
					'expiry'         => $row['expiry'] !== null ? (int) $row['expiry'] : null,
					'is_active'      => (bool) (int) $row['is_active'],
					'created'        => (int) $row['created'],
					'status'         => $status,
					'edit_url'       => $editUrl,
					'toggle_url'     => $toggleUrl,
					'delete_url'     => $deleteUrl,
				];
			}
		}
		catch ( \Throwable ) {}

		$csrfKey = (string) \IPS\Session::i()->csrfKey;

		$this->output( 'coupons',
			(string) \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->couponsList( $coupons, $createUrl, $csrfKey )
		);
	}

	/* ------------------------------------------------------------------ */
	/*  Create coupon                                                     */
	/* ------------------------------------------------------------------ */

	protected function create()
	{
		$dealer  = $this->dealer;
		$baseUrl = 'app=gddealer&module=dealers&controller=coupons';

		/* POST — process form */
		if ( \IPS\Request::i()->isAjax() === false && isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] === 'POST' )
		{
			\IPS\Session::i()->csrfCheck();

			$errors = [];
			$req    = \IPS\Request::i();

			/* code */
			$code = strtoupper( trim( (string) ( $req->code ?? '' ) ) );
			if ( $code === '' )
			{
				$errors[] = 'Coupon code is required.';
			}
			$code = substr( $code, 0, 40 );

			/* discount_type */
			$discountType = (string) ( $req->discount_type ?? 'percent' );
			if ( !in_array( $discountType, [ 'percent', 'amount', 'free_shipping' ], true ) )
			{
				$discountType = 'percent';
			}

			/* discount_value */
			$discountValue = (float) ( $req->discount_value ?? 0 );
			if ( $discountType === 'free_shipping' )
			{
				$discountValue = 0;
			}
			elseif ( $discountType === 'percent' && ( $discountValue < 1 || $discountValue > 90 ) )
			{
				$errors[] = 'Percent discount must be between 1 and 90.';
			}
			elseif ( $discountType === 'amount' && $discountValue <= 0 )
			{
				$errors[] = 'Amount discount must be greater than 0.';
			}

			/* min_purchase */
			$minPurchase = null;
			$minRaw = trim( (string) ( $req->min_purchase ?? '' ) );
			if ( $minRaw !== '' )
			{
				$minPurchase = (float) $minRaw;
				if ( $minPurchase <= 0 )
				{
					$errors[] = 'Minimum purchase must be greater than zero.';
				}
			}

			/* description */
			$description = substr( trim( (string) ( $req->description ?? '' ) ), 0, 255 );

			/* terms */
			$terms = null;
			$termsRaw = trim( (string) ( $req->terms ?? '' ) );
			if ( $termsRaw !== '' )
			{
				$terms = substr( $termsRaw, 0, 500 );
			}

			/* expiry */
			$expiry = null;
			$expiryRaw = trim( (string) ( $req->expiry ?? '' ) );
			if ( $expiryRaw !== '' )
			{
				$parsed = strtotime( $expiryRaw );
				if ( $parsed !== false && $parsed > 0 )
				{
					$expiry = $parsed;
				}
			}

			/* is_active */
			$isActive = (int) ( $req->is_active ?? 1 );
			if ( $isActive !== 0 && $isActive !== 1 )
			{
				$isActive = 1;
			}

			if ( !empty( $errors ) )
			{
				$cancelUrl = (string) \IPS\Http\Url::internal( $baseUrl, 'front', 'dealers_coupons' );
				$formUrl   = (string) \IPS\Http\Url::internal( $baseUrl . '&do=create', 'front', 'dealers_coupons_action' );
				$csrfKey   = (string) \IPS\Session::i()->csrfKey;

				$this->output( 'coupons',
					(string) \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->couponForm(
						'create', $formUrl, $cancelUrl, $csrfKey, $errors,
						[
							'code'           => $code,
							'description'    => $description,
							'discount_type'  => $discountType,
							'discount_value' => $discountValue,
							'min_purchase'   => $minPurchase,
							'terms'          => $terms,
							'expiry'         => $expiryRaw,
							'is_active'      => $isActive,
						]
					)
				);
				return;
			}

			try
			{
				\IPS\Db::i()->insert( 'gd_dealer_coupons', [
					'dealer_id'      => (int) $dealer->dealer_id,
					'code'           => $code,
					'description'    => $description !== '' ? $description : null,
					'discount_type'  => $discountType,
					'discount_value' => $discountValue,
					'min_purchase'   => $minPurchase,
					'terms'          => $terms,
					'expiry'         => $expiry,
					'is_active'      => $isActive,
					'created'        => time(),
				] );
			}
			catch ( \Throwable ) {}

			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( $baseUrl, 'front', 'dealers_coupons' )
			);
			return;
		}

		/* GET — show create form */
		$cancelUrl = (string) \IPS\Http\Url::internal( $baseUrl, 'front', 'dealers_coupons' );
		$formUrl   = (string) \IPS\Http\Url::internal( $baseUrl . '&do=create', 'front', 'dealers_coupons_action' );
		$csrfKey   = (string) \IPS\Session::i()->csrfKey;

		$this->output( 'coupons',
			(string) \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->couponForm(
				'create', $formUrl, $cancelUrl, $csrfKey, [],
				[
					'code'           => '',
					'description'    => '',
					'discount_type'  => 'percent',
					'discount_value' => '',
					'min_purchase'   => '',
					'terms'          => '',
					'expiry'         => '',
					'is_active'      => 1,
				]
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/*  Edit coupon                                                       */
	/* ------------------------------------------------------------------ */

	protected function edit()
	{
		$dealer   = $this->dealer;
		$baseUrl  = 'app=gddealer&module=dealers&controller=coupons';
		$couponId = (int) ( \IPS\Request::i()->coupon_id ?? 0 );

		/* Load and verify ownership */
		$coupon = null;
		try
		{
			$coupon = \IPS\Db::i()->select( '*', 'gd_dealer_coupons', [
				'coupon_id=? AND dealer_id=?', $couponId, (int) $dealer->dealer_id
			] )->first();
		}
		catch ( \Throwable )
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( $baseUrl, 'front', 'dealers_coupons' )
			);
			return;
		}

		if ( $coupon === null )
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( $baseUrl, 'front', 'dealers_coupons' )
			);
			return;
		}

		/* POST — process edit form */
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] === 'POST' )
		{
			\IPS\Session::i()->csrfCheck();

			$errors = [];
			$req    = \IPS\Request::i();

			/* code */
			$code = strtoupper( trim( (string) ( $req->code ?? '' ) ) );
			if ( $code === '' )
			{
				$errors[] = 'Coupon code is required.';
			}
			$code = substr( $code, 0, 40 );

			/* discount_type */
			$discountType = (string) ( $req->discount_type ?? 'percent' );
			if ( !in_array( $discountType, [ 'percent', 'amount', 'free_shipping' ], true ) )
			{
				$discountType = 'percent';
			}

			/* discount_value */
			$discountValue = (float) ( $req->discount_value ?? 0 );
			if ( $discountType === 'free_shipping' )
			{
				$discountValue = 0;
			}
			elseif ( $discountType === 'percent' && ( $discountValue < 1 || $discountValue > 90 ) )
			{
				$errors[] = 'Percent discount must be between 1 and 90.';
			}
			elseif ( $discountType === 'amount' && $discountValue <= 0 )
			{
				$errors[] = 'Amount discount must be greater than 0.';
			}

			/* min_purchase */
			$minPurchase = null;
			$minRaw = trim( (string) ( $req->min_purchase ?? '' ) );
			if ( $minRaw !== '' )
			{
				$minPurchase = (float) $minRaw;
				if ( $minPurchase <= 0 )
				{
					$errors[] = 'Minimum purchase must be greater than zero.';
				}
			}

			/* description */
			$description = substr( trim( (string) ( $req->description ?? '' ) ), 0, 255 );

			/* terms */
			$terms = null;
			$termsRaw = trim( (string) ( $req->terms ?? '' ) );
			if ( $termsRaw !== '' )
			{
				$terms = substr( $termsRaw, 0, 500 );
			}

			/* expiry */
			$expiry = null;
			$expiryRaw = trim( (string) ( $req->expiry ?? '' ) );
			if ( $expiryRaw !== '' )
			{
				$parsed = strtotime( $expiryRaw );
				if ( $parsed !== false && $parsed > 0 )
				{
					$expiry = $parsed;
				}
			}

			/* is_active */
			$isActive = (int) ( $req->is_active ?? 1 );
			if ( $isActive !== 0 && $isActive !== 1 )
			{
				$isActive = 1;
			}

			if ( !empty( $errors ) )
			{
				$cancelUrl = (string) \IPS\Http\Url::internal( $baseUrl, 'front', 'dealers_coupons' );
				$formUrl   = (string) \IPS\Http\Url::internal( $baseUrl . '&do=edit&coupon_id=' . $couponId, 'front', 'dealers_coupons_action' );
				$csrfKey   = (string) \IPS\Session::i()->csrfKey;

				$this->output( 'coupons',
					(string) \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->couponForm(
						'edit', $formUrl, $cancelUrl, $csrfKey, $errors,
						[
							'code'           => $code,
							'description'    => $description,
							'discount_type'  => $discountType,
							'discount_value' => $discountValue,
							'min_purchase'   => $minPurchase,
							'terms'          => $terms,
							'expiry'         => $expiryRaw,
							'is_active'      => $isActive,
						]
					)
				);
				return;
			}

			try
			{
				\IPS\Db::i()->update( 'gd_dealer_coupons', [
					'code'           => $code,
					'description'    => $description !== '' ? $description : null,
					'discount_type'  => $discountType,
					'discount_value' => $discountValue,
					'min_purchase'   => $minPurchase,
					'terms'          => $terms,
					'expiry'         => $expiry,
					'is_active'      => $isActive,
				], [ 'coupon_id=? AND dealer_id=?', $couponId, (int) $dealer->dealer_id ] );
			}
			catch ( \Throwable ) {}

			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( $baseUrl, 'front', 'dealers_coupons' )
			);
			return;
		}

		/* GET — show pre-filled edit form */
		$cancelUrl = (string) \IPS\Http\Url::internal( $baseUrl, 'front', 'dealers_coupons' );
		$formUrl   = (string) \IPS\Http\Url::internal( $baseUrl . '&do=edit&coupon_id=' . $couponId, 'front', 'dealers_coupons_action' );
		$csrfKey   = (string) \IPS\Session::i()->csrfKey;

		/* Format expiry for datetime-local input if set */
		$expiryFormatted = '';
		if ( $coupon['expiry'] !== null && (int) $coupon['expiry'] > 0 )
		{
			$expiryFormatted = date( 'Y-m-d\TH:i', (int) $coupon['expiry'] );
		}

		$this->output( 'coupons',
			(string) \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->couponForm(
				'edit', $formUrl, $cancelUrl, $csrfKey, [],
				[
					'code'           => (string) $coupon['code'],
					'description'    => (string) ( $coupon['description'] ?? '' ),
					'discount_type'  => (string) $coupon['discount_type'],
					'discount_value' => (float) $coupon['discount_value'],
					'min_purchase'   => $coupon['min_purchase'] !== null ? (float) $coupon['min_purchase'] : '',
					'terms'          => (string) ( $coupon['terms'] ?? '' ),
					'expiry'         => $expiryFormatted,
					'is_active'      => (int) $coupon['is_active'],
				]
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/*  Toggle coupon active/inactive                                     */
	/* ------------------------------------------------------------------ */

	protected function toggle()
	{
		\IPS\Session::i()->csrfCheck();

		$dealer   = $this->dealer;
		$baseUrl  = 'app=gddealer&module=dealers&controller=coupons';
		$couponId = (int) ( \IPS\Request::i()->coupon_id ?? 0 );

		try
		{
			$coupon = \IPS\Db::i()->select( '*', 'gd_dealer_coupons', [
				'coupon_id=? AND dealer_id=?', $couponId, (int) $dealer->dealer_id
			] )->first();

			$newActive = (int) $coupon['is_active'] === 1 ? 0 : 1;

			\IPS\Db::i()->update( 'gd_dealer_coupons', [
				'is_active' => $newActive,
			], [ 'coupon_id=? AND dealer_id=?', $couponId, (int) $dealer->dealer_id ] );
		}
		catch ( \Throwable ) {}

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( $baseUrl, 'front', 'dealers_coupons' )
		);
	}

	/* ------------------------------------------------------------------ */
	/*  Delete coupon                                                     */
	/* ------------------------------------------------------------------ */

	protected function delete()
	{
		\IPS\Session::i()->csrfCheck();

		$dealer   = $this->dealer;
		$baseUrl  = 'app=gddealer&module=dealers&controller=coupons';
		$couponId = (int) ( \IPS\Request::i()->coupon_id ?? 0 );

		try
		{
			/* Verify ownership before deleting */
			\IPS\Db::i()->select( 'coupon_id', 'gd_dealer_coupons', [
				'coupon_id=? AND dealer_id=?', $couponId, (int) $dealer->dealer_id
			] )->first();

			\IPS\Db::i()->delete( 'gd_dealer_coupons', [
				'coupon_id=? AND dealer_id=?', $couponId, (int) $dealer->dealer_id
			] );
		}
		catch ( \Throwable ) {}

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( $baseUrl, 'front', 'dealers_coupons' )
		);
	}
}

class coupons extends _coupons {}
