<?php
/**
 * @brief  GD Compliance — ACP: API Keys (Product A, Stage 1)
 *
 * Manual key management for the /compliance-api/ endpoint. Stage 1 is
 * intentionally lightweight — Derrick creates test keys by hand for
 * dealer integration testing. Stage 2 wires Nexus subscriptions to
 * auto-issue keys on purchase and auto-suspend on payment failure.
 *
 * Row schema: gd_compliance_api_keys
 *   id, api_key, member_id, label, status, created_at, last_used_at,
 *   request_count.
 *
 * Actions (all POST + CSRF-checked):
 *   do=form          → new-key form  (GET)
 *   do=createAct     → issue key + insert row (POST)
 *   do=toggleAct     → suspend / reactivate  (POST, CSRF via URL is fine
 *                       because the action redirects — rule #62 note)
 *   do=revokeAct     → revoke (permanent)  (POST)
 *   do=reactivateAct → active                (POST)
 *
 * The plain key is stored + shown in the ACP so Derrick can copy it
 * to the dealer's clipboard (Stage-2 will move to hash-only once
 * self-serve issuance is in place).
 */

namespace IPS\gdcompliance\modules\admin\compliance;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _apikeys extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'compliance_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		$lang = \IPS\Member::loggedIn()->language();
		$h    = fn( string $k ) => htmlspecialchars( (string) $lang->addToStack( $k ), ENT_QUOTES, 'UTF-8' );
		$esc  = fn( string $s ) => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );

		$counts = [ 'active' => 0, 'suspended' => 0, 'revoked' => 0, 'all' => 0 ];
		try
		{
			foreach ( \IPS\Db::i()->select( 'status, COUNT(*) AS n', 'gd_compliance_api_keys', null, null, null, 'status' ) as $r )
			{
				$s = (string) ( $r['status'] ?? '' );
				$n = (int)    ( $r['n']      ?? 0 );
				if ( isset( $counts[ $s ] ) ) { $counts[ $s ] = $n; }
				$counts['all'] += $n;
			}
		}
		catch ( \Throwable ) {}

		$createUrl = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=apikeys&do=form' );

		$intro = '<div class="ipsBox" style="margin-bottom:16px"><div class="ipsBox_body ipsPad">'
			. '<h2 class="ipsType_sectionHead" style="margin:0 0 8px">' . $h( 'gdcompliance_acp_apikeys_title' ) . '</h2>'
			. '<p style="margin:0 0 12px">' . $h( 'gdcompliance_acp_apikeys_intro' ) . '</p>'
			. '<div style="display:flex;gap:16px;margin-bottom:12px;flex-wrap:wrap">'
			. '<div><strong>' . number_format( $counts['active'] ) . '</strong> active</div>'
			. '<div><strong>' . number_format( $counts['suspended'] ) . '</strong> suspended</div>'
			. '<div><strong>' . number_format( $counts['revoked'] ) . '</strong> revoked</div>'
			. '</div>'
			. '<a href="' . $esc( $createUrl ) . '" class="ipsButton ipsButton--primary ipsButton--small">' . $h( 'gdcompliance_acp_apikeys_create' ) . '</a>'
			. '</div></div>';

		/* Flash: newly-created key surface (once — on redirect after create). */
		$newKey = (string) ( \IPS\Request::i()->created_key ?? '' );
		if ( $newKey !== '' )
		{
			$intro = '<div class="ipsMessage ipsMessage_success" style="margin-bottom:16px">'
				. '<strong>New API key created.</strong> Copy it now — this is the only time it will be highlighted.<br>'
				. '<code style="display:inline-block;background:#0f172a;color:#fef3c7;padding:6px 12px;border-radius:6px;margin-top:6px;font-family:ui-monospace,monospace">' . $esc( $newKey ) . '</code>'
				. '</div>' . $intro;
		}

		$baseUrl = \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=apikeys' );
		$table   = new \IPS\Helpers\Table\Db( 'gd_compliance_api_keys', $baseUrl );
		$table->langPrefix    = 'gdcompliance_acp_apikeys_col_';
		$table->include       = [ 'label', 'member_id', 'api_key', 'status', 'request_count', 'created_at', 'last_used_at' ];
		$table->sortBy        = $table->sortBy ?: 'created_at';
		$table->sortDirection = $table->sortDirection ?: 'desc';

		$table->parsers = [
			'label' => function( $v ) {
				return $v ? htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) : '<em style="color:#94a3b8">(unlabeled)</em>';
			},
			'member_id' => function( $v ) {
				if ( !$v ) { return '<span style="color:#cbd5e1">—</span>'; }
				try
				{
					$m   = \IPS\Member::load( (int) $v );
					$url = (string) $m->url();
					$name = htmlspecialchars( (string) $m->name, ENT_QUOTES, 'UTF-8' );
					return '<a href="' . htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' ) . '">' . $name . '</a>';
				}
				catch ( \Throwable ) { return '#' . (int) $v; }
			},
			'api_key' => function( $v ) {
				$s = (string) $v;
				if ( $s === '' ) { return '<span style="color:#cbd5e1">—</span>'; }
				$shown = htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
				return '<code style="font-family:ui-monospace,monospace;font-size:11px;background:#f1f5f9;padding:2px 6px;border-radius:4px">' . $shown . '</code>';
			},
			'status' => function( $v ) {
				$s = (string) $v;
				$style = match( $s ) {
					'active'    => 'background:#dcfce7;color:#14532d',
					'suspended' => 'background:#fef3c7;color:#78350f',
					'revoked'   => 'background:#fee2e2;color:#991b1b',
					default     => 'background:#e5e7eb;color:#374151',
				};
				return '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:700;text-transform:uppercase;' . $style . '">'
					. htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ) . '</span>';
			},
			'request_count' => function( $v ) { return '<span style="font-family:ui-monospace,monospace">' . number_format( (int) $v ) . '</span>'; },
			'created_at'   => function( $v ) { return $v ? htmlspecialchars( date( 'Y-m-d H:i', (int) $v ), ENT_QUOTES, 'UTF-8' ) : '<span style="color:#cbd5e1">—</span>'; },
			'last_used_at' => function( $v ) { return $v ? htmlspecialchars( date( 'Y-m-d H:i', (int) $v ), ENT_QUOTES, 'UTF-8' ) : '<span style="color:#cbd5e1">never</span>'; },
		];

		$table->rowButtons = function( $row ) {
			$base = 'app=gdcompliance&module=compliance&controller=apikeys';
			$status = (string) ( $row['status'] ?? 'active' );
			$btns = [];
			if ( $status === 'active' )
			{
				$btns['suspend'] = [
					'icon'  => 'pause',
					'title' => 'gdcompliance_acp_apikeys_action_suspend',
					'link'  => \IPS\Http\Url::internal( $base . '&do=suspendAct&id=' . (int) $row['id'] )->csrf(),
				];
			}
			elseif ( $status === 'suspended' )
			{
				$btns['reactivate'] = [
					'icon'  => 'play',
					'title' => 'gdcompliance_acp_apikeys_action_reactivate',
					'link'  => \IPS\Http\Url::internal( $base . '&do=reactivateAct&id=' . (int) $row['id'] )->csrf(),
				];
			}
			if ( $status !== 'revoked' )
			{
				$btns['revoke'] = [
					'icon'  => 'times-circle',
					'title' => 'gdcompliance_acp_apikeys_action_revoke',
					'link'  => \IPS\Http\Url::internal( $base . '&do=revokeAct&id=' . (int) $row['id'] )->csrf(),
				];
			}
			return $btns;
		};

		\IPS\Output::i()->title  = $lang->addToStack( 'gdcompliance_acp_apikeys_title' );
		\IPS\Output::i()->output = $intro . (string) $table;
	}

	/**
	 * GET: render the create-key form (member picker + label).
	 */
	protected function form(): void
	{
		$lang = \IPS\Member::loggedIn()->language();
		$esc  = fn( string $s ) => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );

		$actionUrl = (string) \IPS\Http\Url::internal(
			'app=gdcompliance&module=compliance&controller=apikeys&do=createAct'
		);
		$csrfKey = (string) \IPS\Session::i()->csrfKey;

		$html = '<div class="ipsBox" style="max-width:640px;margin:12px auto"><div class="ipsBox_body ipsPad">'
			. '<h2 class="ipsType_sectionHead" style="margin:0 0 12px">' . $esc( (string) $lang->addToStack( 'gdcompliance_acp_apikeys_create_title' ) ) . '</h2>'
			. '<form method="post" action="' . $esc( $actionUrl ) . '">'
			. '<input type="hidden" name="csrfKey" value="' . $esc( $csrfKey ) . '">'
			. '<div style="margin-bottom:12px">'
			. '<label for="gdak-member" style="display:block;font-weight:600;margin-bottom:4px">Owner (member ID)</label>'
			. '<input type="number" id="gdak-member" name="member_id" min="1" required style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:6px">'
			. '<p style="margin:4px 0 0;font-size:12px;color:#64748b">Numeric member_id of the dealer this key belongs to.</p>'
			. '</div>'
			. '<div style="margin-bottom:12px">'
			. '<label for="gdak-label" style="display:block;font-weight:600;margin-bottom:4px">Label</label>'
			. '<input type="text" id="gdak-label" name="label" maxlength="100" required style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:6px" placeholder="e.g. Acme Guns — BigCommerce">'
			. '</div>'
			. '<button type="submit" class="ipsButton ipsButton--primary">' . $esc( (string) $lang->addToStack( 'gdcompliance_acp_apikeys_create' ) ) . '</button> '
			. '<a href="' . $esc( (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=apikeys' ) ) . '" class="ipsButton ipsButton--link">Cancel</a>'
			. '</form></div></div>';

		\IPS\Output::i()->title  = $lang->addToStack( 'gdcompliance_acp_apikeys_create_title' );
		\IPS\Output::i()->output = $html;
	}

	/**
	 * POST: issue a new random key + insert row + redirect back with
	 * the plaintext key in a flash param (surfaced ONCE on the list
	 * page). 40-char hex is generated via CSPRNG.
	 */
	protected function createAct(): void
	{
		\IPS\Session::i()->csrfCheck();

		$memberId = (int) ( \IPS\Request::i()->member_id ?? 0 );
		$label    = trim( substr( (string) ( \IPS\Request::i()->label ?? '' ), 0, 100 ) );

		if ( $memberId <= 0 || $label === '' )
		{
			\IPS\Output::i()->error( 'Owner member_id and label are required.', '2GDAK/1', 400 );
			return;
		}

		try
		{
			$m = \IPS\Member::load( $memberId );
			if ( !$m || !$m->member_id )
			{
				\IPS\Output::i()->error( 'No member with that id.', '2GDAK/2', 400 );
				return;
			}
		}
		catch ( \Throwable )
		{
			\IPS\Output::i()->error( 'Invalid member.', '2GDAK/3', 400 );
			return;
		}

		/* 40-char CSPRNG hex. Prefix with "gdc_" for identifiability in
		   dealer logs (so a leaked key is easy to attribute). */
		try { $token = 'gdc_' . bin2hex( random_bytes( 20 ) ); }
		catch ( \Throwable )
		{
			\IPS\Output::i()->error( 'Could not generate a secure key.', '2GDAK/4', 500 );
			return;
		}

		try
		{
			\IPS\Db::i()->insert( 'gd_compliance_api_keys', [
				'api_key'       => $token,
				'member_id'     => $memberId,
				'label'         => $label,
				'status'        => 'active',
				'created_at'    => time(),
				'last_used_at'  => null,
				'request_count' => 0,
			] );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'apikeys createAct: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
			\IPS\Output::i()->error( 'Could not store the new key.', '2GDAK/5', 500 );
			return;
		}

		\IPS\Output::i()->redirect(
			(string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=apikeys&created_key=' . rawurlencode( $token ) )
		);
	}

	/**
	 * Row action: suspend an active key. Idempotent — a key already
	 * suspended stays suspended.
	 */
	protected function suspendAct(): void
	{
		\IPS\Session::i()->csrfCheck();
		$this->setStatus( 'suspended' );
	}

	protected function reactivateAct(): void
	{
		\IPS\Session::i()->csrfCheck();
		$this->setStatus( 'active' );
	}

	protected function revokeAct(): void
	{
		\IPS\Session::i()->csrfCheck();
		$this->setStatus( 'revoked' );
	}

	protected function setStatus( string $newStatus ): void
	{
		if ( !in_array( $newStatus, [ 'active', 'suspended', 'revoked' ], true ) )
		{
			\IPS\Output::i()->error( 'Bad status', '2GDAK/6', 400 );
			return;
		}
		$id = (int) ( \IPS\Request::i()->id ?? 0 );
		if ( $id <= 0 )
		{
			\IPS\Output::i()->error( 'Missing id', '2GDAK/7', 400 );
			return;
		}
		try
		{
			\IPS\Db::i()->update( 'gd_compliance_api_keys', [ 'status' => $newStatus ], [ 'id=?', $id ] );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'apikeys setStatus: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}
		\IPS\Output::i()->redirect(
			(string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=apikeys' )
		);
	}
}

class apikeys extends _apikeys {}
