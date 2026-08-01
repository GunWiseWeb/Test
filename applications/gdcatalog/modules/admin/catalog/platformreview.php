<?php
/**
 * @brief  GD Catalog — Platform Classifier ACP page (v1.0.113)
 *
 * Two roles:
 *   1. Dry-run + live-run entry points for the PlatformClassifier
 *      (walks all active category-1 products, reports counts, then
 *      commits reclassifications + review-queue rows on demand).
 *   2. Interactive review queue for ambiguous rows: Derrick can
 *      reassign a row to a target category, confirm the current
 *      category is correct, or add a curated override that will
 *      permanently steer future imports of the same pattern.
 *
 * The dry-run is MANDATORY before live-run in the recommended
 * workflow — but the controller doesn't ENFORCE that; Derrick can
 * hit run() directly if he already trusts a prior dry-run. Every
 * live-mode reclassification is logged to gd_catalog_platform_reclass_log
 * for after-the-fact audit / rollback.
 */

namespace IPS\gdcatalog\modules\admin\catalog;

use IPS\gdcatalog\Catalog\PlatformClassifier;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _platformreview extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'catalog_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		$flash = (string) ( \IPS\Request::i()->flash ?? '' );

		$reviewCount = 0;
		$reviewRows  = [];
		try
		{
			$reviewCount = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_catalog_platform_review', [ 'resolved=?', 0 ] )->first();
			foreach ( \IPS\Db::i()->select(
				'*', 'gd_catalog_platform_review',
				[ 'resolved=?', 0 ],
				'created_at DESC',
				[ 0, 100 ]
			) as $r )
			{
				$reviewRows[] = $r;
			}
		}
		catch ( \Throwable ) {}

		$overrideCount = 0;
		$overrideRows  = [];
		try
		{
			$overrideCount = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_catalog_platform_overrides' )->first();
			foreach ( \IPS\Db::i()->select( '*', 'gd_catalog_platform_overrides', [], 'created_at DESC', [ 0, 50 ] ) as $r )
			{
				$overrideRows[] = $r;
			}
		}
		catch ( \Throwable ) {}

		$logCount = 0;
		try { $logCount = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_catalog_platform_reclass_log' )->first(); } catch ( \Throwable ) {}

		$dryrunUrl   = (string) \IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=platformreview&do=dryrun' )->csrf();
		$runUrl      = (string) \IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=platformreview&do=run' )->csrf();
		$overrideUrl = (string) \IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=platformreview&do=override' )->csrf();
		$csrfKey     = \IPS\Session::i()->csrfKey;

		$labels = [ 1 => 'Handguns', 7 => 'Rifles', 16 => 'Shotguns' ];

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gdcatalog_platform_title' );
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'catalog', 'gdcatalog', 'admin' )->platformReview(
			$reviewCount, $reviewRows, $overrideCount, $overrideRows, $logCount,
			$dryrunUrl, $runUrl, $overrideUrl, $csrfKey, $labels, $flash
		);
	}

	protected function dryrun(): void
	{
		\IPS\Session::i()->csrfCheck();

		$stats = PlatformClassifier::runOnCategory1( false, 25 );

		$sampleLines = [];
		foreach ( $stats['sample'] as $s )
		{
			$sampleLines[] = sprintf( '  %s → cat %d [%s] %s',
				$s['upc'], $s['target'], $s['signal'], $s['title'] );
		}
		$msg = sprintf(
			'DRY RUN — total=%d | would→Rifles=%d, would→Shotguns=%d, would stay Handguns=%d, would review=%d, errors=%d',
			$stats['total'], $stats['would_reclassify_rifle'], $stats['would_reclassify_shotgun'],
			$stats['would_stay_handgun'], $stats['would_review'], $stats['errors']
		);
		if ( $sampleLines ) { $msg .= "\n\nSample (up to 25):\n" . implode( "\n", $sampleLines ); }

		$this->_backWithFlash( $msg );
	}

	protected function run(): void
	{
		\IPS\Session::i()->csrfCheck();

		$stats = PlatformClassifier::runOnCategory1( true );

		$msg = sprintf(
			'LIVE RUN COMPLETE — total=%d | reclassified→Rifles=%d, reclassified→Shotguns=%d, stayed Handguns=%d, routed to review=%d, errors=%d. See gd_catalog_platform_reclass_log for the full audit trail.',
			$stats['total'], $stats['would_reclassify_rifle'], $stats['would_reclassify_shotgun'],
			$stats['would_stay_handgun'], $stats['would_review'], $stats['errors']
		);
		$this->_backWithFlash( $msg );
	}

	protected function reassign(): void
	{
		\IPS\Session::i()->csrfCheck();

		$id      = (int) ( \IPS\Request::i()->id ?? 0 );
		$targetCat = (int) ( \IPS\Request::i()->target ?? 0 );
		$allowed = [ 1, 7, 16 ];

		if ( $id <= 0 || !in_array( $targetCat, $allowed, true ) )
		{
			$this->_backWithFlash( 'Invalid reassign request.' );
			return;
		}

		try
		{
			$row = \IPS\Db::i()->select( '*', 'gd_catalog_platform_review', [ 'id=?', $id ] )->first();
			$upc = (string) $row['upc'];
			$currentCat = (int) $row['current_category_id'];

			if ( $targetCat !== $currentCat )
			{
				\IPS\Db::i()->update( 'gd_catalog',
					[ 'category_id' => $targetCat, 'updated_at' => date( 'Y-m-d H:i:s' ) ],
					[ 'upc=?', $upc ]
				);
				\IPS\Db::i()->insert( 'gd_catalog_platform_reclass_log', [
					'upc'             => $upc,
					'old_category_id' => $currentCat,
					'new_category_id' => $targetCat,
					'source'          => 'admin-review',
					'signal'          => 'manual reassignment via review queue',
					'created_at'      => time(),
				] );
			}

			\IPS\Db::i()->update( 'gd_catalog_platform_review', [
				'resolved'              => 1,
				'suggested_category_id' => $targetCat,
			], [ 'id=?', $id ] );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'platformreview reassign: ' . $e->getMessage(), 'gdcatalog_platform' ); } catch ( \Throwable ) {}
			$this->_backWithFlash( 'Reassign failed: ' . $e->getMessage() );
			return;
		}

		$this->_backWithFlash( 'Reassigned UPC to category ' . $targetCat . '.' );
	}

	protected function confirm(): void
	{
		\IPS\Session::i()->csrfCheck();
		$id = (int) ( \IPS\Request::i()->id ?? 0 );
		if ( $id > 0 )
		{
			try { \IPS\Db::i()->update( 'gd_catalog_platform_review', [ 'resolved' => 1 ], [ 'id=?', $id ] ); }
			catch ( \Throwable ) {}
		}
		$this->_backWithFlash( 'Confirmed current category is correct.' );
	}

	protected function override(): void
	{
		\IPS\Session::i()->csrfCheck();
		$pattern    = trim( (string) ( \IPS\Request::i()->pattern ?? '' ) );
		$targetCat  = (int) ( \IPS\Request::i()->target_category_id ?? 0 );
		$note       = mb_substr( trim( (string) ( \IPS\Request::i()->note ?? '' ) ), 0, 255 );

		if ( $pattern === '' || !in_array( $targetCat, [ 1, 7, 16 ], true ) )
		{
			$this->_backWithFlash( 'Override needs a non-empty pattern and a target category (1/7/16).' );
			return;
		}

		try
		{
			\IPS\Db::i()->insert( 'gd_catalog_platform_overrides', [
				'pattern'            => mb_substr( $pattern, 0, 191 ),
				'target_category_id' => $targetCat,
				'note'               => $note ?: null,
				'created_at'         => time(),
			], TRUE );
		}
		catch ( \Throwable $e )
		{
			$this->_backWithFlash( 'Override insert failed: ' . $e->getMessage() );
			return;
		}
		$this->_backWithFlash( 'Curated override added.' );
	}

	protected function _backWithFlash( string $msg ): void
	{
		$url = \IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=platformreview' )
			->setQueryString( 'flash', $msg );
		\IPS\Output::i()->redirect( $url );
	}
}
class platformreview extends _platformreview {}
