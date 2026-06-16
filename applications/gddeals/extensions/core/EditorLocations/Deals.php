<?php
namespace IPS\gddeals\extensions\core\EditorLocations;

use IPS\Content;
use IPS\Extensions\EditorLocationsAbstract;
use IPS\gddeals\Deal;
use IPS\gddeals\Deal\Comment;
use IPS\Helpers\Form\Editor;
use IPS\Http\Url;
use IPS\Member;
use IPS\Node\Model;
use OutOfRangeException;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class Deals extends EditorLocationsAbstract
{
	public function canAttach( Member $member, Editor $field ): ?bool
	{
		return NULL;
	}

	public function canBeModerated( Member $member, Editor $field ): bool
	{
		if ( isset( $field->options['autoSaveKey'] ) AND preg_match( '/^(?:editComment|reply)\-gddeals\/deals\-\d+/', $field->options['autoSaveKey'] ) )
		{
			return TRUE;
		}
		return FALSE;
	}

	public function attachmentPermissionCheck( Member $member, ?int $id1, ?int $id2, ?string $id3, array $attachment, bool $viewOnly = FALSE ): bool
	{
		try
		{
			return Deal::load( $id1 )->canView( $member );
		}
		catch ( OutOfRangeException $e )
		{
			return FALSE;
		}
	}

	public function attachmentLookup( ?int $id1 = NULL, ?int $id2 = NULL, ?string $id3 = NULL ): Model|Content|Url|Member|null
	{
		if ( $id2 )
		{
			return Comment::load( $id2 );
		}
		return Deal::load( $id1 );
	}
}
