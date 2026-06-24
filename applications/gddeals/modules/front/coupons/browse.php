<?php
namespace IPS\gddeals\modules\front\coupons;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _browse extends \IPS\Dispatcher\Controller
{
	use \IPS\gddeals\Coupons\CouponBuilderTrait;

	protected function manage()
	{
		\IPS\Output::i()->cssFiles = array_merge( \IPS\Output::i()->cssFiles, \IPS\Theme::i()->css( 'deals.css', 'gddeals', 'front' ) );
		\IPS\Output::i()->jsFiles = array_merge( \IPS\Output::i()->jsFiles, \IPS\Output::i()->js( 'deals-vote.js', 'gddeals', 'interface' ) );

		$catId   = (int) ( \IPS\Request::i()->category ?? 0 );
		$sort    = \IPS\Request::i()->sort ?? 'newest';
		$page    = max( 1, (int) ( \IPS\Request::i()->page ?? 1 ) );
		$perPage = 24;
		$offset  = ( $page - 1 ) * $perPage;

		if ( !in_array( $sort, [ 'newest', 'discount', 'expiring', 'hottest' ], TRUE ) )
		{
			$sort = 'newest';
		}

		$where = [];
		$where[] = [ 'gd_deal_posts.expired=0 AND ( gd_deal_posts.expires_at IS NULL OR gd_deal_posts.expires_at > ? )', time() ];
		$where[] = [ "gd_deal_posts.post_type='coupon'" ];

		if ( $catId )
		{
			$where[] = [ 'gd_deal_posts.category_id=?', $catId ];
		}

		$baseOrder = match ( $sort ) {
			'discount' => 'gd_deal_posts.discount_pct DESC, gd_deal_posts.posted_at DESC',
			'expiring' => '( gd_deal_posts.expires_at IS NULL ) ASC, gd_deal_posts.expires_at ASC',
			'hottest'  => 'gd_deal_posts.heat_score DESC, gd_deal_posts.posted_at DESC',
			default    => 'gd_deal_posts.posted_at DESC',
		};
		$order = "( gd_deal_posts.source_badge = 'dealer' ) DESC, " . $baseOrder;

		$total = (int) \IPS\gddeals\Deal::getItemsWithPermission( $where, NULL, NULL, 'read', \IPS\Content\Filter::FILTER_AUTOMATIC, 0, NULL, FALSE, FALSE, FALSE, TRUE );

		$deals = [];
		if ( $total > 0 )
		{
			foreach ( \IPS\gddeals\Deal::getItemsWithPermission( $where, $order, [ $offset, $perPage ], 'read' ) as $deal )
			{
				$deals[] = $deal;
			}
		}

		$coupons = $this->buildCouponCards( $deals );

		$cats = [];
		foreach ( \IPS\gddeals\Category::roots() as $c )
		{
			$cats[] = [
				'id'    => $c->_id,
				'title' => $c->_title,
				'url'   => (string) \IPS\Http\Url::internal( 'app=gddeals&module=coupons&controller=browse', 'front' )->setQueryString( 'category', $c->_id ),
			];
		}

		$baseUrl = \IPS\Http\Url::internal( 'app=gddeals&module=coupons&controller=browse', 'front' );
		if ( $catId )
		{
			$baseUrl = $baseUrl->setQueryString( 'category', $catId );
		}
		if ( $sort !== 'newest' )
		{
			$baseUrl = $baseUrl->setQueryString( 'sort', $sort );
		}

		$pages = (int) ceil( $total / $perPage );
		$pagination = '';
		if ( $pages > 1 )
		{
			$pagination = (string) \IPS\Theme::i()->getTemplate( 'global', 'core', 'global' )->pagination( $baseUrl, $pages, $page, $perPage );
		}

		$sortBase = \IPS\Http\Url::internal( 'app=gddeals&module=coupons&controller=browse', 'front' );
		if ( $catId )
		{
			$sortBase = $sortBase->setQueryString( 'category', $catId );
		}
		$sortUrls = [
			'newest'   => (string) $sortBase->setQueryString( 'sort', 'newest' ),
			'hottest'  => (string) $sortBase->setQueryString( 'sort', 'hottest' ),
			'discount' => (string) $sortBase->setQueryString( 'sort', 'discount' ),
			'expiring' => (string) $sortBase->setQueryString( 'sort', 'expiring' ),
		];

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gddeals_coupons_page_title' );
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'coupons', 'gddeals', 'front' )->browse( $coupons, $cats, $catId, $sort, $pagination, $total, $sortUrls );
	}
}
class browse extends _browse {}
