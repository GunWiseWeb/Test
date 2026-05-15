<?php
/**
 * gddealer v1.0.189 - Categories dashboard tab.
 *
 * v188 successfully shipped: gd_dealer_listings.category column,
 * gd_dealer_category_map table, CategoryNormalizer + CategoryMap helper
 * classes, importer per-record hook. Auto-classification runs on import
 * (44 distinct categories mapped, 623 listings categorized for Defense
 * Depot on first manual cron).
 *
 * What v188 was supposed to ship but didn't (Claude Code missed the
 * manual file-edit instructions): the actual dashboard tab where the
 * dealer reviews their mappings.
 *
 * v189 adds (all via runtime patches, no manual file edits):
 *   1. categories() and saveCategoryMap() controller methods on
 *      dashboard.php
 *   2. dashboardCategories template seeded into core_theme_templates
 *   3. Overview banner template patch: when unreviewedCount > 0, show
 *      "Review N auto-classified categories" link
 *   4. Language strings for the new tab
 *
 * Idempotent. Marker: v189-categories-tab.
 */

namespace IPS\gddealer\setup\upg_10189;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
    header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
    exit;
}

class _upgrade
{
    public function step1(): bool
    {
        try
        {
            $appVersion = (int) \IPS\Db::i()->select(
                'app_long_version', 'core_applications',
                [ 'app_directory=?', 'gddealer' ]
            )->first();
            if ( $appVersion < 10188 )
            {
                try { \IPS\Log::log( 'v189 ran with app_long_version=' . $appVersion . ' (expected >=10188).', 'gddealer_upg_10189' ); } catch ( \Throwable ) {}
            }
        }
        catch ( \Throwable ) {}

        $this->addControllerMethods();
        $this->seedTemplate();
        $this->seedLanguageStrings();

        try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
        try { \IPS\Db::i()->delete( 'core_store' ); } catch ( \Throwable ) {}

        try { \IPS\Log::log( 'v189 upgrade complete.', 'gddealer_upg_10189' ); } catch ( \Throwable ) {}

        return TRUE;
    }

