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
		\IPS\Output::i()->jsFiles = array_merge( \IPS\Output::i()->jsFiles, \IPS\Output::i()->js( 'deals-vote.js', 'gddeals', 'interface' ) );

		$catId   = (int) ( \IPS\Request::i()->category ?? 0 );
		$sort    = \IPS\Request::i()->sort ?? 'newest';
		$qf      = \IPS\Request::i()->qf ?? '';
		$page    = max( 1, (int) ( \IPS\Request::i()->page ?? 1 ) );
		$perPage = 24;
		$offset  = ( $page - 1 ) * $perPage;

		if ( !in_array( $sort, [ 'newest', 'discount', 'expiring', 'hottest' ], TRUE ) )
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
			'hottest'  => 'gd_deal_posts.heat_score DESC, gd_deal_posts.posted_at DESC',
			default    => 'gd_deal_posts.posted_at DESC',
		};

		$total = (int) \IPS\gddeals\Deal::getItemsWithPermission( $where, NULL, NULL, 'read', \IPS\Content\Filter::FILTER_AUTOMATIC, 0, NULL, FALSE, FALSE, FALSE, TRUE );

		$rows = [];
		if ( $total > 0 )
		{
			$rows = \IPS\gddeals\Deal::getItemsWithPermission( $where, $order, [ $offset, $perPage ], 'read' );
		}

		$dealIds = [];
		$dealList = [];
		foreach ( $rows as $deal )
		{
			$dealIds[] = $deal->id;
			$dealList[] = $deal;
		}

		$myVotes = [];
		$me = \IPS\Member::loggedIn();
		if ( $me->member_id && !empty( $dealIds ) )
		{
			try
			{
				foreach ( \IPS\Db::i()->select( 'deal_id, vote', 'gd_deal_votes', [ 'member_id=? AND ' . \IPS\Db::i()->in( 'deal_id', $dealIds ), $me->member_id ] ) as $vr )
				{
					$myVotes[ (int) $vr['deal_id'] ] = (int) $vr['vote'];
				}
			}
			catch ( \Throwable ) {}
		}

		$dealerSlugMap = [];
		if ( \IPS\Application::appIsEnabled( 'gddealer' ) )
		{
			$dIds = [];
			foreach ( $dealList as $dl )
			{
				if ( $dl->source_badge === 'dealer' && $dl->dealer_id )
				{
					$dIds[] = (int) $dl->dealer_id;
				}
			}
			if ( !empty( $dIds ) )
			{
				try
				{
					foreach ( \IPS\Db::i()->select( 'dealer_id, dealer_slug', 'gd_dealer_feed_config', \IPS\Db::i()->in( 'dealer_id', $dIds ) ) as $dr )
					{
						$dealerSlugMap[ (int) $dr['dealer_id'] ] = (string) $dr['dealer_slug'];
					}
				}
				catch ( \Throwable ) {}
			}
		}

		$cards = [];
		foreach ( $dealList as $deal )
		{
			$hl = $deal->heat_label ?: 'cold';
			$dealerProfileUrl = '';
			if ( $deal->source_badge === 'dealer' && $deal->dealer_id && isset( $dealerSlugMap[ (int) $deal->dealer_id ] ) )
			{
				$dealerProfileUrl = (string) \IPS\Http\Url::internal(
					'app=gddealer&module=dealers&controller=profile&dealer_slug=' . urlencode( $dealerSlugMap[ (int) $deal->dealer_id ] ),
					'front'
				);
			}
			$cards[] = [
				'url'        => (string) $deal->url(),
				'title'      => $deal->title,
				'category'   => $deal->container()->_title,
				'retailer'   => $deal->retailer_name,
				'price'      => $deal->deal_price !== NULL ? '$' . number_format( (float) $deal->deal_price, 2 ) : '',
				'original'   => $deal->original_price !== NULL ? '$' . number_format( (float) $deal->original_price, 2 ) : '',
				'discount'   => $deal->discount_pct ? rtrim( rtrim( number_format( (float) $deal->discount_pct, 1 ), '0' ), '.' ) : '',
				'promo'      => $deal->promo_code,
				'free_ship'  => (bool) $deal->free_shipping,
				'posted'     => (string) \IPS\DateTime::ts( $deal->posted_at )->relative(),
				'author'     => $deal->author()->name,
				'source'              => $deal->source_badge,
				'dealer_profile_url'  => $dealerProfileUrl,
				'image'      => $deal->image_url ?: '',
				'expires'    => $deal->expires_at ? (string) \IPS\DateTime::ts( $deal->expires_at )->relative() : '',
				'heat_score' => (int) $deal->upvotes - (int) $deal->downvotes,
				'heat_label' => $hl,
				'heat_text'  => $this->_heatText( $hl ),
				'vote_url'   => (string) $deal->url( 'vote' )->csrf(),
				'user_vote'  => $myVotes[ $deal->id ] ?? 0,
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

		$sortBase = \IPS\Http\Url::internal( 'app=gddeals&module=deals&controller=browse', 'front' );
		if ( $catId )
		{
			$sortBase = $sortBase->setQueryString( 'category', $catId );
		}
		if ( $qf )
		{
			$sortBase = $sortBase->setQueryString( 'qf', $qf );
		}
		$sortUrls = [
			'newest'   => (string) $sortBase->setQueryString( 'sort', 'newest' ),
			'hottest'  => (string) $sortBase->setQueryString( 'sort', 'hottest' ),
			'discount' => (string) $sortBase->setQueryString( 'sort', 'discount' ),
			'expiring' => (string) $sortBase->setQueryString( 'sort', 'expiring' ),
		];

		$qfBase = \IPS\Http\Url::internal( 'app=gddeals&module=deals&controller=browse', 'front' );
		if ( $catId )
		{
			$qfBase = $qfBase->setQueryString( 'category', $catId );
		}
		if ( $sort !== 'newest' )
		{
			$qfBase = $qfBase->setQueryString( 'sort', $sort );
		}
		$qfUrls = [
			'under500' => (string) $qfBase->setQueryString( 'qf', 'under500' ),
			'today'    => (string) $qfBase->setQueryString( 'qf', 'today' ),
		];

		$csrfKey = (string) \IPS\Session::i()->csrfKey;

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gddeals_feed_title' );
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'deals', 'gddeals', 'front' )->browse( $cards, $cats, $catId, $sort, $qf, $pagination, $total, $sortUrls, $qfUrls, $csrfKey );
	}

	protected function _heatText( string $label ): string
	{
		return match ( $label ) {
			'warm' => \IPS\Member::loggedIn()->language()->addToStack( 'gddeals_heat_warm' ),
			'hot'  => \IPS\Member::loggedIn()->language()->addToStack( 'gddeals_heat_hot' ),
			'fire' => \IPS\Member::loggedIn()->language()->addToStack( 'gddeals_heat_fire' ),
			default => '',
		};
	}
}
class browse extends _browse {}
