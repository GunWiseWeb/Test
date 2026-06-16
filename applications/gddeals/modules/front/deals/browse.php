<?php
namespace IPS\gddeals\modules\front\deals;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _browse extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		parent::execute();
	}

	protected function manage()
	{
		\IPS\Output::i()->cssFiles = array_merge( \IPS\Output::i()->cssFiles, \IPS\Theme::i()->css( 'deals.css', 'gddeals', 'front' ) );

		$catId   = (int) ( \IPS\Request::i()->category ?? 0 );
		$sort    = \IPS\Request::i()->sort ?? 'newest';
		$qf      = \IPS\Request::i()->qf ?? '';
		$page    = max( 1, (int) ( \IPS\Request::i()->page ?? 1 ) );
		$perPage = 24;
		$offset  = ( $page - 1 ) * $perPage;

		if ( !in_array( $sort, [ 'newest', 'discount', 'expiring' ], TRUE ) )
		{
			$sort = 'newest';
		}
		if ( !in_array( $qf, [ 'under500', 'today', '' ], TRUE ) )
		{
			$qf = '';
		}

		$where = [];
		$where[] = [ 'gd_deal_posts.expired=0 AND ( gd_deal_posts.expires_at IS NULL OR gd_deal_posts.expires_at > ? )', time() ];

		if ( $catId )
		{
			$where[] = [ 'gd_deal_posts.category_id=?', $catId ];
		}
		if ( $qf === 'under500' )
		{
			$where[] = [ 'gd_deal_posts.deal_price IS NOT NULL AND gd_deal_posts.deal_price < 500' ];
		}
		if ( $qf === 'today' )
		{
			$where[] = [ 'gd_deal_posts.posted_at >= ?', strtotime( 'today' ) ];
		}

		$order = match ( $sort ) {
			'discount' => 'gd_deal_posts.discount_pct DESC, gd_deal_posts.posted_at DESC',
			'expiring' => '( gd_deal_posts.expires_at IS NULL ) ASC, gd_deal_posts.expires_at ASC',
			default    => 'gd_deal_posts.posted_at DESC',
		};

		$total = (int) \IPS\gddeals\Deal::getItemsWithPermission( $where, NULL, NULL, 'read', \IPS\Content\Filter::FILTER_AUTOMATIC, 0, NULL, FALSE, FALSE, FALSE, TRUE );

		$rows = [];
		if ( $total > 0 )
		{
			$rows = \IPS\gddeals\Deal::getItemsWithPermission( $where, $order, [ $offset, $perPage ], 'read' );
		}

		$cards = [];
		foreach ( $rows as $deal )
		{
			$cards[] = [
				'url'       => (string) $deal->url(),
				'title'     => $deal->title,
				'category'  => $deal->container()->_title,
				'retailer'  => $deal->retailer_name,
				'price'     => $deal->deal_price !== NULL ? '$' . number_format( (float) $deal->deal_price, 2 ) : '',
				'original'  => $deal->original_price !== NULL ? '$' . number_format( (float) $deal->original_price, 2 ) : '',
				'discount'  => $deal->discount_pct ? rtrim( rtrim( number_format( (float) $deal->discount_pct, 1 ), '0' ), '.' ) : '',
				'promo'     => $deal->promo_code,
				'free_ship' => (bool) $deal->free_shipping,
				'posted'    => (string) \IPS\DateTime::ts( $deal->posted_at )->relative(),
				'author'    => $deal->author()->name,
				'source'    => $deal->source_badge,
				'expires'   => $deal->expires_at ? (string) \IPS\DateTime::ts( $deal->expires_at )->relative() : '',
			];
		}

		$cats = [];
		foreach ( \IPS\gddeals\Category::roots() as $c )
		{
			$cats[] = [
				'id'    => $c->_id,
				'title' => $c->_title,
				'url'   => (string) \IPS\Http\Url::internal( 'app=gddeals&module=deals&controller=browse', 'front' )->setQueryString( 'category', $c->_id ),
			];
		}

		$baseUrl = \IPS\Http\Url::internal( 'app=gddeals&module=deals&controller=browse', 'front' );
		if ( $catId )
		{
			$baseUrl = $baseUrl->setQueryString( 'category', $catId );
		}
		if ( $sort !== 'newest' )
		{
			$baseUrl = $baseUrl->setQueryString( 'sort', $sort );
		}
		if ( $qf )
		{
			$baseUrl = $baseUrl->setQueryString( 'qf', $qf );
		}

		$pages = (int) ceil( $total / $perPage );
		$pagination = '';
		if ( $pages > 1 )
		{
			$pagination = (string) \IPS\Theme::i()->getTemplate( 'global', 'core', 'global' )->pagination( $baseUrl, $pages, $page, $perPage );
		}

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gddeals_feed_title' );
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'deals', 'gddeals', 'front' )->browse( $cards, $cats, $catId, $sort, $qf, $pagination, $total );
	}
}
class browse extends _browse {}
