<?php
/**
 * @brief       GD Master Catalog — ACP Conflict Log Controller
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 * @since       12 Apr 2026
 *
 * Section 2.9: Conflict log browser with filters by field,
 * distributor, date range, and rule applied.
 */

namespace IPS\gdcatalog\modules\admin\catalog;

/* To prevent PHP errors (extending class does not exist) revealing path */

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _conflicts extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'catalog_manage' );
		parent::execute();
	}

	/**
	 * Conflict log browser.
	 */
	protected function manage()
	{
		$where = [];

		$filterField = \IPS\Request::i()->field ?? '';
		$filterSource = \IPS\Request::i()->source ?? '';
		$filterRule  = \IPS\Request::i()->rule ?? '';
		$filterUpc   = \IPS\Request::i()->upc ?? '';

		if ( $filterField !== '' )
		{
			$where[] = [ 'field_name=?', $filterField ];
		}
		if ( $filterSource !== '' )
		{
			$where[] = [ 'winning_source=? OR losing_source=?', $filterSource, $filterSource ];
		}
		if ( $filterRule !== '' )
		{
			$where[] = [ 'rule_applied=?', $filterRule ];
		}
		if ( $filterUpc !== '' )
		{
			$where[] = [ 'upc=?', $filterUpc ];
		}

		$page    = max( 1, (int) ( \IPS\Request::i()->page ?? 1 ) );
		$perPage = 50;

		$total = \IPS\Db::i()->select( 'COUNT(*)', 'gd_conflict_log', $where )->first();

		$entries = [];
		foreach (
			\IPS\Db::i()->select( '*', 'gd_conflict_log', $where, 'resolved_at DESC', [ ( $page - 1 ) * $perPage, $perPage ] ) as $row
		)
		{
			$entries[] = [
				'upc'            => (string) ( $row['upc'] ?? '' ),
				'field_name'     => (string) ( $row['field_name'] ?? '' ),
				'winning_source' => (string) ( $row['winning_source'] ?? '' ),
				'winning_value'  => htmlspecialchars( mb_substr( (string) ( $row['winning_value'] ?? '' ), 0, 80 ) ),
				'losing_source'  => (string) ( $row['losing_source'] ?? '' ),
				'losing_value'   => htmlspecialchars( mb_substr( (string) ( $row['losing_value'] ?? '' ), 0, 80 ) ),
				'rule_applied'   => (string) ( $row['rule_applied'] ?? '' ),
				'resolved_at'    => $row['resolved_at'] ?? null,
			];
		}

		$entryCount = \count( $entries );

		$formActionUrl = (string) \IPS\Http\Url::internal(
			'app=gdcatalog&module=catalog&controller=conflicts'
		);

		$pagination = \IPS\Theme::i()->getTemplate( 'global', 'core', 'global' )->pagination(
			\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=conflicts' ),
			ceil( $total / $perPage ),
			$page,
			$perPage
		);

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gdcatalog_conflicts_title' );
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'catalog', 'gdcatalog', 'admin' )->conflictLog(
			$entries, $filterField, $filterSource, $filterRule, $filterUpc, $total, $pagination, $entryCount, $formActionUrl
		);
	}
}

class conflicts extends _conflicts {}
