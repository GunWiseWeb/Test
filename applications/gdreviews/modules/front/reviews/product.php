<?php
/**
 * @brief  GD Reviews — front controller stub (Stage 1 of 4).
 *
 * Placeholder that renders a "not available yet" notice at the
 * app's front URL. Stage 2 replaces this with the review-submission
 * form; Stage 3 wires the display list; Stage 4 adds moderation.
 *
 * Present in Stage 1 only so the module registration in
 * data/modules.json has a valid default_controller PHP file to
 * resolve — IPS's Dispatcher errors out on install if
 * default_controller points at a non-existent file.
 */

namespace IPS\gdreviews\modules\front\reviews;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _product extends \IPS\Dispatcher\Controller
{
	public function execute(): void
	{
		parent::execute();
	}

	protected function manage(): void
	{
		\IPS\Output::i()->title  = (string) \IPS\Member::loggedIn()->language()->addToStack( 'gdreviews_reviews' );
		\IPS\Output::i()->output = '<div class="ipsMessage ipsMessage_info" style="margin:24px 0">'
			. htmlspecialchars(
				(string) \IPS\Member::loggedIn()->language()->addToStack( 'gdreviews_review_placeholder' ),
				ENT_QUOTES, 'UTF-8'
			)
			. '</div>';
	}
}

class product extends _product {}
