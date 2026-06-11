<?php

namespace IPS\gddealer\setup\upg_10227;

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
		/* Re-seed setupWizardStep2 template — production DB may still have
		   the v1.0.150 body with bare {count(...)}, {strtoupper(...)},
		   {number_format(...)}, {(int)...} expressions that cause ParseError
		   in IPS 5.0.18's template compiler. The v1.0.152 overlay files
		   contain the corrected body using pre-computed $values[] scalars. */
		try
		{
			require_once \IPS\ROOT_PATH . '/applications/gddealer/setup/templates_10152_part2.php';
			require_once \IPS\ROOT_PATH . '/applications/gddealer/setup/templates_10152_part3.php';
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10227 setupWizardStep2 reseed failed: ' . $e->getMessage(), 'gddealer_upg_10227' ); }
			catch ( \Throwable ) {}
		}

		/* v1.0.226 lang strings (carried forward — single upg dir rule #77) */
		$strings = [
			'gddealer_clear_failed_logs'          => 'Clear Failed Logs',
			'gddealer_confirm_clear_logs'         => 'Delete all failed import log entries for this dealer?',
			'gddealer_logs_cleared'               => '%d failed log entries deleted.',
			'gddealer_reset_feed'                 => 'Reset Feed',
			'gddealer_confirm_reset_feed'         => 'This will wipe the feed configuration, all listings, all import logs, and all unmatched UPCs for this dealer. This cannot be undone. Continue?',
			'gddealer_feed_reset_done'            => 'Feed configuration and all related data has been reset.',
			'gddealer_unmatched_review'           => 'Review',
			'gddealer_unmatched_actions'          => 'Actions',
			'gddealer_unmatched_review_title'     => 'Review Unmatched UPC',
			'gddealer_unmatched_dealer_data'      => 'Dealer Feed Data (snapshot)',
			'gddealer_unmatched_add_form_title'   => 'Add to Catalog',
			'gddealer_unmatched_category'         => 'Category',
			'gddealer_unmatched_added_to_catalog' => 'Product added to catalog successfully.',
			'gddealer_unmatched_already_exists'    => 'This UPC already exists in the catalog.',
		];

		foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
		{
			foreach ( $strings as $key => $val )
			{
				try
				{
					\IPS\Db::i()->replace( 'core_sys_lang_words', [
						'lang_id'      => (int) $langId,
						'word_app'     => 'gddealer',
						'word_key'     => $key,
						'word_default' => $val,
						'word_js'      => 0,
						'word_export'  => 1,
					] );
				}
				catch ( \Throwable ) {}
			}
		}

		/* Clear compiled templates + all caches */
		try
		{
			foreach ( \IPS\Theme::themes() as $theme )
			{
				try { $theme->deleteCompiledTemplate(); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable ) {}

		try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}

class upgrade extends _upgrade {}
