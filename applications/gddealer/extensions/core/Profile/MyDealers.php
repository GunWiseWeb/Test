<?php
/**
 * @brief       Profile extension: My Dealers (followed dealers list)
 * @package     IPS Community Suite
 * @subpackage  GD Dealer Manager
 * @since       v1.0.177 (margin + structure refined in v1.0.178)
 *
 * Adds a "My Dealers" tab to public IPS member profiles. The tab is
 * only shown if the member follows at least one dealer. Lists each
 * followed dealer with their profile link, tier badge, and follow date.
 *
 * Follow data lives in core_follow with follow_app='gddealer' and
 * follow_area='dealer'. Joined with gd_dealer_feed_config for dealer
 * metadata.
 *
 * v1.0.178 changes:
 *   - Outer wrapper now has padding:16px 20px; max-width:1100px; margin:0 auto
 *     so the tab content has breathing room (was edge-hugging in v1.0.177)
 *   - Lang key corrected from core_profile_mydealers to profile_gddealer_MyDealers
 *     (the actual key format IPS 5.0.18 uses for Profile extension tabs)
 */

namespace IPS\gddealer\extensions\core\Profile;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class MyDealers extends \IPS\Extensions\ProfileAbstract
{
	/**
	 * Should the tab be shown? Only if member follows at least one dealer.
	 */
	public function showTab(): bool
	{
		try
		{
			$count = (int) \IPS\Db::i()->select(
				'COUNT(*)',
				'core_follow',
				[
					'follow_app=? AND follow_area=? AND follow_member_id=?',
					'gddealer',
					'dealer',
					(int) $this->member->member_id,
				]
			)->first();

			return $count > 0;
		}
		catch ( \Throwable )
		{
			return FALSE;
		}
	}

	/**
	 * Render the tab content.
	 */
	public function render(): string
	{
		$rows = [];

		try
		{
			$select = \IPS\Db::i()->select(
				'cf.follow_id, cf.follow_added, cf.follow_rel_id,
				 d.dealer_id, d.dealer_name, d.dealer_slug, d.subscription_tier, d.is_founding_member, d.tagline, d.logo_url',
				[ 'core_follow', 'cf' ],
				[
					'cf.follow_app=? AND cf.follow_area=? AND cf.follow_member_id=?',
					'gddealer',
					'dealer',
					(int) $this->member->member_id,
				],
				'cf.follow_added DESC'
			);
			$select->join(
				[ 'gd_dealer_feed_config', 'd' ],
				'd.dealer_id = cf.follow_rel_id'
			);

			foreach ( $select as $row )
			{
				$rows[] = $row;
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'MyDealers profile tab query failed: ' . $e->getMessage(), 'gddealer_profile_mydealers' ); } catch ( \Throwable ) {}
			return '<div style="padding:24px 20px;text-align:center;color:#888">Unable to load followed dealers.</div>';
		}

