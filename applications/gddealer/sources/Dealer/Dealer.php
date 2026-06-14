<?php
/**
 * @brief       GD Dealer Manager — Dealer ActiveRecord
 * @package     IPS Community Suite
 * @subpackage  GD Dealer Manager
 * @since       15 Apr 2026
 *
 * Wraps gd_dealer_feed_config — one row per dealer, keyed by dealer_id.
 * dealer_id maps 1:1 to \IPS\Member::$member_id of the dealer's primary
 * contact account. IPS Commerce group promotion controls subscription_tier;
 * the Dealer record is created on first successful checkout.
 */

namespace IPS\gddealer\Dealer;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Dealer extends \IPS\Patterns\ActiveRecord
{
	public static ?string $databaseTable   = 'gd_dealer_feed_config';
	public static string $databasePrefix   = '';
	public static string $databaseColumnId = 'dealer_id';

	/* v169: per-class multiton cache so we don't accidentally
	 * return another ActiveRecord subclass cached at the same PK. */
	protected static array $multitons = [];
	protected static ?array $multitonMap = NULL;

	public static string $application = 'gddealer';
	public static string $module      = 'dealers';
	public static string $controller  = 'directory';

	const TIER_BASIC      = 'basic';
	const TIER_PRO        = 'pro';
	const TIER_ENTERPRISE = 'enterprise';
	const TIER_MAX        = 'max';
	const TIER_FOUNDING   = 'founding';

	/** Scheduling per tier — used by DealerImportFeeds task */
	public static array $tierSchedules = [
		'basic'      => '24hr',
		'pro'        => '12hr',
		'enterprise' => '6hr',
		'max'        => '3hr',
		'founding'   => '1hr',
	];

	/**
	 * Get the effective import schedule for this dealer. Founding members
	 * get 1hr sync regardless of their current subscription_tier. All others
	 * get their tier's normal schedule from $tierSchedules.
	 */
	public function getEffectiveImportSchedule(): string
	{
		if ( !empty( $this->is_founding_member ) )
		{
			return '1hr';
		}
		$tier = (string) ( $this->subscription_tier ?? 'basic' );
		return self::$tierSchedules[ $tier ] ?? '24hr';
	}

	/**
	 * Get the display badge label for this dealer. Founding members show
	 * 'Founder' regardless of subscription_tier. Otherwise show ucfirst of
	 * the tier name (e.g. 'Pro', 'Enterprise', 'Basic').
	 */
	public function getBadgeLabel(): string
	{
		if ( !empty( $this->is_founding_member ) )
		{
			return 'Founder';
		}
		return ucfirst( (string) ( $this->subscription_tier ?? 'basic' ) );
	}

	/** Monthly prices for MRR calculation */
	public static array $tierMrr = [
		'basic'      => 39.0,
		'pro'        => 99.0,
		'enterprise' => 249.0,
		'max'        => 399.0,
		'founding'   => 0.0,
	];

	/** Max active listings per tier. PHP_INT_MAX = unlimited. */
	public static array $tierListingCaps = [
		'basic'      => 500,
		'pro'        => 2500,
		'enterprise' => 6000,
		'max'        => PHP_INT_MAX,
		'founding'   => PHP_INT_MAX,
	];

	public function getListingCap(): int
	{
		if ( !empty( $this->is_founding_member ) )
		{
			return PHP_INT_MAX;
		}
		$tier = (string) ( $this->subscription_tier ?? 'basic' );
		return self::$tierListingCaps[ $tier ] ?? 500;
	}

	/**
	 * Load every dealer row ordered by dealer name.
	 *
	 * @return static[]
	 */
	public static function loadAll(): array
	{
		$out = [];
		foreach ( \IPS\Db::i()->select( '*', 'gd_dealer_feed_config', null, 'dealer_name ASC' ) as $row )
		{
			$out[] = static::constructFromData( $row );
		}
		return $out;
	}

