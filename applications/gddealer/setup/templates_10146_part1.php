<?php
if ( !defined( '\\IPS\\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }

/**
 * v1.0.146 PART 1 of 3 - Schema page HTML structure (hero, tables, cards).
 *
 * This file builds the FIRST chunk of $feedSchemaTpl. It does NOT write
 * to the database. The orchestrator (upgrade.php) requires this file,
 * then part2, then part3, which appends the closing markup + writes to
 * core_theme_templates.
 *
 * Split into 3 files solely to work around save-side timeouts on the
 * monolithic ~34KB template version.
 */

$feedSchemaTpl = <<<'TEMPLATE_EOT'
<div class="gd-fs">

	<header class="gd-fs__hero">
		<div class="gd-fs__heroInner">
			<div class="gd-fs__eyebrow">DOCUMENTATION</div>
			<h1>Dealer Feed Schema v1.1</h1>
			<p class="gd-fs__lede">The official spec for syncing your inventory with GunRack. Format your feed as XML, JSON, or CSV; host it at a public URL; we import on a schedule based on your subscription tier.</p>
			<div class="gd-fs__heroCtas">
				<a href="/dealers/join" class="gd-fs__btn gd-fs__btn--primary">Become a Dealer</a>
				<a href="#examples" class="gd-fs__btn">See Examples</a>
			</div>
		</div>
	</header>

	<nav class="gd-fs__toc" aria-label="Table of contents">
		<div class="gd-fs__tocInner">
			<a href="#overview">Overview</a>
			<a href="#always-required">Required Fields</a>
			<a href="#always-optional">Optional Fields</a>
			<a href="#categories">Categories</a>
			<a href="#examples">Examples</a>
			<a href="#validation">Validation</a>
		</div>
	</nav>

	<main class="gd-fs__main">

		<section id="overview" class="gd-fs__section">
			<h2>Overview</h2>
			<p>Your feed is a <strong>flat list of listings</strong>. Each listing represents one product you have in stock and contains the fields described in this document. Listings are not nested by category &mdash; everything sits at the top level. Category-specific fields (like <code>ammo.caliber</code> or <code>firearm.model</code>) are grouped under their own sub-block in XML and JSON, or appear as flat dotted columns in CSV.</p>
			<p>All three formats produce identical data after parsing. Pick whichever is easier for your existing system to generate.</p>
			<p><strong>What our importer expects:</strong></p>
			<ul>
				<li>A publicly reachable HTTPS URL (Basic Auth or API key headers supported)</li>
				<li>A feed body in XML, JSON, or CSV format</li>
				<li>Same content on every request &mdash; no per-request pagination or filtering</li>
				<li>Updated at least once per day</li>
			</ul>
		</section>

		<section id="always-required" class="gd-fs__section">
			<h2>Required Fields</h2>
			<p>Every listing must include all of these, regardless of category.</p>
			<div class="gd-fs__tableWrap">
				<table class="gd-fs__table">
					<thead><tr><th>Field</th><th>Type</th><th>Constraints</th><th>Description</th></tr></thead>
					<tbody>
						<tr><td><code>upc</code></td><td>string</td><td>12 or 13 digits</td><td>UPC-A or EAN-13 barcode. Dashes/spaces are stripped automatically.</td></tr>
						<tr><td><code>category</code></td><td>enum</td><td>see below</td><td>One of: <code>firearm</code>, <code>ammo</code>, <code>part</code>, <code>accessory</code>, <code>optic</code>, <code>reloading</code>, <code>knife</code>, <code>apparel</code>.</td></tr>
						<tr><td><code>price</code></td><td>decimal</td><td>greater than 0</td><td>Your selling price in USD. No currency symbol.</td></tr>
						<tr><td><code>condition</code></td><td>enum</td><td>see below</td><td>One of: <code>new</code>, <code>used</code>, <code>refurbished</code>.</td></tr>
						<tr><td><code>url</code></td><td>URL</td><td>https://</td><td>Direct link to the product page on your site.</td></tr>
						<tr><td><code>in_stock</code></td><td>boolean</td><td>1/0 or true/false</td><td>Whether the listing is currently available for purchase.</td></tr>
						<tr><td><code>shipping_info</code></td><td>text</td><td>max 60 chars</td><td>Free-form shipping description. Examples: &ldquo;Free shipping&rdquo;, &ldquo;$9.95 flat&rdquo;, &ldquo;Free over $200&rdquo;. Old feeds using <code>shipping_cost</code> or <code>free_shipping</code> are accepted and auto-converted.</td></tr>
					</tbody>
				</table>
			</div>
		</section>

		<section id="always-optional" class="gd-fs__section">
			<h2>Optional Fields</h2>
			<p>These fields enrich your listing but aren't required. We recommend including them whenever possible.</p>
			<div class="gd-fs__tableWrap">
				<table class="gd-fs__table">
					<thead><tr><th>Field</th><th>Type</th><th>Constraints</th><th>Description</th></tr></thead>
					<tbody>
						<tr><td><code>sku</code></td><td>string</td><td>max 100 chars</td><td>Your internal SKU. Useful for reconciling listings with your inventory.</td></tr>
						<tr><td><code>map_price</code></td><td>decimal</td><td>greater than price</td><td>Manufacturer's MAP. When set, listing displays MAP and shows a "Click to see price" CTA pointing to your URL.</td></tr>
						<tr><td><code>stock_qty</code></td><td>integer</td><td>greater than or equal to 0</td><td>Exact quantity on hand. Helps power low-stock alerts.</td></tr>
						<tr><td><code>name</code></td><td>string</td><td>max 200 chars</td><td>Concise product name. Helps when the UPC isn't in our master catalog yet.</td></tr>
						<tr><td><code>brand</code></td><td>string</td><td>max 100 chars</td><td>Manufacturer name (e.g. Federal, Glock, Vortex).</td></tr>
						<tr><td><code>mpn</code></td><td>string</td><td>max 100 chars</td><td>Manufacturer Part Number. Use the original MPN without prefixes.</td></tr>
						<tr><td><code>image_url</code></td><td>URL</td><td>https://</td><td>Product image. JPEG, PNG, or WebP.</td></tr>
					</tbody>
				</table>
			</div>
		</section>

		<section id="categories" class="gd-fs__section">
			<h2>Category-Specific Fields</h2>
			<p>Each category has its own set of fields that enable buyers to filter and search precisely. In <strong>XML and JSON</strong>, these are nested under a category-named block (e.g. <code>&lt;ammo&gt;&lt;caliber&gt;...&lt;/caliber&gt;&lt;/ammo&gt;</code>). In <strong>CSV</strong>, they're flat columns with dotted names (e.g. <code>ammo.caliber</code>).</p>

			<div class="gd-fs__catCard">
				<h3>Ammo <span class="gd-fs__catTag">category=ammo</span></h3>
				<div class="gd-fs__tableWrap">
					<table class="gd-fs__table">
						<thead><tr><th>Field</th><th>Required</th><th>Description</th><th>Examples</th></tr></thead>
						<tbody>
							<tr><td><code>ammo.caliber</code></td><td class="gd-fs__req">Required</td><td>Cartridge / shotshell gauge.</td><td><code>9mm Luger</code>, <code>.223 Rem</code>, <code>12 gauge</code></td></tr>
							<tr><td><code>ammo.rounds</code></td><td class="gd-fs__req">Required</td><td>Number of rounds in this offer (positive integer).</td><td><code>50</code>, <code>1000</code></td></tr>
						</tbody>
					</table>
				</div>
			</div>

			<div class="gd-fs__catCard">
				<h3>Firearm <span class="gd-fs__catTag">category=firearm</span></h3>
				<div class="gd-fs__tableWrap">
					<table class="gd-fs__table">
						<thead><tr><th>Field</th><th>Required</th><th>Description</th><th>Examples</th></tr></thead>
						<tbody>
							<tr><td><code>firearm.model</code></td><td class="gd-fs__rec">Recommended</td><td>General model name. Not the full SKU.</td><td><code>Glock 19</code>, <code>AR-15</code>, <code>Sig P320</code></td></tr>
							<tr><td><code>firearm.type</code></td><td class="gd-fs__opt">Optional</td><td>Firearm type.</td><td><code>handgun</code>, <code>rifle</code>, <code>shotgun</code>, <code>revolver</code></td></tr>
							<tr><td><code>firearm.action</code></td><td class="gd-fs__opt">Optional</td><td>Action type.</td><td><code>semi-auto</code>, <code>bolt-action</code>, <code>single-action</code></td></tr>
							<tr><td><code>firearm.caliber</code></td><td class="gd-fs__opt">Optional</td><td>Firearm caliber.</td><td><code>9mm Luger</code>, <code>.308 Win</code></td></tr>
						</tbody>
					</table>
				</div>
				<p class="gd-fs__note">All firearm subfields are technically optional, but listings with no <code>firearm.model</code>, <code>firearm.type</code>, or <code>firearm.caliber</code> won't appear in firearm-specific search filters.</p>
			</div>

			<div class="gd-fs__catCard">
				<h3>Part <span class="gd-fs__catTag">category=part</span></h3>
				<div class="gd-fs__tableWrap">
					<table class="gd-fs__table">
						<thead><tr><th>Field</th><th>Required</th><th>Description</th><th>Examples</th></tr></thead>
						<tbody>
							<tr><td><code>part.type</code></td><td class="gd-fs__req">Required</td><td>Precise part category. Not too vague, not too detailed.</td><td><code>1911 magazine</code>, <code>AR-15 lower</code>, <code>AR-10 charging handle</code></td></tr>
						</tbody>
					</table>
				</div>
			</div>

			<div class="gd-fs__catCard">
				<h3>Reloading <span class="gd-fs__catTag">category=reloading</span></h3>
				<div class="gd-fs__tableWrap">
					<table class="gd-fs__table">
						<thead><tr><th>Field</th><th>Required</th><th>Description</th><th>Examples</th></tr></thead>
						<tbody>
							<tr><td><code>reloading.type</code></td><td class="gd-fs__req">Required</td><td>One of: <code>bullet</code>, <code>brass</code>, <code>primer</code>.</td><td><code>bullet</code></td></tr>
							<tr><td><code>reloading.rounds</code></td><td class="gd-fs__req">Required</td><td>Positive integer count.</td><td><code>100</code>, <code>1000</code></td></tr>
							<tr><td><code>reloading.bullet_caliber</code></td><td class="gd-fs__req">Required if type=bullet</td><td>Bullet diameter or caliber.</td><td><code>.355</code>, <code>9mm</code></td></tr>
							<tr><td><code>reloading.brass_cartridge</code></td><td class="gd-fs__req">Required if type=brass</td><td>Brass cartridge name.</td><td><code>9mm Luger</code></td></tr>
							<tr><td><code>reloading.primer_size</code></td><td class="gd-fs__req">Required if type=primer</td><td>Primer size.</td><td><code>small pistol</code>, <code>large rifle</code></td></tr>
						</tbody>
					</table>
				</div>
			</div>

			<div class="gd-fs__catCard">
				<h3>Optic <span class="gd-fs__catTag">category=optic</span></h3>
				<div class="gd-fs__tableWrap">
					<table class="gd-fs__table">
						<thead><tr><th>Field</th><th>Required</th><th>Description</th><th>Examples</th></tr></thead>
						<tbody>
							<tr><td><code>optic.type</code></td><td class="gd-fs__req">Required</td><td>One of: <code>red_dot</code>, <code>holographic</code>, <code>lpvo</code>, <code>rifle_scope</code>, <code>pistol_scope</code>, <code>magnifier</code>, <code>iron_sights</code>, <code>prism</code>.</td><td><code>lpvo</code></td></tr>
							<tr><td><code>optic.magnification</code></td><td class="gd-fs__opt">Optional</td><td>Magnification range.</td><td><code>1x</code>, <code>4-16x</code>, <code>1-6x</code></td></tr>
							<tr><td><code>optic.reticle</code></td><td class="gd-fs__opt">Optional</td><td>Reticle style.</td><td><code>MOA</code>, <code>MIL</code>, <code>BDC</code>, <code>dot</code></td></tr>
							<tr><td><code>optic.objective_mm</code></td><td class="gd-fs__opt">Optional</td><td>Objective lens diameter in mm (integer).</td><td><code>24</code>, <code>50</code></td></tr>
						</tbody>
					</table>
				</div>
			</div>

			<div class="gd-fs__catCard">
				<h3>Knife <span class="gd-fs__catTag">category=knife</span></h3>
				<div class="gd-fs__tableWrap">
					<table class="gd-fs__table">
						<thead><tr><th>Field</th><th>Required</th><th>Description</th><th>Examples</th></tr></thead>
						<tbody>
							<tr><td><code>knife.type</code></td><td class="gd-fs__req">Required</td><td>One of: <code>fixed_blade</code>, <code>folding</code>, <code>automatic</code>, <code>assisted</code>, <code>multitool</code>.</td><td><code>folding</code></td></tr>
							<tr><td><code>knife.blade_length_in</code></td><td class="gd-fs__opt">Optional</td><td>Blade length in inches (decimal).</td><td><code>3.24</code>, <code>4.5</code></td></tr>
							<tr><td><code>knife.blade_steel</code></td><td class="gd-fs__opt">Optional</td><td>Blade steel.</td><td><code>S30V</code>, <code>D2</code>, <code>1095</code></td></tr>
						</tbody>
					</table>
				</div>
			</div>

			<div class="gd-fs__catCard">
				<h3>Accessory and Apparel <span class="gd-fs__catTag">category=accessory or apparel</span></h3>
				<p>No category-specific required fields. Catch-all categories for products that don't fit elsewhere (slings, cases, holsters, t-shirts, hats, etc.).</p>
			</div>
		</section>

		<section id="examples" class="gd-fs__section">
			<h2>Format Examples</h2>
			<p>The same 8 listings (one per category) shown in all three formats. Pick whichever your system can generate most easily.</p>

			<div class="gd-fs__tabs">
				<button type="button" class="gd-fs__tabBtn is-active" data-tab="xml">XML</button>
				<button type="button" class="gd-fs__tabBtn" data-tab="json">JSON</button>
				<button type="button" class="gd-fs__tabBtn" data-tab="csv">CSV</button>
			</div>

TEMPLATE_EOT;
