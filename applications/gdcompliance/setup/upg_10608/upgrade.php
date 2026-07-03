<?php
/**
 * @brief  GD Compliance — upgrade 1.6.8
 *
 * Standardized exemption_note wording for every ENABLED AWB state
 * (12 states as of this ship: CA, CT, DC, DE, HI, IL, MA, MD, NJ,
 * NY, RI, WA). The note is a buyer-side disclaimer — the AWB flag
 * STAYS ON for the general public; the popup + ACP surface the
 * text to point the buyer at their FFL for eligibility verification.
 *
 * VA remains enabled=0 (Crump v. Katz enjoined) and is NOT seeded.
 * VT has no enacted AWB (bills all failed) and is NOT present.
 * HI's rule is the "assault pistol" definition (HRS §134-1) — the
 * generic "assault weapons law" phrasing reads correctly for it.
 *
 * The row's actual `citation` column is substituted into {CITATION}
 * so any admin edit to the citation carries through.
 *
 * DEFENSIVE: if this install skipped v1.6.7 (going 1.6.6 → 1.6.8),
 * this step also runs the guarded ALTER to add exemption_note.
 * Column create is idempotent (checkForColumn before addColumn).
 *
 * No compute needed — Flag::forUpc reads exemption_note at render
 * time by joining state_code, so existing gd_compliance_flags rows
 * immediately pick up the text.
 */

namespace IPS\gdcompliance\setup\upg_10608;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	/** State code → human name for {STATE_NAME} substitution. */
	protected const STATE_NAMES = [
		'CA' => 'California',
		'CT' => 'Connecticut',
		'DC' => 'the District of Columbia',
		'DE' => 'Delaware',
		'HI' => 'Hawaii',
		'IL' => 'Illinois',
		'MA' => 'Massachusetts',
		'MD' => 'Maryland',
		'NJ' => 'New Jersey',
		'NY' => 'New York',
		'RI' => 'Rhode Island',
		'WA' => 'Washington',
	];

	public function step1(): bool
	{
		/* ---------- Defensive schema guard (1.6.6 → 1.6.8 leap) ---------- */
		$hasCol = FALSE;
		try
		{
			$hasCol = (bool) \IPS\Db::i()->checkForColumn( 'gd_compliance_awb_rules', 'exemption_note' );
		}
		catch ( \Throwable )
		{
			$hasCol = FALSE;
		}
		if ( !$hasCol )
		{
			try
			{
				\IPS\Db::i()->addColumn( 'gd_compliance_awb_rules', [
					'name'           => 'exemption_note',
					'type'           => 'TEXT',
					'length'         => 0,
					'decimals'       => null,
					'allow_null'     => TRUE,
					'default'        => null,
					'auto_increment' => FALSE,
					'binary'         => FALSE,
					'unsigned'       => FALSE,
					'zerofill'       => FALSE,
					'values'         => [],
					'comment'        => 'free-text disclaimer surfaced in the front-end popup and ACP for this state\'s AWB flags',
				] );
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10608 addColumn exemption_note: ' . $e->getMessage(), 'gdcompliance_upg_10608' ); }
				catch ( \Throwable ) {}
				return FALSE;
			}
		}

		/* ---------- Seed standardized text per enabled state ----------
		   Iterate over enabled rules; read each row's own citation so
		   the text tracks any admin edits. Per-row try/catch: one bad
		   row (encoding / oversized text on a tightened column) cannot
		   abort the loop. VA (enabled=0) never enters this loop, so
		   the "no VA" invariant holds without an explicit exclusion. */
		$updated = 0;
		$skipped = 0;
		$failed  = 0;
		try
		{
			foreach ( \IPS\Db::i()->select( 'id, state_code, citation', 'gd_compliance_awb_rules', [ 'enabled=1' ] ) as $row )
			{
				try
				{
					$id    = (int) ( $row['id'] ?? 0 );
					$state = strtoupper( trim( (string) ( $row['state_code'] ?? '' ) ) );
					$cite  = trim( (string) ( $row['citation'] ?? '' ) );

					if ( $id <= 0 || $state === '' || !isset( self::STATE_NAMES[ $state ] ) )
					{
						$skipped++;
						continue;
					}

					$stateName = self::STATE_NAMES[ $state ];
					if ( $cite === '' )
					{
						/* Fall back — a rule row without a citation on disk
						   is malformed data; still emit a valid disclaimer
						   rather than "()". */
						$citeInText = 'state statute';
					}
					else
					{
						$citeInText = $cite;
					}

					$note =
						"Restricted for sale to the general public under "
						. $stateName . "'s assault weapons law (" . $citeInText . "). "
						. "Narrow exemptions may apply for active sworn law enforcement acquiring in an official capacity, "
						. "and in some cases qualified retired law enforcement or military personnel — but these typically "
						. "require agency authorization and specific documentation, and are not general consumer purchase "
						. "exemptions. Any exemption eligibility and required paperwork must be verified by your FFL before "
						. "transfer. This listing does not indicate that any individual buyer qualifies.";

					\IPS\Db::i()->update( 'gd_compliance_awb_rules', [
						'exemption_note' => $note,
						'updated_at'     => time(),
					], [ 'id=?', $id ] );
					$updated++;
				}
				catch ( \Throwable $e )
				{
					$failed++;
					try { \IPS\Log::log( 'upg_10608 seed ' . ( $row['state_code'] ?? '?' ) . ': ' . $e->getMessage(), 'gdcompliance_upg_10608' ); }
					catch ( \Throwable ) {}
				}
			}

			try { \IPS\Log::log(
				sprintf( 'upg_10608 exemption seed: updated=%d skipped=%d failed=%d', $updated, $skipped, $failed ),
				'gdcompliance_upg_10608'
			); } catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10608 driver SELECT: ' . $e->getMessage(), 'gdcompliance_upg_10608' ); }
			catch ( \Throwable ) {}
		}

		/* Reset AWB per-request caches so an admin loading a page
		   in the same PHP request as this upgrade sees fresh notes. */
		try
		{
			require_once \IPS\ROOT_PATH . '/applications/gdcompliance/sources/AwbModels.php';
			\IPS\gdcompliance\AwbModels::clearCache();
		}
		catch ( \Throwable ) {}

		/* ---------- Cache purges ----------
		   canonical_templates is cleared so the compiled gdsearch
		   product.phtml with the new data-exemption attribute picks
		   up on the next front-end render (v1.6.7 shipped the popup
		   markup + JS; v1.6.8 populates its data). */
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->canonical_templates ); } catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
