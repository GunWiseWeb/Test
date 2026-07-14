<?php
/**
 * @brief  GD Compliance — upgrade 1.6.47 (ammo + knife state advisories)
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.6.47
 *   New product classes routed through the existing advisory pipeline.
 *
 *   1. sources/Engine.php — TOP_LEVEL_TYPES extended:
 *        23  => 'ammo'   (top-level Ammunition category)
 *        138 => 'knife'  (top-level Knives category)
 *      buildTypeMap() now resolves these top-level cats so ammo /
 *      knife rows are no longer skipped at the `$type === null`
 *      guard in computeFlags(). AWB / roster / melting-point /
 *      capacity passes all explicitly skip non-firearm $type via
 *      their own gates; the capacity pass got an extra
 *      in_array($type, [handgun,rifle,shotgun]) guard so a stray
 *      'all'-typed capacity rule can never wrongly match an ammo
 *      SKU whose "capacity" reads as a round count.
 *
 *   2. sources/Advisories.php — matchesFor() extended: 'ammo' and
 *      'knife' firearmType emit one advisory per state that has an
 *      enabled rule for the class. No product-specific gating
 *      (state ammo / knife statutes apply broadly to the class).
 *      Firearm rifle logic is unchanged.
 *
 *   3. modules/admin/compliance/ammunition.php + knives.php — two
 *      new ACP controllers, structurally identical to
 *      modules/admin/compliance/advisories.php but filtered
 *      WHERE firearm_class = 'ammo' / 'knife'. Same summary block,
 *      same per-rule edit cards, same state-filter + flagged-
 *      products table + per-row Set-override link. Class names
 *      dual-declared (rule #2). checkAcpPermission('compliance_manage').
 *
 *   4. data/acpmenu.json — added ammunition + knives entries under
 *      the compliance tab (both restricted to compliance_manage).
 *
 *   5. dev/lang.php + data/lang.xml — added menu keys +
 *      gdcompliance_acp_ammo_* / _knife_* strings. This upgrade
 *      seeds the same rows into core_sys_lang_words for every
 *      language on the target install (rule #43, per-row try/catch).
 *
 *   6. gd_compliance_advisory_rules — seeded per-state ammo + knife
 *      rules. Idempotent: check-then-insert on (state_code +
 *      firearm_class), so re-runs never duplicate.
 *
 * Reasons are informational — every one ends with "verify current
 * law before purchasing". NOT legal advice. Rendered exactly like
 * existing CO/MN advisories (yellow block, click to see full text +
 * citation).
 *
 * NO CanonicalTemplates re-seed call. No template body changes.
 * No schema changes (gd_compliance_advisory_rules already exists;
 * firearm_class is a VARCHAR that accepts arbitrary class strings).
 */

namespace IPS\gdcompliance\setup\upg_10647;

