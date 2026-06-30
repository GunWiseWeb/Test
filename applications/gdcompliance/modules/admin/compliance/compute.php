<?php
namespace IPS\gdcompliance\modules\admin\compliance;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _compute extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'compliance_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		$S    = \IPS\Settings::i();
		$lang = \IPS\Member::loggedIn()->language();
		$h    = fn( string $k ) => htmlspecialchars( (string) $lang->addToStack( $k ), ENT_QUOTES, 'UTF-8' );

		$last    = (string) ( $S->gdcompliance_last_run ?? '' );
		$lastTxt = $last !== '' ? htmlspecialchars( $last, ENT_QUOTES, 'UTF-8' ) : $h( 'gdcompliance_acp_compute_never' );

		$activeRuleCount = 0;
		try { $activeRuleCount = count( \IPS\gdcompliance\Engine::activeRules() ); } catch ( \Throwable ) {}

		$messages = '';
		if ( $activeRuleCount === 0 )
		{
			$messages .= '<div class="ipsMessage ipsMessage--warning" style="margin-bottom:16px"><div class="ipsBox_body ipsPad">'
				. $h( 'gdcompliance_acp_compute_no_rules' ) . '</div></div>';
		}

		$previewUrl = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=compute&do=preview' )->csrf();
		$runUrl     = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=compute&do=run' )->csrf();
		$clearUrl   = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=compute&do=clear' )->csrf();

		$intro = '<div class="ipsBox" style="margin-bottom:16px"><div class="ipsBox_body ipsPad">'
			. '<p style="margin:0 0 10px">' . $h( 'gdcompliance_acp_compute_intro' ) . '</p>'
			. '<p style="margin:0 0 14px"><strong>' . $h( 'gdcompliance_acp_compute_last' ) . ':</strong> ' . $lastTxt . '</p>'
			. '<div style="display:flex;gap:8px;flex-wrap:wrap">'
			. '<a href="' . htmlspecialchars( $previewUrl, ENT_QUOTES, 'UTF-8' ) . '" class="ipsButton ipsButton--secondary">' . $h( 'gdcompliance_acp_compute_preview' ) . '</a>'
			. '<a href="' . htmlspecialchars( $runUrl,     ENT_QUOTES, 'UTF-8' ) . '" class="ipsButton ipsButton--primary">'   . $h( 'gdcompliance_acp_compute_run' ) . '</a>'
			. '<a href="' . htmlspecialchars( $clearUrl,   ENT_QUOTES, 'UTF-8' ) . '" class="ipsButton ipsButton--soft" data-confirm>' . $h( 'gdcompliance_acp_compute_clear' ) . '</a>'
			. '</div></div></div>';

		/* If a preview / run just ran, the request includes ?summary=base64(json)
		   so we can render the results panel. Kept in URL params so the
		   redirect-back pattern works without session state. */
		$resultPanel = '';
		$rawSummary  = (string) ( \IPS\Request::i()->summary ?? '' );
		if ( $rawSummary !== '' )
		{
			$decoded = json_decode( (string) @base64_decode( $rawSummary, true ), true );
			if ( is_array( $decoded ) )
			{
				$resultPanel = $this->renderSummary( $decoded );
			}
		}

		\IPS\Output::i()->title  = $lang->addToStack( 'gdcompliance_acp_compute_title' );
		\IPS\Output::i()->output = $messages . $intro . $resultPanel;
	}

	protected function preview(): void
	{
		\IPS\Session::i()->csrfCheck();
		$result = [ 'processed' => 0, 'firearms' => 0, 'flags' => 0, 'per_state' => [], 'per_state_type' => [], 'unparsed' => [], 'sample' => [], 'dry_run' => true ];
		try { $result = \IPS\gdcompliance\Engine::computeFlags( true ); } catch ( \Throwable $e ) {
			try { \IPS\Log::log( 'compute preview: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}
		$this->redirectWithSummary( $result, 'gdcompliance_acp_compute_preview_done', $result['flags'], count( $result['per_state'] ) );
	}

	protected function run(): void
	{
		\IPS\Session::i()->csrfCheck();
		$result = [ 'processed' => 0, 'firearms' => 0, 'flags' => 0, 'per_state' => [], 'per_state_type' => [], 'unparsed' => [], 'sample' => [], 'dry_run' => false ];
		try { $result = \IPS\gdcompliance\Engine::computeFlags( false ); } catch ( \Throwable $e ) {
			try { \IPS\Log::log( 'compute run: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}
		$this->redirectWithSummary( $result, 'gdcompliance_acp_compute_run_done', $result['flags'], count( $result['per_state'] ) );
	}

	protected function clear(): void
	{
		\IPS\Session::i()->csrfCheck();
		$counts = [ 'flags' => 0, 'unparsed' => 0 ];
		try { $counts = \IPS\gdcompliance\Engine::clearFlags(); } catch ( \Throwable ) {}
		$msg = (string) \IPS\Member::loggedIn()->language()->addToStack( 'gdcompliance_acp_compute_cleared', false, [
			'sprintf' => [ (int) $counts['flags'], (int) $counts['unparsed'] ],
		] );
		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=compute' ),
			$msg
		);
	}

	protected function redirectWithSummary( array $result, string $doneKey, int $a, int $b ): void
	{
		$summary = base64_encode( (string) json_encode( $result ) );
		$url     = \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=compute' )
			->setQueryString( 'summary', $summary );
		$msg = (string) \IPS\Member::loggedIn()->language()->addToStack( $doneKey, false, [ 'sprintf' => [ $a, $b ] ] );
		\IPS\Output::i()->redirect( $url, $msg );
	}

	protected function renderSummary( array $r ): string
	{
		$lang = \IPS\Member::loggedIn()->language();
		$h    = fn( string $k ) => htmlspecialchars( (string) $lang->addToStack( $k ), ENT_QUOTES, 'UTF-8' );

		$head = ! empty( $r['dry_run'] )
			? $h( 'gdcompliance_acp_compute_preview' )
			: $h( 'gdcompliance_acp_compute_run' );

		$out  = '<div class="ipsBox" style="margin-bottom:16px"><div class="ipsBox_body ipsPad">'
			. '<h2 class="ipsType_sectionHead" style="margin:0 0 10px">' . $head
			. ' &mdash; ' . (int) $r['flags'] . ' flags / ' . (int) $r['firearms'] . ' firearm products / ' . (int) $r['processed'] . ' total scanned</h2>';

		/* Per-state roster outcomes (Phase 2 + Phase 3 multi-state). */
		if ( !empty( $r['roster'] ) )
		{
			$out .= '<div style="margin:6px 0 14px;padding:10px 14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;font-size:13px;color:#1e3a8a">';
			$out .= '<strong style="display:block;margin-bottom:6px">Roster outcomes by state:</strong>';
			foreach ( [ 'CA', 'MA', 'MD', 'DC' ] as $rs )
			{
				if ( !isset( $r['roster'][ $rs ] ) ) { continue; }
				$ro = $r['roster'][ $rs ];
				$out .= '<div style="margin:2px 0">'
					. '<strong style="display:inline-block;min-width:32px">' . $rs . ':</strong> '
					. '<span style="color:#14532d">' . (int) ( $ro['on']     ?? 0 ) . ' on</span> &middot; '
					. '<span style="color:#991b1b">' . (int) ( $ro['off']    ?? 0 ) . ' off</span> &middot; '
					. '<span style="color:#92400e">' . (int) ( $ro['review'] ?? 0 ) . ' review</span>'
					. '</div>';
			}
			$out .= '</div>';
		}

		/* Per-state table */
		$out .= '<h3 style="margin:14px 0 6px">' . $h( 'gdcompliance_acp_compute_per_state' ) . '</h3>';
		$out .= '<table class="ipsTable ipsTable_responsive" style="width:100%;border-collapse:collapse">'
			. '<thead><tr><th style="text-align:left;padding:6px 10px;border-bottom:2px solid #e6e9ee">State</th>'
			. '<th style="text-align:right;padding:6px 10px;border-bottom:2px solid #e6e9ee">Flags</th>'
			. '<th style="text-align:left;padding:6px 10px;border-bottom:2px solid #e6e9ee">By type</th>'
			. '</tr></thead><tbody>';
		if ( ! empty( $r['per_state'] ) )
		{
			arsort( $r['per_state'] );
			foreach ( $r['per_state'] as $state => $count )
			{
				$byType = $r['per_state_type'][ $state ] ?? [];
				$parts  = [];
				foreach ( $byType as $t => $c ) { $parts[] = htmlspecialchars( "{$t}={$c}", ENT_QUOTES, 'UTF-8' ); }
				$out .= '<tr><td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;font-weight:700">'
					. htmlspecialchars( (string) $state, ENT_QUOTES, 'UTF-8' )
					. '</td><td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;text-align:right;font-family:ui-monospace,monospace">'
					. number_format( (int) $count )
					. '</td><td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;color:#64748b;font-size:12px">'
					. implode( ' &middot; ', $parts )
					. '</td></tr>';
			}
		}
		else
		{
			$out .= '<tr><td colspan="3" style="padding:14px;text-align:center;color:#94a3b8;font-style:italic">(no flags)</td></tr>';
		}
		$out .= '</tbody></table>';

		/* Sample */
		if ( ! empty( $r['sample'] ) )
		{
			$out .= '<h3 style="margin:18px 0 6px">' . $h( 'gdcompliance_acp_compute_sample' ) . '</h3>';
			$out .= '<table class="ipsTable ipsTable_responsive" style="width:100%;border-collapse:collapse">'
				. '<thead><tr>'
				. '<th style="text-align:left;padding:6px 10px;border-bottom:2px solid #e6e9ee">UPC</th>'
				. '<th style="text-align:left;padding:6px 10px;border-bottom:2px solid #e6e9ee">State</th>'
				. '<th style="text-align:left;padding:6px 10px;border-bottom:2px solid #e6e9ee">Type</th>'
				. '<th style="text-align:right;padding:6px 10px;border-bottom:2px solid #e6e9ee">Capacity</th>'
				. '<th style="text-align:right;padding:6px 10px;border-bottom:2px solid #e6e9ee">Limit</th>'
				. '</tr></thead><tbody>';
			foreach ( $r['sample'] as $s )
			{
				$out .= '<tr>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;font-family:ui-monospace,monospace">' . htmlspecialchars( (string) ( $s['upc'] ?? '' ), ENT_QUOTES, 'UTF-8' ) . '</td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;font-weight:700">' . htmlspecialchars( (string) ( $s['state'] ?? '' ), ENT_QUOTES, 'UTF-8' ) . '</td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9">' . htmlspecialchars( (string) ( $s['type'] ?? '' ), ENT_QUOTES, 'UTF-8' ) . '</td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;text-align:right;font-family:ui-monospace,monospace">' . (int) ( $s['capacity'] ?? 0 ) . '</td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;text-align:right;font-family:ui-monospace,monospace">' . (int) ( $s['limit'] ?? 0 ) . '</td>'
					. '</tr>';
			}
			$out .= '</tbody></table>';
		}

		/* Unparsed */
		if ( ! empty( $r['unparsed'] ) )
		{
			arsort( $r['unparsed'] );
			$out .= '<h3 style="margin:18px 0 6px">' . $h( 'gdcompliance_acp_compute_unparsed' ) . '</h3>';
			$out .= '<table class="ipsTable ipsTable_responsive" style="width:100%;border-collapse:collapse"><tbody>';
			foreach ( $r['unparsed'] as $val => $count )
			{
				$out .= '<tr>'
					. '<td style="padding:4px 10px;border-bottom:1px solid #f1f5f9;font-family:ui-monospace,monospace">' . htmlspecialchars( (string) $val, ENT_QUOTES, 'UTF-8' ) . '</td>'
					. '<td style="padding:4px 10px;border-bottom:1px solid #f1f5f9;text-align:right;font-family:ui-monospace,monospace">' . number_format( (int) $count ) . '</td>'
					. '</tr>';
			}
			$out .= '</tbody></table>';
		}

		$out .= '</div></div>';
		return $out;
	}
}

class compute extends _compute {}
