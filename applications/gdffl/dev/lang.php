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

	/* v1.0.1 — public finder page + JSON endpoint. */
	'module__front_finder'        => 'FFL Finder',
	'gdffl_finder_title'          => 'Find an FFL near you',
	'gdffl_finder_lead'           => 'Enter your ZIP code to find licensed dealers who can receive a transfer for you. Distance is calculated from the ZIP centroid.',
	'gdffl_finder_zip'            => 'Your ZIP code',
	'gdffl_finder_radius'         => 'Within',
	'gdffl_finder_submit'         => 'Find FFLs',
	'gdffl_finder_types'          => 'License types',
	'gdffl_finder_all_types'      => 'Show all license types',
	'gdffl_finder_searching'      => 'Searching…',
	'gdffl_finder_no_results'     => 'No FFLs found within the selected radius. Try a wider search.',
	'gdffl_finder_zip_bad'        => 'Please enter a 5-digit ZIP code.',
	'gdffl_finder_zip_notfound'   => 'That ZIP code is not in our lookup. Try a nearby ZIP.',
	'gdffl_finder_error'          => 'Search failed — please try again in a moment.',
	'gdffl_finder_distance'       => 'mi',
	'gdffl_finder_no_phone'       => 'No phone on file',
	'gdffl_finder_load_more'      => 'Show more results',

	/* v1.0.2 — AJAX-driven ACP importer + ZIP-file admin upload. */
	'gdffl_acp_zipgeo_upload'         => 'Upload real Census ZCTA CSV',
	'gdffl_acp_zipgeo_upload_submit'  => 'Upload ZIP centroid file',
	'gdffl_acp_zipgeo_load_hint'      => 'Loads whatever CSV is currently on disk (uploaded copy preferred, then bundled placeholder).',
	'gdffl_err_no_zip_file'           => 'No ZIP centroid file is on disk yet. Upload a real Census ZCTA CSV or drop one into applications/gdffl/data/zip_geo.csv first.',
	'gdffl_import_running_ffl'        => 'ATF FFL import running…',
	'gdffl_import_running_zip'        => 'ZIP centroid import running…',

	/* v1.0.4 — labels for the two \IPS\Helpers\Form\Upload fields. */
	'gdffl_acp_import_file'           => 'ATF FFL CSV file',
	'gdffl_acp_zipgeo_file'           => 'Census ZCTA CSV file',
];
