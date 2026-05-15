<?php
if ( !defined( '\\IPS\\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }

/**
 * v1.0.147 - Update help template (last shipped in v143) to include the
 * 4 new optional ammo fields: ammo.fire_type, ammo.bullet_design,
 * ammo.tip_color, ammo.case_material.
 *
 * Changes vs v143 help template:
 *   - Step 1 "Category-specific required fields" - clarify ammo has new
 *     optional subfields with their enum values
 *   - Sidebar "Category-Specific Fields" table - add 4 new ammo fields
 *
 * Following project rule #38: replaces whole template content (no regex
 * patches into existing body).
 * Following project rule #19: uses update() to overwrite the existing row.
 * Following project rule #28: full template body declared inline as nowdoc.
 */

$helpTpl = <<<'TEMPLATE_EOT'
<div class="gdHelpPage">
	<div class="gdHelpPage__header">
		<h2>Feed Setup Guide</h2>
		<p>{$helpData['intro']}</p>
	</div>

	<div class="gdHelpPage__grid">
		<div class="gdHelpPage__main">

			<div class="gdHelpPage__card">
				<h3><span class="gdHelpPage__num">1</span> Prepare your product feed</h3>
				<p>{$helpData['step1']}</p>
				<p><strong>Required fields per listing (all categories):</strong></p>
				<ul>
					<li><strong>upc</strong> &mdash; 12 or 13 digit barcode (UPC-A or EAN-13)</li>
					<li><strong>category</strong> &mdash; any text. Auto-mapped to one of: firearm, ammo, part, accessory, optic, reloading, knife, apparel. Unrecognized values fall back to &ldquo;accessory&rdquo; and can be re-assigned in your Categories tab.</li>
					<li><strong>price</strong> &mdash; positive decimal in USD (e.g. 549.99)</li>
					<li><strong>condition</strong> &mdash; new, used, or refurbished</li>
					<li><strong>url</strong> &mdash; https:// link to the product page on your site</li>
					<li><strong>in_stock</strong> &mdash; 1 or 0 (boolean)</li>
				</ul>
				<p><strong>Optional fields:</strong></p>
				<ul>
					<li><strong>shipping_info</strong> &mdash; Free-form shipping description (max 60 chars). Examples: &ldquo;Free shipping&rdquo;, &ldquo;$9.95 flat&rdquo;, &ldquo;Free over $200&rdquo;. Old feeds using shipping_cost or free_shipping are accepted and auto-converted.</li>
					<li><strong>sku</strong> &mdash; your internal SKU (max 100 chars)</li>
					<li><strong>map_price</strong> &mdash; manufacturer MAP. When greater than price, listing displays MAP with "Click to see price" CTA.</li>
					<li><strong>stock_qty</strong> &mdash; non-negative integer quantity</li>
					<li><strong>name</strong>, <strong>brand</strong>, <strong>mpn</strong>, <strong>image_url</strong> &mdash; product metadata</li>
				</ul>
				<p><strong>Category-specific recommended fields</strong> (missing subfields don&rsquo;t block import but prevent the listing from appearing in advanced search filters):</p>
				<ul>
					<li><strong>ammo</strong> &mdash; ammo.caliber, ammo.rounds (all other ammo subfields are optional, see sidebar)</li>
					<li><strong>part</strong> &mdash; part.type</li>
					<li><strong>reloading</strong> &mdash; reloading.type (bullet/brass/primer), reloading.rounds, plus type-specific subfield</li>
					<li><strong>optic</strong> &mdash; optic.type (red_dot, holographic, lpvo, rifle_scope, pistol_scope, magnifier, iron_sights, prism)</li>
					<li><strong>knife</strong> &mdash; knife.type (fixed_blade, folding, automatic, assisted, multitool)</li>
					<li><strong>firearm, accessory, apparel</strong> &mdash; no recommended subfields</li>
				</ul>
			</div>

			<div class="gdHelpPage__card">
				<h3><span class="gdHelpPage__num">2</span> Format your feed</h3>
				<p>{$helpData['step2']}</p>
				{{if $helpData['step2_xml']}}
				<p><strong>XML format example:</strong></p>
				<pre>{$helpData['step2_xml']}</pre>
				{{endif}}
				{{if $helpData['step2_json']}}
				<p><strong>JSON format example:</strong></p>
				<pre>{$helpData['step2_json']}</pre>
				{{endif}}
				{{if $helpData['step2_csv']}}
				<p><strong>CSV format example:</strong></p>
				<pre>{$helpData['step2_csv']}</pre>
				{{endif}}
			</div>

			<div class="gdHelpPage__card">
				<h3><span class="gdHelpPage__num">3</span> Configure field mapping</h3>
				<p>{$helpData['step3']}</p>
				<p>If your existing feed uses different field names, paste a JSON mapping in the Field Mapping box on Feed Settings. Keys are your dealer field names; values are our canonical field names (use dotted notation for nested category-specific fields):</p>
				<pre>{
  "product_id":     "upc",
  "list_price":     "price",
  "manufacturer":   "brand",
  "product_link":   "url",
  "stock_count":    "stock_qty",
  "ship_cost":      "shipping_info",
  "specs.caliber":  "ammo.caliber",
  "specs.rounds":   "ammo.rounds"
}</pre>
			</div>

			<div class="gdHelpPage__card">
				<h3><span class="gdHelpPage__num">4</span> Test your feed</h3>
				<p>Before pointing the importer at your live feed, paste a sample at our validator. The validator parses your feed and returns a per-row report of any errors or warnings &mdash; same rules the importer applies, no data saved.</p>
				<p><a href="/dealers/feed-validator" class="ipsButton ipsButton--primary">Open feed validator</a></p>
			</div>

			<div class="gdHelpPage__card">
				<h3><span class="gdHelpPage__num">5</span> Enter your feed URL</h3>
				<p>{$helpData['step4']}</p>
				<ul>
					<li>Basic Auth: <code>{"username":"user","password":"pass"}</code></li>
					<li>API Key: <code>{"api_key":"your-key-here"}</code></li>
				</ul>
			</div>

			<div class="gdHelpPage__card">
				<h3><span class="gdHelpPage__num">6</span> Review your listings</h3>
				<p>{$helpData['step5']}</p>
			</div>

			{{if count($helpData['requirements']) > 0}}
			<div class="gdHelpPage__card gdHelpPage__card--info">
				<h3>Feed Requirements Summary</h3>
				<ul>
					{{foreach $helpData['requirements'] as $req}}
					<li>{$req}</li>
					{{endforeach}}
				</ul>
			</div>
			{{endif}}
		</div>

		<aside class="gdHelpPage__sidebar">
			<div class="gdHelpPage__card">
				<h3>Quick Field Reference</h3>
				<table>
					<tr><td>upc</td><td class="gdHelpPage__req">Required</td></tr>
					<tr><td>category</td><td class="gdHelpPage__req">Required</td></tr>
					<tr><td>price</td><td class="gdHelpPage__req">Required</td></tr>
					<tr><td>condition</td><td class="gdHelpPage__req">Required</td></tr>
					<tr><td>url</td><td class="gdHelpPage__req">Required</td></tr>
					<tr><td>in_stock</td><td class="gdHelpPage__req">Required</td></tr>
					<tr><td>shipping_info</td><td class="gdHelpPage__opt">Optional</td></tr>
					<tr><td>sku</td><td class="gdHelpPage__opt">Optional</td></tr>
					<tr><td>map_price</td><td class="gdHelpPage__opt">Optional</td></tr>
					<tr><td>stock_qty</td><td class="gdHelpPage__opt">Optional</td></tr>
					<tr><td>name</td><td class="gdHelpPage__opt">Optional</td></tr>
					<tr><td>brand</td><td class="gdHelpPage__opt">Optional</td></tr>
					<tr><td>mpn</td><td class="gdHelpPage__opt">Optional</td></tr>
					<tr><td>image_url</td><td class="gdHelpPage__opt">Optional</td></tr>
				</table>
			</div>

			<div class="gdHelpPage__card">
				<h3>Category-Specific Fields</h3>
				<table>
					<tr><td>ammo.caliber</td><td class="gdHelpPage__req">If ammo</td></tr>
					<tr><td>ammo.rounds</td><td class="gdHelpPage__req">If ammo</td></tr>
					<tr><td>ammo.fire_type</td><td class="gdHelpPage__opt">If ammo</td></tr>
					<tr><td>ammo.bullet_design</td><td class="gdHelpPage__opt">If ammo</td></tr>
					<tr><td>ammo.tip_color</td><td class="gdHelpPage__opt">If ammo</td></tr>
					<tr><td>ammo.case_material</td><td class="gdHelpPage__opt">If ammo</td></tr>
					<tr><td>part.type</td><td class="gdHelpPage__req">If part</td></tr>
					<tr><td>reloading.type</td><td class="gdHelpPage__req">If reloading</td></tr>
					<tr><td>reloading.rounds</td><td class="gdHelpPage__req">If reloading</td></tr>
					<tr><td>optic.type</td><td class="gdHelpPage__req">If optic</td></tr>
					<tr><td>knife.type</td><td class="gdHelpPage__req">If knife</td></tr>
					<tr><td>firearm.model</td><td class="gdHelpPage__opt">If firearm</td></tr>
					<tr><td>firearm.type</td><td class="gdHelpPage__opt">If firearm</td></tr>
					<tr><td>firearm.action</td><td class="gdHelpPage__opt">If firearm</td></tr>
					<tr><td>firearm.caliber</td><td class="gdHelpPage__opt">If firearm</td></tr>
				</table>
			</div>

			<div class="gdHelpPage__card">
				<h3>Sync Schedule</h3>
				<table>
					<tr><td>Basic</td><td>Every 6 hours</td></tr>
					<tr><td>Pro</td><td>Every 30 min</td></tr>
					<tr><td>Enterprise</td><td>Every 15 min</td></tr>
				</table>
			</div>

			<div class="gdHelpPage__card">
				<h3>Test Your Feed</h3>
				<p>Validate your feed format before importing.</p>
				<a href="/dealers/feed-validator" class="ipsButton ipsButton--primary" style="width:100%;text-align:center;display:block">Open Validator</a>
			</div>

			{{if $helpData['contact']}}
			<div class="gdHelpPage__card">
				<h3>Need Help?</h3>
				<p>Our team can help you get your feed configured and your first import running.</p>
				<a href="mailto:{$helpData['contact']}" class="ipsButton ipsButton--primary" style="width:100%;text-align:center;display:block">Email Support</a>
			</div>
			{{endif}}
		</aside>
	</div>
</div>

<style>
.gdHelpPage { max-width: 1200px; margin: 0 auto; padding: 20px; }
.gdHelpPage__header { margin-bottom: 24px; }
.gdHelpPage__header h2 { margin: 0 0 8px; font-size: 1.5em; font-weight: 700; color: #111827; }
.gdHelpPage__header p { margin: 0; color: #64748b; }

.gdHelpPage__grid {
	display: grid;
	grid-template-columns: minmax(0, 1fr) 300px;
	gap: 24px;
	align-items: start;
}
.gdHelpPage__main { min-width: 0; display: flex; flex-direction: column; gap: 16px; }
.gdHelpPage__sidebar {
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: 16px;
	position: sticky;
	top: 20px;
}

.gdHelpPage__card {
	background: #fff;
	border: 1px solid var(--i-border-color, #e0e0e0);
	border-radius: 8px;
	padding: 20px;
}
.gdHelpPage__card--info {
	background: #f0f7ff;
	border-color: #bfdbfe;
}
.gdHelpPage__card--info h3 { color: #1e40af; }
.gdHelpPage__card--info ul { color: #1e3a5f; }
.gdHelpPage__card h3 {
	margin: 0 0 12px;
	font-size: 1.05em;
	font-weight: 700;
	color: #1e3a5f;
	display: flex;
	align-items: center;
	gap: 8px;
}
.gdHelpPage__num {
	background: #2563eb;
	color: #fff;
	border-radius: 50%;
	width: 24px;
	height: 24px;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	font-size: 0.8em;
	font-weight: 700;
	flex-shrink: 0;
}
.gdHelpPage__card ul { margin: 8px 0; padding-left: 20px; }
.gdHelpPage__card p { margin: 0 0 12px; }
.gdHelpPage__card p:last-child { margin-bottom: 0; }
.gdHelpPage__card pre {
	background: #f4f4f4;
	padding: 12px;
	border-radius: 4px;
	overflow-x: auto;
	font-size: 0.85em;
	margin: 12px 0 0;
	white-space: pre;
}
.gdHelpPage__card code {
	background: #f4f4f4;
	padding: 1px 6px;
	border-radius: 3px;
}
.gdHelpPage__card table {
	width: 100%;
	font-size: 0.9em;
	border-collapse: collapse;
}
.gdHelpPage__card table tr { border-bottom: 1px solid #f0f0f0; }
.gdHelpPage__card table tr:last-child { border-bottom: none; }
.gdHelpPage__card table td { padding: 8px 0; font-weight: 500; }
.gdHelpPage__card table td:last-child { text-align: right; color: #64748b; font-weight: 400; }
.gdHelpPage__req { color: #16a34a !important; }
.gdHelpPage__opt { color: #64748b !important; }

@media (max-width: 900px) {
	.gdHelpPage__grid {
		grid-template-columns: 1fr;
	}
	.gdHelpPage__sidebar {
		position: static;
		top: auto;
	}
}

@media (max-width: 480px) {
	.gdHelpPage { padding: 12px; }
	.gdHelpPage__card { padding: 16px; }
	.gdHelpPage__header h2 { font-size: 1.3em; }
}
</style>
TEMPLATE_EOT;

try
{
	\IPS\Db::i()->update( 'core_theme_templates',
		[
			'template_data'    => '$helpData',
			'template_content' => $helpTpl,
			'template_updated' => time(),
		],
		[ 'template_app=? AND template_location=? AND template_group=? AND template_name=?',
		  'gddealer', 'front', 'dealers', 'help' ]
	);
}
catch ( \Throwable $e )
{
	try { \IPS\Log::log( 'templates_10147_help.php update failed: ' . $e->getMessage(), 'gddealer_upg_10147' ); }
	catch ( \Throwable ) {}
}
