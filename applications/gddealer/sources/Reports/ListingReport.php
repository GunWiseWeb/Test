<?php

namespace IPS\gddealer\Reports;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _ListingReport
{
	const VALID_REASONS = [ 'wrong_price', 'dead_link', 'false_in_stock', 'wrong_product', 'other' ];

	const REASON_LABELS = [
		'wrong_price'    => 'Wrong price',
		'dead_link'      => 'Dead/broken link',
		'false_in_stock' => 'Out of stock (listed in stock)',
		'wrong_product'  => 'Wrong product / UPC mismatch',
		'other'          => 'Other',
	];

	public static function fileListingReport( int $dealerId, string $upc, int $reporterId, string $reason, string $note ): bool
	{
		if ( !in_array( $reason, self::VALID_REASONS, true ) )
		{
			$reason = 'other';
		}

		$note = mb_substr( trim( $note ), 0, 2000 );
		$now  = time();

		try
		{
			$existing = \IPS\Db::i()->select(
				'id',
				'gd_listing_reports',
				[ 'dealer_id=? AND upc=? AND reporter_member_id=? AND status=?', $dealerId, $upc, $reporterId, 'new' ]
			)->first();
			return false;
		}
		catch ( \UnderflowException ) {}
		catch ( \Throwable ) { return false; }

		try
		{
			\IPS\Db::i()->insert( 'gd_listing_reports', [
				'upc'                => $upc,
				'dealer_id'          => $dealerId,
				'reporter_member_id' => $reporterId,
				'reason'             => $reason,
				'note'               => $note !== '' ? $note : null,
				'status'             => 'new',
				'created_at'         => $now,
				'updated_at'         => null,
			] );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( $e, 'gddealer_listing_report' ); } catch ( \Throwable ) {}
			return false;
		}

		try
		{
			$dealerMember = \IPS\Member::load( $dealerId );
			if ( $dealerMember && $dealerMember->member_id )
			{
				$productTitle = '';
				try
				{
					$productTitle = (string) \IPS\Db::i()->select( 'title', 'gd_catalog', [ 'upc=?', $upc ] )->first();
				}
				catch ( \Throwable ) {}

				$reporter = \IPS\Member::load( $reporterId );
				$notification = new \IPS\Notification(
					\IPS\Application::load( 'gddealer' ),
					'listing_reported',
					$reporter,
					[ $dealerMember ],
					[
						'upc'           => $upc,
						'reason'        => $reason,
						'reason_label'  => self::REASON_LABELS[ $reason ] ?? $reason,
						'reporter_id'   => $reporterId,
						'reporter_name' => $reporter->name ?? 'A user',
						'product_title' => $productTitle,
					]
				);
				$notification->recipients->attach( $dealerMember );

				try { $notification->send(); }
				catch ( \Throwable $e ) { try { \IPS\Log::log( $e, 'gddealer_listing_report_notif' ); } catch ( \Throwable ) {} }
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( $e, 'gddealer_listing_report' ); } catch ( \Throwable ) {}
		}

		return true;
	}
}

class ListingReport extends _ListingReport {}
