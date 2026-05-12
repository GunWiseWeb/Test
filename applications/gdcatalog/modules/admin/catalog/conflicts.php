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
		$filterScope = (string) ( \IPS\Request::i()->scope ?? '' );
		if ( $filterScope !== 'manual' && $filterScope !== 'auto' )
		{
			$filterScope = '';
		}

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
		if ( $filterScope === 'manual' )
		{
			$where[] = [ 'admin_override=?', 1 ];
		}
		elseif ( $filterScope === 'auto' )
		{
			$where[] = [ 'admin_override=?', 0 ];
		}

		$page    = max( 1, (int) ( \IPS\Request::i()->page ?? 1 ) );
		$perPage = 50;

		$total = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_conflict_log', $where )->first();

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
				'admin_override' => (int) ( $row['admin_override'] ?? 0 ),
				'resolved_at'    => $row['resolved_at'] ?? null,
			];
		}

		$entryCount = \count( $entries );

		/* v1.0.26: total counts by scope. Single grouped query for efficiency. */
		$manualCount = 0;
		$autoCount   = 0;
		foreach ( \IPS\Db::i()->select(
			'admin_override, COUNT(*) AS c',
			'gd_conflict_log',
			NULL,
			NULL,
			NULL,
			'admin_override'
		) as $r )
		{
			if ( (int) $r['admin_override'] === 1 )
			{
				$manualCount = (int) $r['c'];
			}
			else
			{
				$autoCount = (int) $r['c'];
			}
		}

		$formActionUrl = (string) \IPS\Http\Url::internal(
			'app=gdcatalog&module=catalog&controller=conflicts'
		);

		/* v1.0.26: scope filter URLs */
		$urlAll    = (string) \IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=conflicts' );
		$urlManual = (string) \IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=conflicts&scope=manual' );
		$urlAuto   = (string) \IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=conflicts&scope=auto' );

		/* v1.0.26: clear auto-resolved URL (CSRF-protected action) */
		$clearAutoUrl = (string) \IPS\Http\Url::internal(
			'app=gdcatalog&module=catalog&controller=conflicts&do=clearAutoResolved'
		)->csrf();

		$pagination = \IPS\Theme::i()->getTemplate( 'global', 'core', 'global' )->pagination(
			\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=conflicts' ),
			$perPage > 0 ? (int) ceil( $total / $perPage ) : 1,
			$page,
			$perPage
		);

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gdcatalog_conflicts_title' );
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'catalog', 'gdcatalog', 'admin' )->conflictLog(
			$entries, $filterField, $filterSource, $filterRule, $filterUpc, $filterScope,
			$total, $pagination, $entryCount, $formActionUrl,
			$manualCount, $autoCount, $urlAll, $urlManual, $urlAuto, $clearAutoUrl
		);
	}

	/**
	 * v1.0.26: Bulk-delete auto-resolved conflict log entries.
	 * Manual overrides (admin_override=1) are preserved.
	 */
	protected function clearAutoResolved()
	{
		\IPS\Session::i()->csrfCheck();
		\IPS\Dispatcher::i()->checkAcpPermission( 'catalog_manage' );

		try
		{
			$deleted = (int) \IPS\Db::i()->delete( 'gd_conflict_log', [ 'admin_override=?', 0 ] );

			try { \IPS\Log::log( sprintf( 'Admin %s manually cleared %d auto-resolved conflict log entries', \IPS\Member::loggedIn()->name, $deleted ), 'gdcatalog_prune' ); } catch ( \Throwable ) {}

			$message = $deleted === 1
				? '1 auto-resolved conflict log entry deleted.'
				: sprintf( '%d auto-resolved conflict log entries deleted.', $deleted );
		}
		catch ( \Throwable $e )
		{
			$message = 'Clear failed: ' . $e->getMessage();
		}

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=conflicts' ),
			$message
		);
	}
}

class conflicts extends _conflicts {}
