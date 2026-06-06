<?php
namespace IPS\gdsearch\setup\upg_10030;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade
{
	public function step1(): bool
	{
		try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Theme::deleteCompiledTemplate(); }        catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }            catch ( \Throwable ) {}
		return TRUE;
	}
}
class upgrade extends _upgrade {}
