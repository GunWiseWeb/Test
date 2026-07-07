<?php
/**
 * @brief  GD Reviews — Product Content Item (Stage 1 of 4).
 *
 * A thin IPS Content Item that represents a product open for reviews,
 * keyed by catalog UPC and backed by the gdreviews_products shadow
 * table (which we own). The product's display data (title, image,
 * category) is read live from gd_catalog by UPC on demand — never
 * written. gd_catalog is READ-ONLY forever from this app.
 *
 * Why a shadow table:
 *   IPS's Content Item + Review pattern (mirroring Downloads and
 *   Forums) requires the Item to be backed by its own ActiveRecord
 *   table so reviews can FK to a stable numeric id. We can't add
 *   a review-relation to gd_catalog. So gdreviews_products is a
 *   lightweight join row: (product_id PK, product_upc UNIQUE) —
 *   nothing more than an id that maps to a UPC. The row is created
 *   lazily on first-review-write via loadByUpc(). Product title /
 *   image continue to come from gd_catalog at render time.
 *
 * STAGE 1 SCOPE — class is defined and the shadow table exists,
 * but the Item is NOT yet registered via ContentRouter. Stage 2
 * adds the review-submission form + registers the class as content
 * so it participates in moderation / streams / activity.
 */

namespace IPS\gdreviews\Product;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Product extends \IPS\Content\Item
{
	/* v1.0.2 — reviewability is wired via the $reviewClass static
	   property below (the pattern IPS's Downloads app uses on
	   \IPS\downloads\File). IPS 5.0.18 has no such trait; the
	   v1.0.1 `use` line for that phantom trait fatalled every
	   page hit with "Trait ... not found." Ratings, helpful-votes,
	   and moderation are wired through the review class named
	   here — not through a trait. */

	public static ?string $databaseTable   = 'gdreviews_products';
	public static string  $databaseColumnId = 'product_id';
	public static string  $databasePrefix   = 'product_';

	public static string $application = 'gdreviews';
	public static string $module      = 'reviews';

	public static array $databaseColumnMap = [
		'author'    => 'last_review_by',
		'date'      => 'date',
		'title'     => 'title',
		'num_reviews' => 'review_count',
		'last_review'      => 'last_review',
		'last_review_by'   => 'last_review_by',
		'last_review_name' => 'last_review_name',
		'rating'   => 'rating',
	];

	public static string $title = 'gdreviews_product';
	public static string $formLangPrefix = 'gdreviews_review_';

	public static ?string $reviewClass = 'IPS\gdreviews\Product\Review';

	/**
	 * Load or lazily create a Product row for a given UPC. This is
	 * how callers turn a UPC (the natural key that gdsearch and
	 * dealer listings use) into an IPS Content Item. The row is
	 * a thin shadow — no product data lives here beyond a cached
	 * title snapshot. Product title / image come from gd_catalog
	 * (READ-ONLY) at render time.
	 *
	 * Stage 2 uses this from the review-submission form; Stage 3
	 * uses it from the gdsearch product-page tab to render the
	 * review thread.
	 */
	public static function loadByUpc( string $upc, bool $createIfMissing = false ): ?self
	{
		$upc = trim( $upc );
		if ( $upc === '' ) { return null; }

		try
		{
			$row = \IPS\Db::i()->select( '*', 'gdreviews_products', [ 'product_upc=?', $upc ] )->first();
			return static::constructFromData( $row );
		}
		catch ( \UnderflowException )
		{
			if ( !$createIfMissing ) { return null; }
		}

		/* Lazy create — pull the title snapshot from gd_catalog
		   (READ-ONLY SELECT, never write). */
		$title = '';
		try
		{
			$title = (string) \IPS\Db::i()->select( 'title', 'gd_catalog', [ 'upc=?', $upc ] )->first();
		}
		catch ( \Throwable ) {}

		try
		{
			$id = (int) \IPS\Db::i()->insert( 'gdreviews_products', [
				'product_upc'          => $upc,
				'product_title'        => ( $title !== '' ) ? $title : null,
				'product_review_count' => 0,
				'product_rating_real'  => 0.00,
				'product_rating_hits'  => 0,
				'product_rating'       => null,
				'product_date'         => time(),
				'product_last_review'  => 0,
				'product_last_review_by'   => 0,
				'product_last_review_name' => null,
			] );
			return static::load( $id );
		}
		catch ( \Throwable )
		{
			return null;
		}
	}

