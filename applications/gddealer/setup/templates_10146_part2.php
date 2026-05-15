<?php
if ( !defined( '\\IPS\\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }

/**
 * v1.0.146 PART 2 of 3 - Schema page format examples (XML, JSON, CSV).
 *
 * Appends the three tabbed code-block panels to $feedSchemaTpl.
 * No DB writes - that happens in part 3.
 *
 * Note: this expects $feedSchemaTpl to already be defined by part 1.
 */

if ( !isset( $feedSchemaTpl ) )
{
    throw new \RuntimeException( 'templates_10146_part2.php loaded before part1' );
}

$feedSchemaTpl .= <<<'TEMPLATE_EOT'
			<div class="gd-fs__tabPanel is-active" data-panel="xml">
<pre class="gd-fs__code">&lt;?xml version="1.0" encoding="UTF-8"?&gt;
&lt;gunrack_feed xmlns="https://gunrack.deals/schema/feed/v1.1" version="1.1"&gt;
  &lt;listings&gt;
    &lt;listing&gt;
      &lt;upc&gt;764503913051&lt;/upc&gt;
      &lt;sku&gt;GLK-19-G5-MOS&lt;/sku&gt;
      &lt;name&gt;Glock 19 Gen5 MOS 9mm 4.02"&lt;/name&gt;
      &lt;brand&gt;Glock&lt;/brand&gt;
      &lt;category&gt;firearm&lt;/category&gt;
      &lt;price&gt;549.99&lt;/price&gt;
      &lt;map_price&gt;619.99&lt;/map_price&gt;
      &lt;condition&gt;new&lt;/condition&gt;
      &lt;url&gt;https://example-dealer.com/p/glock-19-gen5-mos&lt;/url&gt;
      &lt;shipping_info&gt;$15.00 flat&lt;/shipping_info&gt;
      &lt;in_stock&gt;1&lt;/in_stock&gt;
      &lt;stock_qty&gt;3&lt;/stock_qty&gt;
      &lt;firearm&gt;
        &lt;model&gt;Glock 19&lt;/model&gt;
        &lt;type&gt;handgun&lt;/type&gt;
        &lt;action&gt;semi-auto&lt;/action&gt;
        &lt;caliber&gt;9mm Luger&lt;/caliber&gt;
      &lt;/firearm&gt;
    &lt;/listing&gt;
    &lt;listing&gt;
      &lt;upc&gt;076683081124&lt;/upc&gt;
      &lt;name&gt;Federal Champion 9mm 115gr FMJ 50rd&lt;/name&gt;
      &lt;brand&gt;Federal&lt;/brand&gt;
      &lt;category&gt;ammo&lt;/category&gt;
      &lt;price&gt;22.99&lt;/price&gt;
      &lt;condition&gt;new&lt;/condition&gt;
      &lt;url&gt;https://example-dealer.com/p/federal-9mm-50ct&lt;/url&gt;
      &lt;shipping_info&gt;Free shipping&lt;/shipping_info&gt;
      &lt;in_stock&gt;1&lt;/in_stock&gt;
      &lt;stock_qty&gt;120&lt;/stock_qty&gt;
      &lt;ammo&gt;
        &lt;caliber&gt;9mm Luger&lt;/caliber&gt;
        &lt;rounds&gt;50&lt;/rounds&gt;
      &lt;/ammo&gt;
    &lt;/listing&gt;
    &lt;listing&gt;
      &lt;upc&gt;610563272730&lt;/upc&gt;
      &lt;name&gt;Vortex Viper PST Gen II 1-6x24 SFP&lt;/name&gt;
      &lt;brand&gt;Vortex&lt;/brand&gt;
      &lt;category&gt;optic&lt;/category&gt;
      &lt;price&gt;449.00&lt;/price&gt;
      &lt;condition&gt;new&lt;/condition&gt;
      &lt;url&gt;https://example-dealer.com/p/vortex-viper-pst&lt;/url&gt;
      &lt;shipping_info&gt;$9.95 flat&lt;/shipping_info&gt;
      &lt;in_stock&gt;1&lt;/in_stock&gt;
      &lt;optic&gt;
        &lt;type&gt;lpvo&lt;/type&gt;
        &lt;magnification&gt;1-6x&lt;/magnification&gt;
        &lt;reticle&gt;MOA&lt;/reticle&gt;
        &lt;objective_mm&gt;24&lt;/objective_mm&gt;
      &lt;/optic&gt;
    &lt;/listing&gt;
    &lt;listing&gt;
      &lt;upc&gt;851561006033&lt;/upc&gt;
      &lt;name&gt;Used AR-15 Bolt Carrier Group, M16 profile&lt;/name&gt;
      &lt;category&gt;part&lt;/category&gt;
      &lt;price&gt;89.99&lt;/price&gt;
      &lt;condition&gt;used&lt;/condition&gt;
      &lt;url&gt;https://example-dealer.com/p/used-ar15-bcg&lt;/url&gt;
      &lt;shipping_info&gt;$7.50 flat&lt;/shipping_info&gt;
      &lt;in_stock&gt;1&lt;/in_stock&gt;
      &lt;part&gt;
        &lt;type&gt;AR-15 bolt carrier group&lt;/type&gt;
      &lt;/part&gt;
    &lt;/listing&gt;
    &lt;listing&gt;
      &lt;upc&gt;699618782301&lt;/upc&gt;
      &lt;name&gt;Magpul PMAG 30 AR/M4 GEN M3&lt;/name&gt;
      &lt;brand&gt;Magpul&lt;/brand&gt;
      &lt;category&gt;accessory&lt;/category&gt;
      &lt;price&gt;14.99&lt;/price&gt;
      &lt;condition&gt;new&lt;/condition&gt;
      &lt;url&gt;https://example-dealer.com/p/magpul-pmag-30&lt;/url&gt;
      &lt;shipping_info&gt;Free shipping&lt;/shipping_info&gt;
      &lt;in_stock&gt;0&lt;/in_stock&gt;
    &lt;/listing&gt;
    &lt;listing&gt;
      &lt;upc&gt;662410500358&lt;/upc&gt;
      &lt;name&gt;Hornady XTP 9mm 115gr Bullets, 100ct&lt;/name&gt;
      &lt;brand&gt;Hornady&lt;/brand&gt;
      &lt;category&gt;reloading&lt;/category&gt;
      &lt;price&gt;54.99&lt;/price&gt;
      &lt;condition&gt;new&lt;/condition&gt;
      &lt;url&gt;https://example-dealer.com/p/hornady-xtp-9mm&lt;/url&gt;
      &lt;shipping_info&gt;$12.00 flat&lt;/shipping_info&gt;
      &lt;in_stock&gt;1&lt;/in_stock&gt;
      &lt;reloading&gt;
        &lt;type&gt;bullet&lt;/type&gt;
        &lt;rounds&gt;100&lt;/rounds&gt;
        &lt;bullet_caliber&gt;.355&lt;/bullet_caliber&gt;
      &lt;/reloading&gt;
    &lt;/listing&gt;
    &lt;listing&gt;
      &lt;upc&gt;610953154295&lt;/upc&gt;
      &lt;name&gt;Benchmade Bugout 535&lt;/name&gt;
      &lt;brand&gt;Benchmade&lt;/brand&gt;
      &lt;category&gt;knife&lt;/category&gt;
      &lt;price&gt;165.00&lt;/price&gt;
      &lt;condition&gt;new&lt;/condition&gt;
      &lt;url&gt;https://example-dealer.com/p/benchmade-535&lt;/url&gt;
      &lt;shipping_info&gt;Free shipping&lt;/shipping_info&gt;
      &lt;in_stock&gt;1&lt;/in_stock&gt;
      &lt;knife&gt;
        &lt;type&gt;folding&lt;/type&gt;
        &lt;blade_length_in&gt;3.24&lt;/blade_length_in&gt;
        &lt;blade_steel&gt;S30V&lt;/blade_steel&gt;
      &lt;/knife&gt;
    &lt;/listing&gt;
    &lt;listing&gt;
      &lt;upc&gt;889912345678&lt;/upc&gt;
      &lt;name&gt;Vortex Logo T-Shirt, Black, XL&lt;/name&gt;
      &lt;brand&gt;Vortex&lt;/brand&gt;
      &lt;category&gt;apparel&lt;/category&gt;
      &lt;price&gt;24.99&lt;/price&gt;
      &lt;condition&gt;new&lt;/condition&gt;
      &lt;url&gt;https://example-dealer.com/p/vortex-tshirt&lt;/url&gt;
      &lt;shipping_info&gt;$5.99 flat&lt;/shipping_info&gt;
      &lt;in_stock&gt;1&lt;/in_stock&gt;
    &lt;/listing&gt;
  &lt;/listings&gt;
&lt;/gunrack_feed&gt;</pre>
			</div>

			<div class="gd-fs__tabPanel" data-panel="json">
<pre class="gd-fs__code">{
  "listings": [
    {
      "upc": "764503913051",
      "sku": "GLK-19-G5-MOS",
      "name": "Glock 19 Gen5 MOS 9mm 4.02\"",
      "brand": "Glock",
      "category": "firearm",
      "price": 549.99,
      "map_price": 619.99,
      "condition": "new",
      "url": "https://example-dealer.com/p/glock-19-gen5-mos",
      "shipping_info": "$15.00 flat",
      "in_stock": true,
      "stock_qty": 3,
      "firearm": {
        "model": "Glock 19",
        "type": "handgun",
        "action": "semi-auto",
        "caliber": "9mm Luger"
      }
    },
    {
      "upc": "076683081124",
      "name": "Federal Champion 9mm 115gr FMJ 50rd",
      "brand": "Federal",
      "category": "ammo",
      "price": 22.99,
      "condition": "new",
      "url": "https://example-dealer.com/p/federal-9mm-50ct",
      "shipping_info": "Free shipping",
      "in_stock": true,
      "stock_qty": 120,
      "ammo": { "caliber": "9mm Luger", "rounds": 50 }
    },
    {
      "upc": "610563272730",
      "name": "Vortex Viper PST Gen II 1-6x24 SFP",
      "brand": "Vortex",
      "category": "optic",
      "price": 449.00,
      "condition": "new",
      "url": "https://example-dealer.com/p/vortex-viper-pst",
      "shipping_info": "$9.95 flat",
      "in_stock": true,
      "optic": {
        "type": "lpvo",
        "magnification": "1-6x",
        "reticle": "MOA",
        "objective_mm": 24
      }
    },
    {
      "upc": "851561006033",
      "name": "Used AR-15 Bolt Carrier Group, M16 profile",
      "category": "part",
      "price": 89.99,
      "condition": "used",
      "url": "https://example-dealer.com/p/used-ar15-bcg",
      "shipping_info": "$7.50 flat",
      "in_stock": true,
      "part": { "type": "AR-15 bolt carrier group" }
    },
    {
      "upc": "699618782301",
      "name": "Magpul PMAG 30 AR/M4 GEN M3",
      "brand": "Magpul",
      "category": "accessory",
      "price": 14.99,
      "condition": "new",
      "url": "https://example-dealer.com/p/magpul-pmag-30",
      "shipping_info": "Free shipping",
      "in_stock": false
    },
    {
      "upc": "662410500358",
      "name": "Hornady XTP 9mm 115gr Bullets, 100ct",
      "brand": "Hornady",
      "category": "reloading",
      "price": 54.99,
      "condition": "new",
      "url": "https://example-dealer.com/p/hornady-xtp-9mm",
      "shipping_info": "$12.00 flat",
      "in_stock": true,
      "reloading": { "type": "bullet", "rounds": 100, "bullet_caliber": ".355" }
    },
    {
      "upc": "610953154295",
      "name": "Benchmade Bugout 535",
      "brand": "Benchmade",
      "category": "knife",
      "price": 165.00,
      "condition": "new",
      "url": "https://example-dealer.com/p/benchmade-535",
      "shipping_info": "Free shipping",
      "in_stock": true,
      "knife": { "type": "folding", "blade_length_in": 3.24, "blade_steel": "S30V" }
    },
    {
      "upc": "889912345678",
      "name": "Vortex Logo T-Shirt, Black, XL",
      "brand": "Vortex",
      "category": "apparel",
      "price": 24.99,
      "condition": "new",
      "url": "https://example-dealer.com/p/vortex-tshirt",
      "shipping_info": "$5.99 flat",
      "in_stock": true
    }
  ]
}</pre>
			</div>

			<div class="gd-fs__tabPanel" data-panel="csv">
<pre class="gd-fs__code">upc,sku,name,brand,category,price,map_price,condition,url,shipping_info,in_stock,stock_qty,ammo.caliber,ammo.rounds,firearm.model,firearm.type,firearm.action,firearm.caliber,part.type,reloading.type,reloading.rounds,reloading.bullet_caliber,optic.type,optic.magnification,optic.reticle,optic.objective_mm,knife.type,knife.blade_length_in,knife.blade_steel
764503913051,GLK-19-G5-MOS,"Glock 19 Gen5 MOS 9mm",Glock,firearm,549.99,619.99,new,https://example-dealer.com/p/glock-19-gen5-mos,$15.00 flat,1,3,,,Glock 19,handgun,semi-auto,9mm Luger,,,,,,,,,,
076683081124,,"Federal Champion 9mm 115gr FMJ 50rd",Federal,ammo,22.99,,new,https://example-dealer.com/p/federal-9mm-50ct,Free shipping,1,120,9mm Luger,50,,,,,,,,,,,,,,
610563272730,,"Vortex Viper PST Gen II 1-6x24",Vortex,optic,449.00,,new,https://example-dealer.com/p/vortex-viper-pst,$9.95 flat,1,,,,,,,,,,,lpvo,1-6x,MOA,24,,,
851561006033,,"Used AR-15 Bolt Carrier Group",,part,89.99,,used,https://example-dealer.com/p/used-ar15-bcg,$7.50 flat,1,,,,,,,,AR-15 bolt carrier group,,,,,,,,,,
699618782301,,"Magpul PMAG 30 AR/M4 GEN M3",Magpul,accessory,14.99,,new,https://example-dealer.com/p/magpul-pmag-30,Free shipping,0,,,,,,,,,,,,,,,,,,
662410500358,,"Hornady XTP 9mm 115gr Bullets, 100ct",Hornady,reloading,54.99,,new,https://example-dealer.com/p/hornady-xtp-9mm,$12.00 flat,1,,,,,,,,,bullet,100,.355,,,,,,,
610953154295,,"Benchmade Bugout 535",Benchmade,knife,165.00,,new,https://example-dealer.com/p/benchmade-535,Free shipping,1,,,,,,,,,,,,,,,,,folding,3.24,S30V
889912345678,,"Vortex Logo T-Shirt, Black, XL",Vortex,apparel,24.99,,new,https://example-dealer.com/p/vortex-tshirt,$5.99 flat,1,,,,,,,,,,,,,,,,,</pre>
			</div>
		</section>

TEMPLATE_EOT;
