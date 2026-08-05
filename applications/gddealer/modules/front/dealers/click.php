<?php
/**
 * @brief       GD Dealer Manager - Click Tracking Redirect
 * @package     IPS Community Suite
 * @subpackage  GD Dealer Manager
 * @since       v1.0.310
 *
 * Buy Now links route here as /dealers/click/?d={dealer_id}&u={upc}. We
 * resolve the destination listing_url server-side from gd_dealer_listings
 * (open-redirect safe), log the click into gd_click_log + roll up the
 * daily counter in gd_click_daily, bump the listing's 7d/30d counters,
 * then redirect the shopper to the dealer. Logging failures never block
 * the redirect.
 *
 * v1.0.330 bot filtering + rate-limit:
 *   Crawlers were inflating counts (single IPs hit dozens of distinct
 *   items in minutes). BEFORE logging we now:
 *     1. Check the User-Agent. Empty UA or a known-bot substring match
 *        → log NOTHING (no gd_click_log insert, no gd_click_daily bump,
 *        no listing 7d/30d increment). Still REDIRECT — never break
 *        the click-through.
 *     2. Dedupe within 30 minutes on (dealer_id, upc, ip_hash). If the
 *        same visitor already logged this listing recently, skip
 *        logging + rollup. The redirect still fires.
 *   When a real human click IS logged, we now also store the truncated
 *   user_agent (255 chars) for future auditing.
 */

