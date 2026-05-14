<?php
namespace IPS\gddealer\setup\upg_10181;

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
		/* gddealer v1.0.181 - Fix dealer profile avatar background bleeding
		 * through transparent logo PNGs.
		 *
		 * Symptom: Defense Depot's logo PNG (red letters on transparent bg)
		 * rendered as red letters on BLUE on the dealer profile page. The
		 * blue was the dealer's brand color (--gd-brand) on .hero-avatar
		 * showing through the PNG's transparent pixels. Additionally,
		 * object-fit: cover was cropping the wide 1024x349 logo into the
		 * 92x92 square avatar, only showing partial letters.
		 *
		 * Fix (CSS only, no markup change):
		 *   1. When .hero-avatar contains an <img>, override background to
		 *      white and add small padding so the logo doesn't touch edges.
		 *      Uses :has(img) selector (Chrome 105+, Safari 15.4+, Firefox
		 *      121+, all released >1yr ago, broadly supported).
		 *   2. Change .hero-avatar img object-fit from "cover" to "contain"
		 *      so the full logo is visible without cropping.
		 *
		 * When .hero-avatar has no <img> (initials-only avatar), behavior
		 * is unchanged - brand background + white initials, looks great.
		 *
		 * Same fix is NOT applied to .review-avatar img - reviewer avatars
		 * are typically user-uploaded photos where "cover" is correct.
		 *
		 * Implementation: PHP-side str_replace against both existing
		 * dealerProfile rows (template_set_id=0 customized + set_id=1
		 * shipped) so we don't risk byte-corruption of the 47KB template
		 * by reseeding through a heredoc. Idempotent (str_replace on
		 * already-replaced string is a no-op).
		 *
		 * Per CLAUDE.md rule #51: sanity check vs PREVIOUS version (10180). */

		/* Step 1: Sanity check */
		try
		{
			$row = \IPS\Db::i()->select(
				'app_long_version, app_version',
				'core_applications',
				[ 'app_directory=?', 'gddealer' ]
			)->first();

			$longVer = (int) ( $row['app_long_version'] ?? 0 );
			$msg = sprintf(
				'gddealer v1.0.181 sanity (pre-version-write): app_long_version=%d, app_version=%s',
				$longVer,
				(string) ( $row['app_version'] ?? '' )
			);
			try { \IPS\Log::log( $msg, 'gddealer_upg_10181' ); } catch ( \Throwable ) {}

			if ( $longVer < 10180 )
			{
				$warning = sprintf(
					'gddealer v1.0.181 WARNING: app_long_version=%d below 10180',
					$longVer
				);
				try { \IPS\Log::log( $warning, 'gddealer_upg_10181' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gddealer v1.0.181 sanity check failed: ' . $e->getMessage(), 'gddealer_upg_10181' ); } catch ( \Throwable ) {}
		}

		/* Step 2: Apply CSS patches via PHP str_replace against both rows. */

		/* Patch A: object-fit cover -> contain on .hero-avatar img.
		 * Exact source string matches baseline f3d98097898e3e4f2798a505fa12f51b
		 * line 62 verbatim. */
		$oldAvatarImg = '.gdDealerPage .hero-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }';
		$newAvatarImg = '.gdDealerPage .hero-avatar img { width: 100%; height: 100%; object-fit: contain; display: block; }';

		/* Patch B: Add :has(img) override on its own line after the existing
		 * .hero-avatar declaration. We replace the full original line with
		 * itself + the new rule appended on the next line. */
		$oldAvatarParent = '.gdDealerPage .hero-avatar { width: 92px; height: 92px; border-radius: var(--gd-r-xl); background: var(--gd-brand); color: white; display: inline-flex; align-items: center; justify-content: center; font-weight: 600; font-size: 32px; border: 4px solid var(--gd-surface); flex-shrink: 0; box-shadow: 0 4px 12px rgba(0,0,0,0.08); overflow: hidden; line-height: 1; }';
		$newAvatarParent = $oldAvatarParent
			. "\n"
			. '.gdDealerPage .hero-avatar:has(img) { background: #FFFFFF; padding: 6px; }';

		$replacements = [
			'object-fit cover->contain' => [ $oldAvatarImg, $newAvatarImg ],
			'add :has(img) override'    => [ $oldAvatarParent, $newAvatarParent ],
		];

		try
		{
			$rows = [];
			foreach ( \IPS\Db::i()->select(
				'template_id, template_set_id, template_content',
				'core_theme_templates',
				[ 'template_app=? AND template_name=?', 'gddealer', 'dealerProfile' ]
			) as $r )
			{
				$rows[] = $r;
			}

			try { \IPS\Log::log( sprintf( 'gddealer v1.0.181 found %d dealerProfile row(s) to patch', count( $rows ) ), 'gddealer_upg_10181' ); } catch ( \Throwable ) {}

			foreach ( $rows as $row )
			{
				$content    = (string) $row['template_content'];
				$newContent = $content;
				$applied    = [];
				$skipped    = [];

				foreach ( $replacements as $label => $pair )
				{
					$before = $newContent;
					$newContent = str_replace( $pair[0], $pair[1], $newContent );

					if ( $before !== $newContent )
					{
						$applied[] = $label;
					}
					else
					{
						/* No-op: either already patched (idempotent re-run) or
						 * the source string is absent (template hand-edited
						 * away from baseline). Log either way for diagnosis. */
						$skipped[] = $label;
					}
				}

				if ( $newContent !== $content )
				{
					\IPS\Db::i()->update(
						'core_theme_templates',
						[
							'template_content' => $newContent,
							'template_updated' => time(),
						],
						[ 'template_id=?', (int) $row['template_id'] ]
					);

					try { \IPS\Log::log( sprintf(
						'gddealer v1.0.181 patched template_id=%d set_id=%d (%d->%d bytes) applied=[%s] skipped=[%s]',
						(int) $row['template_id'],
						(int) $row['template_set_id'],
						strlen( $content ),
						strlen( $newContent ),
						implode( ',', $applied ),
						implode( ',', $skipped )
					), 'gddealer_upg_10181' ); } catch ( \Throwable ) {}
				}
				else
				{
					try { \IPS\Log::log( sprintf(
						'gddealer v1.0.181 template_id=%d set_id=%d: no changes (already patched, or template hand-edited)',
						(int) $row['template_id'],
						(int) $row['template_set_id']
					), 'gddealer_upg_10181' ); } catch ( \Throwable ) {}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gddealer v1.0.181 patch loop failed: ' . $e->getMessage(), 'gddealer_upg_10181' ); } catch ( \Throwable ) {}
		}

		/* Step 3: Cache invalidation. Critical - IPS caches compiled template
		 * functions in /static/templates/*.php. Without flushing these, the
		 * old CSS will keep rendering even after the DB rows are updated. */
		try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store' ); } catch ( \Throwable ) {}

		foreach ( glob( \IPS\ROOT_PATH . '/datastore/*.php' ) ?: [] as $f )
		{
			@unlink( $f );
		}
		foreach ( glob( \IPS\ROOT_PATH . '/static/templates/*.php' ) ?: [] as $f )
		{
			@unlink( $f );
		}

		try { unset( \IPS\Data\Store::i()->extensions );   } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll();            } catch ( \Throwable ) {}

		return TRUE;
	}

	public function step1CustomTitle()
	{
		return 'gddealer v1.0.181 - fix avatar background bleeding through transparent logos';
	}
}

class upgrade extends _upgrade {}