use function defined;
use function function_exists;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		$this->seedLangStrings();
		$this->seedAmmoRules();
		$this->seedKnifeRules();
		$this->clearCaches();
		return TRUE;
	}

	/**
	 * Seed the new lang keys into core_sys_lang_words for every
	 * installed language. 6-column shape (rule #43). Per-row
	 * try/catch (rule #44) so a single encoding hiccup can't
	 * abort the whole loop.
	 */
	protected function seedLangStrings(): void
	{
		$strings = [
			'menu__gdcompliance_compliance_ammunition' => 'Ammunition',
			'menu__gdcompliance_compliance_knives'     => 'Knives',

			'gdcompliance_acp_ammo_title'          => 'Ammunition State Advisories',
			'gdcompliance_acp_ammo_intro'          => 'State-specific ammunition rules — FOID card / eligibility permit / vendor licensing / no-ship jurisdictions. Not sale bans (unless a jurisdiction blocks shipment outright). Each row\'s reason is customer-visible in a yellow advisory block on the ammo product page and always ends with "verify current law before purchasing" — informational, not legal advice.',
			'gdcompliance_acp_ammo_flagged_title'  => 'Flagged ammunition products',
			'gdcompliance_acp_ammo_flagged_intro'  => 'Every product currently carrying an advisory flag (all classes). Click a state to filter and reach per-(UPC, state) override — a force_clear suppresses the advisory for that specific product in that state.',
			'gdcompliance_acp_ammo_override'       => 'Set override',

			'gdcompliance_acp_knife_title'         => 'Knife State Advisories',
			'gdcompliance_acp_knife_intro'         => 'State-specific knife rules — switchblade / automatic / balisong restrictions. Each row\'s reason is customer-visible in a yellow advisory block on the knife product page and always ends with "verify current law before purchasing" — informational, not legal advice.',
			'gdcompliance_acp_knife_flagged_title' => 'Flagged knife products',
			'gdcompliance_acp_knife_flagged_intro' => 'Every product currently carrying an advisory flag (all classes). Click a state to filter and reach per-(UPC, state) override — a force_clear suppresses the advisory for that specific product in that state.',
			'gdcompliance_acp_knife_override'      => 'Set override',
		];

		try
		{
			foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
			{
				foreach ( $strings as $key => $val )
				{
					try
					{
						\IPS\Db::i()->replace( 'core_sys_lang_words', [
							'lang_id'      => (int) $langId,
							'word_app'     => 'gdcompliance',
							'word_key'     => $key,
							'word_default' => $val,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
					catch ( \Throwable $e )
					{
						try { \IPS\Log::log( 'upg_10647 lang ' . $key . ': ' . $e->getMessage(), 'gdcompliance_upg_10647' ); } catch ( \Throwable ) {}
					}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10647 lang loop: ' . $e->getMessage(), 'gdcompliance_upg_10647' ); } catch ( \Throwable ) {}
		}
	}

	/**
	 * Insert per-state ammunition advisory rules. Verified law
	 * summaries — every reason ends with "verify current law
	 * before purchasing" so the copy on the storefront stays
	 * informational, not legal advice.
	 *
	 * Idempotent: SELECT-then-INSERT on (state_code, firearm_class)
	 * so re-running upg_10647 does NOT duplicate rows.
	 */
	protected function seedAmmoRules(): void
	{
		$rules = [
			[
				'state_code'    => 'IL',
				'reason'        => 'Illinois requires a valid FOID card and Illinois driver\'s license/state ID to receive ammunition; it ships only to the address on those documents. Ammunition cannot be shipped to Chicago/Cook County. Verify current law before purchasing.',
				'citation'      => '430 ILCS 65 (FOID Act); 430 ILCS 65/3(b-5)',
			],
			[
				'state_code'    => 'CA',
				'reason'        => 'California generally requires ammunition to be transferred through a licensed ammunition vendor/FFL with an eligibility check, unless exempt (e.g. FFL03 + Certificate of Eligibility). Some cities (Los Angeles, Oakland, Sacramento, San Francisco, Avalon) restrict further. This area is in legal flux — verify current law before purchasing.',
				'citation'      => 'Cal. Penal Code § 30312 (Prop 63)',
			],
			[
				'state_code'    => 'CT',
				'reason'        => 'Connecticut requires a valid state ID plus an ammunition certificate, pistol permit, or long-gun eligibility certificate to receive ammunition. Verify current law before purchasing.',
				'citation'      => 'Conn. Gen. Stat. § 29-37k',
			],
			[
				'state_code'    => 'NY',
				'reason'        => 'New York requires ammunition to be shipped to a licensed FFL or registered ammunition seller (SAFE Act); direct residential delivery is restricted, especially in NYC. Verify current law before purchasing.',
				'citation'      => 'N.Y. Penal Law § 400.03 (SAFE Act)',
			],
			[
				'state_code'    => 'NJ',
				'reason'        => 'New Jersey requires a Firearms Purchaser Identification Card or permit to receive handgun-caliber ammunition; rifle/shotgun calibers generally have no such requirement. Hollow-point restrictions may apply. Verify current law before purchasing.',
				'citation'      => 'N.J.S.A. 2C:58-3.3',
			],
			[
				'state_code'    => 'MA',
				'reason'        => 'Massachusetts requires a valid FID card or License to Carry to receive ammunition, and sellers must be MA-licensed; most out-of-state shipments must go to an in-state FFL. Verify current law before purchasing.',
				'citation'      => 'M.G.L. c. 140 § 129C',
			],
			[
				'state_code'    => 'RI',
				'reason'        => 'Rhode Island requires the buyer to be 21+ and hold the required license/safety certificate to purchase ammunition. Verify current law before purchasing.',
				'citation'      => 'R.I. Gen. Laws § 11-47-35',
			],
			[
				'state_code'    => 'MD',
				'reason'        => 'Maryland prohibits online ammunition sales shipped to Annapolis; local restrictions may apply elsewhere. Verify current law before purchasing.',
				'citation'      => 'Annapolis City Code',
			],
			[
				'state_code'    => 'HI',
				'reason'        => 'Many retailers do not ship ammunition to Hawaii due to state/local law and carrier restrictions. Verify current law and retailer policy before purchasing.',
				'citation'      => 'Haw. Rev. Stat. § 134-2 et seq.; state/local law',
			],
			[
				'state_code'    => 'AK',
				'reason'        => 'Many retailers do not ship ammunition to Alaska due to state/local law and carrier restrictions. Verify current law and retailer policy before purchasing.',
				'citation'      => 'State/local law; carrier policy',
			],
			[
				'state_code'    => 'DC',
				'reason'        => 'Many retailers do not ship ammunition to Washington D.C. due to state/local law and carrier restrictions. Verify current law and retailer policy before purchasing.',
				'citation'      => 'D.C. Code § 7-2506.01; local law',
			],
		];

		$this->insertAdvisoryRules( $rules, 'ammo' );
	}

	/**
	 * Insert per-state knife advisory rules. Same idempotent shape
	 * as seedAmmoRules().
	 */
	protected function seedKnifeRules(): void
	{
		$rules = [
			[
				'state_code' => 'WA',
				'reason'     => 'Washington prohibits spring-blade (automatic/switchblade) knives for civilians. Verify current law before purchasing.',
				'citation'   => 'RCW 9.41.250',
			],
			[
				'state_code' => 'CA',
				'reason'     => 'California prohibits selling, shipping, or possessing switchblades with a blade 2 inches or longer, and balisong/butterfly knives are restricted. Verify current law before purchasing.',
				'citation'   => 'Cal. Penal Code § 21510',
			],
			[
				'state_code' => 'MN',
				'reason'     => 'Minnesota bans automatic/switchblade knives for civilians. Verify current law before purchasing.',
				'citation'   => 'Minn. Stat. § 609.66',
			],
			[
				'state_code' => 'NM',
				'reason'     => 'New Mexico restricts automatic/switchblade knives (possession limited). Verify current law before purchasing.',
				'citation'   => 'N.M. Stat. § 30-7-8',
			],
			[
				'state_code' => 'HI',
				'reason'     => 'Hawaii historically banned switchblades and balisongs; a 2024 change legalized possession of automatic knives but concealed carry remains restricted and the statute language is in transition. Verify current law before purchasing.',
				'citation'   => 'Haw. Rev. Stat. § 134-52',
			],
			[
				'state_code' => 'NJ',
				'reason'     => 'New Jersey restricts switchblades/automatic knives (possession lawful only with an "explainable lawful purpose"), effectively limiting them. Verify current law before purchasing.',
				'citation'   => 'N.J.S.A. 2C:39-3(e)',
			],
			[
				'state_code' => 'NY',
				'reason'     => 'New York restricts switchblades/automatic knives (limited exceptions such as valid hunting/fishing/trapping license). Verify current law before purchasing.',
				'citation'   => 'N.Y. Penal Law § 265.01',
			],
			[
				'state_code' => 'DC',
				'reason'     => 'Washington D.C. prohibits switchblade/automatic knife possession. Verify current law before purchasing.',
				'citation'   => 'D.C. Code § 22-4514',
			],
			[
				'state_code' => 'MA',
				'reason'     => 'Massachusetts restricts automatic knives with blades over 1.5 inches and treats balisongs aggressively. Verify current law before purchasing.',
				'citation'   => 'M.G.L. c. 269 § 10(b)',
			],
		];

		$this->insertAdvisoryRules( $rules, 'knife' );
	}

	/**
	 * Shared idempotent insert. Given a class ('ammo' or 'knife')
	 * and a list of [state_code, reason, citation] triples, insert
	 * a row into gd_compliance_advisory_rules for each pair not
	 * already present. Existing rows are LEFT ALONE — a re-run of
	 * this upgrade does not overwrite reason text the admin may
	 * have edited via the ACP.
	 *
	 * @param array<int, array{state_code:string,reason:string,citation:string}> $rules
	 */
	protected function insertAdvisoryRules( array $rules, string $firearmClass ): void
	{
		$now = time();
		foreach ( $rules as $r )
		{
			$state = strtoupper( trim( (string) ( $r['state_code'] ?? '' ) ) );
			if ( strlen( $state ) !== 2 ) { continue; }
			$reason = (string) ( $r['reason'] ?? '' );
			$cite   = substr( (string) ( $r['citation'] ?? '' ), 0, 255 );

			$exists = 0;
			try
			{
				$exists = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_compliance_advisory_rules',
					[ 'state_code=? AND firearm_class=?', $state, $firearmClass ]
				)->first();
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10647 rule check ' . $state . '/' . $firearmClass . ': ' . $e->getMessage(), 'gdcompliance_upg_10647' ); } catch ( \Throwable ) {}
				continue;
			}
			if ( $exists > 0 ) { continue; }

			try
			{
				\IPS\Db::i()->insert( 'gd_compliance_advisory_rules', [
					'state_code'     => $state,
					'firearm_class'  => $firearmClass,
					'enabled'        => 1,
					'reason'         => $reason,
					'citation'       => $cite ?: null,
					'effective_date' => null,
					'updated_at'     => $now,
				] );
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10647 rule insert ' . $state . '/' . $firearmClass . ': ' . $e->getMessage(), 'gdcompliance_upg_10647' ); } catch ( \Throwable ) {}
			}
		}
	}

	/**
	 * Cache purge — module dispatcher + settings + lang + template
	 * caches must re-resolve so the two new admin controllers are
	 * discovered and the new lang keys render. NO
	 * CanonicalTemplates::ensure() call.
	 */
	protected function clearCaches(): void
	{
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->acp_notifications ); }  catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->canonical_templates ); } catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\gdcompliance\Advisories::clearCache(); }        catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }
	}
}
class upgrade extends _upgrade {}
