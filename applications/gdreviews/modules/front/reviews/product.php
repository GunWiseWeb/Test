<?php
/**
 * @brief  GD Reviews — Product review page controller (Stage 2 of 4).
 *
 * A standalone /product-reviews/product/{upc} page that renders the
 * product's title / image (READ-ONLY SELECT from gd_catalog), lists
 * approved reviews newest first, and offers a 1-5 star + text
 * submission form to logged-in members. If the member already has
 * a review for this UPC, the form is replaced with their existing
 * review + Edit / Delete actions (one review per member per product).
 *
 * Stage 3 pulls this display + submission into the gdsearch product
 * page as a tab; the standalone page remains for direct linking and
 * moderation-team access.
 *
 * HARD SAFETY — every read of gd_catalog here is a plain SELECT
 * bound to a single UPC. No column of gd_catalog is written by any
 * code path in this controller.
 */

namespace IPS\gdreviews\modules\front\reviews;

use IPS\gdreviews\Product\Product as ReviewProduct;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _product extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		parent::execute();
	}

	/**
	 * GET /product-reviews/product/{upc}
	 * Product header + review list + form (or edit/delete).
	 */
	protected function manage(): void
	{
		$upc = $this->currentUpc();
		if ( $upc === '' )
		{
			\IPS\Output::i()->error( 'gdreviews_missing_upc', '2GDRV/1', 400 );
			return;
		}

		$product = $this->loadCatalogProduct( $upc );
		if ( $product === null )
		{
			\IPS\Output::i()->error( 'gdreviews_product_not_found', '2GDRV/2', 404 );
			return;
		}

		/* Lazy-ensure shadow row so reviewing this UPC has somewhere
		   to attach — safe when nobody has reviewed yet. */
		ReviewProduct::loadByUpc( $upc, TRUE );

		$member    = \IPS\Member::loggedIn();
		$mine      = $member->member_id ? $this->loadMemberReview( $upc, (int) $member->member_id ) : null;
		$reviews   = $this->loadReviews( $upc );
		$aggregate = $this->loadAggregate( $upc );

		\IPS\Output::i()->title  = htmlspecialchars( (string) $product['title'], ENT_QUOTES, 'UTF-8' ) . ' — ' . (string) $member->language()->addToStack( 'gdreviews_reviews' );
		\IPS\Output::i()->output = $this->pageStyles() . $this->pageHtml( $upc, $product, $reviews, $aggregate, $member, $mine );
	}

	/**
	 * POST create — logged-in, one-per-UPC, CSRF-checked.
	 */
	protected function submitAct(): void
	{
		\IPS\Session::i()->csrfCheck();

		$member = \IPS\Member::loggedIn();
		if ( !$member->member_id )
		{
			\IPS\Output::i()->redirect( $this->loginUrl() );
			return;
		}

		$upc = $this->currentUpc();
		if ( $upc === '' )
		{
			\IPS\Output::i()->error( 'gdreviews_missing_upc', '2GDRV/3', 400 );
			return;
		}

		if ( $this->loadCatalogProduct( $upc ) === null )
		{
			\IPS\Output::i()->error( 'gdreviews_product_not_found', '2GDRV/4', 404 );
			return;
		}

		/* One per member per UPC — if they already have one, route
		   to edit rather than duplicate. */
		if ( $this->loadMemberReview( $upc, (int) $member->member_id ) !== null )
		{
			\IPS\Output::i()->redirect( $this->pageUrl( $upc ) );
			return;
		}

		[ $rating, $title, $content ] = $this->readForm();
		if ( $rating < 1 || $content === '' )
		{
			\IPS\Output::i()->redirect( $this->pageUrl( $upc, 'error' ) );
			return;
		}

		/* Ensure the shadow row exists BEFORE inserting the review
		   so review_item points at a real product_id. */
		$shadow = ReviewProduct::loadByUpc( $upc, TRUE );
		$itemId = $shadow ? (int) $shadow->id : 0;

		try
		{
			\IPS\Db::i()->insert( 'gdreviews_reviews', [
				'review_item'         => $itemId,
				'review_upc'          => $upc,
				'review_author'       => (int) $member->member_id,
				'review_author_name'  => (string) $member->name,
				'review_rating'       => $rating,
				'review_title'        => $title,
				'review_content'      => $content,
				'review_date'         => time(),
				'review_approved'     => 1,
				'review_hidden'       => 0,
				'review_votes_data'   => null,
				'review_votes_helpful'=> 0,
				'review_votes_total'  => 0,
				'review_ip_address'   => (string) ( \IPS\Request::i()->ipAddress() ?? '' ),
			] );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdreviews submitAct: ' . $e->getMessage(), 'gdreviews' ); } catch ( \Throwable ) {}
			\IPS\Output::i()->error( 'gdreviews_save_failed', '2GDRV/5', 500 );
			return;
		}

		ReviewProduct::recomputeAggregate( $upc );

		\IPS\Output::i()->redirect( $this->pageUrl( $upc ) );
	}

	/**
	 * POST edit — author-only, one-per-UPC, CSRF-checked.
	 */
	protected function editAct(): void
	{
		\IPS\Session::i()->csrfCheck();

		$member = \IPS\Member::loggedIn();
		if ( !$member->member_id )
		{
			\IPS\Output::i()->redirect( $this->loginUrl() );
			return;
		}

		$upc  = $this->currentUpc();
		$mine = $this->loadMemberReview( $upc, (int) $member->member_id );
		if ( $mine === null )
		{
			\IPS\Output::i()->redirect( $this->pageUrl( $upc ) );
			return;
		}

		[ $rating, $title, $content ] = $this->readForm();
		if ( $rating < 1 || $content === '' )
		{
			\IPS\Output::i()->redirect( $this->pageUrl( $upc, 'error' ) );
			return;
		}

		try
		{
			\IPS\Db::i()->update( 'gdreviews_reviews', [
				'review_rating'    => $rating,
				'review_title'     => $title,
				'review_content'   => $content,
				'review_edit_time' => time(),
				'review_edit_name' => (string) $member->name,
				'review_edit_show' => 1,
			], [ 'review_id=? AND review_author=?', (int) $mine['review_id'], (int) $member->member_id ] );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdreviews editAct: ' . $e->getMessage(), 'gdreviews' ); } catch ( \Throwable ) {}
		}

		ReviewProduct::recomputeAggregate( $upc );

		\IPS\Output::i()->redirect( $this->pageUrl( $upc ) );
	}

	/**
	 * POST delete — author-only, CSRF-checked.
	 */
	protected function deleteAct(): void
	{
		\IPS\Session::i()->csrfCheck();

		$member = \IPS\Member::loggedIn();
		if ( !$member->member_id )
		{
			\IPS\Output::i()->redirect( $this->loginUrl() );
			return;
		}

		$upc  = $this->currentUpc();
		$mine = $this->loadMemberReview( $upc, (int) $member->member_id );
		if ( $mine === null )
		{
			\IPS\Output::i()->redirect( $this->pageUrl( $upc ) );
			return;
		}

		try
		{
			\IPS\Db::i()->delete(
				'gdreviews_reviews',
				[ 'review_id=? AND review_author=?', (int) $mine['review_id'], (int) $member->member_id ]
			);
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdreviews deleteAct: ' . $e->getMessage(), 'gdreviews' ); } catch ( \Throwable ) {}
		}

		ReviewProduct::recomputeAggregate( $upc );

		\IPS\Output::i()->redirect( $this->pageUrl( $upc ) );
	}

	/* ============================================================
	 * Helpers
	 * ============================================================ */

	private function currentUpc(): string
	{
		return preg_replace( '/[^0-9a-zA-Z\-]/', '', (string) ( \IPS\Request::i()->upc ?? '' ) );
	}

	/** READ-ONLY SELECT against gd_catalog. Returns null when the UPC isn't in the catalog. */
	private function loadCatalogProduct( string $upc ): ?array
	{
		try
		{
			$row = \IPS\Db::i()->select(
				'upc, title, image_url',
				'gd_catalog',
				[ 'upc=?', $upc ]
			)->first();
			return is_array( $row ) ? $row : null;
		}
		catch ( \Throwable )
		{
			return null;
		}
	}

	private function loadMemberReview( string $upc, int $memberId ): ?array
	{
		if ( $upc === '' || $memberId <= 0 ) { return null; }
		try
		{
			return \IPS\Db::i()->select( '*', 'gdreviews_reviews',
				[ 'review_upc=? AND review_author=?', $upc, $memberId ] )->first();
		}
		catch ( \Throwable ) { return null; }
	}

	private function loadReviews( string $upc ): array
	{
		$rows = [];
		try
		{
			foreach ( \IPS\Db::i()->select( '*', 'gdreviews_reviews',
				[ 'review_upc=? AND review_approved=1 AND review_hidden=0', $upc ],
				'review_date DESC', 100 ) as $r )
			{
				$rows[] = $r;
			}
		}
		catch ( \Throwable ) {}
		return $rows;
	}

	private function loadAggregate( string $upc ): array
	{
		try
		{
			$row = \IPS\Db::i()->select(
				'product_review_count, product_rating_real',
				'gdreviews_products',
				[ 'product_upc=?', $upc ]
			)->first();
			return [
				'count' => (int)   ( $row['product_review_count'] ?? 0 ),
				'avg'   => (float) ( $row['product_rating_real']  ?? 0 ),
			];
		}
		catch ( \Throwable )
		{
			return [ 'count' => 0, 'avg' => 0.0 ];
		}
	}

	private function readForm(): array
	{
		$rating  = (int) ( \IPS\Request::i()->rating ?? 0 );
		if ( $rating < 1 ) { $rating = 0; }
		if ( $rating > 5 ) { $rating = 5; }
		$title   = trim( (string) ( \IPS\Request::i()->title ?? '' ) );
		if ( strlen( $title ) > 255 ) { $title = substr( $title, 0, 255 ); }
		$content = trim( (string) ( \IPS\Request::i()->content ?? '' ) );
		return [ $rating, $title, $content ];
	}

	private function pageUrl( string $upc, ?string $flash = null ): string
	{
		$url = \IPS\Http\Url::internal(
			'app=gdreviews&module=reviews&controller=product&upc=' . urlencode( $upc ),
			'front'
		);
		if ( $flash !== null ) { $url = $url->setQueryString( 'flash', $flash ); }
		return (string) $url;
	}

	private function loginUrl(): string
	{
		$self = \IPS\Http\Url::internal(
			'app=gdreviews&module=reviews&controller=product&upc=' . urlencode( $this->currentUpc() ),
			'front'
		);
		return (string) \IPS\Http\Url::internal( 'app=core&module=system&controller=login', 'front', 'login' )
			->setQueryString( 'ref', base64_encode( (string) $self ) );
	}

	private function pageStyles(): string
	{
		return '<style>
.gdrv-wrap{max-width:960px;margin:24px auto;padding:0 16px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#0f172a}
.gdrv-header{display:flex;gap:24px;align-items:flex-start;padding:20px;background:#fff;border:1px solid #e5e7eb;border-radius:10px;margin-bottom:16px}
.gdrv-header img{width:120px;height:120px;object-fit:contain;background:#f8fafc;border-radius:8px;border:1px solid #e5e7eb;flex-shrink:0}
.gdrv-title{font-size:1.4em;font-weight:700;margin:0 0 6px}
.gdrv-agg{color:#475569;font-size:1em}
.gdrv-agg strong{color:#f59e0b;font-size:1.15em}
.gdrv-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:20px;margin-bottom:14px}
.gdrv-card h2{margin:0 0 12px;font-size:1.1em;font-weight:700}
.gdrv-form label{display:block;font-weight:600;font-size:.9em;margin:12px 0 4px;color:#334155}
.gdrv-form input[type=text],.gdrv-form textarea{width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font:inherit;box-sizing:border-box}
.gdrv-form textarea{min-height:110px;resize:vertical}
.gdrv-stars{display:inline-flex;flex-direction:row-reverse;gap:2px;margin-top:2px}
.gdrv-stars input{position:absolute;opacity:0;pointer-events:none}
.gdrv-stars label{display:inline-block;padding:0 3px;font-size:1.7em;color:#e5e7eb;cursor:pointer;line-height:1;margin:0}
.gdrv-stars input:checked ~ label,.gdrv-stars label:hover,.gdrv-stars label:hover ~ label{color:#f59e0b}
.gdrv-btn{display:inline-block;padding:8px 16px;border-radius:6px;border:none;font-weight:600;cursor:pointer;font-size:.95em}
.gdrv-btn--primary{background:#0f172a;color:#fff}
.gdrv-btn--danger{background:#dc2626;color:#fff}
.gdrv-btn--muted{background:#f1f5f9;color:#0f172a}
.gdrv-btn+.gdrv-btn{margin-left:6px}
.gdrv-row{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;padding:14px 0;border-top:1px solid #f1f5f9}
.gdrv-row:first-child{border-top:none}
.gdrv-meta{color:#64748b;font-size:.85em;margin-top:4px}
.gdrv-rowStars{color:#f59e0b;font-size:1.05em;letter-spacing:1px}
.gdrv-empty{color:#64748b;font-style:italic}
.gdrv-flash-error{padding:10px 14px;background:#fef2f2;color:#991b1b;border:1px solid #fecaca;border-radius:6px;margin-bottom:14px}
</style>';
	}

	private function pageHtml( string $upc, array $product, array $reviews, array $aggregate, \IPS\Member $member, ?array $mine ): string
	{
		$esc  = fn( string $s ) => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
		$lang = $member->language();
		$L    = fn( string $k ) => $esc( (string) $lang->addToStack( $k ) );

		$img = trim( (string) ( $product['image_url'] ?? '' ) );

		$out = '<div class="gdrv-wrap">';

		if ( (string) ( \IPS\Request::i()->flash ?? '' ) === 'error' )
		{
			$out .= '<div class="gdrv-flash-error">' . $L( 'gdreviews_form_error' ) . '</div>';
		}

		/* Product header */
		$out .= '<div class="gdrv-header">';
		if ( $img !== '' )
		{
			$out .= '<img src="' . $esc( $img ) . '" alt="">';
		}
		$out .= '<div>';
		$out .= '<h1 class="gdrv-title">' . $esc( (string) $product['title'] ) . '</h1>';
		$out .= '<div class="gdrv-agg">';
		if ( $aggregate['count'] > 0 )
		{
			$out .= '<strong>' . number_format( $aggregate['avg'], 1 ) . ' ★</strong> '
				. sprintf( (string) $lang->addToStack( 'gdreviews_agg_fmt' ), $aggregate['count'] );
		}
		else
		{
			$out .= $L( 'gdreviews_no_reviews' );
		}
		$out .= '</div>';
		$out .= '<div class="gdrv-meta">UPC: ' . $esc( $upc ) . '</div>';
		$out .= '</div></div>';

		/* Submission / mine */
		$out .= '<div class="gdrv-card">';
		if ( !$member->member_id )
		{
			$out .= '<h2>' . $L( 'gdreviews_write_review' ) . '</h2>';
			$out .= '<p>' . $L( 'gdreviews_login_to_review' ) . ' '
				. '<a class="gdrv-btn gdrv-btn--primary" href="' . $esc( $this->loginUrl() ) . '">'
				. $L( 'gdreviews_login' ) . '</a></p>';
		}
		elseif ( $mine !== null )
		{
			$out .= $this->renderMineForm( $upc, $mine, $L, $esc );
		}
		else
		{
			$out .= $this->renderCreateForm( $upc, $L, $esc );
		}
		$out .= '</div>';

		/* Reviews list */
		$out .= '<div class="gdrv-card">';
		$out .= '<h2>' . $L( 'gdreviews_reviews' ) . ' (' . $aggregate['count'] . ')</h2>';
		if ( empty( $reviews ) )
		{
			$out .= '<div class="gdrv-empty">' . $L( 'gdreviews_no_reviews' ) . '</div>';
		}
		else
		{
			foreach ( $reviews as $r )
			{
				$out .= $this->renderReviewRow( $r, $esc );
			}
		}
		$out .= '</div>';

		$out .= '</div>';
		return $out;
	}

	private function renderCreateForm( string $upc, callable $L, callable $esc ): string
	{
		$action = (string) \IPS\Http\Url::internal(
			'app=gdreviews&module=reviews&controller=product&do=submitAct&upc=' . urlencode( $upc ),
			'front'
		);
		return '<h2>' . $L( 'gdreviews_write_review' ) . '</h2>'
			. '<form class="gdrv-form" method="post" action="' . $esc( $action ) . '">'
			. '<input type="hidden" name="csrfKey" value="' . $esc( (string) \IPS\Session::i()->csrfKey ) . '">'
			. '<label>' . $L( 'gdreviews_rating' ) . '</label>'
			. $this->renderStars( 0 )
			. '<label for="gdrv-title">' . $L( 'gdreviews_field_title' ) . '</label>'
			. '<input id="gdrv-title" type="text" name="title" maxlength="255" placeholder="' . $L( 'gdreviews_field_title_ph' ) . '">'
			. '<label for="gdrv-content">' . $L( 'gdreviews_field_content' ) . '</label>'
			. '<textarea id="gdrv-content" name="content" required></textarea>'
			. '<div style="margin-top:14px">'
			. '<button type="submit" class="gdrv-btn gdrv-btn--primary">' . $L( 'gdreviews_submit' ) . '</button>'
			. '</div>'
			. '</form>';
	}

	private function renderMineForm( string $upc, array $mine, callable $L, callable $esc ): string
	{
		$editUrl   = (string) \IPS\Http\Url::internal(
			'app=gdreviews&module=reviews&controller=product&do=editAct&upc=' . urlencode( $upc ),
			'front'
		);
		$deleteUrl = (string) \IPS\Http\Url::internal(
			'app=gdreviews&module=reviews&controller=product&do=deleteAct&upc=' . urlencode( $upc ),
			'front'
		);
		$rating = (int) ( $mine['review_rating'] ?? 0 );
		return '<h2>' . $L( 'gdreviews_your_review' ) . '</h2>'
			. '<form class="gdrv-form" method="post" action="' . $esc( $editUrl ) . '">'
			. '<input type="hidden" name="csrfKey" value="' . $esc( (string) \IPS\Session::i()->csrfKey ) . '">'
			. '<label>' . $L( 'gdreviews_rating' ) . '</label>'
			. $this->renderStars( $rating )
			. '<label for="gdrv-title">' . $L( 'gdreviews_field_title' ) . '</label>'
			. '<input id="gdrv-title" type="text" name="title" maxlength="255" value="' . $esc( (string) ( $mine['review_title'] ?? '' ) ) . '">'
			. '<label for="gdrv-content">' . $L( 'gdreviews_field_content' ) . '</label>'
			. '<textarea id="gdrv-content" name="content" required>' . $esc( (string) ( $mine['review_content'] ?? '' ) ) . '</textarea>'
			. '<div style="margin-top:14px">'
			. '<button type="submit" class="gdrv-btn gdrv-btn--primary">' . $L( 'gdreviews_save' ) . '</button>'
			. '</div>'
			. '</form>'
			. '<form method="post" action="' . $esc( $deleteUrl ) . '" style="margin-top:10px" onsubmit="return confirm(\'' . $L( 'gdreviews_delete_confirm' ) . '\')">'
			. '<input type="hidden" name="csrfKey" value="' . $esc( (string) \IPS\Session::i()->csrfKey ) . '">'
			. '<button type="submit" class="gdrv-btn gdrv-btn--danger">' . $L( 'gdreviews_delete' ) . '</button>'
			. '</form>';
	}

	private function renderStars( int $current ): string
	{
		/* Reverse-order labels so pure-CSS `~` hover highlights work
		   left-to-right. The rendered value 5..1 with checked state
		   points at the current rating. */
		$out = '<div class="gdrv-stars">';
		for ( $i = 5; $i >= 1; $i-- )
		{
			$id = 'gdrv-s' . $i;
			$checked = ( $i === $current ) ? ' checked' : '';
			$out .= '<input type="radio" id="' . $id . '" name="rating" value="' . $i . '"' . $checked . ' required>'
				. '<label for="' . $id . '" title="' . $i . '">★</label>';
		}
		$out .= '</div>';
		return $out;
	}

	private function renderReviewRow( array $r, callable $esc ): string
	{
		$stars   = (int) ( $r['review_rating'] ?? 0 );
		$starsStr = str_repeat( '★', $stars ) . str_repeat( '☆', 5 - $stars );
		$name    = (string) ( $r['review_author_name'] ?? 'Anonymous' );
		if ( $name === '' ) { $name = 'Anonymous'; }
		$date    = (int) ( $r['review_date'] ?? 0 );
		$dateStr = $date > 0 ? (string) \IPS\DateTime::ts( $date )->format( 'M j, Y' ) : '';
		$title   = (string) ( $r['review_title'] ?? '' );
		$content = (string) ( $r['review_content'] ?? '' );

		$html  = '<div class="gdrv-row"><div style="flex:1">';
		$html .= '<div class="gdrv-rowStars">' . $starsStr . '</div>';
		if ( $title !== '' )
		{
			$html .= '<div style="font-weight:700;margin-top:4px">' . $esc( $title ) . '</div>';
		}
		$html .= '<div style="margin-top:6px;white-space:pre-wrap">' . nl2br( $esc( $content ) ) . '</div>';
		$html .= '<div class="gdrv-meta">' . $esc( $name ) . ' · ' . $esc( $dateStr );
		if ( !empty( $r['review_edit_time'] ) && (int) $r['review_edit_show'] === 1 )
		{
			$html .= ' · edited ' . $esc( (string) \IPS\DateTime::ts( (int) $r['review_edit_time'] )->format( 'M j, Y' ) );
		}
		$html .= '</div>';
		$html .= '</div></div>';
		return $html;
	}
}

class product extends _product {}
