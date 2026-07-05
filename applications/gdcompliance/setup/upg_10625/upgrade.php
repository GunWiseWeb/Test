<?php
/**
 * @brief  GD Compliance — upgrade 1.6.25
 *
 * NOTE ON VERSION — the corresponding prompt asked for 1.6.24 as the
 * Stage 3 ship, but v1.6.24 already shipped as the Stage 2 reports
 * feature. Next available number is 1.6.25.
 *
 * WHAT SHIPS IN 1.6.25 — State Lookup Stage 3:
 *
 *   - Advanced Search view (?do=search) on /state-lookup/. Filters:
 *     state (required), mode (Restricted|Available), category
 *     (Any|Handguns|Rifles|Shotguns via Engine::buildTypeMap),
 *     restriction type (Restricted mode only), brand text.
 *     Paginated. Native select()->join() only — no raw preparedQuery.
 *   - Available mode requires at least one of category/brand set —
 *     rejects a bare state-only query so we never enumerate ~catalog.
 *   - Restricted List view (?do=statelist) — full-state list, type
 *     filter, paginated + CSV export (?do=statelist&state=XX&export=csv)
 *     with Content-Disposition attachment, cap gdcompliance_lookup_csv_max
 *     (default 50000).
 *   - Verification note (setting gdcompliance_lookup_available_note,
 *     seeded default) shown at the top of every Available result.
 *   - Row-level "Report a problem" affordance on search + statelist
 *     rows — reuses the Stage-2 flow by linking back to the Single
 *     Lookup URL with state+q pre-filled. Zero new report code.
 *   - Top-of-page tab strip: Single Lookup | Advanced Search |
 *     Restricted List. Preserved across state selection.
 *
 * SELF-CONTAINED (rule #79). This is the ONLY upg dir for this app;
 * every prior migration folded forward:
 *   - v1.6.24 gd_compliance_reports table + report ratelimit setting
 *     + Notifications extension registration + report notification
 *     defaults + full lang re-seed of every 1.6.22/1.6.23 lookup key
 *   - v1.6.23 lookup lang key re-seed
 *   - v1.6.22 defensive settings ensure
 *
 * No schema changes THIS version (statelist reads gd_compliance_flags,
 * available reads gd_catalog + LEFT JOIN flags). Only new settings +
 * lang.
 */

