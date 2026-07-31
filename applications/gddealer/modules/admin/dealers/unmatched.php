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

		$rawRows       = UnmatchedUpc::loadAll( $offset, $perPage, $reportedOnly );
		$reportedCount = UnmatchedUpc::countDealerReported();

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

			$rows[] = [
				'id'                 => (int) $r['id'],
				'upc'                => (string) $r['upc'],
				'dealer_name'        => $dealerNames[ (int) $r['dealer_id'] ] ?? ( 'Dealer #' . (int) $r['dealer_id'] ),
				'first_seen'         => (string) $r['first_seen'],
				'last_seen'          => (string) $r['last_seen'],
				'occurrence_count'   => (int) $r['occurrence_count'],
				'dealer_reported'    => !empty( $r['dealer_reported_at'] ),
				'dealer_reported_at' => !empty( $r['dealer_reported_at'] ) ? date( 'M j, Y g:i A', strtotime( (string) $r['dealer_reported_at'] ) ) : '',
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
		else
		{
			try { $total = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_unmatched_upcs', [ 'admin_excluded=?', 0 ] )->first(); } catch ( \Exception ) {}
		}

		$pageBase = \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=unmatched' );
		if ( $reportedOnly )
		{
			$pageBase = $pageBase->setQueryString( 'reported', '1' );
		}

		$pagination = \IPS\Theme::i()->getTemplate( 'global', 'core', 'global' )->pagination(
			$pageBase,
			(int) ceil( max( 1, $total ) / $perPage ),
			$page,
			$perPage
		);

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gddealer_unmatched_title' );
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'admin' )->unmatchedList(
			$rows, $total, $pagination, $reportedOnly, $reportedCount
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

		$categories = [];
		try {
			foreach ( \IPS\Db::i()->select( 'id, name, parent_id', 'gd_categories', [], 'name ASC' ) as $cat ) {
				$categories[ (int) $cat['id'] ] = (string) $cat['name'];
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
			$row, $snapshot, $dealerName, $categories, $submitUrl, $backUrl, $prefill, $fetchDetailsUrl, $canFetch, $flashMessage
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

		$id  = (int) ( \IPS\Request::i()->upc_id ?? \IPS\Request::i()->id ?? 0 );
		$now = date( 'Y-m-d H:i:s' );

		try {
			$row = \IPS\Db::i()->select( '*', 'gd_unmatched_upcs', [ 'id=?', $id ] )->first();
		} catch ( \Throwable ) {
			\IPS\Output::i()->error( 'node_error', '2GDD/3', 404 );
			return;
		}

		$upc = (string) $row['upc'];

		$exists = false;
		try {
			\IPS\Db::i()->select( 'upc', 'gd_catalog', [ 'upc=?', $upc ] )->first();
			$exists = true;
		} catch ( \Throwable ) {}

		if ( $exists ) {
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=unmatched' ),
				'gddealer_unmatched_already_exists'
			);
			return;
		}

		$data = [
			'upc'            => $upc,
			'title'          => trim( (string) ( \IPS\Request::i()->title ?? '' ) ),
			'brand'          => trim( (string) ( \IPS\Request::i()->brand ?? '' ) ),
			'model'          => trim( (string) ( \IPS\Request::i()->model ?? '' ) ),
			'mpn'            => trim( (string) ( \IPS\Request::i()->mpn ?? '' ) ),
			'category_id'    => (int) ( \IPS\Request::i()->category_id ?? 0 ),
			'caliber'        => trim( (string) ( \IPS\Request::i()->caliber ?? '' ) ) ?: null,
			'action_type'    => trim( (string) ( \IPS\Request::i()->action_type ?? '' ) ) ?: null,
			'capacity'       => trim( (string) ( \IPS\Request::i()->capacity ?? '' ) ) ?: null,
			'barrel_length'  => trim( (string) ( \IPS\Request::i()->barrel_length ?? '' ) ) ?: null,
			'overall_length' => trim( (string) ( \IPS\Request::i()->overall_length ?? '' ) ) ?: null,
			'weight_lbs'     => trim( (string) ( \IPS\Request::i()->weight_lbs ?? '' ) ) ?: null,
			'msrp'           => (float) ( \IPS\Request::i()->msrp ?? 0 ) ?: null,
			'description'    => trim( (string) ( \IPS\Request::i()->description ?? '' ) ) ?: null,
			'image_url'      => trim( (string) ( \IPS\Request::i()->image_url ?? '' ) ) ?: null,
			'product_type'   => mb_substr( trim( (string) ( \IPS\Request::i()->product_type ?? '' ) ), 0, 80 ) ?: null,
			'material'       => mb_substr( trim( (string) ( \IPS\Request::i()->material ?? '' ) ), 0, 80 ) ?: null,
			'color'          => mb_substr( trim( (string) ( \IPS\Request::i()->color ?? '' ) ), 0, 60 ) ?: null,
			'finish'         => mb_substr( trim( (string) ( \IPS\Request::i()->finish ?? '' ) ), 0, 60 ) ?: null,
			'size'           => mb_substr( trim( (string) ( \IPS\Request::i()->size ?? '' ) ), 0, 60 ) ?: null,
			'mount_type'     => mb_substr( trim( (string) ( \IPS\Request::i()->mount_type ?? '' ) ), 0, 80 ) ?: null,
			'fit'            => mb_substr( trim( (string) ( \IPS\Request::i()->fit ?? '' ) ), 0, 150 ) ?: null,
			'battery_size'   => mb_substr( trim( (string) ( \IPS\Request::i()->battery_size ?? '' ) ), 0, 40 ) ?: null,
			'nrr'            => mb_substr( trim( (string) ( \IPS\Request::i()->nrr ?? '' ) ), 0, 20 ) ?: null,
			'lock_type'      => mb_substr( trim( (string) ( \IPS\Request::i()->lock_type ?? '' ) ), 0, 60 ) ?: null,
			'species'        => mb_substr( trim( (string) ( \IPS\Request::i()->species ?? '' ) ), 0, 80 ) ?: null,
			'requires_ffl'   => (int) ( \IPS\Request::i()->requires_ffl ?? 0 ),
			'nfa_item'       => (int) ( \IPS\Request::i()->nfa_item ?? 0 ),
			'is_ammo'        => (int) ( \IPS\Request::i()->is_ammo ?? 0 ),
			'record_status'  => 'active',
			'primary_source' => 'admin',
			'created_at'     => $now,
			'updated_at'     => $now,
		];

		$data = array_filter( $data, fn($v) => $v !== null && $v !== '' );
		$data['upc'] = $upc;

		try {
			\IPS\Db::i()->insert( 'gd_catalog', $data );
		} catch ( \Throwable $e ) {
			\IPS\Output::i()->error( $e->getMessage(), '2GDD/4', 500 );
			return;
		}

		try {
			\IPS\Db::i()->update( 'gd_unmatched_upcs', [
				'status'    => 'added_to_catalog',
				'last_seen' => $now,
			], [ 'id=?', $id ] );
		} catch ( \Throwable ) {}

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=unmatched' ),
			'gddealer_unmatched_added_to_catalog'
		);
	}
}

class unmatched extends _unmatched {}
