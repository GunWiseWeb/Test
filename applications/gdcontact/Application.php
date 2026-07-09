<?php
/**
 * @brief  GD Contact — public contact form + ACP form builder.
 *
 * Replaces IPS's bare native contact form with an admin-
 * configurable one: fields are defined in
 *   ACP → Contact → Fields
 * (add / edit / reorder / enable / disable / any of
 * text/email/phone/textarea/select/checkbox/number) and the
 * front page at /contact/ renders whatever the admin has
 * configured, with the site's CAPTCHA. Submissions are e-mailed
 * via \IPS\Email (same reliable path IPS itself uses) — no DB
 * storage for now.
 */

namespace IPS\gdcontact;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Application extends \IPS\Application
{
	public function get__icon(): string
	{
		return 'envelope';
	}
}

class Application extends _Application {}
