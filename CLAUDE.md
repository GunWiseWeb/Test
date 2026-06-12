# GunRack.deals — Project CLAUDE.md

## What this project is
GunRack (gunrack.deals) is a firearms price comparison and community platform built on IPS Community Suite. It competes with gun.deals. Full technical specification is in `GunRack_Spec_v2.9.16.md` in this repo root — read that file first before writing any code.

## Critical rules — read before touching anything
1. **Never start on Plugin 2 or Plugin 3 before Plugin 1 has completed two full successful import cycles across all six distributors.** This is a hard gate in the spec.
2. **All SQL queries must use IPS parameterized queries — no string interpolation of user input into queries ever.**
3. **Redis must be bound to 127.0.0.1 with requirepass set before the server goes live.** See Appendix C.
4. **XML feed parsing must pass `LIBXML_NONET` to `simplexml_load_string()` flags. Do NOT call `libxml_disable_entity_loader()` — it is deprecated in PHP 8.0+ and triggers fatal errors in IPS's scheduled-task error handler.**
5. **All 12 plugins must register CSRF token validation on every state-changing front-end action.** See Appendix C.
6. **Never store SES credentials or API keys in source code or commit them to Git.**
7. **Never overwrite `dev/lang.php` from scratch — always ADD to the existing file.** Before writing anything to `dev/lang.php`: (1) read the FULL existing file, (2) ADD new strings to the existing array — NEVER regenerate the whole file. The file must contain ALL strings from previous versions PLUS new ones. Wiping and rewriting loses accumulated strings from dozens of prior versions.

