<?php
/**
 * @brief       GD Master Catalog — Distributor Feed Model
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 * @since       12 Apr 2026
 *
 * ActiveRecord model for gd_distributor_feeds.
 * Each row is one feed configuration for one of the six distributors.
 * Auth credentials are stored encrypted via IPS's \IPS\Text\Encrypt.
 */

namespace IPS\gdcatalog\Feed;

/* To prevent PHP errors (extending class does not exist) revealing path */

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}
class Distributor extends \IPS\Patterns\ActiveRecord
{
	/**
	 * @brief [ActiveRecord] Database table
	 */
	public static ?string $databaseTable = 'gd_distributor_feeds';

	/**
	 * @brief [ActiveRecord] ID column
	 */
	public static string $databaseColumnId = 'id';

	/**
	 * @brief [ActiveRecord] No column prefix
	 */
	public static string $databasePrefix = '';

	/**
	 * @brief [ActiveRecord] Multiton store
	 */
	protected static array $multitons = [];

	/* ── Schedule interval map (key → seconds) ── */
	public const SCHEDULE_MAP = [
		'15min' => 900,
		'30min' => 1800,
		'1hr'   => 3600,
		'6hr'   => 21600,
		'daily' => 86400,
	];

	/**
	 * Load all active feeds ordered by priority.
	 *
	 * @return array<static>
	 */
	public static function loadActive(): array
	{
		$results = [];
		foreach (
			\IPS\Db::i()->select( '*', 'gd_distributor_feeds', [ 'active=?', 1 ], 'priority ASC' ) as $row
		)
		{
			$results[] = static::constructFromData( $row );
		}
		return $results;
	}

	/**
	 * Load all feeds (active and inactive) ordered by priority.
	 *
	 * @return array<static>
	 */
	public static function loadAll(): array
	{
		$results = [];
		foreach (
			\IPS\Db::i()->select( '*', 'gd_distributor_feeds', NULL, 'priority ASC' ) as $row
		)
		{
			$results[] = static::constructFromData( $row );
		}
		return $results;
	}

	/**
	 * Load a feed by distributor key (e.g. 'rsr_group').
	 *
	 * @param  string $distributor
	 * @return static
	 * @throws \OutOfRangeException
	 */
	public static function loadByDistributor( string $distributor ): static
	{
		$row = \IPS\Db::i()->select( '*', 'gd_distributor_feeds', [ 'distributor=?', $distributor ] )->first();
		return static::constructFromData( $row );
	}

	/* ================================================================
	 *  Credentials — encrypted at rest
	 * ================================================================ */

	/**
	 * Set auth credentials (encrypts before storage).
	 *
	 * @param  string|null $plaintext  JSON string or raw credential data
	 * @return void
	 */
	public function setCredentials( ?string $plaintext ): void
	{
		if ( $plaintext === null || $plaintext === '' )
		{
			$this->auth_credentials = null;
			return;
		}

		$this->auth_credentials = \IPS\Text\Encrypt::fromPlaintext( $plaintext )->tag();
	}

	/**
	 * Get auth credentials (decrypted).
	 *
	 * @return string|null  Plaintext credential data, or NULL if not set
	 */
	public function getCredentials(): ?string
	{
		if ( empty( $this->auth_credentials ) )
		{
			return null;
		}

		try
		{
			return \IPS\Text\Encrypt::fromTag( $this->auth_credentials )->decrypt();
		}
		catch ( \Exception )
		{
			return null;
		}
	}

	/* ================================================================
	 *  JSON field helpers — field_mapping, category_mapping, conflict_detection_fields
	 * ================================================================ */

	/**
	 * Get the field mapping as an associative array.
	 * Maps distributor field names → canonical gd_catalog column names.
	 *
	 * @return array<string, string>
	 */
	public function getFieldMapping(): array
	{
		return $this->decodeJson( $this->field_mapping );
	}

	/**
	 * Set the field mapping from an associative array.
	 *
	 * @param  array<string, string> $mapping
	 * @return void
	 */
	public function setFieldMapping( array $mapping ): void
	{
		$this->field_mapping = json_encode( $mapping );
	}

	/**
	 * Get the category mapping JSON (passed to CategoryMapper).
	 *
	 * @return string|null
	 */
	public function getCategoryMappingJson(): ?string
	{
		return $this->category_mapping ?: null;
	}