	/**
	 * URL to this product's review thread. Stage 3 wires the actual
	 * route; for now returns the internal query-string form so any
	 * caller Stage 1 already writes at least gets a valid URL back
	 * instead of null.
	 */
	public function url( ?string $action = null ): \IPS\Http\Url
	{
		$url = \IPS\Http\Url::internal(
			'app=gdreviews&module=reviews&controller=product&upc=' . urlencode( (string) $this->upc ),
			'front'
		);
		if ( $action ) { $url = $url->setQueryString( 'do', $action ); }
		return $url;
	}

	/**
	 * v1.0.3 — permission methods aligned to IPS 5.0.18's exact
	 * contract on \IPS\Content\Item: only canCreate() is static;
	 * canView/canEdit/canDelete/canEditTitle/canViewReports are
	 * INSTANCE methods. v1.0.2's static canView() fatalled at
	 * class-compile with "Cannot make non static method canView()
	 * static." Signatures below match core / \IPS\downloads\File.
	 */

	public static function canCreate( \IPS\Member $member, ?\IPS\Node\Model $container = null, bool $showError = false ): bool
	{
		/* Products are lazily created by the review-submission flow;
		   an end user does not "create a product." Stage 2 wires the
		   real check via the review form. */
		return false;
	}

	public function canView( ?\IPS\Member $member = null ): bool
	{
		return true;
	}

	/**
	 * v1.0.1 — recompute review_count + rating_avg on the shadow row
	 * from the current set of approved, non-hidden reviews. Called
	 * after every create / edit / delete so the aggregate the gdsearch
	 * product-page tab (Stage 3) reads is always fresh.
	 *
	 * Idempotent — safe to call redundantly. If the shadow row is
	 * missing (e.g. every review has been deleted and no row was
	 * lazily created), the call is a no-op.
	 */
	public static function recomputeAggregate( string $upc ): void
	{
		$upc = trim( $upc );
		if ( $upc === '' ) { return; }

		try
		{
			$row = \IPS\Db::i()->select(
				'COUNT(*) AS n, COALESCE(AVG(review_rating),0) AS avg, MAX(review_date) AS last_ts',
				'gdreviews_reviews',
				[ 'review_upc=? AND review_approved=1 AND review_hidden=0', $upc ]
			)->first();
		}
		catch ( \Throwable )
		{
			return;
		}

		$count  = (int)   ( $row['n']       ?? 0 );
		$avg    = (float) ( $row['avg']     ?? 0 );
		$lastTs = (int)   ( $row['last_ts'] ?? 0 );

		$lastBy   = 0;
		$lastName = null;
		if ( $count > 0 )
		{
			try
			{
				$latest = \IPS\Db::i()->select(
					'review_author, review_author_name',
					'gdreviews_reviews',
					[ 'review_upc=? AND review_approved=1 AND review_hidden=0', $upc ],
					'review_date DESC',
					1
				)->first();
				$lastBy   = (int)    ( $latest['review_author']      ?? 0 );
				$lastName = (string) ( $latest['review_author_name'] ?? '' );
				if ( $lastName === '' ) { $lastName = null; }
			}
			catch ( \Throwable ) {}
		}

		try
		{
			\IPS\Db::i()->update( 'gdreviews_products', [
				'product_review_count'     => $count,
				'product_rating_real'      => round( $avg, 2 ),
				'product_rating_hits'      => $count,
				'product_rating'           => $count > 0 ? (int) round( $avg ) : null,
				'product_last_review'      => $lastTs,
				'product_last_review_by'   => $lastBy,
				'product_last_review_name' => $lastName,
			], [ 'product_upc=?', $upc ] );
		}
		catch ( \Throwable ) {}
	}

