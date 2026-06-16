<?php
namespace IPS\gddeals\Deal;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Comment extends \IPS\Content\Comment
{
	use \IPS\Content\Hideable, \IPS\Content\Reportable;
	public static string $application = 'gddeals';
	public static string $module      = 'deals';

	public static ?string $databaseTable   = 'gd_deal_comments';
	public static string  $databaseColumnId = 'id';
	public static string  $databasePrefix   = '';

	protected static array $multitons = [];

	public static string $title = 'gddeals_deal_comment';

	public static ?string $itemClass = 'IPS\\gddeals\\Deal';

	public static string $formLangPrefix = 'gddeals_';

	public static array $databaseColumnMap = [
		'item'        => 'deal_id',
		'author'      => 'member_id',
		'author_name' => 'author_name',
		'content'     => 'content',
		'date'        => 'comment_date',
		'ip_address'  => 'ip_address',
		'approved'    => 'approved',
	];
}
class Comment extends _Comment {}
