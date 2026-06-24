<?php
namespace IPS\gddeals\Coupons;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

trait CouponBuilderTrait
{
	protected function buildCouponCards( array $deals ): array
	{
		if ( empty( $deals ) ) { return []; }
		$me = \IPS\Member::loggedIn();

		$myVotes = [];
		$ids = [];
		foreach ( $deals as $deal ) { $ids[] = (int) $deal->id; }
		if ( $me->member_id && $ids )
		{
			try
			{
				foreach ( \IPS\Db::i()->select( 'deal_id, vote', 'gd_deal_votes', [ 'member_id=? AND ' . \IPS\Db::i()->in( 'deal_id', $ids ), $me->member_id ] ) as $vr )
				{
					$myVotes[ (int) $vr['deal_id'] ] = (int) $vr['vote'];
				}
			}
			catch ( \Throwable ) {}
		}

		$slugMap = [];
		if ( \IPS\Application::appIsEnabled( 'gddealer' ) )
		{
			$dids = [];
			foreach ( $deals as $deal )
			{
				if ( $deal->source_badge === 'dealer' && $deal->dealer_id )
				{
					$dids[] = (int) $deal->dealer_id;
				}
			}
			if ( $dids )
			{
				try
				{
					foreach ( \IPS\Db::i()->select( 'dealer_id, dealer_slug', 'gd_dealer_feed_config', \IPS\Db::i()->in( 'dealer_id', array_values( array_unique( $dids ) ) ) ) as $r )
					{
						$slugMap[ (int) $r['dealer_id'] ] = (string) $r['dealer_slug'];
					}
				}
				catch ( \Throwable ) {}
			}
		}

		$out = [];
		foreach ( $deals as $deal )
		{
			$profileUrl = '';
			if ( $deal->source_badge === 'dealer' && $deal->dealer_id )
			{
				$slug = $slugMap[ (int) $deal->dealer_id ] ?? '';
				if ( $slug !== '' )
				{
					$profileUrl = (string) \IPS\Http\Url::internal(
						'app=gddealer&module=dealers&controller=profile&dealer_slug=' . urlencode( $slug ),
						'front'
					);
				}
			}

			if ( $deal->discount_pct )
			{
				$headline = rtrim( rtrim( number_format( (float) $deal->discount_pct, 1 ), '0' ), '.' ) . '% ' . $me->language()->addToStack( 'gddeals_off' );
			}
			elseif ( $deal->free_shipping )
			{
				$headline = $me->language()->addToStack( 'gddeals_free_ship' );
			}
			else
			{
				$headline = $deal->title;
			}

			$out[] = [
				'url'                => (string) $deal->url(),
				'title'              => $deal->title,
				'headline'           => $headline,
				'desc'               => $deal->description ?: '',
				'retailer'           => $deal->retailer_name,
				'source'             => $deal->source_badge,
				'dealer_profile_url' => $profileUrl,
				'code'               => $deal->promo_code ?: '',
				'shop_url'           => (string) ( $deal->deal_url ?: $deal->url() ),
				'expires'            => $deal->expires_at ? (string) \IPS\DateTime::ts( $deal->expires_at )->relative() : '',
				'heat_score'         => (int) $deal->upvotes - (int) $deal->downvotes,
				'vote_url'           => (string) $deal->url( 'vote' )->csrf(),
				'user_vote'          => $myVotes[ $deal->id ] ?? 0,
			];
		}
		return $out;
	}
}
