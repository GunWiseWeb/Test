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

		$me = \IPS\Member::loggedIn();
		$d['can_approve'] = ( $deal->hidden() === 1 AND $deal->canUnhide( $me ) );
		$d['can_hide']    = ( $deal->hidden() === 0 AND $deal->canHide( $me ) );
		$d['can_delete']  = $deal->canDelete( $me );
		$d['url_approve'] = (string) $deal->url( 'approve' )->csrf();
		$d['url_hide']    = (string) $deal->url( 'hide' )->csrf();
		$d['url_delete']  = (string) $deal->url( 'delete' )->csrf();

		$commentForm = $deal->commentForm();

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

		\IPS\Output::i()->title  = $deal->title;
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'deals', 'gddeals', 'front' )->view( $d, $commentsHtml, $commentForm, $commentPagination, $totalComments );
	}

	protected function approve(): void
	{
		\IPS\Session::i()->csrfCheck();
		try { $deal = \IPS\gddeals\Deal::loadAndCheckPerms( \IPS\Request::i()->id ); }
		catch ( \OutOfRangeException $e ) { \IPS\Output::i()->error( 'gddeals_deal_not_found', '2GD101/2', 404 ); return; }
		if ( !$deal->canUnhide() ) { \IPS\Output::i()->error( 'node_error', '2GD102/1', 403 ); return; }
		$deal->unhide( \IPS\Member::loggedIn() );
		\IPS\Output::i()->redirect( $deal->url() );
	}

	protected function hide(): void
	{
		\IPS\Session::i()->csrfCheck();
		try { $deal = \IPS\gddeals\Deal::loadAndCheckPerms( \IPS\Request::i()->id ); }
		catch ( \OutOfRangeException $e ) { \IPS\Output::i()->error( 'gddeals_deal_not_found', '2GD101/3', 404 ); return; }
		if ( !$deal->canHide() ) { \IPS\Output::i()->error( 'node_error', '2GD102/2', 403 ); return; }
		$deal->hide( \IPS\Member::loggedIn() );
		\IPS\Output::i()->redirect( $deal->url() );
	}

	protected function delete(): void
	{
		\IPS\Session::i()->csrfCheck();
		try { $deal = \IPS\gddeals\Deal::loadAndCheckPerms( \IPS\Request::i()->id ); }
		catch ( \OutOfRangeException $e ) { \IPS\Output::i()->error( 'gddeals_deal_not_found', '2GD101/4', 404 ); return; }
		if ( !$deal->canDelete() ) { \IPS\Output::i()->error( 'node_error', '2GD102/3', 403 ); return; }
		$deal->delete();
		\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gddeals&module=deals&controller=browse', 'front', 'gddeals_browse' ) );
	}
}
class view extends _view {}
