<?php
namespace IPS\gdrebates\sources;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Parser
{
	protected array  $source;
	protected string $model;
	protected string $apiKey;

	public function __construct( array $source )
	{
		$this->source = $source;
		$this->apiKey = (string) \IPS\Settings::i()->gdrebates_api_key;

		$override = trim( (string) ( $source['model_override'] ?? '' ) );
		if ( $override !== '' )
		{
			$this->model = $override;
		}
		else
		{
			$global = trim( (string) \IPS\Settings::i()->gdrebates_model );
			$this->model = $global !== '' ? $global : 'claude-haiku-4-5-20251001';
		}
	}

	public function run(): array
	{
		if ( $this->apiKey === '' )
		{
			$this->updateSource( 'error', 'No API key configured', 0 );
			return [ 'status' => 'error', 'message' => 'No API key configured', 'inserted' => 0 ];
		}

		$html = $this->fetchPage( (string) $this->source['url'] );
		if ( $html === NULL )
		{
			$this->updateSource( 'error', 'Failed to fetch page', 0 );
			return [ 'status' => 'error', 'message' => 'Failed to fetch page', 'inserted' => 0 ];
		}

		$extracted = $this->callAnthropic( $html );
		if ( $extracted === NULL )
		{
			$this->updateSource( 'error', 'Anthropic API call failed', 0 );
			return [ 'status' => 'error', 'message' => 'Anthropic API call failed', 'inserted' => 0 ];
		}

		$inserted = $this->insertRebates( $extracted );

		$this->updateSource( 'ok', 'Parsed ' . count( $extracted ) . ' rebate(s), inserted ' . $inserted, count( $extracted ) );
		return [ 'status' => 'ok', 'message' => 'Found ' . count( $extracted ) . ', inserted ' . $inserted, 'inserted' => $inserted ];
	}

