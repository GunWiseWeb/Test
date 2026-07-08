<?php
/**
 * @brief  GD FFL Finder — ACP: import the ATF full-FFL CSV +
 *         load the bundled ZIP centroid file.
 *
 * Both imports run as background queue tasks so 77k rows (ATF)
 * and 33-42k rows (Census ZCTA) never run in one web request.
 * Progress is visible via IPS's ACP queue viewer once queued.
 *
 * gd_ffl and gd_zip_geo are the only tables this controller
 * writes; both writes happen inside the queue extensions, not
 * inline here. This file only accepts the upload / kicks the
 * background task.
 */

namespace IPS\gdffl\modules\admin\manage;

use IPS\gdffl\Ffl\Ffl;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _import extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'ffl_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		$lang = \IPS\Member::loggedIn()->language();
		$esc  = fn( string $s ) => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );

		$importUrl = (string) \IPS\Http\Url::internal( 'app=gdffl&module=manage&controller=import&do=uploadAct' );
		$zipUrl    = (string) \IPS\Http\Url::internal( 'app=gdffl&module=manage&controller=import&do=zipGeoLoadAct' );
		$csrfKey   = (string) \IPS\Session::i()->csrfKey;

		$fflCount = 0;
		try { $fflCount = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_ffl' )->first(); } catch ( \Throwable ) {}
		$zipCount = 0;
		try { $zipCount = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_zip_geo' )->first(); } catch ( \Throwable ) {}

		$lastImportAt   = (int) \IPS\Settings::i()->gdffl_last_import_at;
		$lastImportRows = (int) \IPS\Settings::i()->gdffl_last_import_rows;
		$lastImportSkip = (int) \IPS\Settings::i()->gdffl_last_import_skipped;

		$html = '<div class="ipsBox" style="margin-bottom:16px"><div class="ipsBox_body ipsPad">'
			. '<h2 class="ipsType_sectionHead" style="margin:0 0 8px">' . $esc( (string) $lang->addToStack( 'gdffl_acp_import_title' ) ) . '</h2>'
			. '<p style="margin:0 0 12px">' . $esc( (string) $lang->addToStack( 'gdffl_acp_import_intro' ) ) . '</p>'
			. '<div style="margin:0 0 12px;color:#475569">Current rows: <strong>' . number_format( $fflCount ) . '</strong> FFLs · <strong>' . number_format( $zipCount ) . '</strong> ZIP centroids.</div>';

		if ( $lastImportAt > 0 )
		{
			$html .= '<div style="margin:0 0 12px;color:#64748b;font-size:.9em">'
				. sprintf(
					$esc( (string) $lang->addToStack( 'gdffl_acp_last_import' ) ),
					number_format( $lastImportRows ),
					number_format( $lastImportSkip ),
					$esc( (string) \IPS\DateTime::ts( $lastImportAt )->format( 'Y-m-d H:i' ) )
				)
				. '</div>';
		}

		$html .= '<form method="post" action="' . $esc( $importUrl ) . '" enctype="multipart/form-data">'
			. '<input type="hidden" name="csrfKey" value="' . $esc( $csrfKey ) . '">'
			. '<div style="margin-bottom:10px">'
			. '<label for="gdffl-file" style="display:block;font-weight:600;margin-bottom:4px">' . $esc( (string) $lang->addToStack( 'gdffl_acp_import_upload' ) ) . '</label>'
			. '<input id="gdffl-file" name="ffl_file" type="file" accept=".csv,.txt,text/csv,text/plain" required>'
			. '</div>'
			. '<button type="submit" class="ipsButton ipsButton--primary">' . $esc( (string) $lang->addToStack( 'gdffl_acp_import_submit' ) ) . '</button>'
			. '</form>'
			. '</div></div>';

		$html .= '<div class="ipsBox"><div class="ipsBox_body ipsPad">'
			. '<h2 class="ipsType_sectionHead" style="margin:0 0 8px">' . $esc( (string) $lang->addToStack( 'gdffl_acp_zipgeo_title' ) ) . '</h2>'
			. '<p style="margin:0 0 12px">' . $esc( (string) $lang->addToStack( 'gdffl_acp_zipgeo_intro' ) ) . '</p>'
			. '<form method="post" action="' . $esc( $zipUrl ) . '">'
			. '<input type="hidden" name="csrfKey" value="' . $esc( $csrfKey ) . '">'
			. '<button type="submit" class="ipsButton ipsButton--important">' . $esc( (string) $lang->addToStack( 'gdffl_acp_zipgeo_load' ) ) . '</button>'
			. '</form>'
			. '</div></div>';

		\IPS\Output::i()->title  = $lang->addToStack( 'gdffl_acp_import_title' );
		\IPS\Output::i()->output = $html;
	}

	/**
	 * POST: accept the ATF CSV upload, save to a work path, and
	 * queue the background import task. The task itself lives in
	 * extensions/core/Queue/FflImport.php and runs the batched
	 * insert loop.
	 */
	protected function uploadAct(): void
	{
		\IPS\Session::i()->csrfCheck();

		if ( empty( $_FILES['ffl_file']['tmp_name'] ) || !is_uploaded_file( $_FILES['ffl_file']['tmp_name'] ) )
		{
			\IPS\Output::i()->error( 'gdffl_err_no_upload', '2GDFFL/1', 400 );
			return;
		}

		$workDir = \IPS\ROOT_PATH . '/uploads/gdffl';
		if ( !is_dir( $workDir ) ) { @mkdir( $workDir, 0755, TRUE ); }
		$workPath = $workDir . '/atf-' . bin2hex( random_bytes( 6 ) ) . '.csv';

		if ( !@move_uploaded_file( $_FILES['ffl_file']['tmp_name'], $workPath ) )
		{
			\IPS\Output::i()->error( 'gdffl_err_bad_file', '2GDFFL/2', 500 );
			return;
		}

		/* Resolve delimiter from setting; the queue's preQueueData()
		   auto-detects when 'auto'. */
		$delimSetting = (string) ( \IPS\Settings::i()->gdffl_delimiter ?: 'auto' );
		$delim        = match ( $delimSetting ) {
			'tab'   => "\t",
			'comma' => ',',
			default => 'auto',
		};
		$mode = (string) ( \IPS\Settings::i()->gdffl_import_mode ?: 'replace' );
		if ( !in_array( $mode, [ 'replace', 'merge' ], TRUE ) ) { $mode = 'replace'; }

		try
		{
			\IPS\Task::queue( 'gdffl', 'FflImport', [
				'file'      => $workPath,
				'delimiter' => $delim,
				'mode'      => $mode,
				'total'     => 0,
				'skipped'   => 0,
				'header'    => [],
			], 2 );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdffl queue enqueue: ' . $e->getMessage(), 'gdffl' ); } catch ( \Throwable ) {}
			\IPS\Output::i()->error( 'gdffl_err_bad_file', '2GDFFL/3', 500 );
			return;
		}

		\IPS\Output::i()->redirect(
			(string) \IPS\Http\Url::internal( 'app=gdffl&module=manage&controller=import' ),
			'gdffl_acp_import_queued'
		);
	}

	/**
	 * POST: queue the bundled ZIP-centroid load. Chunked so a
	 * full US ZCTA file (~33-42k rows) can't time out. Safe to
	 * re-run — the queue writes via Db::replace() (idempotent PK).
	 */
	protected function zipGeoLoadAct(): void
	{
		\IPS\Session::i()->csrfCheck();

		$bundledPath = \IPS\ROOT_PATH . '/applications/gdffl/data/zip_geo.csv';

		try
		{
			\IPS\Task::queue( 'gdffl', 'ZipGeoImport', [
				'file'  => $bundledPath,
				'total' => 0,
			], 2 );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdffl zipgeo queue: ' . $e->getMessage(), 'gdffl' ); } catch ( \Throwable ) {}
		}

		\IPS\Output::i()->redirect(
			(string) \IPS\Http\Url::internal( 'app=gdffl&module=manage&controller=import' ),
			'gdffl_acp_zipgeo_queued'
		);
	}
}

class import extends _import {}
