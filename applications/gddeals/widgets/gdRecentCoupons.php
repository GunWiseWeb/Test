<?php
namespace IPS\gddeals\widgets;

use IPS\Helpers\Form;
use IPS\Helpers\Form\Number;
use IPS\Output;
use IPS\Theme;
use IPS\Widget\Customizable;
use IPS\Widget\PermissionCache;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class gdRecentCoupons extends PermissionCache implements Customizable
{
	public string $key = 'gdRecentCoupons';
	public string $app = 'gddeals';

	public function init(): void
	{
		Output::i()->cssFiles = array_merge( Output::i()->cssFiles, Theme::i()->css( 'deals.css', 'gddeals', 'front' ) );
		$this->template( array( Theme::i()->getTemplate( 'widgets', 'gddeals', 'front' ), $this->key ) );
		parent::init();
	}

	public function configuration( Form &$form=null ): Form
	{
		$form = parent::configuration( $form );
		$form->add( new Number( 'toshow', $this->configuration['toshow'] ?? 5, TRUE ) );
		return $form;
	}

	public function render(): string
	{
		$limit = (int) ( $this->configuration['toshow'] ?? 5 );
		if ( $limit < 1 ) { $limit = 5; }

		/* v1.0.55 — filter out expired coupons at query time.
		   Rationale: gd_deal_posts.expired is unreliable for coupons
		   until the auto-expire task (also new in 1.0.55) has run,
		   so we belt-and-suspenders on BOTH the expired flag AND a
		   raw expires_at date check. A coupon shows only when:
		     * post_type = 'coupon'
		     * expired flag is 0 (or NULL — legacy rows)
		     * expires_at is unset (NULL / 0 — perpetual) OR still in
		       the future
		   Mirrors the pattern used in modules/front/deals/browse.php
		   and modules/front/coupons/browse.php. */
		$now = time();
		$where = array(
			array( "gd_deal_posts.post_type = 'coupon'" ),
			array( '( gd_deal_posts.expired = 0 OR gd_deal_posts.expired IS NULL )' ),
			array( '( gd_deal_posts.expires_at IS NULL OR gd_deal_posts.expires_at = 0 OR gd_deal_posts.expires_at > ? )', $now ),
		);
		$order = "( gd_deal_posts.source_badge = 'dealer' ) DESC, gd_deal_posts.posted_at DESC";

		$items = array();
		try
		{
			foreach ( \IPS\gddeals\Deal::getItemsWithPermission( $where, $order, array( 0, $limit ), 'read' ) as $deal )
			{
				$items[] = array(
					'url'      => (string) $deal->url(),
					'title'    => $deal->title,
					'retailer' => (string) $deal->retailer_name,
					'code'     => (string) $deal->promo_code,
					'discount' => ( $deal->discount_pct ? (int) $deal->discount_pct . '% off' : '' ),
					'dealer'   => ( $deal->source_badge === 'dealer' ),
				);
			}
		}
		catch ( \Throwable $e ) {}

		if ( empty( $items ) ) { return ''; }
		return $this->output( $items );
	}
}
