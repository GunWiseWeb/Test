<?php

$lang = array(
	/* App + tabs */
	'__app_gdcompliance'                       => 'Compliance',
	'menutab__gdcompliance'                    => 'Compliance',
	'menutab__gdcompliance_icon'               => 'shield-alt',

	/* ACP menu */
	'menu__gdcompliance_compliance_rules'      => 'Rules',
	'menu__gdcompliance_compliance_compute'    => 'Compute Flags',
	'menu__gdcompliance_compliance_roster'     => 'Rosters',
	'menu__gdcompliance_compliance_review'     => 'Review Queue',
	'menu__gdcompliance_compliance_overrides'  => 'Manual Overrides',
	'menu__gdcompliance_compliance_lookup'     => 'Product Lookup',
	'menu__gdcompliance_compliance_browser'    => 'Restrictions Browser',
	'menu__gdcompliance_compliance_picamodels' => 'PICA Models (IL)',
	'menu__gdcompliance_compliance_settings'   => 'Settings',

	/* Front module + nav */
	'module__gdcompliance_compliance'          => 'Compliance',
	'frontnavigation_gdcompliance_compliance'  => 'Compliance',

	/* Page titles */
	'gdcompliance_acp_rules_title'             => 'Compliance Rules',
	'gdcompliance_acp_rules_intro'             => 'Edit the state magazine-capacity rules used to flag products. Disabling a rule (or letting expires_date pass) stops it from flagging without deleting the row. A rule applies only when enabled AND inside its effective_date/expires_date window. Capacity strictly greater than max_capacity flags the product.',
	'gdcompliance_acp_rules_add'               => 'Add rule',
	'gdcompliance_acp_rules_reseed'            => 'Reseed missing rules',
	'gdcompliance_acp_rules_reseed_done'      => 'Reseed complete',
	'acplog__gdcompliance_rules_reseeded'      => 'Reseeded canonical compliance rules (non-destructive)',
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
	'gdcompliance_acp_roster_refresh_md'       => 'Refresh MD Approved Roster',
	'gdcompliance_acp_roster_refresh_md_dis'   => 'Refresh MD Disapproved List',
	'gdcompliance_acp_roster_import_md'        => 'Import MD CSV',
	'gdcompliance_acp_roster_md_help'          => 'Optional manual export from the MSP Tableau portal. Columns: manufacturer, model (literal "ALL" / "*" / blank → blanket-approved for the whole manufacturer), caliber (optional), list_type (approved | disapproved; default approved). Importing supersedes the PDF-sourced rows for whichever list_type the CSV contains, so you can override either the approved or disapproved list.',
	'gdcompliance_acp_roster_tab_all'          => 'All states',
	'gdcompliance_acp_roster_tab_ca'           => 'CA',
	'gdcompliance_acp_roster_tab_ma'           => 'MA',
	'gdcompliance_acp_roster_tab_md'           => 'MD',
	'gdcompliance_acp_roster_done'             => 'CA roster refreshed: {1} rows ({2} current, {3} expired) across {4} pages.',
	'gdcompliance_acp_roster_ma_done'          => 'MA roster refreshed: {1} rows (extractor: {2}, {3} errors).',
	'gdcompliance_acp_roster_md_pdf_done'      => 'MD approved PDF: {1} rows (as of {2}, {3} split multi-caliber, {4} blanket-caliber, {5} errors).',
	'gdcompliance_acp_roster_md_dis_done'      => 'MD disapproved PDF: {1} rows (as of {2}, {3} errors).',
	'gdcompliance_acp_roster_md_done'          => 'MD CSV imported: {1} rows ({2} blanket, {3} errors).',

	/* Roster column headers (Table\Db langPrefix) */
	'gdcompliance_acp_roster_col_roster_state'    => 'State',
	'gdcompliance_acp_roster_col_list_type'       => 'List',
	'gdcompliance_acp_roster_col_manufacturer'    => 'Manufacturer',
	'gdcompliance_acp_roster_col_model_raw'       => 'Model',
	'gdcompliance_acp_roster_col_caliber'         => 'Caliber',
	'gdcompliance_acp_roster_col_blanket'         => 'All Models',
	'gdcompliance_acp_roster_col_blanket_caliber' => 'Any Caliber',
	'gdcompliance_acp_roster_col_gun_type'        => 'Type',
	'gdcompliance_acp_roster_col_barrel'          => 'Barrel',
	'gdcompliance_acp_roster_col_expired_date'    => 'Expired',
	'gdcompliance_acp_roster_col_date_approved'   => 'Approved',
	'gdcompliance_acp_roster_col_source_label'    => 'Source',
	'gdcompliance_acp_roster_col_as_of_date'      => 'As Of',
	'gdcompliance_acp_roster_col_is_current'      => 'Status',

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

	/* Settings labels (audit — referenced but not yet in a Form; seeded
	   preemptively so any future settings ACP form renders proper labels). */
	'gdcompliance_settings_general'            => 'General',
	'gdcompliance_settings_urls'               => 'Roster URLs',
	'gdcompliance_autosync_enabled'            => 'Enable auto-sync',
	'gdcompliance_ma_roster_url'               => 'MA roster PDF URL',
	'gdcompliance_md_roster_url'               => 'MD MSP approved roster PDF URL',
	'gdcompliance_md_disapproved_url'          => 'MD MSP disapproved list PDF URL',
	'gdcompliance_dc_derive'                   => 'DC status derived from CA+MA+MD',

	/* Phase 4 — Overrides */
	'gdcompliance_acp_overrides_title'         => 'Manual Overrides',
	'gdcompliance_acp_overrides_intro'         => 'Per-UPC per-state force restrict / force clear decisions that survive every recompute. Applied AFTER the rule pass on every run, so a rule miss (e.g. an IL-restricted gun the capacity rule doesn\'t catch) or a false positive can be fixed permanently. Deleting an override reverts that UPC+state to pure rule result on the next compute.',
	'gdcompliance_acp_overrides_count'         => 'Total overrides',
	'gdcompliance_acp_overrides_add'           => 'Add override',
	'gdcompliance_acp_overrides_edit'          => 'Edit override',
	'gdcompliance_acp_overrides_save_error'    => 'Could not save override — check upc / state / action.',

	/* Overrides Table\Db column headers */
	'gdcompliance_acp_overrides_col_upc'        => 'UPC',
	'gdcompliance_acp_overrides_col_state_code' => 'State',
	'gdcompliance_acp_overrides_col_action'     => 'Action',
	'gdcompliance_acp_overrides_col_reason'     => 'Reason',
	'gdcompliance_acp_overrides_col_created_by' => 'By',
	'gdcompliance_acp_overrides_col_created_at' => 'When',

	/* Override action labels */
	'gdcompliance_action_force_restrict'       => 'Force restrict (add flag)',
	'gdcompliance_action_force_clear'          => 'Force clear (remove flag)',
	'gdcompliance_f_upc'                       => 'Product UPC',
	'gdcompliance_f_action'                    => 'Override action',
	'gdcompliance_f_reason'                    => 'Reason (shown on the flag)',

	/* Phase 4 — Product lookup */
	'gdcompliance_acp_lookup_title'            => 'Product Lookup',
	'gdcompliance_acp_lookup_intro'            => 'Find a catalog product by UPC or title. See computed flags AND overrides per state, and set force-restrict / force-clear overrides directly from the detail view.',
	'gdcompliance_acp_lookup_search'           => 'UPC or title',
	'gdcompliance_acp_search_go'               => 'Search',
	'gdcompliance_acp_lookup_no_flags'         => 'No restrictions computed for this UPC',

	/* v1.4.1 — Restrictions Browser */
	'gdcompliance_acp_browser_title'           => 'Restrictions Browser',
	'gdcompliance_acp_browser_intro'           => 'Search / filter every product currently flagged in gd_compliance_flags. Filter by state, reason type (capacity / roster / manual override), or free-text (UPC or product title). This is the "what products are actually flagged right now" view.',
	'gdcompliance_acp_browser_q'               => 'UPC or product title',
	'gdcompliance_acp_browser_state'           => 'State',
	'gdcompliance_acp_browser_type'            => 'Type',
	'gdcompliance_acp_browser_showing'         => 'Showing',
	'gdcompliance_acp_browser_empty'           => 'No flagged products match those filters.',

	/* v1.5.2 — Illinois PICA (720 ILCS 5/24-1.9) */
	'gdcompliance_acp_pica_title'              => 'Illinois PICA Named Models',
	'gdcompliance_acp_pica_intro'              => 'Statutory named-model list from 720 ILCS 5/24-1.9(a)(1)(J). Rifles whose title/brand/model/description match ANY enabled pattern (aggressive normalization: strip all non-alphanumeric, lowercase) get a Tier-1 PICA flag with high confidence. Semi-auto rifles that match nothing here still get a Tier-2 "likely PICA — verify" flag for the review queue.',
	'gdcompliance_acp_pica_add'                => 'Add pattern',
	'gdcompliance_acp_pica_reseed'             => 'Reseed statutory list',
	'gdcompliance_acp_pica_reseed_done'        => 'PICA model reseed complete',
	'acplog__gdcompliance_pica_reseeded'       => 'Reseeded PICA statutory model list (non-destructive)',
	'gdcompliance_acp_pica_col_pattern'        => 'Pattern',
	'gdcompliance_acp_pica_col_pattern_norm'   => 'Normalized',
	'gdcompliance_acp_pica_col_platform_group' => 'Platform',
	'gdcompliance_acp_pica_col_citation'       => 'Citation',
	'gdcompliance_acp_pica_col_enabled'        => 'Enabled',
	'gdcompliance_pica_f_pattern'              => 'Pattern (as it appears in catalog titles)',
	'gdcompliance_pica_f_platform_group'       => 'Platform group (e.g. AR-15, AK, SCAR)',
	'gdcompliance_pica_f_citation'             => 'Statutory citation',
	'gdcompliance_pica_f_enabled'              => 'Enabled',
	'gdcompliance_reason_pica'                 => 'PICA (IL)',

	/* Compute overrides summary strip */
	'gdcompliance_acp_compute_overrides'       => 'Overrides applied',

	/* Front page */
	'gdcompliance_page_title'                  => 'State Compliance Rules',
	'gdcompliance_page_intro'                  => 'Active state magazine-capacity restrictions applied to the GunRack catalog.',
	'gdcompliance_page_empty'                  => 'No active rules.',

	/* Phase 5 — Frontend restriction notice (storefront product page) */
	'gdcompliance_front_heading'               => 'Sales Restrictions',
	'gdcompliance_front_intro'                 => 'This item may not be available for sale or shipment into:',
	'gdcompliance_front_disclaimer'            => 'Restrictions are provided as guidance and may not reflect the most current law; verify before purchase.',
	'gdcompliance_front_state_prefix'          => 'Restricted in',
	'gdcompliance_restricted_badge'            => 'Restricted in %d states',
	'gdcompliance_reason_capacity'             => 'Capacity',
	'gdcompliance_reason_roster'               => 'Roster',
	'gdcompliance_reason_override'             => 'Manual',

	/* Phase 5 — ACP settings page */
	'gdcompliance_acp_settings_title'          => 'Frontend Restriction Display',
	'gdcompliance_front_enabled'               => 'Show restriction panel on storefront',
	'gdcompliance_front_enabled_desc'          => 'Master toggle. When off, no "Sales Restrictions" panels render on product pages or grids.',
	'gdcompliance_front_show_reasons'          => 'Show per-state reason on each row',
	'gdcompliance_front_show_reasons_desc'     => 'When off, the panel lists only the state codes; when on, each state shows its detected reason (capacity vs roster vs manual).',
	'gdcompliance_front_disclaimer_desc'       => 'Displayed at the bottom of every restriction panel. FFL guidance framing recommended.',
	'acplog__gdcompliance_settings_saved'      => 'Saved frontend restriction display settings',

	/* Phase 5 — Widget */
	'block_gdRestrictionNotice'                => 'Product Restriction Notice',
	'gdcompliance_widget_upc_fallback'         => 'Fallback UPC (only used if no product UPC in URL)',
);