namespace IPS\gddealer\modules\front\dealers;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _click extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = FALSE;

	/* Substring matches on the raw User-Agent (case-insensitive) that
	   identify clients we never want to log. Kept short and specific
	   to avoid false positives on legit browsers. */
	protected const BOT_UA_SUBSTRINGS = [
		'bot', 'crawl', 'spider', 'slurp', 'bingpreview',
		'facebookexternalhit', 'python-requests', 'curl', 'wget',
		'httpclient', 'headless', 'scrapy', 'semrush', 'ahrefs',
		'mj12', 'dotbot', 'petalbot', 'gptbot', 'ccbot', 'bytespider',
		'linkedinbot', 'whatsapp', 'telegrambot', 'discordbot',
		'applebot', 'yandex', 'duckduckgo', 'archive.org', 'ia_archiver',
	];

	/* Dedupe window in seconds — same (dealer_id, upc, ip_hash) tuple
	   inside this many seconds counts as one click. */
	protected const DEDUPE_WINDOW_SECONDS = 1800;

	public function execute(): void
	{
		parent::execute();
	}

	protected function manage(): void
	{
		$dealerId = (int) ( \IPS\Request::i()->d ?? 0 );
		$upc      = preg_replace( '/[^0-9]/', '', (string) ( \IPS\Request::i()->u ?? '' ) );

		if ( $dealerId <= 0 || $upc === '' )
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gdsearch&module=search&controller=results', 'front' )
			);
		}

		/* Resolve destination server-side (uq_dealer_upc => one row). URL never comes from the request. */
		$dest = '';
		try {
			$dest = (string) \IPS\Db::i()->select(
				'listing_url', 'gd_dealer_listings',
				[ 'dealer_id=? AND upc=? AND listing_status=?', $dealerId, $upc, 'active' ]
			)->first();
		}
		catch ( \UnderflowException ) { $dest = ''; }
		catch ( \Throwable $e ) {
			try { \IPS\Log::log( $e, 'gddealer_click' ); } catch ( \Throwable ) {}
		}

		/* No valid listing → bounce to the product page rather than erroring. */
		if ( $dest === '' || !preg_match( '#^https://#i', $dest ) )
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gdsearch&module=search&controller=results&do=product&upc=' . urlencode( $upc ), 'front' )
			);
		}

		/* -----------------------------------------------------------
		 * v1.0.330 — filter bots BEFORE any logging. Never block the
		 * redirect: bots still get forwarded (returning a 403 would
		 * break legit users whose UA happens to look bot-shaped).
		 * ----------------------------------------------------------- */
		$ua       = (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' );
		$uaLower  = strtolower( trim( $ua ) );
		$isBot    = ( $uaLower === '' );   /* empty UA => almost always a script */
		if ( !$isBot )
		{
			foreach ( self::BOT_UA_SUBSTRINGS as $needle )
			{
				if ( strpos( $uaLower, $needle ) !== FALSE )
				{
					$isBot = TRUE;
					break;
				}
			}
		}

		if ( !$isBot )
		{
			/* Record the click (best-effort; never block the redirect). */
			try {
				$member    = \IPS\Member::loggedIn();
				$memberId  = $member->member_id ? (int) $member->member_id : NULL;

				/* Resolve US state (2-char) from IP via IPS GeoLocation. Best-effort: any
				   failure (geo disabled, no license, private/unknown IP) leaves it NULL. */
				$userState = NULL;
				try {
					$geo    = \IPS\GeoLocation::getByIp( \IPS\Request::i()->ipAddress() );
					$region = trim( (string) ( $geo->region ?? '' ) );
					if ( $region !== '' ) {
						if ( strlen( $region ) === 2 && ctype_alpha( $region ) ) {
							$userState = strtoupper( $region );
						} else {
							$map = [
								'alabama'=>'AL','alaska'=>'AK','arizona'=>'AZ','arkansas'=>'AR','california'=>'CA',
								'colorado'=>'CO','connecticut'=>'CT','delaware'=>'DE','district of columbia'=>'DC',
								'florida'=>'FL','georgia'=>'GA','hawaii'=>'HI','idaho'=>'ID','illinois'=>'IL',
								'indiana'=>'IN','iowa'=>'IA','kansas'=>'KS','kentucky'=>'KY','louisiana'=>'LA',
								'maine'=>'ME','maryland'=>'MD','massachusetts'=>'MA','michigan'=>'MI','minnesota'=>'MN',
								'mississippi'=>'MS','missouri'=>'MO','montana'=>'MT','nebraska'=>'NE','nevada'=>'NV',
								'new hampshire'=>'NH','new jersey'=>'NJ','new mexico'=>'NM','new york'=>'NY',
								'north carolina'=>'NC','north dakota'=>'ND','ohio'=>'OH','oklahoma'=>'OK','oregon'=>'OR',
								'pennsylvania'=>'PA','rhode island'=>'RI','south carolina'=>'SC','south dakota'=>'SD',
								'tennessee'=>'TN','texas'=>'TX','utah'=>'UT','vermont'=>'VT','virginia'=>'VA',
								'washington'=>'WA','west virginia'=>'WV','wisconsin'=>'WI','wyoming'=>'WY',
								'puerto rico'=>'PR','guam'=>'GU','american samoa'=>'AS','virgin islands'=>'VI',
								'northern mariana islands'=>'MP',
							];
							$userState = $map[ strtolower( $region ) ] ?? NULL;
						}
					}
				} catch ( \Throwable ) { /* geo unavailable — leave NULL, never block the click */ }

				/* Hashed IP for unique-clicks dedup (never store raw IP). */
				$ipHash = NULL;
				try {
					$ip = (string) \IPS\Request::i()->ipAddress();
					if ( $ip !== '' ) {
						$ipHash = hash( 'sha256', $ip . '|' . \IPS\SUITE_UNIQUE_KEY );
					}
				} catch ( \Throwable ) {}

				/* v1.0.330 — 30-min dedupe on (dealer_id, upc, ip_hash). If the same
				   visitor already logged this listing recently, skip the whole
				   logging block. Redirect still fires below. */
				$alreadyLogged = FALSE;
				if ( $ipHash !== NULL )
				{
					try {
						$since = date( 'Y-m-d H:i:s', time() - self::DEDUPE_WINDOW_SECONDS );
						$hit   = \IPS\Db::i()->select(
							'id', 'gd_click_log',
							[ 'dealer_id=? AND upc=? AND ip_hash=? AND clicked_at >= ?', $dealerId, $upc, $ipHash, $since ],
							null, [ 0, 1 ]
						)->first();
						if ( $hit ) { $alreadyLogged = TRUE; }
					}
					catch ( \UnderflowException ) { /* no prior click; fall through and log */ }
					catch ( \Throwable ) { /* on error, log anyway rather than lose the click */ }
				}

				if ( !$alreadyLogged )
				{
					/* Insert the click row. user_agent (v1.0.330) and referrer
					   (v1.0.336) may not exist yet on installs that haven't run
					   the guarded ALTERs — three-tier fallback below drops the
					   newer column(s) on each retry so a schema-lag install
					   doesn't stop logging entirely.

					   v1.0.336 — capture $_SERVER['HTTP_REFERER'] into
					   gd_click_log.referrer so traffic-source analysis doesn't
					   depend on ephemeral Apache access logs (server only
					   retains ~1 day). NULL when absent (direct hits, apps,
					   privacy-mode browsers) — that's expected, not an error. */
					$uaShort  = $ua !== '' ? mb_substr( $ua, 0, 255 ) : NULL;
					$referrer = trim( (string) ( $_SERVER['HTTP_REFERER'] ?? '' ) );
					$referrer = $referrer !== '' ? mb_substr( $referrer, 0, 500 ) : NULL;
					try {
						\IPS\Db::i()->insert( 'gd_click_log', [
							'dealer_id'  => $dealerId,
							'upc'        => $upc,
							'member_id'  => $memberId,
							'ip_hash'    => $ipHash,
							'user_state' => $userState,
							'user_agent' => $uaShort,
							'referrer'   => $referrer,
							'clicked_at' => date( 'Y-m-d H:i:s' ),
						] );
					}
					catch ( \Throwable ) {
						/* Fallback 1: drop referrer (schema pre-v1.0.336). */
						try {
							\IPS\Db::i()->insert( 'gd_click_log', [
								'dealer_id'  => $dealerId,
								'upc'        => $upc,
								'member_id'  => $memberId,
								'ip_hash'    => $ipHash,
								'user_state' => $userState,
								'user_agent' => $uaShort,
								'clicked_at' => date( 'Y-m-d H:i:s' ),
							] );
						}
						catch ( \Throwable ) {
							/* Fallback 2: drop user_agent too (schema pre-v1.0.330). */
							try {
								\IPS\Db::i()->insert( 'gd_click_log', [
									'dealer_id'  => $dealerId,
									'upc'        => $upc,
									'member_id'  => $memberId,
									'ip_hash'    => $ipHash,
									'user_state' => $userState,
									'clicked_at' => date( 'Y-m-d H:i:s' ),
								] );
							}
							catch ( \Throwable $e2 ) {
								try { \IPS\Log::log( $e2, 'gddealer_click' ); } catch ( \Throwable ) {}
							}
						}
					}

					/* Daily rollup upsert (uq_dealer_date). */
					$today = date( 'Y-m-d' );
					\IPS\Db::i()->preparedQuery(
						'INSERT INTO `' . \IPS\Db::i()->prefix . 'gd_click_daily` (dealer_id, click_date, click_count) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE click_count = click_count + 1',
						[ $dealerId, $today ]
					);

					/* Rolling counters on the listing. */
					\IPS\Db::i()->preparedQuery(
						'UPDATE `' . \IPS\Db::i()->prefix . 'gd_dealer_listings` SET click_count_7d = click_count_7d + 1, click_count_30d = click_count_30d + 1 WHERE dealer_id = ? AND upc = ?',
						[ $dealerId, $upc ]
					);
				}
			}
			catch ( \Throwable $e ) {
				try { \IPS\Log::log( $e, 'gddealer_click' ); } catch ( \Throwable ) {}
			}
		}

		/* Forward to the dealer. Bots reach this line too — the redirect
		   is never skipped, only the analytics logging is. */
		\IPS\Output::i()->redirect( \IPS\Http\Url::external( $dest ) );
	}
}

class click extends _click {}
