<?php
/**
 * @brief  GD Deals — Coupons ACP management (v1.0.55).
 *
 * Complements the front-end coupon widget/browse pages by giving
 * Derrick a dedicated ACP list to see, edit, delete, and manually
 * toggle the expired state of every post_type='coupon' row.
 *
 * Uses the ALREADY-EXISTING manually_expired_by / manually_expired_at
 * columns on gd_deal_posts — no schema change. Manual toggle writes
 * expired=1 + those two columns; un-toggle clears them and flips
 * expired back to 0 (respecting future expires_at if any).
 *
 * Status tabs mirror the gdrebates queue.php pattern:
 *   pending  — approved=0
 *   active   — approved=1, not expired, not manually expired
 *   expired  — expires_at<now OR expired=1 OR manually_expired_at IS NOT NULL
 *   all      — no filter
 * Default tab is 'active' (most useful working view).
 */

namespace IPS\gddeals\modules\admin\deals;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _coupons extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'settings_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		$status = (string) ( \IPS\Request::i()->status ?? 'active' );
		if ( !in_array( $status, [ 'active', 'expired', 'pending', 'all' ], true ) ) { $status = 'active'; }
		$now = time();

		$baseWhere = [ [ "post_type='coupon'" ] ];
		if ( $status === 'active' )
		{
			$baseWhere[] = [ 'approved=1' ];
			$baseWhere[] = [ '( expired = 0 OR expired IS NULL )' ];
			$baseWhere[] = [ 'manually_expired_at IS NULL' ];
			$baseWhere[] = [ '( expires_at IS NULL OR expires_at = 0 OR expires_at > ? )', $now ];
		}
		elseif ( $status === 'expired' )
		{
			$baseWhere[] = [
				'( expired = 1 OR manually_expired_at IS NOT NULL OR ( expires_at IS NOT NULL AND expires_at > 0 AND expires_at < ? ) )',
				$now,
			];
		}
		elseif ( $status === 'pending' )
		{
			$baseWhere[] = [ 'approved=0' ];
		}

		$baseUrl = \IPS\Http\Url::internal( 'app=gddeals&module=deals&controller=coupons' )->setQueryString( 'status', $status );
		$table = new \IPS\Helpers\Table\Db( 'gd_deal_posts', $baseUrl, $baseWhere );
		$table->langPrefix = 'gddeals_cp_';
		$table->include = [ 'title', 'retailer_name', 'promo_code', 'discount_pct', 'expires_at', 'show_source', 'featured' ];
		$table->sortBy = $table->sortBy ?: 'posted_at';
		$table->sortDirection = $table->sortDirection ?: 'desc';
		$table->parsers = [
			'expires_at'   => function( $v, $row ) use ( $now ) {
				if ( !$v ) { return '—'; }
				$str = (string) \IPS\DateTime::ts( $v )->localeDate();
				if ( (int) $v < $now )
				{
					return "<span style='color:#c0392b;'>{$str}</span>";
				}
				return $str;
			},
			'discount_pct' => function( $v ) {
				if ( $v === NULL || $v === '' ) { return '—'; }
				return (int) $v . '%';
			},
			'show_source'  => function( $v ) {
				return $v === 'dealer' ? "<span class='ipsBadge ipsBadge--positive'>Dealer</span>" : "<span class='ipsBadge ipsBadge--neutral'>Community</span>";
			},
			'featured'     => function( $v ) {
				return (int) $v ? "<span class='ipsBadge ipsBadge--warning' title='Featured'>&#9733;</span>" : '';
			},
			'promo_code'   => function( $v ) {
				$v = (string) $v;
				if ( $v === '' ) { return '—'; }
				return "<code>" . htmlspecialchars( $v, ENT_QUOTES, 'UTF-8' ) . "</code>";
			},
			'title'        => function( $v, $row ) {
				$t = htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' );
				$isExpiredComputed = $this->_computedExpired( $row );
				return $isExpiredComputed
					? "<span style='opacity:.55;text-decoration:line-through;'>{$t}</span>"
					: $t;
			},
		];
		$table->rowButtons = function( $row ) use ( $status ) {
			$base = 'app=gddeals&module=deals&controller=coupons';
			$qs   = '&status=' . urlencode( $status );
			$btns = [];

			$isManuallyExpired = !empty( $row['manually_expired_at'] );
			$btns['toggleexpired'] = [
				'icon'  => $isManuallyExpired ? 'undo' : 'ban',
				'title' => $isManuallyExpired ? 'gddeals_cp_unexpire' : 'gddeals_cp_expire',
				'link'  => \IPS\Http\Url::internal( $base . $qs . '&do=toggleexpired&id=' . $row['id'] )->csrf(),
			];
			$btns['delete'] = [
				'icon'  => 'times-circle',
				'title' => 'delete',
				'link'  => \IPS\Http\Url::internal( $base . $qs . '&do=delete&id=' . $row['id'] )->csrf(),
				'data'  => [ 'delete' => '' ],
			];
			return $btns;
		};

		$tabs = '';
		foreach ( [ 'active', 'pending', 'expired', 'all' ] as $s )
		{
			$url = \IPS\Http\Url::internal( 'app=gddeals&module=deals&controller=coupons' )->setQueryString( 'status', $s );
			$cnt = $this->_countForTab( $s, $now );
			$active = ( $s === $status ) ? ' ipsButton--primary' : ' ipsButton--soft';
			$lbl    = \IPS\Member::loggedIn()->language()->addToStack( 'gddeals_cp_tab_' . $s );
			$tabs  .= "<a class='ipsButton ipsButton--small{$active}' href='{$url}'>{$lbl} ({$cnt})</a> ";
		}

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'menu__gddeals_deals_coupons' );
		\IPS\Output::i()->output = "<p class='ipsPad'>{$tabs}</p>" . (string) $table;
	}

	protected function _computedExpired( array $row ): bool
	{
		$now = time();
		if ( (int) ( $row['expired'] ?? 0 ) === 1 )              { return TRUE; }
		if ( !empty( $row['manually_expired_at'] ) )              { return TRUE; }
		$ex = (int) ( $row['expires_at'] ?? 0 );
		if ( $ex > 0 && $ex < $now )                              { return TRUE; }
		return FALSE;
	}

	protected function _countForTab( string $tab, int $now ): int
	{
		try
		{
			if ( $tab === 'all' )
			{
				return (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_deal_posts', [ "post_type='coupon'" ] )->first();
			}
			if ( $tab === 'active' )
			{
				return (int) \IPS\Db::i()->select(
					'COUNT(*)',
					'gd_deal_posts',
					[
						"post_type='coupon' AND approved=1 AND ( expired=0 OR expired IS NULL ) AND manually_expired_at IS NULL AND ( expires_at IS NULL OR expires_at=0 OR expires_at > ? )",
						$now,
					]
				)->first();
			}
			if ( $tab === 'expired' )
			{
				return (int) \IPS\Db::i()->select(
					'COUNT(*)',
					'gd_deal_posts',
					[
						"post_type='coupon' AND ( expired=1 OR manually_expired_at IS NOT NULL OR ( expires_at IS NOT NULL AND expires_at > 0 AND expires_at < ? ) )",
						$now,
					]
				)->first();
			}
			if ( $tab === 'pending' )
			{
				return (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_deal_posts', [ "post_type='coupon' AND approved=0" ] )->first();
			}
		}
		catch ( \Throwable ) {}
		return 0;
	}

	protected function toggleexpired(): void
	{
		\IPS\Session::i()->csrfCheck();
		$id = (int) ( \IPS\Request::i()->id ?? 0 );
		if ( $id > 0 )
		{
			try
			{
				$row = \IPS\Db::i()->select( 'manually_expired_at, expires_at', 'gd_deal_posts', [ "id=? AND post_type='coupon'", $id ] )->first();
				$now = time();
				if ( !empty( $row['manually_expired_at'] ) )
				{
					/* Un-expire: clear manual flags. If expires_at is
					   in the future (or unset), also clear expired flag
					   — otherwise leave expired=1 because the date
					   itself justifies it. */
					$ex   = (int) ( $row['expires_at'] ?? 0 );
					$stillDatedExpired = ( $ex > 0 && $ex < $now );
					\IPS\Db::i()->update( 'gd_deal_posts', [
						'manually_expired_by' => NULL,
						'manually_expired_at' => NULL,
						'expired'             => $stillDatedExpired ? 1 : 0,
						'updated'             => $now,
					], [ 'id=?', $id ] );
				}
				else
				{
					/* Expire: set manual flags + expired=1. */
					\IPS\Db::i()->update( 'gd_deal_posts', [
						'manually_expired_by' => (int) \IPS\Member::loggedIn()->member_id,
						'manually_expired_at' => $now,
						'expired'             => 1,
						'updated'             => $now,
					], [ 'id=?', $id ] );
				}
			}
			catch ( \UnderflowException ) {}
			catch ( \Throwable $e )      { try { \IPS\Log::log( 'gddeals coupons toggleexpired: ' . $e->getMessage(), 'gddeals' ); } catch ( \Throwable ) {} }
		}
		$this->_backToList();
	}

	protected function delete(): void
	{
		\IPS\Session::i()->csrfCheck();
		$id = (int) ( \IPS\Request::i()->id ?? 0 );
		if ( $id > 0 )
		{
			try { \IPS\Db::i()->delete( 'gd_deal_posts', [ "id=? AND post_type='coupon'", $id ] ); }
			catch ( \Throwable $e ) { try { \IPS\Log::log( 'gddeals coupons delete: ' . $e->getMessage(), 'gddeals' ); } catch ( \Throwable ) {} }
		}
		$this->_backToList( 'deleted' );
	}

	protected function _backToList( ?string $msg = NULL ): void
	{
		$status = (string) ( \IPS\Request::i()->status ?? 'active' );
		$url = \IPS\Http\Url::internal( 'app=gddeals&module=deals&controller=coupons' )->setQueryString( 'status', $status );
		if ( $msg === NULL ) { \IPS\Output::i()->redirect( $url ); }
		else                 { \IPS\Output::i()->redirect( $url, $msg ); }
	}
}
class coupons extends _coupons {}
