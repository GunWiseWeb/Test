<?php
namespace IPS\gddealer\setup\upg_10184;

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
		/* gddealer v1.0.184 - HOTFIX for v1.0.183 Regenerate Slug button.
		 *
		 * Bug: clicking "Regenerate slug" in the modal got error 2S119/1
		 * (CSRF check failed). Two causes:
		 *
		 *   1) The modal's own hidden <input name="csrfKey"> is empty and
		 *      matches the JS selector first, blocking the fallback that
		 *      would scrape csrfKey from anchor URLs.
		 *
		 *   2) Even if csrfKey were baked into the form action URL via
		 *      ->csrf(), browser form submission with method=POST does NOT
		 *      promote the URL's ?csrfKey=XXX query param into POST body,
		 *      and IPS's csrfCheck() may require it in the POST body
		 *      specifically depending on the request context.
		 *
		 * Fix: in JS, parse csrfKey directly from the data-confirm-url
		 * (which already has ?csrfKey=XXX baked in by the controller's
		 * ->csrf() call) and inject it into the hidden form input
		 * BEFORE the form submits. Belt-and-suspenders: keep it in the
		 * URL too (form.action) so IPS can read it from either source.
		 *
		 * Implementation: surgical replace of the openModal() function
		 * body inside the modal's <script>. The function still lives in
		 * the overview template (no controller change needed).
		 *
		 * Per CLAUDE.md rule #51: sanity check vs PREVIOUS version (10183). */

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
				'gddealer v1.0.184 sanity (pre-version-write): app_long_version=%d, app_version=%s',
				$longVer,
				(string) ( $row['app_version'] ?? '' )
			);
			try { \IPS\Log::log( $msg, 'gddealer_upg_10184' ); } catch ( \Throwable ) {}

			if ( $longVer < 10183 )
			{
				$warning = sprintf(
					'gddealer v1.0.184 WARNING: app_long_version=%d below 10183',
					$longVer
				);
				try { \IPS\Log::log( $warning, 'gddealer_upg_10184' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gddealer v1.0.184 sanity check failed: ' . $e->getMessage(), 'gddealer_upg_10184' ); } catch ( \Throwable ) {}
		}

		/* Step 2: Patch the overview template's modal JS.
		 *
		 * Surgical replace of the openModal() function body. Match a
		 * distinctive snippet from the broken v1.0.183 version and replace
		 * with the corrected version that extracts csrfKey from the
		 * data-confirm-url.
		 *
		 * Pattern matched (the broken CSRF lookup block from v1.0.183): */

		$oldCsrfBlock = "				form.action = confirmUrl;
				/* CSRF: reuse from existing IPS form on page if any,
				 * otherwise fetch a fresh key from any link that has csrf in it */
				var existingCsrf = document.querySelector('input[name=csrfKey]');
				if ( existingCsrf ) {
					csrfInput.value = existingCsrf.value;
				} else {
					/* Fallback: pull csrf from any csrf-suffixed URL on page */
					var anchors = document.querySelectorAll('a[href*=\"csrfKey=\"]');
					if ( anchors.length ) {
						var match = anchors[0].href.match(/csrfKey=([^&]+)/);
						if ( match ) { csrfInput.value = match[1]; }
					}
				}";

		$newCsrfBlock = "				/* v1.0.184 fix: csrfKey is already baked into confirmUrl via
				 * the controller's ->csrf() call. Extract it directly and
				 * stuff it into the hidden input AND keep it in the action
				 * URL - both belt and suspenders for IPS's csrfCheck(). */
				form.action = confirmUrl;
				var csrfMatch = confirmUrl.match(/[?&]csrfKey=([^&]+)/);
				if ( csrfMatch ) {
					csrfInput.value = decodeURIComponent(csrfMatch[1]);
				}";

		try
		{
			$rows = [];
			foreach ( \IPS\Db::i()->select(
				'template_id, template_set_id, template_content',
				'core_theme_templates',
				[ 'template_app=? AND template_name=?', 'gddealer', 'overview' ]
			) as $r )
			{
				$rows[] = $r;
			}

			try { \IPS\Log::log( sprintf( 'gddealer v1.0.184 found %d overview row(s) to patch', count( $rows ) ), 'gddealer_upg_10184' ); } catch ( \Throwable ) {}

			foreach ( $rows as $row )
			{
				$content    = (string) $row['template_content'];
				$newContent = str_replace( $oldCsrfBlock, $newCsrfBlock, $content );

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
						'gddealer v1.0.184 patched overview template_id=%d set_id=%d (%d->%d bytes) - CSRF fix applied',
						(int) $row['template_id'],
						(int) $row['template_set_id'],
						strlen( $content ),
						strlen( $newContent )
					), 'gddealer_upg_10184' ); } catch ( \Throwable ) {}
				}
				else
				{
					try { \IPS\Log::log( sprintf(
						'gddealer v1.0.184 overview template_id=%d set_id=%d: no match (already patched, or v1.0.183 JS was different)',
						(int) $row['template_id'],
						(int) $row['template_set_id']
					), 'gddealer_upg_10184' ); } catch ( \Throwable ) {}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gddealer v1.0.184 overview CSRF patch failed: ' . $e->getMessage(), 'gddealer_upg_10184' ); } catch ( \Throwable ) {}
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
		return 'gddealer v1.0.184 - hotfix CSRF on Regenerate slug button';
	}
}

class upgrade extends _upgrade {}
