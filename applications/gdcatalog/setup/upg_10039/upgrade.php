<?php
namespace IPS\gdcatalog\setup\upg_10039;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) {
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}
class _upgrade
{
	public function step1(): bool
	{
		/* v1.0.39 — Critical fixes before full Sports South import:
		 * 1. Removed deprecated libxml_disable_entity_loader() calls
		 *    from XmlParser.php and SportsSouthClient.php (Rule #58)
		 * 2. Changed catch(\Exception) to catch(\Throwable) in all
		 *    defensive blocks (Rule #35)
		 * 3. Added minimal data/emails.xml (Rule #18)
		 * 4. Added dev/html/ phtml files for IPS 5 dev-mode support
		 * No DB changes required — source-only fixes. */
		try { unset( \IPS\Data\Store::i()->extensions ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		return TRUE;
	}
}
class upgrade extends _upgrade {}
