# GunRack.deals — Project CLAUDE.md

## What this project is
GunRack (gunrack.deals) is a firearms price comparison and community platform built on IPS Community Suite. It competes with gun.deals. Full technical specification is in `GunRack_Spec_v2.9.16.md` in this repo root — read that file first before writing any code.

## Critical rules — read before touching anything
1. **Never start on Plugin 2 or Plugin 3 before Plugin 1 has completed two full successful import cycles across all six distributors.** This is a hard gate in the spec.
2. **All SQL queries must use IPS parameterized queries — no string interpolation of user input into queries ever.**
3. **Redis must be bound to 127.0.0.1 with requirepass set before the server goes live.** See Appendix C.
4. **libxml_disable_entity_loader(true) must appear before every XML parse in feed ingestion.** See Appendix C.
5. **All 12 plugins must register CSRF token validation on every state-changing front-end action.** See Appendix C.
6. **Never store SES credentials or API keys in source code or commit them to Git.**

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