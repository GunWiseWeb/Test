<?php
/**
 * @brief  GD Compliance — Report notification extension
 *
 * Notifies the reporting member when their compliance report has
 * been reviewed (resolved OR dismissed). Two keys share this file:
 *
 *   gdcompliance_report_resolved  — admin picked resolve (with or
 *                                    without a resulting override).
 *   gdcompliance_report_dismissed — admin dismissed (spam / invalid).
 *
 * `extra` payload — set at send() time:
 *   upc, state_code, outcome_label, resolution_note, has_override
 *
 * The URL points back at the /state-lookup/ page for that UPC+state
 * so the member can see the updated classification (if an override
 * was created, the result will already reflect it).
 */

namespace IPS\gdcompliance\extensions\core\Notifications;

use IPS\Extensions\NotificationsAbstract;
use IPS\Http\Url;
use IPS\Member;

if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Report extends NotificationsAbstract
{
	public static function configurationOptions( ?Member $member = NULL ): array
	{
		return [
			'gdcompliance_report_notifications' => [
				'type'              => 'standard',
				'notificationTypes' => [
					'gdcompliance_report_resolved',
					'gdcompliance_report_dismissed',
				],
				'title'             => 'gdcompliance_notif_report',
				'showTitle'         => true,
				'description'       => 'gdcompliance_notif_report_desc',
				'default'           => [ 'inline', 'email' ],
				'disabled'          => [],
			],
		];
	}

	protected static function lookupUrl( string $upc, string $stateCode ): string
	{
		$q = [
			'app'        => 'gdcompliance',
			'module'     => 'lookup',
			'controller' => 'lookup',
			'state'      => strtoupper( $stateCode ),
			'q'          => $upc,
		];
		return (string) Url::internal( http_build_query( $q ), 'front', 'gdcompliance_state_lookup' );
	}

	public function parse_gdcompliance_report_resolved( \IPS\Notification\Inline $notification, bool $htmlEscape = TRUE ): array
	{
		$extra           = \is_array( $notification->extra ) ? $notification->extra : [];
		$upc             = (string) ( $extra['upc']             ?? '' );
		$stateCode       = (string) ( $extra['state_code']      ?? '' );
		$resolutionNote  = trim( (string) ( $extra['resolution_note'] ?? '' ) );
		$hasOverride     = !empty( $extra['has_override'] );

		$title = 'Your compliance report has been reviewed';
		$body  = 'Your report for UPC ' . $upc . ' (' . strtoupper( $stateCode ) . ') was reviewed and resolved'
			. ( $hasOverride ? ' — the catalog has been corrected accordingly.' : '.' );
		if ( $resolutionNote !== '' )
		{
			$body .= ' Note from staff: ' . $resolutionNote;
		}

		return [
			'title'   => $htmlEscape ? htmlspecialchars( $title, ENT_QUOTES, 'UTF-8' ) : $title,
			'content' => $htmlEscape ? htmlspecialchars( $body,  ENT_QUOTES, 'UTF-8' ) : $body,
			'url'     => self::lookupUrl( $upc, $stateCode ),
		];
	}

	public function parse_gdcompliance_report_dismissed( \IPS\Notification\Inline $notification, bool $htmlEscape = TRUE ): array
	{
		$extra          = \is_array( $notification->extra ) ? $notification->extra : [];
		$upc            = (string) ( $extra['upc']             ?? '' );
		$stateCode      = (string) ( $extra['state_code']      ?? '' );
		$resolutionNote = trim( (string) ( $extra['resolution_note'] ?? '' ) );

		$title = 'Your compliance report has been reviewed';
		$body  = 'Your report for UPC ' . $upc . ' (' . strtoupper( $stateCode ) . ') was reviewed and dismissed.';
		if ( $resolutionNote !== '' )
		{
			$body .= ' Note from staff: ' . $resolutionNote;
		}

		return [
			'title'   => $htmlEscape ? htmlspecialchars( $title, ENT_QUOTES, 'UTF-8' ) : $title,
			'content' => $htmlEscape ? htmlspecialchars( $body,  ENT_QUOTES, 'UTF-8' ) : $body,
			'url'     => self::lookupUrl( $upc, $stateCode ),
		];
	}
}

class Report extends _Report {}
