<?php
/**
 * @brief  GD Compliance — upgrade 1.6.6 (compute performance: disable GC cycle
 *         collection during the 58k-row scan)
 *
 * Code-only release. No schema, no data, no flags touched.
 *
 * ROOT CAUSE of the ~582s compute (the ACP "Run compute" 30s-timeout death):
 *   PHP's cycle-collecting garbage collector. computeFlags builds large,
 *   forward-only arrays that all stay alive for the whole loop — $flags
 *   (~32k rows), $result['review_queue'] (~11.7k rows), and the buffered
 *   58k-row catalog result (with descriptions) resident at once. The GC
 *   fires each time its root buffer hits 10,000 roots; with hundreds of
 *   thousands of live array zvals accumulating, every collection cycle
 *   re-scans an ever-growing live set and the cost compounds as the loop
 *   proceeds. Invisible in small-N isolation tests; not affected by raising
 *   memory_limit (GC cadence != memory ceiling); appeared exactly when the
 *   roster pass was fixed to run (arrays began accumulating).
 *
 * What ships (Engine::computeFlags only):
 *   - gc_disable() for the run, gc_enable() restored before every return.
 *   - "GDCK loop-done <secs>s; gc_runs=N" checkpoint per compute so the fix
 *     is verifiable in one run (baseline ~582s -> target ~10-20s, gc_runs ~0).
 *
 * upg_10606 just purges caches — nothing to migrate.
 */

namespace IPS\gdcompliance\setup\upg_10606;

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
		try { unset( \IPS\Data\Store::i()->settings ); }             catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }           catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                    catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                    catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
