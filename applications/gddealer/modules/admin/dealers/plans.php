<?php
/**
 * @brief       GD Dealer Manager — Subscription Plans ACP Editor
 * @package     IPS Community Suite
 * @subpackage  GD Dealer Manager
 * @since       v1.0.174
 *
 * Admin-editable subscription plan content. Each plan (basic, pro,
 * enterprise, founding) stored as a JSON blob in core_sys_conf_settings:
 *
 *   gddealer_plan_basic_json
 *   gddealer_plan_pro_json
 *   gddealer_plan_enterprise_json
 *   gddealer_plan_founding_json
 *
 * Each JSON has shape:
 *   {
 *     "version": 1,
 *     "name":         "Basic",
 *     "price":        "$39 / mo",
 *     "tagline":      "For small shops getting started.",
 *     "sync_label":   "6-hour sync",
 *     "features":     ["Unlimited listings","6-hour sync",...],
 *     "color":        "#6b7280"
 *   }
 *
 * v1.0.174 only ships the editor. Settings are persisted but NOT yet
 * consumed by the dashboard subscription template (v175) or the join
 * page (v176). After installing v174 admins can edit the values; nothing
 * about consumer-facing pages changes until v175 lands.
 */

namespace IPS\gddealer\modules\admin\dealers;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _plans extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	/** Plan keys in display order (fixed). */
	protected static array $planKeys = [ 'basic', 'pro', 'enterprise', 'max', 'founding' ];

	/** Default plan content. Used on first install AND as fallback when settings
	 *  are missing/corrupt. Values match the v1.0.169 hardcoded content from
	 *  templates_10087.php and modules/front/dealers/join.php so that v1.0.174
	 *  install is visually invisible until admins edit. */
	protected static array $defaults = [
		'basic' => [
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
		],
		'pro' => [
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
		],
		'enterprise' => [
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
			],
			'color'      => '#7c3aed',
		],
		'max' => [
			'version'    => 1,
			'name'       => 'Max',
			'price'      => '$399 / mo',
			'tagline'    => 'For the biggest operations — unlimited everything.',
			'sync_label' => '3-hour sync',
			'features'   => [
				'Everything in Enterprise',
				'3-hour sync',
				'Unlimited listings',
				'Unlimited review disputes',
				'Priority support',
			],
			'color'      => '#dc2626',
		],
		'founding' => [
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
		],
	];

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'dealer_manage' );
		parent::execute();
	}

	/**
	 * Get the full plan data for a given key. Returns the saved JSON if valid,
	 * otherwise the default. Defensive against missing/malformed settings.
	 */
	public static function getPlan( string $key ): array
	{
		if ( !in_array( $key, self::$planKeys, true ) )
		{
			return [];
		}

		$settingKey = 'gddealer_plan_' . $key . '_json';
		$raw        = (string) ( \IPS\Settings::i()->{$settingKey} ?? '' );

		if ( $raw !== '' )
		{
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) && isset( $decoded['version'] ) )
			{
				/* Merge with defaults so missing fields don't crash consumers later */
				return array_replace( self::$defaults[ $key ], $decoded );
			}
		}

		return self::$defaults[ $key ];
	}

	/**
	 * Get all plans in display order.
	 */
	public static function getAllPlans(): array
	{
		$out = [];
		foreach ( self::$planKeys as $key )
		{
			$out[ $key ] = self::getPlan( $key );
		}
		return $out;
	}

	protected function manage()
	{
		$form = new \IPS\Helpers\Form;

		foreach ( self::$planKeys as $planKey )
		{
			$plan = self::getPlan( $planKey );

			$form->addHeader( 'gddealer_plan_section_' . $planKey );

			/* Name */
			$form->add( new \IPS\Helpers\Form\Text(
				'gddealer_plan_' . $planKey . '_name',
				(string) $plan['name'],
				TRUE,
				[ 'maxLength' => 50 ]
			) );

			/* Price */
			$form->add( new \IPS\Helpers\Form\Text(
				'gddealer_plan_' . $planKey . '_price',
				(string) $plan['price'],
				TRUE,
				[ 'maxLength' => 50, 'placeholder' => 'e.g. $39 / mo or "Custom pricing"' ]
			) );

			/* Tagline */
			$form->add( new \IPS\Helpers\Form\Text(
				'gddealer_plan_' . $planKey . '_tagline',
				(string) $plan['tagline'],
				FALSE,
				[ 'maxLength' => 200, 'placeholder' => 'For small shops getting started.' ]
			) );

			/* Sync label */
			$form->add( new \IPS\Helpers\Form\Text(
				'gddealer_plan_' . $planKey . '_sync_label',
				(string) $plan['sync_label'],
				FALSE,
				[ 'maxLength' => 50, 'placeholder' => '6-hour sync' ]
			) );

			/* Features (textarea, one per line) */
			$featuresStr = is_array( $plan['features'] ?? null )
				? implode( "\n", $plan['features'] )
				: '';
			$form->add( new \IPS\Helpers\Form\TextArea(
				'gddealer_plan_' . $planKey . '_features',
				$featuresStr,
				FALSE,
				[
					'rows'        => 6,
					'placeholder' => "One feature per line.\nExample:\nUnlimited listings\n6-hour sync\nBasic analytics",
				]
			) );

			/* Color */
			$form->add( new \IPS\Helpers\Form\Color(
				'gddealer_plan_' . $planKey . '_color',
				(string) $plan['color'],
				FALSE
			) );
		}

		if ( $values = $form->values() )
		{
			$saveSet = [];
			foreach ( self::$planKeys as $planKey )
			{
				/* Reconstruct features array from textarea */
				$rawFeatures = (string) ( $values[ 'gddealer_plan_' . $planKey . '_features' ] ?? '' );
				$features    = [];
				foreach ( preg_split( '/\r\n|\r|\n/', $rawFeatures ) as $line )
				{
					$line = trim( $line );
					if ( $line === '' )
					{
						continue;
					}

					/* HTML allowed but sanitized to safe tags via IPS Parser.
					 * If parseStatic isn't available or throws for any reason,
					 * fall back to strip_tags with a small allowlist. */
					try
					{
						$line = \IPS\Text\Parser::parseStatic( $line, NULL, NULL, 'gddealer_plan_features' );
					}
					catch ( \Throwable )
					{
						$line = strip_tags( $line, '<b><i><u><em><strong><a><br>' );
					}

					$features[] = $line;
				}

				$planJson = [
					'version'    => 1,
					'name'       => trim( (string) $values[ 'gddealer_plan_' . $planKey . '_name' ] ),
					'price'      => trim( (string) $values[ 'gddealer_plan_' . $planKey . '_price' ] ),
					'tagline'    => trim( (string) $values[ 'gddealer_plan_' . $planKey . '_tagline' ] ),
					'sync_label' => trim( (string) $values[ 'gddealer_plan_' . $planKey . '_sync_label' ] ),
					'features'   => $features,
					'color'      => trim( (string) $values[ 'gddealer_plan_' . $planKey . '_color' ] ) ?: self::$defaults[ $planKey ]['color'],
				];

				$saveSet[ 'gddealer_plan_' . $planKey . '_json' ] = json_encode( $planJson, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			}

			$form->saveAsSettings( $saveSet );

			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=plans' ),
				'saved'
			);
		}

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'menu__gddealer_dealers_plans' );
		\IPS\Output::i()->output = (string) $form;
	}
}

class plans extends _plans {}
