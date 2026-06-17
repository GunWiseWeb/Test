<?php
namespace IPS\gddeals\modules\front\deals;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _view extends \IPS\Content\Controller
{
	public static bool $csrfProtected = TRUE;
	protected static string $contentModel = 'IPS\\gddeals\\Deal';

	protected function manage(): mixed
	{
		\IPS\Output::i()->cssFiles = array_merge( \IPS\Output::i()->cssFiles, \IPS\Theme::i()->css( 'deals.css', 'gddeals', 'front' ) );
		\IPS\Output::i()->jsFiles = array_merge( \IPS\Output::i()->jsFiles, \IPS\Output::i()->js( 'deals-vote.js', 'gddeals', 'interface' ) );

		try
		{
			$deal = \IPS\gddeals\Deal::loadAndCheckPerms( \IPS\Request::i()->id );
		}
		catch ( \OutOfRangeException $e )
		{
			\IPS\Output::i()->error( 'gddeals_deal_not_found', '2GD101/1', 404 );
			return null;
		}

		$heatLabel = $deal->heat_label ?: 'cold';
		$heatText  = $this->_heatLabel( $heatLabel );
		$score     = (int) $deal->upvotes - (int) $deal->downvotes;

		$userVote = 0;
		$me = \IPS\Member::loggedIn();
		if ( $me->member_id )
		{
			try
			{
				$row = \IPS\Db::i()->select( 'vote', 'gd_deal_votes', [ 'deal_id=? AND member_id=?', $deal->id, $me->member_id ] )->first();
				$userVote = (int) $row;
			}
			catch ( \UnderflowException ) {}
		}

		$d = [
			'id'             => $deal->id,
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
			'heat_score'     => $score,
			'heat_label'     => $heatLabel,
			'heat_text'      => $heatText,
			'vote_url'       => (string) $deal->url( 'vote' )->csrf(),
			'user_vote'      => $userVote,
		];

		$d['source'] = $deal->source_badge;
		$d['dealer_profile_url'] = '';
		if ( $deal->source_badge === 'dealer' && $deal->dealer_id && \IPS\Application::appIsEnabled( 'gddealer' ) )
		{
			try
			{
				$slug = \IPS\Db::i()->select( 'dealer_slug', 'gd_dealer_feed_config', [ 'dealer_id=?', (int) $deal->dealer_id ] )->first();
				$d['dealer_profile_url'] = (string) \IPS\Http\Url::internal(
					'app=gddealer&module=dealers&controller=profile&dealer_slug=' . urlencode( (string) $slug ),
					'front'
				);
			}
			catch ( \Throwable ) {}
		}

		$d['can_approve']   = ( $deal->hidden() !== 0 AND $deal->canUnhide( $me ) );
		$d['can_hide']      = ( $deal->hidden() === 0 AND $deal->canHide( $me ) );
		$d['can_delete']    = $deal->canDelete( $me );
		$d['can_edit']      = $deal->canEdit( $me );
		$d['approve_label'] = $me->language()->addToStack( ( $deal->hidden() === 1 ) ? 'gddeals_mod_approve' : 'gddeals_mod_restore' );
		$d['url_unhide']    = (string) $deal->url( 'moderate' )->setQueryString( 'action', 'unhide' )->csrf();
		$d['url_hide']      = (string) $deal->url( 'moderate' )->setQueryString( 'action', 'hide' )->csrf();
		$d['url_delete']    = (string) $deal->url( 'moderate' )->setQueryString( 'action', 'delete' )->csrf();
		$d['url_edit']      = (string) \IPS\Http\Url::internal( 'app=gddeals&module=deals&controller=submit&id=' . $deal->id, 'front', 'gddeals_submit' );

		$commentForm = \IPS\Member::loggedIn()->member_id ? $deal->commentForm() : NULL;

		$cPage    = max( 1, (int) ( \IPS\Request::i()->page ?? 1 ) );
		$cPerPage = 25;
		$cOffset  = ( $cPage - 1 ) * $cPerPage;
		$totalComments = (int) $deal->mapped( 'num_comments' );

		$comments = $deal->comments( $cPerPage, $cOffset, 'date', 'asc' );
		if ( !\is_array( $comments ) )
		{
			$comments = $comments ? [ $comments ] : [];
		}

		$commentsHtml = '';
		foreach ( $comments as $c )
		{
			$commentsHtml .= $c->html();
		}

		$commentPagination = '';
		if ( $totalComments > $cPerPage )
		{
			$commentPagination = (string) \IPS\Theme::i()->getTemplate( 'global', 'core', 'global' )->pagination(
				$deal->url(),
				(int) ceil( $totalComments / $cPerPage ),
				$cPage,
				$cPerPage
			);
		}

		$csrfKey = (string) \IPS\Session::i()->csrfKey;

		\IPS\Output::i()->title  = $deal->title;
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'deals', 'gddeals', 'front' )->view( $d, $commentsHtml, $commentForm, $commentPagination, $totalComments, $csrfKey );
		return null;
	}

	protected function _heatLabel( string $label ): string
	{
		return match ( $label ) {
			'warm' => \IPS\Member::loggedIn()->language()->addToStack( 'gddeals_heat_warm' ),
			'hot'  => \IPS\Member::loggedIn()->language()->addToStack( 'gddeals_heat_hot' ),
			'fire' => \IPS\Member::loggedIn()->language()->addToStack( 'gddeals_heat_fire' ),
			default => '',
		};
	}

	protected function vote(): void
	{
		if ( !\IPS\Request::i()->isAjax() )
		{
			\IPS\Output::i()->error( 'node_error', '2GD103/1', 403 );
			return;
		}

		$me = \IPS\Member::loggedIn();
		if ( !$me->member_id )
		{
			\IPS\Output::i()->json( [ 'error' => 'login' ], 403 );
			return;
		}

		try
		{
			$deal = \IPS\gddeals\Deal::loadAndCheckPerms( \IPS\Request::i()->id );
		}
		catch ( \OutOfRangeException )
		{
			\IPS\Output::i()->json( [ 'error' => 'not_found' ], 404 );
			return;
		}

		$dir = (string) \IPS\Request::i()->dir;
		$newVote = ( $dir === 'up' ) ? 1 : ( ( $dir === 'down' ) ? -1 : 0 );
		if ( $newVote === 0 )
		{
			\IPS\Output::i()->json( [ 'error' => 'invalid_dir' ], 400 );
			return;
		}

		$existing = 0;
		try
		{
			$row = \IPS\Db::i()->select( '*', 'gd_deal_votes', [ 'deal_id=? AND member_id=?', $deal->id, $me->member_id ] )->first();
			$existing = (int) $row['vote'];
		}
		catch ( \UnderflowException ) {}

		if ( $existing === $newVote )
		{
			\IPS\Db::i()->delete( 'gd_deal_votes', [ 'deal_id=? AND member_id=?', $deal->id, $me->member_id ] );
			$finalVote = 0;
		}
		elseif ( $existing !== 0 )
		{
			\IPS\Db::i()->update( 'gd_deal_votes', [ 'vote' => $newVote, 'voted_at' => time() ], [ 'deal_id=? AND member_id=?', $deal->id, $me->member_id ] );
			$finalVote = $newVote;
		}
		else
		{
			\IPS\Db::i()->insert( 'gd_deal_votes', [
				'deal_id'   => $deal->id,
				'member_id' => $me->member_id,
				'vote'      => $newVote,
				'voted_at'  => time(),
			] );
			$finalVote = $newVote;
		}

		$up   = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_deal_votes', [ 'deal_id=? AND vote=1', $deal->id ] )->first();
		$down = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_deal_votes', [ 'deal_id=? AND vote=-1', $deal->id ] )->first();
		$score = $up - $down;

		$heatLabel = 'cold';
		if ( $score >= 25 ) { $heatLabel = 'fire'; }
		elseif ( $score >= 10 ) { $heatLabel = 'hot'; }
		elseif ( $score >= 3 ) { $heatLabel = 'warm'; }

		$deal->upvotes    = $up;
		$deal->downvotes  = $down;
		$deal->heat_score = max( 0, min( 127, $score ) );
		$deal->heat_label = $heatLabel;
		$deal->save();

		\IPS\Output::i()->json( [
			'ok'         => TRUE,
			'score'      => $score,
			'userVote'   => $finalVote,
			'heat_label' => $heatLabel,
			'heat_text'  => $this->_heatLabel( $heatLabel ),
		] );
	}

}
class view extends _view {}
