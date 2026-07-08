<?php
$lang = [
	'__app_gdffl'                => 'Gun Rack FFL Finder',
	'menutab__gdffl'             => 'FFL Finder',
	'menutab__gdffl_icon'        => 'location-dot',

	'module__admin_manage'       => 'FFL Finder',
	'menu__gdffl_manage_settings' => 'Settings',
	'menu__gdffl_manage_import'  => 'Import ATF CSV',
	'r__ffl_manage'              => 'Manage FFL Finder',

	'gdffl_default_radius'        => 'Default search radius (miles)',
	'gdffl_default_radius_desc'   => 'Miles from the buyer\'s ZIP centroid used by the Stage 2 lookup.',
	'gdffl_default_types'         => 'Default license types shown',
	'gdffl_default_types_desc'    => 'Comma-separated ATF LIC_TYPE codes counted as transfer-capable for the default view (e.g. 01,02).',
	'gdffl_delimiter'             => 'CSV delimiter',
	'gdffl_delimiter_desc'        => 'ATF\'s published file is TAB-delimited; leave on auto to detect.',
	'gdffl_import_mode'           => 'Import mode',
	'gdffl_import_mode_desc'      => 'Replace: TRUNCATE gd_ffl before import (correct for the monthly full file). Merge: upsert by lic_number.',
	'gdffl_per_page'              => 'Results per page',

	'gdffl_acp_settings_title'    => 'FFL Finder — Settings',
	'gdffl_acp_import_title'      => 'FFL Finder — Import ATF CSV',
	'gdffl_acp_import_intro'      => 'Upload the ATF full-FFL CSV (TAB-delimited). The import runs chunked in the background queue so 77k rows never run in one web request. Progress is visible in the ACP queue after upload.',
	'gdffl_acp_import_upload'     => 'ATF FFL CSV file',
	'gdffl_acp_import_submit'     => 'Queue import',
	'gdffl_acp_import_queued'     => 'Import queued — %s rows to process. Track progress in the ACP queue.',
	'gdffl_acp_zipgeo_title'      => 'ZIP centroid data',
	'gdffl_acp_zipgeo_load'       => 'Load bundled ZIP centroid CSV',
	'gdffl_acp_zipgeo_intro'      => 'Loads the bundled US Census ZCTA public-domain ZIP→lat/lng centroid CSV into gd_zip_geo. Chunked; safe to re-run.',
	'gdffl_acp_zipgeo_queued'     => 'ZIP centroid load queued — %s rows to process.',
	'gdffl_acp_last_import'       => 'Last ATF import: %s rows imported, %s skipped, %s',

	'gdffl_queue_ffl'             => 'Importing ATF FFL rows: %s of %s',
	'gdffl_queue_zipgeo'          => 'Loading ZIP centroids: %s of %s',
	'gdffl_err_no_upload'         => 'Please choose a CSV file to upload.',
	'gdffl_err_bad_file'          => 'Could not read the uploaded file.',
];