	/**
	 * Only the dealers whose current time slot matches their subscription tier.
	 *
	 * @return static[]
	 */
	public static function loadDueForImport(): array
	{
		$now = new \DateTime();

		$out = [];
		foreach ( \IPS\Db::i()->select( '*', 'gd_dealer_feed_config', [
			'active=? AND suspended=? AND (
				( feed_delivery_mode=? AND feed_url IS NOT NULL AND feed_url != ? )
				OR feed_delivery_mode=?
			)',
			1, 0, 'url', '', 'upload'
		] ) as $row )
		{
			if ( ( $row['feed_delivery_mode'] ?? '' ) === 'upload' )
			{
				$hasUpload = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_dealer_feed_uploads', [ 'dealer_id=?', (int) $row['dealer_id'] ] )->first();
				if ( !$hasUpload )
				{
					continue;
				}
			}

			$dealer = static::constructFromData( $row );
			if ( $dealer->isDueForImport( $now ) )
			{
				$out[] = $dealer;
			}
		}
		return $out;
	}

	/**
	 * Returns TRUE if this dealer's feed should run now given their schedule.
	 */
	public function isDueForImport( \DateTime $now ): bool
	{
		if ( !$this->active || $this->suspended )
		{
			return false;
		}
		if ( $this->last_run === null )
		{
			return true;
		}

		$schedule = $this->getEffectiveImportSchedule();
		$intervals = [
			'15min' => 15 * 60,
			'30min' => 30 * 60,
			'1hr'   => 3600,
			'3hr'   => 3 * 3600,
			'6hr'   => 6 * 3600,
			'12hr'  => 12 * 3600,
			'24hr'  => 86400,
			'daily' => 86400,
		];
		$seconds = $intervals[ $schedule ] ?? 86400;

		$last = new \DateTime( (string) $this->last_run );
		return ( $now->getTimestamp() - $last->getTimestamp() ) >= $seconds;
	}

	/**
	 * Decrypt auth credentials JSON — null if not configured.
	 */
	public function getCredentials(): ?string
	{
		if ( empty( $this->auth_credentials ) )
		{
			return null;
		}
		try
		{
			return \IPS\Text\Encrypt::fromCipher( (string) $this->auth_credentials )->decrypt();
		}
		catch ( \Exception )
		{
			return (string) $this->auth_credentials;
		}
	}

	public function setCredentials( ?string $plain ): void
	{
		if ( $plain === null || $plain === '' )
		{
			$this->auth_credentials = null;
			return;
		}
		$this->auth_credentials = (string) \IPS\Text\Encrypt::fromPlaintext( $plain )->cipher;
	}

	/**
	 * Generate and persist a new API key for this dealer.
	 */
	public function rotateApiKey(): string
	{
		$key = bin2hex( random_bytes( 24 ) );
		$this->api_key = $key;
		$this->save();
		return $key;
	}

	/**
	 * MRR contribution for this dealer based on their current tier.
	 */
	public function mrrContribution(): float
	{
		return (float) ( static::$tierMrr[ $this->subscription_tier ] ?? 0.0 );
	}

	/**
	 * Return the IPS member group ID configured for a given subscription tier.
	 * Returns 0 if the setting is unconfigured or the tier is unknown.
	 */
	public static function groupIdForTier( string $tier ): int
	{
		$map = [
			self::TIER_FOUNDING   => 'gddealer_group_founding',
			self::TIER_BASIC      => 'gddealer_group_basic',
			self::TIER_PRO        => 'gddealer_group_pro',
			self::TIER_ENTERPRISE => 'gddealer_group_enterprise',
			self::TIER_MAX        => 'gddealer_group_max',
		];

		$key = $map[ $tier ] ?? null;
		if ( $key === null )
		{
			return 0;
		}

		return (int) \IPS\Settings::i()->$key;
	}

	/**
	 * Return all configured dealer group IDs (non-zero, deduplicated).
	 *
	 * @return int[]
	 */
	public static function allDealerGroupIds(): array
	{
		$ids = [];
		foreach ( [ self::TIER_FOUNDING, self::TIER_BASIC, self::TIER_PRO, self::TIER_ENTERPRISE, self::TIER_MAX ] as $tier )
		{
			$gid = static::groupIdForTier( $tier );
			if ( $gid > 0 )
			{
				$ids[] = $gid;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Check whether the given member belongs to any of the configured
	 * dealer member groups (primary or secondary).
	 */
	public static function isDealerMember( \IPS\Member $member ): bool
	{
		if ( !$member->member_id )
		{
			return false;
		}

		$groupIds = static::allDealerGroupIds();
		if ( empty( $groupIds ) )
		{
			return false;
		}

		if ( in_array( (int) $member->member_group_id, $groupIds, true ) )
		{
			return true;
		}

		$others = $member->mgroup_others
			? array_filter( array_map( 'intval', explode( ',', (string) $member->mgroup_others ) ) )
			: [];

		return !empty( array_intersect( $groupIds, $others ) );
	}

	/**
	 * Fetch the active subscription tier for a member's dealer record.
	 * Returns 'basic' if no active dealer record is found. Looks up
	 * gd_dealer_feed_config by dealer_id (which equals member_id for
	 * the primary contact account).
	 */
	public static function getTier( \IPS\Member $member ): string
	{
		if ( !$member->member_id )
		{
			return 'basic';
		}

		try
		{
			$tier = \IPS\Db::i()->select( 'subscription_tier', 'gd_dealer_feed_config',
				[ 'dealer_id=? AND active=?', (int) $member->member_id, 1 ]
			)->first();
			return (string) ( $tier ?: 'basic' );
		}
		catch ( \Exception )
		{
			return 'basic';
		}
	}

	/**
	 * Whether a member may access the support ticket system.
	 * Available to pro, enterprise, and founding tiers only.
	 */
	public static function canAccessSupport( \IPS\Member $member ): bool
	{
		if ( !$member->member_id )
		{
			return false;
		}
		return in_array( static::getTier( $member ), [ 'pro', 'enterprise', 'max', 'founding' ], true );
	}

	/**
	 * Default ticket priority for a member based on tier.
	 * Enterprise defaults to 'high', everyone else to 'normal'.
	 */
	public static function defaultTicketPriority( \IPS\Member $member ): string
	{
		return in_array( static::getTier( $member ), [ 'enterprise', 'max' ], true ) ? 'high' : 'normal';
	}

	public function url(): \IPS\Http\Url
	{
		return \IPS\Http\Url::internal(
			'app=gddealer&module=dealers&controller=profile&dealer_slug=' . urlencode( (string) $this->dealer_slug )
		);
	}

	public function get__title(): string
	{
		return (string) $this->dealer_name;
	}
}

class Dealer extends _Dealer {}
