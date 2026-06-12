<?php
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }

$masterKey = md5( 'gddealer;front;dealers;dealerProfile' );

$dealerProfileTpl = <<<'TEMPLATE_EOT'
<style>
#ipsLayout_mainArea { max-width: 100% !important; }
#ipsLayout_main { max-width: 100% !important; }
</style>
<div class="gdProfile" style="--gd-dealer-brand:{$data['dealer']['brand_color']}">

	<header class="gdProfile__header">
		<div class="gdProfile__headerTop">
			<div class="gdProfile__identity">
				{{if $data['dealer']['logo_url']}}
				<div class="gdProfile__logo">
					<img src="{$data['dealer']['logo_url']}" alt="" loading="lazy">
				</div>
				{{elseif $data['dealer']['avatar_url']}}
				<div class="gdProfile__avatar">
					<img src="{$data['dealer']['avatar_url']}" alt="" loading="lazy">
				</div>
				{{else}}
				<div class="gdProfile__monogram" style="{expression="'background:' . $data['dealer']['brand_color']"}">
					{expression="substr($data['dealer']['dealer_name'], 0, 1)"}
				</div>
				{{endif}}
				<div class="gdProfile__nameBlock">
					<div class="gdProfile__nameRow">
						<h1 class="gdProfile__name">{$data['dealer']['dealer_name']}</h1>
						{{if $data['dealer']['verified']}}
						<span class="gdProfile__verified"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> FFL Verified</span>
						{{endif}}
						<span class="gdProfile__tierBadge" style="{expression="'background:' . $data['dealer']['tier_color']"}">{$data['dealer']['tier_label']}</span>
					</div>
					{{if $data['dealer']['address_city_state']}}
					<div class="gdProfile__location"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> {$data['dealer']['address_city_state']}</div>
					{{endif}}
				</div>
			</div>
			<div class="gdProfile__headerRight">
				<div class="gdProfile__ratingCompact">
					<span class="gdProfile__ratingScore" style="{expression="'color:' . ($data['stats']['rating_color'] ?? '#1E40AF')"}">{$data['stats']['avg_overall']}</span>
					<span class="gdProfile__ratingStar" style="{expression="'color:' . ($data['stats']['rating_color'] ?? '#1E40AF')"}">&#9733;</span>
					<span class="gdProfile__ratingMeta">{expression="number_format($data['stats']['total'])"} reviews</span>
				</div>
				<div class="gdProfile__statPill"><i class="fa-solid fa-box-open" aria-hidden="true"></i> {$data['dealer']['listing_count']} listings</div>
			</div>
		</div>
		<div class="gdProfile__contactRow">
			{{if $data['dealer']['public_phone']}}
			<a href="tel:{$data['dealer']['public_phone']}" class="gdProfile__contactItem"><i class="fa-solid fa-phone" aria-hidden="true"></i> {$data['dealer']['public_phone']}</a>
			{{endif}}
			{{if $data['dealer']['public_email']}}
			<a href="mailto:{$data['dealer']['public_email']}" class="gdProfile__contactItem"><i class="fa-solid fa-envelope" aria-hidden="true"></i> Email</a>
			{{elseif $data['dealer']['contact_email']}}
			<a href="mailto:{$data['dealer']['contact_email']}" class="gdProfile__contactItem"><i class="fa-solid fa-envelope" aria-hidden="true"></i> Contact</a>
			{{endif}}
			{{if $data['dealer']['website_url']}}
			<a href="{$data['dealer']['website_url']}" target="_blank" rel="noopener" class="gdProfile__contactItem"><i class="fa-solid fa-globe" aria-hidden="true"></i> Website</a>
			{{endif}}
			{{foreach $data['dealer']['socials'] as $social}}
			<a href="{$social['url']}" target="_blank" rel="noopener" class="gdProfile__contactItem gdProfile__contactItem--social" title="{$social['network']}">
				{{if $social['network'] === 'facebook'}}<i class="fa-brands fa-facebook" aria-hidden="true"></i>
				{{elseif $social['network'] === 'instagram'}}<i class="fa-brands fa-instagram" aria-hidden="true"></i>
				{{elseif $social['network'] === 'youtube'}}<i class="fa-brands fa-youtube" aria-hidden="true"></i>
				{{elseif $social['network'] === 'twitter'}}<i class="fa-brands fa-x-twitter" aria-hidden="true"></i>
				{{elseif $social['network'] === 'tiktok'}}<i class="fa-brands fa-tiktok" aria-hidden="true"></i>
				{{endif}}
			</a>
			{{endforeach}}
			<a href="{$data['guidelines_url']}" class="gdProfile__contactItem gdProfile__contactItem--guidelines"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> Guidelines</a>
		</div>
		{{if $data['dealer']['about']}}
		<div class="gdProfile__about">{$data['dealer']['about']}</div>
		{{endif}}
	</header>

	{{if !$data['dealer']['is_active']}}
	<div class="gdProfile__notice gdProfile__notice--inactive">
		<strong>This dealer&rsquo;s listings are currently inactive.</strong>
		<p>Inventory and pricing are not being updated. Existing reviews and ratings are shown below for reference.</p>
	</div>
	{{endif}}

	{{if $data['customer_dispute']}}
	<div class="gdProfile__notice gdProfile__notice--dispute">
		<h2 class="gdProfile__disputeTitle">{$data['dealer']['dealer_name']} has contested your review</h2>
		<p class="gdProfile__disputeIntro">
			The dealer has submitted a contest against the review you left.
			{{if $data['customer_dispute']['deadline_formatted']}}You have until <strong>{$data['customer_dispute']['deadline_formatted']}</strong> to respond, or the contest will be automatically resolved in the dealer&rsquo;s favor.{{endif}}
		</p>
		{{if $data['customer_dispute']['dispute_reason']}}
		<div class="gdProfile__disputeQuote">
			<div class="gdProfile__disputeQuoteLabel">Dealer&rsquo;s reason</div>
			<div>{$data['customer_dispute']['dispute_reason']}</div>
		</div>
		{{endif}}
		{{if $data['customer_dispute']['dispute_evidence']}}
		<div class="gdProfile__disputeQuote">
			<div class="gdProfile__disputeQuoteLabel">Dealer&rsquo;s evidence</div>
			<div>{$data['customer_dispute']['dispute_evidence']}</div>
		</div>
		{{endif}}
		<form method="post" action="{$data['customer_dispute']['respond_url']}">
			<input type="hidden" name="csrfKey" value="{$data['csrf_key']}">
			<div class="gdProfile__disputeField">
				<label class="gdProfile__disputeFieldLabel">Your response</label>
				{$data['customer_dispute']['response_editor_html']}
			</div>
			<div class="gdProfile__disputeField">
				<label class="gdProfile__disputeFieldLabel">Supporting evidence (optional)</label>
				{$data['customer_dispute']['evidence_editor_html']}
			</div>
			<button type="submit" class="ipsButton ipsButton--primary">Submit My Response</button>
		</form>
	</div>
	{{endif}}

	<div class="gdProfile__tabContainer">
		<input type="radio" name="gdProfileTab" id="gdTabDeals" class="gdProfile__tabInput" checked>
		<input type="radio" name="gdProfileTab" id="gdTabCoupons" class="gdProfile__tabInput">
		<input type="radio" name="gdProfileTab" id="gdTabListings" class="gdProfile__tabInput">
		<input type="radio" name="gdProfileTab" id="gdTabReviews" class="gdProfile__tabInput">

		<nav class="gdProfile__tabs">
			<label for="gdTabDeals" class="gdProfile__tab gdProfile__tab--deals">
				<i class="fa-solid fa-tags" aria-hidden="true"></i> <span class="gdProfile__tabLabel">Deals</span> <span class="gdProfile__tabBadge">{expression="count($data['deals'])"}</span>
			</label>
			<label for="gdTabCoupons" class="gdProfile__tab gdProfile__tab--coupons">
				<i class="fa-solid fa-ticket" aria-hidden="true"></i> <span class="gdProfile__tabLabel">Coupons</span> <span class="gdProfile__tabBadge">{expression="count($data['coupons'])"}</span>
			</label>
			<label for="gdTabListings" class="gdProfile__tab gdProfile__tab--listings">
				<i class="fa-solid fa-box-open" aria-hidden="true"></i> <span class="gdProfile__tabLabel">Listings</span> <span class="gdProfile__tabBadge">{expression="count($data['listings'])"}</span>
			</label>
			<label for="gdTabReviews" class="gdProfile__tab gdProfile__tab--reviews">
				<i class="fa-solid fa-star" aria-hidden="true"></i> <span class="gdProfile__tabLabel">Reviews</span> <span class="gdProfile__tabBadge">{expression="number_format($data['stats']['total'])"}</span>
			</label>
		</nav>

		<div class="gdProfile__panel gdProfile__panel--deals">
			{{if count($data['deals']) > 0}}
			<div class="gdProfile__grid gdProfile__grid--deals">
				{{foreach $data['deals'] as $deal}}
				<div class="gdProfile__card gdProfile__dealCard">
					{{if $deal['image_url']}}
					<div class="gdProfile__dealImg">
						<img src="{$deal['image_url']}" alt="" loading="lazy">
					</div>
					{{endif}}
					<div class="gdProfile__dealBody">
						{{if $deal['brand']}}
						<div class="gdProfile__cardBrand">{$deal['brand']}</div>
						{{endif}}
						<div class="gdProfile__dealTitle">{$deal['product_title']}</div>
						<div class="gdProfile__dealPricing">
							{{if $deal['deal_price']}}
							<span class="gdProfile__dealSalePrice">{$deal['deal_price']}</span>
							{{endif}}
							{{if $deal['regular_price']}}
							<span class="gdProfile__dealRegPrice">{$deal['regular_price']}</span>
							{{endif}}
							{{if $deal['pct_off']}}
							<span class="gdProfile__dealPctOff">{$deal['pct_off']}</span>
							{{endif}}
						</div>
						{{if $deal['title']}}
						<div class="gdProfile__dealBlurb">{$deal['title']}</div>
						{{endif}}
						{{if $deal['blurb']}}
						<div class="gdProfile__dealBlurb">{$deal['blurb']}</div>
						{{endif}}
						{{if $deal['end_date']}}
						<div class="gdProfile__dealExpiry">Ends {$deal['end_date']}</div>
						{{endif}}
					</div>
				</div>
				{{endforeach}}
			</div>
			{{else}}
			<div class="gdProfile__empty">
				<i class="fa-solid fa-tags" aria-hidden="true"></i>
				<p>No active deals right now.</p>
			</div>
			{{endif}}
		</div>

		<div class="gdProfile__panel gdProfile__panel--coupons">
			{{if count($data['coupons']) > 0}}
			<div class="gdProfile__grid gdProfile__grid--coupons">
				{{foreach $data['coupons'] as $coupon}}
				<div class="gdProfile__card gdProfile__couponCard">
					<div class="gdProfile__couponBadge">{$coupon['discount_display']}</div>
					<div class="gdProfile__couponBody">
						<code class="gdProfile__couponCode">{$coupon['code']}</code>
						{{if $coupon['description']}}
						<div class="gdProfile__couponDesc">{$coupon['description']}</div>
						{{endif}}
						{{if isset($coupon['discount_type']) && $coupon['discount_type'] === 'free_shipping' && $coupon['min_purchase']}}
						<div class="gdProfile__couponMeta">On orders {$coupon['min_purchase']}+</div>
						{{elseif $coupon['min_purchase']}}
						<div class="gdProfile__couponMeta">Min. purchase: {$coupon['min_purchase']}</div>
						{{endif}}
						{{if $coupon['terms']}}
						<div class="gdProfile__couponTerms">{$coupon['terms']}</div>
						{{endif}}
						{{if $coupon['expiry']}}
						<div class="gdProfile__couponExpiry">Expires {$coupon['expiry']}</div>
						{{endif}}
						<div class="gdProfile__couponNote">Honored at {$data['dealer']['dealer_name']}&rsquo;s store</div>
					</div>
				</div>
				{{endforeach}}
			</div>
			{{else}}
			<div class="gdProfile__empty">
				<i class="fa-solid fa-ticket" aria-hidden="true"></i>
				<p>No coupons right now.</p>
			</div>
			{{endif}}
		</div>

		<div class="gdProfile__panel gdProfile__panel--listings">
			{{if count($data['listings']) > 0}}
			<div class="gdProfile__grid gdProfile__grid--listings">
				{{foreach $data['listings'] as $listing}}
				<div class="gdProfile__card gdProfile__listingCard">
					{{if $listing['image_url']}}
					<div class="gdProfile__listingImg">
						<img src="{$listing['image_url']}" alt="" loading="lazy">
					</div>
					{{endif}}
					<div class="gdProfile__listingBody">
						{{if $listing['brand']}}
						<div class="gdProfile__cardBrand">{$listing['brand']}</div>
						{{endif}}
						<div class="gdProfile__listingTitle">{$listing['title']}</div>
						<div class="gdProfile__listingFooter">
							<span class="gdProfile__listingPrice">{$listing['price_display']}</span>
							<span class="gdProfile__listingStock">In Stock</span>
						</div>
						{{if $listing['product_url']}}
						<a href="{$listing['product_url']}" target="_blank" rel="noopener" class="gdProfile__listingLink">View at store &rarr;</a>
						{{endif}}
					</div>
				</div>
				{{endforeach}}
			</div>
			{{else}}
			<div class="gdProfile__empty">
				<i class="fa-solid fa-box-open" aria-hidden="true"></i>
				<p>This dealer has no active listings yet.</p>
			</div>
			{{endif}}
		</div>

		<div class="gdProfile__panel gdProfile__panel--reviews">
			<div class="gdProfile__reviewsLayout">
				<div class="gdProfile__reviewsSidebar">
					<div class="gdProfile__ratingOverview">
						<div class="gdProfile__ratingBig" style="{expression="'color:' . ($data['stats']['rating_color'] ?? '#1E40AF')"}">{$data['stats']['avg_overall']}</div>
						<div class="gdProfile__ratingBigLabel" style="{expression="'color:' . ($data['stats']['rating_color'] ?? '#1E40AF')"}">{$data['stats']['rating_label']}</div>
						<div class="gdProfile__ratingBigCount">{expression="number_format($data['stats']['total'])"} reviews</div>
					</div>
					<div class="gdProfile__breakdownBars">
						<div class="gdProfile__breakdownRow">
							<span class="gdProfile__breakdownLabel">Pricing</span>
							<div class="gdProfile__breakdownTrack">
								<div class="gdProfile__breakdownFill" style="{expression="'width:' . ($data['stats']['pct_pricing'] ?? 0) . '%;background:' . ($data['stats']['color_pricing'] ?? '#1E40AF')"}"></div>
							</div>
							<span class="gdProfile__breakdownValue" style="{expression="'color:' . ($data['stats']['color_pricing'] ?? '#1E40AF')"}">{$data['stats']['avg_pricing']}</span>
						</div>
						<div class="gdProfile__breakdownRow">
							<span class="gdProfile__breakdownLabel">Shipping</span>
							<div class="gdProfile__breakdownTrack">
								<div class="gdProfile__breakdownFill" style="{expression="'width:' . ($data['stats']['pct_shipping'] ?? 0) . '%;background:' . ($data['stats']['color_shipping'] ?? '#1E40AF')"}"></div>
							</div>
							<span class="gdProfile__breakdownValue" style="{expression="'color:' . ($data['stats']['color_shipping'] ?? '#1E40AF')"}">{$data['stats']['avg_shipping']}</span>
						</div>
						<div class="gdProfile__breakdownRow">
							<span class="gdProfile__breakdownLabel">Service</span>
							<div class="gdProfile__breakdownTrack">
								<div class="gdProfile__breakdownFill" style="{expression="'width:' . ($data['stats']['pct_service'] ?? 0) . '%;background:' . ($data['stats']['color_service'] ?? '#1E40AF')"}"></div>
							</div>
							<span class="gdProfile__breakdownValue" style="{expression="'color:' . ($data['stats']['color_service'] ?? '#1E40AF')"}">{$data['stats']['avg_service']}</span>
						</div>
					</div>
					<div class="gdProfile__starDist">
						{{foreach $data['star_counts'] as $starVal => $starCount}}
						{{if $starVal !== 'all'}}
						<a href="{$data['star_options'][$starVal]}" class="gdProfile__starDistRow{{if $data['star_key'] === $starVal}} gdProfile__starDistRow--active{{endif}}">
							<span class="gdProfile__starDistLabel">{$starVal}&#9733;</span>
							<div class="gdProfile__starDistTrack">
								<div class="gdProfile__starDistFill" style="{expression="'width:' . ($data['stats']['total'] > 0 ? round($starCount / $data['stats']['total'] * 100) : 0) . '%'"}"></div>
							</div>
							<span class="gdProfile__starDistCount">{$starCount}</span>
						</a>
						{{endif}}
						{{endforeach}}
					</div>
				</div>

				<div class="gdProfile__reviewsMain">
					<div class="gdProfile__reviewsToolbar">
						<div class="gdProfile__sortLinks">
							<span class="gdProfile__sortLabel">Sort:</span>
							<a href="{$data['sort_options']['newest']}" class="gdProfile__sortLink{{if $data['sort_key'] === 'newest'}} gdProfile__sortLink--active{{endif}}">Newest</a>
							<a href="{$data['sort_options']['oldest']}" class="gdProfile__sortLink{{if $data['sort_key'] === 'oldest'}} gdProfile__sortLink--active{{endif}}">Oldest</a>
							<a href="{$data['sort_options']['highest']}" class="gdProfile__sortLink{{if $data['sort_key'] === 'highest'}} gdProfile__sortLink--active{{endif}}">Highest</a>
							<a href="{$data['sort_options']['lowest']}" class="gdProfile__sortLink{{if $data['sort_key'] === 'lowest'}} gdProfile__sortLink--active{{endif}}">Lowest</a>
						</div>
						{{if $data['star_key'] !== 'all'}}
						<a href="{$data['clear_filters_url']}" class="gdProfile__clearFilters">Clear filters ({$data['total_in_filter']} shown)</a>
						{{endif}}
					</div>

					{{if $data['can_rate']}}
					<div class="gdProfile__reviewForm">
						<h3 class="gdProfile__reviewFormTitle">Leave a Review</h3>
						<form method="post" action="{$data['rate_url']}">
							<input type="hidden" name="csrfKey" value="{$data['csrf_key']}">
							<div class="gdProfile__ratingSelects">
								<div class="gdProfile__ratingSelect">
									<label>Pricing Accuracy</label>
									<select name="rating_pricing" class="ipsInput ipsInput--select" required>
										<option value="">Rate...</option>
										<option value="5">&#9733;&#9733;&#9733;&#9733;&#9733; Excellent</option>
										<option value="4">&#9733;&#9733;&#9733;&#9733;&#9734; Good</option>
										<option value="3">&#9733;&#9733;&#9733;&#9734;&#9734; Average</option>
										<option value="2">&#9733;&#9733;&#9734;&#9734;&#9734; Poor</option>
										<option value="1">&#9733;&#9734;&#9734;&#9734;&#9734; Terrible</option>
									</select>
								</div>
								<div class="gdProfile__ratingSelect">
									<label>Shipping Speed</label>
									<select name="rating_shipping" class="ipsInput ipsInput--select" required>
										<option value="">Rate...</option>
										<option value="5">&#9733;&#9733;&#9733;&#9733;&#9733; Excellent</option>
										<option value="4">&#9733;&#9733;&#9733;&#9733;&#9734; Good</option>
										<option value="3">&#9733;&#9733;&#9733;&#9734;&#9734; Average</option>
										<option value="2">&#9733;&#9733;&#9734;&#9734;&#9734; Poor</option>
										<option value="1">&#9733;&#9734;&#9734;&#9734;&#9734; Terrible</option>
									</select>
								</div>
								<div class="gdProfile__ratingSelect">
									<label>Customer Service</label>
									<select name="rating_service" class="ipsInput ipsInput--select" required>
										<option value="">Rate...</option>
										<option value="5">&#9733;&#9733;&#9733;&#9733;&#9733; Excellent</option>
										<option value="4">&#9733;&#9733;&#9733;&#9733;&#9734; Good</option>
										<option value="3">&#9733;&#9733;&#9733;&#9734;&#9734; Average</option>
										<option value="2">&#9733;&#9733;&#9734;&#9734;&#9734; Poor</option>
										<option value="1">&#9733;&#9734;&#9734;&#9734;&#9734; Terrible</option>
									</select>
								</div>
							</div>
							<div class="gdProfile__reviewEditor">
								{$data['review_body_editor_html']}
							</div>
							<div class="gdProfile__reviewFormFooter">
								<span class="gdProfile__guidelinesNote">By submitting you agree to our <a href="{$data['guidelines_url']}">review guidelines</a>.</span>
								<button type="submit" class="ipsButton ipsButton--primary">Submit Review</button>
							</div>
						</form>
					</div>
					{{elseif $data['already_rated']}}
					<div class="gdProfile__notice gdProfile__notice--info">
						You have already reviewed this dealer. Thank you for your feedback!
					</div>
					{{elseif $data['login_required']}}
					<div class="gdProfile__reviewForm gdProfile__reviewForm--login">
						<p>Sign in to leave a review for this dealer.</p>
						<a href="{$data['login_url']}" class="ipsButton ipsButton--primary">Sign In to Review</a>
					</div>
					{{endif}}

					{{if count($data['reviews']) === 0}}
					<div class="gdProfile__empty gdProfile__empty--inline">
						<i class="fa-regular fa-star" aria-hidden="true"></i>
						<p>No reviews yet. Be the first to review this dealer.</p>
					</div>
					{{else}}
					<div class="gdProfile__reviewsList">
						{{foreach $data['reviews'] as $r}}
						<div class="gdProfile__reviewCard">
							<div class="gdProfile__reviewHeader">
								<div class="gdProfile__reviewAuthor">
									{{if $r['reviewer_avatar']}}
									<span class="gdProfile__reviewAvatar">
										<img src="{$r['reviewer_avatar']}" alt="" loading="lazy">
									</span>
									{{else}}
									<span class="gdProfile__reviewInitials">{$r['customer_initials']}</span>
									{{endif}}
									<div>
										<div class="gdProfile__reviewName">
											{$r['reviewer_name']}
											{{if $r['verified_buyer']}}<span class="gdProfile__verifiedBuyer" title="Verified Buyer"><i class="fa-solid fa-circle-check" aria-hidden="true"></i></span>{{endif}}
										</div>
										<div class="gdProfile__reviewDate">{$r['created_at_formatted']}</div>
									</div>
								</div>
								<div class="gdProfile__reviewScore" style="{expression="'background:' . $r['avg_color'] . '18;border-color:' . $r['avg_color'] . '40'"}">
									<span style="{expression="'color:' . $r['avg_color']"}">{$r['avg_score']}</span>
									<span class="gdProfile__reviewScoreSep">/ 5</span>
								</div>
							</div>

							<div class="gdProfile__reviewStars">
								<div class="gdProfile__reviewStarGroup">
									<span class="gdProfile__reviewStarLabel">Pricing</span>
									<span class="gdProfile__reviewStarVal">{$r['stars_pricing']}</span>
								</div>
								<div class="gdProfile__reviewStarGroup">
									<span class="gdProfile__reviewStarLabel">Shipping</span>
									<span class="gdProfile__reviewStarVal">{$r['stars_shipping']}</span>
								</div>
								<div class="gdProfile__reviewStarGroup">
									<span class="gdProfile__reviewStarLabel">Service</span>
									<span class="gdProfile__reviewStarVal">{$r['stars_service']}</span>
								</div>
							</div>

							{{if $r['review_body']}}
							<div class="gdProfile__reviewBody">{$r['review_body']}</div>
							{{endif}}

							{{if $r['dispute_status'] === 'pending_customer' || $r['dispute_status'] === 'pending_admin'}}
							<div class="gdProfile__reviewDispute">&#9888; This review is currently under admin review.</div>
							{{endif}}

							{{if $r['is_own_review'] && $r['edit_review_url']}}
							<div class="gdProfile__reviewActions">
								<a href="{$r['edit_review_url']}" class="gdProfile__reviewEditLink"><i class="fa-solid fa-pen" aria-hidden="true"></i> Edit your review</a>
							</div>
							{{endif}}

							{{if $r['dealer_response']}}
							<div class="gdProfile__dealerResponse">
								<div class="gdProfile__dealerResponseLabel">
									<i class="fa-solid fa-reply" aria-hidden="true"></i> Dealer Response
									{{if $r['response_at']}}<span class="gdProfile__dealerResponseDate">{$r['response_at']}</span>{{endif}}
								</div>
								<div class="gdProfile__dealerResponseBody">{$r['dealer_response']}</div>
							</div>
							{{endif}}
						</div>
						{{endforeach}}
					</div>
					{{endif}}
				</div>
			</div>
		</div>
	</div>

</div>
TEMPLATE_EOT;

try
{
	\IPS\Db::i()->replace( 'core_theme_templates', [
		'template_set_id'      => 1,
		'template_app'         => 'gddealer',
		'template_location'    => 'front',
		'template_group'       => 'dealers',
		'template_name'        => 'dealerProfile',
		'template_data'        => '$data',
		'template_content'     => $dealerProfileTpl,
		'template_updated'     => time(),
		'template_version'     => '1.0.231',
		'template_master_key'  => $masterKey,
	] );
}
catch ( \Throwable $e )
{
	try { \IPS\Log::log( 'templates_10231 dealerProfile replace failed: ' . $e->getMessage(), 'gddealer_upg_10231' ); }
	catch ( \Throwable ) {}
}