	/**
	 * v1.0.5 — aggregate accessor for external callers (gdsearch's
	 * product-page tab badge reads this to render "Reviews (N)").
	 * Returns { count, rating } — rating is null when there are
	 * no approved+visible reviews, so the caller can render a
	 * neutral "Reviews" label instead of "0.0 ★".
	 */
	public static function aggregate( string $upc ): array
	{
		$upc = trim( $upc );
		if ( $upc === '' ) { return [ 'count' => 0, 'rating' => null ]; }

		try
		{
			$row = \IPS\Db::i()->select(
				'product_review_count, product_rating_real',
				'gdreviews_products',
				[ 'product_upc=?', $upc ]
			)->first();

			$count = (int) ( $row['product_review_count'] ?? 0 );
			return [
				'count'  => $count,
				'rating' => $count > 0 ? (float) $row['product_rating_real'] : null,
			];
		}
		catch ( \Throwable )
		{
			return [ 'count' => 0, 'rating' => null ];
		}
	}

	/**
	 * v1.0.5 — reusable review-section renderer. Returns the full
	 * reviews-section HTML for a product UPC: scoped CSS, submit
	 * area (create / mine / login / group-restricted), and the
	 * approved-reviews list. Called by both the gdreviews standalone
	 * page (modules/front/reviews/product.php::manage) AND gdsearch's
	 * product-page Reviews tab so the two surfaces stay identical.
	 *
	 *   $upc        — UPC of the product (mandatory)
	 *   $member     — viewer; defaults to \IPS\Member::loggedIn()
	 *   $flash      — optional flash token ('error' | 'pending')
	 *                  to render an inline notice
	 *   $returnUrl  — optional absolute URL the submit / edit /
	 *                  delete flow should return to. Empty = the
	 *                  gdreviews standalone page. gdsearch's tab
	 *                  passes its product page here so submitting
	 *                  from the tab lands back on the tab.
	 *
	 * gd_catalog is NOT touched by this method. Product headline
	 * data (title / image) is the caller's responsibility.
	 */
	public static function renderSection( string $upc, ?\IPS\Member $member = null, string $flash = '', string $returnUrl = '' ): string
	{
		$upc = trim( $upc );
		if ( $upc === '' ) { return ''; }

		if ( $member === null ) { $member = \IPS\Member::loggedIn(); }

		$esc  = fn( string $s ) => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
		$lang = $member->language();
		$L    = fn( string $k ) => $esc( (string) $lang->addToStack( $k ) );

		$gate      = self::sectionGate( $member );
		$aggregate = self::aggregate( $upc );
		$mine      = $member->member_id ? self::sectionMine( $upc, (int) $member->member_id ) : null;
		$reviews   = $gate['showList'] ? self::sectionReviews( $upc ) : [];

		$out = self::sectionStyles();
		$out .= '<div class="gdrv-section">';

		if ( $flash === 'error' )
		{
			$msgKey = $gate['minLen'] > 0 ? 'gdreviews_form_error_min' : 'gdreviews_form_error';
			$msg    = (string) $lang->addToStack( $msgKey );
			if ( $gate['minLen'] > 0 )
			{
				$msg = str_replace( '%d', (string) $gate['minLen'], $msg );
			}
			$out .= '<div class="gdrv-flash-error">' . $esc( $msg ) . '</div>';
		}
		elseif ( $flash === 'pending' )
		{
			$out .= '<div class="gdrv-flash-pending">' . $L( 'gdreviews_flash_pending' ) . '</div>';
		}

		/* Aggregate header */
		$out .= '<div class="gdrv-agg-head">';
		if ( $aggregate['count'] > 0 )
		{
			$out .= '<strong>' . number_format( (float) $aggregate['rating'], 1 ) . ' ★</strong> '
				. $esc( sprintf( (string) $lang->addToStack( 'gdreviews_agg_fmt' ), $aggregate['count'] ) );
		}
		else
		{
			$out .= '<span class="gdrv-empty">' . $L( 'gdreviews_no_reviews' ) . '</span>';
		}
		$out .= '</div>';

		/* Submission / edit-delete / guest / restricted */
		$out .= '<div class="gdrv-card">';
		if ( !$member->member_id )
		{
			$out .= '<h2>' . $L( 'gdreviews_write_review' ) . '</h2>'
				. '<p>' . $L( 'gdreviews_login_to_review' ) . ' '
				. '<a class="gdrv-btn gdrv-btn--primary" href="' . $esc( self::sectionLoginUrl( $upc ) ) . '">'
				. $L( 'gdreviews_login' ) . '</a></p>';
		}
		elseif ( $mine !== null )
		{
			$out .= self::sectionMineForm( $upc, $mine, $L, $esc, $returnUrl );
		}
		elseif ( !$gate['canSubmit'] )
		{
			$out .= '<h2>' . $L( 'gdreviews_write_review' ) . '</h2>'
				. '<p class="gdrv-empty">' . $L( 'gdreviews_group_restricted' ) . '</p>';
		}
		else
		{
			$out .= self::sectionCreateForm( $upc, $L, $esc, $returnUrl );
		}
		$out .= '</div>';

		/* Reviews list */
		if ( $gate['showList'] )
		{
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
					$out .= self::sectionRow( $r, $esc );
				}
			}
			$out .= '</div>';
		}
		elseif ( !$member->member_id )
		{
			$out .= '<div class="gdrv-card"><div class="gdrv-empty">' . $L( 'gdreviews_list_login_required' ) . '</div></div>';
		}

		$out .= '</div>';
		return $out;
	}

	/* ============================================================
	 * v1.0.5 — internal helpers for renderSection. Kept private to
	 * this class so both the standalone controller and gdsearch's
	 * tab render byte-identical markup / CSS.
	 * ============================================================ */

	private static function sectionGate( \IPS\Member $member ): array
	{
		$rawGroups = (string) \IPS\Settings::i()->gdreviews_reviewer_groups;
		$reviewerGroups = array_filter( array_map( 'intval', explode( ',', $rawGroups ) ) );

		$mode = (string) ( \IPS\Settings::i()->gdreviews_approval_mode ?: 'immediate' );
		if ( !in_array( $mode, [ 'immediate', 'moderate' ], TRUE ) ) { $mode = 'immediate'; }
		$requireText = (bool) \IPS\Settings::i()->gdreviews_require_text;
		$minLen      = max( 0, (int) \IPS\Settings::i()->gdreviews_min_length );
		$guestView   = (bool) \IPS\Settings::i()->gdreviews_guest_view;

		$isMember  = (bool) $member->member_id;
		$showList  = $isMember || $guestView;
		$canSubmit = $isMember;

		if ( $canSubmit && !empty( $reviewerGroups ) )
		{
			$memberGroups = $member->mgroup_others
				? array_filter( array_map( 'intval', explode( ',', (string) $member->mgroup_others ) ) )
				: [];
			$memberGroups[] = (int) $member->member_group_id;
			if ( empty( array_intersect( $reviewerGroups, $memberGroups ) ) )
			{
				$canSubmit = false;
			}
		}

		return [
			'canSubmit'    => $canSubmit,
			'showList'     => $showList,
			'approvalMode' => $mode,
			'requireText'  => $requireText,
			'minLen'       => $minLen,
			'guestView'    => $guestView,
		];
	}

	private static function sectionMine( string $upc, int $memberId ): ?array
	{
		if ( $upc === '' || $memberId <= 0 ) { return null; }
		try
		{
			return \IPS\Db::i()->select( '*', 'gdreviews_reviews',
				[ 'review_upc=? AND review_author=?', $upc, $memberId ] )->first();
		}
		catch ( \Throwable ) { return null; }
	}

	private static function sectionReviews( string $upc ): array
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

	private static function sectionLoginUrl( string $upc ): string
	{
		$self = (string) \IPS\Http\Url::internal(
			'app=gdreviews&module=reviews&controller=product&upc=' . urlencode( $upc ),
			'front'
		);
		return (string) \IPS\Http\Url::internal( 'app=core&module=system&controller=login', 'front', 'login' )
			->setQueryString( 'ref', base64_encode( $self ) );
	}

	private static function sectionActionUrl( string $upc, string $do, string $returnUrl ): string
	{
		$url = \IPS\Http\Url::internal(
			'app=gdreviews&module=reviews&controller=product&do=' . $do . '&upc=' . urlencode( $upc ),
			'front'
		);
		if ( $returnUrl !== '' )
		{
			$url = $url->setQueryString( 'return', base64_encode( $returnUrl ) );
		}
		return (string) $url;
	}

	private static function sectionCreateForm( string $upc, callable $L, callable $esc, string $returnUrl ): string
	{
		$action = self::sectionActionUrl( $upc, 'submitAct', $returnUrl );
		return '<h2>' . $L( 'gdreviews_write_review' ) . '</h2>'
			. '<form class="gdrv-form" method="post" action="' . $esc( $action ) . '">'
			. '<input type="hidden" name="csrfKey" value="' . $esc( (string) \IPS\Session::i()->csrfKey ) . '">'
			. '<label>' . $L( 'gdreviews_rating' ) . '</label>'
			. self::sectionStars( 0 )
			. '<label for="gdrv-title">' . $L( 'gdreviews_field_title' ) . '</label>'
			. '<input id="gdrv-title" type="text" name="title" maxlength="255" placeholder="' . $L( 'gdreviews_field_title_ph' ) . '">'
			. '<label for="gdrv-content">' . $L( 'gdreviews_field_content' ) . '</label>'
			. '<textarea id="gdrv-content" name="content" required></textarea>'
			. '<div style="margin-top:14px">'
			. '<button type="submit" class="gdrv-btn gdrv-btn--primary">' . $L( 'gdreviews_submit' ) . '</button>'
			. '</div>'
			. '</form>';
	}

	private static function sectionMineForm( string $upc, array $mine, callable $L, callable $esc, string $returnUrl ): string
	{
		$editUrl   = self::sectionActionUrl( $upc, 'editAct', $returnUrl );
		$deleteUrl = self::sectionActionUrl( $upc, 'deleteAct', $returnUrl );
		$rating    = (int) ( $mine['review_rating'] ?? 0 );

		return '<h2>' . $L( 'gdreviews_your_review' ) . '</h2>'
			. '<form class="gdrv-form" method="post" action="' . $esc( $editUrl ) . '">'
			. '<input type="hidden" name="csrfKey" value="' . $esc( (string) \IPS\Session::i()->csrfKey ) . '">'
			. '<label>' . $L( 'gdreviews_rating' ) . '</label>'
			. self::sectionStars( $rating )
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

	private static function sectionStars( int $current ): string
	{
		/* Reverse-order labels so pure-CSS `~` hover highlights work
		   left-to-right. IDs are prefixed with a per-render token so
		   two renders on the same page (unlikely but defensive)
		   don't collide. */
		$token = 'gdrv-s-' . substr( sha1( (string) $current . '.' . microtime() ), 0, 6 );
		$out = '<div class="gdrv-stars">';
		for ( $i = 5; $i >= 1; $i-- )
		{
			$id = $token . '-' . $i;
			$checked = ( $i === $current ) ? ' checked' : '';
			$out .= '<input type="radio" id="' . $id . '" name="rating" value="' . $i . '"' . $checked . ' required>'
				. '<label for="' . $id . '" title="' . $i . '">★</label>';
		}
		$out .= '</div>';
		return $out;
	}

	private static function sectionRow( array $r, callable $esc ): string
	{
		$stars    = (int) ( $r['review_rating'] ?? 0 );
		$starsStr = str_repeat( '★', max( 0, min( 5, $stars ) ) ) . str_repeat( '☆', max( 0, 5 - $stars ) );
		$name     = (string) ( $r['review_author_name'] ?? 'Anonymous' );
		if ( $name === '' ) { $name = 'Anonymous'; }
		$date     = (int) ( $r['review_date'] ?? 0 );
		$dateStr  = $date > 0 ? (string) \IPS\DateTime::ts( $date )->format( 'M j, Y' ) : '';
		$title    = (string) ( $r['review_title'] ?? '' );
		$content  = (string) ( $r['review_content'] ?? '' );

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

	private static function sectionStyles(): string
	{
		return '<style>
.gdrv-section{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#0f172a}
.gdrv-section .gdrv-agg-head{margin:8px 0 14px;color:#475569;font-size:1em}
.gdrv-section .gdrv-agg-head strong{color:#f59e0b;font-size:1.15em}
.gdrv-section .gdrv-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:20px;margin-bottom:14px}
.gdrv-section .gdrv-card h2{margin:0 0 12px;font-size:1.1em;font-weight:700}
.gdrv-section .gdrv-form label{display:block;font-weight:600;font-size:.9em;margin:12px 0 4px;color:#334155}
.gdrv-section .gdrv-form input[type=text],.gdrv-section .gdrv-form textarea{width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font:inherit;box-sizing:border-box}
.gdrv-section .gdrv-form textarea{min-height:110px;resize:vertical}
.gdrv-section .gdrv-stars{display:inline-flex;flex-direction:row-reverse;gap:2px;margin-top:2px}
.gdrv-section .gdrv-stars input{position:absolute;opacity:0;pointer-events:none}
.gdrv-section .gdrv-stars label{display:inline-block;padding:0 3px;font-size:1.7em;color:#e5e7eb;cursor:pointer;line-height:1;margin:0}
.gdrv-section .gdrv-stars input:checked ~ label,.gdrv-section .gdrv-stars label:hover,.gdrv-section .gdrv-stars label:hover ~ label{color:#f59e0b}
.gdrv-section .gdrv-btn{display:inline-block;padding:8px 16px;border-radius:6px;border:none;font-weight:600;cursor:pointer;font-size:.95em}
.gdrv-section .gdrv-btn--primary{background:#0f172a;color:#fff}
.gdrv-section .gdrv-btn--danger{background:#dc2626;color:#fff}
.gdrv-section .gdrv-btn+.gdrv-btn{margin-left:6px}
.gdrv-section .gdrv-row{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;padding:14px 0;border-top:1px solid #f1f5f9}
.gdrv-section .gdrv-row:first-child{border-top:none}
.gdrv-section .gdrv-meta{color:#64748b;font-size:.85em;margin-top:4px}
.gdrv-section .gdrv-rowStars{color:#f59e0b;font-size:1.05em;letter-spacing:1px}
.gdrv-section .gdrv-empty{color:#64748b;font-style:italic}
.gdrv-section .gdrv-flash-error{padding:10px 14px;background:#fef2f2;color:#991b1b;border:1px solid #fecaca;border-radius:6px;margin-bottom:14px}
.gdrv-section .gdrv-flash-pending{padding:10px 14px;background:#fef3c7;color:#78350f;border:1px solid #fde68a;border-radius:6px;margin-bottom:14px}
</style>';
	}
}

class Product extends _Product {}
