<?php
/**
 * @brief       GD Master Catalog — Conflict Resolver
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 * @since       12 Apr 2026
 *
 * Discrete, testable conflict resolution engine implementing every
 * rule in Section 2.2. Called by Importer for each field on each
 * existing product during an update pass.
 *
 * Rules implemented:
 *   - Standard priority (Section 2.2.1): RSR > Sports South > Davidson's > Lipsey's > Zanders > Bill Hicks
 *   - image_url: highest resolution wins regardless of priority
 *   - additional_images: merge all unique URLs from all distributors
 *   - description: longest non-empty text wins regardless of priority
 *   - msrp: highest value wins regardless of priority
 *   - rounds_per_box: cross-validate — flag for admin review if sources disagree
 *   - nfa_item / requires_ffl: any=true (conservative), cannot be set false by lower source
 *   - Admin locked fields: skip entirely (Section 2.2.3)
 */

namespace IPS\gdcatalog\Feed;

/* To prevent PHP errors (extending class does not exist) revealing path */

use IPS\gdcatalog\Catalog\Product;
use IPS\gdcatalog\Feed\Distributor;
use IPS\gdcatalog\Log\ConflictLog;
use IPS\gdcatalog\Log\ImportLog;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class ConflictResolver
{
	protected Product $product;
	protected Distributor $feed;
	protected ImportLog $log;

	/** @var int Count of fields this distributor won in this pass */
	protected int $fieldWins = 0;

	const SKIP_CONFLICT_FIELDS = [
		'raw_distributor_data', 'image_validated', 'image_validated_at',
		'image_http_status', 'distributor_last_seen', 'last_updated',
		'completeness_score', 'distributor_sources', 'primary_source',
	];

	public function __construct( Product $product, Distributor $feed, ImportLog $log )
	{
		$this->product = $product;
		$this->feed    = $feed;
		$this->log     = $log;
	}

	/**
	 * Resolve a single field: determine whether the incoming value should
	 * replace the current value based on the field's conflict rule.
	 *
	 * @param  string $field          Canonical field name
	 * @param  mixed  $incomingValue  Value from the current distributor's feed
	 * @return array{changed: bool, conflict: bool}
	 */
	public function resolve( string $field, mixed $incomingValue ): array
	{
		if ( in_array( $field, self::SKIP_CONFLICT_FIELDS, true ) )
		{
			return [ 'changed' => false, 'conflict' => false ];
		}

		$currentValue = $this->product->$field ?? null;

		/* ── Check field locks (Section 2.2.3) ── */
		if ( $this->isLocked( $field ) )
		{
			/* Still log a conflict if incoming differs from locked value (Section 2.11.5 step 6) */
			if ( $this->valuesDiffer( $currentValue, $incomingValue, $field ) )
			{
				$this->writeFeedConflict( $field, $currentValue, $incomingValue );
			}
			return [ 'changed' => false, 'conflict' => false ];
		}

		/* ── Dispatch to the correct rule ── */
		$rule = Product::ruleForField( $field );

		return match ( $rule )
		{
			Product::RULE_PRIORITY          => $this->resolvePriority( $field, $currentValue, $incomingValue ),
			Product::RULE_LONGEST           => $this->resolveLongest( $field, $currentValue, $incomingValue ),
			Product::RULE_HIGHEST_RES       => $this->resolveHighestRes( $field, $currentValue, $incomingValue ),
			Product::RULE_HIGHEST_VAL       => $this->resolveHighestVal( $field, $currentValue, $incomingValue ),
			Product::RULE_FLAGGED_FOR_REVIEW => $this->resolveFlagForReview( $field, $currentValue, $incomingValue ),
			Product::RULE_ANY_TRUE          => $this->resolveAnyTrue( $field, $currentValue, $incomingValue ),
			Product::RULE_MERGE_ALL         => $this->resolveMergeAll( $field, $currentValue, $incomingValue ),
			default                         => $this->resolvePriority( $field, $currentValue, $incomingValue ),
		};
	}

	/* ================================================================
	 *  RULE: Standard Priority (Section 2.2.1)
	 *  If this distributor has higher priority than whoever currently
	 *  owns the value, overwrite. If lower, keep existing.
	 * ================================================================ */

	protected function resolvePriority( string $field, mixed $current, mixed $incoming ): array
	{
		/* Empty incoming — nothing to do */
		if ( $this->isEmpty( $incoming ) )
		{
			return [ 'changed' => false, 'conflict' => false ];
		}

		/* Empty current — fill the gap regardless of priority */
		if ( $this->isEmpty( $current ) )
		{
			$this->product->$field = $incoming;
			$this->fieldWins++;
			return [ 'changed' => true, 'conflict' => false ];
		}

		/* Both non-empty — priority decides */
		$incomingRank = $this->distributorRank( $this->feed->distributor );
		$currentOwner = $this->findCurrentOwner( $field );
		$currentRank  = $currentOwner !== null
			? $this->distributorRank( $currentOwner )
			: PHP_INT_MAX;

		$conflict = $this->valuesDiffer( $current, $incoming, $field );

		if ( $incomingRank <= $currentRank )
		{
			/* Incoming has equal or higher priority — overwrite */
			if ( $conflict )
			{
				ConflictLog::record(
					$this->product->upc, $field,
					$this->feed->distributor, (string) $incoming,
					$currentOwner ?? 'unknown', (string) $current,
					Product::RULE_PRIORITY
				);
			}
			$this->product->$field = $incoming;
			$this->fieldWins++;
			return [ 'changed' => $conflict, 'conflict' => $conflict ];
		}

		/* Lower priority — log but don't overwrite */
		if ( $conflict )
		{
			ConflictLog::record(
				$this->product->upc, $field,
				$currentOwner ?? 'unknown', (string) $current,
				$this->feed->distributor, (string) $incoming,
				Product::RULE_PRIORITY
			);
		}

		return [ 'changed' => false, 'conflict' => $conflict ];
	}

	/* ================================================================
	 *  RULE: Longest Text — description (Section 2.2.2)
	 * ================================================================ */

	protected function resolveLongest( string $field, mixed $current, mixed $incoming ): array
	{
		if ( $this->isEmpty( $incoming ) )
		{
			return [ 'changed' => false, 'conflict' => false ];
		}

		$incomingLen = mb_strlen( (string) $incoming );
		$currentLen  = mb_strlen( (string) $current );
		$conflict    = $this->valuesDiffer( $current, $incoming, $field );

		if ( $incomingLen > $currentLen )
		{
			if ( $conflict )
			{
				ConflictLog::record(
					$this->product->upc, $field,
					$this->feed->distributor, mb_substr( (string) $incoming, 0, 200 ),
					$this->findCurrentOwner( $field ) ?? 'unknown',
					mb_substr( (string) $current, 0, 200 ),
					Product::RULE_LONGEST
				);
			}
			$this->product->$field = $incoming;
			$this->fieldWins++;
			return [ 'changed' => true, 'conflict' => $conflict ];
		}

		return [ 'changed' => false, 'conflict' => $conflict ];
	}

	/* ================================================================
	 *  RULE: Highest Resolution — image_url (Section 2.2.2)
	 *  Fetches image dimensions at import time.
	 * ================================================================ */

	protected function resolveHighestRes( string $field, mixed $current, mixed $incoming ): array
	{
		if ( $this->isEmpty( $incoming ) )
		{
			return [ 'changed' => false, 'conflict' => false ];
		}

		if ( $this->isEmpty( $current ) )
		{
			$this->product->$field = $incoming;
			$this->fieldWins++;
			return [ 'changed' => true, 'conflict' => false ];
		}

		$conflict = $this->valuesDiffer( $current, $incoming, $field );

		$incomingRes = $this->getImageResolution( (string) $incoming );
		$currentRes  = $this->getImageResolution( (string) $current );

		if ( $incomingRes > $currentRes )
		{
			if ( $conflict )
			{
				ConflictLog::record(
					$this->product->upc, $field,
					$this->feed->distributor, (string) $incoming . " ({$incomingRes}px)",
					$this->findCurrentOwner( $field ) ?? 'unknown',
					(string) $current . " ({$currentRes}px)",
					Product::RULE_HIGHEST_RES
				);
			}
			$this->product->$field = $incoming;
			$this->fieldWins++;
			return [ 'changed' => true, 'conflict' => $conflict ];
		}

		return [ 'changed' => false, 'conflict' => $conflict ];
	}

	/* ================================================================
	 *  RULE: Highest Value — msrp (Section 2.2.2)
	 * ================================================================ */

	protected function resolveHighestVal( string $field, mixed $current, mixed $incoming ): array
	{
		if ( $this->isEmpty( $incoming ) )
		{
			return [ 'changed' => false, 'conflict' => false ];
		}

		$incomingFloat = (float) $incoming;
		$currentFloat  = (float) $current;
		$conflict      = abs( $incomingFloat - $currentFloat ) > 0.001;

		if ( $incomingFloat > $currentFloat )
		{
			if ( $conflict )
			{
				ConflictLog::record(
					$this->product->upc, $field,
					$this->feed->distributor, (string) $incomingFloat,
					$this->findCurrentOwner( $field ) ?? 'unknown',
					(string) $currentFloat,
					Product::RULE_HIGHEST_VAL
				);
			}
			$this->product->$field = $incomingFloat;
			$this->fieldWins++;
			return [ 'changed' => true, 'conflict' => $conflict ];
		}

		return [ 'changed' => false, 'conflict' => $conflict ];
	}

	/* ================================================================
	 *  RULE: Flagged for Review — rounds_per_box (Section 2.2.2)
	 *  Cross-validate: if incoming disagrees, set record_status = admin_review.
	 * ================================================================ */

	protected function resolveFlagForReview( string $field, mixed $current, mixed $incoming ): array
	{
		if ( $this->isEmpty( $incoming ) )
		{
			return [ 'changed' => false, 'conflict' => false ];
		}

		/* No current value — accept incoming */
		if ( $this->isEmpty( $current ) )
		{
			$this->product->$field = $incoming;
			$this->fieldWins++;
			return [ 'changed' => true, 'conflict' => false ];
		}

		$incomingInt = (int) $incoming;
		$currentInt  = (int) $current;

		if ( $incomingInt === $currentInt )
		{
			return [ 'changed' => false, 'conflict' => false ];
		}

		/* Conflict — do NOT auto-resolve. Flag for admin review. */
		$this->product->record_status = Product::STATUS_ADMIN_REVIEW;

		ConflictLog::record(
			$this->product->upc, $field,
			'needs_review', (string) $currentInt . ' vs ' . (string) $incomingInt,
			$this->feed->distributor, (string) $incomingInt,
			Product::RULE_FLAGGED_FOR_REVIEW
		);

		return [ 'changed' => true, 'conflict' => true ];
	}

	/* ================================================================
	 *  RULE: Any = True — nfa_item, requires_ffl (Section 2.2.2)
	 *  If ANY distributor flags true, record is true. Cannot be unset
	 *  by a lower-priority source.
	 * ================================================================ */

	protected function resolveAnyTrue( string $field, mixed $current, mixed $incoming ): array
	{
		$incomingBool = (bool) $incoming;
		$currentBool  = (bool) $current;

		/* Already true — never set back to false */
		if ( $currentBool )
		{
			if ( !$incomingBool )
			{
				/* Log that a distributor disagrees, but don't change */
				ConflictLog::record(
					$this->product->upc, $field,
					$this->findCurrentOwner( $field ) ?? 'existing', 'true',
					$this->feed->distributor, 'false',
					Product::RULE_ANY_TRUE
				);
			}
			return [ 'changed' => false, 'conflict' => !$incomingBool ];
		}

		/* Currently false, incoming true — set to true */
		if ( $incomingBool )
		{
			$this->product->$field = 1;
			$this->fieldWins++;

			ConflictLog::record(
				$this->product->upc, $field,
				$this->feed->distributor, 'true',
				$this->findCurrentOwner( $field ) ?? 'none', 'false',
				Product::RULE_ANY_TRUE
			);

			return [ 'changed' => true, 'conflict' => true ];
		}

		return [ 'changed' => false, 'conflict' => false ];
	}

	/* ================================================================
	 *  RULE: Merge All — additional_images (Section 2.2.2)
	 *  Merge all unique image URLs from all distributors.
	 * ================================================================ */

	protected function resolveMergeAll( string $field, mixed $current, mixed $incoming ): array
	{
		if ( $this->isEmpty( $incoming ) )
		{
			return [ 'changed' => false, 'conflict' => false ];
		}

		/* Parse incoming — could be JSON array or single URL */
		$incomingUrls = [];
		if ( str_starts_with( trim( (string) $incoming ), '[' ) )
		{
			$decoded = json_decode( (string) $incoming, true );
			if ( \is_array( $decoded ) )
			{
				$incomingUrls = $decoded;
			}
		}
		else
		{
			/* Single URL or comma-separated */
			$incomingUrls = array_filter( array_map( 'trim', explode( ',', (string) $incoming ) ) );
		}

		if ( empty( $incomingUrls ) )
		{
			return [ 'changed' => false, 'conflict' => false ];
		}

		$beforeCount = \count( $this->product->getAdditionalImages() );
		$this->product->mergeAdditionalImages( $incomingUrls );
		$afterCount = \count( $this->product->getAdditionalImages() );

		$changed = $afterCount > $beforeCount;

		return [ 'changed' => $changed, 'conflict' => false ];
	}

	/* ================================================================
	 *  Field Lock Checking (Section 2.11.5)
	 * ================================================================ */

	/**
	 * Check if a field is locked via gd_field_locks or the product's locked_fields JSON.
	 *
	 * @param  string $field
	 * @return bool
	 */
	protected function isLocked( string $field ): bool
	{
		/* Check legacy locked_fields JSON on product */
		if ( $this->product->isFieldLocked( $field ) )
		{
			return true;
		}

		/* Check gd_field_locks table */
		try
		{
			$lock = \IPS\Db::i()->select(
				'*', 'gd_field_locks',
				[
					'upc=? AND field_name=? AND listing_id IS NULL',
					$this->product->upc, $field
				]
			)->first();
		}
		catch ( \Throwable )
		{
			/* v1.0.121 (Phase 5): broadened from \UnderflowException per
			 * CLAUDE.md rule #35. \UnderflowException only covered the
			 * "row not found" case; a genuine DB error (bad column,
			 * missing table, connection failure) throws
			 * \IPS\Db\Exception / \Error and would escape here and
			 * abort the caller's whole resolveConflicts pass. Treat
			 * every failure the same as "no lock found" — the field
			 * remains eligible for feed writes, which matches the
			 * safest default when lock state cannot be determined. */
			return false;
		}

		/* Hard lock blocks ALL distributors */
		if ( $lock['lock_type'] === 'hard' )
		{
			return true;
		}

		/* Distributor-specific lock — only blocks THIS distributor */
		if (
			$lock['lock_type'] === 'distributor_specific'
			&& (int) $lock['locked_distributor_id'] === (int) $this->feed->id
		)
		{
			return true;
		}

		return false;
	}

	/* ================================================================
	 *  Feed Conflict Detection (Section 2.11.2)
	 * ================================================================ */

	/**
	 * Write a gd_feed_conflicts record for a locked or compliance-configured field.
	 *
	 * @param  string      $field
	 * @param  mixed       $currentValue
	 * @param  mixed       $incomingValue
	 * @return void
	 */
	protected function writeFeedConflict( string $field, mixed $currentValue, mixed $incomingValue ): void
	{
		\IPS\Db::i()->insert( 'gd_feed_conflicts', [
			'upc'              => $this->product->upc,
			'listing_id'       => null,
			'distributor_id'   => (int) $this->feed->id,
			'field_name'       => $field,
			'current_value'    => (string) ( $currentValue ?? '' ),
			'incoming_value'   => (string) ( $incomingValue ?? '' ),
			'import_id'        => (int) $this->log->id,
			'detected_at'      => date( 'Y-m-d H:i:s' ),
			'status'           => 'pending',
			'auto_resolve_at'  => date( 'Y-m-d H:i:s', time() + ( (int) \IPS\Settings::i()->gdcatalog_auto_resolve_hours * 3600 ) ),
			'resolved_by'      => null,
			'resolved_at'      => null,
			'resolution_note'  => null,
		]);
	}

	/* ================================================================
	 *  Helpers
	 * ================================================================ */

	/**
	 * Get the priority rank of a distributor (0 = highest).
	 *
	 * @param  string $distributor
	 * @return int
	 */
	protected function distributorRank( string $distributor ): int
	{
		$index = array_search( $distributor, Product::$distributorPriority, true );
		return $index !== false ? $index : PHP_INT_MAX;
	}

	/**
	 * Determine which distributor currently "owns" a field value.
	 * Uses primary_source as a proxy — not perfect but avoids
	 * a separate per-field ownership table.
	 *
	 * @param  string $field
	 * @return string|null
	 */
	protected function findCurrentOwner( string $field ): ?string
	{
		$primary = $this->product->primary_source;
		return $primary !== '' ? $primary : null;
	}

	/**
	 * Check whether two values are meaningfully different.
	 *
	 * @param  mixed $a
	 * @param  mixed $b
	 * @return bool
	 */
	protected function valuesDiffer( mixed $a, mixed $b, string $field = '' ): bool
	{
		$normA = $this->normalizeValue( $field, $a );
		$normB = $this->normalizeValue( $field, $b );
		return $normA !== $normB;
	}

	protected function normalizeValue( string $field, mixed $value ): string
	{
		$numericFields = [
			'overall_length', 'barrel_length', 'weight_lbs', 'weight_oz',
			'msrp', 'capacity', 'rounds_per_box', 'boxes_per_case',
			'muzzle_velocity', 'muzzle_energy', 'bullet_weight',
			'magnification', 'objective_mm', 'tube_diameter', 'eye_relief',
		];

		$str = trim( (string) ( $value ?? '' ) );

		if ( in_array( $field, $numericFields, true ) && is_numeric( $str ) )
		{
			return rtrim( rtrim( number_format( (float) $str, 4, '.', '' ), '0' ), '.' );
		}

		return strtolower( $str );
	}

	/**
	 * Check if a value is empty/null/blank.
	 *
	 * @param  mixed $value
	 * @return bool
	 */
	protected function isEmpty( mixed $value ): bool
	{
		return $value === null || $value === '' || $value === false;
	}

	/**
	 * Get image resolution (width * height) for the highest-res rule.
	 * Attempts to read dimensions from the image URL.
	 * Falls back to 0 if dimensions cannot be determined.
	 *
	 * @param  string $url
	 * @return int    Total pixels (width * height)
	 */
	protected function getImageResolution( string $url ): int
	{
		/* Check for dimension hints in URL (e.g. _800x600.jpg) */
		if ( preg_match( '/[_\-x](\d{2,5})x(\d{2,5})\./i', $url, $m ) )
		{
			return (int) $m[1] * (int) $m[2];
		}

		/* Try fetching headers / image size remotely */
		try
		{
			$tmpFile = tempnam( sys_get_temp_dir(), 'gd_img_' );
			$response = \IPS\Http\Url::external( $url )
				->request( 10 )
				->get();

			if ( $response->httpResponseCode === 200 )
			{
				file_put_contents( $tmpFile, (string) $response );
				$size = @getimagesize( $tmpFile );
				unlink( $tmpFile );

				if ( $size !== false )
				{
					return $size[0] * $size[1];
				}
			}
			else
			{
				@unlink( $tmpFile );
			}
		}
		catch ( \Throwable )
		{
			@unlink( $tmpFile ?? '' );
		}

		return 0;
	}

	/**
	 * Get the number of fields this distributor won in this resolution pass.
	 *
	 * @return int
	 */
	public function getFieldWins(): int
	{
		return $this->fieldWins;
	}
}
