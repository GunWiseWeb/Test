<?php
/**
 * @brief  GD Rebates — Manual "Add Rebate" ACP tool (v1.0.9)
 *
 * Skips the parser + review queue: writes directly to gd_rebates
 * with status='approved', source_id=NULL, approved_by=admin's
 * member_id, approved_at=now — the rebate is live on /rebates/
 * the moment the form is saved.
 *
 * Uses the SAME dedupe_hash formula the parser uses in
 * sources/Parser.php insertRebates() (~line 186):
 *   sha1( manufacturer . '|' . title . '|' . end_date_string )
 * so a manual entry participates in the same dedupe system —
 * a later parser run that finds the same rebate will skip it
 * instead of duplicating.
 */

namespace IPS\gdrebates\modules\admin\rebates;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _manualadd extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	/* Full rebate_type enum the front-end filter renders — wider
	   than the parser's sanitizeType() allowlist (which collapses
	   uncommon types to 'other') because manual entries should be
	   able to pick the exact type that renders the correct chip. */
	protected const TYPES = [
		'cash'          => 'gdrebates_type_cash',
		'percent'       => 'gdrebates_type_percent',
		'gift_card'     => 'gdrebates_type_gift_card',
		'prepaid_card'  => 'gdrebates_type_prepaid_card',
		'store_credit'  => 'gdrebates_type_store_credit',
		'free_item'     => 'gdrebates_type_free_item',
		'free_shipping' => 'gdrebates_type_free_shipping',
		'bundle'        => 'gdrebates_type_bundle',
		'other'         => 'gdrebates_type_other',
	];

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'manualadd_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		$form = new \IPS\Helpers\Form( 'form', 'gdrebates_manual_save' );

		$form->addHeader( 'gdrebates_manual_h_required' );
		$form->add( new \IPS\Helpers\Form\Text( 'gdrebates_manual_manufacturer', '', TRUE, [ 'maxLength' => 120 ] ) );
		$form->add( new \IPS\Helpers\Form\Text( 'gdrebates_manual_title', '', TRUE, [ 'maxLength' => 255 ] ) );
		$form->add( new \IPS\Helpers\Form\Select( 'gdrebates_manual_rebate_type', 'cash', TRUE, [ 'options' => self::TYPES ] ) );

		$form->addHeader( 'gdrebates_manual_h_amount' );
		$form->add( new \IPS\Helpers\Form\Number( 'gdrebates_manual_amount', 0, FALSE, [ 'decimals' => 2, 'unlimited' => 0, 'unlimitedLang' => 'gdrebates_manual_amount_na' ] ) );
		$form->add( new \IPS\Helpers\Form\Text( 'gdrebates_manual_amount_text', '', FALSE, [ 'maxLength' => 80, 'placeholder' => '$50 off / FREE Optic' ] ) );

		$form->addHeader( 'gdrebates_manual_h_dates' );
		$form->add( new \IPS\Helpers\Form\Date( 'gdrebates_manual_start_date', NULL, FALSE ) );
		$form->add( new \IPS\Helpers\Form\Date( 'gdrebates_manual_end_date',   NULL, FALSE ) );
		$form->add( new \IPS\Helpers\Form\Date( 'gdrebates_manual_submit_by',  NULL, FALSE ) );

		$form->addHeader( 'gdrebates_manual_h_models' );
		$form->add( new \IPS\Helpers\Form\TextArea( 'gdrebates_manual_eligible_models', '', FALSE, [ 'rows' => 4 ], NULL, NULL, NULL, 'gdrebates_manual_eligible_models' ) );

		$form->addHeader( 'gdrebates_manual_h_urls' );
		$form->add( new \IPS\Helpers\Form\Url( 'gdrebates_manual_redemption_url', '', FALSE ) );
		$form->add( new \IPS\Helpers\Form\Url( 'gdrebates_manual_source_url',     '', FALSE ) );
		$form->add( new \IPS\Helpers\Form\Url( 'gdrebates_manual_image_url',      '', FALSE ) );
		$form->add( new \IPS\Helpers\Form\Url( 'gdrebates_manual_pdf_url',        '', FALSE ) );

		if ( $values = $form->values() )
		{
			$mfr   = trim( (string) $values['gdrebates_manual_manufacturer'] );
			$title = trim( (string) $values['gdrebates_manual_title'] );

			if ( $mfr === '' || $title === '' )
			{
				$form->error = \IPS\Member::loggedIn()->language()->addToStack( 'gdrebates_manual_err_required' );
			}
			else
			{
				$rebateType = (string) $values['gdrebates_manual_rebate_type'];
				if ( !array_key_exists( $rebateType, self::TYPES ) ) { $rebateType = 'other'; }

				$amountRaw = $values['gdrebates_manual_amount'];
				$amount    = is_numeric( $amountRaw ) && (float) $amountRaw > 0 ? (float) $amountRaw : NULL;

				/* IPS Form\Date returns \IPS\DateTime|NULL depending on
				   the widget state; normalise to a unix ts or NULL for
				   the DB. */
				$toTs = function( $v ) : ?int {
					if ( $v instanceof \IPS\DateTime ) { return $v->getTimestamp(); }
					if ( is_int( $v ) )                { return $v > 0 ? $v : NULL; }
					if ( is_string( $v ) && $v !== '' ) { $ts = strtotime( $v ); return $ts !== false ? $ts : NULL; }
					return NULL;
				};

				$endTs = $toTs( $values['gdrebates_manual_end_date'] );

				/* Match the parser's dedupe_hash formula EXACTLY — see
				   sources/Parser.php insertRebates() line ~186:
				     sha1( $mfr . '|' . $title . '|' . $end_date_string )
				   where $end_date_string is the parser's flatten()'d
				   raw string (e.g. "2026-11-13" or "") before parseDate.
				   Manual form uses the ISO 'Y-m-d' rendering of the
				   picked date when non-null; else '' to match the
				   parser's "no end date" shape. */
				$hashEnd = $endTs !== NULL ? date( 'Y-m-d', $endTs ) : '';
				$hash    = sha1( $mfr . '|' . $title . '|' . $hashEnd );

				/* Refuse to duplicate — surface a clear error so the
				   admin can either edit the existing row (via a future
				   edit tool) or change the title to disambiguate. */
				try
				{
					$existing = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_rebates', [ 'dedupe_hash=?', $hash ] )->first();
				}
				catch ( \Throwable ) { $existing = 0; }
				if ( $existing > 0 )
				{
					$form->error = \IPS\Member::loggedIn()->language()->addToStack( 'gdrebates_manual_err_dupe' );
				}
				else
				{
					$adminId = (int) \IPS\Member::loggedIn()->member_id;
					$now     = time();

					$row = [
						'source_id'      => NULL,
						'manufacturer'   => mb_substr( $mfr, 0, 120 ),
						'title'          => mb_substr( $title, 0, 255 ),
						'rebate_type'    => $rebateType,
						'amount'         => $amount,
						'amount_text'    => mb_substr( trim( (string) $values['gdrebates_manual_amount_text'] ), 0, 80 ),
						'eligible_models'=> trim( (string) $values['gdrebates_manual_eligible_models'] ),
						'start_date'     => $toTs( $values['gdrebates_manual_start_date'] ),
						'end_date'       => $endTs,
						'submit_by'      => $toTs( $values['gdrebates_manual_submit_by'] ),
						'redemption_url' => mb_substr( trim( (string) $values['gdrebates_manual_redemption_url'] ), 0, 500 ),
						'source_url'     => mb_substr( trim( (string) $values['gdrebates_manual_source_url'] ),     0, 500 ),
						'image_url'      => mb_substr( trim( (string) $values['gdrebates_manual_image_url'] ),      0, 500 ),
						'pdf_url'        => mb_substr( trim( (string) $values['gdrebates_manual_pdf_url'] ),        0, 500 ),

						/* SKIPS THE REVIEW QUEUE — approved immediately. */
						'status'         => 'approved',
						'approved_by'    => $adminId,
						'approved_at'    => $now,

						'dedupe_hash'    => $hash,
						'raw_extract'    => json_encode( [ 'manual' => true, 'created_by' => $adminId ], JSON_UNESCAPED_SLASHES ),
						'created'        => $now,
						'updated'        => $now,
					];

					try
					{
						\IPS\Db::i()->insert( 'gd_rebates', $row );
					}
					catch ( \Throwable $e )
					{
						try { \IPS\Log::log( 'gdrebates manual add: ' . $e->getMessage(), 'gdrebates' ); } catch ( \Throwable ) {}
						$form->error = 'DB insert failed: ' . $e->getMessage();
					}

					if ( !isset( $form->error ) || $form->error === '' )
					{
						$browseUrl = (string) \IPS\Http\Url::internal( 'app=gdrebates&module=rebates&controller=browse', 'front' );
						\IPS\Output::i()->redirect(
							\IPS\Http\Url::internal( 'app=gdrebates&module=rebates&controller=manualadd' ),
							\IPS\Member::loggedIn()->language()->addToStack( 'gdrebates_manual_saved', FALSE, [ 'sprintf' => [ $browseUrl ] ] )
						);
						return;
					}
				}
			}
		}

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'menu__gdrebates_rebates_manualadd' );
		\IPS\Output::i()->output = (string) $form;
	}
}
class manualadd extends _manualadd {}