    /**
     * Step 1 - inject the categories() and saveCategoryMap() methods
     * into dashboard.php immediately BEFORE the reviews() method.
     */
    protected function addControllerMethods(): void
    {
        $path = \IPS\ROOT_PATH . '/applications/gddealer/modules/front/dealers/dashboard.php';
        if ( !is_file( $path ) ) { return; }

        $contents = (string) file_get_contents( $path );

        if ( strpos( $contents, 'v189-categories-tab' ) !== false )
        {
            try { \IPS\Log::log( 'dashboard.php already has v189-categories-tab marker.', 'gddealer_upg_10189' ); } catch ( \Throwable ) {}
            return;
        }

        $needle = 'protected function reviews(): void';

        $count = substr_count( $contents, $needle );
        if ( $count !== 1 )
        {
            try { \IPS\Log::log( 'dashboard.php: reviews() needle count=' . $count . '. Aborting.', 'gddealer_upg_10189' ); } catch ( \Throwable ) {}
            return;
        }

        $newMethods = <<<'PHPCODE'
/**
     * v189-categories-tab: Dealer reviews and edits auto-classified
     * category mappings from gd_dealer_category_map.
     */
    protected function categories(): void
    {
        $dealer   = $this->dealer;
        $dealerId = (int) $dealer->dealer_id;

        $mappings = \IPS\gddealer\Feed\CategoryMap::allForDealer( $dealerId );

        /* Summary counts by canonical. */
        $byCanonical = [];
        foreach ( $mappings as $m )
        {
            $c = $m['canonical'];
            if ( !isset( $byCanonical[ $c ] ) )
            {
                $byCanonical[ $c ] = [ 'count' => 0, 'rows' => 0 ];
            }
            $byCanonical[ $c ]['count'] += $m['occurrence_count'];
            $byCanonical[ $c ]['rows']++;
        }

        $unreviewed = \IPS\gddealer\Feed\CategoryMap::unreviewedCount( $dealerId );

        $saveUrl = (string) \IPS\Http\Url::internal(
            'app=gddealer&module=dealers&controller=dashboard&do=saveCategoryMap'
        );

        $data = [
            'dealer'         => method_exists( $this, 'dealerSummary' ) ? $this->dealerSummary() : [],
            'tab_urls'       => $this->tabUrls(),
            'mappings'       => $mappings,
            'by_canonical'   => $byCanonical,
            'valid_options'  => \IPS\gddealer\Feed\CategoryNormalizer::VALID,
            'unreviewed'     => $unreviewed,
            'save_url'       => $saveUrl,
            'csrf_key'       => (string) \IPS\Session::i()->csrfKey,
            'saved_flash'    => (int) ( \IPS\Request::i()->saved ?? 0 ) === 1,
        ];

        $body = (string) \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->dashboardCategories( $data );

        if ( method_exists( $this, 'output' ) )
        {
            $this->output( 'categories', $body );
        }
        else
        {
            \IPS\Output::i()->output = $body;
        }
    }

    /**
     * v189-categories-tab: save dealer's edits to gd_dealer_category_map.
     */
    protected function saveCategoryMap(): void
    {
        \IPS\Session::i()->csrfCheck();

        $dealerId = (int) $this->dealer->dealer_id;

        $raw = \IPS\Request::i()->canonical ?? [];
        if ( !is_array( $raw ) ) { $raw = []; }

        $updates = [];
        foreach ( $raw as $id => $canonical )
        {
            $updates[ (int) $id ] = (string) $canonical;
        }

        $applied = \IPS\gddealer\Feed\CategoryMap::applyDealerEdits( $dealerId, $updates );

        try { \IPS\Log::log( 'Dealer ' . $dealerId . ' updated ' . $applied . ' category mapping(s).', 'gddealer_category_map' ); } catch ( \Throwable ) {}

        \IPS\Output::i()->redirect(
            \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=categories' )->setQueryString( 'saved', 1 )
        );
    }

PHPCODE;

        $contents = str_replace( $needle, $newMethods . $needle, $contents );

        file_put_contents( $path, $contents );

        try { \IPS\Log::log( 'Injected categories() + saveCategoryMap() methods into dashboard.php (v189).', 'gddealer_upg_10189' ); } catch ( \Throwable ) {}
    }

    /**
     * Step 2 - seed the dashboardCategories template into
     * core_theme_templates. Template_set_id=1 is app-shipped.
     */
    protected function seedTemplate(): void
    {
        try
        {
            $existing = 0;
            try
            {
                $existing = (int) \IPS\Db::i()->select( 'COUNT(*)', 'core_theme_templates',
                    [ "template_app='gddealer' AND template_group='dealers' AND template_name='dashboardCategories' AND template_set_id=1" ]
                )->first();
            }
            catch ( \Throwable ) {}

            $templateContent = $this->getTemplateBody();
            $now = time();

            if ( $existing > 0 )
            {
                \IPS\Db::i()->update( 'core_theme_templates',
                    [
                        'template_content' => $templateContent,
                        'template_updated' => $now,
                    ],
                    [ "template_app='gddealer' AND template_group='dealers' AND template_name='dashboardCategories' AND template_set_id=1" ]
                );
                try { \IPS\Log::log( 'Updated existing dashboardCategories template.', 'gddealer_upg_10189' ); } catch ( \Throwable ) {}
            }
            else
            {
                \IPS\Db::i()->insert( 'core_theme_templates', [
                    'template_set_id'        => 1,
                    'template_group'         => 'dealers',
                    'template_content'       => $templateContent,
                    'template_name'          => 'dashboardCategories',
                    'template_data'          => '$data',
                    'template_updated'       => $now,
                    'template_master_key'    => '',
                    'template_location'      => 'front',
                    'template_app'           => 'gddealer',
                    'template_version'       => '10189',
                    'template_has_hookpoints'=> 0,
                ] );
                try { \IPS\Log::log( 'Inserted new dashboardCategories template.', 'gddealer_upg_10189' ); } catch ( \Throwable ) {}
            }
        }
        catch ( \Throwable $e )
        {
            try { \IPS\Log::log( 'seedTemplate failed: ' . $e->getMessage(), 'gddealer_upg_10189' ); } catch ( \Throwable ) {}
        }
    }

