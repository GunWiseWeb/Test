<?php
/**
 * @brief  GD Compliance — ACP Settings (v1.6.32 rebuild)
 *
 * Grouped edit UI for every gdcompliance_* setting that ships with
 * the app. Verified inventory by grepping `\IPS\Settings::i()->
 * gdcompliance_` across the codebase — every one referenced in a
 * source file has an edit field here.
 *
 * Groups:
 *   1. Storefront panel (Phase 5 pre-existing)
 *   2. Public Lookup (Stage 1 → Stage 3)
 *   3. CSV Export Gate (v1.6.27)
 *   4. Compliance API (v1.6.28 → Stage 3 v1.6.31)
 *   5. Rosters (long-standing distributor URLs)
 *
 * IPS group-picker: gdcompliance_csv_allowed_groups +
 * gdcompliance_api_access_groups render as multi-select group
 * pickers using \IPS\Helpers\Form\Select with an id→name map from
 * core_groups. Stored as comma-separated IDs (unchanged wire
 * format — reads unaffected).
 *
 * api_tiers JSON validated on submit; invalid JSON raises a form
 * error, existing value preserved.
 *
 * Setting VALUES are LOADED from IPS\Settings and only written back
 * on explicit save (rule per ticket — no re-seed / overwrite).
 */

