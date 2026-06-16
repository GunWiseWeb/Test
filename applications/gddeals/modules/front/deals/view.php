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
		\IPS\Output::i()->cssFiles = array_merge( \IPS\Output::i()->cssFiles, \IPS\Theme::i()->css( 'deals.css', 'gddeals', 'front' ) );

		try
		{
			$deal = \IPS\gddeals\Deal::loadAndCheckPerms( \IPS\Request::i()->id );
		}
		catch ( \OutOfRangeException $e )
		{
			\IPS\Output::i()->error( 'gddeals_deal_not_found', '2GD101/1', 404 );
			return;
		}

		$d = [
			'title'          => $deal->title,
			'category_name'  => $deal->container()->_title,
			'retailer_name'  => $deal->retailer_name,
			'retailer_type'  => $deal->retailer_type,
			'store_location' => $deal->store_location ?: '',
			'price'          => $deal->deal_price !== NULL ? '$' . number_format( (float) $deal->deal_price, 2 ) : '',
			'original'       => $deal->original_price !== NULL ? '$' . number_format( (float) $deal->original_price, 2 ) : '',
			'discount'       => $deal->discount_pct ? rtrim( rtrim( number_format( (float) $deal->discount_pct, 1 ), '0' ), '.' ) : '',
			'deal_url'       => (string) ( $deal->deal_url ?: '' ),
			'promo'          => $deal->promo_code ?: '',
			'free_ship'      => (bool) $deal->free_shipping,
			'shipping'       => $deal->shipping_cost !== NULL ? '$' . number_format( (float) $deal->shipping_cost, 2 ) : '',
			'image'          => $deal->image_url ?: '',
			'description'    => $deal->description ?: '',
			'post_type'      => $deal->post_type,
			'author_name'    => $deal->author()->name,
			'posted'         => (string) \IPS\DateTime::ts( $deal->posted_at )->relative(),
			'is_pending'     => (bool) $deal->hidden(),
		];

		\IPS\Output::i()->title  = $deal->title;
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'deals', 'gddeals', 'front' )->view( $d );
	}
}
class view extends _view {}
