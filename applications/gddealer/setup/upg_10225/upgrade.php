<?php
namespace IPS\gddealer\setup\upg_10225;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade
{
    public function step1(): bool
    {
        // v1.0.225: Add Flagged UPCs to ACP menu.
        try { unset( \IPS\Data\Store::i()->acpMenu ); } catch ( \Throwable ) {}
        try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
        return TRUE;
    }
}
class upgrade extends _upgrade {}
