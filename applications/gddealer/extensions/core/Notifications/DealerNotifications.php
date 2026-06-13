<?php
/**
 * GD Dealer Manager — Notifications extension (IPS 5).
 *
 * Extends NotificationsAbstract and exposes notification types via
 * configurationOptions(). Each notification key gets a parse_KEY()
 * method that returns title/url/content (plus optional author) to
 * render the inline notification card.
 */

namespace IPS\gddealer\extensions\core\Notifications;

use IPS\Extensions\NotificationsAbstract;
use IPS\Member;
use IPS\Notification\Inline;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class DealerNotifications extends NotificationsAbstract
{
	public static function configurationOptions( ?Member $member = NULL ): array
	{
		return [
			'new_dealer_review' => [
				'type'              => 'standard',
				'notificationTypes' => [ 'new_dealer_review' ],
				'title'             => 'gddealer_notif_new_review',
				'showTitle'         => true,
				'description'       => 'gddealer_notif_new_review_desc',
				'default'           => [ 'inline', 'email' ],
				'disabled'          => [],
			],
			'updated_dealer_review' => [
				'type'              => 'standard',
				'notificationTypes' => [ 'updated_dealer_review' ],
				'title'             => 'gddealer_notif_updated_review',
				'showTitle'         => true,
				'description'       => 'gddealer_notif_updated_review_desc',
				'default'           => [ 'inline', 'email' ],
				'disabled'          => [],
			],
			'review_disputed' => [
				'type'              => 'standard',
				'notificationTypes' => [ 'review_disputed' ],
				'title'             => 'gddealer_notif_review_disputed',
				'showTitle'         => true,
				'description'       => 'gddealer_notif_review_disputed_desc',
				'default'           => [ 'inline', 'email' ],
				'disabled'          => [],
			],
			'dispute_admin_review' => [
				'type'              => 'standard',
				'notificationTypes' => [ 'dispute_admin_review' ],
				'title'             => 'gddealer_notif_dispute_admin_review',
				'showTitle'         => true,
				'description'       => 'gddealer_notif_dispute_admin_review_desc',
				'default'           => [ 'inline', 'email' ],
				'disabled'          => [],
			],
			'dispute_upheld' => [
				'type'              => 'standard',
				'notificationTypes' => [ 'dispute_upheld' ],
				'title'             => 'gddealer_notif_dispute_upheld',
				'showTitle'         => true,
				'description'       => 'gddealer_notif_dispute_upheld_desc',
				'default'           => [ 'inline', 'email' ],
				'disabled'          => [],
			],
			'dispute_dismissed' => [
				'type'              => 'standard',
				'notificationTypes' => [ 'dispute_dismissed' ],
				'title'             => 'gddealer_notif_dispute_dismissed',
				'showTitle'         => true,
				'description'       => 'gddealer_notif_dispute_dismissed_desc',
				'default'           => [ 'inline', 'email' ],
				'disabled'          => [],
			],
			'dealer_responded' => [
				'type'              => 'standard',
				'notificationTypes' => [ 'dealer_responded' ],
				'title'             => 'gddealer_notif_dealer_responded',
				'showTitle'         => true,
				'description'       => 'gddealer_notif_dealer_responded_desc',
				'default'           => [ 'inline' ],
				'disabled'          => [],
			],
			'dispute_customer_responded' => [
				'type'              => 'standard',
				'notificationTypes' => [ 'dispute_customer_responded' ],
				'title'             => 'gddealer_notif_dispute_customer_responded',
				'showTitle'         => true,
				'description'       => 'gddealer_notif_dispute_customer_responded_desc',
				'default'           => [ 'inline', 'email' ],
				'disabled'          => [],
			],
			'dispute_outcome_reviewer' => [
				'type'              => 'standard',
				'notificationTypes' => [ 'dispute_outcome_reviewer' ],
				'title'             => 'gddealer_notif_dispute_outcome_reviewer',
				'showTitle'         => true,
				'description'       => 'gddealer_notif_dispute_outcome_reviewer_desc',
				'default'           => [ 'inline', 'email' ],
				'disabled'          => [],
			],
			'dispute_edit_requested' => [
				'type'              => 'standard',
				'notificationTypes' => [ 'dispute_edit_requested' ],
				'title'             => 'gddealer_notif_dispute_edit_requested',
				'showTitle'         => true,
				'description'       => 'gddealer_notif_dispute_edit_requested_desc',
				'default'           => [ 'inline', 'email' ],
				'disabled'          => [],
			],
			'support_ticket_new' => [
				'type'              => 'standard',
				'notificationTypes' => [ 'support_ticket_new' ],
				'title'             => 'gddealer_notif_support_ticket_new',
				'showTitle'         => true,
				'description'       => 'gddealer_notif_support_ticket_new_desc',
				'default'           => [ 'inline', 'email' ],
				'disabled'          => [],
			],
			'support_reply_to_dealer' => [
				'type'              => 'standard',
				'notificationTypes' => [ 'support_reply_to_dealer' ],
				'title'             => 'gddealer_notif_support_reply_to_dealer',
				'showTitle'         => true,
				'description'       => 'gddealer_notif_support_reply_to_dealer_desc',
				'default'           => [ 'inline', 'email' ],
				'disabled'          => [],
			],
			'support_reply_to_admin' => [
				'type'              => 'standard',
				'notificationTypes' => [ 'support_reply_to_admin' ],
				'title'             => 'gddealer_notif_support_reply_to_admin',
				'showTitle'         => true,
				'description'       => 'gddealer_notif_support_reply_to_admin_desc',
				'default'           => [ 'inline', 'email' ],
				'disabled'          => [],
			],
			'gddealer_ffl_verified' => [
				'type'              => 'standard',
				'notificationTypes' => [ 'gddealer_ffl_verified' ],
				'title'             => 'gddealer_notif_ffl_verified',
				'showTitle'         => true,
				'description'       => 'gddealer_notif_ffl_verified_desc',
				'default'           => [ 'inline', 'email' ],
				'disabled'          => [],
			],
			'gddealer_ffl_rejected' => [
				'type'              => 'standard',
				'notificationTypes' => [ 'gddealer_ffl_rejected' ],
				'title'             => 'gddealer_notif_ffl_rejected',
				'showTitle'         => true,
				'description'       => 'gddealer_notif_ffl_rejected_desc',
				'default'           => [ 'inline', 'email' ],
				'disabled'          => [],
			],
			'listing_reported' => [
				'type'              => 'standard',
				'notificationTypes' => [ 'listing_reported' ],
				'title'             => 'gddealer_notif_listing_reported',
				'showTitle'         => true,
				'description'       => 'gddealer_notif_listing_reported_desc',
				'default'           => [ 'inline', 'email' ],
				'disabled'          => [],
			],
		];
	}

	public function parse_new_dealer_review( Inline $notification, bool $htmlEscape = TRUE ): array
	{
		$extra = $notification->extra ?: [];
		return [
			'title'   => ( $extra['reviewer_name'] ?? 'A member' ) . ' left a review on your dealer profile',
			'url'     => \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=reviews' ),
			'content' => 'Visit your dashboard to view the review and post a response.',
			'author'  => NULL,
		];
	}

	public function parse_updated_dealer_review( Inline $notification, bool $htmlEscape = TRUE ): array
	{
		$extra = $notification->extra ?: [];
		return [
			'title'   => ( $extra['reviewer_name'] ?? 'A member' ) . ' updated their review on your dealer profile',
			'url'     => \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=reviews' ),
			'content' => 'A reviewer has updated their ratings or comments. Visit your dashboard to see the changes.',
			'author'  => NULL,
		];
	}

	public function parse_review_disputed( Inline $notification, bool $htmlEscape = TRUE ): array
	{
		$extra = $notification->extra ?: [];
		$slug  = (string) ( $extra['dealer_slug'] ?? '' );
		$id    = (int) ( $extra['review_id'] ?? 0 );
		$url   = $id > 0
			? \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=profile&dealer_slug=' . urlencode( $slug ) . '&dispute=' . $id )
			: \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=profile&dealer_slug=' . urlencode( $slug ) );
		return [
			'title'   => ( $extra['dealer_name'] ?? 'A dealer' ) . ' has disputed your review',
			'url'     => $url,
			'content' => 'You have 30 days to respond or the dispute will be resolved automatically.',
			'author'  => NULL,
		];
	}

	public function parse_dispute_admin_review( Inline $notification, bool $htmlEscape = TRUE ): array
	{
		$extra = $notification->extra ?: [];
		return [
			'title'   => 'A review dispute is ready for admin review',
			'url'     => \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers&do=disputes', 'admin' ),
			'content' => ( $extra['reviewer_name'] ?? 'A customer' ) . ' responded to a dispute on ' . ( $extra['dealer_name'] ?? 'a dealer' ) . '. Admin action required.',
			'author'  => NULL,
		];
	}

	public function parse_dispute_upheld( Inline $notification, bool $htmlEscape = TRUE ): array
	{
		return [
			'title'   => 'Your review contest was upheld',
			'url'     => \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=reviews' ),
			'content' => 'An admin has ruled in your favor. The review no longer affects your rating average.',
			'author'  => NULL,
		];
	}

	public function parse_dispute_dismissed( Inline $notification, bool $htmlEscape = TRUE ): array
	{
		return [
			'title'   => 'Your review contest was dismissed',
			'url'     => \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=reviews' ),
			'content' => 'An admin has dismissed your contest. The review remains visible.',
			'author'  => NULL,
		];
	}

	public function parse_dealer_responded( Inline $notification, bool $htmlEscape = TRUE ): array
	{
		$extra = $notification->extra ?: [];
		$slug  = (string) ( $extra['dealer_slug'] ?? '' );
		return [
			'title'   => ( $extra['dealer_name'] ?? 'A dealer' ) . ' responded to your review',
			'url'     => \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=profile&dealer_slug=' . urlencode( $slug ) ),
			'content' => 'The dealer has posted a public response to your review. Click to view it.',
			'author'  => NULL,
		];
	}

	public function parse_dispute_customer_responded( Inline $notification, bool $htmlEscape = TRUE ): array
	{
		$extra = $notification->extra ?: [];
		$slug  = (string) ( $extra['dealer_slug'] ?? '' );
		$id    = (int) ( $extra['review_id'] ?? 0 );
		$url   = $id > 0
			? \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=profile&dealer_slug=' . urlencode( $slug ) . '&dispute=' . $id )
			: \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=reviews' );
		return [
			'title'   => ( $extra['reviewer_name'] ?? 'A customer' ) . ' responded to your dispute',
			'url'     => $url,
			'content' => 'The customer has submitted their evidence. An admin will review and decide.',
			'author'  => NULL,
		];
	}

	public function parse_dispute_edit_requested( Inline $notification, bool $htmlEscape = TRUE ): array
	{
		$extra = $notification->extra ?: [];
		$slug  = (string) ( $extra['dealer_slug'] ?? '' );
		$id    = (int) ( $extra['dispute_id'] ?? 0 );
		$url   = $id > 0 && $slug !== ''
			? \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=profile&dealer_slug=' . urlencode( $slug ) . '&dispute=' . $id )
			: \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=reviews' );
		return [
			'title'   => 'Admin requested an update to your dispute response',
			'url'     => $url,
			'content' => 'An admin reviewed your response and asked for clarification. Click to update.',
			'author'  => NULL,
		];
	}

	public function parse_dispute_outcome_reviewer( Inline $notification, bool $htmlEscape = TRUE ): array
	{
		$extra   = $notification->extra ?: [];
		$outcome = (string) ( $extra['outcome'] ?? 'resolved' );
		$dealer  = (string) ( $extra['dealer_name'] ?? 'the dealer' );

		$title = match ( $outcome )
		{
			'dismissed' => 'Your review on ' . $dealer . ' has been upheld',
			'upheld'    => 'Your review dispute was resolved in ' . $dealer . '\'s favor',
			default     => 'Your review dispute was resolved',
		};

		$content = match ( $outcome )
		{
			'dismissed' => 'Admin reviewed the evidence and your review stands. It remains visible and continues to affect the dealer\'s rating.',
			'upheld'    => 'Admin ruled in the dealer\'s favor. Your review stays visible but no longer affects their rating.',
			default     => 'The admin team has resolved your dispute. View your reviews for details.',
		};

		return [
			'title'   => $title,
			'url'     => \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=reviews' ),
			'content' => $content,
			'author'  => NULL,
		];
	}

	public function parse_support_ticket_new( Inline $notification, bool $htmlEscape = TRUE ): array
	{
		$extra = $notification->extra ?: [];
		$id    = (int) ( $extra['ticket_id'] ?? 0 );
		$url   = $id > 0
			? \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=support&do=view&id=' . $id, 'admin' )
			: \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=support&do=tickets', 'admin' );
		return [
			'title'   => 'New support ticket from ' . (string) ( $extra['dealer_name'] ?? 'a dealer' ),
			'url'     => $url,
			'content' => (string) ( $extra['subject'] ?? '' ),
			'author'  => NULL,
		];
	}

	public function parse_support_reply_to_dealer( Inline $notification, bool $htmlEscape = TRUE ): array
	{
		$extra = $notification->extra ?: [];
		$id    = (int) ( $extra['ticket_id'] ?? 0 );
		$url   = $id > 0
			? \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=support&do=view&id=' . $id )
			: \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=support' );
		return [
			'title'   => 'Staff replied to your ticket: ' . (string) ( $extra['subject'] ?? '' ),
			'url'     => $url,
			'content' => 'Click to view the reply.',
			'author'  => NULL,
		];
	}

	public function parse_gddealer_ffl_verified( Inline $notification, bool $htmlEscape = TRUE ): array
	{
		return [
			'title'   => 'Your FFL license has been verified',
			'url'     => \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard' ),
			'content' => 'The green FFL Verified badge now appears on your public dealer profile.',
			'author'  => NULL,
		];
	}

	public function parse_gddealer_ffl_rejected( Inline $notification, bool $htmlEscape = TRUE ): array
	{
		$extra = $notification->extra ?: [];
		return [
			'title'   => 'Your FFL submission needs attention',
			'url'     => \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=customize' ),
			'content' => isset( $extra['reason'] ) ? 'Reason: ' . $extra['reason'] : 'Please review and re-submit your FFL from the dashboard.',
			'author'  => NULL,
		];
	}

	public function parse_support_reply_to_admin( Inline $notification, bool $htmlEscape = TRUE ): array
	{
		$extra = $notification->extra ?: [];
		$id    = (int) ( $extra['ticket_id'] ?? 0 );
		$url   = $id > 0
			? \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=support&do=view&id=' . $id, 'admin' )
			: \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=support&do=tickets', 'admin' );
		return [
			'title'   => (string) ( $extra['dealer_name'] ?? 'A dealer' ) . ' replied on ticket: ' . (string) ( $extra['subject'] ?? '' ),
			'url'     => $url,
			'content' => 'Click to view the reply.',
			'author'  => NULL,
		];
	}

	public function parse_listing_reported( Inline $notification, bool $htmlEscape = TRUE ): array
	{
		$extra       = $notification->extra ?: [];
		$upc         = (string) ( $extra['upc'] ?? '' );
		$reasonLabel = (string) ( $extra['reason_label'] ?? $extra['reason'] ?? 'an issue' );
		$reporter    = (string) ( $extra['reporter_name'] ?? 'A user' );
		$title       = (string) ( $extra['product_title'] ?? '' );

		$content = $reporter . ' reported your listing for ' . $reasonLabel;
		if ( $upc !== '' )
		{
			$content .= ' (UPC ' . $upc . ')';
		}
		if ( $title !== '' )
		{
			$content .= ' — ' . $title;
		}

		return [
			'title'   => 'A listing was reported',
			'url'     => \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=reportedListings' ),
			'content' => $content,
			'author'  => NULL,
		];
	}
}
