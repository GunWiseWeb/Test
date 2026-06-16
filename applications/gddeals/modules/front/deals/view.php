<?php
namespace IPS\gddeals\modules\front\deals;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _view extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		parent::execute();
	}

	protected function manage()
	{
		try
		{
			$deal = \IPS\gddeals\Deal::loadAndCheckPerms( \IPS\Request::i()->id );
		}
		catch ( \OutOfRangeException $e )
		{
			\IPS\Output::i()->error( 'gddeals_deal_not_found', '2GD101/1', 404 );
			return;
		}

		$dealPrice    = ( $deal->deal_price > 0 )     ? '$' . number_format( (float) $deal->deal_price, 2 )     : '';
		$origPrice    = ( $deal->original_price > 0 )  ? '$' . number_format( (float) $deal->original_price, 2 ) : '';
		$shipCost     = ( $deal->shipping_cost > 0 )   ? '$' . number_format( (float) $deal->shipping_cost, 2 )  : '';
		$discountPct  = ( $deal->discount_pct > 0 )    ? number_format( (float) $deal->discount_pct, 1 )         : '';

		$d = [
			'title'          => $deal->title,
			'category_name'  => $deal->container()->_title,
			'retailer_name'  => $deal->retailer_name,
			'retailer_type'  => $deal->retailer_type,
			'store_location' => $deal->store_location,
			'deal_price'     => $dealPrice,
			'original_price' => $origPrice,
			'discount_pct'   => $discountPct,
			'deal_url'       => $deal->deal_url,
			'promo_code'     => $deal->promo_code,
			'free_shipping'  => (bool) $deal->free_shipping,
			'shipping_cost'  => $shipCost,
			'description'    => $deal->description,
			'post_type'      => $deal->post_type,
			'source_badge'   => $deal->source_badge,
			'author_name'    => $deal->author()->name,
			'posted'         => \IPS\DateTime::ts( $deal->posted_at )->relative(),
			'is_pending'     => (bool) $deal->hidden(),
		];

		\IPS\Output::i()->title  = $deal->title;
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'deals', 'gddeals', 'front' )->view( $d );
	}
}
class view extends _view {}