	/**
	 * Set the category mapping from an associative array.
	 *
	 * @param  array<string, string> $mapping  distributor string => canonical slug
	 * @return void
	 */
	public function setCategoryMapping( array $mapping ): void
	{
		$this->category_mapping = json_encode( $mapping );
	}

	/**
	 * Get conflict detection field toggles.
	 *
	 * @return array<string, bool>
	 */
	public function getConflictDetectionFields(): array
	{
		$decoded = $this->decodeJson( $this->conflict_detection_fields );
		if ( empty( $decoded ) )
		{
			/* Return defaults per Section 2.11.2 */
			return [
				'restricted_states' => true,
				'nfa_item'          => true,
				'requires_ffl'      => true,
				'caliber'           => true,
				'rounds_per_box'    => true,
				'category'          => false,
				'manufacturer'      => false,
				'description'       => false,
			];
		}
		return $decoded;
	}

	/**
	 * Set conflict detection field toggles.
	 *
	 * @param  array<string, bool> $fields
	 * @return void
	 */
	public function setConflictDetectionFields( array $fields ): void
	{
		$this->conflict_detection_fields = json_encode( $fields );
	}

	/**
	 * Check whether a specific field triggers conflict detection for this distributor.
	 *
	 * @param  string $field
	 * @return bool
	 */
	public function isConflictField( string $field ): bool
	{
		$fields = $this->getConflictDetectionFields();
		return !empty( $fields[$field] );
	}

	/* ================================================================
	 *  Schedule helpers
	 * ================================================================ */

	/**
	 * Whether this feed is due for import based on its schedule and last_run.
	 *
	 * @return bool
	 */
	public function isDue(): bool
	{
		if ( !$this->active )
		{
			return false;
		}

		if ( $this->last_run === null )
		{
			return true;
		}

		$interval = static::SCHEDULE_MAP[ $this->import_schedule ] ?? 21600;
		$lastRun  = strtotime( $this->last_run );

		return ( time() - $lastRun ) >= $interval;
	}

	/**
	 * Whether this feed is currently running.
	 *
	 * @return bool
	 */
	public function isRunning(): bool
	{
		return $this->last_run_status === 'running';
	}

	/**
	 * Mark this feed as currently running.
	 *
	 * @return void
	 */
	public function markRunning(): void
	{
		$this->last_run        = date( 'Y-m-d H:i:s' );
		$this->last_run_status = 'running';
		$this->save();
	}

	/**
	 * Mark this feed run as completed.
	 *
	 * @param  int $recordCount
	 * @return void
	 */
	public function markCompleted( int $recordCount ): void
	{
		$this->last_record_count = $recordCount;
		$this->last_run_status   = 'completed';
		$this->save();
	}

	/**
	 * Mark this feed run as failed.
	 *
	 * @return void
	 */
	public function markFailed(): void
	{
		$this->last_run_status = 'failed';
		$this->save();
	}

	/**
	 * v1.0.36: Mark this feed as locked. Sets locked=1, locked_at=now,
	 * locked_by=current member. Used by dashboard.php lockCatalog() after
	 * enqueuing the LockDistributorCatalog background task.
	 *
	 * @return void
	 */
	public function markLocked(): void
	{
		try
		{
			$memberId = (int) \IPS\Member::loggedIn()->member_id;
		}
		catch ( \Throwable )
		{
			$memberId = 0;
		}

		$this->locked    = 1;
		$this->locked_at = date( 'Y-m-d H:i:s' );
		$this->locked_by = $memberId > 0 ? $memberId : null;
		$this->save();
	}

	/**
	 * v1.0.36: Mark this feed as unlocked. Clears all three lock columns.
	 * Used by dashboard.php unlockCatalog() after clearing locked_fields
	 * on the actual product rows.
	 *
	 * @return void
	 */
	public function markUnlocked(): void
	{
		$this->locked    = 0;
		$this->locked_at = null;
		$this->locked_by = null;
		$this->save();
	}

	/**
	 * Get the human-readable distributor label.
	 *
	 * @return string
	 */
	public function distributorLabel(): string
	{
		return \IPS\Member::loggedIn()->language()->addToStack( 'gdcatalog_dist_' . $this->distributor );
	}

	/* ── Private ── */

	/**
	 * Decode a JSON column safely.
	 *
	 * @param  string|null $raw
	 * @return array
	 */
	protected function decodeJson( ?string $raw ): array
	{
		if ( $raw === null || $raw === '' )
		{
			return [];
		}
		$decoded = json_decode( $raw, true );
		return \is_array( $decoded ) ? $decoded : [];
	}
}
