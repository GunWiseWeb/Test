<?php
namespace IPS\gddealer\setup\upg_10174;

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
		/* v1.0.174 - Subscription Plans admin editor (Phase 1 of 3).
		 *
		 * This phase ships the admin editor and storage only. Settings are
		 * persisted but not yet consumed by consumer-facing pages. Phase 2
		 * (v1.0.175) will rewire the dashboard subscription template to read
		 * from these settings; Phase 3 (v1.0.176) will rewire join.php.
		 *
		 * Two-stage upgrade:
		 *   1. Seed lang strings into core_sys_lang_words for existing installs
		 *      (lang.xml only runs on fresh install per CLAUDE.md rule #5/#39).
		 *   2. Seed default plan JSON values matching the v1.0.169 hardcoded
		 *      content. Existing installs see no visual change. Defaults are
		 *      only written if the setting doesn't already exist OR is empty
		 *      (idempotent re-run safe).
		 */

		/* ---- Stage 1: lang strings ---- */
		/* Per CLAUDE.md rule #43: only 6-column shape for IPS 5.0.18 schema.
		 * Per CLAUDE.md rule #44: per-row try/catch so one failure doesn't
		 * poison the rest of the loop. */
		$newStrings = [
			'menu__gddealer_dealers_plans'        => 'Subscription Plans',
			'gddealer_plan_section_basic'         => 'Basic Plan',
			'gddealer_plan_section_pro'           => 'Pro Plan',
			'gddealer_plan_section_enterprise'    => 'Enterprise Plan',
			'gddealer_plan_section_founding'      => 'Founding Plan (info display only)',
			'gddealer_plan_basic_name'            => 'Display Name',
			'gddealer_plan_basic_price'           => 'Price Display',
			'gddealer_plan_basic_tagline'         => 'Audience Tagline',
			'gddealer_plan_basic_sync_label'      => 'Sync Frequency Label',
			'gddealer_plan_basic_features'        => 'Features (one per line, basic HTML allowed)',
			'gddealer_plan_basic_color'           => 'Accent Color',
			'gddealer_plan_pro_name'              => 'Display Name',
			'gddealer_plan_pro_price'             => 'Price Display',
			'gddealer_plan_pro_tagline'           => 'Audience Tagline',
			'gddealer_plan_pro_sync_label'        => 'Sync Frequency Label',
			'gddealer_plan_pro_features'          => 'Features (one per line, basic HTML allowed)',
			'gddealer_plan_pro_color'             => 'Accent Color',
			'gddealer_plan_enterprise_name'       => 'Display Name',
			'gddealer_plan_enterprise_price'      => 'Price Display',
			'gddealer_plan_enterprise_tagline'    => 'Audience Tagline',
			'gddealer_plan_enterprise_sync_label' => 'Sync Frequency Label',
			'gddealer_plan_enterprise_features'   => 'Features (one per line, basic HTML allowed)',
			'gddealer_plan_enterprise_color'      => 'Accent Color',
			'gddealer_plan_founding_name'         => 'Display Name',
			'gddealer_plan_founding_price'        => 'Price Display',
			'gddealer_plan_founding_tagline'      => 'Audience Tagline',
			'gddealer_plan_founding_sync_label'   => 'Sync Frequency Label',
			'gddealer_plan_founding_features'     => 'Features (one per line, basic HTML allowed)',
			'gddealer_plan_founding_color'        => 'Accent Color',
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
							'word_app'     => 'gddealer',
							'word_key'     => $key,
							'word_default' => $val,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
					catch ( \Throwable $rowException )
					{
						try { \IPS\Log::log( 'v1.0.174 lang seed failed key=' . $key . ': ' . $rowException->getMessage(), 'gddealer_upg_10174' ); } catch ( \Throwable ) {}
					}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'v1.0.174 lang seed outer failed: ' . $e->getMessage(), 'gddealer_upg_10174' ); } catch ( \Throwable ) {}
		}

		/* ---- Stage 2: default plan JSON values ---- */
		$defaults = [
			'gddealer_plan_basic_json' => json_encode( [
				'version'    => 1,
				'name'       => 'Basic',
				'price'      => '$39 / mo',
				'tagline'    => 'For small shops getting started.',
				'sync_label' => '6-hour sync',
				'features'   => [
					'Unlimited listings',
					'6-hour sync',
					'Basic analytics',
					'2 review disputes per month',
				],
				'color'      => '#6b7280',
			], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),

			'gddealer_plan_pro_json' => json_encode( [
				'version'    => 1,
				'name'       => 'Pro',
				'price'      => '$99 / mo',
				'tagline'    => 'For dealers serious about competing.',
				'sync_label' => '30-minute sync',
				'features'   => [
					'Everything in Basic',
					'30-minute sync',
					'Full analytics & opportunities',
					'5 review disputes per month',
					'Priority placement',
				],
				'color'      => '#2563eb',
			], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),

			'gddealer_plan_enterprise_json' => json_encode( [
				'version'    => 1,
				'name'       => 'Enterprise',
				'price'      => '$249 / mo',
				'tagline'    => 'For high-volume dealers.',
				'sync_label' => '15-minute sync',
				'features'   => [
					'Everything in Pro',
					'15-minute sync',
					'Unlimited review disputes',
					'Dedicated onboarding',
					'API access + custom branding',
				],
				'color'      => '#7c3aed',
			], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),

			'gddealer_plan_founding_json' => json_encode( [
				'version'    => 1,
				'name'       => 'Founder',
				'price'      => 'Founding partner',
				'tagline'    => 'Founding partner · all features unlocked.',
				'sync_label' => 'Enterprise-tier sync',
				'features'   => [
					'All Enterprise features',
					'Faster sync than your paid tier',
					'Permanent Founder badge',
					'Discounts on paid tier (configurable in Commerce)',
				],
				'color'      => '#b45309',
			], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
		];

		foreach ( $defaults as $key => $defaultValue )
		{
			try
			{
				/* Only write the default if the setting is missing or empty.
				 * Re-running this upgrade on an install where admin has already
				 * customized values must NOT clobber those customizations. */
				$existing = '';
				try
				{
					$existing = (string) ( \IPS\Settings::i()->{$key} ?? '' );
				}
				catch ( \Throwable ) { /* setting not yet registered */ }

				if ( $existing === '' || $existing === '0' )
				{
					\IPS\Db::i()->replace( 'core_sys_conf_settings', [
						'conf_key'         => $key,
						'conf_value'       => $defaultValue,
						'conf_default'     => $defaultValue,
						'conf_app'         => 'gddealer',
					] );
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'v1.0.174 setting seed failed for ' . $key . ': ' . $e->getMessage(), 'gddealer_upg_10174' ); } catch ( \Throwable ) {}
			}
		}

		/* Cache invalidation */
		try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'settings%' OR store_key LIKE 'theme_%' OR store_key LIKE 'template_%' OR store_key LIKE 'lang_%'" ] ); } catch ( \Throwable ) {}

		foreach ( glob( \IPS\ROOT_PATH . '/datastore/lang_*' ) ?: [] as $f )
		{
			@unlink( $f );
		}

		try { unset( \IPS\Data\Store::i()->settings );     } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions );   } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll();            } catch ( \Throwable ) {}

		return TRUE;
	}

	public function step1CustomTitle()
	{
		return 'v1.0.174 - subscription plans admin editor + default JSON seed (Phase 1 of 3)';
	}
}

class upgrade extends _upgrade {}
