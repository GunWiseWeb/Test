<?php

$lang = array(
	/* App + tabs */
	'__app_gdbills'                     => 'Firearms Bill Tracker',
	'menutab__gdbills'                  => 'Bills',
	'menutab__gdbills_icon'             => 'gavel',

	/* ACP menu items */
	'menu__gdbills_bills_bills'         => 'Bills',
	'menu__gdbills_bills_settings'      => 'Settings',
	'menu__gdbills_bills_import'        => 'CSV Import',
	'menu__gdbills_bills_sync'          => 'LegiScan Sync',

	/* Front module + FrontNavigation */
	'module__gdbills_bills'             => 'Bill Tracker',
	'frontnavigation_gdbills_bills'     => 'Bill Tracker',

	/* Page */
	'gdbills_page_title'                => 'Firearms Bill Tracker',
	'gdbills_page_subtitle'             => 'Pending and enacted firearms legislation by state',
	'gdbills_state_map_title'           => 'Browse by state',
	'gdbills_state_map_hint'            => 'Tap a state to view the bills currently active there.',
	'gdbills_law_heading'               => 'Existing Laws',
	'gdbills_enacted_heading'           => 'Recently Enacted',
	'gdbills_pending_heading'           => 'Pending Bills',
	'gdbills_no_bills'                  => 'No bills tracked for this state right now.',
	'gdbills_no_bills_overall'          => 'No bills tracked yet. Configure your LegiScan API key in the ACP and run Sync to populate.',
	'gdbills_modal_close'               => 'Close',
	'gdbills_view_full_text'            => 'View full text',
	'gdbills_last_action'               => 'Last action',
	'gdbills_sponsor'                   => 'Sponsor',
	'gdbills_introduced'                => 'Introduced',
	'gdbills_signed'                    => 'Signed',
	'gdbills_passed_house'              => 'Passed House',
	'gdbills_passed_senate'             => 'Passed Senate',

	/* Badges */
	'gdbills_type_pending'              => 'Pending',
	'gdbills_type_enacted'              => 'Enacted',
	'gdbills_type_law'                  => 'Law',

	/* Progress tracker (Introduced → House → Senate → Governor → Law) */
	'gdbills_stage_introduced'          => 'Introduced',
	'gdbills_stage_house'               => 'House',
	'gdbills_stage_senate'              => 'Senate',
	'gdbills_stage_governor'            => 'Governor',
	'gdbills_stage_law'                 => 'Law',
	'gdbills_failed'                    => 'Failed',
	'gdbills_vetoed'                    => 'Vetoed',

	/* Filters */
	'gdbills_filter_state'              => 'State',
	'gdbills_filter_type'               => 'Type',
	'gdbills_filter_all_states'         => 'All states',
	'gdbills_filter_all_types'          => 'All types',
	'gdbills_filter_apply'              => 'Apply',
	'gdbills_filter_clear'              => 'Clear',
	'gdbills_filter_all'                => 'All',
	'gdbills_filter_law'                => 'Existing Laws',
	'gdbills_filter_enacted'            => 'Recently Enacted',
	'gdbills_filter_pending'            => 'Pending',
	'gdbills_filter_date'               => 'Date',
	'gdbills_filter_from'               => 'From',
	'gdbills_filter_to'                 => 'To',
	'gdbills_last_updated'              => 'Last Updated',
	'gdbills_showing_count'             => 'Showing %d items',
	'gdbills_back_all_states'           => 'All states',

	/* ACP — Bills list */
	'gdbills_acp_bills_title'           => 'Tracked Bills',
	'gdbills_acp_bills_intro'           => 'Add, edit, or remove tracked legislation. Bills can also be sourced from LegiScan or CSV.',
	'gdbills_acp_bills_add'             => 'Add bill',
	'gdbills_acp_bills_edit'            => 'Edit bill',
	'gdbills_acp_bills_delete'          => 'Delete bill',
	'gdbills_acp_bills_count'           => 'Total tracked',
	'gdbills_acp_bills_none'            => 'No bills match the current filters.',
	'gdbills_acp_search'                => 'Search bills',
	'gdbills_acp_search_state'          => 'State',
	'gdbills_acp_search_type'           => 'Type',
	'gdbills_acp_search_q'              => 'Title or bill number',
	'gdbills_acp_search_go'             => 'Search',
	'gdbills_acp_search_reset'          => 'Reset',

	/* Native ACP Table\Db column headers — keys = langPrefix + column name */
	'gdbills_acp_col_state_code'        => 'State',
	'gdbills_acp_col_bill_number'       => 'Bill #',
	'gdbills_acp_col_bill_title'        => 'Title',
	'gdbills_acp_col_bill_type'         => 'Type',
	'gdbills_acp_col_status'            => 'Status',
	'gdbills_acp_col_last_action_date'  => 'Last action',
	'gdbills_acp_col_source'            => 'Source',

	/* ACP — Bill form fields */
	'gdbills_f_bill_number'             => 'Bill number',
	'gdbills_f_bill_title'              => 'Bill title',
	'gdbills_f_state_code'              => 'State (2-letter)',
	'gdbills_f_bill_type'               => 'Bill type',
	'gdbills_f_status'                  => 'Status',
	'gdbills_f_progress_stage'          => 'Progress stage',
	'gdbills_f_sponsor_name'            => 'Sponsor name',
	'gdbills_f_sponsor_party'           => 'Sponsor party',
	'gdbills_f_description'             => 'Description',
	'gdbills_f_url'                     => 'Full-text URL',
	'gdbills_f_date_introduced'         => 'Date introduced',
	'gdbills_f_last_action_date'        => 'Last action date',
	'gdbills_f_last_action'             => 'Last action',
	'gdbills_f_passed_senate_date'      => 'Passed Senate date',
	'gdbills_f_passed_house_date'       => 'Passed House date',
	'gdbills_f_signed_date'             => 'Signed date',
	'gdbills_f_legiscan_id'             => 'LegiScan bill id',
	'gdbills_f_source'                  => 'Source',

	/* ACP — Settings */
	'gdbills_acp_settings_title'        => 'Settings',
	'gdbills_settings_general'          => 'General',
	'gdbills_settings_legiscan'         => 'LegiScan API',
	'gdbills_settings_keywords'         => 'Search Keywords',
	'gdbills_legiscan_key'              => 'LegiScan API key',
	'gdbills_autosync_enabled'          => 'Enable daily auto-sync',
	'gdbills_relevance_threshold'       => 'LegiScan relevance threshold (0-100)',
	'gdbills_relevance_threshold_desc'  => 'Sync skips any LegiScan search hit whose relevance score is below this number. 50 is a balanced default; raise (60-70) if results are still noisy, lower if real firearms bills are being dropped.',
	'gdbills_search_keywords'           => 'Search keywords (one per line)',
	'gdbills_relevance_keywords'        => 'Relevance allowlist (one per line)',
	'gdbills_exclusion_keywords'        => 'Exclusion list (one per line)',
	'gdbills_session_note'              => 'Session note shown to visitors',

	/* ACP — Import */
	'gdbills_acp_import_title'          => 'CSV Import',
	'gdbills_acp_import_intro'          => 'Upload a CSV with columns: bill_number, bill_title, state_code, bill_type, status, sponsor_name, description, url, date_introduced, last_action_date, last_action.',
	'gdbills_acp_import_file'           => 'CSV file',
	'gdbills_acp_import_run'            => 'Import',
	'gdbills_acp_import_summary'        => 'Imported {1} bills ({2} new, {3} updated, {4} errors).',

	/* ACP — Sync */
	'gdbills_acp_sync_title'            => 'LegiScan Sync',
	'gdbills_acp_sync_intro'            => 'Manually trigger a sync against the LegiScan API. The daily task will also run automatically.',
	'gdbills_acp_sync_button'           => 'Sync now',
	'gdbills_acp_sync_last'             => 'Last sync',
	'gdbills_acp_sync_never'            => 'Never',
	'gdbills_acp_sync_done'             => 'Sync complete: {1} bills processed.',
	'gdbills_acp_sync_no_key'           => 'Set your LegiScan API key in Settings before syncing.',
	'gdbills_acp_sync_disabled'         => 'Auto-sync is disabled. Manual sync still works.',

	/* Existing-laws seed + prior-session detection (ACP) */
	'gdbills_acp_seed_title'            => 'Seed Existing Laws',
	'gdbills_acp_seed_intro'            => 'Imports the bundled list of well-known existing state firearms laws (Illinois Protect Illinois Communities Act, NY SAFE Act, etc.) as bill_type=law. No API calls. Safe to re-run; existing rows update in place.',
	'gdbills_acp_seed_button'           => 'Seed existing laws',
	'gdbills_acp_seed_done'             => 'Existing laws seeded: {1} upserted of {2} processed.',
	'gdbills_acp_detect_title'          => 'Detect Prior-Session Laws',
	'gdbills_acp_detect_intro'          => 'Queries LegiScan for enacted firearms bills (status=Passed) from prior years (2021-2024) and tags them as existing laws. Admin-triggered only; not part of the daily sync.',
	'gdbills_acp_detect_warning'        => 'This uses significant LegiScan API quota. Limit by state to control usage.',
	'gdbills_acp_detect_state_label'    => 'State',
	'gdbills_acp_detect_all_states'     => 'All 50 states (heavy quota)',
	'gdbills_acp_detect_button'         => 'Detect prior-session laws',
	'gdbills_acp_detect_done'           => 'Prior-session detection complete: {1} tagged as existing law of {2} processed.',

	/* Task */
	'task__gdbills_syncBills'           => 'Sync firearms bills from LegiScan',

	/* Widgets */
	'block__gdbills_billMap'            => 'Bill Tracker Map',
	'block__gdbills_billList'           => 'Bill List',
	'gdbills_w_state'                   => 'State filter (blank = all)',
	'gdbills_w_type'                    => 'Type filter (blank = all)',
	'gdbills_w_limit'                   => 'Rows to show',
);
