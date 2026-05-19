<style>
#ipsLayout_mainArea { max-width: 100% !important; }
#ipsLayout_main { max-width: 100% !important; }
.ipsLayout_container { max-width: 1446px !important; }
.gdDealerStats { display:flex; flex-wrap:wrap; border-top:1px solid var(--i-border-color,#e8e8e8); margin-top:16px; }
.gdDealerStats > div { flex:1 1 120px; padding:16px 20px; text-align:center; min-height:80px; display:flex; flex-direction:column; justify-content:center; }
.gdDealerStats > div + div { border-left:1px solid var(--i-border-color,#e8e8e8); }
@media (max-width: 768px) {
  .gdDealerStats > div { flex:1 1 45%; min-height:60px; }
  .gdDealerStats > div + div { border-left:none; }
  .gdDealerStats > div:nth-child(odd) { border-right:1px solid var(--i-border-color,#e8e8e8); }
  .gdDealerStats > div:nth-child(n+3) { border-top:1px solid var(--i-border-color,#e8e8e8); }
  .gdProfileSidebar { width:100% !important; flex-shrink:1 !important; }
  .gdProfileButtons { justify-content:center; }
  .gdTableWrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
}
@media (max-width: 480px) {
  .gdDealerStats > div { flex:1 1 100%; border-left:none !important; border-right:none !important; }
  .gdDealerStats > div + div { border-top:1px solid var(--i-border-color,#e8e8e8); }
}
</style>

<div class="gdDealerWrapper" style="width:100%;max-width:1446px;margin:0 auto;padding:0 24px;box-sizing:border-box">

	<header class="ipsPageHeader ipsBox ipsBox--profileHeader ipsPull i-margin-bottom_block" style="width:100%;box-sizing:border-box;border-radius:8px;overflow:hidden;margin-bottom:16px">
		<div class="ipsCoverPhoto ipsCoverPhoto--profile" style="position:relative;overflow:hidden;min-height:180px">
			<div class="ipsCoverPhoto__container" style="width:100%;height:180px;overflow:hidden">
				{{if $dealer['cover_photo_url']}}
					<img src="{$dealer['cover_photo_url']}" class="ipsCoverPhoto__image" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover">
				{{else}}
					<div class="ipsFallbackImage gdDealerCoverFallback" style="background:linear-gradient(135deg,#1e3a8a 0%,#2563eb 100%);width:100%;height:180px"></div>
				{{endif}}
			</div>
		</div>
		<div class="ipsCoverPhotoMeta" style="background:#fff;border-top:none;padding:20px 24px">
			<div style="display:flex;gap:20px;align-items:flex-end;flex-wrap:wrap">
				{{if $dealer['avatar_url']}}
				<div class="ipsCoverPhoto__avatar" id="elProfilePhoto" style="margin-top:-60px">
					<span class="ipsUserPhoto ipsUserPhoto--xlarge">
						<img src="{$dealer['avatar_url']}" alt="" loading="lazy" onerror="this.style.display='none'">
					</span>
				</div>
				{{endif}}
				<div class="ipsCoverPhoto__titles" style="flex:1;min-width:200px">
					<div class="ipsCoverPhoto__title">
						<h1 style="margin:0;font-size:1.6em;font-weight:800">{$dealer['dealer_name']}</h1>
					</div>
					<div class="ipsCoverPhoto__desc" style="margin-top:6px">
						<span style="background:{$dealer['tier_color']};color:#fff;padding:2px 10px;border-radius:20px;font-size:0.8em;font-weight:700">{$dealer['tier_label']}</span>
					</div>
				</div>
				<div class="ipsCoverPhoto__buttons gdProfileButtons" style="display:flex;gap:8px;flex-wrap:wrap">
					<a href="mailto:{$dealer['contact_email']}" class="ipsButton ipsButton--primary">
						<i class="fa-solid fa-envelope" aria-hidden="true"></i>
						<span>Contact Dealer</span>
					</a>
					<a href="{$guidelinesUrl}" class="ipsButton ipsButton--inherit">
						<i class="fa-solid fa-circle-info" aria-hidden="true"></i>
						<span>Review Guidelines</span>
					</a>
				</div>
			</div>
			<div class="gdDealerStats">
				<div>
					<div style="font-size:0.72em;color:#888;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:6px;white-space:nowrap">Overall Rating</div>
					<div style="font-size:1.6em;font-weight:800;color:{$stats['rating_color']};line-height:1">{$stats['avg_overall']}<span style="font-size:0.45em;color:#888;font-weight:400"> /5</span></div>
					<div style="font-size:0.72em;font-weight:600;color:{$stats['rating_color']};margin-top:4px">{$stats['rating_label']}</div>
				</div>
				<div>
					<div style="font-size:0.75em;color:#888;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:6px;white-space:nowrap">Reviews</div>
					<div style="font-size:1.6em;font-weight:800;line-height:1">{$stats['total']}</div>
				</div>
				<div>
					<div style="font-size:0.75em;color:#888;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:6px;white-space:nowrap">Member Since</div>
					<div style="font-size:1.6em;font-weight:800;line-height:1">{{if $dealer['member_since']}}{$dealer['member_since']}{{else}}&mdash;{{endif}}</div>
				</div>
				<div>
					<div style="font-size:0.75em;color:#888;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:6px;white-space:nowrap">Active Listings</div>
					<div style="font-size:1.6em;font-weight:800;color:#16a34a;line-height:1">{$dealer['listing_count']}</div>
				</div>
			</div>
		</div>
	</header>

	{{if !$dealer['is_active']}}
	<div style="background:#f8f9fa;border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;padding:14px 18px;margin-bottom:16px">
		<strong style="color:#374151">This dealer's listings are currently inactive.</strong>
		<p style="margin:4px 0 0;color:#6b7280;font-size:0.9em">Inventory and pricing are not being updated. Existing reviews and ratings are shown below for reference.</p>
	</div>
	{{endif}}

	{{if $customerDispute}}
	<div class="ipsBox i-margin-bottom_block" style="background:#fff8f0;border:1px solid #f59e0b;border-radius:8px;padding:20px;margin-bottom:24px">
		<h2 style="margin:0 0 8px;font-size:1.05em;font-weight:700;color:#92400e">{$dealer['dealer_name']} has contested your review</h2>
		<p style="margin:0 0 12px;font-size:0.9em;color:#78350f">
			The dealer has submitted a contest against the review you left.
			{{if $customerDispute['deadline_formatted']}}You have until <strong>{$customerDispute['deadline_formatted']}</strong> to respond, or the contest will be automatically resolved in the dealer's favor.{{endif}}
		</p>
		{{if $customerDispute['dispute_reason']}}
		<div style="background:#fff;border-left:3px solid #f59e0b;padding:10px 14px;margin-bottom:12px;border-radius:0 4px 4px 0">
			<div style="font-size:0.8em;font-weight:700;color:#92400e;margin-bottom:4px">Dealer's reason</div>
			<div style="margin:0;font-size:0.9em;color:#333">{$customerDispute['dispute_reason']|raw}</div>
		</div>
		{{endif}}
		{{if $customerDispute['dispute_evidence']}}
		<div style="background:#fff;border-left:3px solid #f59e0b;padding:10px 14px;margin-bottom:12px;border-radius:0 4px 4px 0">
			<div style="font-size:0.8em;font-weight:700;color:#92400e;margin-bottom:4px">Dealer's evidence</div>
			<div style="margin:0;font-size:0.9em;color:#333">{$customerDispute['dispute_evidence']|raw}</div>
		</div>
		{{endif}}
		<form method="post" action="{$customerDispute['respond_url']}">
			<input type="hidden" name="csrfKey" value="{$csrfKey}">
			<label style="display:block;font-size:0.85em;font-weight:600;margin-bottom:4px;color:#78350f">Your response</label>
			<div style="margin-bottom:8px">{$customerDispute['response_editor_html']|raw}</div>
			<label style="display:block;font-size:0.85em;font-weight:600;margin-bottom:4px;color:#78350f">Supporting evidence (optional)</label>
			<div style="margin-bottom:8px">{$customerDispute['evidence_editor_html']|raw}</div>
			<button type="submit" class="ipsButton ipsButton--primary">Submit My Response</button>
		</form>
	</div>
	{{endif}}

	<div class="ipsProfile ipsProfile--profile" style="display:flex;gap:24px;flex-wrap:wrap">
		<aside class="ipsProfile__aside gdProfileSidebar" style="width:300px;flex-shrink:0">
			<div class="ipsProfile__sticky-outer">
				<div class="ipsProfile__sticky-inner">
					<div class="ipsWidget" style="background:#fff;border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;margin-bottom:16px">
						<h3 class="ipsWidget__title" style="margin:0;padding:12px 16px;border-bottom:1px solid var(--i-border-color,#f0f0f0);font-size:0.9em;font-weight:700">Rating Breakdown</h3>
						<div class="ipsWidget__content i-padding_2" style="padding:16px">
							<div style="margin-bottom:14px">
								<div style="display:flex;justify-content:space-between;margin-bottom:4px">
									<span style="font-size:0.85em">Pricing Accuracy</span>
									<strong style="color:{$stats['color_pricing']}">{$stats['avg_pricing']}/5</strong>
								</div>
								<div style="background:var(--i-border-color,#e0e0e0);border-radius:4px;height:8px">
									<div style="background:{$stats['color_pricing']};border-radius:4px;height:8px;width:{$stats['pct_pricing']}%;transition:width 0.3s ease"></div>
								</div>
							</div>
							<div style="margin-bottom:14px">
								<div style="display:flex;justify-content:space-between;margin-bottom:4px">
									<span style="font-size:0.85em">Shipping Speed</span>
									<strong style="color:{$stats['color_shipping']}">{$stats['avg_shipping']}/5</strong>
								</div>
								<div style="background:var(--i-border-color,#e0e0e0);border-radius:4px;height:8px">
									<div style="background:{$stats['color_shipping']};border-radius:4px;height:8px;width:{$stats['pct_shipping']}%;transition:width 0.3s ease"></div>
								</div>
							</div>
							<div>
								<div style="display:flex;justify-content:space-between;margin-bottom:4px">
									<span style="font-size:0.85em">Customer Service</span>
									<strong style="color:{$stats['color_service']}">{$stats['avg_service']}/5</strong>
								</div>
								<div style="background:var(--i-border-color,#e0e0e0);border-radius:4px;height:8px">
									<div style="background:{$stats['color_service']};border-radius:4px;height:8px;width:{$stats['pct_service']}%;transition:width 0.3s ease"></div>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
		</aside>

		<div class="ipsProfile__main" style="flex:1 1 0;min-width:0">
			{{if $canRate}}
			<div class="ipsBox i-margin-bottom_block" style="background:#fff;border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;margin-bottom:24px">
				<h3 class="ipsBox__header" style="margin:0;padding:14px 18px;border-bottom:1px solid var(--i-border-color,#f0f0f0);font-size:1em;font-weight:700">Leave a Review</h3>
				<div class="i-padding_2" style="padding:18px">
					<form method="post" action="{$rateUrl}">
						<input type="hidden" name="csrfKey" value="{$csrfKey}">
						<div style="display:flex;gap:20px;margin-bottom:16px;flex-wrap:wrap">
							<div style="flex:1 1 140px">
								<label style="display:block;font-size:0.85em;font-weight:600;margin-bottom:6px">Pricing Accuracy</label>
								<select name="rating_pricing" class="ipsInput ipsInput--select" required>
									<option value="">Rate...</option>
									<option value="5">★★★★★ Excellent</option>
									<option value="4">★★★★☆ Good</option>
									<option value="3">★★★☆☆ Average</option>
									<option value="2">★★☆☆☆ Poor</option>
									<option value="1">★☆☆☆☆ Terrible</option>
								</select>
							</div>
							<div style="flex:1 1 140px">
								<label style="display:block;font-size:0.85em;font-weight:600;margin-bottom:6px">Shipping Speed</label>
								<select name="rating_shipping" class="ipsInput ipsInput--select" required>
									<option value="">Rate...</option>
									<option value="5">★★★★★ Excellent</option>
									<option value="4">★★★★☆ Good</option>
									<option value="3">★★★☆☆ Average</option>
									<option value="2">★★☆☆☆ Poor</option>
									<option value="1">★☆☆☆☆ Terrible</option>
								</select>
							</div>
							<div style="flex:1 1 140px">
								<label style="display:block;font-size:0.85em;font-weight:600;margin-bottom:6px">Customer Service</label>
								<select name="rating_service" class="ipsInput ipsInput--select" required>
									<option value="">Rate...</option>
									<option value="5">★★★★★ Excellent</option>
									<option value="4">★★★★☆ Good</option>
									<option value="3">★★★☆☆ Average</option>
									<option value="2">★★☆☆☆ Poor</option>
									<option value="1">★☆☆☆☆ Terrible</option>
								</select>
							</div>
						</div>
						<div style="margin-bottom:12px">{$reviewBodyEditorHtml|raw}</div>
						<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
							<span style="font-size:0.8em;color:#666">By submitting you agree to our <a href="{$guidelinesUrl}" style="color:#2563eb">review guidelines</a>.</span>
							<button type="submit" class="ipsButton ipsButton--primary">Submit Review</button>
						</div>
					</form>
				</div>
			</div>
			{{elseif $alreadyRated}}
			<div style="background:#f0f7ff;border:1px solid #bfdbfe;border-radius:8px;padding:14px 18px;margin-bottom:24px;color:#1e40af">
				You have already reviewed this dealer. Thank you for your feedback!
			</div>
			{{elseif $loginRequired}}
			<div class="ipsBox i-margin-bottom_block" style="background:#fff;border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;margin-bottom:24px">
				<div class="i-padding_2" style="padding:24px;text-align:center">
					<p style="margin:0 0 12px;color:#666">Sign in to leave a review for this dealer.</p>
					<a href="{$loginUrl}" class="ipsButton ipsButton--primary">Sign In to Review</a>
				</div>
			</div>
			{{endif}}

			<div class="gdReviewList" style="padding:16px 4px 0">
				<h3 style="margin:0 0 14px;padding:0 4px;font-size:1em;font-weight:700;color:#111827">Customer Reviews <span style="font-size:0.8em;font-weight:500;color:#6b7280;margin-left:4px">({expression="number_format($stats['total'])"})</span></h3>
				{{if count($reviews) === 0}}
				<div style="background:#fff;border:0.5px solid #e5e7eb;border-radius:12px;padding:40px 24px;text-align:center;color:#9ca3af">
					<i class="fa-regular fa-star" style="font-size:2em;display:block;margin-bottom:10px;opacity:0.4" aria-hidden="true"></i>
					No reviews yet. Be the first to review this dealer.
				</div>
				{{else}}
					{{foreach $reviews as $r}}
					<div style="background:#fff;border:0.5px solid #e5e7eb;border-radius:12px;padding:20px 22px;margin:0 0 16px;color:#1f2937">

						<div style="display:flex;align-items:center;gap:12px;margin-bottom:14px">
							<div style="width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#c9a24a,#b8862d);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:500;font-size:15px;flex-shrink:0;overflow:hidden">
								{{if $r['reviewer_avatar']}}
									<img src="{$r['reviewer_avatar']}" alt="" style="width:100%;height:100%;object-fit:cover" loading="lazy">
								{{else}}
									{expression="strtoupper(substr($r['reviewer_name'], 0, 1))"}
								{{endif}}
							</div>
							<div style="display:flex;flex-direction:column;min-width:0;flex:1">
								<span style="font-weight:500;font-size:14px;color:#111827;line-height:1.3">{$r['reviewer_name']}</span>
								<span style="font-size:12px;color:#6b7280;margin-top:2px">{$r['created_at_formatted']}</span>
							</div>
							<span style="display:inline-flex;align-items:center;gap:6px;background:{$r['avg_color']}18;border:0.5px solid {$r['avg_color']}60;border-radius:20px;padding:4px 12px;font-size:13px;color:{$r['avg_color']};font-weight:500;white-space:nowrap">
								<span style="font-size:14px">{$r['avg_score']}</span>
								<span style="opacity:0.6;font-weight:400">/ 5</span>
							</span>
						</div>

						<div style="display:flex;gap:20px;margin-bottom:14px;flex-wrap:wrap">
							<div style="display:flex;align-items:center;gap:8px">
								<span style="font-size:12px;color:#6b7280;font-weight:500">Pricing</span>
								<span style="letter-spacing:1px;font-size:13px;line-height:1;color:#f59e0b">{$r['stars_pricing']}</span>
							</div>
							<div style="display:flex;align-items:center;gap:8px">
								<span style="font-size:12px;color:#6b7280;font-weight:500">Shipping</span>
								<span style="letter-spacing:1px;font-size:13px;line-height:1;color:#f59e0b">{$r['stars_shipping']}</span>
							</div>
							<div style="display:flex;align-items:center;gap:8px">
								<span style="font-size:12px;color:#6b7280;font-weight:500">Service</span>
								<span style="letter-spacing:1px;font-size:13px;line-height:1;color:#f59e0b">{$r['stars_service']}</span>
							</div>
						</div>

						{{if $r['review_body']}}
						<div style="font-size:14px;line-height:1.6;color:#374151;margin:4px 0 0">{$r['review_body']|raw}</div>
						{{endif}}

						{{if $r['is_own_review'] and $r['dispute_status'] === 'pending_customer'}}
						<div style="background:#fef2f2;border:0.5px solid #fca5a5;border-radius:8px;padding:10px 14px;font-size:12px;color:#991b1b;margin-top:12px;display:flex;align-items:center;gap:8px">
							<span style="flex-shrink:0;color:#dc2626;font-size:14px;line-height:1">⚠</span>
							<span><strong>Action required:</strong> The dealer has contested this review. You must respond or the dispute will be resolved in their favor.
							{{if $r['dispute_respond_url']}}<a href="{$r['dispute_respond_url']}" style="color:#dc2626;font-weight:500;margin-left:6px">Respond now →</a>{{endif}}</span>
						</div>
						{{elseif $r['dispute_status'] === 'pending_customer' or $r['dispute_status'] === 'pending_admin'}}
						<div style="background:#fffbeb;border:0.5px solid #fcd34d;border-radius:8px;padding:10px 14px;font-size:12px;color:#78350f;margin-top:12px;display:flex;align-items:center;gap:8px">
							<span style="flex-shrink:0;color:#d97706;font-size:14px;line-height:1">⚠</span>
							<span>This review is currently under dispute review.</span>
						</div>
						{{endif}}

						{{if $r['dealer_response']}}
						<div style="margin-top:16px;background:#f9fafb;border-radius:10px;padding:14px 16px;border-left:3px solid #2563eb">
							<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;flex-wrap:wrap;gap:8px">
								<span style="display:inline-flex;align-items:center;gap:6px;font-size:11px;color:#2563eb;font-weight:500;text-transform:uppercase;letter-spacing:0.06em">
									<i class="fa-solid fa-reply" aria-hidden="true"></i> Dealer response{{if $r['dealer_name']}} · {$r['dealer_name']}{{endif}}
								</span>
								<span style="font-size:11px;color:#9ca3af">{$r['response_at']}</span>
							</div>
							<div style="font-size:13px;line-height:1.5;color:#374151;margin:0">{$r['dealer_response']|raw}</div>
						</div>
						{{endif}}

						{{if $r['edit_review_url']}}
						<div style="display:flex;justify-content:flex-end;margin-top:12px">
							<a href="{$r['edit_review_url']}" style="font-size:12px;color:#2563eb;font-weight:500;text-decoration:none;display:inline-flex;align-items:center;gap:4px">
								<i class="fa-solid fa-pen-to-square" aria-hidden="true"></i> Edit your review
							</a>
						</div>
						{{endif}}

					</div>
					{{endforeach}}
				{{endif}}
			</div>
		</div>
	</div>

</div>