<?php
/**
 * @brief       GD Master Catalog — ACP Compliance Review Panel
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 * @since       12 Apr 2026
 *
 * Unified compliance review panel (Section 2.11.3) with 4 tabs:
 * New restrictions | Feed conflicts | Locked fields | Admin restrictions
 */

namespace IPS\gdcatalog\modules\admin\catalog;

/* To prevent PHP errors (extending class does not exist) revealing path */

use IPS\gdcatalog\Compliance\Flag;
use IPS\gdcatalog\Compliance\FlagProcessor;
use IPS\gdcatalog\Conflict\FeedConflict;
use IPS\gdcatalog\Conflict\FieldLock;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _compliance extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'catalog_manage' );
		parent::execute();
	}

	/**
	 * Tabbed compliance review panel.
	 *
	 * All row-level data is flattened into scalar arrays and all dynamic
	 * URLs are pre-built in the controller so the template can render with
	 * plain `{$row['key']}` / `<a href="{$row['url']}">` patterns. See Rule
	 * #12 — the IPS template compiler rejects nested `{url="...{$x->id}"}`
	 * tokens with UnexpectedValueException.
	 */
	protected function manage()
	{
		$tab = \IPS\Request::i()->tab ?? 'new';

		$rawPendingFlags     = Flag::loadPending();
		$rawPendingConflicts = FeedConflict::loadPending();
		$rawAllLocks         = FieldLock::loadAllLocks();
		$rawAdminFlags       = Flag::loadAdminSet();

		$counts = [
			'new'       => \count( $rawPendingFlags ),
			'conflicts' => \count( $rawPendingConflicts ),
			'locks'     => \count( $rawAllLocks ),
			'admin'     => \count( $rawAdminFlags ),
		];

		/* Pre-built tab URLs */
		$tabUrls = [
			'new'       => (string) \IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=compliance&tab=new' ),
			'conflicts' => (string) \IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=compliance&tab=conflicts' ),
			'locks'     => (string) \IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=compliance&tab=locks' ),
			'admin'     => (string) \IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=compliance&tab=admin' ),
		];

		/* Pending flags */
		$pendingFlags = [];
		foreach ( $rawPendingFlags as $flag )
		{
			$pendingFlags[] = [
				'upc'             => (string) ( $flag->upc ?? '' ),
				'flag_type'       => self::formatType( (string) ( $flag->flag_type ?? '' ) ),
				'flag_value'      => (string) ( $flag->flag_value ?? '' ),
				'distributor_id'  => (string) ( $flag->distributor_id ?? '' ),
				'first_seen_at'   => $flag->first_seen_at ?? null,
				'approve_url'     => (string) \IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=compliance&do=approve&id=' . (int) $flag->id )->csrf(),
				'reject_url'      => (string) \IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=compliance&do=reject&id=' . (int) $flag->id )->csrf(),
			];
		}

		/* Pending feed conflicts */
		$pendingConflicts = [];
		foreach ( $rawPendingConflicts as $conflict )
		{
			$pendingConflicts[] = [
				'upc'             => (string) ( $conflict->upc ?? '' ),
				'field_name'      => (string) ( $conflict->field_name ?? '' ),
				'current_value'   => htmlspecialchars( mb_substr( (string) ( $conflict->current_value ?? '' ), 0, 60 ) ),
				'incoming_value'  => htmlspecialchars( mb_substr( (string) ( $conflict->incoming_value ?? '' ), 0, 60 ) ),
				'auto_resolve_at' => $conflict->auto_resolve_at ?? null,
				'accept_url'      => (string) \IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=compliance&do=acceptConflict&id=' . (int) $conflict->id )->csrf(),
				'keep_url'        => (string) \IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=compliance&do=keepConflict&id=' . (int) $conflict->id )->csrf(),
				'custom_url'      => (string) \IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=compliance&do=customConflict&id=' . (int) $conflict->id )->csrf(),
			];
		}

		/* All locks */
		$allLocks = [];
		foreach ( $rawAllLocks as $lock )
		{
			$allLocks[] = [
				'upc'          => (string) ( $lock->upc ?? '' ),
				'field_name'   => (string) ( $lock->field_name ?? '' ),
				'locked_value' => htmlspecialchars( mb_substr( (string) ( $lock->locked_value ?? '' ), 0, 60 ) ),
				'is_hard_lock' => (bool) $lock->isHardLock(),
				'lock_reason'  => htmlspecialchars( mb_substr( (string) ( $lock->lock_reason ?? '' ), 0, 80 ) ),
				'locked_at'    => $lock->locked_at ?? null,
				'unlock_url'   => (string) \IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=compliance&do=unlock&id=' . (int) $lock->id )->csrf(),
			];
		}

		/* Admin-set flags */
		$adminFlags = [];
		foreach ( $rawAdminFlags as $flag )
		{
			$adminFlags[] = [
				'upc'                => (string) ( $flag->upc ?? '' ),
				'listing_id'         => (int) ( $flag->listing_id ?? 0 ),
				'flag_type'          => self::formatType( (string) ( $flag->flag_type ?? '' ) ),
				'flag_value'         => (string) ( $flag->flag_value ?? '' ),
				'admin_reviewed_by'  => (string) ( $flag->admin_reviewed_by ?? '' ),
				'admin_reviewed_at'  => $flag->admin_reviewed_at ?? null,
				'source'             => self::formatSource( (string) ( $flag->source ?? '' ) ),
			];
		}

		$addRestrictionUrl = (string) \IPS\Http\Url::internal(
			'app=gdcatalog&module=catalog&controller=compliance&do=addRestriction'
		)->csrf();

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gdcatalog_compliance_title' );
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'catalog', 'gdcatalog', 'admin' )->compliancePanel(
			$tab, $counts, $tabUrls, $pendingFlags, $pendingConflicts, $allLocks, $adminFlags, $addRestrictionUrl
		);
	}

	/**
	 * Approve a pending compliance flag.
	 */
	protected function approve()
	{
		\IPS\Session::i()->csrfCheck();
		$flagId = (int) \IPS\Request::i()->id;
		FlagProcessor::approve( $flagId, \IPS\Member::loggedIn()->member_id );

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=compliance&tab=new' ),
			'Flag approved — badge is now live'
		);
	}

	/**
	 * Reject a pending compliance flag.
	 */
	protected function reject()
	{
		\IPS\Session::i()->csrfCheck();
		$flagId = (int) \IPS\Request::i()->id;
		FlagProcessor::reject( $flagId, \IPS\Member::loggedIn()->member_id );

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=compliance&tab=new' ),
			'Flag rejected'
		);
	}

	/**
	 * Accept incoming value on a feed conflict.
	 */
	protected function acceptConflict()
	{
		\IPS\Session::i()->csrfCheck();
		$conflict = FeedConflict::load( (int) \IPS\Request::i()->id );
		$conflict->acceptIncoming( \IPS\Member::loggedIn()->member_id );

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=compliance&tab=conflicts' ),
			'Incoming value accepted'
		);
	}

	/**
	 * Keep existing value on a feed conflict (creates distributor-specific lock).
	 */
	protected function keepConflict()
	{
		\IPS\Session::i()->csrfCheck();

		$conflict = FeedConflict::load( (int) \IPS\Request::i()->id );

		/* Lock reason is required */
		if ( empty( \IPS\Request::i()->reason ) )
		{
			/* Show a form to collect the reason */
			$form = new \IPS\Helpers\Form;
			$form->add( new \IPS\Helpers\Form\TextArea( 'gdcatalog_compliance_lock_reason', '', TRUE ) );

			if ( $values = $form->values() )
			{
				$conflict->keepExisting( \IPS\Member::loggedIn()->member_id, $values['gdcatalog_compliance_lock_reason'] );

				\IPS\Output::i()->redirect(
					\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=compliance&tab=conflicts' ),
					'Existing value kept — field locked for this distributor'
				);
				return;
			}

			\IPS\Output::i()->title  = 'Keep Existing — Lock Reason Required';
			\IPS\Output::i()->output = (string) $form;
			return;
		}

		$conflict->keepExisting( \IPS\Member::loggedIn()->member_id, \IPS\Request::i()->reason );

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=compliance&tab=conflicts' ),
			'Existing value kept — field locked for this distributor'
		);
	}

	/**
	 * Set a custom value on a feed conflict (creates hard lock).
	 */
	protected function customConflict()
	{
		\IPS\Session::i()->csrfCheck();

		$conflict = FeedConflict::load( (int) \IPS\Request::i()->id );

		$form = new \IPS\Helpers\Form;
		$form->add( new \IPS\Helpers\Form\Text( 'gdcatalog_custom_value', '', TRUE ) );
		$form->add( new \IPS\Helpers\Form\TextArea( 'gdcatalog_compliance_lock_reason', '', TRUE ) );

		if ( $values = $form->values() )
		{
			$conflict->setCustom(
				\IPS\Member::loggedIn()->member_id,
				$values['gdcatalog_custom_value'],
				$values['gdcatalog_compliance_lock_reason']
			);

			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=compliance&tab=conflicts' ),
				'Custom value applied — field hard locked'
			);
			return;
		}

		\IPS\Output::i()->title  = 'Set Custom Value';
		\IPS\Output::i()->output = (string) $form;
	}

	/**
	 * Unlock a field lock.
	 */
	protected function unlock()
	{
		\IPS\Session::i()->csrfCheck();
		$lock = FieldLock::load( (int) \IPS\Request::i()->id );
		$lock->unlock();

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=compliance&tab=locks' ),
			'Field unlocked — next import will update normally'
		);
	}

	/**
	 * Create an admin-set state restriction.
	 */
	protected function addRestriction()
	{
		\IPS\Session::i()->csrfCheck();

		$form = new \IPS\Helpers\Form;
		$form->add( new \IPS\Helpers\Form\Text( 'gdcatalog_product_upc', '', TRUE ) );
		$form->add( new \IPS\Helpers\Form\Text( 'gdcatalog_restriction_states', '', TRUE, [
			'placeholder' => 'CA,NY,MA',
		] ) );

		if ( $values = $form->values() )
		{
			FlagProcessor::createAdminFlag(
				trim( $values['gdcatalog_product_upc'] ),
				null,
				'state_restriction',
				strtoupper( trim( $values['gdcatalog_restriction_states'] ) ),
				\IPS\Member::loggedIn()->member_id
			);

			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=compliance&tab=admin' ),
				'Admin restriction created'
			);
			return;
		}

		\IPS\Output::i()->title  = 'Add State Restriction';
		\IPS\Output::i()->output = (string) $form;
	}

	/**
	 * Map raw flag_type enum values to human-readable labels.
	 */
	private static function formatType( string $val ): string
	{
		return match( $val ) {
			'state_restriction' => 'State Restriction',
			'ffl_required'      => 'FFL Required',
			'age_restriction'   => 'Age Restriction',
			'hazmat'            => 'Hazmat',
			'consumer_banned'   => 'Consumer Banned',
			default             => ucwords( str_replace( '_', ' ', $val ) ),
		};
	}

	/**
	 * Map raw source enum values to human-readable labels.
	 */
	private static function formatSource( string $val ): string
	{
		return match( $val ) {
			'admin_manual'    => 'Admin (Manual)',
			'admin_override'  => 'Admin (Override)',
			'distributor'     => 'Distributor Feed',
			default           => ucwords( str_replace( '_', ' ', $val ) ),
		};
	}
}

class compliance extends _compliance {}