namespace IPS\gdcompliance\modules\admin\compliance;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _settings extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'compliance_manage' );
		parent::execute();
	}

	/**
	 * Build the `g_id => g_name` map for the group multi-selects.
	 * Uses core_groups directly. Memoized per request.
	 */
	protected function groupOptions(): array
	{
		static $cache = null;
		if ( $cache !== null ) { return $cache; }
		$out = [];
		try
		{
			foreach ( \IPS\Db::i()->select( 'g_id, g_name', 'core_groups', null, 'g_name ASC' ) as $row )
			{
				$out[ (int) $row['g_id'] ] = (string) $row['g_name'];
			}
		}
		catch ( \Throwable ) {}
		$cache = $out;
		return $out;
	}

	/**
	 * Explode a comma-separated setting value into an int[] for the
	 * Select `value` argument. Empty setting → empty array.
	 */
	protected function csvSettingToIds( string $key ): array
	{
		$raw = (string) ( \IPS\Settings::i()->{$key} ?? '' );
		if ( $raw === '' ) { return []; }
		$out = [];
		foreach ( explode( ',', $raw ) as $v )
		{
			$i = (int) trim( $v );
			if ( $i > 0 ) { $out[] = $i; }
		}
		return array_values( array_unique( $out ) );
	}

	protected function manage(): void
	{
		$lang = \IPS\Member::loggedIn()->language();
		$form = new \IPS\Helpers\Form;

		$groupOpts = $this->groupOptions();

		/* ==================================================================
		 * 1) STOREFRONT PANEL (Phase 5)
		 * ================================================================== */
		$form->addHeader( 'gdcompliance_acp_settings_storefront_header' );

		$form->add( new \IPS\Helpers\Form\YesNo(
			'gdcompliance_front_enabled',
			(int) ( \IPS\Settings::i()->gdcompliance_front_enabled ?? 1 ),
			FALSE
		) );

		$form->add( new \IPS\Helpers\Form\YesNo(
			'gdcompliance_front_show_reasons',
			(int) ( \IPS\Settings::i()->gdcompliance_front_show_reasons ?? 1 ),
			FALSE
		) );

		$form->add( new \IPS\Helpers\Form\TextArea(
			'gdcompliance_front_disclaimer',
			(string) ( \IPS\Settings::i()->gdcompliance_front_disclaimer ?? '' ),
			FALSE,
			[ 'rows' => 3 ]
		) );

		/* ==================================================================
		 * 2) PUBLIC LOOKUP  (/state-lookup/)
		 * ================================================================== */
		$form->addHeader( 'gdcompliance_acp_settings_lookup_header' );

		$form->add( new \IPS\Helpers\Form\YesNo(
			'gdcompliance_lookup_enabled',
			(int) ( \IPS\Settings::i()->gdcompliance_lookup_enabled ?? 1 ),
			FALSE
		) );

		$form->add( new \IPS\Helpers\Form\TextArea(
			'gdcompliance_lookup_disclaimer',
			(string) ( \IPS\Settings::i()->gdcompliance_lookup_disclaimer ?? '' ),
			FALSE,
			[ 'rows' => 6 ]
		) );

		$form->add( new \IPS\Helpers\Form\TextArea(
			'gdcompliance_lookup_available_note',
			(string) ( \IPS\Settings::i()->gdcompliance_lookup_available_note ?? '' ),
			FALSE,
			[ 'rows' => 5 ]
		) );

		$form->add( new \IPS\Helpers\Form\Number(
			'gdcompliance_lookup_csv_max',
			(int) ( \IPS\Settings::i()->gdcompliance_lookup_csv_max ?? 50000 ),
			FALSE,
			[ 'min' => 100, 'max' => 200000 ]
		) );

		$form->add( new \IPS\Helpers\Form\Number(
			'gdcompliance_report_ratelimit',
			(int) ( \IPS\Settings::i()->gdcompliance_report_ratelimit ?? 5 ),
			FALSE,
			[ 'min' => 1, 'max' => 100 ]
		) );

		/* ==================================================================
		 * 3) CSV EXPORT GATE  (/state-lookup/ restricted-list CSV)
		 * ================================================================== */
		$form->addHeader( 'gdcompliance_acp_settings_csv_header' );

		$form->add( new \IPS\Helpers\Form\Select(
			'gdcompliance_csv_allowed_groups',
			$this->csvSettingToIds( 'gdcompliance_csv_allowed_groups' ),
			FALSE,
			[
				'options'  => $groupOpts,
				'multiple' => TRUE,
			]
		) );

		$form->add( new \IPS\Helpers\Form\Text(
			'gdcompliance_csv_upsell_url',
			(string) ( \IPS\Settings::i()->gdcompliance_csv_upsell_url ?? '#' ),
			FALSE,
			[ 'maxLength' => 500 ]
		) );

		$form->add( new \IPS\Helpers\Form\TextArea(
			'gdcompliance_csv_upsell_text',
			(string) ( \IPS\Settings::i()->gdcompliance_csv_upsell_text ?? '' ),
			FALSE,
			[ 'rows' => 3 ]
		) );

		/* ==================================================================
		 * 4) COMPLIANCE API  (/api/compliance/*)
		 * ================================================================== */
		$form->addHeader( 'gdcompliance_acp_settings_api_header' );

		$form->add( new \IPS\Helpers\Form\Select(
			'gdcompliance_api_access_groups',
			$this->csvSettingToIds( 'gdcompliance_api_access_groups' ),
			FALSE,
			[
				'options'  => $groupOpts,
				'multiple' => TRUE,
			]
		) );

		$form->add( new \IPS\Helpers\Form\Number(
			'gdcompliance_api_subscription_id',
			(int) ( \IPS\Settings::i()->gdcompliance_api_subscription_id ?? 6 ),
			FALSE,
			[ 'min' => 0 ]
		) );

		$form->add( new \IPS\Helpers\Form\Number(
			'gdcompliance_api_default_quota',
			(int) ( \IPS\Settings::i()->gdcompliance_api_default_quota ?? 10000 ),
			FALSE,
			[ 'min' => 1 ]
		) );

		$form->add( new \IPS\Helpers\Form\Number(
			'gdcompliance_api_burst_per_sec',
			(int) ( \IPS\Settings::i()->gdcompliance_api_burst_per_sec ?? 10 ),
			FALSE,
			[ 'min' => 1, 'max' => 10000 ]
		) );

		/* api_tiers — JSON textarea with a validation callback. Reject
		   invalid JSON with a form error; preserve current value on
		   error so Derrick doesn't lose his edit. */
		$form->add( new \IPS\Helpers\Form\TextArea(
			'gdcompliance_api_tiers',
			(string) ( \IPS\Settings::i()->gdcompliance_api_tiers ?? '{"13":10000}' ),
			FALSE,
			[ 'rows' => 4 ],
			function( $val ) {
				$val = (string) $val;
				if ( $val === '' ) { return; }
				$decoded = json_decode( $val, true );
				if ( !is_array( $decoded ) || json_last_error() !== JSON_ERROR_NONE )
				{
					throw new \DomainException( 'gdcompliance_api_tiers_bad_json' );
				}
				foreach ( $decoded as $k => $v )
				{
					if ( (int) $k <= 0 || !is_numeric( $v ) || (int) $v < 0 )
					{
						throw new \DomainException( 'gdcompliance_api_tiers_bad_shape' );
					}
				}
			}
		) );

		$form->add( new \IPS\Helpers\Form\TextArea(
			'gdcompliance_api_disclaimer',
			(string) ( \IPS\Settings::i()->gdcompliance_api_disclaimer ?? '' ),
			FALSE,
			[ 'rows' => 5 ]
		) );

		$form->add( new \IPS\Helpers\Form\YesNo(
			'gdcompliance_api_verified',
			(int) ( \IPS\Settings::i()->gdcompliance_api_verified ?? 0 ),
			FALSE
		) );

		/* ==================================================================
		 * 5) ROSTERS  (distributor / state-list source URLs)
		 * ================================================================== */
		$form->addHeader( 'gdcompliance_acp_settings_roster_header' );

		$form->add( new \IPS\Helpers\Form\Text(
			'gdcompliance_ca_roster_url',
			(string) ( \IPS\Settings::i()->gdcompliance_ca_roster_url ?? '' ),
			FALSE,
			[ 'maxLength' => 500 ]
		) );

		$form->add( new \IPS\Helpers\Form\Text(
			'gdcompliance_ma_roster_url',
			(string) ( \IPS\Settings::i()->gdcompliance_ma_roster_url ?? '' ),
			FALSE,
			[ 'maxLength' => 500 ]
		) );

		$form->add( new \IPS\Helpers\Form\Text(
			'gdcompliance_md_roster_url',
			(string) ( \IPS\Settings::i()->gdcompliance_md_roster_url ?? '' ),
			FALSE,
			[ 'maxLength' => 500 ]
		) );

		$form->add( new \IPS\Helpers\Form\Text(
			'gdcompliance_md_disapproved_url',
			(string) ( \IPS\Settings::i()->gdcompliance_md_disapproved_url ?? '' ),
			FALSE,
			[ 'maxLength' => 500 ]
		) );

		$form->add( new \IPS\Helpers\Form\YesNo(
			'gdcompliance_dc_derive',
			(int) ( \IPS\Settings::i()->gdcompliance_dc_derive ?? 1 ),
			FALSE
		) );

		if ( $values = $form->values() )
		{
			/* Normalize the group multi-selects back to comma-separated
			   strings — that's the wire format the readers use. */
			$csvGroups = is_array( $values['gdcompliance_csv_allowed_groups'] ?? null )
				? $values['gdcompliance_csv_allowed_groups']
				: [];
			$apiGroups = is_array( $values['gdcompliance_api_access_groups'] ?? null )
				? $values['gdcompliance_api_access_groups']
				: [];

			$csvGroupsStr = implode( ',', array_map( 'intval', $csvGroups ) );
			$apiGroupsStr = implode( ',', array_map( 'intval', $apiGroups ) );

			try
			{
				\IPS\Settings::i()->changeValues( [
					/* Storefront */
					'gdcompliance_front_enabled'         => (int) (bool) $values['gdcompliance_front_enabled'],
					'gdcompliance_front_show_reasons'    => (int) (bool) $values['gdcompliance_front_show_reasons'],
					'gdcompliance_front_disclaimer'      => (string)     $values['gdcompliance_front_disclaimer'],

					/* Public Lookup */
					'gdcompliance_lookup_enabled'        => (int) (bool) $values['gdcompliance_lookup_enabled'],
					'gdcompliance_lookup_disclaimer'     => (string)     $values['gdcompliance_lookup_disclaimer'],
					'gdcompliance_lookup_available_note' => (string)     $values['gdcompliance_lookup_available_note'],
					'gdcompliance_lookup_csv_max'        => (int)        $values['gdcompliance_lookup_csv_max'],
					'gdcompliance_report_ratelimit'      => (int)        $values['gdcompliance_report_ratelimit'],

					/* CSV Export Gate */
					'gdcompliance_csv_allowed_groups'    => $csvGroupsStr,
					'gdcompliance_csv_upsell_url'        => (string)     $values['gdcompliance_csv_upsell_url'],
					'gdcompliance_csv_upsell_text'       => (string)     $values['gdcompliance_csv_upsell_text'],

					/* Compliance API */
					'gdcompliance_api_access_groups'     => $apiGroupsStr,
					'gdcompliance_api_subscription_id'   => (int)        $values['gdcompliance_api_subscription_id'],
					'gdcompliance_api_default_quota'     => (int)        $values['gdcompliance_api_default_quota'],
					'gdcompliance_api_burst_per_sec'     => (int)        $values['gdcompliance_api_burst_per_sec'],
					'gdcompliance_api_tiers'             => (string)     $values['gdcompliance_api_tiers'],
					'gdcompliance_api_disclaimer'        => (string)     $values['gdcompliance_api_disclaimer'],
					'gdcompliance_api_verified'          => (int) (bool) $values['gdcompliance_api_verified'],

					/* Rosters */
					'gdcompliance_ca_roster_url'         => (string)     $values['gdcompliance_ca_roster_url'],
					'gdcompliance_ma_roster_url'         => (string)     $values['gdcompliance_ma_roster_url'],
					'gdcompliance_md_roster_url'         => (string)     $values['gdcompliance_md_roster_url'],
					'gdcompliance_md_disapproved_url'    => (string)     $values['gdcompliance_md_disapproved_url'],
					'gdcompliance_dc_derive'             => (int) (bool) $values['gdcompliance_dc_derive'],
				] );
			}
			catch ( \Throwable ) {}

			try { \IPS\Session::i()->log( 'acplog__gdcompliance_settings_saved' ); }
			catch ( \Throwable ) {}

			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=settings' ),
				'saved'
			);
		}

		\IPS\Output::i()->title  = $lang->addToStack( 'gdcompliance_acp_settings_title' );
		\IPS\Output::i()->output = (string) $form;
	}
}

class settings extends _settings {}
