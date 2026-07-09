<?php
/**
 * @brief  GD FFL Finder — ACP importer.
 *         v1.0.4 — mirrors the gdbills upload pattern verbatim.
 *
 * WHY THIS FILE LOOKS THE WAY IT DOES:
 *   v1.0.0/1.0.2/1.0.3 hand-built the upload form as raw HTML
 *   pointing at a distinct do=fflUploadAct action. IPS's ACP
 *   dispatcher 301-redirected those POSTs, and a 301 on a
 *   multipart POST tells the browser to retry as GET, dropping
 *   $_FILES → gd_ffl stayed at 0. The 'admin' base fix in
 *   v1.0.3 still 301'd because the CANONICAL working ACP form
 *   pattern is NOT "form action = distinct do=", it is
 *   \IPS\Helpers\Form — which internally targets the SAME URL
 *   the form was rendered on, injects the required ACP session
 *   key + form key + CSRF, and lets the framework normalize
 *   the POST route so no redirect happens.
 *
 *   COPIED VERBATIM (structure) from
 *   applications/gdbills/modules/admin/bills/import.php:
 *     * new \IPS\Helpers\Form( 'gdbills_import', 'gdbills_acp_import_run' );
 *     * $form->add( new \IPS\Helpers\Form\Upload( 'gdbills_acp_import_file', null, TRUE, [
 *         'allowedFileTypes' => [ 'csv', 'txt' ], 'temporary' => true ] ) );
 *     * if ( $values = $form->values() ) { $file = $values[...]; }
 *     * echo (string) $form  →  IPS renders the form + posts to
 *                              the SAME URL that rendered it.
 *
 *   ATF-specific parsing (Ffl::toDbRow, header-name mapping,
 *   fgetcsv, batch AJAX loop) is unchanged; only the initial
 *   upload mechanism is swapped to the framework-blessed form.
 *
 * gd_ffl and gd_zip_geo are the only tables this controller
 * writes to. gd_zip_geo is SELECT-only inside Ffl::toDbRow().
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

	/** Rows processed per AJAX step. Big enough that ~77k rows
	 *  finish in ~40 requests; small enough that no single
	 *  request comes close to a PHP timeout. */
	protected const ROWS_PER_STEP = 2000;

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'ffl_manage' );
		parent::execute();
	}

	/* ------------------------------------------------------------------
	 * ACP PAGE — two \IPS\Helpers\Form instances (FFL + ZIP) plus
	 * a Load ZIP button, plus JS-driven progress panels for the
	 * two AJAX batch loops. Whichever form the admin submits is
	 * detected by ->values() returning non-false; the other form
	 * still renders untouched on the same page.
	 * ------------------------------------------------------------------ */
	protected function manage(): void
	{
		$lang = \IPS\Member::loggedIn()->language();

		/* ------------------------------------------------------------
		 * FFL upload form. Copies the gdbills shape verbatim so
		 * IPS's ACP dispatcher recognises the POST and does NOT
		 * 301 it. The Upload field with `temporary => true` gives
		 * us an \IPS\File on submit; we snapshot its contents into
		 * uploads/gdffl/atf-<rand>.csv and let the AJAX loop take
		 * it from there.
		 * ------------------------------------------------------------ */
		$fflForm = new \IPS\Helpers\Form( 'gdffl_atf_upload', 'gdffl_acp_import_submit' );
		$fflForm->add( new \IPS\Helpers\Form\Upload( 'gdffl_acp_import_file', null, TRUE, [
			'allowedFileTypes' => [ 'csv', 'txt' ],
			'maxFileSize'      => 128,
			'temporary'        => true,
		] ) );

		if ( $values = $fflForm->values() )
		{
			$file = $values['gdffl_acp_import_file'] ?? null;
			if ( $file instanceof \IPS\File )
			{
				$workDir = \IPS\ROOT_PATH . '/uploads/gdffl';
				if ( !is_dir( $workDir ) ) { @mkdir( $workDir, 0755, TRUE ); }
				$workPath = $workDir . '/atf-' . bin2hex( random_bytes( 6 ) ) . '.csv';

				try
				{
					$bytes = (string) $file->contents();
					@file_put_contents( $workPath, $bytes );
				}
				catch ( \Throwable $e )
				{
					try { $file->delete(); } catch ( \Throwable ) {}
					\IPS\Output::i()->error( 'gdffl_err_bad_file', '2GDFFL/1', 500 );
					return;
				}
				try { $file->delete(); } catch ( \Throwable ) {}

				$meta = $this->sniffCsv( $workPath );
				if ( $meta === null )
				{
					@unlink( $workPath );
					\IPS\Output::i()->error( 'gdffl_err_bad_file', '2GDFFL/2', 500 );
					return;
				}

				$mode = (string) ( \IPS\Settings::i()->gdffl_import_mode ?: 'replace' );
				if ( !in_array( $mode, [ 'replace', 'merge' ], TRUE ) ) { $mode = 'replace'; }

				$_SESSION['gdffl_ffl_job'] = [
					'file'      => $workPath,
					'delimiter' => $meta['delimiter'],
					'header'    => $meta['header'],
					'total'     => $meta['total'],
					'skipped'   => 0,
					'mode'      => $mode,
					'started'   => time(),
				];
				$_SESSION['gdffl_start_ffl'] = TRUE;

				\IPS\Output::i()->redirect(
					(string) \IPS\Http\Url::internal( 'app=gdffl&module=manage&controller=import', 'admin' )
				);
				return;
			}
		}

		/* ------------------------------------------------------------
		 * ZIP centroid upload form — same shape as the FFL form.
		 * Overwrites uploads/gdffl/zip_geo.csv (idempotent) so
		 * repeat uploads are safe.
		 * ------------------------------------------------------------ */
		$zipForm = new \IPS\Helpers\Form( 'gdffl_zip_upload', 'gdffl_acp_zipgeo_upload_submit' );
		$zipForm->add( new \IPS\Helpers\Form\Upload( 'gdffl_acp_zipgeo_file', null, TRUE, [
			'allowedFileTypes' => [ 'csv', 'txt' ],
			'maxFileSize'      => 32,
			'temporary'        => true,
		] ) );

		if ( $values = $zipForm->values() )
		{
			$file = $values['gdffl_acp_zipgeo_file'] ?? null;
			if ( $file instanceof \IPS\File )
			{
				$workDir = \IPS\ROOT_PATH . '/uploads/gdffl';
				if ( !is_dir( $workDir ) ) { @mkdir( $workDir, 0755, TRUE ); }
				$workPath = $workDir . '/zip_geo.csv';

				try
				{
					$bytes = (string) $file->contents();
					@file_put_contents( $workPath, $bytes );
				}
				catch ( \Throwable $e )
				{
					try { $file->delete(); } catch ( \Throwable ) {}
					\IPS\Output::i()->error( 'gdffl_err_bad_file', '2GDFFL/3', 500 );
					return;
				}
				try { $file->delete(); } catch ( \Throwable ) {}

				$total = $this->countCsvRows( $workPath );
				$_SESSION['gdffl_zip_job'] = [
					'file'    => $workPath,
					'total'   => $total,
					'skipped' => 0,
					'started' => time(),
				];
				$_SESSION['gdffl_start_zip'] = TRUE;

				\IPS\Output::i()->redirect(
					(string) \IPS\Http\Url::internal( 'app=gdffl&module=manage&controller=import', 'admin' )
				);
				return;
			}
		}

		/* ------------------------------------------------------------
		 * PAGE ASSEMBLY.
		 * ------------------------------------------------------------ */
		$esc = fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );

		$fflStepUrl  = (string) \IPS\Http\Url::internal( 'app=gdffl&module=manage&controller=import&do=fflStep',         'admin' );
		$zipStepUrl  = (string) \IPS\Http\Url::internal( 'app=gdffl&module=manage&controller=import&do=zipGeoStep',      'admin' );
		$fflStartUrl = (string) \IPS\Http\Url::internal( 'app=gdffl&module=manage&controller=import&do=fflResume',       'admin' );
		$zipStartUrl = (string) \IPS\Http\Url::internal( 'app=gdffl&module=manage&controller=import&do=zipResume',       'admin' );
		$zipLoadUrl  = (string) \IPS\Http\Url::internal( 'app=gdffl&module=manage&controller=import&do=zipGeoStart',     'admin' );
		$csrfKey     = (string) \IPS\Session::i()->csrfKey;

		$fflCount = 0;
		try { $fflCount = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_ffl' )->first(); } catch ( \Throwable ) {}
		$zipCount = 0;
		try { $zipCount = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_zip_geo' )->first(); } catch ( \Throwable ) {}

		$lastImportAt   = (int) \IPS\Settings::i()->gdffl_last_import_at;
		$lastImportRows = (int) \IPS\Settings::i()->gdffl_last_import_rows;
		$lastImportSkip = (int) \IPS\Settings::i()->gdffl_last_import_skipped;

		$autoStartFfl = !empty( $_SESSION['gdffl_start_ffl'] );
		$autoStartZip = !empty( $_SESSION['gdffl_start_zip'] );
		unset( $_SESSION['gdffl_start_ffl'], $_SESSION['gdffl_start_zip'] );

		$pendingAtf = $this->hasPendingAtfUpload();

		/* Intro + counts panel. */
		$intro  = '<div class="ipsBox" style="margin-bottom:16px"><div class="ipsBox_body ipsPad">';
		$intro .= '<h2 class="ipsType_sectionHead" style="margin:0 0 8px">' . $esc( (string) $lang->addToStack( 'gdffl_acp_import_title' ) ) . '</h2>';
		$intro .= '<p style="margin:0 0 12px">' . $esc( (string) $lang->addToStack( 'gdffl_acp_import_intro' ) ) . '</p>';
		$intro .= '<div style="margin:0 0 12px;color:#475569">Current rows: <strong>' . number_format( $fflCount ) . '</strong> FFLs · <strong>' . number_format( $zipCount ) . '</strong> ZIP centroids.</div>';
		if ( $lastImportAt > 0 )
		{
			$intro .= '<div style="margin:0 0 12px;color:#64748b;font-size:.9em">'
				. sprintf(
					$esc( (string) $lang->addToStack( 'gdffl_acp_last_import' ) ),
					number_format( $lastImportRows ),
					number_format( $lastImportSkip ),
					$esc( (string) \IPS\DateTime::ts( $lastImportAt )->format( 'Y-m-d H:i' ) )
				)
				. '</div>';
		}
		$intro .= '</div></div>';

		/* FFL upload panel — the \IPS\Helpers\Form renders its own
		   native ACP chrome (label + file input + submit button).
		   Explicit Start button below for the case where the
		   ACP session flag didn't survive the redirect. */
		$fflPanel  = '<div class="ipsBox" style="margin-bottom:16px"><div class="ipsBox_body ipsPad">';
		$fflPanel .= '<h3 class="ipsType_sectionHead" style="margin:0 0 12px">ATF FFL CSV</h3>';
		$fflPanel .= (string) $fflForm;
		if ( $pendingAtf )
		{
			$fflPanel .= '<div id="gdffl-ffl-startwrap" style="margin-top:12px">';
			$fflPanel .= '<form method="post" action="' . $esc( $fflStartUrl ) . '" style="display:inline">';
			$fflPanel .= '<input type="hidden" name="csrfKey" value="' . $esc( $csrfKey ) . '">';
			$fflPanel .= '<button type="submit" class="ipsButton ipsButton--important" id="gdffl-ffl-start">Start ATF import</button>';
			$fflPanel .= '</form>';
			$fflPanel .= '<span style="margin-left:8px;color:#64748b;font-size:.9em">A previously-uploaded ATF file is on disk. Click to start / resume the batch loop.</span>';
			$fflPanel .= '</div>';
		}
		$fflPanel .= '<div id="gdffl-ffl-progress" class="gdffl-progress" style="display:none;margin-top:14px">';
		$fflPanel .= '<div class="gdffl-progress-bar"><div class="gdffl-progress-fill" id="gdffl-ffl-fill" style="width:0%"></div></div>';
		$fflPanel .= '<div class="gdffl-progress-text" id="gdffl-ffl-text">Starting…</div>';
		$fflPanel .= '</div>';
		$fflPanel .= '</div></div>';

		/* ZIP upload panel — same shape, plus a "Load ZIP data"
		   button that starts the loop against whatever ZIP CSV is
		   on disk right now (uploads/gdffl/zip_geo.csv wins, then
		   the server-disk drop at data/zip_geo.csv, then the
		   10-row shipped sample). */
		$zipPanel  = '<div class="ipsBox" style="margin-bottom:16px"><div class="ipsBox_body ipsPad">';
		$zipPanel .= '<h3 class="ipsType_sectionHead" style="margin:0 0 8px">' . $esc( (string) $lang->addToStack( 'gdffl_acp_zipgeo_title' ) ) . '</h3>';
		$zipPanel .= '<p style="margin:0 0 12px">' . $esc( (string) $lang->addToStack( 'gdffl_acp_zipgeo_intro' ) ) . '</p>';
		$zipPanel .= (string) $zipForm;

		$zipPanel .= '<div style="margin-top:12px;padding-top:12px;border-top:1px solid #e2e8f0">';
		$zipPanel .= '<form method="post" action="' . $esc( $zipLoadUrl ) . '" style="display:inline">';
		$zipPanel .= '<input type="hidden" name="csrfKey" value="' . $esc( $csrfKey ) . '">';
		$zipPanel .= '<button type="submit" class="ipsButton" id="gdffl-zip-start">' . $esc( (string) $lang->addToStack( 'gdffl_acp_zipgeo_load' ) ) . '</button>';
		$zipPanel .= '<span style="margin-left:8px;color:#64748b;font-size:.9em">' . $esc( (string) $lang->addToStack( 'gdffl_acp_zipgeo_load_hint' ) ) . '</span>';
		$zipPanel .= '</form>';
		$zipPanel .= '</div>';

		$zipPanel .= '<div id="gdffl-zip-progress" class="gdffl-progress" style="display:none;margin-top:14px">';
		$zipPanel .= '<div class="gdffl-progress-bar"><div class="gdffl-progress-fill" id="gdffl-zip-fill" style="width:0%"></div></div>';
		$zipPanel .= '<div class="gdffl-progress-text" id="gdffl-zip-text">Starting…</div>';
		$zipPanel .= '</div>';
		$zipPanel .= '</div></div>';

		/* JS config — endpoints + csrf + autostart flags. */
		$cfg = [
			'fflStep'   => $fflStepUrl,
			'zipStep'   => $zipStepUrl,
			'fflResume' => $fflStartUrl,
			'zipResume' => $zipStartUrl,
			'csrfKey'   => $csrfKey,
			'startFfl'  => (bool) $autoStartFfl,
			'startZip'  => (bool) $autoStartZip,
		];
		$scriptTag = '<script>window.gdfflImportConfig = ' . json_encode( $cfg, JSON_UNESCAPED_SLASHES ) . ';</script>';

		/* Static CSS + JS from interface/ (rule #47). */
		try
		{
			\IPS\Output::i()->cssFiles = array_merge(
				\IPS\Output::i()->cssFiles,
				\IPS\Theme::i()->css( 'import.css', 'gdffl', 'interface' )
			);
			\IPS\Output::i()->jsFiles = array_merge(
				\IPS\Output::i()->jsFiles,
				\IPS\Output::i()->js( 'import.js', 'gdffl', 'interface' )
			);
		}
		catch ( \Throwable ) {}

		\IPS\Output::i()->title  = $lang->addToStack( 'gdffl_acp_import_title' );
		\IPS\Output::i()->output = $intro . $fflPanel . $zipPanel . $scriptTag;
	}

	/* ------------------------------------------------------------------
	 * "Load bundled ZIP centroid CSV" — resolves the on-disk path,
	 * primes the AJAX loop.
	 * ------------------------------------------------------------------ */
	protected function zipGeoStart(): void
	{
		\IPS\Session::i()->csrfCheck();

		$path = $this->resolveZipPath();
		if ( $path === '' || !is_readable( $path ) )
		{
			\IPS\Output::i()->error( 'gdffl_err_no_zip_file', '2GDFFL/6', 400 );
			return;
		}

		$total = $this->countCsvRows( $path );
		$_SESSION['gdffl_zip_job'] = [
			'file'    => $path,
			'total'   => $total,
			'skipped' => 0,
			'started' => time(),
		];
		$_SESSION['gdffl_start_zip'] = TRUE;

		\IPS\Output::i()->redirect(
			(string) \IPS\Http\Url::internal( 'app=gdffl&module=manage&controller=import', 'admin' )
		);
	}

	/* ------------------------------------------------------------------
	 * "Start ATF import" — re-primes the session from whatever
	 * atf-*.csv is on disk. Belt-and-suspenders for cases where
	 * the upload's session flag didn't survive the redirect.
	 * ------------------------------------------------------------------ */
	protected function fflResume(): void
	{
		\IPS\Session::i()->csrfCheck();

		$path = $this->latestAtfUpload();
		if ( $path === '' )
		{
			\IPS\Output::i()->error( 'gdffl_err_no_upload', '2GDFFL/7', 400 );
			return;
		}

		$meta = $this->sniffCsv( $path );
		if ( $meta === null )
		{
			\IPS\Output::i()->error( 'gdffl_err_bad_file', '2GDFFL/8', 500 );
			return;
		}

		$mode = (string) ( \IPS\Settings::i()->gdffl_import_mode ?: 'replace' );
		if ( !in_array( $mode, [ 'replace', 'merge' ], TRUE ) ) { $mode = 'replace'; }

		$_SESSION['gdffl_ffl_job'] = [
			'file'      => $path,
			'delimiter' => $meta['delimiter'],
			'header'    => $meta['header'],
			'total'     => $meta['total'],
			'skipped'   => 0,
			'mode'      => $mode,
			'started'   => time(),
		];
		$_SESSION['gdffl_start_ffl'] = TRUE;

		\IPS\Output::i()->redirect(
			(string) \IPS\Http\Url::internal( 'app=gdffl&module=manage&controller=import', 'admin' )
		);
	}

	protected function zipResume(): void
	{
		\IPS\Session::i()->csrfCheck();

		$path = $this->resolveZipPath();
		if ( $path === '' || !is_readable( $path ) )
		{
			\IPS\Output::i()->error( 'gdffl_err_no_zip_file', '2GDFFL/9', 400 );
			return;
		}

		$total = $this->countCsvRows( $path );
		$_SESSION['gdffl_zip_job'] = [
			'file'    => $path,
			'total'   => $total,
			'skipped' => 0,
			'started' => time(),
		];
		$_SESSION['gdffl_start_zip'] = TRUE;

		\IPS\Output::i()->redirect(
			(string) \IPS\Http\Url::internal( 'app=gdffl&module=manage&controller=import', 'admin' )
		);
	}

	/* ------------------------------------------------------------------
	 * AJAX ENDPOINT — FFL step. do=fflStep&offset=N.
	 * Header-name column mapping via array_flip so ATF column
	 * reorders don't break the import. fgetcsv for quoted fields
	 * (BUSINESS_NAMEs with embedded commas). Per-row try/catch.
	 * TRUNCATE gd_ffl once on the first batch in replace mode.
	 * ------------------------------------------------------------------ */
	protected function fflStep(): void
	{
		\IPS\Session::i()->csrfCheck();

		$job = $_SESSION['gdffl_ffl_job'] ?? null;
		if ( !is_array( $job ) || empty( $job['file'] ) )
		{
			$this->jsonError( 'no-job' );
			return;
		}

		$path   = (string) $job['file'];
		$delim  = (string) ( $job['delimiter'] ?? "\t" );
		$header = (array)  ( $job['header']    ?? [] );
		$total  = (int)    ( $job['total']     ?? 0 );
		$mode   = (string) ( $job['mode']      ?? 'replace' );
		$offset = max( 0, (int) ( \IPS\Request::i()->offset ?? 0 ) );

		if ( !is_readable( $path ) || empty( $header ) )
		{
			$this->jsonError( 'bad-file' );
			return;
		}

		if ( $offset === 0 && $mode === 'replace' )
		{
			try { \IPS\Db::i()->delete( 'gd_ffl' ); } catch ( \Throwable ) {}
		}

		$headerIndex = array_flip( array_values( $header ) );

		$fh = @fopen( $path, 'r' );
		if ( !$fh ) { $this->jsonError( 'open-failed' ); return; }

		fgetcsv( $fh, 0, $delim );
		$skip = $offset;
		while ( $skip > 0 && fgetcsv( $fh, 0, $delim ) !== false ) { $skip--; }

		$processed = 0;
		$skipped   = (int) ( $job['skipped'] ?? 0 );
		$now       = time();

		while ( $processed < self::ROWS_PER_STEP )
		{
			$fields = fgetcsv( $fh, 0, $delim );
			if ( $fields === false || $fields === null ) { break; }
			if ( count( $fields ) === 1 && ( $fields[0] === null || $fields[0] === '' ) )
			{
				$processed++;
				continue;
			}

			$row = [];
			foreach ( $headerIndex as $col => $idx )
			{
				$row[ (string) $col ] = $fields[ $idx ] ?? '';
			}

			try
			{
				$zipLatLng = $this->zipLookup( (string) ( $row['PREMISE_ZIP_CODE'] ?? '' ) );
				$dbRow     = Ffl::toDbRow( $row, $zipLatLng, $now );

				if ( $mode === 'merge' )
				{
					\IPS\Db::i()->insert( 'gd_ffl', $dbRow, TRUE );
				}
				else
				{
					\IPS\Db::i()->insert( 'gd_ffl', $dbRow );
				}
			}
			catch ( \Throwable )
			{
				$skipped++;
			}

			$processed++;
		}
		fclose( $fh );

		$newOffset      = $offset + $processed;
		$job['skipped'] = $skipped;
		$done           = ( $processed === 0 ) || ( $total > 0 && $newOffset >= $total );

		if ( $done )
		{
			try
			{
				\IPS\Settings::i()->changeValues( [
					'gdffl_last_import_at'      => time(),
					'gdffl_last_import_rows'    => $newOffset,
					'gdffl_last_import_skipped' => $skipped,
				] );
			}
			catch ( \Throwable ) {}

			if ( is_file( $path ) ) { @unlink( $path ); }
			unset( $_SESSION['gdffl_ffl_job'] );
		}
		else
		{
			$_SESSION['gdffl_ffl_job'] = $job;
		}

		$this->jsonOut( [
			'done'      => (bool) $done,
			'processed' => $newOffset,
			'total'     => $total,
			'offset'    => $newOffset,
			'skipped'   => $skipped,
		] );
	}

	/* ------------------------------------------------------------------
	 * AJAX ENDPOINT — ZIP-centroid step. do=zipGeoStep&offset=N.
	 * Walks # comments + optional header row, then does one
	 * replace() per data row against gd_zip_geo.
	 * ------------------------------------------------------------------ */
	protected function zipGeoStep(): void
	{
		\IPS\Session::i()->csrfCheck();

		$job = $_SESSION['gdffl_zip_job'] ?? null;
		if ( !is_array( $job ) || empty( $job['file'] ) )
		{
			$this->jsonError( 'no-job' );
			return;
		}

		$path   = (string) $job['file'];
		$total  = (int)    ( $job['total'] ?? 0 );
		$offset = max( 0, (int) ( \IPS\Request::i()->offset ?? 0 ) );

		if ( !is_readable( $path ) )
		{
			$this->jsonError( 'bad-file' );
			return;
		}

		$fh = @fopen( $path, 'r' );
		if ( !$fh ) { $this->jsonError( 'open-failed' ); return; }

		$processed  = 0;
		$skipped    = (int) ( $job['skipped'] ?? 0 );
		$seenBefore = 0;

		while ( $seenBefore < $offset )
		{
			$line = fgets( $fh );
			if ( $line === false ) { break; }
			$trim = trim( $line );
			if ( $trim === '' || $trim[0] === '#' ) { continue; }
			$firstComma = strpos( $trim, ',' );
			$firstCol   = $firstComma === false ? $trim : substr( $trim, 0, $firstComma );
			if ( strcasecmp( trim( $firstCol ), 'zip' ) === 0 ) { continue; }
			$seenBefore++;
		}

		while ( $processed < self::ROWS_PER_STEP )
		{
			$line = fgets( $fh );
			if ( $line === false ) { break; }
			$trim = trim( $line );
			if ( $trim === '' || $trim[0] === '#' ) { continue; }

			$fields = str_getcsv( $trim );
			if ( count( $fields ) < 3 ) { continue; }

			$first = trim( (string) $fields[0] );
			if ( strcasecmp( $first, 'zip' ) === 0 ) { continue; }

			$zip = Ffl::normalizeZip( $first );
			if ( $zip === '' ) { $skipped++; $processed++; continue; }

			$lat = (float) $fields[1];
			$lng = (float) $fields[2];
			if ( $lat === 0.0 && $lng === 0.0 ) { $skipped++; $processed++; continue; }

			$city  = isset( $fields[3] ) ? trim( (string) $fields[3] ) : '';
			$state = isset( $fields[4] ) ? strtoupper( trim( (string) $fields[4] ) ) : '';

			try
			{
				\IPS\Db::i()->replace( 'gd_zip_geo', [
					'zip'   => $zip,
					'lat'   => $lat,
					'lng'   => $lng,
					'city'  => $city !== '' ? $city : null,
					'state' => $state !== '' ? substr( $state, 0, 2 ) : null,
				] );
			}
			catch ( \Throwable )
			{
				$skipped++;
			}

			$processed++;
		}
		fclose( $fh );

		$newOffset      = $offset + $processed;
		$job['skipped'] = $skipped;
		$done           = ( $processed === 0 ) || ( $total > 0 && $newOffset >= $total );

		if ( $done )
		{
			unset( $_SESSION['gdffl_zip_job'] );
		}
		else
		{
			$_SESSION['gdffl_zip_job'] = $job;
		}

		$this->jsonOut( [
			'done'      => (bool) $done,
			'processed' => $newOffset,
			'total'     => $total,
			'offset'    => $newOffset,
			'skipped'   => $skipped,
		] );
	}

	/* ------------------------------------------------------------------
	 * INTERNAL HELPERS.
	 * ------------------------------------------------------------------ */
	protected function sniffCsv( string $path ): ?array
	{
		$fh = @fopen( $path, 'r' );
		if ( !$fh ) { return null; }

		$firstLine = fgets( $fh );
		if ( $firstLine === false ) { fclose( $fh ); return null; }

		$delimSetting = (string) ( \IPS\Settings::i()->gdffl_delimiter ?: 'auto' );
		$delim        = match ( $delimSetting ) {
			'tab'   => "\t",
			'comma' => ',',
			default => Ffl::detectDelimiter( $firstLine ),
		};

		$header = str_getcsv( trim( $firstLine, "\r\n" ), $delim );
		if ( !is_array( $header ) || count( $header ) < 3 ) { fclose( $fh ); return null; }
		$header = array_map( fn( $s ): string => trim( (string) $s ), $header );

		$count = 0;
		while ( ( $raw = fgets( $fh ) ) !== false )
		{
			if ( trim( $raw ) === '' ) { continue; }
			$count++;
		}
		fclose( $fh );

		return [
			'delimiter' => $delim,
			'header'    => $header,
			'total'     => $count,
		];
	}

	protected function countCsvRows( string $path ): int
	{
		$fh = @fopen( $path, 'r' );
		if ( !$fh ) { return 0; }

		$count = 0;
		while ( ( $raw = fgets( $fh ) ) !== false )
		{
			$trim = trim( $raw );
			if ( $trim === '' || $trim[0] === '#' ) { continue; }
			$firstComma = strpos( $trim, ',' );
			$firstCol   = $firstComma === false ? $trim : substr( $trim, 0, $firstComma );
			if ( strcasecmp( trim( $firstCol ), 'zip' ) === 0 ) { continue; }
			$count++;
		}
		fclose( $fh );

		return $count;
	}

	protected function resolveZipPath(): string
	{
		$uploaded = \IPS\ROOT_PATH . '/uploads/gdffl/zip_geo.csv';
		if ( is_readable( $uploaded ) ) { return $uploaded; }

		$bundled = \IPS\ROOT_PATH . '/applications/gdffl/data/zip_geo.csv';
		if ( is_readable( $bundled ) ) { return $bundled; }

		$sample = \IPS\ROOT_PATH . '/applications/gdffl/data/zip_geo.sample.csv';
		if ( is_readable( $sample ) ) { return $sample; }

		return '';
	}

	protected function hasPendingAtfUpload(): bool
	{
		return $this->latestAtfUpload() !== '';
	}

	protected function latestAtfUpload(): string
	{
		$dir = \IPS\ROOT_PATH . '/uploads/gdffl';
		if ( !is_dir( $dir ) ) { return ''; }

		$candidates = @glob( $dir . '/atf-*.csv' );
		if ( !is_array( $candidates ) || empty( $candidates ) ) { return ''; }

		$newest = '';
		$mtime  = 0;
		foreach ( $candidates as $candidate )
		{
			$ts = @filemtime( $candidate );
			if ( $ts !== false && $ts > $mtime )
			{
				$mtime  = $ts;
				$newest = $candidate;
			}
		}
		return $newest;
	}

	protected function zipLookup( string $rawZip ): array
	{
		$zip = Ffl::normalizeZip( $rawZip );
		if ( $zip === '' ) { return []; }
		try
		{
			$row = \IPS\Db::i()->select( 'lat, lng', 'gd_zip_geo', [ 'zip=?', $zip ] )->first();
			return [ $zip => [ (float) $row['lat'], (float) $row['lng'] ] ];
		}
		catch ( \Throwable )
		{
			return [];
		}
	}

	protected function jsonOut( array $payload ): void
	{
		\IPS\Output::i()->json( $payload );
	}

	protected function jsonError( string $code ): void
	{
		\IPS\Output::i()->json( [ 'done' => true, 'error' => $code ] );
	}
}

class import extends _import {}
