<?php
/**
 * @brief  GD Loadout — ACP: All Loadouts (site-wide list + delete)
 *
 * Lists every gd_loadouts row regardless of owner so orphaned /
 * guest-owned loadouts (member deleted, member_id no longer in
 * core_members) can be purged. All deletion routes through the
 * shared \IPS\gdloadout\Loadout\Loadout::deleteCascade() helper
 * so every child table (items, votes, comments, follows,
 * suggestions, forum_posts) is cleaned up in one place.
 *
 * v1.0.74 initial cut. Restriction: loadouts_manage.
 */

namespace IPS\gdloadout\modules\admin\manage;

use IPS\Db;
use IPS\Http\Url;
use IPS\Member;
use IPS\Output;
use IPS\Request;
use IPS\Session;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _loadouts extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	protected const PER_PAGE = 50;

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'loadouts_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		$lang    = Member::loggedIn()->language();
		$h       = fn( string $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );

		$page       = max( 1, (int) ( Request::i()->page ?? 1 ) );
		$per        = self::PER_PAGE;
		$off        = ( $page - 1 ) * $per;
		$q          = trim( (string) ( Request::i()->q ?? '' ) );
		$orphanOnly = (int) ( Request::i()->orphan ?? 0 ) === 1;

		$prefix = (string) Db::i()->prefix;

		/* WHERE — base + optional filters. Orphan detection joins to
		   core_members via NOT EXISTS subselect (ANSI_QUOTES safe). */
		$where = [ '1=1' ];
		$args  = [];
		if ( $q !== '' )
		{
			$where[] = '( l.name LIKE ? OR l.slug LIKE ? )';
			$like    = '%' . $q . '%';
			$args[]  = $like;
			$args[]  = $like;
		}
		if ( $orphanOnly )
		{
			$where[] = 'NOT EXISTS ( SELECT 1 FROM ' . $prefix . 'core_members m WHERE m.member_id = l.member_id )';
		}
		$whereSql = implode( ' AND ', $where );

		/* Overall count. */
		$total = 0;
		try
		{
			$total = (int) Db::i()->select( 'COUNT(*)', [ 'gd_loadouts', 'l' ],
				array_merge( [ $whereSql ], $args )
			)->first();
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'loadouts count: ' . $e->getMessage(), 'gdloadout' ); } catch ( \Throwable ) {}
		}

		/* Orphan count (for the filter chip). */
		$orphanTotal = 0;
		try
		{
			$orphanTotal = (int) Db::i()->select( 'COUNT(*)', [ 'gd_loadouts', 'l' ],
				[ 'NOT EXISTS ( SELECT 1 FROM ' . $prefix . 'core_members m WHERE m.member_id = l.member_id )' ]
			)->first();
		}
		catch ( \Throwable ) {}

		/* Page of rows. */
		$rows = [];
		try
		{
			foreach ( Db::i()->select(
				'l.id, l.member_id, l.name, l.slug, l.visibility, l.total_items, l.created_at, l.featured, l.upvotes',
				[ 'gd_loadouts', 'l' ],
				array_merge( [ $whereSql ], $args ),
				'l.created_at DESC, l.id DESC',
				[ $off, $per ]
			) as $r )
			{
				$rows[] = $r;
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'loadouts list: ' . $e->getMessage(), 'gdloadout' ); } catch ( \Throwable ) {}
		}

		$baseUrl = Url::internal( 'app=gdloadout&module=manage&controller=loadouts' );
		$csrf    = (string) Session::i()->csrfKey;

		/* Intro / filter bar. */
		$header = '<div class="ipsBox" style="margin-bottom:14px"><div class="ipsBox_body ipsPad">'
			. '<h2 class="ipsType_sectionHead" style="margin:0 0 6px">' . $h( $lang->addToStack( 'gdloadout_acp_loadouts_title' ) ) . '</h2>'
			. '<p style="margin:0 0 10px;color:#475569">' . $h( $lang->addToStack( 'gdloadout_acp_loadouts_intro' ) ) . '</p>'
			. '<div style="display:flex;gap:18px;flex-wrap:wrap;font-size:13px;margin-bottom:12px">'
			. '<div><strong style="color:#0f172a;font-size:20px">' . number_format( $total ) . '</strong> ' . $h( $lang->addToStack( 'gdloadout_acp_loadouts_total' ) ) . '</div>'
			. '<div><strong style="color:#b91c1c;font-size:20px">' . number_format( $orphanTotal ) . '</strong> ' . $h( $lang->addToStack( 'gdloadout_acp_loadouts_orphans' ) ) . '</div>'
			. '</div>'
			. '<form method="get" action="' . $h( (string) $baseUrl ) . '" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">'
			. '<input type="hidden" name="app" value="gdloadout"><input type="hidden" name="module" value="manage"><input type="hidden" name="controller" value="loadouts">'
			. '<input type="text" name="q" value="' . $h( $q ) . '" placeholder="' . $h( $lang->addToStack( 'gdloadout_acp_loadouts_search_ph' ) ) . '" style="padding:4px 8px;font-size:13px;min-width:220px">'
			. '<label style="font-size:13px;display:inline-flex;align-items:center;gap:4px"><input type="checkbox" name="orphan" value="1"' . ( $orphanOnly ? ' checked' : '' ) . '> ' . $h( $lang->addToStack( 'gdloadout_acp_loadouts_orphans_only' ) ) . '</label>'
			. '<button type="submit" class="ipsButton ipsButton--primary ipsButton--verySmall">' . $h( $lang->addToStack( 'gdloadout_acp_loadouts_filter' ) ) . '</button>'
			. ( ( $q !== '' || $orphanOnly ) ? '<a class="ipsButton ipsButton--soft ipsButton--verySmall" href="' . $h( (string) $baseUrl ) . '">' . $h( $lang->addToStack( 'gdloadout_acp_loadouts_clear' ) ) . '</a>' : '' )
			. '</form>'
			. '</div></div>';

		/* Rows. */
		$rowsHtml = '';
		if ( empty( $rows ) )
		{
			$rowsHtml = '<tr><td colspan="8" style="padding:20px;text-align:center;color:#94a3b8">' . $h( $lang->addToStack( 'gdloadout_acp_loadouts_none' ) ) . '</td></tr>';
		}
		else
		{
			/* Batch-resolve owner names to avoid one Member::load per row. */
			$memberIds = array_unique( array_map( fn( $r ) => (int) $r['member_id'], $rows ) );
			$memberIds = array_values( array_filter( $memberIds, fn( $x ) => $x > 0 ) );
			$memberNames = [];
			if ( !empty( $memberIds ) )
			{
				try
				{
					foreach ( Db::i()->select( 'member_id, name', 'core_members',
						[ Db::i()->in( 'member_id', $memberIds ) ]
					) as $mr )
					{
						$memberNames[ (int) $mr['member_id'] ] = (string) $mr['name'];
					}
				}
				catch ( \Throwable ) {}
			}

			$delUrl = (string) Url::internal( 'app=gdloadout&module=manage&controller=loadouts&do=delete' );

			foreach ( $rows as $r )
			{
				$id     = (int) $r['id'];
				$mid    = (int) $r['member_id'];
				$name   = (string) ( $r['name'] ?? '' );
				$slug   = (string) ( $r['slug'] ?? '' );
				$vis    = (string) ( $r['visibility'] ?? '' );
				$items  = (int) ( $r['total_items'] ?? 0 );
				$upv    = (int) ( $r['upvotes'] ?? 0 );
				$feat   = (int) ( $r['featured'] ?? 0 ) === 1;
				$cAt    = (int) ( $r['created_at'] ?? 0 );

				$isOrphan = $mid > 0 && !isset( $memberNames[ $mid ] );
				$ownerHtml = '';
				if ( $mid === 0 )
				{
					$ownerHtml = '<span style="color:#64748b">' . $h( $lang->addToStack( 'gdloadout_acp_loadouts_guest' ) ) . '</span>';
				}
				elseif ( $isOrphan )
				{
					$ownerHtml = '<span style="color:#b91c1c;font-weight:600">' . $h( $lang->addToStack( 'gdloadout_acp_loadouts_deleted_member' ) ) . ' #' . $mid . '</span>';
				}
				else
				{
					$ownerHtml = $h( $memberNames[ $mid ] ) . ' <span style="color:#64748b">#' . $mid . '</span>';
				}

				$viewUrl = '';
				if ( $slug !== '' && in_array( $vis, [ 'public', 'unlisted' ], true ) )
				{
					try { $viewUrl = (string) Url::internal( 'app=gdloadout&module=loadouts&controller=view&slug=' . urlencode( $slug ), 'front' ); }
					catch ( \Throwable ) { $viewUrl = ''; }
				}

				$rowClass = $isOrphan ? ' style="background:#fef2f2"' : '';

				$rowsHtml .= '<tr' . $rowClass . '>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;font-family:ui-monospace,monospace;font-size:12px">' . $id . '</td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;font-size:13px"><strong>' . $h( $name ) . '</strong>'
					. ( $viewUrl !== '' ? ' <a href="' . $h( $viewUrl ) . '" target="_blank" style="color:#0f6e56;font-size:11px">view</a>' : '' )
					. '</td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;font-size:13px">' . $ownerHtml . '</td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;color:#475569;font-size:12px">' . $h( $vis ) . '</td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;text-align:right">' . number_format( $items ) . '</td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;text-align:right">' . number_format( $upv ) . '</td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;color:#64748b;font-size:12px">' . ( $cAt > 0 ? date( 'Y-m-d', $cAt ) : '—' ) . ( $feat ? ' <span style="color:#b45309;font-weight:600">&#9733;</span>' : '' ) . '</td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;text-align:right">'
					. '<form method="post" action="' . $h( $delUrl ) . '" style="display:inline" onsubmit="return confirm(\'' . $h( $lang->addToStack( 'gdloadout_acp_loadouts_delete_confirm' ) ) . '\');">'
					. '<input type="hidden" name="csrfKey" value="' . $h( $csrf ) . '">'
					. '<input type="hidden" name="id" value="' . $id . '">'
					. ( $q !== '' ? '<input type="hidden" name="q" value="' . $h( $q ) . '">' : '' )
					. ( $orphanOnly ? '<input type="hidden" name="orphan" value="1">' : '' )
					. ( $page > 1 ? '<input type="hidden" name="page" value="' . $page . '">' : '' )
					. '<button type="submit" class="ipsButton ipsButton--danger ipsButton--verySmall">' . $h( $lang->addToStack( 'gdloadout_acp_loadouts_delete' ) ) . '</button>'
					. '</form>'
					. '</td>'
					. '</tr>';
			}
		}

		$table = '<div class="ipsBox"><div class="ipsBox_body ipsPad">'
			. '<table style="width:100%;border-collapse:collapse">'
			. '<thead><tr style="background:#f8fafc">'
			. '<th style="text-align:left;padding:8px 10px;border-bottom:2px solid #e2e8f0;font-size:11px;text-transform:uppercase;color:#64748b">' . $h( $lang->addToStack( 'gdloadout_acp_loadouts_col_id' ) ) . '</th>'
			. '<th style="text-align:left;padding:8px 10px;border-bottom:2px solid #e2e8f0;font-size:11px;text-transform:uppercase;color:#64748b">' . $h( $lang->addToStack( 'gdloadout_acp_loadouts_col_name' ) ) . '</th>'
			. '<th style="text-align:left;padding:8px 10px;border-bottom:2px solid #e2e8f0;font-size:11px;text-transform:uppercase;color:#64748b">' . $h( $lang->addToStack( 'gdloadout_acp_loadouts_col_owner' ) ) . '</th>'
			. '<th style="text-align:left;padding:8px 10px;border-bottom:2px solid #e2e8f0;font-size:11px;text-transform:uppercase;color:#64748b">' . $h( $lang->addToStack( 'gdloadout_acp_loadouts_col_visibility' ) ) . '</th>'
			. '<th style="text-align:right;padding:8px 10px;border-bottom:2px solid #e2e8f0;font-size:11px;text-transform:uppercase;color:#64748b">' . $h( $lang->addToStack( 'gdloadout_acp_loadouts_col_items' ) ) . '</th>'
			. '<th style="text-align:right;padding:8px 10px;border-bottom:2px solid #e2e8f0;font-size:11px;text-transform:uppercase;color:#64748b">' . $h( $lang->addToStack( 'gdloadout_acp_loadouts_col_upvotes' ) ) . '</th>'
			. '<th style="text-align:left;padding:8px 10px;border-bottom:2px solid #e2e8f0;font-size:11px;text-transform:uppercase;color:#64748b">' . $h( $lang->addToStack( 'gdloadout_acp_loadouts_col_created' ) ) . '</th>'
			. '<th style="padding:8px 10px;border-bottom:2px solid #e2e8f0"></th>'
			. '</tr></thead>'
			. '<tbody>' . $rowsHtml . '</tbody>'
			. '</table>';

		/* Rich pager (First / Prev / jump / Next / Last), preserves q+orphan. */
		if ( $total > $per )
		{
			$totalPages = (int) ceil( $total / $per );
			$mkHref = function( $p ) use ( $baseUrl, $q, $orphanOnly, $totalPages ) {
				$p = max( 1, min( (int) $p, $totalPages ) );
				return (string) $baseUrl->setQueryString( array_filter( [
					'q'      => $q !== '' ? $q : null,
					'orphan' => $orphanOnly ? 1 : null,
					'page'   => $p > 1 ? $p : null,
				] ) );
			};

			$table .= '<div style="display:flex;gap:6px;justify-content:center;align-items:center;flex-wrap:wrap;margin-top:12px;font-size:13px;color:#64748b">'
				. ( $page > 1 ? '<a class="ipsButton ipsButton--soft ipsButton--verySmall" href="' . $h( $mkHref( 1 ) ) . '">&laquo; First</a>' : '' )
				. ( $page > 1 ? '<a class="ipsButton ipsButton--soft ipsButton--verySmall" href="' . $h( $mkHref( $page - 1 ) ) . '">&larr; Prev</a>' : '' )
				. '<span style="padding:4px 8px">' . $h( $lang->addToStack( 'gdloadout_acp_loadouts_page' ) ) . ' ' . $page . ' / ' . $totalPages . ' &middot; ' . number_format( $total ) . '</span>'
				. ( $page < $totalPages ? '<a class="ipsButton ipsButton--soft ipsButton--verySmall" href="' . $h( $mkHref( $page + 1 ) ) . '">Next &rarr;</a>' : '' )
				. ( $page < $totalPages ? '<a class="ipsButton ipsButton--soft ipsButton--verySmall" href="' . $h( $mkHref( $totalPages ) ) . '">Last &raquo;</a>' : '' )
				. '<form method="get" action="' . $h( (string) $baseUrl ) . '" style="display:inline-flex;gap:4px;align-items:center;margin-left:12px">'
				. '<input type="hidden" name="app" value="gdloadout"><input type="hidden" name="module" value="manage"><input type="hidden" name="controller" value="loadouts">'
				. ( $q !== '' ? '<input type="hidden" name="q" value="' . $h( $q ) . '">' : '' )
				. ( $orphanOnly ? '<input type="hidden" name="orphan" value="1">' : '' )
				. '<label style="font-size:12px;color:#64748b">' . $h( $lang->addToStack( 'gdloadout_acp_loadouts_jump' ) ) . '</label>'
				. '<input type="number" name="page" min="1" max="' . $totalPages . '" value="' . $page . '" style="width:80px;padding:3px 6px;font-size:12px">'
				. '<button type="submit" class="ipsButton ipsButton--primary ipsButton--verySmall">' . $h( $lang->addToStack( 'gdloadout_acp_loadouts_go' ) ) . '</button>'
				. '</form>'
				. '</div>';
		}

		$table .= '</div></div>';

		Output::i()->title  = $lang->addToStack( 'gdloadout_acp_loadouts_title' );
		Output::i()->output = $header . $table;
	}

	protected function delete(): void
	{
		Session::i()->csrfCheck();
		$id = (int) ( Request::i()->id ?? 0 );

		if ( $id > 0 )
		{
			try
			{
				\IPS\gdloadout\Loadout\Loadout::deleteCascade( $id );
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'loadouts admin delete id=' . $id . ': ' . $e->getMessage(), 'gdloadout' ); } catch ( \Throwable ) {}
			}
		}

		/* Preserve filter/pager on the redirect so the admin lands
		   back where they were. */
		$q          = trim( (string) ( Request::i()->q ?? '' ) );
		$orphanOnly = (int) ( Request::i()->orphan ?? 0 ) === 1;
		$page       = max( 1, (int) ( Request::i()->page ?? 1 ) );

		$url = Url::internal( 'app=gdloadout&module=manage&controller=loadouts' )
			->setQueryString( array_filter( [
				'q'      => $q !== '' ? $q : null,
				'orphan' => $orphanOnly ? 1 : null,
				'page'   => $page > 1 ? $page : null,
			] ) );
		Output::i()->redirect( $url, 'gdloadout_acp_loadouts_deleted' );
	}
}

class loadouts extends _loadouts {}
