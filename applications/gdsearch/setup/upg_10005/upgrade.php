<?php
namespace IPS\gdsearch\setup\upg_10005;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade
{
    public function step1(): bool
    {
        try { unset( \IPS\Data\Store::i()->furl ); } catch ( \Throwable ) {}
        try { \IPS\Data\Cache::i()->clearAll(); }     catch ( \Throwable ) {}
        return TRUE;
    }
}
class upgrade extends _upgrade {}
