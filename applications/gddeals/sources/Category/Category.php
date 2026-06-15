<?php
namespace IPS\gddeals\Category;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Category extends \IPS\Node\Model implements \IPS\Node\Permissions
{
	public static ?string $databaseTable   = 'gd_deal_categories';
	public static string  $databaseColumnId = 'id';
	public static string  $databasePrefix   = '';

	protected static array $multitons = [];

	public static string $nodeTitle = 'gddeals_categories_title';

	public static string $databaseColumnOrder = 'position';

	public static string $databaseColumnParent = 'parent_id';

	public static ?string $databaseColumnEnabledDisabled = 'open';

	public static string $titleLangPrefix = 'gddeals_cat_';

	public static ?string $contentItemClass = 'IPS\\gddeals\\Deal\\Deal';

	public static string $permApp  = 'gddeals';
	public static string $permType = 'category';

	public static array $permissionMap = [
		'view'  => 'view',
		'read'  => 2,
		'add'   => 3,
		'reply' => 4,
	];

	public static string $permissionLangPrefix = 'gddeals_perm_';

	public static array $bitOptions = [
		'bitoptions' => [
			'bitoptions' => [
				'allow_submissions' => 1,
				'require_approval'  => 2,
			],
		],
	];

	public function get__title(): string
	{
		return \IPS\Member::loggedIn()->language()->addToStack( static::$titleLangPrefix . $this->id );
	}

	public function get_name_seo(): ?string
	{
		return $this->_data['name_seo'] ?? null;
	}

	public function set_name_seo( ?string $val ): void
	{
		$this->_data['name_seo'] = $val;
	}

	public static function formFields( \IPS\Node\Model $node = null ): array
	{
		$form = [];

		$form['name'] = new \IPS\Helpers\Form\Translatable( 'gddeals_category_name', null, TRUE, [
			'app'  => 'gddeals',
			'key'  => ( $node AND $node->id ) ? static::$titleLangPrefix . $node->id : null,
		] );

		return $form;
	}

	public function formatFormValues( array $values ): array
	{
		if ( isset( $values['gddeals_category_name'] ) )
		{
			\IPS\Lang::saveCustom( 'gddeals', static::$titleLangPrefix . $this->id, $values['gddeals_category_name'] );
			unset( $values['gddeals_category_name'] );
		}

		return $values;
	}
}
class Category extends _Category {}
