<?php
/**
 * @brief  GD Compliance — upgrade 1.6.33
 *
 * WHAT SHIPS IN 1.6.33 — Settings group-picker bugfix:
 *
 *   modules/admin/compliance/settings.php shipped in v1.6.32 with
 *   a groupOptions() helper that queried a NON-EXISTENT
 *   core_groups.g_name column. The query threw silently and left
 *   BOTH group multi-selects (CSV allowed_groups + API access_groups)
 *   with zero options — Derrick's picker was blank. v1.6.33
 *   rewrites the helper to resolve names the IPS-native way:
 *     1. \IPS\Member\Group::groups() — Group objects whose ->name
 *        property resolves the core_group_{id} lang key.
 *     2. Fallback: iterate core_groups.g_id and resolve
 *        core_group_{id} via the current member's language.
 *   Sorted natural-case ASC.
 *
 * PURE PHP FIX. No settings values changed, no schema, no lang keys.
 * Every prior migration carried forward defensively per rule #79.
 * Cache purge included so the corrected settings.php reloads on the
 * next ACP hit.
 */

namespace IPS\gdcompliance\setup\upg_10633;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		/* ------------------------------------------------------------
		 * 1) SCHEMA carry-forward.
		 * ------------------------------------------------------------ */
		try
		{
			if ( !\IPS\Db::i()->checkForTable( 'gd_compliance_reports' ) )
			{
				\IPS\Db::i()->createTable( [
					'name'    => 'gd_compliance_reports',
					'columns' => [
						[ 'name' => 'id',                      'type' => 'INT',      'length' => 10, 'unsigned' => TRUE, 'auto_increment' => TRUE, 'allow_null' => FALSE ],
						[ 'name' => 'member_id',               'type' => 'INT',      'length' => 10, 'unsigned' => TRUE, 'default' => 0,   'allow_null' => FALSE ],
						[ 'name' => 'upc',                     'type' => 'VARCHAR',  'length' => 50, 'default' => '',   'allow_null' => FALSE ],
						[ 'name' => 'state_code',              'type' => 'CHAR',     'length' => 2,  'default' => '',   'allow_null' => FALSE ],
						[ 'name' => 'reported_classification', 'type' => 'VARCHAR',  'length' => 40, 'default' => '',   'allow_null' => FALSE ],
						[ 'name' => 'note',                    'type' => 'TEXT',                    'allow_null' => TRUE ],
						[ 'name' => 'status',                  'type' => 'VARCHAR',  'length' => 20, 'default' => 'pending', 'allow_null' => FALSE ],
						[ 'name' => 'resolution_note',         'type' => 'TEXT',                    'allow_null' => TRUE ],
						[ 'name' => 'resolved_by',             'type' => 'INT',      'length' => 10, 'unsigned' => TRUE, 'allow_null' => TRUE ],
						[ 'name' => 'resolved_at',             'type' => 'INT',      'length' => 10, 'unsigned' => TRUE, 'allow_null' => TRUE ],
						[ 'name' => 'created_at',              'type' => 'INT',      'length' => 10, 'unsigned' => TRUE, 'allow_null' => TRUE ],
						[ 'name' => 'ip_address',              'type' => 'VARCHAR',  'length' => 45, 'allow_null' => TRUE ],
					],
					'indexes' => [
						[ 'type' => 'primary', 'name' => 'PRIMARY',     'columns' => [ 'id' ] ],
						[ 'type' => 'key',     'name' => 'idx_status',  'columns' => [ 'status' ] ],
						[ 'type' => 'key',     'name' => 'idx_member',  'columns' => [ 'member_id' ] ],
						[ 'type' => 'key',     'name' => 'idx_upc',     'columns' => [ 'upc' ] ],
						[ 'type' => 'key',     'name' => 'idx_created', 'columns' => [ 'created_at' ] ],
					],
				] );
			}
		}
		catch ( \Throwable $e ) { try { \IPS\Log::log( 'upg_10633 gdcr: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {} }

		try
		{
			if ( !\IPS\Db::i()->checkForTable( 'gd_compliance_api_keys' ) )
			{
				\IPS\Db::i()->createTable( [
					'name'    => 'gd_compliance_api_keys',
					'columns' => [
						[ 'name' => 'id',            'type' => 'INT',    'length' => 10, 'unsigned' => TRUE, 'auto_increment' => TRUE, 'allow_null' => FALSE ],
						[ 'name' => 'api_key',       'type' => 'VARCHAR','length' => 80, 'default' => '',    'allow_null' => FALSE ],
						[ 'name' => 'member_id',     'type' => 'INT',    'length' => 10, 'unsigned' => TRUE, 'default' => 0, 'allow_null' => FALSE ],
						[ 'name' => 'label',         'type' => 'VARCHAR','length' => 100, 'allow_null' => TRUE ],
						[ 'name' => 'status',        'type' => 'VARCHAR','length' => 20, 'default' => 'active', 'allow_null' => FALSE ],
						[ 'name' => 'created_at',    'type' => 'INT',    'length' => 10, 'unsigned' => TRUE, 'allow_null' => TRUE ],
						[ 'name' => 'last_used_at',  'type' => 'INT',    'length' => 10, 'unsigned' => TRUE, 'allow_null' => TRUE ],
						[ 'name' => 'request_count', 'type' => 'BIGINT', 'length' => 20, 'unsigned' => TRUE, 'default' => 0, 'allow_null' => FALSE ],
					],
					'indexes' => [
						[ 'type' => 'primary', 'name' => 'PRIMARY',    'columns' => [ 'id' ] ],
						[ 'type' => 'unique',  'name' => 'uq_api_key', 'columns' => [ 'api_key' ] ],
						[ 'type' => 'key',     'name' => 'idx_member', 'columns' => [ 'member_id' ] ],
						[ 'type' => 'key',     'name' => 'idx_status', 'columns' => [ 'status' ] ],
					],
				] );
			}
		}
		catch ( \Throwable $e ) { try { \IPS\Log::log( 'upg_10633 apikeys: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {} }

		try
		{
			if ( !\IPS\Db::i()->checkForTable( 'gd_compliance_api_usage' ) )
			{
				\IPS\Db::i()->createTable( [
					'name'    => 'gd_compliance_api_usage',
					'columns' => [
						[ 'name' => 'key_id', 'type' => 'INT',    'length' => 10, 'unsigned' => TRUE, 'allow_null' => FALSE ],
						[ 'name' => 'period', 'type' => 'VARCHAR','length' => 20, 'default' => '',    'allow_null' => FALSE ],
						[ 'name' => 'count',  'type' => 'INT',    'length' => 10, 'unsigned' => TRUE, 'default' => 0, 'allow_null' => FALSE ],
					],
					'indexes' => [
						[ 'type' => 'primary', 'name' => 'PRIMARY', 'columns' => [ 'key_id', 'period' ] ],
					],
				] );
			}
		}
		catch ( \Throwable $e ) { try { \IPS\Log::log( 'upg_10633 usage: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {} }

		/* ------------------------------------------------------------
		 * 2) SETTINGS — carry-forward defaults ONLY for rows that
		 *    don't yet exist. Existing values are NEVER overwritten
		 *    (ticket requirement — Derrick's edits stay).
		 * ------------------------------------------------------------ */
		$defaultDisclaimer =
			"State Firearm Compliance Lookup — Important Notice. This tool provides general information based on our product catalog and our understanding of current state law. It is not legal advice and is not a guarantee of legality. Firearm laws change frequently, vary by locality, and depend on individual circumstances. A result of 'no restrictions found' means our system did not flag this item for the selected state — it does not affirmatively certify the item is legal for you to purchase or possess. Always verify with your FFL and consult current state and local law before completing any purchase or transfer. Gun Wise LLC assumes no liability for reliance on this tool.";

		$defaultAvailableNote =
			"This 'available' list reflects items our compliance engine did not flag for the selected state. While our engine catches the vast majority of restrictions, no automated system is ever 100% accurate — it cannot account for every local ordinance, recent law change, or individual circumstance. Always confirm with your local laws and your FFL before purchasing to make sure an item is legal for you in your area.";

		$defaultUpsellText =
			"Downloading the full restricted-list CSV is a membership benefit. Upgrade your membership to enable bulk downloads.";

		$defaultApiDisclaimer =
			"This information is provided for general reference and is not legal advice or a guarantee of legality. Our engine catches the vast majority of restrictions but is never 100% accurate. Always verify with a licensed FFL and current state/local law.";

		$defaultTiers = '{"13":10000}';

		$defaultAllowedGroups = '4';
		try
		{
			$adminIds = [];
			foreach ( \IPS\Db::i()->select( 'g_id', 'core_groups', [ 'g_access_cp=?', 1 ] ) as $gid )
			{
				$adminIds[] = (int) $gid;
			}
			if ( !empty( $adminIds ) )
			{
				$defaultAllowedGroups = implode( ',', $adminIds );
			}
		}
		catch ( \Throwable ) {}

		$directInserts = [
			'gdcompliance_report_ratelimit'      => [ '5',      '5',      'full' ],
			'gdcompliance_lookup_available_note' => [ $defaultAvailableNote, $defaultAvailableNote, 'none' ],
			'gdcompliance_lookup_csv_max'        => [ '50000',  '50000',  'full' ],
			'gdcompliance_csv_allowed_groups'    => [ $defaultAllowedGroups, '4', 'full' ],
			'gdcompliance_csv_upsell_url'        => [ '#',      '#',      'none' ],
			'gdcompliance_csv_upsell_text'       => [ $defaultUpsellText, $defaultUpsellText, 'none' ],
			'gdcompliance_api_disclaimer'        => [ $defaultApiDisclaimer, $defaultApiDisclaimer, 'none' ],
			'gdcompliance_api_verified'          => [ '0',      '0',      'full' ],
			'gdcompliance_api_access_groups'     => [ '13',     '13',     'full' ],
			'gdcompliance_api_subscription_id'   => [ '6',      '6',      'full' ],
			'gdcompliance_api_tiers'             => [ $defaultTiers, $defaultTiers, 'full' ],
			'gdcompliance_api_default_quota'     => [ '10000',  '10000',  'full' ],
			'gdcompliance_api_burst_per_sec'     => [ '10',     '10',     'full' ],
			'gdcompliance_lookup_disclaimer'     => [ $defaultDisclaimer, $defaultDisclaimer, 'none' ],
			'gdcompliance_lookup_enabled'        => [ '1',      '1',      'full' ],
		];
		foreach ( $directInserts as $key => [ $val, $def, $report ] )
		{
			try
			{
				$has = (int) \IPS\Db::i()->select( 'COUNT(*)', 'core_sys_conf_settings',
					[ 'conf_key=?', $key ] )->first();
				if ( $has === 0 )
				{
					\IPS\Db::i()->insert( 'core_sys_conf_settings', [
						'conf_key'     => $key,
						'conf_value'   => $val,
						'conf_default' => $def,
						'conf_app'     => 'gdcompliance',
						'conf_report'  => $report,
					] );
				}
			}
			catch ( \Throwable ) {}
		}

		/* ------------------------------------------------------------
		 * 3) EXTENSIONS — self-heal Notifications/Report registration.
		 * ------------------------------------------------------------ */
		try
		{
			$extPath = \IPS\ROOT_PATH . '/applications/gdcompliance/data/extensions.json';
			$want    = [
				'core' => [
					'Notifications' => [
						'Report' => 'IPS\\gdcompliance\\extensions\\core\\Notifications\\Report',
					],
				],
			];
			$current = [];
			if ( is_readable( $extPath ) )
			{
				$decoded = json_decode( (string) file_get_contents( $extPath ), true );
				if ( is_array( $decoded ) ) { $current = $decoded; }
			}
			$needsWrite = false;
			foreach ( $want as $group => $types )
			{
				foreach ( $types as $type => $entries )
				{
					foreach ( $entries as $name => $cls )
					{
						if ( ( $current[ $group ][ $type ][ $name ] ?? '' ) !== $cls )
						{
							$current[ $group ][ $type ][ $name ] = $cls;
							$needsWrite = true;
						}
					}
				}
			}
			if ( $needsWrite && is_writable( dirname( $extPath ) ) )
			{
				@file_put_contents( $extPath, json_encode( $current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			}
		}
		catch ( \Throwable ) {}

		try
		{
			foreach ( [ 'gdcompliance_report_resolved', 'gdcompliance_report_dismissed' ] as $nkey )
			{
				try
				{
					\IPS\Db::i()->replace( 'core_notification_defaults', [
						'notification_app' => 'gdcompliance',
						'notification_key' => $nkey,
						'default'          => '["inline","email"]',
					] );
				}
				catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable ) {}

		/* ------------------------------------------------------------
		 * 4) LANG — re-seed EVERY lookup / reports / api / mykey key
		 *    plus the NEW 1.6.32 settings-page headers + JSON error
		 *    strings + field labels. Per-row try/catch (rule #44);
		 *    6-col schema only (rule #43).
		 * ------------------------------------------------------------ */
		$newStrings = [
			'gdcompliance_acp_settings_lookup_header'   => 'Public State Compliance Lookup (/state-lookup/)',
			'gdcompliance_lookup_enabled'               => 'Publish the /state-lookup/ page',
			'gdcompliance_lookup_enabled_desc'          => 'When off, visitors to /state-lookup/ see a "temporarily unavailable" notice instead of the lookup form.',
			'gdcompliance_lookup_disclaimer'            => 'Public disclaimer',
			'gdcompliance_lookup_disclaimer_desc'       => 'Shown at the top of the /state-lookup/ page. Legal-guidance framing recommended — this is customer-facing.',

			'gdcompliance_lookup_page_title'            => 'State Firearm Compliance Lookup',
			'gdcompliance_lookup_intro'                 => 'Pick your state and enter a UPC or MPN to check whether that item is restricted for sale in your state. Read-only against our current catalog.',
			'gdcompliance_lookup_disclaimer_label'      => 'Important Notice',
			'gdcompliance_lookup_default_disclaimer'    => $defaultDisclaimer,
			'gdcompliance_lookup_field_state'           => 'Ship-to state',
			'gdcompliance_lookup_pick_state'            => 'Pick a state…',
			'gdcompliance_lookup_field_q'               => 'UPC or MPN',
			'gdcompliance_lookup_field_q_ph'            => 'e.g. 022188879834',
			'gdcompliance_lookup_submit'                => 'Look up',

			'gdcompliance_lookup_product'               => 'UPC %s:',
			'gdcompliance_lookup_citation'              => 'Citation: %s',
			'gdcompliance_lookup_restricted_headline'   => 'Restricted for sale in %s',
			'gdcompliance_lookup_advisory_label'        => 'Buyer requirement',
			'gdcompliance_lookup_advisory_headline'     => 'Buyer requirement in %s',
			'gdcompliance_lookup_advisory_intro'        => 'This item can ship. The buyer must meet a state permit / training requirement at the FFL. Not a sale prohibition.',
			'gdcompliance_lookup_norestrict_headline'   => 'No restrictions found in %s',
			'gdcompliance_lookup_clear_body'            => 'No restrictions found for this item in %s.',
			'gdcompliance_lookup_clear_reminder'        => 'This is not a legal guarantee. Verify with your FFL and consult current state and local law before completing any purchase.',
			'gdcompliance_lookup_verify_reminder'       => 'This reflects our current data. Verify with your receiving FFL before purchase.',
			'gdcompliance_lookup_not_found'             => 'We could not find %s in our catalog, so we have no compliance information for it.',
			'gdcompliance_lookup_not_found_hint'        => 'Double-check the UPC / MPN. Only items currently in our catalog are covered by this tool.',
			'gdcompliance_lookup_disabled_msg'          => 'The state-lookup page is temporarily unavailable. Please check back later.',

			'gdcompliance_lookup_report_cta'            => 'Report a problem with this result',
			'gdcompliance_lookup_report_login_cta'      => 'Log in to report a classification issue',
			'gdcompliance_lookup_report_login_required' => 'Please log in to submit a report.',
			'gdcompliance_lookup_report_note_label'     => 'Tell us what looks wrong',
			'gdcompliance_lookup_report_note_placeholder' => 'What do you believe is misclassified, and why? Feel free to link a state statute or catalog page.',
			'gdcompliance_lookup_report_submit'         => 'Submit report',
			'gdcompliance_lookup_report_hint'           => 'Reports are reviewed by staff. Corrections can take up to 90 days to be reflected in the catalog. If you are logged in, you will be notified when your report is resolved.',
			'gdcompliance_lookup_report_thanks'         => 'Thank you — your report has been received and will be reviewed. Any resulting correction can take up to 90 days to be reflected in the catalog. You will be notified when your report is resolved.',
			'gdcompliance_lookup_report_ratelimited'    => 'You have submitted several reports recently. Please try again later.',
			'gdcompliance_lookup_report_error'          => 'We could not accept your report. Please check the form and try again.',

			'menu__gdcompliance_compliance_reports'     => 'Compliance Reports',
			'gdcompliance_acp_reports_title'            => 'Compliance Reports',
			'gdcompliance_acp_reports_intro'            => 'Member-submitted reports from the public /state-lookup/ page. Resolve a report to create an override that flips the classification on next recompute, or dismiss to close without action. Either action notifies the reporter.',
			'gdcompliance_acp_reports_tab_pending'      => 'Pending',
			'gdcompliance_acp_reports_tab_resolved'     => 'Resolved',
			'gdcompliance_acp_reports_tab_dismissed'    => 'Dismissed',
			'gdcompliance_acp_reports_tab_all'          => 'All',
			'gdcompliance_acp_reports_col_member_id'               => 'Reporter',
			'gdcompliance_acp_reports_col_upc'                     => 'UPC',
			'gdcompliance_acp_reports_col_state_code'              => 'State',
			'gdcompliance_acp_reports_col_reported_classification' => 'Reported',
			'gdcompliance_acp_reports_col_note'                    => 'Note',
			'gdcompliance_acp_reports_col_status'                  => 'Status',
			'gdcompliance_acp_reports_col_created_at'              => 'Submitted',
			'gdcompliance_acp_reports_action_resolve'   => 'Resolve',
			'gdcompliance_acp_reports_action_dismiss'   => 'Dismiss',
			'gdcompliance_acp_reports_action_view'      => 'View',
			'gdcompliance_acp_reports_resolve_title'    => 'Resolve report',
			'gdcompliance_acp_reports_dismiss_title'    => 'Dismiss report',
			'gdcompliance_acp_reports_resolve_override' => 'Create catalog override on resolve?',
			'gdcompliance_acp_reports_resolve_override_none'           => 'No override — classification was correct or handled separately.',
			'gdcompliance_acp_reports_resolve_override_force_clear'    => 'Force clear — no restrictions for this UPC in this state.',
			'gdcompliance_acp_reports_resolve_override_force_restrict' => 'Force restrict — this UPC IS restricted in this state.',
			'gdcompliance_acp_reports_resolution_note'      => 'Resolution note (surfaced to the reporter)',
			'gdcompliance_acp_reports_resolution_note_hint' => 'Optional. Kept short — this is delivered inline with the notification.',
			'gdcompliance_acp_reports_cancel'          => 'Cancel',

			'gdcompliance_report_ratelimit'      => 'Max reports per member per hour',
			'gdcompliance_report_ratelimit_desc' => 'Rate limit for submissions to the /state-lookup/ report form. Login is already required; this backstops spam.',
			'gdcompliance_notif_report'                        => 'Compliance report reviewed',
			'gdcompliance_notif_report_desc'                   => 'When staff resolves or dismisses a compliance report you submitted from the /state-lookup/ page.',
			'notification__gdcompliance_report_resolved'       => 'Compliance report resolved',
			'notification__gdcompliance_report_resolved_desc'  => 'Notify me when a compliance report I submitted is resolved.',
			'notification__gdcompliance_report_dismissed'      => 'Compliance report dismissed',
			'notification__gdcompliance_report_dismissed_desc' => 'Notify me when a compliance report I submitted is dismissed.',

			'gdcompliance_lookup_available_note'   => $defaultAvailableNote,
			'gdcompliance_lookup_available_note_desc' => 'Verification note shown at the top of the Advanced Search "Available" result view. Customer-facing.',
			'gdcompliance_lookup_csv_max'          => 'CSV export row cap',
			'gdcompliance_lookup_csv_max_desc'     => 'Maximum rows the /state-lookup/ restricted-list CSV export will produce, per state. Backstop against runaway generation.',
			'gdcompliance_lookup_tab_single'       => 'Single Lookup',
			'gdcompliance_lookup_tab_search'       => 'Advanced Search',
			'gdcompliance_lookup_tab_statelist'    => 'Restricted List',
			'gdcompliance_lookup_search_state'     => 'Ship-to state',
			'gdcompliance_lookup_search_category'  => 'Category',
			'gdcompliance_lookup_search_brand'     => 'Brand (optional)',
			'gdcompliance_lookup_search_mode'      => 'Mode',
			'gdcompliance_lookup_search_type'      => 'Restriction type',
			'gdcompliance_lookup_search_run'       => 'Search',
			'gdcompliance_lookup_mode_restricted'  => 'Restricted',
			'gdcompliance_lookup_mode_available'   => 'Available',
			'gdcompliance_lookup_available_warn'   => 'Filter required. The "available" list is too large to render without at least one of Category or Brand set. Pick a category or type a brand.',
			'gdcompliance_lookup_pick_state_msg'   => 'Pick a state to search.',
			'gdcompliance_lookup_pick_state_list'  => 'Pick a state to view its full restricted list.',
			'gdcompliance_lookup_search_no_match'  => 'No items match those filters for %s.',
			'gdcompliance_lookup_statelist_title'  => 'Restricted List',
			'gdcompliance_lookup_csv_download'     => 'Download CSV',
			'gdcompliance_lookup_row_report'       => 'Report a problem',
			'gdcompliance_lookup_row_available_label' => 'Available',

			'gdcompliance_csv_allowed_groups'      => 'Groups allowed to download the restricted-list CSV',
			'gdcompliance_csv_allowed_groups_desc' => 'Members in any listed group can download the /state-lookup/ restricted-list CSV; everyone else sees an upsell prompt. Guests always denied.',
			'gdcompliance_csv_upsell_url'          => 'CSV upsell link',
			'gdcompliance_csv_upsell_url_desc'     => 'Where the "Upgrade" button on the CSV upsell block links to. Point at your membership / subscription page. Leave as # to render a disabled "Learn more" label.',
			'gdcompliance_csv_upsell_text'         => 'CSV upsell message',
			'gdcompliance_csv_upsell_text_desc'    => 'Text shown to non-allowed visitors in place of the CSV download button.',
			'gdcompliance_csv_upsell_default'      => $defaultUpsellText,
			'gdcompliance_csv_locked_title'        => 'Download CSV — members only',
			'gdcompliance_csv_cta_upgrade'         => 'Upgrade',
			'gdcompliance_csv_cta_learn'           => 'Learn more',

			'menu__gdcompliance_compliance_apikeys'  => 'API Keys',
			'gdcompliance_acp_apikeys_title'         => 'Compliance API Keys',
			'gdcompliance_acp_apikeys_intro'         => 'Machine-to-machine keys for /api/compliance/. Each key belongs to a member (dealer) and authenticates JSON requests to /api/compliance/check and /api/compliance/batch. Suspend to temporarily block (returns 402); revoke to permanently block (returns 401).',
			'gdcompliance_acp_apikeys_create'        => 'Create key',
			'gdcompliance_acp_apikeys_create_title'  => 'Create API key',
			'gdcompliance_acp_apikeys_col_label'         => 'Label',
			'gdcompliance_acp_apikeys_col_member_id'     => 'Owner',
			'gdcompliance_acp_apikeys_col_api_key'       => 'Key',
			'gdcompliance_acp_apikeys_col_status'        => 'Status',
			'gdcompliance_acp_apikeys_col_request_count' => 'Requests',
			'gdcompliance_acp_apikeys_col_created_at'    => 'Created',
			'gdcompliance_acp_apikeys_col_last_used_at'  => 'Last used',
			'gdcompliance_acp_apikeys_action_suspend'    => 'Suspend',
			'gdcompliance_acp_apikeys_action_reactivate' => 'Reactivate',
			'gdcompliance_acp_apikeys_action_revoke'     => 'Revoke',
			'gdcompliance_api_disclaimer'      => 'API response disclaimer',
			'gdcompliance_api_disclaimer_desc' => 'Legal-guidance text embedded in every /api/compliance/ JSON response so it propagates to the dealer\'s frontend (liability chain).',
			'gdcompliance_api_verified'        => 'Data verification: mark data as legally verified',
			'gdcompliance_api_verified_desc'   => 'Off (default): every API response carries verification_status="pending_legal_review". Flip on after your legal review completes to advertise "verified" data to integrating dealers.',

			'gdcompliance_api_access_groups'        => 'Member groups granting API access',
			'gdcompliance_api_access_groups_desc'   => 'IPS Commerce should add subscribers to at least one of these groups (secondary group on the API subscription package) and remove them on lapse — the API gate reads live group membership per request, so no webhook is needed. Admins always pass.',
			'gdcompliance_api_subscription_id'      => 'API subscription package ID',
			'gdcompliance_api_subscription_id_desc' => 'Nexus subscription package ID used to build the subscribe/upsell link in 402 responses and on the self-service key page.',
			'gdcompliance_mykey_page_title'    => 'Your Compliance API Key',
			'gdcompliance_mykey_login'         => 'Log in',
			'gdcompliance_mykey_login_msg'     => 'Please log in to view or generate your API key.',
			'gdcompliance_mykey_upsell_title'  => 'API access requires a subscription',
			'gdcompliance_mykey_upsell_msg'    => 'The Compliance API is a subscription-only integration. Once you subscribe, this page will let you generate and manage your API key.',
			'gdcompliance_mykey_upsell_cta'    => 'View subscription',
			'gdcompliance_mykey_generate_title' => 'Generate your API key',
			'gdcompliance_mykey_generate_msg'   => 'You have an active Compliance API subscription. Generate a key below to start making requests.',
			'gdcompliance_mykey_generate_btn'   => 'Generate API key',
			'gdcompliance_mykey_your_key'       => 'Your API key',
			'gdcompliance_mykey_regen_btn'      => 'Regenerate key',
			'gdcompliance_mykey_regen_confirm'  => 'Regenerating will invalidate the existing key immediately. Any live integrations using it will break until you paste in the new key. Continue?',
			'gdcompliance_mykey_how_title'      => 'How to use it',
			'gdcompliance_mykey_endpoints'      => 'Endpoints',
			'gdcompliance_mykey_envelope'       => 'Response envelope',

			'gdcompliance_api_tiers'              => 'Tier quotas (group → monthly requests)',
			'gdcompliance_api_tiers_desc'         => 'JSON object mapping group_id (string) to monthly request quota (int). Example: {"13":10000,"14":100000}. Set a quota of 0 to grant unlimited requests for that tier. Members in multiple tiers get the highest quota.',
			'gdcompliance_api_default_quota'      => 'Default monthly quota',
			'gdcompliance_api_default_quota_desc' => 'Applied when a member is in an API-access group that has no explicit tier mapping. Ignored for admins (always unlimited).',
			'gdcompliance_api_burst_per_sec'      => 'Burst throttle (requests / second / key)',
			'gdcompliance_api_burst_per_sec_desc' => 'Server-protection throttle. Requests above this rate return HTTP 429 rate_limited with Retry-After: 1. Independent of the monthly quota.',
			'gdcompliance_mykey_usage_title'      => 'Usage',
			'gdcompliance_mykey_usage_tier'       => 'Tier',
			'gdcompliance_mykey_usage_quota'      => 'Quota',
			'gdcompliance_mykey_usage_month'      => 'This month',
			'gdcompliance_mykey_usage_reset'      => 'Reset',
			'gdcompliance_mykey_usage_lifetime'   => 'Lifetime',
			'gdcompliance_mykey_usage_upsell'     => 'Approaching your monthly quota. Upgrade your subscription to raise the cap.',
			'gdcompliance_mykey_usage_over'       => 'Monthly quota reached. Further requests will return 429 until reset.',

			/* v1.6.32 NEW — settings ACP page */
			'gdcompliance_acp_settings_title'             => 'GD Compliance — Settings',
			'gdcompliance_acp_settings_storefront_header' => 'Storefront restriction panel (Phase 5)',
			'gdcompliance_acp_settings_csv_header'        => 'CSV Export Gate — /state-lookup/ restricted-list download',
			'gdcompliance_acp_settings_api_header'        => 'Compliance API — /api/compliance/*',
			'gdcompliance_acp_settings_roster_header'     => 'Roster source URLs (CA / MA / MD)',
			'gdcompliance_api_tiers_bad_json'             => 'Tier quotas must be valid JSON. Example: {"13":10000}',
			'gdcompliance_api_tiers_bad_shape'            => 'Each entry must be a positive group id mapped to a non-negative integer quota. Use 0 for unlimited.',
			'gdcompliance_api_verified_warning'           => 'WARNING: only enable after your legal review completes. Flipping this on advertises "verified" data to integrating dealers.',
			'gdcompliance_front_enabled'                  => 'Show the storefront restriction panel',
			'gdcompliance_front_show_reasons'             => 'Show reason text on the restriction panel',
			'gdcompliance_front_disclaimer'               => 'Storefront panel disclaimer',
			'gdcompliance_ca_roster_url'                  => 'California roster source URL',
			'gdcompliance_ma_roster_url'                  => 'Massachusetts roster source URL',
			'gdcompliance_md_roster_url'                  => 'Maryland approved-list source URL',
			'gdcompliance_md_disapproved_url'             => 'Maryland disapproved-list source URL',
			'gdcompliance_dc_derive'                      => 'Derive DC restrictions from CA/MA/MD',
			'acplog__gdcompliance_settings_saved'         => 'Updated GD Compliance settings',
		];

		try
		{
			foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
			{
				foreach ( $newStrings as $key => $val )
				{
					try
					{
						\IPS\Db::i()->replace( 'core_sys_lang_words', [
							'lang_id'      => (int) $langId,
							'word_app'     => 'gdcompliance',
							'word_key'     => (string) $key,
							'word_default' => (string) $val,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
					catch ( \Throwable ) {}
				}
			}
		}
		catch ( \Throwable ) {}

		/* ------------------------------------------------------------
		 * 5) ACP permission row (defensive).
		 * ------------------------------------------------------------ */
		try
		{
			$has = (int) \IPS\Db::i()->select( 'COUNT(*)', 'core_admin_permission_rows',
				[ 'app=? AND `key`=?', 'gdcompliance', 'compliance_manage' ] )->first();
			if ( !$has )
			{
				\IPS\Db::i()->insert( 'core_admin_permission_rows', [
					'app' => 'gdcompliance',
					'key' => 'compliance_manage',
					'tab' => 'gdcompliance',
				] );
			}
		}
		catch ( \Throwable ) {}

		/* ------------------------------------------------------------
		 * 6) CACHE PURGES.
		 * ------------------------------------------------------------ */
		try { unset( \IPS\Data\Store::i()->lang ); }               catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->furl_configuration ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->furl ); }               catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->acpmenu ); }            catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->notifications ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->canonical_templates ); } catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}

		try
		{
			$datastore = \IPS\ROOT_PATH . '/datastore';
			if ( is_dir( $datastore ) )
			{
				foreach ( [ 'furl_configuration.php', 'furl.php', 'modules_front.php', 'modules_admin.php', 'settings.php' ] as $file )
				{
					$p = $datastore . '/' . $file;
					if ( is_file( $p ) && is_writable( $p ) ) { @unlink( $p ); }
				}
			}
		}
		catch ( \Throwable ) {}

		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
