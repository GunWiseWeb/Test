<?php
/**
 * @brief  GD Reviews — upgrade 1.0.4.
 *
 * WHAT SHIPS IN 1.0.4 — ACP surface + settings enforcement.
 *
 *   * New admin module `manage` with two controllers:
 *       - settings — ACP settings form
 *       - reviews  — direct review-management table
 *     Registered via data/modules.json + acpmenu.json +
 *     acprestrictions.json (reviews_manage permission).
 *
 *   * Five new settings, seeded here so ACP → Settings loads
 *     without empty rows for existing installs:
 *       - gdreviews_reviewer_groups (comma-separated group IDs;
 *         empty = any logged-in member)
 *       - gdreviews_approval_mode    ("immediate" | "moderate")
 *       - gdreviews_require_text     (bool)
 *       - gdreviews_min_length       (int)
 *       - gdreviews_guest_view       (bool)
 *
 *   * The front submission flow now consults these settings on
 *     every submit / edit (min length / require text / eligible
 *     group / approval mode). Guests see no list when guest_view
 *     is off. Group names in the ACP picker come from
 *     \IPS\Member\Group::groups() — never a `g_name` column
 *     (which does not exist on IPS 5.0.18's core_groups).
 *
 * gd_catalog remains READ-ONLY across every code path. No
 * schema changes — review_approved already ships in the Stage-1
 * schema.
 */

namespace IPS\gdreviews\setup\upg_10004;

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
		/* -----------------------------------------------------
		 * Seed the v1.0.4 settings (idempotent — replace() will
		 * NOT clobber a value an admin has already changed via
		 * the ACP form since the row's `conf_value` is only
		 * inserted if the key is new; per-row try/catch keeps
		 * one bad row from poisoning the loop).
		 * ----------------------------------------------------- */
		$defaults = [
			'gdreviews_reviewer_groups' => '',
			'gdreviews_approval_mode'   => 'immediate',
			'gdreviews_require_text'    => '1',
			'gdreviews_min_length'      => '10',
			'gdreviews_guest_view'      => '1',
		];
		foreach ( $defaults as $key => $val )
		{
			try
			{
				$exists = (int) \IPS\Db::i()->select( 'COUNT(*)', 'core_sys_conf_settings',
					[ 'conf_key=?', $key ] )->first();
			}
			catch ( \Throwable ) { $exists = 0; }

			if ( $exists === 0 )
			{
				try
				{
					\IPS\Db::i()->insert( 'core_sys_conf_settings', [
						'conf_key'          => $key,
						'conf_value'        => $val,
						'conf_default'      => $val,
						'conf_app'          => 'gdreviews',
						'conf_report'       => 'full',
					] );
				}
				catch ( \Throwable ) {}
			}
		}

		/* -----------------------------------------------------
		 * Lang re-seed for the v1.0.4 keys (ACP menu, form
		 * labels, list column headers, front notices). Per
		 * rules #43/#44 — 6-column schema, per-row try/catch.
		 * ----------------------------------------------------- */
		$v104 = [
			'menutab__gdreviews'                    => 'Product Reviews',
			'menutab__gdreviews_icon'               => 'star',
			'module__admin_manage'                  => 'Reviews',
			'menu__gdreviews_manage_settings'       => 'Settings',
			'menu__gdreviews_manage_reviews'        => 'Reviews',
			'r__reviews_manage'                     => 'Manage product reviews',

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

			'gdreviews_acp_reviews_title'                  => 'Product Reviews — Management',
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

			'gdreviews_form_error_min'              => 'Please pick a rating and enter at least %d characters.',
			'gdreviews_group_restricted'            => 'Reviewing is limited to certain member groups. Your account is not currently eligible to submit a review.',
			'gdreviews_flash_pending'               => 'Thanks — your review was submitted and is pending admin approval. It will appear here once approved.',
			'gdreviews_list_login_required'         => 'Log in to view reviews for this product.',
		];

		try
		{
			foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
			{
				foreach ( $v104 as $key => $val )
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

		/* -----------------------------------------------------
		 * Cache purge — force reload of extensions / applications
		 * / modules / settings / FURL so the new admin module +
		 * settings values are picked up on first request.
		 * ----------------------------------------------------- */
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->furl_configuration ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->furl ); }               catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->acpMenu ); }            catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
