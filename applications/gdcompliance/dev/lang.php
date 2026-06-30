<?php

$lang = array(
	/* App + tabs */
	'__app_gdcompliance'                       => 'Compliance',
	'menutab__gdcompliance'                    => 'Compliance',
	'menutab__gdcompliance_icon'               => 'shield-alt',

	/* ACP menu */
	'menu__gdcompliance_compliance_rules'      => 'Rules',
	'menu__gdcompliance_compliance_compute'    => 'Compute Flags',

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

	/* Front page */
	'gdcompliance_page_title'                  => 'State Compliance Rules',
	'gdcompliance_page_intro'                  => 'Active state magazine-capacity restrictions applied to the GunRack catalog.',
	'gdcompliance_page_empty'                  => 'No active rules.',
);
