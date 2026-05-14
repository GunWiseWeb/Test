<?php
namespace IPS\gddealer\setup\upg_10182;

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
		/* gddealer v1.0.182 - Fix broken View URL + slug history with 301 redirects.
		 *
		 * Three deliverables in this ship:
		 *
		 * 1) Fix broken admin "View" URL. The admin dealer detail page was
		 *    building URLs like:
		 *      /admin/?app=gddealer&module=dealers&controller=profile&dealer_slug=we-are-it
		 *    which resolves to a non-existent admin controller. The CORRECT
		 *    URL goes through the dealers_profile FURL key to produce:
		 *      /dealer/we-are-it/
		 *    Fix: pass 4th+5th params to Url::internal() so it routes through
		 *    the FURL system to the front-end profile.
		 *
		 * 2) Centralized slug helper (new sources/Dealer/Slug.php). Provides
		 *    generate() (from name), regenerate() (manual button trigger,
		 *    records old slug in history), and recordHistory() (used when
		 *    slugs change for any reason).
		 *
		 * 3) New gd_dealer_slug_history table records old slugs. When the
		 *    front profile.php route handler can't find a dealer by the
		 *    incoming slug, it falls back to slug_history and 301-redirects
		 *    to the dealer's CURRENT slug. Old URLs keep working forever.
		 *
		 * Both admin AND dealer-side regenerate slug buttons ship in this
		 * version (admin: dealerDetail template, dealer: dashboardCustomize).
		 *
		 * Schema:
		 *   id              auto-inc PK
		 *   old_slug        varchar(100), UNIQUE - because each old slug can
		 *                   only point at one dealer (slugs are globally unique)
		 *   dealer_id       int, FK conceptual to gd_dealer_feed_config.dealer_id
		 *   retired_at      datetime, when the slug was retired
		 *   retired_reason  varchar(50), e.g. 'manual_regenerate' / 'admin_change'
		 *
		 * Per CLAUDE.md rule #51: sanity check vs PREVIOUS version (10181). */

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
				'gddealer v1.0.182 sanity (pre-version-write): app_long_version=%d, app_version=%s',
				$longVer,
				(string) ( $row['app_version'] ?? '' )
			);
			try { \IPS\Log::log( $msg, 'gddealer_upg_10182' ); } catch ( \Throwable ) {}

			if ( $longVer < 10181 )
			{
				$warning = sprintf(
					'gddealer v1.0.182 WARNING: app_long_version=%d below 10181',
					$longVer
				);
				try { \IPS\Log::log( $warning, 'gddealer_upg_10182' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gddealer v1.0.182 sanity check failed: ' . $e->getMessage(), 'gddealer_upg_10182' ); } catch ( \Throwable ) {}
		}

		/* Step 2: CREATE TABLE gd_dealer_slug_history if not exists.
		 *
		 * Idempotent - SHOW TABLES check first. Per CLAUDE.md memory: always
		 * verify actual table schema before CREATE/ALTER. */
		$alreadyExists = false;
		try
		{
			foreach ( \IPS\Db::i()->query( "SHOW TABLES LIKE 'gd_dealer_slug_history'" ) as $r )
			{
				$alreadyExists = true;
				break;
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gddealer v1.0.182 SHOW TABLES failed: ' . $e->getMessage(), 'gddealer_upg_10182' ); } catch ( \Throwable ) {}
		}

		if ( $alreadyExists )
		{
			try { \IPS\Log::log( 'gddealer v1.0.182 table gd_dealer_slug_history already exists, skipping CREATE', 'gddealer_upg_10182' ); } catch ( \Throwable ) {}
		}
		else
		{
			$prefix = \IPS\Db::i()->prefix;
			$sql = "CREATE TABLE `{$prefix}gd_dealer_slug_history` (
				`id` int(10) unsigned NOT NULL AUTO_INCREMENT,
				`old_slug` varchar(100) NOT NULL,
				`dealer_id` int(10) unsigned NOT NULL,
				`retired_at` datetime NOT NULL,
				`retired_reason` varchar(50) NOT NULL DEFAULT 'manual_regenerate',
				PRIMARY KEY (`id`),
				UNIQUE KEY `uq_old_slug` (`old_slug`),
				KEY `idx_dealer_id` (`dealer_id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

			try
			{
				\IPS\Db::i()->query( $sql );
				try { \IPS\Log::log( 'gddealer v1.0.182 created table gd_dealer_slug_history', 'gddealer_upg_10182' ); } catch ( \Throwable ) {}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'gddealer v1.0.182 CREATE TABLE failed: ' . $e->getMessage(), 'gddealer_upg_10182' ); } catch ( \Throwable ) {}
			}
		}

		/* Step 3: Add lang strings for the new UI elements. Per CLAUDE.md
		 * memory: ONLY 6 columns (lang_id, word_app, word_key, word_default,
		 * word_js, word_export). Per-row try/catch required. */
		$langStrings = [
			'gddealer_regenerate_slug_btn'        => 'Regenerate URL slug',
			'gddealer_regenerate_slug_title'      => 'Regenerate URL slug',
			'gddealer_regenerate_slug_current'    => 'Current URL slug',
			'gddealer_regenerate_slug_new'        => 'New URL slug',
			'gddealer_regenerate_slug_warning'    => 'The old URL will 301-redirect to the new URL. Already-shared links will keep working.',
			'gddealer_regenerate_slug_confirm'    => 'Regenerate slug',
			'gddealer_regenerate_slug_cancel'     => 'Cancel',
			'gddealer_regenerate_slug_done'       => 'URL slug regenerated. Old URL will redirect.',
			'gddealer_regenerate_slug_unchanged'  => 'New slug matches current slug - nothing to change.',
			'gddealer_regenerate_slug_failed'     => 'Failed to regenerate slug. Try again or contact support.',
		];

		foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
		{
			foreach ( $langStrings as $key => $default )
			{
				try
				{
					\IPS\Db::i()->replace( 'core_sys_lang_words', [
						'lang_id'       => (int) $langId,
						'word_app'      => 'gddealer',
						'word_key'      => $key,
						'word_default'  => $default,
						'word_js'       => 0,
						'word_export'   => 1,
					] );
				}
				catch ( \Throwable $e )
				{
					try { \IPS\Log::log( sprintf( 'gddealer v1.0.182 lang insert failed for %s/%d: %s', $key, (int) $langId, $e->getMessage() ), 'gddealer_upg_10182' ); } catch ( \Throwable ) {}
				}
			}
		}

		/* Step 4: Template patch — add Regenerate button + modal to dealerDetail.
		 * Same str_replace approach as v1.0.181 — SELECT rows, str_replace, UPDATE.
		 * Idempotent (re-run on already-patched content is a no-op). */
		$oldViewBlock = '<code style="font-size:0.85em;background:#f4f4f4;padding:6px 10px;border-radius:4px;flex:1;word-break:break-all">{$dealer[\'profile_url\']}</code>
                                        <a href="{$dealer[\'profile_url\']}" target="_blank" class="ipsButton ipsButton--normal ipsButton--small">View</a>';

		$newViewBlock = '<code style="font-size:0.85em;background:#f4f4f4;padding:6px 10px;border-radius:4px;flex:1;word-break:break-all">{$dealer[\'profile_url\']}</code>
                                        <a href="{$dealer[\'profile_url\']}" target="_blank" class="ipsButton ipsButton--normal ipsButton--small">View</a>
                                        <button type="button" class="ipsButton ipsButton--normal ipsButton--small" data-gd-regenerate-slug data-dealer-id="{$dealer[\'dealer_id\']}" data-preview-url="{$dealer[\'regenerate_preview_url\']}" data-confirm-url="{$dealer[\'regenerate_confirm_url\']}">Regenerate</button>';

		$modalHtml = '
<!-- v1.0.182: Regenerate slug confirmation modal -->
<div id="gd-regen-slug-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center" data-gd-regen-modal>
	<div style="background:#fff;border-radius:8px;padding:24px;max-width:480px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,0.2)">
		<h3 style="margin:0 0 16px;font-size:1.2em">Regenerate URL slug</h3>
		<div style="margin-bottom:12px">
			<div style="font-size:0.85em;color:#666;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">Current slug</div>
			<code id="gd-regen-old" style="display:block;background:#f4f4f4;padding:8px 12px;border-radius:4px;font-size:0.95em">—</code>
		</div>
		<div style="margin-bottom:12px">
			<div style="font-size:0.85em;color:#666;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">New slug</div>
			<code id="gd-regen-new" style="display:block;background:#e8f5e9;padding:8px 12px;border-radius:4px;font-size:0.95em">—</code>
		</div>
		<p style="margin:16px 0;padding:12px;background:#fff8e1;border-left:3px solid #ffa726;font-size:0.9em">The old URL will 301-redirect to the new URL. Already-shared links will keep working.</p>
		<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:20px">
			<button type="button" class="ipsButton ipsButton--normal" data-gd-regen-cancel>Cancel</button>
			<form id="gd-regen-confirm-form" method="POST" action="" style="display:inline">
				<input type="hidden" name="csrfKey" id="gd-regen-csrf" value="">
				<button type="submit" class="ipsButton ipsButton--primary">Regenerate slug</button>
			</form>
		</div>
	</div>
</div>

<script>
(function(){
	var triggers = document.querySelectorAll(\'[data-gd-regenerate-slug]\');
	var modal = document.getElementById(\'gd-regen-slug-modal\');
	var oldEl = document.getElementById(\'gd-regen-old\');
	var newEl = document.getElementById(\'gd-regen-new\');
	var form = document.getElementById(\'gd-regen-confirm-form\');
	var csrfInput = document.getElementById(\'gd-regen-csrf\');
	if ( !modal || !triggers.length ) { return; }

	function openModal(trigger){
		var previewUrl = trigger.getAttribute(\'data-preview-url\');
		var confirmUrl = trigger.getAttribute(\'data-confirm-url\');
		oldEl.textContent = \'Loading…\';
		newEl.textContent = \'Loading…\';
		modal.style.display = \'flex\';
		fetch(previewUrl, {credentials: \'same-origin\'})
			.then(function(r){return r.json();})
			.then(function(d){
				oldEl.textContent = d.old_slug || \'(none)\';
				newEl.textContent = d.would_change ? d.new_slug : \'(no change needed)\';
				if ( !d.would_change ) {
					form.querySelector(\'button[type=submit]\').disabled = true;
					form.querySelector(\'button[type=submit]\').textContent = \'No change needed\';
				} else {
					form.querySelector(\'button[type=submit]\').disabled = false;
					form.querySelector(\'button[type=submit]\').textContent = \'Regenerate slug\';
				}
				form.action = confirmUrl;
				var existingCsrf = document.querySelector(\'input[name=csrfKey]\');
				if ( existingCsrf ) { csrfInput.value = existingCsrf.value; }
			})
			.catch(function(){
				oldEl.textContent = \'Error\';
				newEl.textContent = \'Could not load preview.\';
			});
	}

	function closeModal(){ modal.style.display = \'none\'; }

	triggers.forEach(function(t){
		t.addEventListener(\'click\', function(e){ e.preventDefault(); openModal(t); });
	});

	modal.addEventListener(\'click\', function(e){
		if ( e.target === modal || e.target.hasAttribute(\'data-gd-regen-cancel\') ) { closeModal(); }
	});
})();
</script>';

		try
		{
			$rows = [];
			foreach ( \IPS\Db::i()->select(
				'template_id, template_set_id, template_content',
				'core_theme_templates',
				[ 'template_app=? AND template_name=?', 'gddealer', 'dealerDetail' ]
			) as $r )
			{
				$rows[] = $r;
			}

			try { \IPS\Log::log( sprintf( 'gddealer v1.0.182 found %d dealerDetail row(s) to patch', count( $rows ) ), 'gddealer_upg_10182' ); } catch ( \Throwable ) {}

			foreach ( $rows as $row )
			{
				$content    = (string) $row['template_content'];
				$newContent = $content;

				/* Patch: Add Regenerate button next to View */
				$before = $newContent;
				$newContent = str_replace( $oldViewBlock, $newViewBlock, $newContent );
				$buttonApplied = ( $before !== $newContent );

				/* Patch: Append modal HTML at the end of the template if not already there */
				$modalApplied = false;
				if ( strpos( $newContent, 'gd-regen-slug-modal' ) === false )
				{
					$newContent .= $modalHtml;
					$modalApplied = true;
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
						'gddealer v1.0.182 patched dealerDetail template_id=%d set_id=%d (%d->%d bytes) button=%s modal=%s',
						(int) $row['template_id'],
						(int) $row['template_set_id'],
						strlen( $content ),
						strlen( $newContent ),
						$buttonApplied ? 'applied' : 'skipped',
						$modalApplied ? 'applied' : 'skipped'
					), 'gddealer_upg_10182' ); } catch ( \Throwable ) {}
				}
				else
				{
					try { \IPS\Log::log( sprintf(
						'gddealer v1.0.182 dealerDetail template_id=%d set_id=%d: no changes (already patched or hand-edited)',
						(int) $row['template_id'],
						(int) $row['template_set_id']
					), 'gddealer_upg_10182' ); } catch ( \Throwable ) {}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gddealer v1.0.182 dealerDetail patch failed: ' . $e->getMessage(), 'gddealer_upg_10182' ); } catch ( \Throwable ) {}
		}

		/* Step 4.5: Patch dashboardCustomize template (dealer-side).
		 *
		 * Adds "Regenerate URL slug" button + confirmation modal after the
		 * existing Business name input. Patches BOTH set_id=0 and set_id=1
		 * rows. Idempotent via str_replace + presence-check on modal block.
		 *
		 * The JS inside uses ONLY `var` declarations (no $-prefix), so the
		 * IPS template engine won't try to interpolate them. */

		$oldBusinessNameField = '<label class="gdField__label gdField__label--required">Business name</label>
            <input type="text" name="dealer_name" value="{$data[\'profile\'][\'dealer_name\']}" maxlength="150" class="gdInput" required>
        </div>';

		$newBusinessNameField = '<label class="gdField__label gdField__label--required">Business name</label>
            <input type="text" name="dealer_name" value="{$data[\'profile\'][\'dealer_name\']}" maxlength="150" class="gdInput" required>
            <div style="margin-top:8px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <div style="font-size:12px;color:#64748b">Current URL slug: <code style="background:#f1f5f9;padding:2px 6px;border-radius:3px;font-size:11px">{$data[\'profile\'][\'dealer_slug\']}</code></div>
                <button type="button" class="gdBtn gdBtn--secondary" style="padding:4px 10px;font-size:12px" data-gd-regenerate-slug data-preview-url="{$data[\'regenerate_preview_url\']}" data-confirm-url="{$data[\'regenerate_confirm_url\']}">Regenerate URL slug</button>
            </div>
        </div>';

		$dealerModalBlock = <<<'MODALEOF'

<!-- v1.0.182: Regenerate slug confirmation modal (dealer-side) -->
<div id="gd-regen-slug-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center" data-gd-regen-modal>
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
		<p style="margin:16px 0;padding:12px;background:#fff8e1;border-left:3px solid #ffa726;font-size:0.9em;line-height:1.5"><strong>Heads up:</strong> the old URL will 301-redirect to the new URL. Already-shared links keep working. Save any unsaved profile changes first &mdash; regenerate uses the current saved name, not what you may have typed.</p>
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
				var existingCsrf = document.querySelector('input[name=csrfKey]');
				if ( existingCsrf ) { csrfInput.value = existingCsrf.value; }
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
			$dcRows = [];
			foreach ( \IPS\Db::i()->select(
				'template_id, template_set_id, template_content',
				'core_theme_templates',
				[ 'template_app=? AND template_name=?', 'gddealer', 'dashboardCustomize' ]
			) as $r )
			{
				$dcRows[] = $r;
			}

			try { \IPS\Log::log( sprintf( 'gddealer v1.0.182 found %d dashboardCustomize row(s) to patch', count( $dcRows ) ), 'gddealer_upg_10182' ); } catch ( \Throwable ) {}

			foreach ( $dcRows as $row )
			{
				$content    = (string) $row['template_content'];
				$newContent = $content;
				$applied    = [];
				$skipped    = [];

				/* Patch 1: Business name field gets slug display + button */
				$before = $newContent;
				$newContent = str_replace( $oldBusinessNameField, $newBusinessNameField, $newContent );
				if ( $before !== $newContent ) { $applied[] = 'business_name_field'; } else { $skipped[] = 'business_name_field'; }

				/* Patch 2: Modal block appended (only if not already present) */
				if ( strpos( $newContent, 'gd-regen-slug-modal' ) === false )
				{
					$newContent .= $dealerModalBlock;
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
						'gddealer v1.0.182 patched dashboardCustomize template_id=%d set_id=%d (%d->%d bytes) applied=[%s] skipped=[%s]',
						(int) $row['template_id'],
						(int) $row['template_set_id'],
						strlen( $content ),
						strlen( $newContent ),
						implode( ',', $applied ),
						implode( ',', $skipped )
					), 'gddealer_upg_10182' ); } catch ( \Throwable ) {}
				}
				else
				{
					try { \IPS\Log::log( sprintf(
						'gddealer v1.0.182 dashboardCustomize template_id=%d set_id=%d: no changes (already patched, or template hand-edited)',
						(int) $row['template_id'],
						(int) $row['template_set_id']
					), 'gddealer_upg_10182' ); } catch ( \Throwable ) {}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gddealer v1.0.182 dashboardCustomize patch failed: ' . $e->getMessage(), 'gddealer_upg_10182' ); } catch ( \Throwable ) {}
		}

		/* Step 5: Cache invalidation. Critical - IPS caches everything. */
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
		return 'gddealer v1.0.182 - fix View URL + slug history with 301 redirects';
	}
}

class upgrade extends _upgrade {}
