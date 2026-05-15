<?php
namespace IPS\gddealer\setup;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) {
    header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
    exit;
}

$gddealerV10082Templates = [];

/* help */
$gddealerV10082Templates[] = [
    'set_id'            => 1,
    'app'               => 'gddealer',
    'location'          => 'front',
    'group'             => 'dealers',
    'template_name'     => 'help',
    'template_data'     => '$data',
    'template_content'  => <<<'TEMPLATE_EOT'
<div class="gdPageHeader">
    <div class="gdPageHeader__titleBlock">
        <div class="gdPageHeader__eyebrow">Help center</div>
        <h1 class="gdPageHeader__title">Feed setup guide</h1>
        {{if $data['intro']}}
        <p class="gdPageHeader__sub">{$data['intro']|raw}</p>
        {{else}}
        <p class="gdPageHeader__sub">Follow these five steps to get your inventory live. Most dealers finish setup in under 30 minutes.</p>
        {{endif}}
    </div>
</div>

{{if count($data['requirements']) > 0}}
<div class="gdReqBanner">
    <div class="gdReqBanner__icon">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
    </div>
    <div class="gdReqBanner__body">
        <div class="gdReqBanner__title">Feed requirements at a glance</div>
        <div class="gdReqBanner__list">
            {{foreach $data['requirements'] as $req}}
            <span>&bull; {$req}</span>
            {{endforeach}}
        </div>
    </div>
</div>
{{endif}}

<nav class="gdProgressNav" aria-label="Steps">
    <div class="gdProgressNav__track">
        <a class="gdProgressNav__step is-active" href="#gdStep1"><span class="gdProgressNav__num">1</span>Prepare feed</a>
        <a class="gdProgressNav__step" href="#gdStep2"><span class="gdProgressNav__num">2</span>Format</a>
        <a class="gdProgressNav__step" href="#gdStep3"><span class="gdProgressNav__num">3</span>Field mapping</a>
        <a class="gdProgressNav__step" href="#gdStep4"><span class="gdProgressNav__num">4</span>Enter URL</a>
        <a class="gdProgressNav__step" href="#gdStep5"><span class="gdProgressNav__num">5</span>Review listings</a>
    </div>
</nav>

<div class="gdHelpLayout">

    <div class="gdHelpLayout__main">

        <section class="gdStep" id="gdStep1">
            <div class="gdStep__head">
                <span class="gdStep__num">1</span>
                <div>
                    <div class="gdStep__title">Prepare your product feed</div>
                </div>
            </div>
            {{if $data['step1']}}
            <div class="gdStep__body">{$data['step1']|raw}</div>
            {{endif}}
        </section>

        <section class="gdStep" id="gdStep2">
            <div class="gdStep__head">
                <span class="gdStep__num">2</span>
                <div>
                    <div class="gdStep__title">Format your feed</div>
                </div>
            </div>
            {{if $data['step2']}}
            <div class="gdStep__body">{$data['step2']|raw}</div>
            {{endif}}

            {{if $data['step2_csv'] or $data['step2_json'] or $data['step2_xml']}}
            <div class="gdFormatTabs" role="tablist">
                {{if $data['step2_csv']}}<button class="gdFormatTab is-active" data-format="csv" type="button">CSV</button>{{endif}}
                {{if $data['step2_json']}}<button class="gdFormatTab" data-format="json" type="button">JSON</button>{{endif}}
                {{if $data['step2_xml']}}<button class="gdFormatTab" data-format="xml" type="button">XML</button>{{endif}}
            </div>

            {{if $data['step2_csv']}}
            <div class="gdFormatPanel is-active" data-panel="csv">
                <div class="gdCodeBlock">
                    <button class="gdCodeBlock__copy" data-copy-target="gdCode-csv" type="button">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                        Copy
                    </button>
                    <pre id="gdCode-csv">{$data['step2_csv']}</pre>
                </div>
            </div>
            {{endif}}
            {{if $data['step2_json']}}
            <div class="gdFormatPanel" data-panel="json">
                <div class="gdCodeBlock">
                    <button class="gdCodeBlock__copy" data-copy-target="gdCode-json" type="button">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                        Copy
                    </button>
                    <pre id="gdCode-json">{$data['step2_json']}</pre>
                </div>
            </div>
            {{endif}}
            {{if $data['step2_xml']}}
            <div class="gdFormatPanel" data-panel="xml">
                <div class="gdCodeBlock">
                    <button class="gdCodeBlock__copy" data-copy-target="gdCode-xml" type="button">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                        Copy
                    </button>
                    <pre id="gdCode-xml">{$data['step2_xml']}</pre>
                </div>
            </div>
            {{endif}}
            {{endif}}
        </section>

        <section class="gdStep" id="gdStep3">
            <div class="gdStep__head">
                <span class="gdStep__num">3</span>
                <div>
                    <div class="gdStep__title">Configure field mapping</div>
                </div>
            </div>
            {{if $data['step3']}}
            <div class="gdStep__body">{$data['step3']|raw}</div>
            {{endif}}
        </section>

        <section class="gdStep" id="gdStep4">
            <div class="gdStep__head">
                <span class="gdStep__num">4</span>
                <div>
                    <div class="gdStep__title">Enter your feed URL</div>
                </div>
            </div>
            {{if $data['step4']}}
            <div class="gdStep__body">{$data['step4']|raw}</div>
            {{endif}}
        </section>

        <section class="gdStep" id="gdStep5">
            <div class="gdStep__head">
                <span class="gdStep__num">5</span>
                <div>
                    <div class="gdStep__title">Review your listings</div>
                </div>
            </div>
            {{if $data['step5']}}
            <div class="gdStep__body">{$data['step5']|raw}</div>
            {{endif}}
        </section>

    </div>

    <aside class="gdHelpLayout__rail">

        <div class="gdRailCard">
            <div class="gdRailCard__title">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:6px"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                Quick field reference
            </div>
            <div class="gdRefTable">
                <div class="gdRefRow"><span class="gdRefRow__name">upc</span><span class="gdRefRow__pill gdRefRow__pill--req">Required</span></div>
                <div class="gdRefRow"><span class="gdRefRow__name">dealer_price</span><span class="gdRefRow__pill gdRefRow__pill--req">Required</span></div>
                <div class="gdRefRow"><span class="gdRefRow__name">in_stock</span><span class="gdRefRow__pill gdRefRow__pill--req">Required</span></div>
                <div class="gdRefRow"><span class="gdRefRow__name">shipping_info</span><span class="gdRefRow__pill gdRefRow__pill--opt">Optional</span></div>
                <div class="gdRefRow"><span class="gdRefRow__name">condition</span><span class="gdRefRow__pill gdRefRow__pill--opt">Optional</span></div>
                <div class="gdRefRow"><span class="gdRefRow__name">listing_url</span><span class="gdRefRow__pill gdRefRow__pill--opt">Optional</span></div>
                <div class="gdRefRow"><span class="gdRefRow__name">stock_qty</span><span class="gdRefRow__pill gdRefRow__pill--opt">Optional</span></div>
                <div class="gdRefRow"><span class="gdRefRow__name">dealer_sku</span><span class="gdRefRow__pill gdRefRow__pill--opt">Optional</span></div>
            </div>
        </div>

        <div class="gdRailCard">
            <div class="gdRailCard__title">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:6px"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                Sync schedule
            </div>
            <div class="gdSyncSchedule">
                <div class="gdSyncSchedule__row">
                    <span class="gdTierBadge gdTierBadge--basic">Basic</span>
                    <span class="gdSyncSchedule__freq">{$data['sync_basic']}</span>
                </div>
                <div class="gdSyncSchedule__row">
                    <span class="gdTierBadge gdTierBadge--pro">Pro</span>
                    <span class="gdSyncSchedule__freq">{$data['sync_pro']}</span>
                </div>
                <div class="gdSyncSchedule__row">
                    <span class="gdTierBadge gdTierBadge--enterprise">Enterprise</span>
                    <span class="gdSyncSchedule__freq">{$data['sync_ent']}</span>
                </div>
            </div>
        </div>

        <div class="gdContactCard">
            <div class="gdContactCard__title">Need a hand?</div>
            <div class="gdContactCard__sub">Our team can help you get your feed configured and your first import running smoothly.</div>
            <a href="mailto:{$data['contact']}" class="gdBtn gdBtn--primary gdBtn--sm" style="display:inline-block">Email support</a>
            <a href="{$data['support_url']}" class="gdBtn gdBtn--secondary gdBtn--sm" style="display:inline-block;margin-left:6px">Open a ticket</a>
        </div>

    </aside>

</div>

<script>
(function(){
    var tabs = document.querySelectorAll('.gdFormatTab');
    tabs.forEach(function(tab){
        tab.addEventListener('click', function(){
            var fmt = tab.getAttribute('data-format');
            tabs.forEach(function(t){ t.classList.remove('is-active'); });
            tab.classList.add('is-active');
            document.querySelectorAll('.gdFormatPanel').forEach(function(p){ p.classList.remove('is-active'); });
            var panel = document.querySelector('.gdFormatPanel[data-panel="' + fmt + '"]');
            if ( panel ) panel.classList.add('is-active');
        });
    });

    document.querySelectorAll('.gdCodeBlock__copy').forEach(function(btn){
        btn.addEventListener('click', function(){
            var targetId = btn.getAttribute('data-copy-target');
            var el = document.getElementById(targetId);
            if ( !el ) return;
            var txt = el.textContent;
            if ( navigator.clipboard && navigator.clipboard.writeText ) {
                navigator.clipboard.writeText(txt).then(function(){
                    var orig = btn.innerHTML;
                    btn.textContent = 'Copied';
                    setTimeout(function(){ btn.innerHTML = orig; }, 1400);
                });
            } else {
                var range = document.createRange();
                range.selectNode(el);
                var sel = window.getSelection();
                sel.removeAllRanges(); sel.addRange(range);
                try { document.execCommand('copy'); } catch(e) {}
                sel.removeAllRanges();
            }
        });
    });

    var steps = document.querySelectorAll('.gdStep');
    var navLinks = document.querySelectorAll('.gdProgressNav__step');
    function onScroll(){
        var fromTop = window.scrollY + 140;
        steps.forEach(function(step, idx){
            if ( step.offsetTop <= fromTop && step.offsetTop + step.offsetHeight > fromTop ) {
                navLinks.forEach(function(l){ l.classList.remove('is-active'); });
                if ( navLinks[idx] ) navLinks[idx].classList.add('is-active');
            }
        });
    }
    if ( steps.length > 0 ) {
        window.addEventListener('scroll', onScroll, { passive: true });
    }
})();
</script>
TEMPLATE_EOT
];

foreach ( $gddealerV10082Templates as $tpl ) {
    $masterKey = md5( $tpl['app'] . ';' . $tpl['location'] . ';' . $tpl['group'] . ';' . $tpl['template_name'] );

    \IPS\Db::i()->replace( 'core_theme_templates', [
        'template_set_id'        => $tpl['set_id'],
        'template_app'           => $tpl['app'],
        'template_location'      => $tpl['location'],
        'template_group'         => $tpl['group'],
        'template_name'          => $tpl['template_name'],
        'template_data'          => $tpl['template_data'],
        'template_content'       => $tpl['template_content'],
        'template_master_key'    => $masterKey,
        'template_updated'       => time(),
    ] );
}

try { \IPS\Theme::master()->recompileTemplates(); } catch ( \Throwable ) {}
try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
try { \IPS\Data\Store::i()->clearAll(); } catch ( \Throwable ) {}