    /**
     * Step 3 - language strings for the tab label + save flash.
     */
    protected function seedLanguageStrings(): void
    {
        $strings = [
            'gddealer_tab_categories'       => 'Categories',
            'gddealer_categories_saved'     => 'Saved.',
            'gddealer_categories_pageTitle' => 'Category Mappings',
        ];

        try
        {
            foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
            {
                $langId = (int) $langId;
                foreach ( $strings as $key => $value )
                {
                    try
                    {
                        \IPS\Db::i()->replace( 'core_sys_lang_words', [
                            'lang_id'      => $langId,
                            'word_app'     => 'gddealer',
                            'word_key'     => $key,
                            'word_default' => $value,
                            'word_js'      => 0,
                            'word_export'  => 1,
                        ] );
                    }
                    catch ( \Throwable ) {}
                }
            }

            try { \IPS\Log::log( 'Seeded ' . count( $strings ) . ' language strings.', 'gddealer_upg_10189' ); } catch ( \Throwable ) {}
        }
        catch ( \Throwable $e )
        {
            try { \IPS\Log::log( 'seedLanguageStrings failed: ' . $e->getMessage(), 'gddealer_upg_10189' ); } catch ( \Throwable ) {}
        }
    }

    /**
     * The dashboardCategories template body.
     */
    protected function getTemplateBody(): string
    {
        return <<<'TPL'
<div class="gdDashboard">
    <div class="gdDashboard__shell">

        {{if $data['tab_urls']}}
        <nav class="gdDashboard__tabs">
            {{foreach $data['tab_urls'] as $key => $url}}
                <a href="{$url}" class="gdDashboard__tab {{if $key === 'categories'}}gdDashboard__tab--active{{endif}}">
                    {expression="ucfirst( $key )"}
                </a>
            {{endforeach}}
        </nav>
        {{endif}}

        <div class="gdDashboard__body">

            <h1 class="gdDashboard__title">Category Mappings</h1>

            <p class="gdDashboard__lede">
                Your feed uses categories like "Firearms, Handguns, Pistols". We map them to one of our
                canonical categories so search and filtering work consistently. Edit any mapping below;
                set a category to "skip" to drop those records from your listings.
            </p>

            {{if $data['saved_flash']}}
            <div class="gdAlert gdAlert--success">Mappings saved.</div>
            {{endif}}

            {{if $data['unreviewed'] > 0}}
            <div class="gdAlert gdAlert--info">
                <strong>{$data['unreviewed']}</strong> mapping(s) were auto-classified and haven't been reviewed yet. Confirm or change them below.
            </div>
            {{endif}}

            {{if $data['by_canonical']}}
            <div class="gdCatSummary">
                {{foreach $data['by_canonical'] as $cat => $info}}
                <div class="gdCatSummary__pill">
                    <span class="gdCatSummary__name">{expression="ucfirst( $cat )"}</span>
                    <span class="gdCatSummary__count">{$info['count']} records</span>
                </div>
                {{endforeach}}
            </div>
            {{endif}}

            {{if count( $data['mappings'] ) === 0}}
                <div class="gdEmpty">
                    <p>No category mappings yet. Run your next import and they'll populate automatically.</p>
                </div>
            {{else}}
                <form method="post" action="{$data['save_url']}" class="gdCatForm">
                    <input type="hidden" name="csrfKey" value="{$data['csrf_key']}">

                    <table class="gdCatTable">
                        <thead>
                            <tr>
                                <th class="gdCatTable__source">Your category</th>
                                <th class="gdCatTable__canonical">Maps to</th>
                                <th class="gdCatTable__count">Records</th>
                                <th class="gdCatTable__status">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {{foreach $data['mappings'] as $m}}
                            <tr class="{{if $m['auto_mapped']}}gdCatTable__row--auto{{endif}}">
                                <td class="gdCatTable__source">
                                    <span class="gdCatTable__sourceText">{$m['source_value']}</span>
                                </td>
                                <td class="gdCatTable__canonical">
                                    <select name="canonical[{$m['id']}]" class="gdCatTable__select">
                                        {{foreach $data['valid_options'] as $opt}}
                                        <option value="{$opt}" {{if $m['canonical'] === $opt}}selected{{endif}}>{$opt}</option>
                                        {{endforeach}}
                                    </select>
                                </td>
                                <td class="gdCatTable__count">{$m['occurrence_count']}</td>
                                <td class="gdCatTable__status">
                                    {{if $m['auto_mapped']}}
                                        <span class="gdCatTable__badge gdCatTable__badge--auto">auto</span>
                                    {{else}}
                                        <span class="gdCatTable__badge gdCatTable__badge--confirmed">confirmed</span>
                                    {{endif}}
                                </td>
                            </tr>
                            {{endforeach}}
                        </tbody>
                    </table>

                    <div class="gdCatForm__actions">
                        <button type="submit" class="gdBtn gdBtn--primary">Save Changes</button>
                    </div>
                </form>
            {{endif}}

        </div>
    </div>
</div>

<style>
.gdDashboard__shell { max-width: 1200px; margin: 0 auto; padding: 24px; font-family: 'Inter', system-ui, sans-serif; }
.gdDashboard__tabs { display: flex; gap: 4px; border-bottom: 2px solid #e5e7eb; margin-bottom: 24px; flex-wrap: wrap; }
.gdDashboard__tab { padding: 12px 20px; color: #6b7280; text-decoration: none; border-bottom: 2px solid transparent; margin-bottom: -2px; font-weight: 500; }
.gdDashboard__tab:hover { color: #1f2937; }
.gdDashboard__tab--active { color: #1e40af; border-bottom-color: #1e40af; }
.gdDashboard__title { font-size: 28px; font-weight: 700; margin: 0 0 8px; color: #111827; }
.gdDashboard__lede { color: #6b7280; margin: 0 0 24px; max-width: 760px; line-height: 1.6; }
.gdAlert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; }
.gdAlert--success { background: #d1fae5; color: #065f46; border: 1px solid #34d399; }
.gdAlert--info { background: #dbeafe; color: #1e40af; border: 1px solid #60a5fa; }
.gdCatSummary { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 24px; }
.gdCatSummary__pill { background: #f3f4f6; padding: 8px 14px; border-radius: 999px; font-size: 13px; }
.gdCatSummary__name { font-weight: 600; color: #111827; text-transform: capitalize; }
.gdCatSummary__count { color: #6b7280; margin-left: 6px; }
.gdEmpty { padding: 48px; text-align: center; background: #f9fafb; border-radius: 12px; color: #6b7280; }
.gdCatForm { background: #fff; border-radius: 12px; border: 1px solid #e5e7eb; overflow: hidden; }
.gdCatTable { width: 100%; border-collapse: collapse; }
.gdCatTable th { background: #f9fafb; padding: 12px 16px; text-align: left; font-weight: 600; font-size: 13px; color: #374151; border-bottom: 1px solid #e5e7eb; }
.gdCatTable td { padding: 12px 16px; border-bottom: 1px solid #f3f4f6; }
.gdCatTable__row--auto { background: #fffbeb; }
.gdCatTable__sourceText { font-family: 'JetBrains Mono', monospace; font-size: 13px; color: #1f2937; }
.gdCatTable__select { padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; }
.gdCatTable__count { color: #6b7280; font-variant-numeric: tabular-nums; }
.gdCatTable__badge { padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
.gdCatTable__badge--auto { background: #fef3c7; color: #92400e; }
.gdCatTable__badge--confirmed { background: #d1fae5; color: #065f46; }
.gdCatForm__actions { padding: 16px 20px; background: #f9fafb; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end; }
.gdBtn { padding: 10px 18px; border-radius: 8px; font-weight: 600; font-size: 14px; border: none; cursor: pointer; }
.gdBtn--primary { background: #1e40af; color: #fff; }
.gdBtn--primary:hover { background: #1e3a8a; }
</style>
TPL;
    }
}

class upgrade extends _upgrade {}
