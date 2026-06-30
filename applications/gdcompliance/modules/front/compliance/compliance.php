<?php
namespace IPS\gdcompliance\modules\front\compliance;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _compliance extends \IPS\Dispatcher\Controller
{
	protected function manage(): void
	{
		$lang  = \IPS\Member::loggedIn()->language();
		$today = date( 'Y-m-d' );

		$rules = [];
		try
		{
			foreach ( \IPS\Db::i()->select( 'state_code, firearm_type, max_capacity, rule_type, effective_date, expires_date, source_note',
				'gd_compliance_rules',
				[ 'enabled=1 AND (effective_date IS NULL OR effective_date<=?) AND (expires_date IS NULL OR expires_date>=?)', $today, $today ],
				'state_code ASC, firearm_type ASC'
			) as $r )
			{
				$rules[] = $r;
			}
		}
		catch ( \Throwable ) {}

		$h = fn( string $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );

		$out  = '<div class="ipsBox ipsBox_transparent ipsPad" style="max-width:920px;margin:0 auto">';
		$out .= '<h1 class="ipsType_pageTitle" style="margin:0 0 6px">' . $h( (string) $lang->addToStack( 'gdcompliance_page_title' ) ) . '</h1>';
		$out .= '<p style="margin:0 0 16px;color:#475569">' . $h( (string) $lang->addToStack( 'gdcompliance_page_intro' ) ) . '</p>';

		if ( empty( $rules ) )
		{
			$out .= '<p style="padding:24px;text-align:center;color:#94a3b8;font-style:italic">' . $h( (string) $lang->addToStack( 'gdcompliance_page_empty' ) ) . '</p>';
		}
		else
		{
			$out .= '<table class="ipsTable ipsTable_responsive" style="width:100%;border-collapse:collapse;background:#fff;border:1px solid #e6e9ee;border-radius:8px;overflow:hidden">'
				. '<thead><tr>'
				. '<th style="text-align:left;padding:10px 12px;background:#f8fafc;border-bottom:2px solid #e6e9ee">State</th>'
				. '<th style="text-align:left;padding:10px 12px;background:#f8fafc;border-bottom:2px solid #e6e9ee">Firearm</th>'
				. '<th style="text-align:right;padding:10px 12px;background:#f8fafc;border-bottom:2px solid #e6e9ee">Limit</th>'
				. '<th style="text-align:left;padding:10px 12px;background:#f8fafc;border-bottom:2px solid #e6e9ee">Type</th>'
				. '<th style="text-align:left;padding:10px 12px;background:#f8fafc;border-bottom:2px solid #e6e9ee">Source</th>'
				. '</tr></thead><tbody>';
			foreach ( $rules as $r )
			{
				$out .= '<tr>'
					. '<td style="padding:8px 12px;border-bottom:1px solid #f1f5f9;font-weight:700">' . $h( (string) $r['state_code'] ) . '</td>'
					. '<td style="padding:8px 12px;border-bottom:1px solid #f1f5f9">' . $h( (string) $r['firearm_type'] ) . '</td>'
					. '<td style="padding:8px 12px;border-bottom:1px solid #f1f5f9;text-align:right;font-family:ui-monospace,monospace">' . (int) $r['max_capacity'] . '</td>'
					. '<td style="padding:8px 12px;border-bottom:1px solid #f1f5f9;color:#475569;font-size:13px">' . $h( (string) $r['rule_type'] ) . '</td>'
					. '<td style="padding:8px 12px;border-bottom:1px solid #f1f5f9;color:#64748b;font-size:12px">' . $h( (string) ( $r['source_note'] ?? '' ) ) . '</td>'
					. '</tr>';
			}
			$out .= '</tbody></table>';
		}
		$out .= '</div>';

		\IPS\Output::i()->title  = $lang->addToStack( 'gdcompliance_page_title' );
		\IPS\Output::i()->output = $out;
	}
}

class compliance extends _compliance {}
