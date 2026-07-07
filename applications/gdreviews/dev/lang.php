<?php
$lang = [
	'__app_gdreviews'          => 'Gun Rack Product Reviews',
	'menutab__gdreviews'       => 'Product Reviews',
	'menutab__gdreviews_icon'  => 'star',

	'module__front_reviews'    => 'Product Reviews',

	'gdreviews_product'          => 'Product',
	'gdreviews_products'         => 'Products',
	'gdreviews_products_pl_lc'   => 'products',
	'__indefart_gdreviews_product'  => 'a product',
	'__defart_gdreviews_product'    => 'the product',
	'__indefart_gdreviews_products' => 'some products',
	'__defart_gdreviews_products'   => 'the products',

	'gdreviews_review'          => 'Review',
	'gdreviews_reviews'         => 'Reviews',
	'__indefart_gdreviews_review'   => 'a review',
	'__defart_gdreviews_review'     => 'the review',

	'gdreviews_content_gdreviews_product' => 'Product',
	'gdreviews_content_gdreviews_review'  => 'Review',

	'gdreviews_write_review'     => 'Write a review',
	'gdreviews_no_reviews'       => 'No reviews yet — be the first to write one.',
	'gdreviews_review_placeholder' => 'Product reviews are not available yet. Stage 2 of the rollout ships the submission form.',

	/* v1.0.1 — submission / edit / delete form labels */
	'gdreviews_rating'             => 'Your rating',
	'gdreviews_field_title'        => 'Title (optional)',
	'gdreviews_field_title_ph'     => 'Sum up your experience in a few words',
	'gdreviews_field_content'      => 'Your review',
	'gdreviews_submit'             => 'Submit review',
	'gdreviews_save'               => 'Save changes',
	'gdreviews_delete'             => 'Delete review',
	'gdreviews_delete_confirm'     => 'Delete your review? This cannot be undone.',
	'gdreviews_your_review'        => 'Your review',
	'gdreviews_login_to_review'    => 'Log in to write a review.',
	'gdreviews_login'              => 'Log in',
	'gdreviews_form_error'         => 'Please pick a rating and enter a review.',
	'gdreviews_agg_fmt'            => 'from %s review(s)',
	'gdreviews_missing_upc'        => 'No product specified.',
	'gdreviews_product_not_found'  => 'Product not found.',
	'gdreviews_save_failed'        => 'Could not save your review. Please try again.',

	/* v1.0.4 — ACP menu + permissions */
	'menutab__gdreviews'                    => 'Product Reviews',
	'menutab__gdreviews_icon'               => 'star',
	'module__admin_manage'                  => 'Reviews',
	'menu__gdreviews_manage_settings'       => 'Settings',
	'menu__gdreviews_manage_reviews'        => 'Reviews',
	'r__reviews_manage'                     => 'Manage product reviews',

	/* v1.0.4 — ACP settings form */
	'gdreviews_reviewer_groups'             => 'Reviewer groups',
	'gdreviews_reviewer_groups_desc'        => 'Which member groups may submit reviews. Leave empty to allow any logged-in member.',
	'gdreviews_approval_mode'               => 'Approval mode',
	'gdreviews_approval_mode_desc'          => 'Whether new reviews appear immediately or wait for admin approval.',
	'gdreviews_approval_immediate'          => 'Show immediately',
	'gdreviews_approval_moderate'           => 'Require approval',
	'gdreviews_require_text'                => 'Require a text body',
	'gdreviews_require_text_desc'           => 'Reject rating-only submissions.',
	'gdreviews_min_length'                  => 'Minimum review length',
	'gdreviews_min_length_desc'             => 'Minimum characters in the review body. 0 disables the check.',
	'gdreviews_guest_view'                  => 'Guests can view reviews',
	'gdreviews_guest_view_desc'             => 'When off, only logged-in members see the review list.',

	/* v1.0.4 — ACP reviews list */
	'gdreviews_acp_reviews_title'           => 'Product Reviews — Management',
	'gdreviews_acp_reviews_col_review_upc'         => 'Product',
	'gdreviews_acp_reviews_col_review_author_name' => 'Author',
	'gdreviews_acp_reviews_col_review_rating'      => 'Rating',
	'gdreviews_acp_reviews_col_review_content'     => 'Content',
	'gdreviews_acp_reviews_col_review_date'        => 'Date',
	'gdreviews_acp_reviews_col_review_approved'    => 'Status',
	'gdreviews_acp_action_approve'          => 'Approve',
	'gdreviews_acp_action_hide'             => 'Hide',
	'gdreviews_acp_action_unhide'           => 'Unhide',
	'gdreviews_acp_action_delete'           => 'Delete',

	/* v1.0.4 — front notices for settings enforcement */
	'gdreviews_form_error_min'              => 'Please pick a rating and enter at least %d characters.',
	'gdreviews_group_restricted'            => 'Reviewing is limited to certain member groups. Your account is not currently eligible to submit a review.',
	'gdreviews_flash_pending'               => 'Thanks — your review was submitted and is pending admin approval. It will appear here once approved.',
	'gdreviews_list_login_required'         => 'Log in to view reviews for this product.',
];
