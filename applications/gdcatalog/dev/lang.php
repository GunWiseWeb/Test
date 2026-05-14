<?php
/**
 * @brief       GD Master Catalog — Language Strings
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 */

$lang = array(

	/* Application */
	'__app_gdcatalog'                          => "GD Master Catalog",

	/* ACP Menu */
	'module__gdcatalog_catalog'                => "Master Catalog",
	'menu__gdcatalog_catalog_dashboard'        => "Dashboard",
	'menu__gdcatalog_catalog_products'         => "Products",
	'menu__gdcatalog_catalog_feeds'            => "Feed Configuration",
	'menu__gdcatalog_catalog_conflicts'        => "Conflict Log",
	'menu__gdcatalog_catalog_compliance'       => "Compliance Review",
	'menu__gdcatalog_catalog_locks'            => "Locked Fields",
	'menu__gdcatalog_catalog_settings'         => "Settings",

	/* Dashboard */
	'gdcatalog_dash_title'                     => "Master Catalog Dashboard",
	'gdcatalog_dash_total_products'            => "Total Products",
	'gdcatalog_dash_by_category'               => "Products by Category",
	'gdcatalog_dash_by_distributor'            => "Products by Distributor",
	'gdcatalog_dash_last_run'                  => "Last Import Run",
	'gdcatalog_dash_run_status'                => "Run Status",
	'gdcatalog_dash_records_created'           => "Records Created",
	'gdcatalog_dash_records_updated'           => "Records Updated",
	'gdcatalog_dash_records_errored'           => "Records Errored",
	'gdcatalog_dash_opensearch_status'         => "OpenSearch Status",
	'gdcatalog_dash_opensearch_count'          => "Indexed Documents",
	'gdcatalog_dash_rebuild_index'             => "Rebuild Index",
	'gdcatalog_dash_trigger_import'            => "Run Import Now",

	/* Products */
	'gdcatalog_products_title'                 => "Product Browser",
	'gdcatalog_product_upc'                    => "UPC",
	'gdcatalog_product_title'                  => "Title",
	'gdcatalog_product_brand'                  => "Brand",
	'gdcatalog_product_model'                  => "Model",
	'gdcatalog_product_category'               => "Category",
	'gdcatalog_product_caliber'                => "Caliber",
	'gdcatalog_product_msrp'                   => "MSRP",
	'gdcatalog_product_status'                 => "Status",
	'gdcatalog_product_primary_source'         => "Primary Source",
	'gdcatalog_product_locked_fields'          => "Locked Fields",
	'gdcatalog_product_lock_field'             => "Lock This Field",
	'gdcatalog_product_unlock_field'           => "Unlock",
	'gdcatalog_save_manual'                    => "Save Product",
	'gdcatalog_product_lock_warning'           => "🔒 This field is locked. Distributor imports cannot overwrite it.",
	'gdcatalog_bulk_lock_label'                => "Bulk Lock",
	'gdcatalog_lock_all_fields'                => "Lock All Populated Fields",
	'gdcatalog_unlock_all_fields'              => "Unlock All Fields",
	'gdcatalog_add_product_btn'                => "+ Add Product",
	'gdcatalog_product_admin_review'           => "Admin Review Required",

	/* Feed Configuration */
	'gdcatalog_feeds_title'                    => "Distributor Feed Configuration",
	'gdcatalog_feed_name'                      => "Feed Name",
	'gdcatalog_feed_distributor'               => "Distributor",
	'gdcatalog_feed_url'                       => "Feed URL",
	'gdcatalog_feed_format'                    => "Feed Format",
	'gdcatalog_feed_auth_type'                 => "Auth Type",
	'gdcatalog_feed_auth_credentials'          => "Auth Credentials",
	'gdcatalog_feed_field_mapping'             => "Field Mapping",
	'gdcatalog_feed_category_mapping'          => "Category Mapping",
	'gdcatalog_feed_schedule'                  => "Import Schedule",
	'gdcatalog_feed_active'                    => "Active",
	'gdcatalog_feed_last_run'                  => "Last Run",
	'gdcatalog_feed_last_count'                => "Last Record Count",
	'gdcatalog_feed_last_status'               => "Last Run Status",
	'gdcatalog_feed_conflict_detection'        => "Conflict Detection Fields",

	/* Distributor Names */
	'gdcatalog_dist_rsr_group'                 => "RSR Group",
	'gdcatalog_dist_sports_south'              => "Sports South",
	'gdcatalog_dist_davidsons'                 => "Davidson's",
	'gdcatalog_dist_lipseys'                   => "Lipsey's",
	'gdcatalog_dist_zanders'                   => "Zanders Sporting Goods",
	'gdcatalog_dist_bill_hicks'                => "Bill Hicks",

	/* Conflict Log */
	'gdcatalog_conflicts_title'                => "Conflict Log",
	'gdcatalog_conflict_upc'                   => "UPC",
	'gdcatalog_conflict_field'                 => "Field",
	'gdcatalog_conflict_winner'                => "Winning Source",
	'gdcatalog_conflict_winner_val'            => "Winning Value",
	'gdcatalog_conflict_loser'                 => "Losing Source",
	'gdcatalog_conflict_loser_val'             => "Losing Value",
	'gdcatalog_conflict_rule'                  => "Rule Applied",
	'gdcatalog_conflict_date'                  => "Resolved At",

	/* Compliance Review Panel */
	'gdcatalog_compliance_title'               => "Compliance Review Panel",
	'gdcatalog_compliance_tab_new'             => "New Restrictions",
	'gdcatalog_compliance_tab_conflicts'       => "Feed Conflicts",
	'gdcatalog_compliance_tab_locks'           => "Locked Fields",
	'gdcatalog_compliance_tab_admin'           => "Admin Restrictions",
	'gdcatalog_compliance_approve'             => "Approve",
	'gdcatalog_compliance_reject'              => "Reject",
	'gdcatalog_compliance_accept_incoming'     => "Accept Incoming Value",
	'gdcatalog_compliance_keep_existing'       => "Keep Existing",
	'gdcatalog_compliance_set_custom'          => "Set Custom Value",
	'gdcatalog_compliance_auto_resolved'       => "Auto-Resolved (48h)",
	'gdcatalog_compliance_lock_reason'         => "Lock Reason (required)",

	/* Locked Fields */
	'gdcatalog_locks_title'                    => "Locked Fields Report",
	'gdcatalog_lock_type_hard'                 => "Hard Lock",
	'gdcatalog_lock_type_distributor'          => "Distributor-Specific Lock",
	'gdcatalog_lock_unlock'                    => "Unlock Field",
	'gdcatalog_lock_reason'                    => "Reason",
	'gdcatalog_lock_locked_by'                 => "Locked By",
	'gdcatalog_lock_locked_at'                 => "Locked At",

	/* Settings */
	'gdcatalog_settings_title'                 => "Plugin Settings",
	'gdcatalog_setting_opensearch_host'        => "OpenSearch Host",
	'gdcatalog_setting_opensearch_host_desc'   => "Direct connection URL for OpenSearch. Default: http://localhost:9200",
	'gdcatalog_setting_opensearch_index'       => "OpenSearch Index Name",
	'gdcatalog_setting_opensearch_index_desc'  => "Index name for the product catalog. Default: gunrack_products",
	'gdcatalog_setting_auto_resolve'           => "Auto-Resolve Hours",
	'gdcatalog_setting_auto_resolve_desc'      => "Hours before unresolved feed conflicts are automatically accepted. Default: 48",
	'gdcatalog_setting_discontinue_threshold'  => "Discontinue Threshold",
	'gdcatalog_setting_discontinue_desc'       => "Consecutive import misses before a product is marked Discontinued. Default: 3",

	/* Record Status */
	'gdcatalog_status_active'                  => "Active",
	'gdcatalog_status_discontinued'            => "Discontinued",
	'gdcatalog_status_admin_review'            => "Admin Review",
	'gdcatalog_status_pending'                 => "Pending",

	/* Conflict Rules */
	'gdcatalog_rule_priority'                  => "Standard Priority",
	'gdcatalog_rule_longest'                   => "Longest Text",
	'gdcatalog_rule_highest_res'               => "Highest Resolution",
	'gdcatalog_rule_highest_val'               => "Highest Value",
	'gdcatalog_rule_flagged_for_review'        => "Flagged for Review",
	'gdcatalog_rule_admin_override'            => "Admin Override",
	'gdcatalog_rule_any_true'                  => "Any Source = True",
	'gdcatalog_rule_merge_all'                 => "Merge All Sources",

	/* Tasks */
	'task__ImportFeeds'                        => "Import Distributor Feeds",
	'task__AutoResolveConflicts'               => "Auto-Resolve Expired Feed Conflicts",

	/* Feed config extras */
	'gdcatalog_feeds_help'                     => "Configure each distributor's feed URL, authentication, field mapping, and import schedule. Feeds are processed by the ImportFeeds background task.",
	'gdcatalog_feed_field_mapping_json'        => "Field Mapping JSON",
	'gdcatalog_feed_category_mapping_json'     => "Category Mapping JSON",
	'gdcatalog_conflict_restricted_states'     => "Conflict detect: restricted_states",
	'gdcatalog_conflict_nfa_item'              => "Conflict detect: nfa_item",
	'gdcatalog_conflict_requires_ffl'          => "Conflict detect: requires_ffl",
	'gdcatalog_conflict_caliber'               => "Conflict detect: caliber",
	'gdcatalog_conflict_rounds_per_box'        => "Conflict detect: rounds_per_box",
	'gdcatalog_conflict_category'              => "Conflict detect: category",
	'gdcatalog_conflict_manufacturer'          => "Conflict detect: manufacturer",
	'gdcatalog_conflict_description'           => "Conflict detect: description",

	/* ACP Permissions */
	'gdcatalog_feeds_manage'                   => "Can manage feed configuration",
);
