<?php
/**
 * @brief  GD Deals — Auto Deals ACP settings (Phase 3)
 *
 * Settings page for the auto-deal pipeline (DealEngine + DealPublisher
 * live in gddealer). Defaults match the Phase 1/2 hardcoded constants
 * so the page can be left untouched and the pipeline behaves identically.
 * Settings are read at compute time with constant-fallback, so a blank
 * setting can never break the pipeline.
 */

namespace IPS\gddeals\modules\admin\deals;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _autodeals extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'settings_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		$S    = \IPS\Settings::i();
		$form = new \IPS\Helpers\Form;

		/* ---- Generation ---- */
		$form->addHeader( 'gddeals_ad_generation' );
		$form->add( new \IPS\Helpers\Form\YesNo(  'gddeals_auto_enabled',    (int)    ( $S->gddeals_auto_enabled ?? 1 ) ) );
		$form->add( new \IPS\Helpers\Form\YesNo(  'gddeals_auto_approve',    (int)    ( $S->gddeals_auto_approve ?? 0 ) ) );
		$form->add( new \IPS\Helpers\Form\Number( 'gddeals_auto_cap',        (int)    ( $S->gddeals_auto_cap ?: 50 ), FALSE, [ 'min' => 1 ] ) );
		$form->add( new \IPS\Helpers\Form\Text(   'gddeals_auto_badge_label', (string) ( $S->gddeals_auto_badge_label ?: 'Auto Deal' ) ) );

		/* ---- Deal types (per-type on/off) ---- */
		$form->addHeader( 'gddeals_ad_types' );
		foreach ( [ 'lowest_ever', 'lowest_30d', 'msrp_off', 'price_drop', 'back_in_stock', 'rare_find', 'free_ship' ] as $t )
		{
			$key = 'gddeals_type_' . $t;
			$form->add( new \IPS\Helpers\Form\YesNo( $key, (int) ( $S->$key ?? 1 ) ) );
		}

		/* ---- Thresholds ---- */
		$form->addHeader( 'gddeals_ad_thresholds' );
		$form->add( new \IPS\Helpers\Form\Number( 'gddeals_thr_msrp_pct',   (float) ( $S->gddeals_thr_msrp_pct ?: 25 ),  FALSE, [ 'min' => 0, 'max' => 100, 'decimals' => 2 ] ) );
		$form->add( new \IPS\Helpers\Form\Number( 'gddeals_thr_drop_pct',   (float) ( $S->gddeals_thr_drop_pct ?: 15 ),  FALSE, [ 'min' => 0, 'max' => 100, 'decimals' => 2 ] ) );
		$form->add( new \IPS\Helpers\Form\Number( 'gddeals_thr_drop_hours', (int)   ( $S->gddeals_thr_drop_hours ?: 48 ), FALSE, [ 'min' => 1 ] ) );
		$form->add( new \IPS\Helpers\Form\Number( 'gddeals_thr_rare_max',   (int)   ( $S->gddeals_thr_rare_max ?: 3 ),    FALSE, [ 'min' => 1 ] ) );
		$form->add( new \IPS\Helpers\Form\Number( 'gddeals_thr_back_days',  (int)   ( $S->gddeals_thr_back_days ?: 14 ),  FALSE, [ 'min' => 1 ] ) );
		$form->add( new \IPS\Helpers\Form\Number( 'gddeals_thr_30d_days',   (int)   ( $S->gddeals_thr_30d_days ?: 30 ),   FALSE, [ 'min' => 1 ] ) );

		/* ---- Score weights ---- */
		$form->addHeader( 'gddeals_ad_weights' );
		$form->add( new \IPS\Helpers\Form\Number( 'gddeals_wt_msrp',         (float) ( $S->gddeals_wt_msrp ?: 1.0 ),         FALSE, [ 'min' => 0, 'decimals' => 2 ] ) );
		$form->add( new \IPS\Helpers\Form\Number( 'gddeals_wt_drop',         (float) ( $S->gddeals_wt_drop ?: 0.8 ),         FALSE, [ 'min' => 0, 'decimals' => 2 ] ) );
		$form->add( new \IPS\Helpers\Form\Number( 'gddeals_wt_drop_flag',    (float) ( $S->gddeals_wt_drop_flag ?: 10 ),     FALSE, [ 'min' => 0, 'decimals' => 2 ] ) );
		$form->add( new \IPS\Helpers\Form\Number( 'gddeals_wt_back',         (float) ( $S->gddeals_wt_back ?: 8 ),           FALSE, [ 'min' => 0, 'decimals' => 2 ] ) );
		$form->add( new \IPS\Helpers\Form\Number( 'gddeals_wt_freeship',     (float) ( $S->gddeals_wt_freeship ?: 6 ),       FALSE, [ 'min' => 0, 'decimals' => 2 ] ) );
		$form->add( new \IPS\Helpers\Form\Number( 'gddeals_wt_lowest_ever',  (float) ( $S->gddeals_wt_lowest_ever ?: 4 ),    FALSE, [ 'min' => 0, 'decimals' => 2 ] ) );
		$form->add( new \IPS\Helpers\Form\Number( 'gddeals_wt_lowest_30d',   (float) ( $S->gddeals_wt_lowest_30d ?: 2 ),     FALSE, [ 'min' => 0, 'decimals' => 2 ] ) );
		$form->add( new \IPS\Helpers\Form\Number( 'gddeals_wt_rare',         (float) ( $S->gddeals_wt_rare ?: 2 ),           FALSE, [ 'min' => 0, 'decimals' => 2 ] ) );

		/* ---- Front-page placement (which auto-deal types show on /deals) ---- */
		$form->addHeader( 'gddeals_ad_placement' );
		foreach ( [ 'lowest_ever', 'lowest_30d', 'msrp_off', 'price_drop', 'back_in_stock', 'rare_find', 'free_ship' ] as $t )
		{
			$key = 'gddeals_front_' . $t;
			$form->add( new \IPS\Helpers\Form\YesNo( $key, (int) ( $S->$key ?? 1 ) ) );
		}

		if ( $values = $form->values() )
		{
			$form->saveAsSettings( $values );
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddeals&module=deals&controller=autodeals' ),
				'saved'
			);
			return;
		}

		$recomputeUrl = (string) \IPS\Http\Url::internal( 'app=gddeals&module=deals&controller=autodeals&do=recompute' )->csrf();
		$recomputeBtn = '<div style="margin:0 0 16px"><a href="' . htmlspecialchars( $recomputeUrl, ENT_QUOTES ) . '" class="ipsButton ipsButton_primary">'
			. htmlspecialchars( (string) \IPS\Member::loggedIn()->language()->addToStack( 'gddeals_ad_recompute' ), ENT_QUOTES )
			. '</a> <span style="font-size:0.85em;color:#666;margin-left:8px">'
			. htmlspecialchars( (string) \IPS\Member::loggedIn()->language()->addToStack( 'gddeals_ad_recompute_hint' ), ENT_QUOTES )
			. '</span></div>';

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gddeals_ad_title' );
		\IPS\Output::i()->output = $recomputeBtn . (string) $form;
	}

	/**
	 * Recompute deal flags + publish auto-deals on demand.
	 * Cross-app call: gddeals ACP → gddealer Deals\* classes.
	 */
	protected function recompute(): void
	{
		\IPS\Session::i()->csrfCheck();

		$ranEngine = false;
		$ranPublish = false;
		$msg = 'gddeals_ad_recompute_done';

		try
		{
			if ( class_exists( '\\IPS\\gddealer\\Deals\\DealEngine' ) )
			{
				\IPS\gddealer\Deals\DealEngine::computeAll();
				$ranEngine = true;
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'autodeals.recompute engine: ' . $e->getMessage(), 'gddeals_autodeals' ); } catch ( \Throwable ) {}
		}

		try
		{
			if ( class_exists( '\\IPS\\gddealer\\Deals\\DealPublisher' ) )
			{
				\IPS\gddealer\Deals\DealPublisher::publish();
				$ranPublish = true;
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'autodeals.recompute publish: ' . $e->getMessage(), 'gddeals_autodeals' ); } catch ( \Throwable ) {}
		}

		if ( !$ranEngine || !$ranPublish )
		{
			$msg = 'gddeals_ad_recompute_partial';
		}

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gddeals&module=deals&controller=autodeals' ),
			$msg
		);
	}
}

class autodeals extends _autodeals {}
