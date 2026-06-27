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

			\IPS\Db::i()->insert( 'gd_click_log', [
				'dealer_id'  => $dealerId,
				'upc'        => $upc,
				'member_id'  => $memberId,
				'ip_hash'    => $ipHash,
				'user_state' => $userState,
				'clicked_at' => date( 'Y-m-d H:i:s' ),
			] );

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
		catch ( \Throwable $e ) {
			try { \IPS\Log::log( $e, 'gddealer_click' ); } catch ( \Throwable ) {}
		}

		/* Forward to the dealer. */
		\IPS\Output::i()->redirect( \IPS\Http\Url::external( $dest ) );
	}
}

class click extends _click {}
