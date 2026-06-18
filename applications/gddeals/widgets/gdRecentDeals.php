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

class gdRecentDeals extends PermissionCache implements Customizable
{
	public string $key = 'gdRecentDeals';
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

		$where = array(
			array( '( gd_deal_posts.expired=0 OR gd_deal_posts.expired IS NULL )' ),
			array( "( gd_deal_posts.post_type IS NULL OR gd_deal_posts.post_type <> 'coupon' )" ),
		);
		$order = "( gd_deal_posts.source_badge = 'dealer' ) DESC, gd_deal_posts.posted_at DESC";

		$items = array();
		try
		{
			foreach ( \IPS\gddeals\Deal::getItemsWithPermission( $where, $order, array( 0, $limit ), 'read' ) as $deal )
			{
				$price = '';
				if ( $deal->deal_price !== NULL && $deal->deal_price !== '' )
				{
					$price = '$' . number_format( (float) $deal->deal_price, 2 );
				}
				$items[] = array(
					'url'      => (string) $deal->url(),
					'title'    => $deal->title,
					'retailer' => (string) $deal->retailer_name,
					'price'    => $price,
					'discount' => ( $deal->discount_pct ? (int) $deal->discount_pct . '% off' : '' ),
					'image'    => (string) $deal->image_url,
					'dealer'   => ( $deal->source_badge === 'dealer' ),
				);
			}
		}
		catch ( \Throwable $e ) {}

		if ( empty( $items ) ) { return ''; }
		return $this->output( $items );
	}
}
