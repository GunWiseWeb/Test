<?php
/**
 * @brief  GD Reviews — upgrade 1.0.1
 *
 * WHAT SHIPS IN 1.0.1 — Stage 2 of 4: submission + edit + delete UI.
 *
 *   * Standalone review page at /product-reviews/product/{upc}:
 *       - Product header (title + image) pulled READ-ONLY from
 *         gd_catalog via SELECT by UPC. Never written.
 *       - Approved reviews listed newest first.
 *       - Logged-out visitors: "Log in to review" CTA.
 *       - Logged-in members with no existing review for the UPC:
 *         a 1-5 star + title + content form. Submit → row inserted,
 *         aggregate recomputed, review appears immediately.
 *       - Logged-in members who already reviewed: their review shown
 *         with Edit + Delete instead of a duplicate form.
 *   * Product::recomputeAggregate() static helper — recounts and
 *     re-averages review_rating on approved+visible rows and writes
 *     the aggregate onto the gdreviews_products shadow row. Called
 *     after every create / edit / delete.
 *   * FURL page entry `reviews_product` (friendly `product/{@upc}`)
 *     added under the existing `product-reviews` topLevel.
 *   * Lang keys for the form / list / aggregate re-seeded per rules
 *     #43 / #44 (6-column schema, per-row try/catch).
 *
 * HARD SAFETY — every gd_catalog access remains a SELECT; nothing
 * in this upgrade or in the Stage 2 controller writes to it. The
 * gddealer and gdcompliance apps are not touched.
 *
 * Cache + FURL datastore clear at the end so IPS picks up the
 * new route and controller.
 */

namespace IPS\gdreviews\setup\upg_10001;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		/* Lang re-seed for existing installs (fresh installs get
		   lang.xml). Per rules #43/#44. */
		$v101 = [
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
		];

		try
		{
			foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
			{
				foreach ( $v101 as $key => $val )
				{
					try
					{
						\IPS\Db::i()->replace( 'core_sys_lang_words', [
							'lang_id'      => (int) $langId,
							'word_app'     => 'gdreviews',
							'word_key'     => $key,
							'word_default' => $val,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
					catch ( \Throwable ) {}
				}
			}
		}
		catch ( \Throwable ) {}

		/* FURL datastore MUST clear so IPS re-parses furl.json and
		   picks up the new `reviews_product` page. */
		try { unset( \IPS\Data\Store::i()->furl_configuration ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->furl ); }               catch ( \Throwable ) {}

		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
