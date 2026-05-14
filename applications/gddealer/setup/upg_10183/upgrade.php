<?php
namespace IPS\gddealer\setup\upg_10183;

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
		/* gddealer v1.0.183 - Add "Regenerate" button next to the dealer's
		 * displayed slug URL on the dashboard overview page.
		 *
		 * Context: v1.0.182 added the regenerate plumbing (table, helper,
		 * controller methods, redirects). It tried to put the button on
		 * the dashboardCustomize template's Business name input - wrong
		 * location, dealer doesn't see it there.
		 *
		 * This ship puts the button where the slug is ACTUALLY displayed:
		 * on the overview page, in the identity header card, right next
		 * to "gunrack.deals/dealers/we-are-it".
		 *
		 * Two changes:
		 *
		 *   1) overview template patch: add Regenerate button after the
		 *      .gdIdentity__url paragraph + append confirmation modal
		 *      block at end of template.
		 *
		 *   2) overview() controller adds regenerate_preview_url +
		 *      regenerate_confirm_url to the $data array so the template
		 *      can wire up the button. (PHP source patch - separate file,
		 *      see v183_PHP_PATCHES.md)
		 *
		 * Baseline md5: ca2c442279b4cf983fbe2727e0b1d4cd (12813 bytes,
		 * single row template_set_id=1, no customized set_id=0 to worry
		 * about - simpler than dashboardCustomize was).
		 *
		 * template_data signature stays as '$data' (single arg). No
		 * arg-order risk like v1.0.36/37.
		 *
		 * Per CLAUDE.md rule #51: sanity check vs PREVIOUS version (10182). */

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
				'gddealer v1.0.183 sanity (pre-version-write): app_long_version=%d, app_version=%s',
				$longVer,
				(string) ( $row['app_version'] ?? '' )
			);
			try { \IPS\Log::log( $msg, 'gddealer_upg_10183' ); } catch ( \Throwable ) {}

			if ( $longVer < 10182 )
			{
				$warning = sprintf(
					'gddealer v1.0.183 WARNING: app_long_version=%d below 10182',
					$longVer
				);
				try { \IPS\Log::log( $warning, 'gddealer_upg_10183' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gddealer v1.0.183 sanity check failed: ' . $e->getMessage(), 'gddealer_upg_10183' ); } catch ( \Throwable ) {}
		}

		/* Step 2: Patch the overview template.
		 *
		 * Two patches:
		 *
		 * Patch A: Right after the .gdIdentity__url paragraph, insert a
		 * small Regenerate button so it sits next to the displayed slug URL.
		 *
		 * Patch B: Append the confirmation modal HTML+JS block at end of
		 * template (after closing tags).
		 *
		 * Both via PHP str_replace; idempotent via presence-check.
		 *
		 * Nowdoc heredoc used to avoid PHP $variable interpolation. JS
		 * uses only `var` declarations to avoid IPS template engine
		 * eating $-prefixed names. */

		$oldUrlLine = '<p class="gdIdentity__url">gunrack.deals/dealers/{$data[\'dealer\'][\'dealer_slug\']}</p>';

		$newUrlLine = '<p class="gdIdentity__url">gunrack.deals/dealers/{$data[\'dealer\'][\'dealer_slug\']}
                <button type="button" class="gdBtn gdBtn--secondary" style="margin-left:8px;padding:2px 10px;font-size:11px;vertical-align:middle" data-gd-regenerate-slug data-preview-url="{$data[\'regenerate_preview_url\']}" data-confirm-url="{$data[\'regenerate_confirm_url\']}">Regenerate</button>
            </p>';

		$modalBlock = <<<'MODALEOF'

<!-- v1.0.183: Regenerate slug confirmation modal (overview page) -->
<div id="gd-regen-slug-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center">
	<div style="background:#fff;border-radius:8px;padding:24px;max-width:520px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,0.2)">
		<h3 style="margin:0 0 16px;font-size:1.2em;font-weight:600">Regenerate URL slug</h3>
		<div style="margin-bottom:12px">
			<div style="font-size:0.8em;color:#666;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">Current slug</div>
			<code id="gd-regen-old" style="display:block;background:#f4f4f4;padding:8px 12px;border-radius:4px;font-size:0.95em">&mdash;</code>
		</div>
		<div style="margin-bottom:12px">
			<div style="font-size:0.8em;color:#666;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">New slug</div>
			<code id="gd-regen-new" style="display:block;background:#e8f5e9;padding:8px 12px;border-radius:4px;font-size:0.95em">&mdash;</code>
		</div>
		<p style="margin:16px 0;padding:12px;background:#fff8e1;border-left:3px solid #ffa726;font-size:0.9em;line-height:1.5"><strong>Heads up:</strong> the old URL will 301-redirect to the new URL. Already-shared links keep working. The new slug is generated from your current saved business name.</p>
		<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:20px">
			<button type="button" class="gdBtn gdBtn--secondary" data-gd-regen-cancel>Cancel</button>
			<form id="gd-regen-confirm-form" method="POST" action="" style="display:inline">
				<input type="hidden" name="csrfKey" id="gd-regen-csrf" value="">
				<button type="submit" class="gdBtn gdBtn--primary" id="gd-regen-submit">Regenerate slug</button>
			</form>
		</div>
	</div>
</div>
<script>
(function(){
	var triggers = document.querySelectorAll('[data-gd-regenerate-slug]');
	var modal = document.getElementById('gd-regen-slug-modal');
	var oldEl = document.getElementById('gd-regen-old');
	var newEl = document.getElementById('gd-regen-new');
	var form = document.getElementById('gd-regen-confirm-form');
	var csrfInput = document.getElementById('gd-regen-csrf');
	var submitBtn = document.getElementById('gd-regen-submit');
	if ( !modal || !triggers.length ) { return; }

	function openModal(trigger){
		var previewUrl = trigger.getAttribute('data-preview-url');
		var confirmUrl = trigger.getAttribute('data-confirm-url');
		oldEl.textContent = 'Loading...';
		newEl.textContent = 'Loading...';
		submitBtn.disabled = false;
		submitBtn.textContent = 'Regenerate slug';
		modal.style.display = 'flex';
		fetch(previewUrl, {credentials: 'same-origin'})
			.then(function(r){return r.json();})
			.then(function(d){
				oldEl.textContent = d.old_slug || '(none)';
				if ( !d.would_change ) {
					newEl.textContent = '(no change needed)';
					submitBtn.disabled = true;
					submitBtn.textContent = 'No change needed';
				} else {
					newEl.textContent = d.new_slug;
					submitBtn.disabled = false;
					submitBtn.textContent = 'Regenerate slug';
				}
				form.action = confirmUrl;
				/* CSRF: reuse from existing IPS form on page if any,
				 * otherwise fetch a fresh key from any link that has csrf in it */
				var existingCsrf = document.querySelector('input[name=csrfKey]');
				if ( existingCsrf ) {
					csrfInput.value = existingCsrf.value;
				} else {
					/* Fallback: pull csrf from any csrf-suffixed URL on page */
					var anchors = document.querySelectorAll('a[href*="csrfKey="]');
					if ( anchors.length ) {
						var match = anchors[0].href.match(/csrfKey=([^&]+)/);
						if ( match ) { csrfInput.value = match[1]; }
					}
				}
			})
			.catch(function(){
				oldEl.textContent = 'Error';
				newEl.textContent = 'Could not load preview.';
				submitBtn.disabled = true;
			});
	}

	function closeModal(){ modal.style.display = 'none'; }

	triggers.forEach(function(t){
		t.addEventListener('click', function(e){ e.preventDefault(); openModal(t); });
	});

	modal.addEventListener('click', function(e){
		if ( e.target === modal || e.target.hasAttribute('data-gd-regen-cancel') ) { closeModal(); }
	});
})();
</script>

MODALEOF;

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

			try { \IPS\Log::log( sprintf( 'gddealer v1.0.183 found %d overview row(s) to patch', count( $rows ) ), 'gddealer_upg_10183' ); } catch ( \Throwable ) {}

			foreach ( $rows as $row )
			{
				$content    = (string) $row['template_content'];
				$newContent = $content;
				$applied    = [];
				$skipped    = [];

				/* Patch A: Add Regenerate button next to slug URL */
				$before = $newContent;
				$newContent = str_replace( $oldUrlLine, $newUrlLine, $newContent );
				if ( $before !== $newContent ) { $applied[] = 'slug_url_button'; } else { $skipped[] = 'slug_url_button'; }

				/* Patch B: Append modal block (only if not already present) */
				if ( strpos( $newContent, 'gd-regen-slug-modal' ) === false )
				{
					$newContent .= $modalBlock;
					$applied[] = 'modal_block';
				}
				else
				{
					$skipped[] = 'modal_block';
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
						'gddealer v1.0.183 patched overview template_id=%d set_id=%d (%d->%d bytes) applied=[%s] skipped=[%s]',
						(int) $row['template_id'],
						(int) $row['template_set_id'],
						strlen( $content ),
						strlen( $newContent ),
						implode( ',', $applied ),
						implode( ',', $skipped )
					), 'gddealer_upg_10183' ); } catch ( \Throwable ) {}
				}
				else
				{
					try { \IPS\Log::log( sprintf(
						'gddealer v1.0.183 overview template_id=%d set_id=%d: no changes (already patched, or template hand-edited)',
						(int) $row['template_id'],
						(int) $row['template_set_id']
					), 'gddealer_upg_10183' ); } catch ( \Throwable ) {}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gddealer v1.0.183 overview patch failed: ' . $e->getMessage(), 'gddealer_upg_10183' ); } catch ( \Throwable ) {}
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
		return 'gddealer v1.0.183 - Regenerate slug button on overview page';
	}
}

class upgrade extends _upgrade {}
