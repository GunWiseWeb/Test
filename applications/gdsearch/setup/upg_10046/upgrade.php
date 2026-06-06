<?php
namespace IPS\gdsearch\setup\upg_10046;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade { public function step1(): bool {
	try { require_once \IPS\ROOT_PATH . '/applications/gdsearch/setup/templates_10046.php'; } catch ( \Throwable ) {}
	return TRUE;
} }
class upgrade extends _upgrade {}
