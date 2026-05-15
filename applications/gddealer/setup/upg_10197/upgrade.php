<?php
/**
 * v1.0.197: Fix missing "name" field on gd_dealer_category_map in schema.json.
 *
 * Schema.json fixes apply automatically via IPS's schema diff on install/upgrade.
 * This upgrade step exists solely to register the version bump.
 */

namespace IPS\gddealer\setup\upg_10197;

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
		return TRUE;
	}
}

class upgrade extends _upgrade {}
