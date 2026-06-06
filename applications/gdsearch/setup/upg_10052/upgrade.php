<?php
namespace IPS\gdsearch\setup\upg_10052;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade { public function step1(): bool { return TRUE; } }
class upgrade extends _upgrade {}
