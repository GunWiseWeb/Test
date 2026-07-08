<?php
/**
 * @brief  GD FFL Finder — ACP: upload + AJAX-driven batch importer
 *         for the ATF full-FFL CSV and the Census ZCTA zip_geo file.
 *
 * WHY THIS EXISTS (v1.0.2 rewrite):
 *   Queue-based imports (extensions/core/Queue/*) shipped in v1.0.0
 *   but on low-traffic ACP sessions IPS's scheduled queue runner
 *   never fired, so the ATF CSV "queued" and gd_ffl stayed at 0.
 *   This controller now drives the imports directly from the
 *   browser via an AJAX batch loop:
 *     * manage() — renders the upload form + progress panels.
 *     * fflUploadAct() — accepts the ATF CSV, stores in uploads/
 *       gdffl/, redirects back to manage() with a session marker
 *       so the JS auto-starts the FFL loop.
 *     * zipGeoUploadAct() — same but for the Census ZCTA file.
 *     * fflStep() — JSON endpoint (do=fflStep&offset=N) — reads
 *       up to rowsPerCycle rows starting at offset, inserts them,
 *       returns { done, processed, total, offset } for the JS.
 *     * zipGeoStep() — same shape, against gd_zip_geo.
 *   The queue extensions are still shipped as an optional fallback
 *   (the scheduler picks them up when it eventually runs), but the
 *   AJAX loop is the primary path and does not depend on the queue.
 *
 * gd_ffl and gd_zip_geo are the only tables this controller writes
 * to. gd_zip_geo is SELECT-only inside Ffl::toDbRow().
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
	 *  finish in ~40 requests; small enough that no single request
	 *  ever comes close to a PHP timeout. */
	protected const ROWS_PER_STEP = 2000;

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'ffl_manage' );
		parent::execute();
	}

	/* ------------------------------------------------------------------
	 * ACP PAGE — form + progress panels.
	 * ------------------------------------------------------------------ */
	protected function manage(): void
	{
		$lang = \IPS\Member::loggedIn()->language();
		$esc  = fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );

		/* IPS ACP form actions + AJAX endpoints MUST be built with
		   the 'admin' base as Url::internal's 2nd arg. Without it,
		   the dispatcher issues a 301 to normalize to the ACP
		   entrypoint on POST — and a 301 on a multipart POST tells
		   the browser to retry as GET, which drops $_FILES and
		   loses the upload. Mirror the pattern from working ACP
		   forms in this codebase (see gddealer/modules/admin/
		   dealers/stockactions.php). */
		$importUrl   = (string) \IPS\Http\Url::internal( 'app=gdffl&module=manage&controller=import&do=fflUploadAct',    'admin' );
		$zipUpUrl    = (string) \IPS\Http\Url::internal( 'app=gdffl&module=manage&controller=import&do=zipGeoUploadAct', 'admin' );
		$zipLoadUrl  = (string) \IPS\Http\Url::internal( 'app=gdffl&module=manage&controller=import&do=zipGeoStart',     'admin' );
		$fflStepUrl  = (string) \IPS\Http\Url::internal( 'app=gdffl&module=manage&controller=import&do=fflStep',         'admin' );
		$zipStepUrl  = (string) \IPS\Http\Url::internal( 'app=gdffl&module=manage&controller=import&do=zipGeoStep',      'admin' );
		$fflStartUrl = (string) \IPS\Http\Url::internal( 'app=gdffl&module=manage&controller=import&do=fflResume',       'admin' );
		$zipStartUrl = (string) \IPS\Http\Url::internal( 'app=gdffl&module=manage&controller=import&do=zipResume',       'admin' );
		$csrfKey     = (string) \IPS\Session::i()->csrfKey;

		$fflCount = 0;
		try { $fflCount = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_ffl' )->first(); } catch ( \Throwable ) {}
		$zipCount = 0;
		try { $zipCount = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_zip_geo' )->first(); } catch ( \Throwable ) {}

		$lastImportAt   = (int) \IPS\Settings::i()->gdffl_last_import_at;
		$lastImportRows = (int) \IPS\Settings::i()->gdffl_last_import_rows;
		$lastImportSkip = (int) \IPS\Settings::i()->gdffl_last_import_skipped;

		/* When the FFL / ZIP upload has just completed we drop a
		   marker into the ACP session so the JS knows to auto-start
		   the corresponding AJAX batch loop. */
		$autoStartFfl = !empty( $_SESSION['gdffl_start_ffl'] );
		$autoStartZip = !empty( $_SESSION['gdffl_start_zip'] );
		unset( $_SESSION['gdffl_start_ffl'], $_SESSION['gdffl_start_zip'] );

		/* Belt-and-suspenders — if native $_SESSION on the ACP is
		   flaky and the autostart flag never survives the upload→
		   redirect round-trip, the admin can still see an explicit
		   "Start import" button because the upload file itself is
		   the source of truth: uploads/gdffl/atf-*.csv means "an
		   ATF upload is pending, no batch loop kicked yet."
		   uploads/gdffl/zip_geo.csv means "a ZIP upload is pending
		   OR was already fully loaded" — we surface a start button
		   any time the file exists so the admin can re-run. */
		$pendingAtf = $this->hasPendingAtfUpload();
		$pendingZip = $this->hasZipFileOnDisk();

		$html  = '<div class="ipsBox" style="margin-bottom:16px"><div class="ipsBox_body ipsPad">';
		$html .= '<h2 class="ipsType_sectionHead" style="margin:0 0 8px">' . $esc( (string) $lang->addToStack( 'gdffl_acp_import_title' ) ) . '</h2>';
		$html .= '<p style="margin:0 0 12px">' . $esc( (string) $lang->addToStack( 'gdffl_acp_import_intro' ) ) . '</p>';
		$html .= '<div style="margin:0 0 12px;color:#475569">Current rows: <strong>' . number_format( $fflCount ) . '</strong> FFLs · <strong>' . number_format( $zipCount ) . '</strong> ZIP centroids.</div>';

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

		$html .= '<form method="post" action="' . $esc( $importUrl ) . '" enctype="multipart/form-data" id="gdffl-ffl-form">';
		$html .= '<input type="hidden" name="csrfKey" value="' . $esc( $csrfKey ) . '">';
		$html .= '<div style="margin-bottom:10px">';
		$html .= '<label for="gdffl-file" style="display:block;font-weight:600;margin-bottom:4px">' . $esc( (string) $lang->addToStack( 'gdffl_acp_import_upload' ) ) . '</label>';
		$html .= '<input id="gdffl-file" name="ffl_file" type="file" accept=".csv,.txt,text/csv,text/plain" required>';
		$html .= '</div>';
		$html .= '<button type="submit" class="ipsButton ipsButton--primary">' . $esc( (string) $lang->addToStack( 'gdffl_acp_import_submit' ) ) . '</button>';
		$html .= '</form>';

		/* Explicit Start Import — visible when a pending ATF file
		   exists on disk (uploads/gdffl/atf-*.csv) but the auto-
		   start session flag has already been consumed or was
		   never set. Clicking POSTs to do=fflResume which primes
		   the session job from the on-disk file, then the JS kicks
		   the loop. */
		if ( $pendingAtf )
		{
			$html .= '<div id="gdffl-ffl-startwrap" style="margin-top:12px">';
			$html .= '<form method="post" action="' . $esc( $fflStartUrl ) . '" style="display:inline">';
			$html .= '<input type="hidden" name="csrfKey" value="' . $esc( $csrfKey ) . '">';
			$html .= '<button type="submit" class="ipsButton ipsButton--important" id="gdffl-ffl-start">Start ATF import</button>';
			$html .= '</form>';
			$html .= '<span style="margin-left:8px;color:#64748b;font-size:.9em">A previously-uploaded ATF file is on disk. Click to start / resume the batch loop.</span>';
			$html .= '</div>';
		}

		$html .= '<div id="gdffl-ffl-progress" class="gdffl-progress" style="display:none;margin-top:14px">';
		$html .= '<div class="gdffl-progress-bar"><div class="gdffl-progress-fill" id="gdffl-ffl-fill" style="width:0%"></div></div>';
		$html .= '<div class="gdffl-progress-text" id="gdffl-ffl-text">Starting…</div>';
		$html .= '</div>';

		$html .= '</div></div>';

		/* ZIP centroid panel. */
		$html .= '<div class="ipsBox"><div class="ipsBox_body ipsPad">';
		$html .= '<h2 class="ipsType_sectionHead" style="margin:0 0 8px">' . $esc( (string) $lang->addToStack( 'gdffl_acp_zipgeo_title' ) ) . '</h2>';
		$html .= '<p style="margin:0 0 12px">' . $esc( (string) $lang->addToStack( 'gdffl_acp_zipgeo_intro' ) ) . '</p>';

		$html .= '<form method="post" action="' . $esc( $zipUpUrl ) . '" enctype="multipart/form-data" id="gdffl-zip-form" style="margin-bottom:12px">';
		$html .= '<input type="hidden" name="csrfKey" value="' . $esc( $csrfKey ) . '">';
		$html .= '<div style="margin-bottom:10px">';
		$html .= '<label for="gdffl-zip-file" style="display:block;font-weight:600;margin-bottom:4px">' . $esc( (string) $lang->addToStack( 'gdffl_acp_zipgeo_upload' ) ) . '</label>';
		$html .= '<input id="gdffl-zip-file" name="zip_file" type="file" accept=".csv,.txt,text/csv,text/plain" required>';
		$html .= '</div>';
		$html .= '<button type="submit" class="ipsButton ipsButton--important">' . $esc( (string) $lang->addToStack( 'gdffl_acp_zipgeo_upload_submit' ) ) . '</button>';
		$html .= '</form>';

		$html .= '<form method="post" action="' . $esc( $zipLoadUrl ) . '" style="display:inline">';
		$html .= '<input type="hidden" name="csrfKey" value="' . $esc( $csrfKey ) . '">';
		$html .= '<button type="submit" class="ipsButton" id="gdffl-zip-start">' . $esc( (string) $lang->addToStack( 'gdffl_acp_zipgeo_load' ) ) . '</button>';
		$html .= '<span style="margin-left:8px;color:#64748b;font-size:.9em">' . $esc( (string) $lang->addToStack( 'gdffl_acp_zipgeo_load_hint' ) ) . '</span>';
		$html .= '</form>';

		if ( $pendingZip )
		{
			$html .= '<div style="margin-top:6px;color:#64748b;font-size:.9em">'
				. 'A ZIP centroid CSV is on disk and ready to load. Click above to start the batch loop.'
				. '</div>';
		}

		$html .= '<div id="gdffl-zip-progress" class="gdffl-progress" style="display:none;margin-top:14px">';
		$html .= '<div class="gdffl-progress-bar"><div class="gdffl-progress-fill" id="gdffl-zip-fill" style="width:0%"></div></div>';
		$html .= '<div class="gdffl-progress-text" id="gdffl-zip-text">Starting…</div>';
		$html .= '</div>';

		$html .= '</div></div>';

		/* Config payload for the JS — endpoints, csrf, autostart
		   flags, plus resume URLs for the explicit start buttons. */
		$cfg = [
			'fflStep'   => $fflStepUrl,
			'zipStep'   => $zipStepUrl,
			'fflResume' => $fflStartUrl,
			'zipResume' => $zipStartUrl,
			'csrfKey'   => $csrfKey,
			'startFfl'  => (bool) $autoStartFfl,
			'startZip'  => (bool) $autoStartZip,
		];
		$html .= '<script>window.gdfflImportConfig = ' . json_encode( $cfg, JSON_UNESCAPED_SLASHES ) . ';</script>';

		/* Static CSS + JS from interface/. Rule #47 — served
		   directly, no template-engine variable substitution. */
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
		\IPS\Output::i()->output = $html;
	}

	/* ------------------------------------------------------------------
	 * ATF CSV upload — stores to uploads/gdffl/atf-<rand>.csv,
	 * remembers the resolved delimiter + header + total row count
	 * in the ACP session, redirects back to manage() so the JS auto-
	 * kicks the AJAX loop on next page render.
	 * ------------------------------------------------------------------ */
	protected function fflUploadAct(): void
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

		/* Sniff header + delimiter + total row count so the AJAX
		   loop doesn't have to re-scan the file every step. */
		$meta = $this->sniffCsv( $workPath );
		if ( $meta === null )
		{
			@unlink( $workPath );
			\IPS\Output::i()->error( 'gdffl_err_bad_file', '2GDFFL/3', 500 );
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
	}

	/* ------------------------------------------------------------------
	 * ZIP centroid upload — stores to uploads/gdffl/zip_geo.csv,
	 * primes the ACP session for the AJAX loop.
	 * ------------------------------------------------------------------ */
	protected function zipGeoUploadAct(): void
	{
		\IPS\Session::i()->csrfCheck();

		if ( empty( $_FILES['zip_file']['tmp_name'] ) || !is_uploaded_file( $_FILES['zip_file']['tmp_name'] ) )
		{
			\IPS\Output::i()->error( 'gdffl_err_no_upload', '2GDFFL/4', 400 );
			return;
		}

		$workDir = \IPS\ROOT_PATH . '/uploads/gdffl';
		if ( !is_dir( $workDir ) ) { @mkdir( $workDir, 0755, TRUE ); }
		$workPath = $workDir . '/zip_geo.csv';

		if ( !@move_uploaded_file( $_FILES['zip_file']['tmp_name'], $workPath ) )
		{
			\IPS\Output::i()->error( 'gdffl_err_bad_file', '2GDFFL/5', 500 );
			return;
		}

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
	}

	/* ------------------------------------------------------------------
	 * "Load bundled ZIP centroid CSV" — resolves the on-disk path
	 * (uploaded file preferred; falls back to data/zip_geo.csv),
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
	 * AJAX ENDPOINT — FFL step. do=fflStep&offset=N.
	 * Reads up to ROWS_PER_STEP rows from the ATF CSV starting at
	 * offset, inserts each via Ffl::toDbRow(), returns JSON:
	 *   { done, processed, total, offset, skipped }
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

		/* On the first batch of a replace-mode import, truncate
		   gd_ffl exactly once. */
		if ( $offset === 0 && $mode === 'replace' )
		{
			try { \IPS\Db::i()->delete( 'gd_ffl' ); } catch ( \Throwable ) {}
		}

		/* Map header NAME → column index once so per-row lookup is
		   O(1) and independent of column ordering. */
		$headerIndex = array_flip( array_values( $header ) );

		$fh = @fopen( $path, 'r' );
		if ( !$fh ) { $this->jsonError( 'open-failed' ); return; }

		/* Skip the header row + everything up to $offset. */
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
			/* fgetcsv returns [ null ] for blank lines. */
			if ( count( $fields ) === 1 && ( $fields[0] === null || $fields[0] === '' ) )
			{
				$processed++;
				continue;
			}

			/* Header-name column mapping — array_flip lets us look
			   up any ATF column by its published name (LIC_REGN,
			   BUSINESS_NAME, PREMISE_ZIP_CODE, etc.). */
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

		$newOffset          = $offset + $processed;
		$job['skipped']     = $skipped;
		$done               = ( $processed === 0 ) || ( $total > 0 && $newOffset >= $total );

		if ( $done )
		{
			/* Stamp last-import settings, tidy up the work file. */
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

		/* zip_geo.csv format:
		     #-prefixed comment lines (any number)
		     optional header row (zip,lat,lng,city,state)
		     data rows              (zip,lat,lng,city,state)
		   We treat comments as skippable, and the header row is
		   detected by "zip" appearing in the first column (case-
		   insensitive) rather than by row position. */
		$processed  = 0;
		$skipped    = (int) ( $job['skipped'] ?? 0 );
		$seenBefore = 0;

		/* Walk to the correct data-row offset, counting only DATA
		   rows (not comments / header). This keeps the JS's
		   { processed / total } math consistent. */
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

		$newOffset       = $offset + $processed;
		$job['skipped']  = $skipped;
		$done            = ( $processed === 0 ) || ( $total > 0 && $newOffset >= $total );

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
	 * POST endpoint — "Start ATF import" button. Re-primes the
	 * session job from whatever atf-*.csv is on disk, then
	 * redirects back to manage() which auto-starts the JS loop.
	 * Belt-and-suspenders for cases where the upload's session
	 * flag didn't survive the round-trip.
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

	/* ------------------------------------------------------------------
	 * POST endpoint — "Start ZIP import" button. Same shape as
	 * fflResume, against the resolved ZIP centroid path.
	 * ------------------------------------------------------------------ */
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
	 * INTERNAL — does an ATF upload sit in uploads/gdffl/ waiting
	 * for its batch loop to start / resume? The upload's presence
	 * is the source of truth — the ACP session flag can be lost
	 * across the upload → 302 → manage() hop and we don't want
	 * that to strand the import.
	 * ------------------------------------------------------------------ */
	protected function hasPendingAtfUpload(): bool
	{
		return $this->latestAtfUpload() !== '';
	}

	/* ------------------------------------------------------------------
	 * INTERNAL — path to the most recent atf-*.csv upload sitting
	 * in uploads/gdffl/, or '' if none is present. Multiple files
	 * shouldn't accumulate (fflStep unlinks on done), but if two
	 * uploads race, take the newest by mtime.
	 * ------------------------------------------------------------------ */
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

	/* ------------------------------------------------------------------
	 * INTERNAL — is any ZIP centroid CSV on disk (uploaded or
	 * server-disk drop or the shipped sample)? True whenever
	 * resolveZipPath() would return a readable path.
	 * ------------------------------------------------------------------ */
	protected function hasZipFileOnDisk(): bool
	{
		$path = $this->resolveZipPath();
		return $path !== '' && is_readable( $path );
	}

	/* ------------------------------------------------------------------
	 * INTERNAL — sniff a CSV: detect delimiter, snapshot the
	 * header row, count total data rows (skipping blanks + header).
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

	/* ------------------------------------------------------------------
	 * INTERNAL — count data rows in a zip_geo-style CSV
	 * (skips #-comment lines and a zip-header row).
	 * ------------------------------------------------------------------ */
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

	/* ------------------------------------------------------------------
	 * INTERNAL — resolve the ZIP centroid file path. Uploaded copy
	 * in uploads/gdffl/ wins; on-disk data/zip_geo.csv is the
	 * fallback (for admins who dropped the real Census file directly
	 * onto the server).
	 * ------------------------------------------------------------------ */
	protected function resolveZipPath(): string
	{
		/* Uploaded via the ACP wins — that's the fastest path an
		   admin has to get real data in. Kept under uploads/ so it
		   survives an app-upgrade extraction. */
		$uploaded = \IPS\ROOT_PATH . '/uploads/gdffl/zip_geo.csv';
		if ( is_readable( $uploaded ) ) { return $uploaded; }

		/* Server-disk drop of the full Census ZCTA file. The
		   tarball never overwrites this path (it's excluded from
		   the build) so an admin who scp'd the real 42k-row file
		   into data/zip_geo.csv keeps it across upgrades. */
		$bundled = \IPS\ROOT_PATH . '/applications/gdffl/data/zip_geo.csv';
		if ( is_readable( $bundled ) ) { return $bundled; }

		/* Format-reference sample that ships in the tarball. Only
		   10 rows — enough to prove the loader wire against
		   gd_ffl.premise_zip when the admin has done neither of
		   the above yet. */
		$sample = \IPS\ROOT_PATH . '/applications/gdffl/data/zip_geo.sample.csv';
		if ( is_readable( $sample ) ) { return $sample; }

		return '';
	}

	/* ------------------------------------------------------------------
	 * INTERNAL — look up a single ZIP centroid; returns
	 * [ zip => [ lat, lng ] ] or [] when the ZIP is unknown so the
	 * FFL row still imports (rule: don't drop the FFL if the ZIP
	 * lookup misses; store lat/lng as NULL).
	 * ------------------------------------------------------------------ */
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

	/* ------------------------------------------------------------------
	 * INTERNAL — JSON writers. \IPS\Output::i()->json() emits the
	 * right Content-Type and short-circuits template rendering.
	 * ------------------------------------------------------------------ */
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