## Known landmines — check these before shipping any plugin build
These are the high-friction spots that have broken production deploys multiple times. Skim this list first, then see the numbered rule for details.
- `data/extensions.json` stripped during build or on server writeback — see rule #16
- `data/emails.xml` whitespace breaks IPS's XMLReader and silently parses zero templates — see rule #18
- New admin templates added only to `install.php`, not seeded into existing installs via `templates_XXXXX.php` — see rule #19
- Notification registered in the extension but never seeded into `core_notification_defaults` — see rule #24
- Email send and bell notification nested in the same `try/catch`, so an email failure silently eats the notification — see rule #25
- `\IPS\Output::i()->redirect( $url, 'message' )` shows a ~1 second black interstitial on front-end controllers — see rule #21
- `app_long_version` stuck at a low value so upgrades never run — always check the DB row before debugging "my upgrade step didn't execute"
- `\IPS\Db::i()->insertId()` does not exist — `insert()` returns the ID directly (rule #20)
- Adding `app_version` fields to `data/application.json` triggers "Unknown column" on upgrade — only `data/versions.json` holds versions (rule #23)
- `setup/templates_XXXXX.php` using regex extraction from `install.php` silently corrupts newlines — see rule #28
- `\IPS\Output::i()->headCss[]` does not exist — silently ignored or throws — see rule #29
- Template calls `{template="otherTemplate"}` but the referenced template was never seeded — EX0 with no log — see rule #30
- Task declared complete after `php -l` passes, but page throws EX0 at runtime — see rule #31

## Tech stack
- **Platform:** IPS Community Suite (self-hosted)
- **Server:** Standard NVMe — 4 Core, 10GB RAM, 140GB NVMe, 2x IPv4
- **Search:** OpenSearch self-hosted on search.gunrack.deals (Nginx reverse proxy, IP allowlisted)
- **Email:** Amazon SES with dedicated IP
- **Cache:** Redis (localhost only)
- **Payments:** IPS Commerce + Stripe
- **Domain:** gunrack.deals (IPv4 #1 → gunrack.deals, IPv4 #2 → search.gunrack.deals)

## Plugin build order (do not deviate)
| Phase | Weeks | What |
|---|---|---|
| 1 | 1–4 | Server setup → IPS install → Plugin 1 (Master Catalog) |
| 2 | 5–8 | Plugin 2 (Dealers) + Plugin 3 (Price Compare) in parallel |
| 3 | 9–10 | Plugin 4 (Reviews) + Plugin 5 (Rebates) |
| 4 | 11–12 | Plugin 6 (Disputes) + Plugin 7 (Power Features / VIP) |
| 5 | 13–14 | Plugin 8 (SEO) + Plugin 9 (Email) |
| 6 | 15–16 | Plugin 10 (Deal Posts) + Plugin 11 (Loadouts) |
| 7 | 17–18 | Plugin 12 (Forum Integration) + Section 16 (Leaderboard) |
| 8 | 19–20 | Section 17 (Homepage App) + Section 18 (Dealer Onboarding) |
| 9 | 21–22 | QA, security checklist (Appendix C), launch prep |

## Six distributors (Plugin 1)
Sports South · RSR Group (conflict resolution priority #1) · Zanders · Davidson's · Lipsey's · Bill Hicks

## Key database tables (quick reference)
- `gd_catalog` — master product catalog keyed by UPC
- `gd_dealer_listings` — per-dealer pricing and stock
- `gd_price_history` — price snapshots for heat scoring and history charts
- `gd_compliance_flags` — state restrictions and compliance data (source = distributor|admin_manual|admin_override)
- `gd_feed_conflicts` — incoming feed values that conflict with database (status = pending|accepted|kept|custom|auto_accepted)
- `gd_field_locks` — fields locked against distributor overwrites (lock_type = distributor_specific|hard)
- `gd_loadouts` — community loadout builds
- `gd_loadout_items` — items within a loadout (slot-based)
- `gd_loadout_forum_posts` — tracks forum shares for rate limiting
- `gd_feed_conflicts` — auto-resolves after 48 hours if admin doesn't act
- `gd_deal_posts` — community deal submissions
- `gd_rebates` — manufacturer rebates

## IPS extensions used (not custom pages)
- **Homepage:** GunRack Deals registered as IPS application, set as default homepage via ACP → System → Site Promotion → Default Application
- **Member profiles:** Four IPS profile tab extensions (Deals, Reviews, Builds, Wishlists) — NO new profile page
- **Leaderboard:** IPS native leaderboard + Points system + custom tab extensions for dealer metrics
- **Member groups:** Managed via IPS Commerce group promotions (not manually)

## Security checklist location
Full 20-item pre-launch security checklist is in **Appendix C Section C.8** of the spec. Nothing goes live until every item is checked.

## Pre-development actions (start these immediately, parallel with dev)
1. RSR Group — apply for technology/data partner feed access (blocks Plugin 1)
2. Sports South — same (blocks Plugin 1 catalog completeness)  
3. Amazon SES setup + SPF/DKIM/DMARC DNS records
4. IPS Community Suite license purchase
5. Chrome Web Store + Firefox AMO developer accounts (extension takes 1–7 days to review)
6. 2–3 founding FFL dealers confirmed for free 90-day trial (needed to test Plugin 2)

## IPS v5 third-party application requirements
These were learned by comparing against a working IPS v5 plugin. They apply to every application in this project.

1. **Application.php needs BOTH classes** — `class _Application extends \IPS\Application` AND `class Application extends _Application {}` below it. Both are required: the underscore class is what IPS resolves via its autoloader; the non-underscore alias is required for PHP to locate the class when instantiated normally.
2. **Controllers need BOTH classes** — e.g. `class _dashboard extends \IPS\Dispatcher\Controller` AND `class dashboard extends _dashboard {}` below it. Both are required for every controller in `modules/`.
3. **`execute()` MUST have `: void` return type** — the parent `\IPS\Dispatcher\Controller::execute()` signature requires it. Omitting it causes a fatal error.
4. **Templates are seeded via `setup/install.php`, not `data/theme.xml`** — IPS's XML import corrupts `{{-- comments --}}` to bare `-- comments --` and breaks template eval. Workaround: insert templates directly into `core_theme_templates` via `\IPS\Db::i()->insert()` in `setup/install.php`, using nowdoc heredocs (`<<<'TEMPLATE_EOT'`) to preserve comment syntax literally. Required fields: `template_set_id=1`, `template_app`, `template_location='admin'`, `template_group='catalog'`, `template_name`, `template_data` (parameter list), `template_content`. Do NOT use `data/theme.xml`. Controllers call `\IPS\Theme::i()->getTemplate( 'catalog', 'gdcatalog', 'admin' )->templateName(...)` once the install has seeded the DB.
5. **Language strings go in `data/lang.xml`, not `dev/lang.php`** — IPS installs language strings from this XML file. Format: `<language><app key="appdir"><word key="...">` with CDATA values. The `dev/lang.php` file is for IN_DEV mode only.
6. **Tar must be packaged with files at root level — no parent folder** — `Application.php` must be the first entry at the tar root, not inside `gdcatalog/`. Paths are `Application.php`, `data/theme.xml`, `modules/admin/catalog/dashboard.php`, etc. Use PharData `addFromString()` (not `addFile()` which produces 0-byte files). Every directory must contain a blank `index.html`.
7. **ActiveRecord property types — exact declarations, copy verbatim** — every model that extends `\IPS\Patterns\ActiveRecord` must declare these three static properties with EXACTLY these visibilities and types. Any deviation (adding `?` where it doesn't belong, dropping `?` where it does, wrong visibility) is a fatal type-variance error against the parent class and will white-screen the ACP on autoload:

    ```php
    public static ?string $databaseTable   = 'table_name';  // nullable — ?string
    public static string  $databaseColumnId = 'id';         // NOT nullable — string
    public static string  $databasePrefix   = '';           // NOT nullable — string (empty string for no prefix)
    ```

    Rules:
    - `$databaseTable` is the **only** one that is nullable (`?string`). Parent declares it nullable; omitting the `?` errors.
    - `$databaseColumnId` is **never** nullable. Every ActiveRecord has a primary key column, so `string` is the only correct type. Do not write `?string`.
    - `$databasePrefix` is **never** nullable. If there is no prefix, use `''` (empty string), not `null`. Do not write `?string`.
    - All three are `public static`. Never change visibility.
    - Copy the three lines above verbatim into each new model file and change only the string values.
8. **Dashboard controllers must NOT make live OpenSearch calls** — `OpenSearchIndexer::i()->getStats()` and `->indexExists()` perform synchronous HTTP requests that hang the ACP page indefinitely when the cluster is slow or unreachable. On the dashboard `manage()` method set `$osExists = FALSE` and `$osStats = []` as hardcoded values, and move all real index work into the dedicated `rebuildIndex` / `processQueue` actions the admin triggers explicitly. Every DB query on the dashboard must be wrapped in its own `try { ... } catch ( \Exception ) {}` block so one missing table cannot break the whole page.
9. **IPS templates have no comment syntax** — `{{-- comment --}}` is not parsed by the IPS template compiler and HTML `<!-- -->` gets rendered to the page. Do not put any comments inside templates seeded via `setup/install.php`. Use PHP nowdoc heredocs (`<<<'TEMPLATE_EOT'`) for the `template_content` column so real newlines/tabs are preserved; verify with `SELECT HEX(SUBSTRING(template_content,1,50))` that the first bytes include `0A`/`09`, not `5C6E`/`5C74`.
10. **`Application.php` must be the first entry in the tar file** — IPS's installer inspects the very first tar header to identify the application and will reject or misinstall the package if anything else precedes it (including `data/`, `dev/`, or a stray `index.html`). The build command must explicitly list `Application.php` first before any directories, e.g. `tar -cf gdcatalog-v1.0.0.tar Application.php data/ modules/ sources/ tasks/ setup/ dev/ index.html`. When building via `PharData::addFromString()`, add `Application.php` in the very first call before iterating directories. Verify with `tar -tf gdcatalog-v1.0.0.tar | head -1` — the first line must be `Application.php`.
11. **Application `get__icon()` must be `public` with `: string` return type** — the parent `\IPS\Application::get__icon()` is declared `public function get__icon(): string`. Child overrides must match exactly: `public function get__icon(): string { return 'database'; }`. A `protected` override with a `public` parent triggers a fatal LSP-visibility error that white-screens the ACP; omitting the return type triggers a fatal signature-mismatch error. IPS core apps (forums, blog, etc.) all use this exact pattern.
12. **IPS template syntax — only these proven safe patterns** — anything outside this list risks an `UnexpectedValueException` at compile time. Keep templates as dumb as possible; move all logic to the controller.
    1. `{$variable}` — simple variable output. Do not mix subscripts with arrow access (`{$ds['feed']->priority}` is illegal — flatten to scalars in the controller).
    2. `{expression="php_expression"}` — arbitrary PHP. Use this for `number_format(...)`, `htmlspecialchars(...)`, etc.
    3. `{{if condition}}...{{else}}...{{endif}}` — conditions must be simple (`$x`, `$x === 'foo'`, `count($x) > 0`). Avoid nested function calls inside the condition.
    4. `{{foreach $array as $item}}...{{endforeach}}` — the loop source must be a plain array variable, never an object-property chain.
    5. `{lang="key"}` — language strings.
    6. **Never** nest a `{url="..."}` tag inside an `{{if}}` block that depends on per-row data; the tokenizer evaluates tag arguments in a single pass and will break on the inner `{$...}`.
    7. **Never** use `->` object access inside a `{url=...}`, `{lang=...}`, or `{expression=...}` tag parameter where it sits next to an array subscript.
    8. For links that need dynamic IDs, build the full URL in the controller with `\IPS\Http\Url::internal(...)->csrf()` cast to string, and pass it as a scalar template variable (e.g. `$ds['run_import_url']`). The template then renders `<a href="{$ds['run_import_url']}">`.
    9. If you reach for any syntax not in this list, stop and push the logic back into the controller instead.
13. **Never use anonymous functions, closures, or `array_filter`/`array_map`/`array_walk`/`usort` with callables inside `{expression="..."}` template tags** — the IPS template compiler cannot tokenize PHP closures (`function( $f ) { ... }` or `fn( $f ) => ...`) inside expression tag arguments and throws `UnexpectedValueException` or silently emits broken PHP. Safe expressions are flat calls only: `number_format($x)`, `htmlspecialchars($x)`, `count($array)`, `strtoupper($x)`, `$x ? 'a' : 'b'`. Anything requiring a callback — counting filtered items, transforming a list, sorting — must be computed in the controller and passed as a pre-built scalar (e.g. `$activeFeedCount`, `$configuredUrlCount`) that the template prints directly via `{$activeFeedCount}`.
14. **ACP sidebar tab icons are set via a language key in `lang.xml`, NOT via `get__icon()`** — in IPS v5 the left-sidebar tab glyph is driven by the language string `menutab__{app_directory}_icon`; `Application::get__icon()` does not control it. The value is a FontAwesome icon name with no `fa-` prefix (e.g. `database`, `shield`, `tag`, `users`, `chart-bar`). Example for gdcatalog: `<word key="menutab__gdcatalog_icon"><![CDATA[database]]></word>`. Every future plugin must include this key in `lang.xml` or the tab will render with no icon. `get__icon()` must still exist on the Application class with the `: string` return type (see Rule #11) — other parts of IPS read it — but it does not determine the ACP sidebar icon.
15. **IPS 5 FURL tokens — only three exist** — in `data/furl.json` friendly/real patterns, the ONLY valid tokens are:
    - `{#param}` — matches a numeric value only (e.g. integer IDs)
    - `{@param}` — matches an alphanumeric string including hyphens (use this for slugs, action names, subpage keys — anything that is text or mixed)
    - `{?}` — SEO title placeholder, no parameter name
    
    Any other token form — `{!param}`, `{$param}`, `{*param}`, `{%param}` — does not exist in IPS 5 and silently breaks the route (it either fails to register or matches nothing). Common mistake: using `{#do}` for string action values like `feedSettings`, `listings`, `rate`, `guidelines` — those need `{@do}` because `{#}` only accepts digits. Rule of thumb: if the URL segment is not strictly a positive integer, use `{@param}`, never `{#param}`. More specific friendly patterns must be listed BEFORE wildcard patterns in `furl.json` so literal paths win over slug capture — e.g. `review-guidelines` must appear before `profile/{@dealer_slug}`.
16. **`data/extensions.json` is the source of truth for extension registration** — every extension class under `extensions/<group>/<type>/` must have a corresponding entry in `data/extensions.json` using the fully qualified namespace with **double-backslashes** in JSON. Format: `"<Type>": { "<ClassName>": "IPS\\<app>\\extensions\\<group>\\<Type>\\<ClassName>" }`. If the file exists on disk but isn't registered, IPS cannot see it — any code depending on that extension throws `OutOfBoundsException`. This is especially fatal for `EditorLocations` since `\IPS\Helpers\Form\Editor` fails to construct when its `key` option isn't registered. IPS has been observed to overwrite `data/extensions.json` from a stale datastore cache during concurrent requests on upgrade, stripping entries. Every upgrade step should defensively self-heal the file: check each required registration, rewrite the file if anything is missing, then `unset( \IPS\Data\Store::i()->extensions )`. See `applications/gddealer/setup/upg_10033/upgrade.php` for the reference implementation.
17. **Build-time verification — inspect the tar, not just the repo** — pre-build greps on source files are necessary but not sufficient. The repo can be correct and the tar can still ship with missing or stripped content; this has happened multiple times. Every build process must end with a verification pass that extracts the tar (or uses `tar -xOf`) and greps the extracted files against the expected state. No tar is handed off until the extracted contents match the source. Specifically verify: `data/extensions.json`, `data/versions.json`, `data/emails.xml`, every `extensions/*/*/*.php` file, and `Application.php` as the first tar entry. Example:
    ```bash
    tar -xOf applications/<app>/<app>-v<ver>.tar data/extensions.json | grep <ClassName>
    tar -tf applications/<app>/<app>-v<ver>.tar | head -1    # must print Application.php
    ```
18. **`data/emails.xml` whitespace is parser-sensitive** — IPS's `installEmailTemplates()` uses `XMLReader` with a loop that exits the moment `$xml->name` isn't `'template'`, **including whitespace text nodes**. Pretty-printed XML with tabs or newlines between `<emails>` and the first `<template>` (or between sibling `<template>` elements) silently parses to **zero** templates. Core IPS `emails.xml` files are written with no whitespace between element siblings (e.g. `<emails><template><template_name>...`). Always verify before shipping:
    ```bash
    php -r '
    $xml = new XMLReader();
    $xml->open("applications/<app>/data/emails.xml");
    $xml->read();
    $count = 0;
    while ( $xml->read() && $xml->name == "template" ) {
        if ( $xml->nodeType != XMLReader::ELEMENT ) continue;
        $count++;
        while ( $xml->read() && $xml->name != "template" ) {}
    }
    echo "Templates parsed: $count\n";
    '
    ```
    Output must match the expected template count exactly. If it's less, the XML is malformed for IPS's parser regardless of whether it's valid XML.
19. **Seed templates in BOTH `install.php` AND `templates_XXXXX.php`** — `setup/install.php` runs on fresh installs only; `setup/templates_XXXXX.php` runs on upgrades. Adding template content to `install.php` without a corresponding `templates_XXXXX.php` means existing installs never get the new content and pages render blank. For every new admin or front template, seed it in both. Use `\IPS\Db::i()->replace()` in the upgrade seed — NOT `insert()` or `INSERT IGNORE` — so existing rows are overwritten with the new design. Same applies to notification defaults, email templates, and any other seeded data. After any upgrade, spot-check `core_theme_templates` to confirm the row exists; don't assume because the file is in the repo it landed in the DB.
20. **IPS 5 method signatures — never guess, always read core source** — before writing any call to an IPS API, read the core source in the reference IPS install. Verified APIs that have caused regressions:
    - `\IPS\Db::i()->insert( $table, $set, $onDupKey=FALSE, $ignoreErrors=FALSE ): int|string` returns the new row ID directly. **There is no `insertId()` method** — assign the return value of `insert()` to get the ID.
    - `\IPS\DateTime::ts( $unix )->localeDate()` / `->localeTime()` applies the viewer's profile timezone. Never use raw `date()` for user-visible timestamps — that ignores timezone.
    - `\IPS\Helpers\Form\Editor` constructor: `new Editor( string $name, mixed $defaultValue, ?bool $required, array $options, ... )`. Required `$options` keys: `app`, `key`, `autoSaveKey`, `attachIds`. Throws `OutOfBoundsException` if `$options['key']` is not registered in `data/extensions.json` under `EditorLocations`.
    - `\IPS\Text\Parser::parseStatic( string $value, ?array $attachIds, ?Member $member, string $area, ... )` parses editor HTML for display. `$area` format is `"<app>_<ExtensionClassName>"` (e.g. `'gddealer_Responses'`).
    - `\IPS\File::claimAttachments( $postKey, $id1, $id2, $id3 )` must be called after saving editor content to claim uploaded attachments. `$postKey` must match the `autoSaveKey` from the editor constructor.
21. **`\IPS\Output::i()->redirect()` with a message string shows a black interstitial page on the front-end** — passing a second argument (a message key) to `redirect()` causes IPS to render a "Please wait while we transfer you..." interstitial for ~1 second before the HTTP redirect. On sites with certain theme configurations this renders against a dark background and looks broken. For user-facing controllers in this project, use bare redirects: `\IPS\Output::i()->redirect( $url )` — no second argument. Admin controllers can keep messages since AdminCP chrome handles them fine.
22. **Upgrade step conventions** — every new feature that touches the DB or seed data needs `setup/upg_XXXXX/upgrade.php` where `XXXXX` matches the `long_version` from `data/versions.json`. Upgrade steps MUST be idempotent — safe to re-run. Use `INSERT IGNORE`, check-then-insert, or guards like `NOT LIKE '<%'` on data migrations. Always clear extension / application datastore caches at the end of every step:
    ```php
    try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Exception ) {}
    try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Exception ) {}
    try { \IPS\Data\Cache::i()->clearAll(); }            catch ( \Exception ) {}
    ```
    Never assume `\IPS\Application::load( $app )->installEmailTemplates()` will seed templates on its own — on existing installs with malformed `emails.xml` it silently inserts zero templates. Upgrade steps that rely on email templates should verify `core_email_templates` row counts afterward and fall back to direct inserts if needed.
23. **Never add version fields to `data/application.json`** — IPS reads version exclusively from `data/versions.json`. Adding `app_version` or `app_version_human` to `application.json` triggers "Unknown column" errors on upgrade. Version bumps only touch `data/versions.json`.
24. **Notifications must be registered in three places** — a new IPS bell-notification key needs all of: (1) registration in `extensions/core/Notifications/<NotificationsExtension>.php` under `configurationOptions()` AND a matching `parse_<key>()` method, (2) seeding in `core_notification_defaults` via both `setup/install.php` AND `setup/upg_XXXXX/upgrade.php` for existing installs, (3) language strings for `<app>_notif_<key>` and `<app>_notif_<key>_desc` in `data/lang.xml`. Missing any of the three causes silent notification failures where the code appears to succeed but no bell ever appears.
25. **Email and bell notifications must be in independent `try/catch` blocks** — never nest an email send and a bell notification send in the same `try/catch`. If the email throws (template missing, SES failure, transient error), the shared catch swallows the exception and the notification is never sent. Always use two adjacent, completely independent `try/catch` blocks so a failure in one channel cannot suppress the other. When adding a new action that modifies reviews, disputes, or any user-visible state, audit every code path that can reach that state — not just the primary action. Edits typically go through a separate action (e.g. `editReview()`) that needs its own email + notification pair.
26. **Test the production deploy path, not just the repo state** — every completed feature must be verified by: (a) extracting the built tar to confirm all expected files are present, (b) running the IPS upgrade against a DB state that matches production, (c) spot-checking that new template content actually landed in `core_theme_templates` and not just in `setup/install.php`, (d) testing the user-facing flow in a browser with a non-admin account. "The code looks right in the repo" is not the bar. "The feature works on a clean IPS install after deploying the tar" is the bar.
27. **Every `setup/upg_XXXXX/upgrade.php` MUST be wrapped in `class _upgrade`** — IPS's upgrade runner instantiates `\IPS\<app>\setup\upg_XXXXX\Upgrade` (the class alias) and calls `step1()`, `step2()`, ... on it. A file that defines bare `function step1()` at namespace level without the class wrapper throws `"Class ... could not be loaded. Ensure it is in the correct namespace."` Required shape, matching rule #2's underscore/alias pattern:
    ```php
    namespace IPS\<app>\setup\upg_XXXXX;
    class _upgrade
    {
        public function step1(): bool
        {
            /* re-seed templates if applicable — upgrade.php alone doesn't
               trigger templates_XXXXX.php, you must require_once it. */
            require_once \IPS\ROOT_PATH . '/applications/<app>/setup/templates_XXXXX.php';
            /* cache clears at end */
            return TRUE;
        }
    }
    class upgrade extends _upgrade {}
    ```
    Every step must return `TRUE` to advance; returning anything else tells IPS to re-run the same step. Pre-build verification: `grep -c "class _upgrade" setup/upg_XXXXX/upgrade.php` must return `1`. If the upgrade exists solely to re-seed templates, it still needs the class wrapper AND the `require_once 'templates_XXXXX.php'` — without the require, the seed never runs even when the class loads correctly. Reference: `upg_10047`, `upg_10048`, `upg_10050`.
28. **Template seeds must embed content literally via nowdoc — never extract from other files at runtime** — `setup/templates_XXXXX.php` files must declare each template's full `template_content` as an inline nowdoc heredoc (`<<<'TEMPLATE_EOT'`) in the same file. Do NOT use `file_get_contents( install.php )`, regex extraction, or any form of "read the template from another file and re-insert it." Regex extraction over PHP-quoted strings silently converts real `\n` newlines into the two-character escape sequence `\n`, which IPS's template compiler does not unescape — the rendered page shows raw backslash-n text and/or throws on unparsed control flow tags. Reference check after any seed runs:

    ```sql
    SELECT template_name, template_content LIKE '%\\\\n%' AS has_literal_backslash_n
    FROM core_theme_templates
    WHERE template_app='<app>';
    ```

    The `has_literal_backslash_n` column must be `0` for every row. If it is `1` the seed is corrupt even if the PHP ran without errors. Symptom: EX0 "Something went wrong" on pages that use the affected template, with no log entry.
29. **IPS 5 Output API — `\IPS\Output::i()->headCss[]` does not exist** — the `headCss` property was never defined on `\IPS\Output`. Assigning to `$headCss[]` silently creates a dynamic property in PHP 8.1 (deprecation warning), is entirely ignored by the template renderer, and in some request contexts throws. To include application-specific CSS in IPS 5, either (a) seed the CSS into `core_theme_css` via `data/css.json` + the standard IPS theme resource import, or (b) for prototype work only, prepend an inline `<style>` block to `\IPS\Output::i()->output` just before it goes to the client. Never write to a property on `\IPS\Output::i()` without first confirming the property exists in `system/Output/Output.php`.
30. **When a seeded template references another template, EVERY referenced template must be seeded in the same transaction** — `{template="otherTemplate" group="..." app="..."}` tags in IPS templates are resolved at render time against `core_theme_templates`. If the referenced template is missing the page throws EX0 with no log entry (the template compiler fails silently). Before declaring a seed task complete, run this verification against the DB:

    ```sql
    SELECT template_name FROM core_theme_templates
    WHERE template_app='<app>'
      AND template_name IN (<every template touched by this version>);
    ```

    Every name passed in must come back. Missing names = broken deploy. "The seed file mentions all three" is not sufficient; the DB is the source of truth.
31. **Before declaring any runtime-visible task complete, exercise the actual user path — PHP lint is not enough** — `php -l` only catches syntax errors. IPS-specific bugs (missing template dependencies, non-existent `Output` properties, malformed seed content, autoload misses, wrong parameter types passed to IPS helpers) only surface when the page is actually rendered. Verification steps for every completed task:

    1. Open the page as the target user role in a browser
    2. Check response is 200 and has expected content (not EX0)
    3. For server-side verification when a browser isn't available:
    ```bash
    php -d display_errors=1 -r 'require "<path>/init.php"; require "<affected-file>";'
    ```
        Confirm both require statements complete without output.
    4. If the task touched DB seeds, verify the seeded rows exist with `SELECT template_name, LENGTH(template_content) FROM core_theme_templates WHERE ...`.

    A task that passes lint but throws EX0 at runtime is not complete.
32. **Diagnosing EX0 — find the actual exception before writing a fix** — `Error code: EX0` is IPS's generic "unhandled exception" page. It logs nothing by default. The real exception message must be obtained before writing any fix. Primary sources to check in order:

    1. `core_error_logs` table: `SELECT FROM_UNIXTIME(log_date), log_error_code, log_request_uri, LEFT(log_error, 3000) FROM core_error_logs ORDER BY log_date DESC LIMIT 5\G`
    2. `uploads/logs/YYYY_MM_DD_uncaught_exception.php` files
    3. Force the error with `IN_DEV => true` in `conf_global.php` and reload — IPS prints the full trace inline
    4. CLI-trigger the path:
    ```bash
    php -d display_errors=1 -r '$_SERVER["REQUEST_METHOD"]="GET"; $_SERVER["QUERY_STRING"]="<route>"; require "<path>/init.php"; try { \IPS\Dispatcher\Front::i()->run(); } catch (\Throwable $e) { echo $e->getMessage()."\n".$e->getFile().":".$e->getLine()."\n".$e->getTraceAsString(); }'
    ```

    Do not propose fixes based on guessing what the exception might be. "Same error" reports with no trace are not actionable.
33. **Large phased structural changes — stop at every phase boundary and verify production works** — a change that touches 15+ files, introduces new templates, rewrites shells, or migrates CSS into new locations must be broken into phases that each land independently in production. After each phase: deploy to the real server, load the affected pages in a browser, confirm no regressions, get user sign-off, THEN proceed to the next phase. "Ship all of v1.0.71 through v1.0.77 in one session" is not permitted. If the current session is building toward a multi-phase change, explicitly stop at the boundary and ask before proceeding. Phase 1 delivers a testable shell. Phase 2+ each adds one testable page body. Each phase is one tar, one version bump, one deploy, one verification cycle.

34. **`\IPS\Db::i()->addQuote()` does not exist** — IPS 5's database layer has no `addQuote()` or `quote()` convenience method. Concatenating escaped strings into raw SQL via a non-existent method throws `\Error` (undefined method), which is NOT caught by `catch ( \Exception )` — it only descends from `\Throwable`. For any SQL that cannot use `\IPS\Db::i()->select()` (e.g. complex subqueries, INSERT...SELECT, UPDATE with CASE), use `\IPS\Db::i()->preparedQuery( $sql, [ $param1, $param2, ... ] )` with `?` placeholders. Never concatenate user-controlled or date strings into SQL.
35. **Catch `\Throwable`, not `\Exception`, for defensive DB blocks** — undefined-method calls (`addQuote()`, missing IPS APIs) throw `\Error`, which extends `\Throwable` but NOT `\Exception`. Any `try/catch` block that wraps IPS DB calls as a graceful fallback must catch `\Throwable` to avoid EX0 crashes from `\Error` leaking up the stack. Pattern: `try { /* DB call */ } catch ( \Throwable ) { /* safe default */ }`. Only use `catch ( \Exception $e )` when you specifically need the message and are certain no `\Error` can occur.
36. **`\IPS\Db::i()->preparedQuery()` for complex SQL** — when `\IPS\Db::i()->select()` can't express the query (INSERT...SELECT, subqueries, UNION, UPDATE with CASE), use `preparedQuery( string $query, array $binds )`. It returns a `mysqli_result` (or `true` for non-SELECT). The `$query` uses `?` positional placeholders; `$binds` is a flat array of values. Always prefix table names with `\IPS\Db::i()->prefix`. For integer dealer/member IDs already cast to `(int)`, binding as `?` is still preferred over interpolation for consistency.

## Full specification
Read `GunRack_Spec_v2.9.16.md` for complete specs on all 12 plugins, database schemas, acceptance criteria, server setup (Appendix B), security requirements (Appendix C), and Phase 2 roadmap (Section 19).


## Template and upgrade rules (learned from v1.0.106–v1.0.131 regressions)
37. **Never use regex to inject HTML into existing template bodies** — if the template needs a new section, write the full clean template in the upgrade.php with a heredoc and overwrite the row. Regex injection into template bodies has caused orphan `{{endif}}` tags and broken compiled template classes multiple times (v1.0.128, v1.0.129).
38. **Never write a "wrapping" upgrade script that does read-modify-write on a template** — each upgrade that touches a template must replace it with a complete known-good version, not append/prepend to whatever's there. Wrapping scripts (read template → prepend CSS/wrapper → append closing div → write back) stack on every upgrade run, inflating templates exponentially (v1.0.106–v1.0.108 stacked dealerShell to 465KB across 15+ upgrades).
39. **Every new lang string requires upgrade.php seeding** — `data/lang.xml` only runs on fresh install. For each new lang key in a version, add a block to that version's upgrade.php that inserts into `core_sys_lang_words` for every `lang_id` in `core_sys_lang`. Without this, existing installs render raw lang keys instead of human-readable labels.
40. **Every upgrade.php that touches templates must end by busting caches** — delete `core_cache`, delete `core_store` rows starting with `theme_` or `template_`, and `unlink()` matching files in `datastore/`. Without this, IPS serves stale compiled templates even after the DB row is updated.
41. **Reactive UI on form fields = JavaScript inside the template** — IPS form helpers render server-side only. Don't try to make panels appear/disappear by re-fetching the page or by checking DB state at render — use a JS handler on the form input (e.g. `addEventListener('change', sync)` on radio buttons).
42. **Test each upgrade on a clean checkout before tagging** — specifically: install the previous version, then run the new upgrade. Don't test by re-running the new install on a fresh DB — that path uses `setup/install.php`, not `upgrade.php`. The upgrade path is where all the regressions hide.

## IPS 5.0.18 schema gotchas and runtime patterns (learned from gdcatalog v1.0.2–v1.0.6)
43. **`core_sys_lang_words` schema is 6 columns only on IPS 5.0.18 — never include `word_default_version`, `word_custom`, or `word_is_custom`** — IPS 5.0.18 (Derrick's install) has only `lang_id`, `word_app`, `word_key`, `word_default`, `word_js`, `word_export`. Other column names from newer IPS docs (`word_default_version`, `word_custom`, `word_is_custom`) do NOT exist on this schema. Including them in `\IPS\Db::i()->replace( 'core_sys_lang_words', [...] )` throws `Unknown column` and aborts the entire seed loop, leaving the UI rendering raw lang keys. Verified working pattern (matches gddealer upg_10077, upg_10082, upg_10088, upg_10089, upg_10119):

    ```php
    foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
    {
        foreach ( $newStrings as $key => $val )
        {
            try
            {
                \IPS\Db::i()->replace( 'core_sys_lang_words', [
                    'lang_id'      => (int) $langId,
                    'word_app'     => '<app>',
                    'word_key'     => $key,
                    'word_default' => $val,
                    'word_js'      => 0,
                    'word_export'  => 1,
                ] );
            }
            catch ( \Throwable $e ) { /* per-row catch — see rule #44 */ }
        }
    }
    ```

44. **Bulk DB seed loops must wrap each row in its own `try/catch`, not the outer loop** — when seeding lang strings, templates, or any row-by-row data into IPS tables, putting a single `try/catch` around the entire `foreach` causes one bad row (encoding issue, length overflow, schema mismatch) to abort the entire loop. Subsequent rows never get inserted, leaving partial state. Always wrap the per-row `replace()`/`insert()` call in its own `try/catch ( \Throwable )` so one failure logs but doesn't poison the rest. The outer try/catch can wrap the SELECT that drives the loop.
45. **`core_theme_templates` schema on IPS 5.0.18 — verified column list, no user_ columns** — running `DESCRIBE core_theme_templates` on IPS 5.0.18 returns these columns and ONLY these:

    ```
    template_id, template_set_id, template_group, template_content,
    template_name, template_data, template_updated, template_master_key,
    template_location, template_app, template_version, template_has_hookpoints
    ```

    Older IPS docs and AI training data may suggest `template_user_edited`, `template_user_created`, or `template_user_added` exist — they do NOT on 5.0.18. Including them in `\IPS\Db::i()->replace( 'core_theme_templates', [...] )` throws `Unknown column 'template_user_edited' in 'INSERT INTO'`. The catch returns `FALSE` from the upgrade step, IPS retries indefinitely, install hangs forever (gdcatalog v1.0.2 was lost to this exact bug). Always include `template_updated => time()` and `template_version => '<version>'` on reseed; never reference the user_* columns.
46. **IPS template engine interpolates `$variable` tokens globally — never put `<script>` blocks with `$`-prefixed JS variables in templates** — IPS's template compiler does a global `$varname` substitution pass over the entire template body, including inside `<script>` tags. JS variables named `$originals`, `$helper`, `$this`, etc. get replaced with empty strings (since they don't exist in the template's argument list), producing broken JS like `var = tr.children();` (no variable name, syntax error). The error is silent on the server side but kills the entire script in the browser. Two solutions:
    * Move the JS to a static file at `applications/<app>/interface/<file>.js` and enqueue from the controller via `\IPS\Output::i()->js( '<file>.js', '<app>', 'interface' )`. Static JS is NOT processed by the template engine. This is the IPS-native pattern.
    * Avoid `$`-prefixed variable names entirely in any inline `<script>` (rename `$originals` to `originals`, etc.). Defensive but still fragile if anyone else edits the template.

    Always prefer the static file approach for any non-trivial JS.
47. **Plugin JS must live in `interface/`, not `dev/js/`, for production-mode serving** — `\IPS\Output::i()->js()` has three branches based on `$location` and `IN_DEV`:
    * `'interface'` — serves the file directly from `applications/<app>/interface/<file>.js` via a versioned URL. Works in production AND dev mode. The right pattern for plugin JS.
    * `'admin'` or `'global'` with `IN_DEV` true — serves from `dev/js/` for development.
    * `'admin'` or `'global'` with `IN_DEV` false (production) — expects pre-compiled JS from the IPS dev build process. Returns an empty array if no compiled file exists, so `Output::i()->jsFiles = array_merge( ..., [] )` silently does nothing and the JS is never enqueued.

    Plugins shipping JS without a build pipeline must use `interface/`. Never put plugin JS in `dev/js/admin/` and expect it to load on production installs.
48. **AJAX CSRF — use `\IPS\Session::i()->csrfKey` via data attribute, not `->csrf()` URL-baking** — IPS URL helpers like `\IPS\Http\Url::internal( '...' )->csrf()` bake a CSRF token tied to the request context that generated it. When `manage()` (a GET request) bakes CSRF into a URL intended for an AJAX POST endpoint, the resulting POST hits IPS's `csrfCheck()` which validates POST-context CSRF and rejects the GET-baked token with `403 Forbidden`. Working pattern (verified in `gddealer/modules/admin/dealers/stockreplies.php` line 227):
    1. Controller passes `\IPS\Session::i()->csrfKey` to template as a separate scalar argument
    2. Template renders it as `data-csrf-key='{$csrfKey}'` on the relevant element (table, form, button)
    3. JS reads the attribute and sends as POST body parameter `csrfKey`
    4. The AJAX endpoint URL is generated WITHOUT `->csrf()` — just the plain internal URL
    5. The AJAX endpoint's controller method calls `\IPS\Session::i()->csrfCheck()` as normal — it validates against the POST body parameter

    Never use `->csrf()` for URLs intended to receive AJAX POST requests.
49. **Tar build commands must use explicit `--exclude` flags for defense-in-depth** — listing only the includes (e.g. `tar -cf foo.tar Application.php data dev modules ...`) implicitly excludes everything not listed, but is fragile: if someone later adds `tests` or `node_modules` to the include list, non-production code starts shipping. Every tar build command must include explicit excludes:

    ```bash
    tar -cf <app>-v<ver>.tar \
      --exclude='<app>-v*.tar' \
      --exclude='_design' \
      --exclude='tests' \
      --exclude='node_modules' \
      --exclude='.git' \
      Application.php data dev index.html [interface] modules setup sources tasks
    ```

    Add `interface` to the include list for any plugin shipping static JS/CSS via the `interface/` path (rule #47). The recursive tar pattern (`<app>-v*.tar`) prevents prior tarballs from being included in new ones.

50. **`data/versions.json` structure (CRITICAL — IPS 5.0.18)** — IPS 5.0.18's app installer reads `data/versions.json` with this expected shape: keys are integer-as-string (e.g. `"10179"`), values are human version string (e.g. `"1.0.179"`). Wrong shape (do NOT use): `{"1.0.179": 10179}`. Right shape (always use): `{"10179": "1.0.179"}`.

    The wrong shape causes IPS's `array_keys()` / `array_values()` calls in `system/Application/Application.php` (around line 2092) to return the inverse of what IPS expects:

    ```php
    $longVersions  = array_keys( $versions );    // expects [10000, 10001, ...]
    $humanVersions = array_values( $versions );  // expects ["1.0.0", "1.0.1", ...]
    $latestL = array_pop( $longVersions );
    $latestH = array_pop( $humanVersions );
    \IPS\Db::i()->update( 'core_applications', [
        'app_version'      => $latestH,
        'app_long_version' => $latestL,
    ] );
    ```

    With the wrong shape, IPS writes:
    - `app_version` (VARCHAR) gets the int → stored as string of digits
    - `app_long_version` (INT) gets the human string → MySQL truncates → garbage values like 10 or 1

    Result: IPS thinks the app is at an old version and re-runs all historical upgrade scripts on every install. Logs fill with "duplicate column" / "table exists" errors from idempotent migrations re-running.

    Verified against IPS's own apps:
    - `applications/forums/data/versions.json`: `{"5001800": "5.0.18"}`
    - `applications/core/data/versions.json`: `{"105013": "4.5.0 Beta 1"}`

    When appending a new version to `versions.json`, always use:

    ```json
    "10180": "1.0.180"
    ```

    NOT:

    ```json
    "1.0.180": 10180
    ```

    Applies to both `gddealer` and `gdcatalog`. gddealer was corrected in v1.0.179. gdcatalog still has the wrong structure as of this writing — needs a parallel fix when convenient.

51. **`upgrade.php` runs BEFORE IPS writes the new version row** — IPS runs `setup/upg_10X/upgrade.php` BEFORE updating `core_applications.app_long_version` and `app_version`. The version row update happens AFTER all upgrade scripts complete. This means if `upgrade.php` reads `app_long_version` during its `step1()` execution, it sees the PRE-upgrade value (the version of the previously-installed tarball), NOT the new version being installed.

    Practical consequence: don't write upgrade.php sanity checks comparing against the version being installed. They'll always log a false-positive "below expected version" warning. Example bug from v1.0.179's upgrade.php:

    ```php
    /* Inside upg_10179/upgrade.php */
    $longVer = (int) $row['app_long_version'];
    if ( $longVer < 10179 )
    {
        \IPS\Log::log( 'WARNING: app_long_version below 10179', 'gddealer_upg_10179' );
    }
    /* This warning fires every time, because $longVer is still 10178 here.
     * IPS hasn't updated the row to 10179 yet — it does that AFTER step1() returns. */
    ```

    Solutions:
    - Compare against the PREVIOUS version, not the current ("should be at least 10178" for upg_10179)
    - Move version-related sanity checks to a later trigger (post-install hook, application boot logic, or a scheduled task)
    - Skip the check entirely if it's just defensive logging

    For genuine post-install verification, the better pattern is: after running the install in ACP, manually run `SELECT app_long_version FROM core_applications WHERE app_directory='X'` to confirm the version stuck.

52. **Every plugin change must work on BOTH fresh install AND upgrade — verify in pairs** — Fresh installs run `setup/install.php` + `data/schema.json` + `data/lang.xml` + `data/extensions.json` + `data/emails.xml`. They do NOT run any `setup/upg_XXXXX/upgrade.php` script. Upgrades run ONLY `setup/upg_XXXXX/upgrade.php` scripts whose version integer is greater than the currently-installed version. They do NOT re-run `setup/install.php`. This means every change that touches user-visible state MUST land in BOTH paths or one of the two install paths will be broken. The parallel pairs:

    | Change type | Fresh install path | Upgrade path |
    |---|---|---|
    | New DB table | `data/schema.json` | `setup/upg_XXXXX/queries.json` (CREATE) |
    | New column on existing table | `data/schema.json` | `setup/upg_XXXXX/queries.json` (addColumn) |
    | New template | `setup/install.php` `$gddealerTemplates` array | `setup/upg_XXXXX/upgrade.php` `\IPS\Db::i()->replace('core_theme_templates', [...])` |
    | Modified template body | `setup/install.php` (rewrite body) | `setup/upg_XXXXX/upgrade.php` replace with same body |
    | New lang string | `data/lang.xml` | `setup/upg_XXXXX/upgrade.php` insert into `core_sys_lang_words` for every `lang_id` |
    | New email template | `data/emails.xml` | `setup/upg_XXXXX/upgrade.php` calls `installEmailTemplates()` or inserts directly |
    | New extension | `data/extensions.json` + file under `extensions/` | `setup/upg_XXXXX/upgrade.php` clears extension cache; file ships in tarball |
    | New notification default | `setup/install.php` insert into `core_notification_defaults` | `setup/upg_XXXXX/upgrade.php` same insert |
    | New controller method | Source file under `modules/` ships in tarball | Same — controller code lives in source, no upgrade.php splicing |
    | New controller URL/route | `data/modules.json` and/or `data/furl.json` | Source files ship in tarball |
    | Template body that depends on a new `$data` key | Update controller method to add the key in source | Same — source code |

    Rules for keeping the two paths in sync:

    - The template body in `install.php` and the body in `upg_XXXXX/upgrade.php` must be **byte-for-byte identical** at the time the upgrade ships. Use a nowdoc heredoc (rule #28) and copy-paste; do not regex-extract from one to the other.
    - If only one of the pair is updated, the change is incomplete. A fresh install on a different server will be missing whatever wasn't in install.php. A prod upgrade will be missing whatever wasn't in upgrade.php.
    - Never use `upgrade.php` to write to a `.php` source file (controller, trait, model). Source files ship in the tarball; that IS the fresh-install AND upgrade path for code. Splicing into source via upgrade.php means every subsequent tarball re-overwrites the splice from git, which is what caused v195's "Categories sidebar keeps disappearing" disaster.

    Verification before declaring done — for every version that adds user-visible state, both paths must be greppable in the tarball:

    ```bash
    # For a new template named 'foo':
    tar -xOf <app>-v<ver>.tar setup/install.php | grep -c "'foo'"
    # expect: >= 1

    tar -xOf <app>-v<ver>.tar setup/upg_XXXXX/upgrade.php | grep -c "'foo'"
    # expect: >= 1 (if existing installs need it re-seeded)

    # For a new lang key 'bar':
    tar -xOf <app>-v<ver>.tar data/lang.xml | grep -c "bar"
    # expect: >= 1

    tar -xOf <app>-v<ver>.tar setup/upg_XXXXX/upgrade.php | grep -c "bar"
    # expect: >= 1 (insert into core_sys_lang_words for every lang_id)

    # For a new column on existing table:
    tar -xOf <app>-v<ver>.tar data/schema.json | grep -c "new_column"
    # expect: >= 1

    tar -xOf <app>-v<ver>.tar setup/upg_XXXXX/queries.json | grep -c "new_column"
    # expect: >= 1
    ```

    If only one side of the pair greps to 1, the build is not done.

    Test plan that catches install/upgrade drift before shipping:

    1. Take a clean IPS install at the current production version.
    2. Install the new tarball. Verify the new feature works. (Tests upgrade path.)
    3. Take a fresh clean IPS install with no prior version of the app.
    4. Install the new tarball. Verify the new feature works. (Tests install path.)

    Both must pass before tagging.

53. **Template overlay cascade (gddealer-specific)** — the gddealer codebase uses a TWO-STAGE template seed system:
    1. `install.php` seeds bare-bones template bodies via the `$gddealerTemplates[]` array near the top.
    2. `install.php`'s bottom `require_once` chain runs overlay files (`setup/templates_XXXXX.php`) that OVERWRITE specific templates with their authoritative final/rich versions.

    When a template appears in BOTH the array AND an overlay file, the OVERLAY is the source of truth. The array body for that template is effectively dead code (the overlay overwrites it at install time).

    Implications:
    * DO NOT write `foreach ($gddealerTemplates as $tpl) replace()` upgrades — these revert overlays and silently break pages. The v207 disaster came from exactly this mistake.
    * Template edits go in the OVERLAY file. If you edit `install.php`'s array entry instead, you MUST also mirror the change to every overlay file targeting that template, OR remove the overlay from `install.php`'s require chain.
    * Before writing any `upgrade.php` that writes to `core_theme_templates`, read `install.php`'s bottom `require_once` chain. Your upgrade must produce the same DB state a fresh install of this version would produce. If it can't, the source-of-truth (`install.php`'s array OR an overlay file) is wrong — fix THERE, not in the upgrade.
    * Catch-up/re-seed upgrades must enumerate each affected template explicitly. For every template touched, confirm whether an overlay targets it. If yes, re-run the overlay's file (`require_once applications/gddealer/setup/templates_XXXXX.php`) — don't reseed from the array.

54. **Every prompt to Claude Code must be checkable post-build** — every prompt for Claude Code ends with a "Verification before declaring done" section containing exact grep/tar-extraction checks against the BUILT tarball. Each check states the exact command to run and the exact expected count or string. Claude Code skips steps in prompts regularly (v204 shipped 1 of 4 steps). Always verify with grep AFTER build, BEFORE installing. Don't trust "I did all the steps" claims from Claude Code or anyone. If any verification check fails, the build is incomplete and the steps must be redone — do not install a partial tarball.

55. **Static checks ≠ runtime verification** — a "passing" tarball (grep-clean, all verification checks pass) only proves the tarball was BUILT correctly. It does NOT prove the fix WORKS. Always require runtime exercise after deploy:
    * Imports: run actual import, confirm `status=completed` with non-zero `records_total`. NOT `status=failed rec=0`.
    * Pages: load actual URL in browser, confirm renders without EX0 or "template throwing an error".
    * Buttons: click in actual UI, confirm expected response.
    * Tasks: wait for scheduled task to run, check next log row.

    v208 shipped a grep-clean tarball claiming to fix the libxml deprecation. Imports still failed for hours on prod because the fix was static-correct but masked an unrelated downstream issue. Static = "built it right." Runtime = "it actually works."

56. **One bug at a time** — when Derrick reports a specific broken page, error, or behavior, FIX THAT one thing. Do not drift into adjacent diagnostics ("while we're here, let's also check the imports"). If a related bug is discovered during the investigation: note it explicitly, tell Derrick we found another issue and recommend a separate fix, then return focus to the original ticket. The v208/feedSettings/imports session got tangled because of diagnostic drift. Pattern: ONE broken thing → ONE fix → confirm working → MOVE to next.

57. **PowerShell SSH heredocs are unreliable** — Derrick uses Windows PowerShell to SSH into prod (`ssh -p 2200 root@108.160.146.199`). Multi-line PHP scripts piped via heredoc (`cat > /tmp/x.php << 'PHPEOF' ... PHPEOF`) often arrive mangled — PowerShell duplicates lines, eats `$variables`, or garbles output. When writing prod diagnostic commands: PREFER single-line one-shot commands (`php -r '...'` with semicolons separating statements). If heredoc is unavoidable, keep it ≤20 lines. For complex diagnostics, send commands ONE AT A TIME and have Derrick paste output between each.

58. **`libxml_disable_entity_loader()` is forbidden** — this function is deprecated in PHP 8.0+ and removed in PHP 9.0. Any call to it on Derrick's prod (PHP 8.x) triggers a deprecation that IPS's scheduled-task error handler elevates to a fatal, breaking imports and other XML-handling flows. For XML feed parsing, the effective XXE guard is the `LIBXML_NONET` flag passed to `simplexml_load_string()`. Do NOT call `libxml_disable_entity_loader()`. Audit periodically with:
    ```bash
    grep -rn "libxml_disable_entity_loader(" applications/gddealer/ | grep -v "//\|^\s*\*"
    ```
    Any hits (excluding comments) need cleanup.

59. **Canonical templates (v213 architecture)** — 12 gddealer templates are managed by `\IPS\gddealer\Setup\CanonicalTemplates`: feedSettings, overview, listings, analytics, dealerNavIcon, dealerShell, dealerSidebar, dashboardCustomize, dealerProfile, unmatched, help, supportTicketView. The `SOURCES` constant maps each template to the overlay file(s) that write its canonical body. `ensure()` re-runs those overlay files. To change a managed template body: edit the overlay file referenced in SOURCES, bump version, ship. Every new `upg_*/upgrade.php` MUST end with:
    ```php
    require_once \IPS\ROOT_PATH . '/applications/gddealer/sources/Setup/CanonicalTemplates.php';
    \IPS\gddealer\Setup\CanonicalTemplates::ensure();
    \IPS\gddealer\Setup\CanonicalTemplates::clearCaches();
    ```
    DO NOT write new overlay files for these 12 templates without updating the SOURCES constant. To add a NEW managed template: add an entry to SOURCES mapping template_name to [array of overlay file paths].

60. **gddealer CSS architecture (v213 native IPS 5 pattern)** — the 91 KB `applications/gddealer/dev/css/front/dealer.css` holds all dashboard component styles. It is loaded via IPS 5's native CSS pipeline: (1) `Application::installOther()` calls `\IPS\Theme\Dev\Theme::importDevCss('gddealer', 0)` to register dev/css into `core_theme_css`, (2) `upg_10213+` scripts call `Theme::compileCss()` to compile to a served URL, (3) every controller that renders dealer output merges via `\IPS\Output::i()->cssFiles = array_merge( cssFiles, \IPS\Theme::i()->css('dealer.css', 'gddealer', 'front') )`. Currently done in `DealerShellTrait::output()` AND `profile.php`'s public profile output sites. DO NOT bring back the inline file-read band-aid. DO NOT add `<style>` blocks to template bodies for component styling. To edit dashboard styling: edit `dev/css/front/dealer.css`, bump version — importDevCss + compileCss steps re-register and recompile on upgrade.

61. **IPS dev mode escalates E_WARNING to fatal — template `$data` key access must use `isset()`** — in production, accessing a missing array key (e.g. `$data['saved_flash']`) returns null and the `{{if}}` evaluates false silently. In `IN_DEV` mode, Whoops escalates the E_WARNING to a fatal error. Every template `{{if $data['key']}}` where the key is not guaranteed to exist (e.g. flash messages only set after a save action) MUST be written as `{{if isset($data['key']) && $data['key']}}`. Audit all templates when switching to dev mode — any access to an optional key will throw. Pattern: if the controller doesn't set the key on every code path, the template must guard with `isset()`.

62. **`->csrf()` must NEVER appear on GET URLs that render 200 HTML responses** — IPS dev mode enforces that a CSRF key in a GET URL cannot coexist with a 200 HTML response. The IPS rule: CSRF keys go in POST body or the request must redirect after use. This means: (a) edit/form-display actions (`do=edit`, `do=suspend` showing a reason form, `do=fflReject` GET) must NOT have `->csrf()` on their URL, (b) action buttons that POST-and-redirect (`do=toggleSuspend` unsuspend, `do=forceImport`, `do=deleteReview`) may use `->csrf()` because the response is a redirect (302), not a 200. When a single action URL serves both GET (renders form) and POST (performs action), the URL itself never gets `->csrf()` — CSRF is embedded in the form as a hidden field via `\IPS\Session::i()->csrfKey`. Audit every `->csrf()` call: does clicking this link render HTML? If yes, remove `->csrf()`.

63. **Two-phase ACP actions (GET=form, POST=act) need no csrf on the link URL** — when converting an ACP action from a direct POST link to a two-phase GET-form/POST-act flow (like toggleSuspend with a reason form): (1) strip `->csrf()` from the URL passed to the template, (2) embed `\IPS\Session::i()->csrfKey` as a hidden field in the rendered form, (3) the POST handler calls `\IPS\Session::i()->csrfCheck()` normally. The GET handler renders HTML and does NOT call `csrfCheck()`. ACP-permission-gated actions (behind `checkAcpPermission()`) don't need additional CSRF on the GET phase — the ACP session is the gate.

64. **Never use PHP heredocs or multiline strings in SSH one-liners — write to /tmp file first** — Rule #57 covers PowerShell heredoc mangling but the fix pattern is not stated clearly. The reliable workflow for any multiline PHP edit on prod: (1) write the PHP to `/tmp/fix_something.php` using a `cat > /tmp/fix_something.php << 'PHPEOF' ... PHPEOF` heredoc (this usually works for writing files), (2) run `php /tmp/fix_something.php` as a separate command. Never pipe multiline PHP into `php -r '...'` — the shell mangles quotes, backslashes, and dollar signs. Never use `sed -i` with multiline replacement strings — use PHP `str_replace()` in a script instead. For `str_replace()` patterns: always verify exact indentation first with `sed -n 'Np' file | cat -A` (shows tabs as `^I`, spaces as spaces) before writing the find string.

65. **`localeTime()` and `localeDate()` use the member's browser timezone cookie only during real HTTP requests** — `\IPS\DateTime::ts($ts)->localeTime()` reads the `ips4_ipsTimezone` cookie to adjust the timezone. This cookie is only available during a real browser request processed through IPS's full HTTP pipeline. In CLI (`php -r`), during `$data` array construction in a controller, or in any context where the cookie hasn't been parsed, the result is always UTC (server timezone). For user-facing time display: (a) prefer `\IPS\DateTime::ts($ts)->format('g:i A')` which respects the timezone cookie during HTTP requests, (b) for "current time" displays (e.g. "Last updated X:XX PM"), use JavaScript `new Date().toLocaleTimeString([], {hour:'numeric', minute:'2-digit', hour12:true})` rendered client-side — this always uses the browser's local timezone with zero server involvement. Never use `date('g:i A', time())` for user-facing current time on a UTC server.

66. **Server timezone is UTC — all user-facing timestamps must account for this** — the GunRack.deals server is UTC. `date('H:i')`, `date('g:i A')`, and `time()` all return UTC values. Dealers in US timezones will see timestamps that are 5–7 hours wrong if raw PHP time functions are used. Correct patterns: (a) stored timestamps use `date('Y-m-d H:i:s')` (UTC storage is correct), (b) displayed timestamps use `\IPS\DateTime::ts($ts)->format('g:i A')` (adjusts to member timezone via cookie), (c) "right now" displays use JS `new Date()` (uses browser's OS timezone). Wrong pattern: `date('g:i A', time())` or `date('g:i A', $ts)` for display.

67. **`g_signature_limits` must be a 6-part colon-delimited string — empty value causes fatal in account settings** — IPS's `Member::canEditSignature()` explodes `g_signature_limits` by `:` and accesses index `[5]`. If the column is empty string or has fewer than 6 parts, PHP throws `Undefined array key 5` which in dev mode is a fatal. On production it returns false (silently breaks signature). Fix: ensure all member groups have `g_signature_limits` set to at least `0:0:0:0:0:1` (signatures enabled, no restrictions). Check after creating new groups or restoring DB backups. Diagnosis: `SELECT g_id, g_signature_limits FROM core_groups` — any row with empty or short value needs updating. If signatures are globally disabled in ACP, set `0:::::` (6 parts) not `''`.

68. **IPS dev mode surfaces bugs that production silently swallows — use dev mode to audit all templates before prod shipping** — running the plugin in `IN_DEV` mode on a dev server reveals: (a) undefined array key accesses in templates, (b) CSRF keys in GET URLs that render HTML, (c) template syntax errors that `eval()` silently ignores in production, (d) missing template dependencies. Always run a full page-by-page click-through in dev mode before building the production tarball. Each Whoops error in dev mode is a real bug that production is hiding. Fix every dev-mode error before shipping — don't dismiss them as "works in prod." The pattern: dev mode error → find root cause → fix → verify page loads clean → move to next page.

69. **When a page looks "bare bones" instead of the rich design, check all overlay files for the authoritative version** — the gddealer template cascade (Rule #53) means install.php's `$gddealerTemplates` array may contain an OLD version of a template body while a later overlay file contains the current rich version. When a page renders a stripped-down layout: (1) `grep -rn "'templateName'" setup/` to find all files that seed that template, (2) sort by version number, (3) the HIGHEST version number's body is authoritative. Use the size and feature presence (hours, social links, rating bars, etc.) as a proxy for richness. The install.php array entry is often outdated — always check overlay files.

70. **`suspension_reason` column pattern for soft-suspension UX** — when a dealer is suspended, they should see their dashboard with a prominent banner rather than a hard redirect/block. Implementation: (1) add `suspension_reason TEXT NULL` column to `gd_dealer_feed_config` after `suspended`, (2) pass `(bool) $dealer->suspended` and `(string) ($dealer->suspension_reason ?? '')` to the shell template, (3) show banner at top of shell when suspended, (4) admin suspend action is two-phase: GET renders reason form, POST saves reason + suspends. The suspension notice should show on EVERY dashboard page (via the shell template), not just overview. When unsuspending, clear `suspension_reason = null`. This pattern applies to any future "soft-block with reason" feature.

71. **Template parameter additions require updating BOTH the trait/controller call AND the template `<ips:template parameters="">` line** — when adding parameters to a template (e.g. adding `$suspended`, `$suspensionReason` to dealerShell): (1) update the `<ips:template parameters="...">` first line of the `.phtml` file, (2) update every `->dealerShell(...)` call in PHP source to pass the new arguments. If either is missed, IPS throws a parameter count mismatch at runtime. In the gddealer architecture, dealerShell is called from `DealerShellTrait::output()` — that's the only place to update for front-end dashboard pages.

72. **`data/schema.json` must be updated whenever a column is added to a live table via ALTER** — adding a column directly via `ALTER TABLE` on the dev server (for testing) is fine, but `data/schema.json` must be updated to include the new column definition before shipping the tarball. Fresh installs use `schema.json` to create the table — without the column in the schema, fresh installs will be missing it. The column definition in `schema.json` must match what was ALTERed in: correct type, `allow_null`, `default`, `length` (0 for TEXT/BLOB types, actual length for VARCHAR/INT). Also add the column to `setup/upg_XXXXX/queries.json` as an `addColumn` operation for upgrade installs.

73. **ACP member group `g_signature_limits` is 6 colon-separated parts but IPS may create groups with empty string** — when creating member groups via ACP or via `\IPS\Db::i()->insert('core_groups', [...])` without specifying `g_signature_limits`, the column defaults to empty string. This causes a fatal in `canEditSignature()` (Rule #67). Any code that creates member groups (dealer tier group creation, onboarding, etc.) must explicitly set `g_signature_limits` to a valid 6-part string. The safe default is `'0:0:0:0:0:1'` (signatures enabled, no restrictions). Check all `core_groups` inserts in the codebase.

74. **All templates must live as `.phtml` files in `dev/html/{location}/{group}/` — never seed-only via `install.php`** — the IPS 5 native template architecture requires `.phtml` files in `dev/html/` for dev-mode to work correctly. Plugins that only seed templates via `setup/install.php` into `core_theme_templates` are invisible to the IPS template engine in dev mode, preventing proper error surfacing, template editing in ACP Developer Center, and the Build → `data/theme.xml` pipeline. Required structure for every plugin:
    ```
    dev/
      html/
        admin/
          {group}/
            templateName.phtml    ← first line: <ips:template parameters="$var1, $var2" />
        front/
          {group}/
            templateName.phtml
      css/
        front/
          styles.css
      js/
        front/
          script.js
      lang.php                    ← IN_DEV language strings
    ```
    Rules:
    - Every `.phtml` file's FIRST LINE must be `<ips:template parameters="$var1, $var2" />` matching exactly the arguments the controller passes
    - `setup/install.php` template seeding via DB `replace()` should ALSO remain for production fresh installs (IPS uses `core_theme_templates` in production mode, `dev/html/` in dev mode)
    - ACP Developer Center → Build generates `data/theme.xml` from `dev/html/` files for production shipping
    - Every new template added to a plugin needs BOTH: a `.phtml` file in `dev/html/` AND a seed in `setup/install.php` (for production fresh installs) AND a seed in the relevant `upg_*/upgrade.php` (for production upgrades)
    - Verify dev/ files are included in the tarball build command: `Application.php data dev index.html interface modules setup sources tasks extensions`

75. **`dev/html/` directory requires `index.html` files in every subdirectory** — IPS scans the `dev/` directory structure and expects every directory to contain a blank `index.html` to prevent directory listing. Missing `index.html` files don't break functionality but cause IPS installer warnings. Add blank `index.html` to: `dev/`, `dev/html/`, `dev/html/admin/`, `dev/html/admin/{group}/`, `dev/html/front/`, `dev/html/front/{group}/`, `dev/css/`, `dev/css/front/`, `dev/js/`, `dev/js/front/`. The blank `index.html` content: `<html><body></body></html>` or just an empty file.

77. **One upgrade version per tarball (IPS 5.0.18 upgrade runner bug)** — never ship multiple new upgrade versions in the same tarball. IPS 5.0.18's upgrade runner has a confirmed bug: when multiple sequential `upg_*` directories exist that need to run (e.g. upg_10041, upg_10042, upg_10043), it crashes on the second iteration with:

    ```
    Undefined array key "extra"
    /applications/core/modules/admin/applications/applications.php line 1069
    ```

    This happens because `$data['extra']['_totalSteps']` is not initialized correctly when looping through multiple upgrade versions in a single upgrade session. The fix:

    - Every tarball ships exactly ONE new `upg_*` directory
    - That single upgrade step contains ALL changes since the previously installed version
    - `versions.json` registers only that ONE new version number
    - Fresh installs use `install.php` (unaffected by this bug)
    - The upgrade runner only needs to run ONCE

    Wrong (causes crash when upgrading from v1.0.40):

    ```json
    {
      "10041": "1.0.41",
      "10042": "1.0.42",
      "10043": "1.0.43"
    }
    ```

    With directories: `setup/upg_10041/`, `setup/upg_10042/`, `setup/upg_10043/`

    Correct:

    ```json
    {
      "10043": "1.0.43"
    }
    ```

    With only: `setup/upg_10043/upgrade.php` — containing ALL the changes from 10041, 10042, and 10043 consolidated into one step1() method.

    Verification check to always include:

    ```bash
    # Confirm only ONE new upg_ directory in tarball
    tar -tf gdcatalog-v1.0.XX.tar | grep "setup/upg_" | grep "upgrade.php"
    # expect: exactly one line
    ```

78. **Never use bare `delete()` on user-configurable data tables in `install.php` — always guard with a row count check** — `setup/install.php` runs on fresh installs AND on reinstalls (when admin removes and re-adds the app). A bare `\IPS\Db::i()->delete( 'table_name', [...] )` without checking whether the table already has user-configured data will wipe admin customizations on reinstall. For tables where the admin may have added, edited, or reordered rows (e.g. `gd_distributor_feeds`, `gd_categories`, `core_theme_templates`), guard destructive operations with a row count check:

    ```php
    $existing = (int) \IPS\Db::i()->select( 'COUNT(*)', 'table_name', $where )->first();
    if ( $existing === 0 )
    {
        // Safe to seed defaults — table is empty / no matching rows
        \IPS\Db::i()->insert( 'table_name', [...] );
    }
    ```

    For `core_theme_templates` seeding specifically, use `\IPS\Db::i()->replace()` (INSERT ... ON DUPLICATE KEY UPDATE) which preserves user edits when keys don't collide, rather than DELETE-all-then-INSERT which destroys any admin template customizations. The only acceptable use of bare `delete()` in install.php is for truly disposable/regenerable data (cache tables, queue tables) where loss has zero user impact.

79. **Rule #77 enforcement: ALL apps (gddealer, gdcatalog, gdsearch, gdloadout, and all future plugins) must have EXACTLY ONE `setup/upg_*` dir at all times** — the current version's. When starting a NEW version: create the new `upg_<long>` dir AND delete the previous one in the SAME step. Each upg dir must be self-contained (re-seed all templates + lang + idempotent schema/column adds + guarded schema changes via `checkForColumn`/`checkForTable`) so it never depends on a prior upg dir that no longer exists. The build MUST abort if >1 upg dir is present. Pre-build guard:
    ```bash
    N=$(ls -d applications/<app>/setup/upg_* 2>/dev/null | wc -l)
    if [ "$N" -ne 1 ]; then
      echo "ABORT: $N upg dirs present in <app>/setup — must be exactly 1 (CLAUDE.md #77). Remove all but the latest before building."
      exit 1
    fi
    ```
    Post-build verification (belt and suspenders):
    ```bash
    INTAR=$(tar -tf <app>-v*.tar | grep "setup/upg_" | grep -c upgrade.php)
    if [ "$INTAR" -ne 1 ]; then echo "ABORT: built tar has $INTAR upg dirs, must be 1"; exit 1; fi
    ```

80. **IPS template bare `{…}` is ONLY valid for plain `$variable['key']` access** — any function call, cast, or compound expression (`count()`, `number_format()`, `strtoupper()`, `(int)…`, ternaries, concatenation) MUST use `{expression="…"}`. Bare `{func(...)}` or `{(int)...}` compiles to broken PHP (`syntax error, unexpected string content ""`) and fatals the whole template. Examples: `{count($arr)}` → `{expression="count($arr)"}`, `{strtoupper($s)}` → `{expression="strtoupper($s)"}`, `{number_format($n)}` → `{expression="number_format($n)"}`, `{(int)($x)}` → `{expression="(int)($x)"}`. Plain `{$var}`, `{$arr['key']}`, `{$obj}` are fine — those are simple variable interpolation. When in doubt, use `{expression="…"}` or pre-compute in the controller and pass as a scalar.

81. **IN_DEV forbids `csrfKey` in the URL of any rendered 2xx page** — NEVER put `->csrf()` on a URL that RENDERS a page (edit forms, view pages, list pages). Only put `->csrf()` on action URLs that DO work and immediately `->redirect()` (toggle, delete, reset) — the post-redirect URL has no key. Form pages get CSRF from `\IPS\Helpers\Form` on POST (`csrfCheck()` on POST only). A `->csrf()` link pointing at a form-rendering GET action will fatal in IN_DEV with `"CSRF keys should be sent via POST or the request should be redirected to a URL not containing a CSRF key once finished."` This check lives in `system/Output/Output.php` and fires on any non-AJAX 2xx HTML response where `$_GET['csrfKey']` is set.

## Server details
- Primary IP: 108.160.146.199
- Secondary IP: 162.255.160.38
- SSH port: 2200
- OS: AlmaLinux 9
- Control panel: DirectAdmin
- IPS path: /home/gunrack/domains/gunrack.deals/public_html/
- OpenSearch: http://localhost:9200 (internal) / https://search.gunrack.deals (external)
- IPS version: 5.0.18
- OpenSearch version: 2.1.0