	protected function fetchPage( string $url ): ?string
	{
		/* v1.0.12 — realistic Chrome-on-Windows header set replaces
		   the earlier "GunRack-Rebates/1.0" User-Agent. That obvious-
		   scraper UA got 403'd by any site with even the mildest
		   User-Agent sniffing (Beretta, Ruger discount portals,
		   several manufacturer promo landing pages).

		   This helps with basic UA sniffing / simple WAF rules only.
		   It will NOT bypass JS-challenge bot protection like
		   Cloudflare / Incapsula / PerimeterX — those require
		   actual JavaScript execution (a headless browser) to solve,
		   which is out of scope for this cheap header-only change.

		   Also switched the silent-catch to log to core_log category
		   'gdrebates' so future fetch failures surface without a
		   manual CLI reproduction (earlier bug hunts wasted time on
		   this). */
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
			try { \IPS\Log::log( 'gdrebates fetchPage ' . $url . ': ' . $e->getMessage(), 'gdrebates' ); } catch ( \Throwable ) {}
			return NULL;
		}
	}

	protected function callAnthropic( string $html ): ?array
	{
		/* v1.0.6 — the old strip_tags($html) removed TAGS but left the
		   CONTENTS of <script>/<style> blocks as raw text (minified
		   JS/CSS), which bloated the character count with useless
		   content and pushed the real rebate copy past the 80,000-char
		   truncation. Verified: Springfield Armory "Gear Up 2026 Model
		   2020" page — the word "rebate" sat at position ~97,256 in
		   the cleaned text, so Claude never saw it and correctly
		   returned an empty array ("Parsed 0 rebate(s)").

		   Strip script/style/comment BLOCKS entirely (tags AND
		   contents) BEFORE strip_tags, then collapse whitespace so
		   the character budget is spent on real content. Budget
		   raised to 350,000 chars (~90-100K tokens) — comfortably
		   within Claude Sonnet's context window with headroom for
		   the prompt + response, and enough to cover 3x this ~100K-
		   char cleaned example. A future page that still exceeds
		   the budget just truncates (existing behavior); the parser
		   run will still show it in last_message. */
		$clean = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $html );
		$clean = preg_replace( '#<style\b[^>]*>.*?</style>#is',   '', (string) $clean );
		$clean = preg_replace( '#<!--.*?-->#s',                    '', (string) $clean );
		$clean = strip_tags( (string) $clean );
		$clean = preg_replace( '/[ \t]+/',   ' ',    (string) $clean );
		$clean = preg_replace( '/\n{3,}/',   "\n\n", (string) $clean );
		$html  = mb_substr( trim( (string) $clean ), 0, 350000 );

		$prompt = "Extract every firearm rebate from this page. Return a JSON array of objects with keys: "
			. "title (string), rebate_type (cash|percent|gift_card|other), amount (number or null), "
			. "amount_text (human string like '$75 off'), eligible_models (string list of qualifying models), "
			. "start_date (YYYY-MM-DD or null), end_date (YYYY-MM-DD or null), submit_by (YYYY-MM-DD or null), "
			. "redemption_url (string or ''), image_url (string or ''), pdf_url (string or ''). "
			. "Return ONLY the JSON array, no markdown fences.";

		$body = json_encode( [
			'model'      => $this->model,
			'max_tokens' => 4096,
			'messages'   => [
				[ 'role' => 'user', 'content' => $prompt . "\n\nPAGE TEXT:\n" . $html ],
			],
		], JSON_UNESCAPED_SLASHES );

		try
		{
			$response = \IPS\Http\Url::external( 'https://api.anthropic.com/v1/messages' )
				->request( 120 )
				->setHeaders( [
					'Content-Type'      => 'application/json',
					'x-api-key'         => $this->apiKey,
					'anthropic-version'  => '2023-06-01',
				] )
				->post( $body );

			$data = json_decode( (string) $response, TRUE );
			if ( !isset( $data['content'][0]['text'] ) )
			{
				return NULL;
			}

			$text = trim( $data['content'][0]['text'] );
			if ( str_starts_with( $text, '```' ) )
			{
				$text = preg_replace( '/^```[a-z]*\n?/', '', $text );
				$text = preg_replace( '/\n?```$/', '', $text );
			}

			$rebates = json_decode( $text, TRUE );
			return is_array( $rebates ) ? $rebates : NULL;
		}
		catch ( \Throwable $e )
		{
			return NULL;
		}
	}

	protected function flatten( $v ): string
	{
		if ( is_array( $v ) )
		{
			$parts = [];
			foreach ( $v as $item )
			{
				if ( is_scalar( $item ) ) { $parts[] = trim( (string) $item ); }
			}
			return implode( ', ', array_filter( $parts, fn( $p ) => $p !== '' ) );
		}
		if ( is_scalar( $v ) ) { return trim( (string) $v ); }
		return '';
	}

	protected function insertRebates( array $rebates ): int
	{
		$inserted = 0;
		$sourceId = (int) $this->source['source_id'];
		$mfr      = $this->flatten( $this->source['manufacturer'] ?? '' );
		$srcUrl   = (string) $this->source['url'];

		foreach ( $rebates as $r )
		{
			$title = mb_substr( $this->flatten( $r['title'] ?? '' ), 0, 255 );
			if ( $title === '' )
			{
				continue;
			}

			$hash = sha1( $mfr . '|' . $title . '|' . $this->flatten( $r['end_date'] ?? '' ) );

			$existing = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_rebates', [ 'dedupe_hash=?', $hash ] )->first();
			if ( $existing > 0 )
			{
				continue;
			}

			$startTs  = $this->parseDate( $r['start_date'] ?? NULL );
			$endTs    = $this->parseDate( $r['end_date'] ?? NULL );
			$submitTs = $this->parseDate( $r['submit_by'] ?? NULL );

			\IPS\Db::i()->insert( 'gd_rebates', [
				'source_id'      => $sourceId,
				'manufacturer'   => $mfr,
				'title'          => $title,
				'rebate_type'    => $this->sanitizeType( $this->flatten( $r['rebate_type'] ?? 'other' ) ),
				'amount'         => isset( $r['amount'] ) && is_numeric( $r['amount'] ) ? (float) $r['amount'] : NULL,
				'amount_text'    => mb_substr( $this->flatten( $r['amount_text'] ?? '' ), 0, 80 ),
				'eligible_models'=> $this->flatten( $r['eligible_models'] ?? '' ),
				'start_date'     => $startTs,
				'end_date'       => $endTs,
				'submit_by'      => $submitTs,
				'redemption_url' => mb_substr( $this->flatten( $r['redemption_url'] ?? '' ), 0, 500 ),
				'source_url'     => $srcUrl,
				'image_url'      => mb_substr( $this->flatten( $r['image_url'] ?? '' ), 0, 500 ),
				'pdf_url'        => mb_substr( $this->flatten( $r['pdf_url'] ?? '' ), 0, 500 ),
				'status'         => 'pending',
				'dedupe_hash'    => $hash,
				'raw_extract'    => json_encode( $r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'created'        => time(),
				'updated'        => time(),
			] );
			$inserted++;
		}

		return $inserted;
	}

	protected function parseDate( $val ): ?int
	{
		if ( is_array( $val ) ) { $val = $this->flatten( $val ); }
		if ( !is_scalar( $val ) ) { return NULL; }
		if ( $val === NULL || $val === '' )
		{
			return NULL;
		}
		$ts = strtotime( (string) $val );
		return $ts !== false ? $ts : NULL;
	}

	protected function sanitizeType( string $type ): string
	{
		$type = is_array( $type ) ? '' : (string) $type;
		$allowed = [ 'cash', 'percent', 'gift_card', 'other' ];
		return in_array( $type, $allowed, true ) ? $type : 'other';
	}

	protected function updateSource( string $status, string $message, int $found ): void
	{
		try
		{
			\IPS\Db::i()->update( 'gd_rebate_sources', [
				'last_parsed_at' => time(),
				'last_status'    => $status,
				'last_message'   => mb_substr( $message, 0, 65000 ),
				'last_found'     => $found,
				'updated'        => time(),
			], [ 'source_id=?', (int) $this->source['source_id'] ] );
		}
		catch ( \Throwable $e ) {}
	}
}
class Parser extends _Parser {}
