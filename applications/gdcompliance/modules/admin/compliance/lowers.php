<?php
/**
 * @brief  GD Compliance — Lowers & Receivers (v1.6.10)
 *
 * Curated override layer on top of the auto cat154 matcher. Same UI
 * conventions as awbmodels.php: Table\Db over gd_compliance_lowers,
 * add/edit/toggle/delete row buttons, plus an auto-summary card and a
 * per-UPC test box that runs Lowers::classify() live.
 *
 * Curated entries WIN over auto logic (force_clear/force_flag/review).
 * Empty table → pure auto behavior.
 */

namespace IPS\gdcompliance\modules\admin\compliance;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _lowers extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	const ACTIONS = [
		'force_flag'  => 'Force flag (curated match)',
		'force_clear' => 'Force clear (never flag)',
		'review'      => 'Route to review',
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

		/* Auto-summary card — counts across cat154 with the CURRENT
		   classifier. Uses SELECT COUNT(*) FROM gd_catalog + a
		   sub-iter-classify pass; the numbers are read-only and only
		   as fresh as the last catalog import. Per-request memo on
		   Lowers::classify keeps this cheap even for 135 rows. */
		try
		{
			require_once \IPS\ROOT_PATH . '/applications/gdcompliance/sources/Lowers.php';
			\IPS\gdcompliance\Lowers::clearCache();
		}
		catch ( \Throwable ) {}

		$totals = [ 'flag' => 0, 'review' => 0, 'clear' => 0, 'skip' => 0, 'total' => 0 ];
		try
		{
			foreach ( \IPS\Db::i()->select( 'upc, category_id, title, brand, manufacturer, model, mpn, caliber',
				'gd_catalog', [ 'category_id=?', \IPS\gdcompliance\Lowers::CATEGORY_LOWER ] ) as $p )
			{
				$totals['total']++;
				$v = \IPS\gdcompliance\Lowers::classify( $p );
				if ( !is_array( $v ) ) { $totals['skip']++; continue; }
				$verd = (string) ( $v['verdict'] ?? '' );
				if ( isset( $totals[ $verd ] ) ) { $totals[ $verd ]++; }
			}
		}
		catch ( \Throwable ) {}

		$summary = '<div class="ipsBox" style="margin-bottom:14px"><div class="ipsBox_body ipsPad">'
			. '<h2 class="ipsType_sectionHead" style="margin:0 0 6px">' . $h( $lang->addToStack( 'gdcompliance_acp_lowers_title' ) ) . '</h2>'
			. '<p style="margin:0 0 10px;color:#475569">' . $h( $lang->addToStack( 'gdcompliance_acp_lowers_intro' ) ) . '</p>'
			. '<div style="display:flex;gap:16px;flex-wrap:wrap;font-size:13px">'
			. '<div><strong style="color:#0f172a;font-size:20px">' . number_format( (int) $totals['total'] ) . '</strong><br><span style="color:#64748b">cat154 rows</span></div>'
			. '<div><strong style="color:#991b1b;font-size:20px">' . number_format( (int) $totals['flag'] ) . '</strong><br><span style="color:#64748b">will flag</span></div>'
			. '<div><strong style="color:#a16207;font-size:20px">' . number_format( (int) $totals['review'] ) . '</strong><br><span style="color:#64748b">to review</span></div>'
			. '<div><strong style="color:#059669;font-size:20px">' . number_format( (int) $totals['clear'] ) . '</strong><br><span style="color:#64748b">force clear</span></div>'
			. '<div><strong style="color:#94a3b8;font-size:20px">' . number_format( (int) $totals['skip'] ) . '</strong><br><span style="color:#64748b">skipped (parts)</span></div>'
			. '</div>'
			. '</div></div>';

		/* Test box — enter a UPC, show the verdict live. */
		$testHtml = '';
		$testUpc  = trim( (string) ( \IPS\Request::i()->test_upc ?? '' ) );
		if ( $testUpc !== '' )
		{
			$row = null;
			try
			{
				$row = \IPS\Db::i()->select( 'upc, category_id, title, brand, manufacturer, model, mpn, caliber',
					'gd_catalog', [ 'upc=?', $testUpc ] )->first();
			}
			catch ( \Throwable ) { $row = null; }

			if ( !is_array( $row ) )
			{
				$testHtml = '<div style="padding:10px;background:#fff7ed;border:1px solid #fdba74;border-radius:6px;color:#7c2d12">UPC <code>' . $h( $testUpc ) . '</code> not found in gd_catalog.</div>';
			}
			else
			{
				$v = \IPS\gdcompliance\Lowers::classify( $row );
				if ( !is_array( $v ) )
				{
					$testHtml = '<div style="padding:10px;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:6px"><strong>Skip</strong> &mdash; not in lower categories (cat154/69), or excluded as a part/upper.</div>';
				}
				else
				{
					$verd = (string) ( $v['verdict'] ?? '' );
					$src  = (string) ( $v['source']  ?? '' );
					$pat  = (string) ( $v['pattern'] ?? '' );
					$hint = (string) ( $v['reason_hint'] ?? '' );
					$note = (string) ( $v['note'] ?? '' );

					$colors = [
						'flag'   => [ 'bg' => '#fee2e2', 'br' => '#f87171', 'fg' => '#991b1b' ],
						'review' => [ 'bg' => '#fef3c7', 'br' => '#fbbf24', 'fg' => '#78350f' ],
						'clear'  => [ 'bg' => '#dcfce7', 'br' => '#34d399', 'fg' => '#065f46' ],
					];
					$c = $colors[ $verd ] ?? [ 'bg' => '#f1f5f9', 'br' => '#cbd5e1', 'fg' => '#334155' ];

					$testHtml = '<div style="padding:12px;background:' . $c['bg'] . ';border:1px solid ' . $c['br'] . ';border-radius:6px;color:' . $c['fg'] . '">'
						. '<strong style="text-transform:uppercase;font-size:12px">' . $h( $verd ) . '</strong>'
						. ( $src !== '' ? ' <span style="font-size:11px;color:#475569">source: ' . $h( $src ) . '</span>' : '' )
						. '<br><span style="font-size:13px;color:#334155">' . $h( (string) $row['title'] ) . '</span>'
						. ( $pat  !== '' ? '<br><small>matched: ' . $h( $pat  ) . '</small>' : '' )
						. ( $hint !== '' ? '<br><small>hint: '    . $h( $hint ) . '</small>' : '' )
						. ( $note !== '' ? '<br><small>note: '    . $h( $note ) . '</small>' : '' )
						. '</div>';
				}
			}
		}

		$testUrl = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=lowers' );
		$csrf    = (string) \IPS\Session::i()->csrfKey;
		$testCard = '<div class="ipsBox" style="margin-bottom:14px"><div class="ipsBox_body ipsPad">'
			. '<h3 style="margin:0 0 8px;font-size:14px;color:#334155">' . $h( $lang->addToStack( 'gdcompliance_acp_lowers_test' ) ) . '</h3>'
			. '<form method="get" action="' . $h( $testUrl ) . '" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">'
			. '<input type="hidden" name="app" value="gdcompliance"><input type="hidden" name="module" value="compliance"><input type="hidden" name="controller" value="lowers">'
			. '<input type="search" name="test_upc" value="' . $h( $testUpc ) . '" placeholder="UPC to test" style="flex:1 1 240px;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px">'
			. '<button type="submit" class="ipsButton ipsButton--primary ipsButton--small">Test</button>'
			. '</form>'
			. $testHtml
			. '</div></div>';

		/* Curated-list Table\Db. */
		$baseUrl = \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=lowers' );
		$table   = new \IPS\Helpers\Table\Db( 'gd_compliance_lowers', $baseUrl );
		$table->langPrefix    = 'gdcompliance_acp_lowers_col_';
		$table->include       = [ 'pattern', 'platform', 'action', 'note' ];
		$table->sortBy        = $table->sortBy ?: 'action';
		$table->sortDirection = $table->sortDirection ?: 'asc';

		$actionColors = [
			'force_flag'  => [ 'bg' => '#fee2e2', 'fg' => '#991b1b', 'lbl' => 'FLAG' ],
			'force_clear' => [ 'bg' => '#dcfce7', 'fg' => '#065f46', 'lbl' => 'CLEAR' ],
			'review'      => [ 'bg' => '#fef3c7', 'fg' => '#78350f', 'lbl' => 'REVIEW' ],
		];
		$table->parsers = [
			'pattern'  => fn( $v ) => '<strong style="font-family:ui-monospace,monospace">' . $h( (string) $v ) . '</strong>',
			'platform' => fn( $v ) => $v ? '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:#dbeafe;color:#1e3a8a">' . $h( (string) $v ) . '</span>' : '<span style="color:#cbd5e1">—</span>',
			'action'   => function( $v ) use ( $h, $actionColors ) {
				$key = (string) $v;
				$c   = $actionColors[ $key ] ?? [ 'bg' => '#f1f5f9', 'fg' => '#334155', 'lbl' => strtoupper( $key ) ];
				return '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:700;background:' . $c['bg'] . ';color:' . $c['fg'] . '">' . $h( $c['lbl'] ) . '</span>';
			},
			'note'     => fn( $v ) => $v ? '<span style="color:#475569;font-size:12px">' . $h( (string) $v ) . '</span>' : '<span style="color:#cbd5e1">—</span>',
		];
		$table->rowButtons = function( $row ) {
			$base = 'app=gdcompliance&module=compliance&controller=lowers';
			return [
				'edit'   => [ 'icon' => 'pencil',       'title' => 'edit',   'link' => \IPS\Http\Url::internal( $base . '&do=form&id=' . (int) $row['id'] ) ],
				'delete' => [ 'icon' => 'times-circle', 'title' => 'delete', 'link' => \IPS\Http\Url::internal( $base . '&do=delete&id=' . (int) $row['id'] )->csrf(), 'data' => [ 'delete' => '' ] ],
			];
		};

		$addUrl = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=lowers&do=form' );
		$intro  = '<div class="ipsBox" style="margin-bottom:14px"><div class="ipsBox_body ipsPad">'
			. '<h3 style="margin:0 0 6px;font-size:14px;color:#334155">' . $h( $lang->addToStack( 'gdcompliance_acp_lowers_curated' ) ) . '</h3>'
			. '<p style="margin:0 0 10px;color:#475569;font-size:13px">' . $h( $lang->addToStack( 'gdcompliance_acp_lowers_curated_intro' ) ) . '</p>'
			. '<a href="' . $h( $addUrl ) . '" class="ipsButton ipsButton--primary ipsButton--small">' . $h( $lang->addToStack( 'gdcompliance_acp_lowers_add' ) ) . '</a>'
			. '</div></div>';

		\IPS\Output::i()->title  = $lang->addToStack( 'gdcompliance_acp_lowers_title' );
		\IPS\Output::i()->output = $summary . $testCard . $intro . (string) $table;
	}

	protected function form(): void
	{
		$id  = (int) ( \IPS\Request::i()->id ?? 0 );
		$row = null;
		if ( $id > 0 )
		{
			try { $row = \IPS\Db::i()->select( '*', 'gd_compliance_lowers', [ 'id=?', $id ] )->first(); }
			catch ( \Throwable ) { $row = null; }
		}

		$form = new \IPS\Helpers\Form;
		$form->add( new \IPS\Helpers\Form\Text(   'gdcompliance_lowers_f_pattern',  $row['pattern']  ?? '', TRUE,  [ 'maxLength' => 191 ] ) );
		$form->add( new \IPS\Helpers\Form\Text(   'gdcompliance_lowers_f_platform', $row['platform'] ?? '', FALSE, [ 'maxLength' => 40 ] ) );
		$form->add( new \IPS\Helpers\Form\Select( 'gdcompliance_lowers_f_action',   $row['action']   ?? 'force_flag', TRUE, [ 'options' => self::ACTIONS ] ) );
		$form->add( new \IPS\Helpers\Form\Text(   'gdcompliance_lowers_f_note',     $row['note']     ?? '', FALSE, [ 'maxLength' => 255 ] ) );

		if ( $values = $form->values() )
		{
			$pattern = trim( (string) $values['gdcompliance_lowers_f_pattern'] );
			$action  = (string) $values['gdcompliance_lowers_f_action'];
			if ( $pattern === '' || strlen( $pattern ) < 3 )
			{
				$form->error = 'Pattern must be at least 3 characters.';
			}
			elseif ( !isset( self::ACTIONS[ $action ] ) )
			{
				$form->error = 'Invalid action.';
			}
			else
			{
				$data = [
					'pattern'    => substr( $pattern, 0, 191 ),
					'platform'   => substr( (string) $values['gdcompliance_lowers_f_platform'], 0, 40 ) ?: null,
					'action'     => $action,
					'note'       => substr( (string) $values['gdcompliance_lowers_f_note'], 0, 255 ) ?: null,
					'created_at' => time(),
				];
				try
				{
					if ( $row ) { \IPS\Db::i()->update( 'gd_compliance_lowers', $data, [ 'id=?', $id ] ); }
					else        { \IPS\Db::i()->insert( 'gd_compliance_lowers', $data ); }
					try { \IPS\gdcompliance\Lowers::clearCache(); } catch ( \Throwable ) {}
				}
				catch ( \Throwable $e )
				{
					try { \IPS\Log::log( 'lowers save: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
				}

				\IPS\Output::i()->redirect(
					\IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=lowers' ),
					'saved'
				);
				return;
			}
		}

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gdcompliance_acp_lowers_add' );
		\IPS\Output::i()->output = (string) $form;
	}

	protected function delete(): void
	{
		\IPS\Session::i()->csrfCheck();
		$id = (int) ( \IPS\Request::i()->id ?? 0 );
		if ( $id > 0 )
		{
			try
			{
				\IPS\Db::i()->delete( 'gd_compliance_lowers', [ 'id=?', $id ] );
				try { \IPS\gdcompliance\Lowers::clearCache(); } catch ( \Throwable ) {}
			}
			catch ( \Throwable ) {}
		}
		\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=lowers' ), 'deleted' );
	}
}

class lowers extends _lowers {}
