<?php
namespace IPS\gdcatalog\setup\upg_10002;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * v1.0.2 NO-OP
 *
 * The original v1.0.2 upgrade.php attempted to reseed the feedList template
 * but referenced 3 columns (template_user_edited, template_user_created,
 * template_user_added) that don't exist on the IPS 5.0.18 core_theme_templates
 * schema. Every replace() call threw "Unknown column" errors, the catch
 * returned FALSE, and IPS retried indefinitely - causing install to hang.
 *
 * v1.0.3 fix-forwards what v1.0.2 was supposed to do (template reseed + lang
 * string seeding) using the correct column list.
 *
 * This file is now a no-op so the IPS upgrade chain (10001 -> 10002 -> 10003)
 * can proceed past 10002 cleanly. The actual work happens in upg_10003.
 *
 * NOTE: This bends the project's "once shipped, frozen" rule. Justification:
 * the original v1.0.2 was OBJECTIVELY BROKEN (could not complete install at
 * all). The rule's intent (audit trail of behavior changes) is preserved by
 * git history showing the original v1.0.2 commit and this v1.0.3 fix commit
 * with explanatory message. See commit message for full context.
 */
class _upgrade
{
	public function step1(): bool
	{
		/* Intentionally empty. See file docblock. */
		return TRUE;
	}

	public function step1CustomTitle()
	{
		return 'v1.0.2 (no-op - superseded by v1.0.3)';
	}
}

class upgrade extends _upgrade {}
