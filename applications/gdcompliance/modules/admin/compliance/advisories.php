<?php
/**
 * @brief  GD Compliance — Buyer-permit ADVISORY dashboard (v1.6.17)
 *
 * Reads/writes gd_compliance_advisory_rules — one row per (state,
 * firearm_class). Displays currently-enabled advisories with their
 * per-state customer-visible reason text, editable inline.
 *
 * Advisories are NOT restrictions. Every row here means: item ships;
 * buyer needs a permit/card/training step at the FFL.
 */

namespace IPS\gdcompliance\modules\admin\compliance;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _advisories extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	const STATE_NAMES = [
		'CO' => 'Colorado',
		'MN' => 'Minnesota',
	];

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'compliance_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		$lang = \IPS\Member::loggedIn()->language();
		$h    = fn( string $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );

		$saved = (int) ( \IPS\Request::i()->saved ?? 0 ) === 1;

		/* Live counts across gd_compliance_flags for advisory rows. */
		$perState = [];
		try
		{
			foreach ( \IPS\Db::i()->select(
				'state_code, COUNT(*) AS c',
				'gd_compliance_flags',
				[ 'firearm_type=?', 'advisory' ],
				null,
				null,
				'state_code'
			) as $row )
			{
				$perState[ (string) $row['state_code'] ] = (int) $row['c'];
			}
		}
		catch ( \Throwable ) {}
		$totalAdvisories = array_sum( $perState );

		$distinctAdvisoryUpcs = 0;
		try
		{
			$distinctAdvisoryUpcs = (int) \IPS\Db::i()->select( 'COUNT(DISTINCT upc)', 'gd_compliance_flags',
				[ 'firearm_type=?', 'advisory' ] )->first();
		}
		catch ( \Throwable ) {}

		$rules = [];
		try
		{
			foreach ( \IPS\Db::i()->select( '*', 'gd_compliance_advisory_rules', null,
				'state_code ASC, firearm_class ASC' ) as $row )
			{
				$rules[] = $row;
			}
		}
		catch ( \Throwable ) {}

		$savedBanner = '';
		if ( $saved )
		{
			$savedBanner = '<div class="ipsBox" style="margin-bottom:14px;border-left:4px solid #059669"><div class="ipsBox_body ipsPad" style="background:#ecfdf5"><strong style="color:#065f46">Saved.</strong> Recompute to re-emit advisory flags with the updated text.</div></div>';
		}

		$summary = '<div class="ipsBox" style="margin-bottom:14px"><div class="ipsBox_body ipsPad">'
			. '<h2 class="ipsType_sectionHead" style="margin:0 0 6px">' . $h( $lang->addToStack( 'gdcompliance_acp_adv_title' ) ) . '</h2>'
			. '<p style="margin:0 0 10px;color:#475569">' . $h( $lang->addToStack( 'gdcompliance_acp_adv_intro' ) ) . '</p>'
			. '<div style="display:flex;gap:24px;flex-wrap:wrap;font-size:13px">'
			. '<div><strong style="color:#0f172a;font-size:22px">' . number_format( $distinctAdvisoryUpcs ) . '</strong><br><span style="color:#64748b">distinct products with advisories</span></div>'
			. '<div><strong style="color:#0f172a;font-size:22px">' . number_format( $totalAdvisories ) . '</strong><br><span style="color:#64748b">total advisory rows</span></div>'
			. '<div><strong style="color:#0f172a;font-size:22px">' . number_format( count( $perState ) ) . '</strong><br><span style="color:#64748b">active states</span></div>'
			. '</div>'
			. '</div></div>';

		/* Per-rule edit cards. */
		$editCards = '';
		if ( empty( $rules ) )
		{
			$editCards = '<div class="ipsBox"><div class="ipsBox_body ipsPad" style="text-align:center;color:#94a3b8">No advisory rules seeded yet. Run upg_10617 to populate CO + MN.</div></div>';
		}
		else
		{
			$csrf = (string) \IPS\Session::i()->csrfKey;
			foreach ( $rules as $r )
			{
				$id    = (int)    ( $r['id'] ?? 0 );
				$state = strtoupper( (string) ( $r['state_code'] ?? '' ) );
				$cls   = strtolower( (string) ( $r['firearm_class'] ?? 'rifle' ) );
				$ena   = (int) ( $r['enabled'] ?? 0 ) === 1;
				$reason  = (string) ( $r['reason']   ?? '' );
				$cite    = (string) ( $r['citation'] ?? '' );
				$effDate = (string) ( $r['effective_date'] ?? '' );

				$flagCount = (int) ( $perState[ $state ] ?? 0 );
				$stateName = self::STATE_NAMES[ $state ] ?? $state;

				$saveUrl = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=advisories&do=save' );

				$editCards .= '<div class="ipsBox" style="margin-bottom:14px"><div class="ipsBox_body ipsPad">'
					. '<div style="display:flex;justify-content:space-between;align-items:baseline;margin:0 0 8px">'
					. '<h3 style="margin:0;font-size:15px;color:#0f172a"><span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:700;background:#fef3c7;color:#78350f;margin-right:8px">' . $h( $state ) . '</span> ' . $h( $stateName ) . ' <span style="color:#64748b;font-size:12px;font-weight:400">&middot; ' . $h( $cls ) . '</span></h3>'
					. '<span style="color:#64748b;font-size:12px">' . number_format( $flagCount ) . ' advisory rows on flags</span>'
					. '</div>'
					. '<form method="post" action="' . $h( $saveUrl ) . '">'
					. '<input type="hidden" name="csrfKey" value="' . $h( $csrf ) . '">'
					. '<input type="hidden" name="id" value="' . $id . '">'
					. '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px 20px;margin-bottom:10px">'
					. '<label style="display:flex;flex-direction:column;gap:3px;font-size:12px"><span>Enabled</span><select name="enabled">'
					. '<option value="1"' . ( $ena ? ' selected' : '' ) . '>Enabled</option>'
					. '<option value="0"' . ( !$ena ? ' selected' : '' ) . '>Disabled</option>'
					. '</select></label>'
					. '<label style="display:flex;flex-direction:column;gap:3px;font-size:12px"><span>Effective date (YYYY-MM-DD; blank = active now)</span><input type="text" name="effective_date" placeholder="YYYY-MM-DD" maxlength="10" value="' . $h( $effDate ) . '"></label>'
					. '<label style="display:flex;flex-direction:column;gap:3px;font-size:12px;grid-column:span 2"><span>Citation</span><input type="text" name="citation" maxlength="255" value="' . $h( $cite ) . '"></label>'
					. '<label style="display:flex;flex-direction:column;gap:3px;font-size:12px;grid-column:span 2"><span>Customer-visible advisory text</span><textarea name="reason" rows="5">' . $h( $reason ) . '</textarea><span style="color:#64748b;font-size:11px">Shown in the yellow advisory block on the product page. Wording should make it clear the item CAN ship — the buyer completes a permit/card step at the FFL.</span></label>'
					. '</div>'
					. '<button type="submit" class="ipsButton ipsButton--primary ipsButton--small">Save ' . $h( $state ) . '</button>'
					. '</form>'
					. '</div></div>';
			}
		}

		\IPS\Output::i()->title  = $lang->addToStack( 'gdcompliance_acp_adv_title' );
		\IPS\Output::i()->output = $savedBanner . $summary . $editCards;
	}

	protected function save(): void
	{
		\IPS\Session::i()->csrfCheck();
		$id = (int) ( \IPS\Request::i()->id ?? 0 );
		if ( $id <= 0 )
		{
			\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=advisories' ) );
			return;
		}
		try
		{
			$effRaw = trim( (string) ( \IPS\Request::i()->effective_date ?? '' ) );
			$eff    = null;
			if ( $effRaw !== '' && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $effRaw ) )
			{
				$eff = $effRaw;
			}
			\IPS\Db::i()->update( 'gd_compliance_advisory_rules', [
				'enabled'        => (int) ( \IPS\Request::i()->enabled ?? 0 ) === 1 ? 1 : 0,
				'reason'         => trim( (string) \IPS\Request::i()->reason ),
				'citation'       => substr( (string) \IPS\Request::i()->citation, 0, 255 ) ?: null,
				'effective_date' => $eff,
				'updated_at'     => time(),
			], [ 'id=?', $id ] );
			try { \IPS\gdcompliance\Advisories::clearCache(); } catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'advisories save: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}
		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=advisories' )->setQueryString( 'saved', 1 )
		);
	}
}

class advisories extends _advisories {}
