<?php
/**
 * @brief  gdsearch price-drop notifications (on-site bell + email toggle).
 */
namespace IPS\gdsearch\extensions\core\Notifications;

use IPS\Member;
use IPS\Notification\Inline;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _PriceAlerts extends \IPS\Extensions\NotificationsAbstract
{
	/** Appears automatically on the member Notification Settings page. */
	public static function configurationOptions( ?Member $member = NULL ): array
	{
		return [
			'gdsearch_price_drop' => [
				'type'              => 'standard',
				'notificationTypes' => [ 'price_drop' ],
				'title'             => 'gdsearch_notif_price_drop',
				'showTitle'         => TRUE,
				'description'       => 'gdsearch_notif_price_drop_desc',
				'default'           => [ 'inline', 'email' ],
				'disabled'          => [],
			],
		];
	}

	/** Bell (inline) rendering. Reads data passed in $notification->extra. */
	public function parse_price_drop( Inline $notification, bool $htmlEscape = TRUE ): array
	{
		$extra = $notification->extra ?: [];
		$title = (string) ( $extra['title'] ?? 'A product you\'re watching' );
		$price = isset( $extra['price'] ) ? '$' . number_format( (float) $extra['price'], 2 ) : '';
		$upc   = (string) ( $extra['upc'] ?? '' );

		$url = $upc !== ''
			? \IPS\Http\Url::internal( "app=gdsearch&module=search&controller=results&do=product&upc={$upc}", 'front', 'gdsearch_product' )
			: \IPS\Http\Url::internal( 'app=gdsearch&module=search&controller=results', 'front', 'gdsearch_results' );

		return [
			'title'   => $title . ( $price !== '' ? ' dropped to ' . $price : ' dropped in price' ),
			'url'     => $url,
			'content' => 'A dealer is now at or below your alert price. View current prices.',
			'author'  => NULL,
		];
	}
}

class PriceAlerts extends _PriceAlerts {}
