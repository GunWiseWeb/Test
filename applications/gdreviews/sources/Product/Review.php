<?php
/**
 * @brief  GD Reviews — Review class (Stage 1 of 4).
 *
 * IPS-native \IPS\Content\Review subclass bound to gdreviews_reviews.
 * Attached to the Product Content Item via $itemClass so IPS knows
 * "this review belongs to this product." Once wired via ContentRouter
 * (Stage 2), this class gives us native moderation, helpful-votes,
 * report queue, and edit history for free.
 *
 * STAGE 1 SCOPE — class is defined and the table exists, but the
 * submission form is Stage 2 and ContentRouter registration is
 * Stage 2. No end user hits this class in Stage 1.
 */

namespace IPS\gdreviews\Product;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Review extends \IPS\Content\Review
{
	public static ?string $databaseTable   = 'gdreviews_reviews';
	/* v1.0.6 — same double-prefix bug as Product.php. IPS builds
	   the PK column as prefix+id; with "review_" the columnId
	   must be the UNPREFIXED "id" so it resolves to review_id. */
	public static string  $databaseColumnId = 'id';
	public static string  $databasePrefix   = 'review_';

	public static string $application = 'gdreviews';
	public static string $module      = 'reviews';

	public static string $itemClass = 'IPS\gdreviews\Product\Product';

	public static array $databaseColumnMap = [
		'item'    => 'item',
		'author'  => 'author',
		'author_name' => 'author_name',
		'content' => 'content',
		'date'    => 'date',
		'rating'  => 'rating',
		'approved'  => 'approved',
		'hidden'  => 'hidden',
		'ip_address' => 'ip_address',
		'title'   => 'title',
		'votes'         => 'votes_data',
		'votes_helpful' => 'votes_helpful',
		'votes_total'   => 'votes_total',
		'edit_time'   => 'edit_time',
		'edit_member_name' => 'edit_name',
		'edit_reason' => 'edit_reason',
		'edit_show'   => 'edit_show',
	];

	public static string $formLangPrefix = 'gdreviews_review_';
	public static string $title          = 'gdreviews_review';
}

class Review extends _Review {}
