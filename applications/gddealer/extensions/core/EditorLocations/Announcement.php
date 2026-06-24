<?php

namespace IPS\gddealer\extensions\core\EditorLocations;

use IPS\Content;
use IPS\Extensions\EditorLocationsAbstract;
use IPS\Helpers\Form\Editor;
use IPS\Http\Url;
use IPS\Member;
use IPS\Node\Model;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class Announcement extends EditorLocationsAbstract
{
	public function canAttach( Member $member, Editor $field ): ?bool
	{
		return NULL;
	}

	public function attachmentPermissionCheck( Member $member, ?int $id1, ?int $id2, ?string $id3, array $attachment, bool $viewOnly=FALSE ): bool
	{
		return TRUE;
	}

	public function attachmentLookup( ?int $id1=NULL, ?int $id2=NULL, ?string $id3=NULL ): Model|Content|Url|Member|null
	{
		return NULL;
	}
}
