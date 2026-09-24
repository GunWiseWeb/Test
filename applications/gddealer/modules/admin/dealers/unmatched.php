<?php
/**
 * @brief       GD Dealer Manager — ACP Unmatched UPCs Controller
 * @package     IPS Community Suite
 * @subpackage  GD Dealer Manager
 * @since       15 Apr 2026
 *
 * Cross-dealer unmatched UPCs. Sortable by occurrence count. "Add to Catalog"
 * creates a minimal gd_catalog stub and clears the unmatched row.
 */

namespace IPS\gddealer\modules\admin\dealers;

use IPS\gddealer\Unmatched\UnmatchedUpc;
use IPS\gddealer\Dealer\Dealer;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _unmatched extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'dealer_manage' );
		parent::execute();
	}

	protected function manage()
	{
		$page         = max( 1, (int) ( \IPS\Request::i()->page ?? 1 ) );
		$perPage      = 50;
		$offset       = ( $page - 1 ) * $perPage;
		$reportedOnly = ( (string) ( \IPS\Request::i()->reported ?? '' ) === '1' );
		/* v1.0.343: default flipped — show added rows so admins can
		 * see progress at a glance on the same list. hide_added=1
		 * query param declutters when the list gets long. */
		$hideAdded = ( (string) ( \IPS\Request::i()->hide_added ?? '' ) === '1' );
		$showAdded = !$hideAdded;

		$rawRows       = UnmatchedUpc::loadAll( $offset, $perPage, $reportedOnly, $showAdded );
		$reportedCount = UnmatchedUpc::countDealerReported();
		$addedCount    = UnmatchedUpc::countAdded();

		/* Build a dealer-id -> name lookup so we can display dealer names */
		$dealerNames = [];
		try
		{
			foreach ( \IPS\Db::i()->select( 'dealer_id, dealer_name', 'gd_dealer_feed_config' ) as $r )
			{
				$dealerNames[ (int) $r['dealer_id'] ] = (string) $r['dealer_name'];
			}
		}
		catch ( \Exception ) {}

		$rows = [];
		foreach ( $rawRows as $r )
		{
			$excludeUrl = (string) \IPS\Http\Url::internal(
				'app=gddealer&module=dealers&controller=unmatched&do=exclude&id=' . (int) $r['id']
			)->csrf();
			$addUrl = (string) \IPS\Http\Url::internal(
				'app=gddealer&module=dealers&controller=unmatched&do=addToCatalog&id=' . (int) $r['id']
			)->csrf();

			$reviewUrl = (string) \IPS\Http\Url::internal(
				'app=gddealer&module=dealers&controller=unmatched&do=review&upc_id=' . (int) $r['id']
			);

			$status = (string) ( $r['status'] ?? '' );
			/* v1.0.342: for rows already promoted to gd_catalog, link
			 * to gdcatalog's product edit form so the admin can jump
			 * straight to enriching / promoting to active. */
			$catalogEditUrl = '';
			if ( $status === 'added_to_catalog' )
			{
				$catalogEditUrl = (string) \IPS\Http\Url::internal(
					'app=gdcatalog&module=catalog&controller=products&do=edit&upc=' . urlencode( (string) $r['upc'] )
				);
			}

			$rows[] = [
				'id'                 => (int) $r['id'],
				'upc'                => (string) $r['upc'],
				'dealer_name'        => $dealerNames[ (int) $r['dealer_id'] ] ?? ( 'Dealer #' . (int) $r['dealer_id'] ),
				'first_seen'         => (string) $r['first_seen'],
				'last_seen'          => (string) $r['last_seen'],
				'occurrence_count'   => (int) $r['occurrence_count'],
				'dealer_reported'    => !empty( $r['dealer_reported_at'] ),
				'dealer_reported_at' => !empty( $r['dealer_reported_at'] ) ? date( 'M j, Y g:i A', strtotime( (string) $r['dealer_reported_at'] ) ) : '',
				'status'             => $status,
				'is_added'           => ( $status === 'added_to_catalog' ),
				'catalog_edit_url'   => $catalogEditUrl,
				'exclude_url'        => $excludeUrl,
				'add_url'            => $addUrl,
				'review_url'         => $reviewUrl,
			];
		}

		$total = 0;
		if ( $reportedOnly )
		{
			$total = $reportedCount;
		}
		else if ( $showAdded )
		{
			try { $total = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_unmatched_upcs', [ 'admin_excluded=?', 0 ] )->first(); } catch ( \Exception ) {}
		}
		else
		{
			$total = UnmatchedUpc::countPending( false );
		}

		$pageBase = \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=unmatched' );
		if ( $reportedOnly )
		{
			$pageBase = $pageBase->setQueryString( 'reported', '1' );
		}
		if ( $hideAdded )
		{
			$pageBase = $pageBase->setQueryString( 'hide_added', '1' );
		}

		$pagination = \IPS\Theme::i()->getTemplate( 'global', 'core', 'global' )->pagination(
			$pageBase,
			(int) ceil( max( 1, $total ) / $perPage ),
			$page,
			$perPage
		);

		/* v1.0.343: URLs for the "Hide already added" / "Show already added"
		 * toggle. Default is Show (added rows visible with Pending Review
		 * badge); Hide declutters. */
		$hideAddedUrl = (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=unmatched' )
			->setQueryString( 'hide_added', '1' );
		$showAddedUrl = (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=unmatched' );

		/* v1.0.344: bulk-action endpoints for the "Add Selected" /
		 * "Exclude Selected" buttons above the table. Both csrf-baked
		 * because they're POST + redirect. */
		$bulkAddUrl = (string) \IPS\Http\Url::internal(
			'app=gddealer&module=dealers&controller=unmatched&do=bulkAdd'
		)->csrf();
		$bulkExcludeUrl = (string) \IPS\Http\Url::internal(
			'app=gddealer&module=dealers&controller=unmatched&do=bulkExclude'
		)->csrf();

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gddealer_unmatched_title' );
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'admin' )->unmatchedList(
			$rows, $total, $pagination, $reportedOnly, $reportedCount,
			$showAdded, $addedCount, $showAddedUrl, $hideAddedUrl,
			$bulkAddUrl, $bulkExcludeUrl
		);
	}

	protected function exclude()
	{
		\IPS\Session::i()->csrfCheck();
		UnmatchedUpc::exclude( (int) \IPS\Request::i()->id );
		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=unmatched' ),
			'UPC excluded from queue'
		);
	}

	protected function review(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'gddealer_dealer_manage' );

		$id = (int) \IPS\Request::i()->upc_id;

		try {
			$row = \IPS\Db::i()->select( '*', 'gd_unmatched_upcs', [ 'id=?', $id ] )->first();
		} catch ( \Throwable ) {
			\IPS\Output::i()->error( 'node_error', '2GDD/2', 404 );
			return;
		}

		$snapshot = [];
		if ( !empty( $row['snapshot_json'] ) ) {
			try { $snapshot = json_decode( (string) $row['snapshot_json'], true ) ?: []; } catch ( \Throwable ) {}
		}

		$dealerName = '';
		try {
			$d = \IPS\Db::i()->select( 'dealer_name', 'gd_dealer_feed_config', [ 'dealer_id=?', (int) $row['dealer_id'] ] )->first();
			$dealerName = (string) $d;
		} catch ( \Throwable ) {}

		/* v1.0.335 — keep parent_id in the categories structure so the
		   template can render a grouped/indented dropdown via
		   <optgroup>. Previously discarded parent_id, dumping every
		   category (including subcategories like Revolvers, Pistols)
		   into a single flat A-Z list where an admin couldn't visually
		   distinguish "Revolvers (Handguns subcategory)" from a random
		   unrelated category — leading to wrong-category assignments
		   that now matter because gdsearch facets filter on exact
		   category_id (Revolvers=3 vs Handguns=1 are different IDs). */
		$categories        = [];
		$categoriesByParent = [];
		try {
			foreach ( \IPS\Db::i()->select( 'id, name, parent_id', 'gd_categories', [], 'name ASC' ) as $cat ) {
				$id       = (int) $cat['id'];
				$parentId = (int) $cat['parent_id'];
				$categories[ $id ]                = (string) $cat['name'];
				$categoriesByParent[ $parentId ][] = [ 'id' => $id, 'name' => (string) $cat['name'] ];
			}
		} catch ( \Throwable ) {}

		$submitUrl = (string) \IPS\Http\Url::internal(
			'app=gddealer&module=dealers&controller=unmatched&do=addToCatalog&upc_id=' . $id
		)->csrf();
		$backUrl = (string) \IPS\Http\Url::internal(
			'app=gddealer&module=dealers&controller=unmatched'
		);
		/* v1.0.333 — URL for the "Fetch details from dealer's listing"
		   AI-assist button. Enabled in the template only when a
		   listing_url exists AND at least one target field is empty. */
		$fetchDetailsUrl = (string) \IPS\Http\Url::internal(
			'app=gddealer&module=dealers&controller=unmatched&do=fetchdetails&upc_id=' . $id
		)->csrf();

		/* v1.0.333 — listing_url added to the prefilled fields so the
		   ACP form can render it (as a reference link) exactly the same
		   way every other field is prefilled from the snapshot. */
		$prefill = array_merge(
			[ 'title' => '', 'brand' => '', 'mpn' => '', 'model' => '', 'msrp' => '', 'caliber' => '', 'image_url' => '', 'description' => '',
			  'product_type' => '', 'material' => '', 'color' => '', 'finish' => '', 'size' => '', 'mount_type' => '', 'fit' => '', 'battery_size' => '', 'nrr' => '', 'lock_type' => '', 'species' => '',
			  'listing_url' => '' ],
			array_intersect_key( $snapshot, array_flip( [ 'title', 'brand', 'mpn', 'model', 'msrp', 'caliber', 'image_url', 'description',
				'product_type', 'material', 'color', 'finish', 'size', 'mount_type', 'fit', 'battery_size', 'nrr', 'lock_type', 'species',
				'listing_url' ] ) )
		);
		if ( $prefill['brand'] === '' && !empty( $snapshot['manufacturer'] ) )
		{
			$prefill['brand'] = (string) $snapshot['manufacturer'];
		}

		/* v1.0.333 — flags the template uses to decide whether to
		   render the fetch-details button and whether to note "fetched
		   N fields" flash after the previous request. */
		$canFetch     = ( trim( (string) ( $prefill['listing_url'] ?? '' ) ) !== '' ) && $this->_hasMissingFields( $prefill );
		$flashMessage = (string) ( \IPS\Request::i()->flash ?? '' );

		\IPS\Output::i()->title = \IPS\Member::loggedIn()->language()->addToStack( 'gddealer_unmatched_review_title' );
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'admin' )->unmatchedUpcReview(
			$row, $snapshot, $dealerName, $categories, $submitUrl, $backUrl, $prefill, $fetchDetailsUrl, $canFetch, $flashMessage, $categoriesByParent
		);
	}

	/* v1.0.333 — AI-assist "Fetch details from dealer's listing" action.
	   Fetches listing_url with realistic browser headers, cleans script/
	   style/comment blocks before strip_tags (reusing the proven pattern
	   from gdrebates/sources/Parser.php v1.0.6+v1.0.12), sends the
	   cleaned page text to Claude with a prompt that asks ONLY for
	   product fields, then merges the returned JSON into the row's
	   snapshot_json FILLING BLANKS ONLY — never overwriting a field
	   that already had a value from the original dealer feed. On
	   success/failure, redirects back to the review screen with a
	   flash message. API key comes from the existing shared
	   gdrebates_api_key setting (reused site-wide — no new setting). */
	protected function fetchdetails(): void
	{
		\IPS\Session::i()->csrfCheck();
		\IPS\Dispatcher::i()->checkAcpPermission( 'gddealer_dealer_manage' );

		$id = (int) ( \IPS\Request::i()->upc_id ?? 0 );

		try
		{
			$row = \IPS\Db::i()->select( '*', 'gd_unmatched_upcs', [ 'id=?', $id ] )->first();
		}
		catch ( \Throwable )
		{
			\IPS\Output::i()->error( 'node_error', '2GDD/5', 404 );
			return;
		}

		$snapshot = [];
		if ( !empty( $row['snapshot_json'] ) )
		{
			try { $snapshot = json_decode( (string) $row['snapshot_json'], true ) ?: []; } catch ( \Throwable ) {}
		}

		$url = trim( (string) ( $snapshot['listing_url'] ?? '' ) );
		if ( $url === '' )
		{
			$this->_backToReview( $id, 'gddealer_unmatched_fetch_no_url' );
			return;
		}

		$apiKey = trim( (string) \IPS\Settings::i()->gdrebates_api_key );
		if ( $apiKey === '' )
		{
			$this->_backToReview( $id, 'gddealer_unmatched_fetch_no_key' );
			return;
		}

		$html = $this->_fetchPage( $url );
		if ( $html === null )
		{
			$this->_backToReview( $id, 'gddealer_unmatched_fetch_fail' );
			return;
		}

		$fields = $this->_extractProduct( $html, $apiKey );
		if ( $fields === null )
		{
			$this->_backToReview( $id, 'gddealer_unmatched_fetch_fail' );
			return;
		}

		/* Fill-blanks-only merge: only write a key when the current
		   snapshot doesn't already have a non-empty value. Never
		   silently overwrite dealer-feed data or (indirectly) any
		   admin edit that flowed from it. Track how many fields
		   actually landed for the success message. */
		$targetFields = [ 'title', 'brand', 'mpn', 'model', 'msrp', 'caliber', 'image_url', 'description' ];
		$added = 0;
		foreach ( $targetFields as $f )
		{
			$existing = trim( (string) ( $snapshot[ $f ] ?? '' ) );
			if ( $existing !== '' ) { continue; }
			if ( !isset( $fields[ $f ] ) ) { continue; }
			$val = $fields[ $f ];
			if ( $val === null ) { continue; }
			if ( is_string( $val ) && trim( $val ) === '' ) { continue; }
			$snapshot[ $f ] = ( $f === 'msrp' && is_numeric( $val ) ) ? (float) $val : (string) $val;
			$added++;
		}

		if ( $added > 0 )
		{
			try
			{
				\IPS\Db::i()->update( 'gd_unmatched_upcs', [
					'snapshot_json' => json_encode( $snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				], [ 'id=?', $id ] );
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'gddealer unmatched fetchdetails persist: ' . $e->getMessage(), 'gddealer' ); } catch ( \Throwable ) {}
			}
		}

		$msg = \IPS\Member::loggedIn()->language()->addToStack( 'gddealer_unmatched_fetch_success', FALSE, [ 'sprintf' => [ $added ] ] );
		$reviewUrl = \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=unmatched&do=review&upc_id=' . $id )
			->setQueryString( 'flash', (string) $msg );
		\IPS\Output::i()->redirect( $reviewUrl );
	}

	protected function _backToReview( int $id, string $flashLangKey ): void
	{
		$msg = \IPS\Member::loggedIn()->language()->addToStack( $flashLangKey );
		$reviewUrl = \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=unmatched&do=review&upc_id=' . $id )
			->setQueryString( 'flash', (string) $msg );
		\IPS\Output::i()->redirect( $reviewUrl );
	}

	protected function _hasMissingFields( array $prefill ): bool
	{
		foreach ( [ 'mpn', 'model', 'msrp', 'caliber', 'description', 'image_url' ] as $f )
		{
			if ( trim( (string) ( $prefill[ $f ] ?? '' ) ) === '' ) { return TRUE; }
		}
		return FALSE;
	}

	/* Fetch a dealer product page with realistic Chrome-on-Windows
	   headers, matching the gdrebates Parser.php v1.0.12 pattern —
	   defeats basic UA sniffing / mild WAFs. Logs failures to core_log
	   category 'gddealer' rather than swallowing silently. Does NOT
	   handle Cloudflare / Incapsula JS challenges (out of scope). */
	protected function _fetchPage( string $url ): ?string
	{
		try
		{
			$response = \IPS\Http\Url::external( $url )
				->request( 30 )
				->setHeaders( [
					'User-Agent'                => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
					'Accept'                    => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
					'Accept-Language'           => 'en-US,en;q=0.9',
					'Accept-Encoding'           => 'gzip, deflate, br',
					'sec-ch-ua'                 => '"Chromium";v="130", "Google Chrome";v="130", "Not?A_Brand";v="99"',
					'sec-ch-ua-mobile'          => '?0',
					'sec-ch-ua-platform'        => '"Windows"',
					'Sec-Fetch-Dest'            => 'document',
					'Sec-Fetch-Mode'            => 'navigate',
					'Sec-Fetch-Site'            => 'none',
					'Sec-Fetch-User'            => '?1',
					'Upgrade-Insecure-Requests' => '1',
				] )
				->get();
			return (string) $response;
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gddealer unmatched fetchdetails fetch ' . $url . ': ' . $e->getMessage(), 'gddealer' ); } catch ( \Throwable ) {}
			return NULL;
		}
	}

	/* Same cleaning + budget as gdrebates Parser::callAnthropic v1.0.6
	   (strip script/style/comment BLOCKS before strip_tags so the
	   character budget isn't spent on minified JS/CSS), then send to
	   Claude with a product-extraction prompt. Returns an assoc array
	   of the extracted fields on success, NULL on any failure. */
	protected function _extractProduct( string $html, string $apiKey ): ?array
	{
		$clean = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $html );
		$clean = preg_replace( '#<style\b[^>]*>.*?</style>#is',   '', (string) $clean );
		$clean = preg_replace( '#<!--.*?-->#s',                    '', (string) $clean );
		$clean = strip_tags( (string) $clean );
		$clean = preg_replace( '/[ \t]+/',   ' ',    (string) $clean );
		$clean = preg_replace( '/\n{3,}/',   "\n\n", (string) $clean );
		$page  = mb_substr( trim( (string) $clean ), 0, 350000 );

		$model = trim( (string) \IPS\Settings::i()->gdrebates_model );
		if ( $model === '' ) { $model = 'claude-haiku-4-5-20251001'; }

		$prompt = "Extract this firearm or firearms accessory product's details from the page. "
			. "Return ONLY a JSON object with keys: "
			. "title (string), brand (string), mpn (string), model (string), "
			. "msrp (number or null), caliber (string), description (string), image_url (string). "
			. "Use null for anything not found. No markdown fences, no commentary — JSON object only.";

		$body = json_encode( [
			'model'      => $model,
			'max_tokens' => 2048,
			'messages'   => [
				[ 'role' => 'user', 'content' => $prompt . "\n\nPAGE TEXT:\n" . $page ],
			],
		], JSON_UNESCAPED_SLASHES );

		try
		{
			$response = \IPS\Http\Url::external( 'https://api.anthropic.com/v1/messages' )
				->request( 120 )
				->setHeaders( [
					'Content-Type'      => 'application/json',
					'x-api-key'         => $apiKey,
					'anthropic-version' => '2023-06-01',
				] )
				->post( $body );

			$data = json_decode( (string) $response, TRUE );
			if ( !isset( $data['content'][0]['text'] ) )
			{
				try { \IPS\Log::log( 'gddealer unmatched extractProduct: empty Claude response', 'gddealer' ); } catch ( \Throwable ) {}
				return NULL;
			}

			$text = trim( $data['content'][0]['text'] );
			if ( str_starts_with( $text, '```' ) )
			{
				$text = preg_replace( '/^```[a-z]*\n?/', '', $text );
				$text = preg_replace( '/\n?```$/', '', $text );
			}

			$fields = json_decode( $text, TRUE );
			return is_array( $fields ) ? $fields : NULL;
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gddealer unmatched extractProduct: ' . $e->getMessage(), 'gddealer' ); } catch ( \Throwable ) {}
			return NULL;
		}
	}

	protected function addToCatalog(): void
	{
		\IPS\Session::i()->csrfCheck();
		\IPS\Dispatcher::i()->checkAcpPermission( 'gddealer_dealer_manage' );

		$id         = (int) ( \IPS\Request::i()->upc_id ?? \IPS\Request::i()->id ?? 0 );
		$publishNow = ( (int) ( \IPS\Request::i()->publish_now ?? 0 ) === 1 );

		/* v1.0.344: collect every field the Review form might post so
		 * _promoteToCatalog gets them as overrides. Direct-list-click
		 * doesn't submit any of these — the helper then falls back to
		 * the dealer's snapshot data. */
		$overrides = [];
		$formFields = [
			'title', 'brand', 'model', 'mpn', 'category_id', 'caliber',
			'action_type', 'capacity', 'barrel_length', 'overall_length',
			'weight_lbs', 'msrp', 'description', 'image_url',
			'product_type', 'material', 'color', 'finish', 'size',
			'mount_type', 'fit', 'battery_size', 'nrr', 'lock_type',
			'species', 'requires_ffl', 'nfa_item', 'is_ammo',
		];
		foreach ( $formFields as $k )
		{
			$v = \IPS\Request::i()->$k ?? null;
			if ( $v !== null )
			{
				$overrides[ $k ] = $v;
			}
		}

		$result = $this->_promoteToCatalog( $id, $publishNow, $overrides );

		$backUrl = \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=unmatched' );
		switch ( $result['status'] )
		{
			case 'exists':
				\IPS\Output::i()->redirect( $backUrl, 'gddealer_unmatched_already_exists' );
				return;
			case 'notfound':
				\IPS\Output::i()->error( 'node_error', '2GDD/3', 404 );
				return;
			case 'error':
				\IPS\Output::i()->error( $result['message'], '2GDD/4', 500 );
				return;
			default:
				\IPS\Output::i()->redirect( $backUrl, 'gddealer_unmatched_added_to_catalog' );
				return;
		}
	}

	/**
	 * v1.0.344 — bulk-add selected Unmatched UPCs to the Review Queue.
	 *
	 * Accepts POST `ids[]` (array of gd_unmatched_upcs.id) and an
	 * optional `publish_now=1` flag. Iterates through the selection
	 * (capped at 200 per request to avoid PHP timeouts on huge
	 * feeds), calling _promoteToCatalog on each. Each row uses its
	 * own snapshot_json for title/brand/model/mpn/image/msrp/
	 * category_id, so the resulting Review Queue rows carry the
	 * dealer data without any per-row form input. Redirects back to
	 * the list with a summary flash: "Added N, already existed M,
	 * errors K." When the selection exceeds the per-request cap the
	 * flash notes how many are left so the admin can select the next
	 * batch and re-run.
	 *
	 * CSRF-protected via Session::i()->csrfCheck().
	 */
	protected function bulkAdd(): void
	{
		\IPS\Session::i()->csrfCheck();
		\IPS\Dispatcher::i()->checkAcpPermission( 'gddealer_dealer_manage' );

		$backUrl = \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=unmatched' );

		$ids = \IPS\Request::i()->ids ?? [];
		if ( !is_array( $ids ) || empty( $ids ) )
		{
			\IPS\Output::i()->redirect( $backUrl, 'No UPCs selected.' );
			return;
		}

		$publishNow = ( (int) ( \IPS\Request::i()->publish_now ?? 0 ) === 1 );
		$cap        = 200;
		$total      = count( $ids );
		$batch      = array_slice( array_map( 'intval', $ids ), 0, $cap );

		$added = 0; $exists = 0; $notFound = 0; $errors = 0;
		foreach ( $batch as $id )
		{
			if ( $id <= 0 ) { continue; }
			$r = $this->_promoteToCatalog( $id, $publishNow, [] );
			switch ( $r['status'] )
			{
				case 'added':    $added++;    break;
				case 'exists':   $exists++;   break;
				case 'notfound': $notFound++; break;
				default:         $errors++;   break;
			}
		}

		$msg = sprintf(
			'Bulk add: %d sent to Review Queue, %d already in catalog, %d errors%s.',
			$added, $exists, $errors,
			$notFound > 0 ? ", $notFound not found" : ''
		);
		if ( $total > $cap )
		{
			$msg .= sprintf( ' Capped at %d per request; %d remain — select again to continue.', $cap, $total - $cap );
		}

		\IPS\Output::i()->redirect( $backUrl, $msg );
	}

	/**
	 * v1.0.344 — bulk-exclude selected Unmatched UPCs.
	 *
	 * Companion to bulkAdd for cleaning junk UPCs off the list in
	 * one click. Sets admin_excluded=1 on each selected row so the
	 * default list stops showing them. Does NOT touch gd_catalog.
	 */
	protected function bulkExclude(): void
	{
		\IPS\Session::i()->csrfCheck();
		\IPS\Dispatcher::i()->checkAcpPermission( 'gddealer_dealer_manage' );

		$backUrl = \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=unmatched' );

		$ids = \IPS\Request::i()->ids ?? [];
		if ( !is_array( $ids ) || empty( $ids ) )
		{
			\IPS\Output::i()->redirect( $backUrl, 'No UPCs selected.' );
			return;
		}

		$excluded = 0;
		foreach ( $ids as $rawId )
		{
			$id = (int) $rawId;
			if ( $id <= 0 ) { continue; }
			try
			{
				\IPS\Db::i()->update( 'gd_unmatched_upcs', [ 'admin_excluded' => 1 ], [ 'id=?', $id ] );
				$excluded++;
			}
			catch ( \Throwable ) {}
		}

		\IPS\Output::i()->redirect( $backUrl, sprintf( 'Bulk exclude: %d UPC(s) removed from list.', $excluded ) );
	}

	/**
	 * v1.0.344 — shared helper for single-add + bulk-add.
	 *
	 * Loads gd_unmatched_upcs row by id, checks whether the UPC is
	 * already in gd_catalog (skip if yes), decodes snapshot_json for
	 * default field values, applies any `$overrides` from a Review
	 * form submission, INSERTs the gd_catalog row with
	 * record_status='admin_review' (or 'active' when $publishNow),
	 * and updates the gd_unmatched_upcs row to status='added_to_catalog'.
	 *
	 * Returns an assoc array {status, upc, message} instead of
	 * redirecting so callers can aggregate stats across many rows.
	 *   status = 'added' | 'exists' | 'notfound' | 'error'
	 */
	protected function _promoteToCatalog( int $id, bool $publishNow, array $overrides ): array
	{
		if ( $id <= 0 )
		{
			return [ 'status' => 'notfound', 'upc' => '', 'message' => 'invalid id' ];
		}

		try
		{
			$row = \IPS\Db::i()->select( '*', 'gd_unmatched_upcs', [ 'id=?', $id ] )->first();
		}
		catch ( \Throwable )
		{
			return [ 'status' => 'notfound', 'upc' => '', 'message' => "id=$id not in gd_unmatched_upcs" ];
		}

		$upc = (string) $row['upc'];
		if ( $upc === '' )
		{
			return [ 'status' => 'error', 'upc' => '', 'message' => 'empty upc on row' ];
		}

		try
		{
			\IPS\Db::i()->select( 'upc', 'gd_catalog', [ 'upc=?', $upc ] )->first();
			/* Already in catalog — mark the unmatched row so it
			 * stops showing as Pending in the list, then report
			 * back as 'exists' so the caller can count it. */
			try
			{
				\IPS\Db::i()->update( 'gd_unmatched_upcs', [
					'status'    => 'added_to_catalog',
					'last_seen' => date( 'Y-m-d H:i:s' ),
				], [ 'id=?', $id ] );
			}
			catch ( \Throwable ) {}
			return [ 'status' => 'exists', 'upc' => $upc, 'message' => '' ];
		}
		catch ( \Throwable ) { /* not found = expected, continue */ }

		$snapshot = [];
		if ( !empty( $row['snapshot_json'] ) )
		{
			try { $snapshot = json_decode( (string) $row['snapshot_json'], true ) ?: []; }
			catch ( \Throwable ) {}
		}

		/* Override wins over snapshot, both trimmed, empty means fall through. */
		$val = static function ( string $key ) use ( $snapshot, $overrides ): string
		{
			if ( isset( $overrides[ $key ] ) )
			{
				$v = trim( (string) $overrides[ $key ] );
				if ( $v !== '' ) { return $v; }
			}
			return trim( (string) ( $snapshot[ $key ] ?? '' ) );
		};

		$brand = $val( 'brand' );
		if ( $brand === '' )
		{
			$brand = trim( (string) ( $snapshot['manufacturer'] ?? '' ) );
		}

		$categoryId = (int) ( $overrides['category_id'] ?? ( $snapshot['category_id'] ?? 0 ) );
		$msrp       = (float) ( $overrides['msrp'] ?? ( $snapshot['msrp'] ?? 0 ) );

		$now = date( 'Y-m-d H:i:s' );
		$data = [
			'upc'            => $upc,
			'title'          => $val( 'title' ),
			'brand'          => $brand,
			'model'          => $val( 'model' ),
			'mpn'            => $val( 'mpn' ),
			'category_id'    => $categoryId,
			'caliber'        => $val( 'caliber' ) ?: null,
			'action_type'    => $val( 'action_type' ) ?: null,
			'capacity'       => $val( 'capacity' ) ?: null,
			'barrel_length'  => $val( 'barrel_length' ) ?: null,
			'overall_length' => $val( 'overall_length' ) ?: null,
			'weight_lbs'     => $val( 'weight_lbs' ) ?: null,
			'msrp'           => $msrp > 0 ? $msrp : null,
			'description'    => $val( 'description' ) ?: null,
			'image_url'      => $val( 'image_url' ) ?: null,
			'product_type'   => mb_substr( $val( 'product_type' ), 0, 80 ) ?: null,
			'material'       => mb_substr( $val( 'material' ), 0, 80 ) ?: null,
			'color'          => mb_substr( $val( 'color' ), 0, 60 ) ?: null,
			'finish'         => mb_substr( $val( 'finish' ), 0, 60 ) ?: null,
			'size'           => mb_substr( $val( 'size' ), 0, 60 ) ?: null,
			'mount_type'     => mb_substr( $val( 'mount_type' ), 0, 80 ) ?: null,
			'fit'            => mb_substr( $val( 'fit' ), 0, 150 ) ?: null,
			'battery_size'   => mb_substr( $val( 'battery_size' ), 0, 40 ) ?: null,
			'nrr'            => mb_substr( $val( 'nrr' ), 0, 20 ) ?: null,
			'lock_type'      => mb_substr( $val( 'lock_type' ), 0, 60 ) ?: null,
			'species'        => mb_substr( $val( 'species' ), 0, 80 ) ?: null,
			'requires_ffl'   => (int) ( $overrides['requires_ffl'] ?? ( $snapshot['requires_ffl'] ?? 0 ) ),
			'nfa_item'       => (int) ( $overrides['nfa_item']     ?? ( $snapshot['nfa_item']     ?? 0 ) ),
			'is_ammo'        => (int) ( $overrides['is_ammo']      ?? ( $snapshot['is_ammo']      ?? 0 ) ),
			'record_status'  => $publishNow ? 'active' : 'admin_review',
			'primary_source' => 'admin',
			'last_updated'   => $now,
		];

		/* Drop empty scalars but always keep upc + control fields the
		 * schema needs a value for. */
		$data = array_filter( $data, static fn ( $v ) => $v !== null && $v !== '' );
		$data['upc']            = $upc;
		$data['record_status']  = $data['record_status']  ?? ( $publishNow ? 'active' : 'admin_review' );
		$data['primary_source'] = $data['primary_source'] ?? 'admin';
		$data['last_updated']   = $data['last_updated']   ?? $now;

		try
		{
			\IPS\Db::i()->insert( 'gd_catalog', $data );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gddealer _promoteToCatalog upc=' . $upc . ': ' . $e->getMessage(), 'gddealer' ); } catch ( \Throwable ) {}
			return [ 'status' => 'error', 'upc' => $upc, 'message' => $e->getMessage() ];
		}

		try
		{
			\IPS\Db::i()->update( 'gd_unmatched_upcs', [
				'status'    => 'added_to_catalog',
				'last_seen' => $now,
			], [ 'id=?', $id ] );
		}
		catch ( \Throwable ) {}

		return [ 'status' => 'added', 'upc' => $upc, 'message' => '' ];
	}
}

class unmatched extends _unmatched {}
