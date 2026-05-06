<?php
/**
 * @brief       GD Master Catalog — ACP Settings Controller
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 * @since       12 Apr 2026
 */

namespace IPS\gdcatalog\modules\admin\catalog;

/* To prevent PHP errors (extending class does not exist) revealing path */

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _settings extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'catalog_manage' );
		parent::execute();
	}

	/**
	 * Settings form.
	 */
	protected function manage()
	{
		$form = new \IPS\Helpers\Form;

		$form->add( new \IPS\Helpers\Form\Text(
			'gdcatalog_opensearch_host',
			\IPS\Settings::i()->gdcatalog_opensearch_host ?: 'http://localhost:9200',
			TRUE
		));
		$form->add( new \IPS\Helpers\Form\Text(
			'gdcatalog_opensearch_index',
			\IPS\Settings::i()->gdcatalog_opensearch_index ?: 'gunrack_products',
			TRUE
		));
		$form->add( new \IPS\Helpers\Form\Number(
			'gdcatalog_auto_resolve_hours',
			\IPS\Settings::i()->gdcatalog_auto_resolve_hours ?: 48,
			TRUE,
			[ 'min' => 1, 'max' => 168 ]
		));
		$form->add( new \IPS\Helpers\Form\Number(
			'gdcatalog_discontinue_threshold',
			\IPS\Settings::i()->gdcatalog_discontinue_threshold ?: 3,
			TRUE,
			[ 'min' => 1, 'max' => 10 ]
		));
		$form->add( new \IPS\Helpers\Form\YesNo(
			'gdcatalog_digest_email_enabled',
			(bool) \IPS\Settings::i()->gdcatalog_digest_email_enabled,
			FALSE
		));
		$form->add( new \IPS\Helpers\Form\Text(
			'gdcatalog_digest_email_recipient',
			\IPS\Settings::i()->gdcatalog_digest_email_recipient ?: '',
			FALSE
		));

		if ( $values = $form->values() )
		{
			$form->saveAsSettings( $values );

			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=settings' ),
				'saved'
			);
		}

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gdcatalog_settings_title' );
		\IPS\Output::i()->output = (string) $form;
	}
}

class settings extends _settings {}
