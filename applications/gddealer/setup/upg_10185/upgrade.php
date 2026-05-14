<?php
namespace IPS\gddealer\setup\upg_10185;

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
		/* gddealer v1.0.185 - Cover image transparency fix.
		 *
		 * Same root cause as v1.0.181 avatar fix:
		 *
		 * The .hero-cover CSS rule uses background: var(--gd-brand)
		 * (the dealer's blue brand color) as the base color. When the
		 * cover_url image is transparent PNG or doesn't fully cover the
		 * div's aspect ratio after background-size:cover clipping, the
		 * blue base bleeds through, making the cover look blue.
		 *
		 * Fix: when the .hero-cover div has an inline background-image
		 * (i.e., dealer has a real cover photo), override the brand-blue
		 * base color with a neutral white. The image will sit on top
		 * cleanly even if it has transparency. The base color stays as
		 * brand-blue for dealers without a cover photo (fallback look).
		 *
		 * Implementation: surgical str_replace on the dealerProfile
		 * template's CSS block - mirror the v1.0.181 `:has(img)` trick.
		 *
		 * Per CLAUDE.md rule #51: sanity check vs PREVIOUS version (10184). */

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
				'gddealer v1.0.185 sanity (pre-version-write): app_long_version=%d, app_version=%s',
				$longVer,
				(string) ( $row['app_version'] ?? '' )
			);
			try { \IPS\Log::log( $msg, 'gddealer_upg_10185' ); } catch ( \Throwable ) {}

			if ( $longVer < 10184 )
			{
				$warning = sprintf(
					'gddealer v1.0.185 WARNING: app_long_version=%d below 10184',
					$longVer
				);
				try { \IPS\Log::log( $warning, 'gddealer_upg_10185' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gddealer v1.0.185 sanity check failed: ' . $e->getMessage(), 'gddealer_upg_10185' ); } catch ( \Throwable ) {}
		}

		/* Step 2: Patch the dealerProfile template.
		 *
		 * Confirmed via diagnostic: dealerProfile is the public profile page
		 * template (the one viewed at /dealer/<slug>/). Line 56 has the
		 * .hero-cover rule. We're going to APPEND a new rule that overrides
		 * the brand background when an inline background-image is set.
		 *
		 * Since CSS selectors can target inline-style attributes,
		 * `.hero-cover[style*="background-image"]` matches only the cover
		 * div that has a real cover photo URL inlined. */

		$oldCoverRule = '.gdDealerPage .hero-cover { height: 180px; background: var(--gd-brand); background-image: linear-gradient(135deg, var(--gd-brand) 0%, color-mix(in srgb, var(--gd-brand) 70%, white) 100%); position: relative; background-size: cover; background-position: center; }';

		$newCoverRule = '.gdDealerPage .hero-cover { height: 180px; background: var(--gd-brand); background-image: linear-gradient(135deg, var(--gd-brand) 0%, color-mix(in srgb, var(--gd-brand) 70%, white) 100%); position: relative; background-size: cover; background-position: center; }
.gdDealerPage .hero-cover[style*="background-image"] { background-color: #FFFFFF; background-blend-mode: normal; }';

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

			try { \IPS\Log::log( sprintf( 'gddealer v1.0.185 found %d dealerProfile row(s) to patch', count( $rows ) ), 'gddealer_upg_10185' ); } catch ( \Throwable ) {}

			foreach ( $rows as $row )
			{
				$content    = (string) $row['template_content'];

				/* Idempotent: already patched if our override rule is present */
				if ( strpos( $content, '.hero-cover[style*="background-image"]' ) !== false )
				{
					try { \IPS\Log::log( sprintf(
						'gddealer v1.0.185 dealerProfile template_id=%d set_id=%d already patched, skipping',
						(int) $row['template_id'],
						(int) $row['template_set_id']
					), 'gddealer_upg_10185' ); } catch ( \Throwable ) {}
					continue;
				}

				$newContent = str_replace( $oldCoverRule, $newCoverRule, $content );

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
						'gddealer v1.0.185 patched dealerProfile template_id=%d set_id=%d (%d->%d bytes)',
						(int) $row['template_id'],
						(int) $row['template_set_id'],
						strlen( $content ),
						strlen( $newContent )
					), 'gddealer_upg_10185' ); } catch ( \Throwable ) {}
				}
				else
				{
					try { \IPS\Log::log( sprintf(
						'gddealer v1.0.185 dealerProfile template_id=%d set_id=%d: no match for old cover rule (template hand-edited?)',
						(int) $row['template_id'],
						(int) $row['template_set_id']
					), 'gddealer_upg_10185' ); } catch ( \Throwable ) {}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gddealer v1.0.185 dealerProfile patch failed: ' . $e->getMessage(), 'gddealer_upg_10185' ); } catch ( \Throwable ) {}
		}

		/* Step 3: Cache invalidation. */
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
		return 'gddealer v1.0.185 - cover image transparency fix';
	}
}

class upgrade extends _upgrade {}