		if ( empty( $rows ) )
		{
			$dirUrl = (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=directory', 'front', 'dealers_directory' );
			return '<div style="padding:32px 20px;text-align:center;color:#666">'
			     . '<p style="margin:0 0 12px;font-size:1.05em">You\'re not following any dealers yet.</p>'
			     . '<a href="' . htmlspecialchars( $dirUrl, ENT_QUOTES, 'UTF-8' ) . '" style="color:#2563eb;font-weight:600">Browse the dealer directory</a>'
			     . '</div>';
		}

		/* Tier color/label resolution. Mirrors the v1.0.173 founding-aware
		 * logic in directory.php and profile.php. */
		$founding = (string) ( \IPS\Settings::i()->gddealer_founding_badge_color   ?: '#b45309' );
		$proColor = (string) ( \IPS\Settings::i()->gddealer_pro_badge_color        ?: '#2563eb' );
		$entColor = (string) ( \IPS\Settings::i()->gddealer_enterprise_badge_color ?: '#7c3aed' );
		$basColor = (string) ( \IPS\Settings::i()->gddealer_basic_badge_color      ?: '#6b7280' );

		$cards = '';
		foreach ( $rows as $row )
		{
			$isFounding = !empty( $row['is_founding_member'] );

			if ( $isFounding )
			{
				$tierLabel = 'Founder';
				$tierColor = $founding;
			}
			else
			{
				$tier = (string) ( $row['subscription_tier'] ?? 'basic' );
				$tierLabel = ucfirst( $tier );
				$tierColor = match( $tier )
				{
					'founding'   => $founding,
					'pro'        => $proColor,
					'enterprise' => $entColor,
					default      => $basColor,
				};
			}

			$profileUrl = (string) \IPS\Http\Url::internal(
				'app=gddealer&module=dealers&controller=profile&dealer_slug=' . urlencode( (string) $row['dealer_slug'] ),
				'front',
				'dealers_profile',
				(string) $row['dealer_slug']
			);

			$dealerName = htmlspecialchars( (string) $row['dealer_name'], ENT_QUOTES, 'UTF-8' );
			$tagline    = htmlspecialchars( (string) ( $row['tagline'] ?? '' ), ENT_QUOTES, 'UTF-8' );
			$followedAt = (int) $row['follow_added'] > 0 ? date( 'M j, Y', (int) $row['follow_added'] ) : '';

			$logoUrl    = (string) ( $row['logo_url'] ?? '' );
			$logoHtml   = $logoUrl !== ''
				? '<img src="' . htmlspecialchars( $logoUrl, ENT_QUOTES, 'UTF-8' ) . '" alt="" style="width:48px;height:48px;border-radius:8px;object-fit:cover;flex-shrink:0">'
				: '<div style="width:48px;height:48px;border-radius:8px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-weight:700;color:#64748b">' . htmlspecialchars( mb_substr( (string) $row['dealer_name'], 0, 1 ), ENT_QUOTES, 'UTF-8' ) . '</div>';

			$cards .= '<div style="background:#fff;border:1px solid var(--i-border-color,#e0e0e0);border-radius:10px;padding:16px;display:flex;gap:14px;align-items:flex-start">'
			       .   $logoHtml
			       .   '<div style="flex:1;min-width:0">'
			       .     '<div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">'
			       .       '<a href="' . htmlspecialchars( $profileUrl, ENT_QUOTES, 'UTF-8' ) . '" style="font-weight:700;color:#0f172a;text-decoration:none;font-size:1em">' . $dealerName . '</a>'
			       .       '<span style="background:' . htmlspecialchars( $tierColor, ENT_QUOTES, 'UTF-8' ) . ';color:#fff;padding:2px 8px;border-radius:12px;font-size:0.7em;font-weight:700;text-transform:uppercase;letter-spacing:0.04em">' . htmlspecialchars( $tierLabel, ENT_QUOTES, 'UTF-8' ) . '</span>'
			       .     '</div>'
			       .     ( $tagline !== '' ? '<div style="font-size:0.85em;color:#64748b;margin-bottom:6px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' . $tagline . '</div>' : '' )
			       .     ( $followedAt !== '' ? '<div style="font-size:0.75em;color:#94a3b8">Followed ' . $followedAt . '</div>' : '' )
			       .   '</div>'
			       . '</div>';
		}

		$count = count( $rows );

		/* v1.0.178: outer wrapper has horizontal padding + max-width so the
		 * tab content has breathing room and doesn't stretch awkwardly on
		 * wide screens. */
		return '<div style="padding:16px 20px;max-width:1100px;margin:0 auto">'
		     .   '<div style="margin-bottom:16px;font-size:0.9em;color:#64748b">Following ' . $count . ' dealer' . ( $count === 1 ? '' : 's' ) . '</div>'
		     .   '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px">'
		     .     $cards
		     .   '</div>'
		     . '</div>';
	}
}
