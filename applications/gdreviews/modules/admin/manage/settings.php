<?php
/**
 * @brief  GD Reviews — ACP: Settings (v1.0.4, Stage 2 polish).
 *
 * Product-reviews back-office controls: which member groups may
 * submit reviews, whether new reviews auto-approve or hit a mod
 * queue, whether a text body is required, minimum text length,
 * and whether guests can view reviews at all.
 *
 * Group names are resolved via IPS's Group API — the
 * `\IPS\Member\Group::groups()` list. Never SELECT the group-
 * name column direct: it does not exist on core_groups in
 * IPS 5.0.18 (gdcompliance / gddealer lesson: that path throws
 * "Unknown column" and silently blanks the picker).
 */

namespace IPS\gdreviews\modules\admin\manage;

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
		\IPS\Dispatcher::i()->checkAcpPermission( 'reviews_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		$lang = \IPS\Member::loggedIn()->language();

		/* Build the group picker options from IPS's Group API — the
		   only correct way to render group NAMES on IPS 5.0.18. */
		$groupOptions = [];
		try
		{
			foreach ( \IPS\Member\Group::groups( TRUE, FALSE ) as $g )
			{
				$groupOptions[ (int) $g->g_id ] = (string) $g->name;
			}
		}
		catch ( \Throwable ) {}

		$currentGroups = array_filter( array_map( 'intval', explode( ',',
			(string) \IPS\Settings::i()->gdreviews_reviewer_groups ) ) );

		$form = new \IPS\Helpers\Form( 'form', 'save' );

		$form->add( new \IPS\Helpers\Form\Select( 'gdreviews_reviewer_groups', $currentGroups, FALSE, [
			'options'  => $groupOptions,
			'multiple' => TRUE,
			'noDefault'=> TRUE,
		], NULL, NULL, NULL, 'gdreviews_reviewer_groups' ) );

		$form->add( new \IPS\Helpers\Form\Radio( 'gdreviews_approval_mode',
			(string) ( \IPS\Settings::i()->gdreviews_approval_mode ?: 'immediate' ), TRUE, [
				'options' => [
					'immediate' => 'gdreviews_approval_immediate',
					'moderate'  => 'gdreviews_approval_moderate',
				],
			], NULL, NULL, NULL, 'gdreviews_approval_mode' ) );

		$form->add( new \IPS\Helpers\Form\YesNo( 'gdreviews_require_text',
			(bool) \IPS\Settings::i()->gdreviews_require_text, FALSE,
			[], NULL, NULL, NULL, 'gdreviews_require_text' ) );

		$form->add( new \IPS\Helpers\Form\Number( 'gdreviews_min_length',
			(int) \IPS\Settings::i()->gdreviews_min_length, FALSE,
			[ 'min' => 0, 'max' => 5000 ], NULL, NULL, NULL, 'gdreviews_min_length' ) );

		$form->add( new \IPS\Helpers\Form\YesNo( 'gdreviews_guest_view',
			(bool) \IPS\Settings::i()->gdreviews_guest_view, FALSE,
			[], NULL, NULL, NULL, 'gdreviews_guest_view' ) );

		if ( $values = $form->values() )
		{
			$groupIds = [];
			if ( !empty( $values['gdreviews_reviewer_groups'] ) )
			{
				$groupIds = array_filter( array_map( 'intval', (array) $values['gdreviews_reviewer_groups'] ) );
			}

			$mode = (string) ( $values['gdreviews_approval_mode'] ?? 'immediate' );
			if ( !in_array( $mode, [ 'immediate', 'moderate' ], TRUE ) ) { $mode = 'immediate'; }

			$minLen = max( 0, min( 5000, (int) ( $values['gdreviews_min_length'] ?? 10 ) ) );

			try
			{
				\IPS\Settings::i()->changeValues( [
					'gdreviews_reviewer_groups' => implode( ',', $groupIds ),
					'gdreviews_approval_mode'   => $mode,
					'gdreviews_require_text'    => (bool) $values['gdreviews_require_text'] ? 1 : 0,
					'gdreviews_min_length'      => $minLen,
					'gdreviews_guest_view'      => (bool) $values['gdreviews_guest_view'] ? 1 : 0,
				] );
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'gdreviews settings save: ' . $e->getMessage(), 'gdreviews' ); } catch ( \Throwable ) {}
			}

			\IPS\Output::i()->redirect(
				(string) \IPS\Http\Url::internal( 'app=gdreviews&module=manage&controller=settings' ),
				'saved'
			);
			return;
		}

		\IPS\Output::i()->title  = $lang->addToStack( 'menu__gdreviews_manage_settings' );
		\IPS\Output::i()->output = (string) $form;
	}
}

class settings extends _settings {}