namespace IPS\gdcompliance\setup\upg_10625;

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
		 * 1) SCHEMA — carry v1.6.24's gd_compliance_reports create
		 *    forward defensively. checkForTable guard means safe on a
		 *    box already at 1.6.24.
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
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10625 create gd_compliance_reports: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}

		/* ------------------------------------------------------------
		 * 2) SETTINGS — defensive re-seed of 1.6.22 disclaimer + 1.6.24
		 *    ratelimit + NEW 1.6.25 available_note + csv_max.
		 * ------------------------------------------------------------ */
		$defaultDisclaimer =
			"State Firearm Compliance Lookup — Important Notice. This tool provides general information based on our product catalog and our understanding of current state law. It is not legal advice and is not a guarantee of legality. Firearm laws change frequently, vary by locality, and depend on individual circumstances. A result of 'no restrictions found' means our system did not flag this item for the selected state — it does not affirmatively certify the item is legal for you to purchase or possess. Always verify with your FFL and consult current state and local law before completing any purchase or transfer. Gun Wise LLC assumes no liability for reliance on this tool.";

		$defaultAvailableNote =
			"This 'available' list reflects items our compliance engine did not flag for the selected state. While our engine catches the vast majority of restrictions, no automated system is ever 100% accurate — it cannot account for every local ordinance, recent law change, or individual circumstance. Always confirm with your local laws and your FFL before purchasing to make sure an item is legal for you in your area.";

		try
		{
			$changes = [];
			$currentDisclaimer = (string) ( \IPS\Settings::i()->gdcompliance_lookup_disclaimer ?? '' );
			if ( $currentDisclaimer === '' )
			{
				$changes['gdcompliance_lookup_disclaimer'] = $defaultDisclaimer;
			}
			if ( !isset( \IPS\Settings::i()->gdcompliance_lookup_enabled ) )
			{
				$changes['gdcompliance_lookup_enabled'] = 1;
			}
			if ( !isset( \IPS\Settings::i()->gdcompliance_report_ratelimit ) )
			{
				$changes['gdcompliance_report_ratelimit'] = 5;
			}
			$currentAvailableNote = (string) ( \IPS\Settings::i()->gdcompliance_lookup_available_note ?? '' );
			if ( $currentAvailableNote === '' )
			{
				$changes['gdcompliance_lookup_available_note'] = $defaultAvailableNote;
			}
			if ( !isset( \IPS\Settings::i()->gdcompliance_lookup_csv_max ) )
			{
				$changes['gdcompliance_lookup_csv_max'] = 50000;
			}
			if ( !empty( $changes ) )
			{
				\IPS\Settings::i()->changeValues( $changes );
			}
		}
		catch ( \Throwable ) {}

		/* If the setting rows don't exist at all, insert directly. */
		$directInserts = [
			'gdcompliance_report_ratelimit'         => [ '5',      '5',      'full' ],
			'gdcompliance_lookup_available_note'    => [ $defaultAvailableNote, $defaultAvailableNote, 'none' ],
			'gdcompliance_lookup_csv_max'           => [ '50000',  '50000',  'full' ],
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
		 * 3) EXTENSIONS — self-heal Notifications/Report registration
		 *    in data/extensions.json + cache bust (rule #16).
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

		/* Seed notification defaults (bell + email) — idempotent. */
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
		 * 4) LANG — full re-seed of every gdcompliance_lookup_* +
		 *    every gdcompliance_acp_reports_* + notification strings +
		 *    v1.6.25 Stage 3 strings. Per-row try/catch (rule #44).
		 *    6-column schema only (rule #43).
		 * ------------------------------------------------------------ */
		$newStrings = [
			/* -- v1.6.22 ACP settings block */
			'gdcompliance_acp_settings_lookup_header'   => 'Public State Compliance Lookup (/state-lookup/)',
			'gdcompliance_lookup_enabled'               => 'Publish the /state-lookup/ page',
			'gdcompliance_lookup_enabled_desc'          => 'When off, visitors to /state-lookup/ see a "temporarily unavailable" notice instead of the lookup form.',
			'gdcompliance_lookup_disclaimer'            => 'Public disclaimer',
			'gdcompliance_lookup_disclaimer_desc'       => 'Shown at the top of the /state-lookup/ page. Legal-guidance framing recommended — this is customer-facing.',

			/* -- v1.6.22 public page chrome */
			'gdcompliance_lookup_page_title'            => 'State Firearm Compliance Lookup',
			'gdcompliance_lookup_intro'                 => 'Pick your state and enter a UPC or MPN to check whether that item is restricted for sale in your state. Read-only against our current catalog.',
			'gdcompliance_lookup_disclaimer_label'      => 'Important Notice',
			'gdcompliance_lookup_default_disclaimer'    => $defaultDisclaimer,
			'gdcompliance_lookup_field_state'           => 'Ship-to state',
			'gdcompliance_lookup_pick_state'            => 'Pick a state…',
			'gdcompliance_lookup_field_q'               => 'UPC or MPN',
			'gdcompliance_lookup_field_q_ph'            => 'e.g. 022188879834',
			'gdcompliance_lookup_submit'                => 'Look up',

			/* -- v1.6.23 result strings (native IPS sprintf) */
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

			/* -- v1.6.24 Stage 2 report block */
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

			/* -- v1.6.24 ACP triage */
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

			/* -- v1.6.24 settings + notifications */
			'gdcompliance_report_ratelimit'      => 'Max reports per member per hour',
			'gdcompliance_report_ratelimit_desc' => 'Rate limit for submissions to the /state-lookup/ report form. Login is already required; this backstops spam.',
			'gdcompliance_notif_report'                        => 'Compliance report reviewed',
			'gdcompliance_notif_report_desc'                   => 'When staff resolves or dismisses a compliance report you submitted from the /state-lookup/ page.',
			'notification__gdcompliance_report_resolved'       => 'Compliance report resolved',
			'notification__gdcompliance_report_resolved_desc'  => 'Notify me when a compliance report I submitted is resolved.',
			'notification__gdcompliance_report_dismissed'      => 'Compliance report dismissed',
			'notification__gdcompliance_report_dismissed_desc' => 'Notify me when a compliance report I submitted is dismissed.',

			/* -- v1.6.25 NEW: Stage 3 advanced search + statelist + CSV */
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
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
