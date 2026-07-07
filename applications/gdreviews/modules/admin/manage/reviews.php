<?php
/**
 * @brief  GD Reviews — ACP: reviews management list (v1.0.4).
 *
 * Direct admin table over gdreviews_reviews with row actions for
 * approve (pending → approved), hide / unhide, and delete. Companion
 * to IPS's native moderation / report queue — this is the
 * always-available admin surface for scanning + triage.
 *
 * Content is truncated in the list so wide review bodies don't
 * push the action buttons off the right edge (lesson from
 * gdcompliance apikeys v1.6.46). The truncated preview is
 * accompanied by a `title=` tooltip carrying the full text.
 */

namespace IPS\gdreviews\modules\admin\manage;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _reviews extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'reviews_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		$lang = \IPS\Member::loggedIn()->language();

		$counts = [ 'pending' => 0, 'approved' => 0, 'hidden' => 0, 'all' => 0 ];
		try
		{
			foreach ( \IPS\Db::i()->select(
				'review_approved, review_hidden, COUNT(*) AS n',
				'gdreviews_reviews', null, null, null,
				[ 'review_approved', 'review_hidden' ]
			) as $r )
			{
				$n = (int) ( $r['n'] ?? 0 );
				$counts['all'] += $n;
				if ( (int) $r['review_hidden'] === 1 )      { $counts['hidden']   += $n; }
				elseif ( (int) $r['review_approved'] === 1 ){ $counts['approved'] += $n; }
				else                                        { $counts['pending']  += $n; }
			}
		}
		catch ( \Throwable ) {}

		$intro = '<div class="ipsBox" style="margin-bottom:16px"><div class="ipsBox_body ipsPad">'
			. '<h2 class="ipsType_sectionHead" style="margin:0 0 8px">' . htmlspecialchars( (string) $lang->addToStack( 'gdreviews_acp_reviews_title' ), ENT_QUOTES, 'UTF-8' ) . '</h2>'
			. '<div style="display:flex;gap:16px;flex-wrap:wrap">'
			. '<div><strong>' . number_format( $counts['pending'] ) . '</strong> pending</div>'
			. '<div><strong>' . number_format( $counts['approved'] ) . '</strong> approved</div>'
			. '<div><strong>' . number_format( $counts['hidden'] ) . '</strong> hidden</div>'
			. '</div></div></div>';

		$baseUrl = \IPS\Http\Url::internal( 'app=gdreviews&module=manage&controller=reviews' );
		$table   = new \IPS\Helpers\Table\Db( 'gdreviews_reviews', $baseUrl );
		$table->langPrefix    = 'gdreviews_acp_reviews_col_';
		$table->include       = [ 'review_upc', 'review_author_name', 'review_rating', 'review_content', 'review_date', 'review_approved' ];
		$table->sortBy        = $table->sortBy ?: 'review_date';
		$table->sortDirection = $table->sortDirection ?: 'desc';

		$table->parsers = [
			'review_upc' => function( $v ) {
				$upc = (string) $v;
				if ( $upc === '' ) { return '<span style="color:#cbd5e1">—</span>'; }
				$title = '';
				try
				{
					$title = (string) \IPS\Db::i()->select(
						'product_title', 'gdreviews_products', [ 'product_upc=?', $upc ]
					)->first();
				}
				catch ( \Throwable ) {}
				$out = '<code style="font-family:ui-monospace,monospace;font-size:11px;background:#f1f5f9;padding:2px 6px;border-radius:4px">' . htmlspecialchars( $upc, ENT_QUOTES, 'UTF-8' ) . '</code>';
				if ( $title !== '' )
				{
					$short = mb_strlen( $title ) > 40 ? mb_substr( $title, 0, 40 ) . '…' : $title;
					$out .= '<div style="font-size:.85em;color:#475569;margin-top:2px">' . htmlspecialchars( $short, ENT_QUOTES, 'UTF-8' ) . '</div>';
				}
				return $out;
			},
			'review_author_name' => function( $v ) {
				return htmlspecialchars( (string) ( $v ?: 'Anonymous' ), ENT_QUOTES, 'UTF-8' );
			},
			'review_rating' => function( $v ) {
				$n = (int) $v;
				return '<span style="color:#f59e0b;letter-spacing:1px">'
					. str_repeat( '★', max( 0, min( 5, $n ) ) )
					. '</span> <span style="color:#94a3b8;font-size:.85em">(' . $n . ')</span>';
			},
			'review_content' => function( $v ) {
				$s = (string) ( $v ?? '' );
				if ( $s === '' ) { return '<span style="color:#cbd5e1">—</span>'; }
				$short = mb_strlen( $s ) > 90 ? mb_substr( $s, 0, 90 ) . '…' : $s;
				return '<span title="' . htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ) . '">'
					. htmlspecialchars( $short, ENT_QUOTES, 'UTF-8' ) . '</span>';
			},
			'review_date' => function( $v ) {
				return $v ? htmlspecialchars( date( 'Y-m-d H:i', (int) $v ), ENT_QUOTES, 'UTF-8' ) : '<span style="color:#cbd5e1">—</span>';
			},
			'review_approved' => function( $v, $row ) {
				if ( (int) ( $row['review_hidden'] ?? 0 ) === 1 )
				{
					return '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:700;text-transform:uppercase;background:#fef3c7;color:#78350f">Hidden</span>';
				}
				return (int) $v === 1
					? '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:700;text-transform:uppercase;background:#dcfce7;color:#14532d">Approved</span>'
					: '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:700;text-transform:uppercase;background:#fee2e2;color:#991b1b">Pending</span>';
			},
		];

		$table->rowButtons = function( $row ) {
			$base = 'app=gdreviews&module=manage&controller=reviews';
			$id   = (int) $row['review_id'];
			$btns = [];
			if ( (int) $row['review_approved'] === 0 && (int) $row['review_hidden'] === 0 )
			{
				$btns['approve'] = [
					'icon'  => 'check',
					'title' => 'gdreviews_acp_action_approve',
					'link'  => \IPS\Http\Url::internal( $base . '&do=approveAct&id=' . $id )->csrf(),
				];
			}
			if ( (int) $row['review_hidden'] === 1 )
			{
				$btns['unhide'] = [
					'icon'  => 'eye',
					'title' => 'gdreviews_acp_action_unhide',
					'link'  => \IPS\Http\Url::internal( $base . '&do=unhideAct&id=' . $id )->csrf(),
				];
			}
			else
			{
				$btns['hide'] = [
					'icon'  => 'eye-slash',
					'title' => 'gdreviews_acp_action_hide',
					'link'  => \IPS\Http\Url::internal( $base . '&do=hideAct&id=' . $id )->csrf(),
				];
			}
			$btns['delete'] = [
				'icon'  => 'times-circle',
				'title' => 'gdreviews_acp_action_delete',
				'link'  => \IPS\Http\Url::internal( $base . '&do=deleteAct&id=' . $id )->csrf(),
			];
			return $btns;
		};

		\IPS\Output::i()->title  = $lang->addToStack( 'menu__gdreviews_manage_reviews' );
		\IPS\Output::i()->output = $intro . (string) $table;
	}

	protected function approveAct(): void
	{
		\IPS\Session::i()->csrfCheck();
		$this->setFlags( [ 'review_approved' => 1, 'review_hidden' => 0 ] );
	}

	protected function hideAct(): void
	{
		\IPS\Session::i()->csrfCheck();
		$this->setFlags( [ 'review_hidden' => 1 ] );
	}

	protected function unhideAct(): void
	{
		\IPS\Session::i()->csrfCheck();
		$this->setFlags( [ 'review_hidden' => 0 ] );
	}

	protected function deleteAct(): void
	{
		\IPS\Session::i()->csrfCheck();
		$id = (int) ( \IPS\Request::i()->id ?? 0 );
		if ( $id <= 0 ) { $this->back(); return; }

		$upc = '';
		try
		{
			$upc = (string) \IPS\Db::i()->select( 'review_upc', 'gdreviews_reviews', [ 'review_id=?', $id ] )->first();
		}
		catch ( \Throwable ) {}

		try { \IPS\Db::i()->delete( 'gdreviews_reviews', [ 'review_id=?', $id ] ); }
		catch ( \Throwable ) {}

		if ( $upc !== '' )
		{
			try { \IPS\gdreviews\Product\Product::recomputeAggregate( $upc ); } catch ( \Throwable ) {}
		}

		$this->back();
	}

	private function setFlags( array $set ): void
	{
		$id = (int) ( \IPS\Request::i()->id ?? 0 );
		if ( $id <= 0 ) { $this->back(); return; }

		$upc = '';
		try
		{
			$upc = (string) \IPS\Db::i()->select( 'review_upc', 'gdreviews_reviews', [ 'review_id=?', $id ] )->first();
		}
		catch ( \Throwable ) {}

		try { \IPS\Db::i()->update( 'gdreviews_reviews', $set, [ 'review_id=?', $id ] ); }
		catch ( \Throwable ) {}

		if ( $upc !== '' )
		{
			try { \IPS\gdreviews\Product\Product::recomputeAggregate( $upc ); } catch ( \Throwable ) {}
		}

		$this->back();
	}

	private function back(): void
	{
		\IPS\Output::i()->redirect(
			(string) \IPS\Http\Url::internal( 'app=gdreviews&module=manage&controller=reviews' )
		);
	}
}

class reviews extends _reviews {}
