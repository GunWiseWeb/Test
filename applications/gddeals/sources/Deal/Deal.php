<?php
namespace IPS\gddeals\Deal;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Deal extends \IPS\Content\Item
{
	use \IPS\Content\Hideable,
		\IPS\Content\Featurable,
		\IPS\Content\Reportable;
	public static string $application = 'gddeals';
	public static string $module      = 'deals';

	public static ?string $databaseTable   = 'gd_deal_posts';
	public static string  $databaseColumnId = 'id';
	public static string  $databasePrefix   = '';

	protected static array $multitons = [];

	public static string $title = 'gddeals_ct_deal';

	public static array $databaseColumnMap = [
		'container'         => 'category_id',
		'author'            => 'member_id',
		'author_name'       => 'author_name',
		'title'             => 'title',
		'content'           => 'description',
		'date'              => 'posted_at',
		'updated'           => 'updated',
		'num_comments'      => 'comment_count',
		'unapproved_comments' => 'unapproved_comments',
		'hidden_comments'   => 'hidden_comments',
		'last_comment'      => 'last_comment',
		'views'             => 'view_count',
		'approved'          => 'approved',
		'approved_by'       => 'approved_by',
		'approved_date'     => 'approved_date',
		'featured'          => 'featured',
		'ip_address'        => 'ip_address',
	];

	public static ?string $containerNodeClass = 'IPS\\gddeals\\Category\\Category';

	public static ?string $commentClass = 'IPS\\gddeals\\Deal\\Comment';

	public static string $icon = 'tags';
}
class Deal extends _Deal {}
