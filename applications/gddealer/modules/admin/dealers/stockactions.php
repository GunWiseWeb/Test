<?php
/**
 * @brief       GD Dealer Manager — ACP Stock Actions Controller
 * @package     IPS Community Suite
 * @subpackage  GD Dealer Manager
 * @since       19 Apr 2026
 *
 * CRUD for canned multi-step actions (reply + status + priority + assign).
 */

namespace IPS\gddealer\modules\admin\dealers;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _stockactions extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'dealer_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'dealer_manage' );

		$rows = [];
		try
		{
			foreach ( \IPS\Db::i()->select( '*', 'gd_dealer_support_stock_actions', null,
				'position ASC, id ASC'
			) as $r )
			{
				$deptName = '';
				if ( !empty( $r['department_id'] ) )
				{
					try
					{
						$deptName = (string) \IPS\Db::i()->select( 'name', 'gd_dealer_support_departments',
							[ 'id=?', (int) $r['department_id'] ] )->first();
					}
					catch ( \Exception ) {}
				}

				$effects = [];
				if ( !empty( $r['reply_body'] ) )   { $effects[] = 'Reply'; }
				if ( !empty( $r['new_status'] ) )    { $effects[] = 'Status → ' . str_replace( '_', ' ', (string) $r['new_status'] ); }
				if ( !empty( $r['new_priority'] ) )  { $effects[] = 'Priority → ' . (string) $r['new_priority']; }
				if ( $r['new_assignee'] !== null && (string) $r['new_assignee'] !== '' )
				{
					$effects[] = (int) $r['new_assignee'] === 0 ? 'Unassign' : 'Assign → #' . (int) $r['new_assignee'];
				}

				$baseQS = [ 'id' => (int) $r['id'] ];
				$rows[] = [
					'id'              => (int) $r['id'],
					'title'           => (string) $r['title'],
					'department_name' => $deptName ?: 'All departments',
					'effects'         => implode( ', ', $effects ) ?: 'None',
					'position'        => (int) $r['position'],
					'enabled'         => (bool) $r['enabled'],
					'edit_url'        => (string) \IPS\Http\Url::internal(
						'app=gddealer&module=dealers&controller=stockactions&do=form', 'admin'
					)->setQueryString( $baseQS ),
					'delete_url'      => (string) \IPS\Http\Url::internal(
						'app=gddealer&module=dealers&controller=stockactions&do=delete', 'admin'
					)->setQueryString( $baseQS )->csrf(),
					'toggle_url'      => (string) \IPS\Http\Url::internal(
						'app=gddealer&module=dealers&controller=stockactions&do=toggle', 'admin'
					)->setQueryString( $baseQS )->csrf(),
					'move_up_url'     => (string) \IPS\Http\Url::internal(
						'app=gddealer&module=dealers&controller=stockactions&do=moveUp', 'admin'
					)->setQueryString( $baseQS )->csrf(),
					'move_down_url'   => (string) \IPS\Http\Url::internal(
						'app=gddealer&module=dealers&controller=stockactions&do=moveDown', 'admin'
					)->setQueryString( $baseQS )->csrf(),
				];
			}
		}
		catch ( \Exception ) {}

		$addUrl = (string) \IPS\Http\Url::internal(
			'app=gddealer&module=dealers&controller=stockactions&do=form', 'admin'
		);

		\IPS\Output::i()->title  = 'Stock Actions';
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'dealers' )->supportStockActions(
			$rows, $addUrl
		);
	}

	protected function form(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'dealer_manage' );

		$id     = (int) ( \IPS\Request::i()->id ?? 0 );
		$isEdit = $id > 0;

		$existing = null;
		if ( $isEdit )
		{
			try
			{
				$existing = \IPS\Db::i()->select( '*', 'gd_dealer_support_stock_actions', [ 'id=?', $id ] )->first();
			}
			catch ( \Exception ) {}

			if ( !$existing )
			{
				\IPS\Output::i()->redirect(
					\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=stockactions', 'admin' ),
					'Stock action not found.'
				);
				return;
			}
		}

		$editor = new \IPS\Helpers\Form\Editor(
			'gddealer_stock_action_body',
			$isEdit ? (string) ( $existing['reply_body'] ?? '' ) : '',
			FALSE,
			[
				'app'         => 'gddealer',
				'key'         => 'Responses',
				'autoSaveKey' => 'gddealer-stock-action-' . $id,
				'attachIds'   => [ $id, 21 ],
			],
			NULL, NULL, NULL,
			'editor_stock_action_' . $id
		);

		if ( \IPS\Request::i()->requestMethod() === 'POST' )
		{
			\IPS\Session::i()->csrfCheck();

			$title        = trim( (string) ( \IPS\Request::i()->title ?? '' ) );
			$replyBody    = (string) ( \IPS\Request::i()->gddealer_stock_action_body ?? '' );
			$newStatus    = trim( (string) ( \IPS\Request::i()->new_status ?? '' ) );
			$newPriority  = trim( (string) ( \IPS\Request::i()->new_priority ?? '' ) );
			$newAssignee  = trim( (string) ( \IPS\Request::i()->new_assignee ?? '' ) );
			$departmentId = (int) ( \IPS\Request::i()->department_id ?? 0 );
			$enabled      = (int) ( !empty( \IPS\Request::i()->enabled ) ? 1 : 0 );

			if ( $title === '' )
			{
				\IPS\Output::i()->redirect(
					\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=stockactions&do=form', 'admin' )
						->setQueryString( $isEdit ? [ 'id' => $id ] : [] ),
					'Title is required.'
				);
				return;
			}

			$validStatuses   = [ '', 'open', 'pending_staff', 'pending_customer', 'resolved', 'closed' ];
			$validPriorities = [ '', 'low', 'normal', 'high', 'urgent' ];
			if ( !in_array( $newStatus, $validStatuses, true ) )   { $newStatus = ''; }
			if ( !in_array( $newPriority, $validPriorities, true ) ) { $newPriority = ''; }

			$data = [
				'title'         => mb_substr( $title, 0, 255 ),
				'reply_body'    => trim( $replyBody ) !== '' ? $replyBody : null,
				'new_status'    => $newStatus !== '' ? $newStatus : null,
				'new_priority'  => $newPriority !== '' ? $newPriority : null,
				'new_assignee'  => $newAssignee !== '' ? (int) $newAssignee : null,
				'department_id' => $departmentId > 0 ? $departmentId : null,
				'enabled'       => $enabled,
			];

			if ( $isEdit )
			{
				try
				{
					\IPS\Db::i()->update( 'gd_dealer_support_stock_actions', $data, [ 'id=?', $id ] );
				}
				catch ( \Exception ) {}
			}
			else
			{
				$nextPos = 0;
				try
				{
					$nextPos = (int) \IPS\Db::i()->select( 'MAX(position)', 'gd_dealer_support_stock_actions' )->first();
				}
				catch ( \Exception ) {}
				$data['position']   = $nextPos + 1;
				$data['created_at'] = date( 'Y-m-d H:i:s' );

				try
				{
					\IPS\Db::i()->insert( 'gd_dealer_support_stock_actions', $data );
				}
				catch ( \Exception ) {}
			}

			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=stockactions', 'admin' ),
				$isEdit ? 'Stock action updated.' : 'Stock action created.'
			);
			return;
		}

		$departments = [ 0 => 'All departments (global)' ];
		try
		{
			foreach ( \IPS\Db::i()->select( '*', 'gd_dealer_support_departments', null, 'position ASC' ) as $d )
			{
				$departments[ (int) $d['id'] ] = (string) $d['name'];
			}
		}
		catch ( \Exception ) {}

		$adminMembers = [];
		try
		{
			// TODO: Replace hardcoded group 4 with a setting
			foreach ( \IPS\Db::i()->select( 'member_id, name', 'core_members',
				[ 'member_group_id=?', 4 ], 'name ASC', [ 0, 100 ]
			) as $m )
			{
				$adminMembers[ (int) $m['member_id'] ] = (string) $m['name'];
			}
		}
		catch ( \Exception ) {}

		/* v1.0.332 — on the "Add new" branch $existing is null, so the
		   prior null-comparison access on the new_assignee key threw
		   E_WARNING. isset() is null-safe against a null root and
		   returns false cleanly, matching the pattern used by every
		   other field in this array. The `enabled` line also gets a
		   defensive `?? true` inside the edit branch so an $existing
		   row missing the column can't crash the form. */
		$formData = [
			'id'            => $id,
			'title'         => (string) ( $existing['title'] ?? '' ),
			'new_status'    => (string) ( $existing['new_status'] ?? '' ),
			'new_priority'  => (string) ( $existing['new_priority'] ?? '' ),
			'new_assignee'  => isset( $existing['new_assignee'] ) ? (string) $existing['new_assignee'] : '',
			'department_id' => (int) ( $existing['department_id'] ?? 0 ),
			'enabled'       => $isEdit ? (bool) ( $existing['enabled'] ?? true ) : true,
		];

		$editorHtml = (string) $editor;

		$submitUrl = (string) \IPS\Http\Url::internal(
			'app=gddealer&module=dealers&controller=stockactions&do=form', 'admin'
		);
		if ( $isEdit )
		{
			$submitUrl = (string) \IPS\Http\Url::internal(
				'app=gddealer&module=dealers&controller=stockactions&do=form', 'admin'
			)->setQueryString( [ 'id' => $id ] );
		}

		$backUrl = (string) \IPS\Http\Url::internal(
			'app=gddealer&module=dealers&controller=stockactions', 'admin'
		);

		\IPS\Output::i()->title  = $isEdit ? 'Edit Stock Action' : 'Add Stock Action';
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'dealers' )->supportStockActionForm(
			$formData, $isEdit, $editorHtml, $departments, $adminMembers, $submitUrl, $backUrl, \IPS\Session::i()->csrfKey
		);
	}

	protected function delete(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'dealer_manage' );
		\IPS\Session::i()->csrfCheck();

		$id = (int) ( \IPS\Request::i()->id ?? 0 );

		try
		{
			\IPS\Db::i()->delete( 'gd_dealer_support_stock_actions', [ 'id=?', $id ] );
		}
		catch ( \Exception ) {}

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=stockactions', 'admin' ),
			'Stock action deleted.'
		);
	}

	protected function toggle(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'dealer_manage' );
		\IPS\Session::i()->csrfCheck();

		$id = (int) ( \IPS\Request::i()->id ?? 0 );

		$current = null;
		try
		{
			$current = (int) \IPS\Db::i()->select( 'enabled', 'gd_dealer_support_stock_actions', [ 'id=?', $id ] )->first();
		}
		catch ( \Exception ) {}

		if ( $current === null )
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=stockactions', 'admin' )
			);
			return;
		}

		try
		{
			\IPS\Db::i()->update( 'gd_dealer_support_stock_actions', [
				'enabled' => $current ? 0 : 1,
			], [ 'id=?', $id ] );
		}
		catch ( \Exception ) {}

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=stockactions', 'admin' ),
			$current ? 'Stock action disabled.' : 'Stock action enabled.'
		);
	}

	protected function moveUp(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'dealer_manage' );
		\IPS\Session::i()->csrfCheck();
		$this->swapPosition( (int) ( \IPS\Request::i()->id ?? 0 ), 'up' );
	}

	protected function moveDown(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'dealer_manage' );
		\IPS\Session::i()->csrfCheck();
		$this->swapPosition( (int) ( \IPS\Request::i()->id ?? 0 ), 'down' );
	}

	private function swapPosition( int $id, string $direction ): void
	{
		$current = null;
		try
		{
			$current = \IPS\Db::i()->select( '*', 'gd_dealer_support_stock_actions', [ 'id=?', $id ] )->first();
		}
		catch ( \Exception ) {}

		if ( !$current )
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=stockactions', 'admin' )
			);
			return;
		}

		$op    = $direction === 'up' ? '<' : '>';
		$order = $direction === 'up' ? 'position DESC' : 'position ASC';

		$neighbor = null;
		try
		{
			$neighbor = \IPS\Db::i()->select( '*', 'gd_dealer_support_stock_actions',
				[ 'position' . $op . '?', (int) $current['position'] ],
				$order
			)->first();
		}
		catch ( \Exception ) {}

		if ( !$neighbor )
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=stockactions', 'admin' )
			);
			return;
		}

		try
		{
			\IPS\Db::i()->update( 'gd_dealer_support_stock_actions',
				[ 'position' => (int) $neighbor['position'] ],
				[ 'id=?', (int) $current['id'] ]
			);
			\IPS\Db::i()->update( 'gd_dealer_support_stock_actions',
				[ 'position' => (int) $current['position'] ],
				[ 'id=?', (int) $neighbor['id'] ]
			);
		}
		catch ( \Exception ) {}

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=stockactions', 'admin' )
		);
	}
}

class stockactions extends _stockactions {}
