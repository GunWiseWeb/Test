<?php
/**
 * @brief  GD Bills — Bill data access layer (port of FBT_Database)
 *
 * Static helpers over gd_bills + gd_bills_meta. Uses IPS-native
 * \IPS\Db::i()->select() / insert() / replace() — no raw preparedQuery
 * for row iteration.
 */

namespace IPS\gdbills;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Bill
{
	const VALID_TYPES = [ 'pending', 'enacted', 'law' ];

	/* Upsert a bill row. Match priority: legiscan_id, else (bill_number, state_code).
	   Returns ['action' => 'insert'|'update'|'skip', 'id' => int|null]. */
	public static function upsert( array $data ): array
	{
		$data = self::sanitize( $data );
		if ( $data['bill_number'] === '' || $data['state_code'] === '' )
		{
			return [ 'action' => 'skip', 'id' => null ];
		}

		$existingId = null;
		try
		{
			if ( !empty( $data['legiscan_id'] ) )
			{
				$existingId = (int) \IPS\Db::i()->select( 'id', 'gd_bills',
					[ 'legiscan_id=?', (int) $data['legiscan_id'] ]
				)->first();
			}
		}
		catch ( \UnderflowException ) {}
		catch ( \Throwable ) {}

		if ( !$existingId )
		{
			try
			{
				$existingId = (int) \IPS\Db::i()->select( 'id', 'gd_bills',
					[ 'bill_number=? AND state_code=?', $data['bill_number'], $data['state_code'] ]
				)->first();
			}
			catch ( \UnderflowException ) {}
			catch ( \Throwable ) {}
		}

		$now = date( 'Y-m-d H:i:s' );
		$data['updated_at'] = $now;

		try
		{
			if ( $existingId )
			{
				\IPS\Db::i()->update( 'gd_bills', $data, [ 'id=?', $existingId ] );
				return [ 'action' => 'update', 'id' => $existingId ];
			}
			$data['created_at'] = $now;
			$newId = (int) \IPS\Db::i()->insert( 'gd_bills', $data );
			return [ 'action' => 'insert', 'id' => $newId ];
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'Bill::upsert: ' . $e->getMessage(), 'gdbills' ); } catch ( \Throwable ) {}
			return [ 'action' => 'skip', 'id' => null ];
		}
	}

	public static function exists( string $billNumber, string $stateCode ): bool
	{
		try
		{
			\IPS\Db::i()->select( 'id', 'gd_bills',
				[ 'bill_number=? AND state_code=?', $billNumber, strtoupper( $stateCode ) ]
			)->first();
			return true;
		}
		catch ( \Throwable ) { return false; }
	}

	/* Returns rows ordered by last_action_date DESC, date_introduced DESC.
	   Optional date range filters on last_action_date BETWEEN dateFrom..dateTo
	   (inclusive). Rows with NULL last_action_date are excluded when either
	   bound is set. Empty string / null bounds mean "no constraint". */
	public static function getByState( string $stateCode, ?string $type = null, int $limit = 200, ?string $dateFrom = null, ?string $dateTo = null ): array
	{
		$where = [ [ 'state_code=?', strtoupper( $stateCode ) ] ];
		if ( $type !== null && in_array( $type, self::VALID_TYPES, true ) )
		{
			$where[] = [ 'bill_type=?', $type ];
		}
		$df = self::validDate( $dateFrom );
		$dt = self::validDate( $dateTo );
		if ( $df !== null ) { $where[] = [ 'last_action_date >= ?', $df ]; }
		if ( $dt !== null ) { $where[] = [ 'last_action_date <= ?', $dt ]; }

		$out = [];
		try
		{
			foreach ( \IPS\Db::i()->select( '*', 'gd_bills', $where,
				'last_action_date IS NULL ASC, last_action_date DESC, date_introduced DESC',
				$limit
			) as $row )
			{
				$out[] = $row;
			}
		}
		catch ( \Throwable $e ) { try { \IPS\Log::log( $e, 'gdbills' ); } catch ( \Throwable ) {} }
		return $out;
	}

	/* Three-bucket pull for the front page. Pass null/empty $state to query
	   across all states (used when the user applies a type/date filter
	   without picking a state). Returns ['law'=>[], 'enacted'=>[], 'pending'=>[]]. */
	public static function getThreeBuckets( ?string $state = null, ?string $dateFrom = null, ?string $dateTo = null, int $limit = 200 ): array
	{
		$buckets = [ 'law' => [], 'enacted' => [], 'pending' => [] ];
		$df = self::validDate( $dateFrom );
		$dt = self::validDate( $dateTo );
		$stateCode = $state !== null && $state !== '' ? strtoupper( $state ) : null;

		foreach ( array_keys( $buckets ) as $type )
		{
			if ( $stateCode !== null )
			{
				$buckets[ $type ] = self::getByState( $stateCode, $type, $limit, $df, $dt );
				continue;
			}
			$args = [ 'type' => $type, 'limit' => $limit ];
			if ( $df !== null ) { $args['date_from'] = $df; }
			if ( $dt !== null ) { $args['date_to']   = $dt; }
			$buckets[ $type ] = self::getAll( $args );
		}
		return $buckets;
	}

	/* Shared where-clause builder for getAll / getTotalCount so list and
	   count use IDENTICAL filters (pagination totals stay correct). Accepts:
	     state    exact match on state_code (uppercased)
	     type     exact match on bill_type (any string allowed; caller validates)
	     status   exact match on status
	     q        LIKE %q% on bill_title OR bill_number (free-text search)
	     date_from/date_to  last_action_date >= / <= (YYYY-MM-DD only) */
	protected static function buildWhere( array $args ): array
	{
		$state    = isset( $args['state'] )   ? strtoupper( (string) $args['state'] ) : '';
		$type     = isset( $args['type'] )    ? (string) $args['type']    : '';
		$status   = isset( $args['status'] )  ? (string) $args['status']  : '';
		$q        = isset( $args['q'] )       ? trim( (string) $args['q'] ) : '';
		$dateFrom = self::validDate( isset( $args['date_from'] ) ? (string) $args['date_from'] : null );
		$dateTo   = self::validDate( isset( $args['date_to'] )   ? (string) $args['date_to']   : null );

		$where = [];
		if ( $state  !== '' ) { $where[] = [ 'state_code=?', $state ]; }
		if ( $type   !== '' ) { $where[] = [ 'bill_type=?', $type ]; }
		if ( $status !== '' ) { $where[] = [ 'status=?', $status ]; }
		if ( $q      !== '' )
		{
			$like = '%' . $q . '%';
			$where[] = [ '(bill_title LIKE ? OR bill_number LIKE ?)', $like, $like ];
		}
		if ( $dateFrom !== null ) { $where[] = [ 'last_action_date >= ?', $dateFrom ]; }
		if ( $dateTo   !== null ) { $where[] = [ 'last_action_date <= ?', $dateTo   ]; }
		return $where;
	}

	/* Validate a YYYY-MM-DD string; return canonical form or null. */
	protected static function validDate( ?string $v ): ?string
	{
		if ( $v === null ) { return null; }
		$v = trim( $v );
		if ( $v === '' ) { return null; }
		if ( !preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) { return null; }
		return $v;
	}

	public static function getAll( array $args = [] ): array
	{
		$where = self::buildWhere( $args );
		$limit   = isset( $args['limit'] )   ? (int)    $args['limit']   : 50;
		$offset  = isset( $args['offset'] )  ? (int)    $args['offset']  : 0;
		$orderby = isset( $args['orderby'] ) ? (string) $args['orderby'] : 'last_action_date';
		$order   = isset( $args['order'] )   && strtolower( (string) $args['order'] ) === 'asc' ? 'ASC' : 'DESC';

		$allowedOrderby = [ 'last_action_date', 'date_introduced', 'state_code', 'bill_number' ];
		if ( !in_array( $orderby, $allowedOrderby, true ) ) { $orderby = 'last_action_date'; }

		$out = [];
		try
		{
			foreach ( \IPS\Db::i()->select( '*', 'gd_bills', $where ?: null,
				"{$orderby} {$order}",
				$limit > 0 ? [ $offset, $limit ] : null
			) as $row )
			{
				$out[] = $row;
			}
		}
		catch ( \Throwable $e ) { try { \IPS\Log::log( $e, 'gdbills' ); } catch ( \Throwable ) {} }
		return $out;
	}

	public static function getTotalCount( array $args = [] ): int
	{
		$where = self::buildWhere( $args );
		try
		{
			return (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_bills', $where ?: null )->first();
		}
		catch ( \Throwable ) { return 0; }
	}

	/* Returns [ 'XX' => count ] keyed by state code. Drives map shading. */
	public static function getCountsByState(): array
	{
		$out = [];
		try
		{
			foreach ( \IPS\Db::i()->select( 'state_code, COUNT(*) AS c', 'gd_bills',
				null, 'state_code ASC', null, 'state_code'
			) as $row )
			{
				$code = strtoupper( (string) $row['state_code'] );
				if ( $code !== '' ) { $out[ $code ] = (int) $row['c']; }
			}
		}
		catch ( \Throwable $e ) { try { \IPS\Log::log( $e, 'gdbills' ); } catch ( \Throwable ) {} }
		return $out;
	}

	public static function loadById( int $id ): ?array
	{
		try
		{
			$row = \IPS\Db::i()->select( '*', 'gd_bills', [ 'id=?', $id ] )->first();
			return is_array( $row ) ? $row : null;
		}
		catch ( \Throwable ) { return null; }
	}

	public static function delete( int $id ): bool
	{
		try
		{
			\IPS\Db::i()->delete( 'gd_bills', [ 'id=?', $id ] );
			return true;
		}
		catch ( \Throwable ) { return false; }
	}

	/* gd_bills_meta helpers. */
	public static function getMeta( string $key, ?string $default = null ): ?string
	{
		try
		{
			$row = \IPS\Db::i()->select( 'meta_value', 'gd_bills_meta', [ 'meta_key=?', $key ] )->first();
			return $row === null ? $default : (string) $row;
		}
		catch ( \Throwable ) { return $default; }
	}

	public static function setMeta( string $key, string $value ): void
	{
		try
		{
			\IPS\Db::i()->replace( 'gd_bills_meta', [
				'meta_key'   => $key,
				'meta_value' => $value,
				'updated_at' => date( 'Y-m-d H:i:s' ),
			] );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'Bill::setMeta: ' . $e->getMessage(), 'gdbills' ); } catch ( \Throwable ) {}
		}
	}

	protected static function sanitize( array $data ): array
	{
		$out = [
			'bill_number'        => substr( trim( (string) ( $data['bill_number'] ?? '' ) ), 0, 50 ),
			'bill_title'         => trim( (string) ( $data['bill_title']  ?? '' ) ),
			'state_code'         => strtoupper( substr( trim( (string) ( $data['state_code'] ?? '' ) ), 0, 2 ) ),
			'bill_type'          => in_array( $data['bill_type'] ?? '', self::VALID_TYPES, true ) ? $data['bill_type'] : 'pending',
			'status'             => substr( (string) ( $data['status'] ?? 'introduced' ), 0, 50 ),
			'progress_stage'     => isset( $data['progress_stage'] ) ? substr( (string) $data['progress_stage'], 0, 50 ) : null,
			'sponsor_name'       => isset( $data['sponsor_name'] ) ? substr( (string) $data['sponsor_name'], 0, 255 ) : null,
			'sponsor_party'      => isset( $data['sponsor_party'] ) ? substr( (string) $data['sponsor_party'], 0, 50 ) : null,
			'cosponsors'         => $data['cosponsors'] ?? null,
			'description'        => $data['description'] ?? null,
			'url'                => isset( $data['url'] ) ? substr( (string) $data['url'], 0, 500 ) : null,
			'date_introduced'    => self::cleanDate( $data['date_introduced']    ?? null ),
			'last_action_date'   => self::cleanDate( $data['last_action_date']   ?? null ),
			'last_action'        => $data['last_action'] ?? null,
			'passed_senate_date' => self::cleanDate( $data['passed_senate_date'] ?? null ),
			'passed_house_date'  => self::cleanDate( $data['passed_house_date']  ?? null ),
			'signed_date'        => self::cleanDate( $data['signed_date']        ?? null ),
			'legiscan_id'        => isset( $data['legiscan_id'] ) && $data['legiscan_id'] !== '' ? (int) $data['legiscan_id'] : null,
			'source'             => substr( (string) ( $data['source'] ?? 'manual' ), 0, 50 ),
		];
		return $out;
	}

	protected static function cleanDate( $v ): ?string
	{
		if ( $v === null || $v === '' || $v === '0000-00-00' ) { return null; }
		$ts = strtotime( (string) $v );
		if ( $ts === false ) { return null; }
		return date( 'Y-m-d', $ts );
	}
}

class Bill extends _Bill {}
