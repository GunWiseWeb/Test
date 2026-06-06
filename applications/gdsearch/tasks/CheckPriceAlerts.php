<?php
namespace IPS\gdsearch\tasks;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _CheckPriceAlerts extends \IPS\Task
{
	/**
	 * Compare each active price alert against the product's current lowest price
	 * (gd_catalog.total_min_price) and email the member once when it drops to/below
	 * their threshold. Re-arms when the price rises back above the threshold.
	 */
	public function execute(): mixed
	{
		$alerts = \IPS\Db::i()->select( '*', 'gd_price_alerts', [ 'threshold > 0' ], 'id ASC', [ 0, 500 ] );

		foreach ( $alerts as $a )
		{
			try
			{
				$upc       = (string) $a['upc'];
				$threshold = (float) $a['threshold'];

				$current = null;
				try {
					$row = \IPS\Db::i()->select( 'total_min_price, title', 'gd_catalog', [ 'upc=? AND record_status=?', $upc, 'active' ] )->first();
					$current = ( $row['total_min_price'] !== null ) ? (float) $row['total_min_price'] : null;
					$title   = (string) $row['title'];
				} catch ( \Throwable ) { continue; }

				if ( $current === null || $current <= 0 )
				{
					continue;
				}

				$lastNotified      = (int) ( $a['last_notified'] ?? 0 );
				$lastNotifiedPrice = ( $a['last_notified_price'] !== null ) ? (float) $a['last_notified_price'] : null;

				if ( $current <= $threshold )
				{
					$shouldNotify = ( $lastNotified === 0 ) || ( $lastNotifiedPrice !== null && $current < $lastNotifiedPrice );
					if ( $shouldNotify )
					{
						if ( $this->notify( (int) $a['member_id'], $upc, $title, $current, $threshold ) )
						{
							\IPS\Db::i()->update( 'gd_price_alerts',
								[ 'last_notified' => time(), 'last_notified_price' => $current ],
								[ 'id=?', (int) $a['id'] ]
							);
						}
					}
				}
				elseif ( $lastNotified !== 0 )
				{
					\IPS\Db::i()->update( 'gd_price_alerts',
						[ 'last_notified' => 0, 'last_notified_price' => null ],
						[ 'id=?', (int) $a['id'] ]
					);
				}
			}
			catch ( \Throwable ) { continue; }
		}

		return null;
	}

	protected function notify( int $memberId, string $upc, string $title, float $price, float $threshold ): bool
	{
		try
		{
			$member = \IPS\Member::load( $memberId );
			if ( !$member->member_id || !$member->email )
			{
				return false;
			}

			$url = (string) \IPS\Http\Url::internal( "app=gdsearch&module=search&controller=results&do=product&upc={$upc}", 'front', 'gdsearch_product' );
			$manageUrl = (string) \IPS\Http\Url::internal( 'app=gdsearch&module=search&controller=results&do=myAlerts', 'front' );

			$priceStr = '$' . number_format( $price, 2 );
			$threshStr = '$' . number_format( $threshold, 2 );
			$safeTitle = htmlspecialchars( $title, ENT_QUOTES );

			$html  = '<p>Good news — a product you\'re watching just dropped to <strong>' . $priceStr . '</strong>, at or below your alert price of ' . $threshStr . '.</p>';
			$html .= '<p><strong>' . $safeTitle . '</strong></p>';
			$html .= '<p><a href="' . $url . '">View current dealer prices &raquo;</a></p>';
			$html .= '<p style="font-size:12px;color:#6b7280">You set this price alert on gunrack.deals. <a href="' . $manageUrl . '">Manage your alerts</a>.</p>';

			$plain = "A product you're watching dropped to {$priceStr} (your alert: {$threshStr}).\n{$title}\n{$url}\n\nManage alerts: {$manageUrl}";

			\IPS\Email::buildFromContent( 'Price drop: ' . $title, $html, $plain )->send( $member );
			return true;
		}
		catch ( \Throwable )
		{
			return false;
		}
	}
}

class CheckPriceAlerts extends _CheckPriceAlerts {}
