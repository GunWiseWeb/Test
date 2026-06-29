<?php
namespace IPS\gdbills\modules\admin\bills;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _import extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'bills_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		$form = new \IPS\Helpers\Form( 'gdbills_import', 'gdbills_acp_import_run' );
		$form->add( new \IPS\Helpers\Form\Upload( 'gdbills_acp_import_file', null, TRUE, [
			'allowedFileTypes' => [ 'csv', 'txt' ],
			'maxFileSize'      => 16,
			'temporary'        => true,
		] ) );

		$summary = '';
		if ( $values = $form->values() )
		{
			$file = $values['gdbills_acp_import_file'] ?? null;
			if ( $file instanceof \IPS\File )
			{
				$counts = self::processCsv( (string) $file->contents() );
				$msg = (string) \IPS\Member::loggedIn()->language()->addToStack( 'gdbills_acp_import_summary', false, [
					'sprintf' => [ $counts['total'], $counts['new'], $counts['updated'], $counts['errors'] ],
				] );
				$summary = '<div class="ipsMessage ipsMessage_success">' . htmlspecialchars( $msg, ENT_QUOTES ) . '</div>';
				try { $file->delete(); } catch ( \Throwable ) {}
			}
		}

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gdbills_acp_import_title' );
		\IPS\Output::i()->output = '<p>' . htmlspecialchars( (string) \IPS\Member::loggedIn()->language()->addToStack( 'gdbills_acp_import_intro' ), ENT_QUOTES ) . '</p>'
			. $summary
			. (string) $form;
	}

	protected static function processCsv( string $body ): array
	{
		$counts = [ 'total' => 0, 'new' => 0, 'updated' => 0, 'errors' => 0 ];
		$rows = preg_split( "/\r\n|\r|\n/", trim( $body ) );
		if ( empty( $rows ) ) { return $counts; }

		$header = str_getcsv( array_shift( $rows ) );
		$header = array_map( fn( $h ) => strtolower( trim( (string) $h ) ), $header );

		foreach ( $rows as $line )
		{
			$line = trim( (string) $line );
			if ( $line === '' ) { continue; }
			$counts['total']++;
			$cols = str_getcsv( $line );
			$data = [];
			foreach ( $header as $i => $k )
			{
				$data[ $k ] = $cols[ $i ] ?? null;
			}
			$data['source'] = 'csv';

			$res = \IPS\gdbills\Bill::upsert( $data );
			if ( $res['action'] === 'insert' )      { $counts['new']++; }
			elseif ( $res['action'] === 'update' )  { $counts['updated']++; }
			else                                    { $counts['errors']++; }
		}
		return $counts;
	}
}

class import extends _import {}
