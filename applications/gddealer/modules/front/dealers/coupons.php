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

				$gddealsEnabled    = \IPS\Application::appIsEnabled( 'gddeals' );
				$gddealsCategories = $this->gddealsCategoriesForForm();

				$this->output( 'coupons',
					(string) \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->couponForm(
						'create', $formUrl, $cancelUrl, $csrfKey, $errors,
						[
							'code'                  => $code,
							'description'            => $description,
							'discount_type'          => $discountType,
							'discount_value'         => $discountValue,
							'min_purchase'           => $minPurchase,
							'terms'                  => $terms,
							'expiry'                 => $expiryRaw,
							'is_active'              => $isActive,
							'publish_community'      => (int) ( $req->publish_community ?? 0 ),
							'community_category_id'  => (int) ( $req->community_category_id ?? 0 ),
						],
						$gddealsEnabled, $gddealsCategories
					)
				);
				return;
			}

			try
			{
				$newId = \IPS\Db::i()->insert( 'gd_dealer_coupons', [
					'dealer_id'             => (int) $dealer->dealer_id,
					'code'                  => $code,
					'description'           => $description !== '' ? $description : null,
					'discount_type'         => $discountType,
					'discount_value'        => $discountValue,
					'min_purchase'          => $minPurchase,
					'terms'                 => $terms,
					'expiry'                => $expiry,
					'is_active'             => $isActive,
					'created'               => time(),
					'publish_community'     => ( (int) ( $req->publish_community ?? 0 ) === 1 ) ? 1 : 0,
					'community_category_id' => (int) ( $req->community_category_id ?? 0 ) ?: null,
				] );
				$this->syncCommunityCoupon( (int) $newId );
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

		$gddealsEnabled    = \IPS\Application::appIsEnabled( 'gddeals' );
		$gddealsCategories = $this->gddealsCategoriesForForm();

		$this->output( 'coupons',
			(string) \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->couponForm(
				'create', $formUrl, $cancelUrl, $csrfKey, [],
				[
					'code'                  => '',
					'description'           => '',
					'discount_type'         => 'percent',
					'discount_value'        => '',
					'min_purchase'          => '',
					'terms'                 => '',
					'expiry'                => '',
					'is_active'             => 1,
					'publish_community'     => 0,
					'community_category_id' => 0,
				],
				$gddealsEnabled, $gddealsCategories
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

				$gddealsEnabled    = \IPS\Application::appIsEnabled( 'gddeals' );
				$gddealsCategories = $this->gddealsCategoriesForForm();

				$this->output( 'coupons',
					(string) \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->couponForm(
						'edit', $formUrl, $cancelUrl, $csrfKey, $errors,
						[
							'code'                  => $code,
							'description'            => $description,
							'discount_type'          => $discountType,
							'discount_value'         => $discountValue,
							'min_purchase'           => $minPurchase,
							'terms'                  => $terms,
							'expiry'                 => $expiryRaw,
							'is_active'              => $isActive,
							'publish_community'      => (int) ( $req->publish_community ?? 0 ),
							'community_category_id'  => (int) ( $req->community_category_id ?? 0 ),
						],
						$gddealsEnabled, $gddealsCategories
					)
				);
				return;
			}

			try
			{
				\IPS\Db::i()->update( 'gd_dealer_coupons', [
					'code'                  => $code,
					'description'           => $description !== '' ? $description : null,
					'discount_type'         => $discountType,
					'discount_value'        => $discountValue,
					'min_purchase'          => $minPurchase,
					'terms'                 => $terms,
					'expiry'                => $expiry,
					'is_active'             => $isActive,
					'publish_community'     => ( (int) ( $req->publish_community ?? 0 ) === 1 ) ? 1 : 0,
					'community_category_id' => (int) ( $req->community_category_id ?? 0 ) ?: null,
				], [ 'coupon_id=? AND dealer_id=?', $couponId, (int) $dealer->dealer_id ] );
				$this->syncCommunityCoupon( $couponId );
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

		$gddealsEnabled    = \IPS\Application::appIsEnabled( 'gddeals' );
		$gddealsCategories = $this->gddealsCategoriesForForm();

		$this->output( 'coupons',
			(string) \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->couponForm(
				'edit', $formUrl, $cancelUrl, $csrfKey, [],
				[
					'code'                  => (string) $coupon['code'],
					'description'           => (string) ( $coupon['description'] ?? '' ),
					'discount_type'         => (string) $coupon['discount_type'],
					'discount_value'        => (float) $coupon['discount_value'],
					'min_purchase'          => $coupon['min_purchase'] !== null ? (float) $coupon['min_purchase'] : '',
					'terms'                 => (string) ( $coupon['terms'] ?? '' ),
					'expiry'                => $expiryFormatted,
					'is_active'             => (int) $coupon['is_active'],
					'publish_community'     => (int) ( $coupon['publish_community'] ?? 0 ),
					'community_category_id' => (int) ( $coupon['community_category_id'] ?? 0 ),
				],
				$gddealsEnabled, $gddealsCategories
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
			$this->syncCommunityCoupon( $couponId );
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
			/* Verify ownership and get community_post_id before deleting */
			$row = \IPS\Db::i()->select( '*', 'gd_dealer_coupons', [
				'coupon_id=? AND dealer_id=?', $couponId, (int) $dealer->dealer_id
			] )->first();

			$communityPostId = (int) ( $row['community_post_id'] ?? 0 );
			if ( $communityPostId && \IPS\Application::appIsEnabled( 'gddeals' ) )
			{
				try { \IPS\gddeals\Deal::load( $communityPostId )->delete(); } catch ( \OutOfRangeException $e ) {}
			}

			\IPS\Db::i()->delete( 'gd_dealer_coupons', [
				'coupon_id=? AND dealer_id=?', $couponId, (int) $dealer->dealer_id
			] );
		}
		catch ( \Throwable ) {}

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( $baseUrl, 'front', 'dealers_coupons' )
		);
	}

	/* ------------------------------------------------------------------ */
	/*  gddeals community helpers                                         */
	/* ------------------------------------------------------------------ */

	protected function gddealsCategoriesForForm(): array
	{
		if ( !\IPS\Application::appIsEnabled( 'gddeals' ) ) { return []; }
		$out = [];
		try {
			foreach ( \IPS\gddeals\Category::roots() as $root ) {
				$this->gddealsCategoryBranch( $root, 0, $out );
			}
		} catch ( \Throwable $e ) {}
		return $out;
	}

	protected function gddealsCategoryBranch( \IPS\gddeals\Category $cat, int $depth, array &$out ): void
	{
		$prefix = $depth > 0 ? str_repeat( "\xE2\x80\x94 ", $depth ) : '';
		$out[ (int) $cat->_id ] = $prefix . (string) $cat->_title;

		try {
			foreach ( $cat->children() as $child ) {
				$this->gddealsCategoryBranch( $child, $depth + 1, $out );
			}
		} catch ( \Throwable $e ) {}
	}

	protected function syncCommunityCoupon( int $couponId ): void
	{
		if ( !\IPS\Application::appIsEnabled( 'gddeals' ) ) { return; }

		try { $row = \IPS\Db::i()->select( '*', 'gd_dealer_coupons', [ 'coupon_id=?', $couponId ] )->first(); }
		catch ( \UnderflowException $e ) { return; }

		$publish    = (int) ( $row['publish_community'] ?? 0 ) === 1;
		$categoryId = (int) ( $row['community_category_id'] ?? 0 );
		$existingId = (int) ( $row['community_post_id'] ?? 0 );
		$isActive   = (int) ( $row['is_active'] ?? 0 ) === 1;

		if ( !$publish || !$categoryId )
		{
			if ( $existingId )
			{
				try { \IPS\gddeals\Deal::load( $existingId )->delete(); } catch ( \OutOfRangeException $e ) {}
				\IPS\Db::i()->update( 'gd_dealer_coupons', [ 'community_post_id' => null ], [ 'coupon_id=?', $couponId ] );
			}
			return;
		}

		try { $category = \IPS\gddeals\Category::load( $categoryId ); }
		catch ( \OutOfRangeException $e ) { return; }

		$dealerName = ''; $websiteUrl = '';
		try {
			$cfg = \IPS\Db::i()->select( 'dealer_name, website_url', 'gd_dealer_feed_config', [ 'dealer_id=?', (int) $row['dealer_id'] ] )->first();
			$dealerName = (string) ( $cfg['dealer_name'] ?? '' );
			$websiteUrl = (string) ( $cfg['website_url'] ?? '' );
		} catch ( \UnderflowException $e ) {}

		$code   = (string) ( $row['code'] ?? '' );
		$desc   = trim( (string) ( $row['description'] ?? '' ) );
		$terms  = (string) ( $row['terms'] ?? '' );
		$dType  = (string) ( $row['discount_type'] ?? 'percent' );
		$dValue = (float) ( $row['discount_value'] ?? 0 );
		$title  = $desc !== '' ? $desc : ( $code !== '' ? ( 'Coupon code ' . $code ) : 'Dealer coupon' );

		$post = null;
		if ( $existingId ) { try { $post = \IPS\gddeals\Deal::load( $existingId ); } catch ( \OutOfRangeException $e ) { $post = null; } }
		if ( $post === null )
		{
			$author = \IPS\Member::load( (int) $row['dealer_id'] );
			$post = \IPS\gddeals\Deal::createItem( $author, \IPS\Request::i()->ipAddress(), \IPS\DateTime::create(), $category, FALSE );
		}
		else
		{
			$post->category_id = (int) $category->_id;
		}

		$post->post_type      = 'coupon';
		$post->title          = $title;
		$post->description    = $terms;
		$post->promo_code     = $code;
		$post->retailer_name  = $dealerName ?: $title;
		$post->retailer_type  = 'online';
		$post->deal_url       = $websiteUrl;
		$post->image_url      = null;
		$post->source_badge   = 'dealer';
		$post->expires_at     = $row['expiry'] !== null ? (int) $row['expiry'] : null;
		$post->discount_pct   = ( $dType === 'percent' ) ? $dValue : 0;
		$post->free_shipping  = ( $dType === 'free_shipping' ) ? 1 : 0;
		$post->deal_price     = null;
		$post->original_price = null;
		$post->save();

		try {
			if ( $isActive && $post->hidden() !== 0 ) { $post->unhide( \IPS\Member::loggedIn() ); }
			elseif ( !$isActive && $post->hidden() === 0 ) { $post->hide( \IPS\Member::loggedIn() ); }
		} catch ( \Throwable $e ) {}

		\IPS\Db::i()->update( 'gd_dealer_coupons', [ 'community_post_id' => (int) $post->id ], [ 'coupon_id=?', $couponId ] );
	}
}

class coupons extends _coupons {}
