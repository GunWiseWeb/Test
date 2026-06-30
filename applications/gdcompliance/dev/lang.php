<?php

$lang = array(
	/* App + tabs */
	'__app_gdcompliance'                       => 'Compliance',
	'menutab__gdcompliance'                    => 'Compliance',
	'menutab__gdcompliance_icon'               => 'shield-alt',

	/* ACP menu */
	'menu__gdcompliance_compliance_rules'      => 'Rules',
	'menu__gdcompliance_compliance_compute'    => 'Compute Flags',
	'menu__gdcompliance_compliance_roster'     => 'CA Roster',
	'menu__gdcompliance_compliance_review'     => 'Review Queue',

	/* Front module + nav */
	'module__gdcompliance_compliance'          => 'Compliance',
	'frontnavigation_gdcompliance_compliance'  => 'Compliance',

	/* Page titles */
	'gdcompliance_acp_rules_title'             => 'Compliance Rules',
	'gdcompliance_acp_rules_intro'             => 'Edit the state magazine-capacity rules used to flag products. Disabling a rule (or letting expires_date pass) stops it from flagging without deleting the row. A rule applies only when enabled AND inside its effective_date/expires_date window. Capacity strictly greater than max_capacity flags the product.',
	'gdcompliance_acp_rules_add'               => 'Add rule',
	'gdcompliance_acp_compute_title'           => 'Compute Compliance Flags',
	'gdcompliance_acp_compute_intro'           => 'Recomputes gd_compliance_flags from gd_catalog × the active rule set. gd_catalog is never modified. Always preview first.',
	'gdcompliance_acp_compute_preview'         => 'Preview (dry run)',
	'gdcompliance_acp_compute_run'             => 'Run / recompute flags',
	'gdcompliance_acp_compute_clear'           => 'Clear all flags',
	'gdcompliance_acp_compute_last'            => 'Last run',
	'gdcompliance_acp_compute_never'           => 'Never',

	/* Compute result panels */
	'gdcompliance_acp_compute_preview_done'    => 'Dry run: would flag {1} products across {2} state-rule pairs.',
	'gdcompliance_acp_compute_run_done'        => 'Computed: flagged {1} products across {2} state-rule pairs.',
	'gdcompliance_acp_compute_cleared'         => 'Cleared {1} flags + {2} unparsed-capacity rows.',
	'gdcompliance_acp_compute_no_rules'        => 'No active rules — enable at least one rule in the Rules tab before computing.',
	'gdcompliance_acp_compute_per_state'       => 'Flags by state',
	'gdcompliance_acp_compute_unparsed'        => 'Unparsed capacity values',
	'gdcompliance_acp_compute_sample'          => 'Sample flagged products',

	/* Rules table column headers (Table\Db langPrefix) */
	'gdcompliance_acp_col_state_code'          => 'State',
	'gdcompliance_acp_col_firearm_type'        => 'Firearm Type',
	'gdcompliance_acp_col_max_capacity'        => 'Limit',
	'gdcompliance_acp_col_rule_type'           => 'Rule Type',
	'gdcompliance_acp_col_effective_date'      => 'Effective',
	'gdcompliance_acp_col_expires_date'        => 'Expires',
	'gdcompliance_acp_col_enabled'             => 'Enabled',
	'gdcompliance_acp_col_source_note'         => 'Source',

	/* Rule form fields */
	'gdcompliance_f_state_code'                => 'State (2-letter)',
	'gdcompliance_f_firearm_type'              => 'Firearm type',
	'gdcompliance_f_max_capacity'              => 'Max capacity (limit)',
	'gdcompliance_f_rule_type'                 => 'Rule type',
	'gdcompliance_f_effective_date'            => 'Effective date (YYYY-MM-DD; blank = always)',
	'gdcompliance_f_expires_date'              => 'Expires date (YYYY-MM-DD; blank = never)',
	'gdcompliance_f_enabled'                   => 'Enabled',
	'gdcompliance_f_source_note'               => 'Source note (citation)',

	/* CA roster (Phase 2) */
	'gdcompliance_acp_roster_title'            => 'CA DOJ Certified Handgun Roster',
	'gdcompliance_acp_roster_intro'            => 'Snapshot of the California Department of Justice Certified Handgun roster. Used by the compute pass to classify catalog handguns into on-roster (CA-legal), off-roster (flagged), or unmatched (review queue). Manual refresh only — never auto-scheduled.',
	'gdcompliance_acp_roster_source'           => 'Source',
	'gdcompliance_acp_roster_last'             => 'Last refreshed',
	'gdcompliance_acp_roster_never'            => 'Never',
	'gdcompliance_acp_roster_total'            => 'Total rows',
	'gdcompliance_acp_roster_current'          => 'Current',
	'gdcompliance_acp_roster_expired'          => 'Expired',
	'gdcompliance_acp_roster_refresh'          => 'Refresh CA Roster',
	'gdcompliance_acp_roster_refresh_ma'       => 'Refresh MA Roster',
	'gdcompliance_acp_roster_import_md'        => 'Import MD CSV',
	'gdcompliance_acp_roster_md_help'          => 'MD\'s live roster is in a Tableau dashboard (not fetchable) and uses "All models approved" blanket entries. Export from the MD portal, save as CSV with columns: manufacturer, model (literal "ALL" / "*" / blank → blanket-approved for the whole manufacturer), caliber (optional).',
	'gdcompliance_acp_roster_tab_all'          => 'All states',
	'gdcompliance_acp_roster_tab_ca'           => 'CA',
	'gdcompliance_acp_roster_tab_ma'           => 'MA',
	'gdcompliance_acp_roster_tab_md'           => 'MD',
	'gdcompliance_acp_roster_done'             => 'CA roster refreshed: {1} rows ({2} current, {3} expired) across {4} pages.',
	'gdcompliance_acp_roster_ma_done'          => 'MA roster refreshed: {1} rows (extractor: {2}, {3} errors).',
	'gdcompliance_acp_roster_md_done'          => 'MD roster imported: {1} rows ({2} blanket, {3} errors).',

	/* Roster column headers (Table\Db langPrefix) */
	'gdcompliance_acp_roster_col_roster_state'  => 'State',
	'gdcompliance_acp_roster_col_manufacturer'  => 'Manufacturer',
	'gdcompliance_acp_roster_col_model_raw'     => 'Model',
	'gdcompliance_acp_roster_col_caliber'       => 'Caliber',
	'gdcompliance_acp_roster_col_blanket'       => 'Blanket',
	'gdcompliance_acp_roster_col_gun_type'      => 'Type',
	'gdcompliance_acp_roster_col_barrel'        => 'Barrel',
	'gdcompliance_acp_roster_col_expired_date'  => 'Expired',
	'gdcompliance_acp_roster_col_date_approved' => 'Approved',
	'gdcompliance_acp_roster_col_is_current'    => 'Status',

	/* Review queue (Phase 2) */
	'gdcompliance_acp_review_title'            => 'CA Roster Review Queue',
	'gdcompliance_acp_review_intro'            => 'Handguns the matcher could not confidently place on or off the roster. Each row shows the near-miss roster candidates the matcher considered. Resolve each manually — "Mark on-roster" clears the CA restriction for that UPC, "Mark off-roster" sets the CA flag. Decisions persist across re-computes (only unresolved rows are wiped + re-populated on each compute).',
	'gdcompliance_acp_review_pending'          => 'Pending',
	'gdcompliance_acp_review_resolved'         => 'Resolved',
	'gdcompliance_acp_review_mark_on'          => 'Mark on-roster (CA-legal)',
	'gdcompliance_acp_review_mark_off'         => 'Mark off-roster (restrict)',
	'gdcompliance_acp_review_reopen'           => 'Re-open for review',
	'gdcompliance_acp_review_candidates'       => 'Near-miss roster candidates',
	'gdcompliance_acp_review_already_resolved' => 'Already resolved',

	/* Review column headers (Table\Db langPrefix) */
	'gdcompliance_acp_review_col_roster_state'     => 'State',
	'gdcompliance_acp_review_col_upc'              => 'UPC',
	'gdcompliance_acp_review_col_manufacturer'     => 'Manufacturer',
	'gdcompliance_acp_review_col_model_title'      => 'Model / Title',
	'gdcompliance_acp_review_col_caliber'          => 'Caliber',
	'gdcompliance_acp_review_col_suggested_status' => 'Suggested',
	'gdcompliance_acp_review_col_resolved_status'  => 'Resolved as',

	/* Front page */
	'gdcompliance_page_title'                  => 'State Compliance Rules',
	'gdcompliance_page_intro'                  => 'Active state magazine-capacity restrictions applied to the GunRack catalog.',
	'gdcompliance_page_empty'                  => 'No active rules.',
);
