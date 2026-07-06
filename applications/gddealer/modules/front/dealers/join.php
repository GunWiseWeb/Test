<?php
/**
 * @brief       GD Dealer Manager — Frontend Dealer Join Page (/dealers/join)
 * @package     IPS Community Suite
 * @subpackage  GD Dealer Manager
 * @since       16 Apr 2026
 *
 * Section 3.3 of the spec. Public-facing landing page that advertises
 * the subscription tiers and links directly to IPS Commerce checkout.
 */

namespace IPS\gddealer\modules\front\dealers;

use IPS\gddealer\Dealer\Dealer;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _join extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		parent::execute();
	}

	/**
	 * Landing page — hero, pricing cards, how-it-works, FAQ, bottom CTA.
	 * If the logged-in member already has a gd_dealer_feed_config row,
	 * they are sent to their dashboard instead.
	 */
	protected function manage()
	{
		$member = \IPS\Member::loggedIn();

		if ( $member->member_id )
		{
			try
			{
				Dealer::load( (int) $member->member_id );
				\IPS\Output::i()->redirect(
					\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard' )
				);
				return;
			}
			catch ( \OutOfRangeException ) { /* not a dealer yet */ }

			$dealerGroups = [
				(int) \IPS\Settings::i()->gddealer_group_basic,
				(int) \IPS\Settings::i()->gddealer_group_pro,
				(int) \IPS\Settings::i()->gddealer_group_enterprise,
				(int) \IPS\Settings::i()->gddealer_group_max,
				(int) \IPS\Settings::i()->gddealer_group_founding,
			];
			$memberGroups = $member->mgroup_others
				? array_filter( array_map( 'intval', explode( ',', (string) $member->mgroup_others ) ) )
				: [];
			$memberGroups[] = (int) $member->member_group_id;

			if ( array_intersect( $dealerGroups, $memberGroups ) )
			{
				\IPS\Output::i()->redirect(
					\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=join&do=register' )
				);
				return;
			}
		}

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::external( 'https://gunrack.deals/dealer-memberships/' )
		);
		return;
	}

	/**
	 * v1.0.326 — Dealer login entry point.
	 *
	 * A single URL that a dealer-facing "Dealer Login" link can point
	 * at. Behavior:
	 *   1. Logged OUT  → send the visitor through IPS core login, with
	 *      a `ref` back to this endpoint so the dealer-status redirect
	 *      runs after they authenticate.
	 *   2. Logged IN + registered dealer (has a Dealer record) → dashboard.
	 *   3. Logged IN + in a dealer group but NOT registered → registration form.
	 *   4. Logged IN + not a dealer → external buy-membership page.
	 *
	 * Steps 2–4 mirror `manage()` above so the outcome after login is
	 * indistinguishable from visiting the join landing page while
	 * already authenticated. `Dealer::isDealerMember()` is the
	 * canonical primary+secondary group check.
	 */
	protected function login(): void
	{
		$member = \IPS\Member::loggedIn();

		/* 1) Not logged in — hand off to IPS login, come back afterward. */
		if ( !$member->member_id )
		{
			$self = (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=join&do=login', 'front' );
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=core&module=system&controller=login', 'front', 'login' )
					->setQueryString( 'ref', base64_encode( $self ) )
			);
			return;
		}

		/* 2) Registered dealer — straight to dashboard. */
		try
		{
			Dealer::load( (int) $member->member_id );
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard' )
			);
			return;
		}
		catch ( \OutOfRangeException ) { /* not registered yet */ }

		/* 3) Dealer group member but no Dealer record → registration form. */
		if ( Dealer::isDealerMember( $member ) )
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=join&do=register' )
			);
			return;
		}

		/* 4) Not a dealer — external buy-membership page (mirrors manage()). */
		\IPS\Output::i()->redirect(
			\IPS\Http\Url::external( 'https://gunrack.deals/dealer-memberships/' )
		);
	}

	/**
	 * Resolve a tier's CTA URL. If an IPS Commerce product ID is configured,
	 * send the dealer straight into the nexus checkout flow. Otherwise fall
	 * back to the join landing page itself.
	 */
	protected function commerceUrlFor( int $productId, string $fallback ): string
	{
		if ( $productId <= 0 )
		{
			return $fallback;
		}
		return (string) \IPS\Http\Url::internal(
			'app=nexus&module=store&controller=product&id=' . $productId
		);
	}

	/**
	 * Self-service onboarding form. Reached via dashboard redirect when a
	 * member holds a dealer subscription group but has no feed_config row.
	 * Tier is inferred from the member's group membership; the member fills
	 * in their display name and an optional feed URL, and the form creates
	 * the gd_dealer_feed_config row and sends the welcome email.
	 */
	protected function register(): void
	{
		$member = \IPS\Member::loggedIn();

		if ( !$member->member_id )
		{
			\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=core&module=system&controller=login', 'front', 'login' ) );
			return;
		}

		try
		{
			Dealer::load( (int) $member->member_id );
			\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard' ) );
			return;
		}
		catch ( \OutOfRangeException ) { /* proceed with registration */ }

		$tier         = 'basic';
		$memberGroups = $member->mgroup_others
			? array_filter( array_map( 'intval', explode( ',', (string) $member->mgroup_others ) ) )
			: [];
		$memberGroups[] = (int) $member->member_group_id;

		if ( in_array( (int) \IPS\Settings::i()->gddealer_group_max, $memberGroups, true ) )
		{
			$tier = 'max';
		}
		elseif ( in_array( (int) \IPS\Settings::i()->gddealer_group_enterprise, $memberGroups, true ) )
		{
			$tier = 'enterprise';
		}
		elseif ( in_array( (int) \IPS\Settings::i()->gddealer_group_pro, $memberGroups, true ) )
		{
			$tier = 'pro';
		}
		elseif ( in_array( (int) \IPS\Settings::i()->gddealer_group_founding, $memberGroups, true ) )
		{
			$tier = 'founding';
		}

		$form = new \IPS\Helpers\Form( 'dealer_register', 'gddealer_register_submit' );
		$form->add( new \IPS\Helpers\Form\Text( 'gddealer_reg_dealer_name', $member->name, TRUE, [ 'maxLength' => 100 ] ) );
		$form->add( new \IPS\Helpers\Form\Select( 'gddealer_reg_feed_format', 'xml', FALSE, [
			'options' => [ 'xml' => 'XML', 'json' => 'JSON', 'csv' => 'CSV' ],
		] ) );
		$form->add( new \IPS\Helpers\Form\Text( 'gddealer_reg_feed_url', '', FALSE, [ 'maxLength' => 500 ] ) );

		if ( $values = $form->values() )
		{
			$dealerName = trim( (string) $values['gddealer_reg_dealer_name'] );
			$feedFormat = (string) $values['gddealer_reg_feed_format'];
			$feedUrl    = trim( (string) ( $values['gddealer_reg_feed_url'] ?? '' ) );

			$slug = strtolower( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $dealerName ) ) );
			$slug = trim( $slug, '-' ) ?: 'dealer-' . (int) $member->member_id;
			$base = $slug;
			$i    = 1;
			while ( (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_dealer_feed_config',
				[ 'dealer_slug=?', $slug ] )->first() > 0 )
			{
				$slug = $base . '-' . $i++;
			}

			$apiKey = bin2hex( random_bytes( 24 ) );

			\IPS\Db::i()->insert( 'gd_dealer_feed_config', [
				'dealer_id'         => (int) $member->member_id,
				'dealer_name'       => $dealerName,
				'dealer_slug'       => $slug,
				'subscription_tier' => $tier,
				'feed_url'          => $feedUrl !== '' ? $feedUrl : null,
				'feed_format'       => $feedFormat,
				'active'            => 1,
				'suspended'         => 0,
				'api_key'           => $apiKey,
				'created_at'        => date( 'Y-m-d H:i:s' ),
			] );

			try
			{
				\IPS\Email::buildFromTemplate( 'gddealer', 'dealerWelcome', [
					'name'          => $member->name,
					'api_key'       => $apiKey,
					'contact_email' => (string) ( \IPS\Settings::i()->gddealer_help_contact ?: 'dealers@gunrack.deals' ),
					'profile_url'   => (string) \IPS\Http\Url::internal(
						'app=gddealer&module=dealers&controller=profile&dealer_slug=' . urlencode( $slug )
					),
				], \IPS\Email::TYPE_TRANSACTIONAL )->send( $member );
			}
			catch ( \Exception ) {}

			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard' ),
				'gddealer_register_complete'
			);
			return;
		}

		$guidelinesUrl = (string) \IPS\Http\Url::internal(
			'app=gddealer&module=dealers&controller=profile&do=guidelines',
			'front', 'dealers_review_guidelines'
		);

		\IPS\Output::i()->title  = $member->language()->addToStack( 'gddealer_register_title' );
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )
			->dealerRegister( (string) $form, $tier, $member->name, $guidelinesUrl );
	}
	protected function subscribe(): void
	{
		$tier = strtolower( trim( (string) ( \IPS\Request::i()->tier ?? '' ) ) );

		$map = [
			'basic'      => (int) \IPS\Settings::i()->gddealer_commerce_basic_id,
			'pro'        => (int) \IPS\Settings::i()->gddealer_commerce_pro_id,
			'enterprise' => (int) \IPS\Settings::i()->gddealer_commerce_enterprise_id,
			'max'        => (int) \IPS\Settings::i()->gddealer_commerce_max_id,
		];

		if ( !isset( $map[ $tier ] ) || !$map[ $tier ] )
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=nexus&module=subscriptions&controller=subscriptions', 'front', 'nexus_subscriptions' )
			);
			return;
		}

		$pid = $map[ $tier ];

		try
		{
			$pkg = \IPS\nexus\Subscription\Package::load( $pid );
			$url = $pkg->url()->setQueryString( 'do', 'purchase' )->csrf();
			\IPS\Output::i()->redirect( $url );
			return;
		}
		catch ( \Throwable )
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=nexus&module=subscriptions&controller=subscriptions', 'front', 'nexus_subscriptions' )
			);
			return;
		}
	}
}

class join extends _join {}
