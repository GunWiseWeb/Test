<?php

namespace IPS\gddealer\setup\upg_10313;

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
		$prefix = \IPS\Db::i()->prefix;

		/* 1. Add directory_listed to gd_dealer_feed_config (default 1 = listed). */
		try {
			$has = (bool) \IPS\Db::i()->query( "SHOW COLUMNS FROM `{$prefix}gd_dealer_feed_config` LIKE 'directory_listed'" )->num_rows;
			if ( !$has ) {
				\IPS\Db::i()->query( "ALTER TABLE `{$prefix}gd_dealer_feed_config` ADD COLUMN `directory_listed` TINYINT(3) UNSIGNED NOT NULL DEFAULT 1" );
			}
		} catch ( \Throwable $e ) {
			try { \IPS\Log::log( $e, 'gddealer_upgrade' ); } catch ( \Throwable ) {}
		}

		/* 2. Reseed the dealerDirectory template from dev/html (strips tier markup). */
		$path = \IPS\ROOT_PATH . '/applications/gddealer/dev/html/front/dealers/dealerDirectory.phtml';
		if ( is_readable( $path ) )
		{
			$body = file_get_contents( $path );
			if ( $body !== false && $body !== '' )
			{
				$body = preg_replace( '/^<ips:template[^>]*\/>\s*\n?/', '', $body, 1 );

				try {
					\IPS\Db::i()->delete( 'core_theme_templates', [
						'template_app=? AND template_location=? AND template_group=? AND template_name=? AND template_set_id<>?',
						'gddealer', 'front', 'dealers', 'dealerDirectory', 1
					] );
				} catch ( \Throwable ) {}

				try {
					\IPS\Db::i()->replace( 'core_theme_templates', [
						'template_set_id'   => 1,
						'template_app'      => 'gddealer',
						'template_location' => 'front',
						'template_group'    => 'dealers',
						'template_name'     => 'dealerDirectory',
						'template_data'     => '$dealers, $total, $page, $perPage, $pagination, $sort, $search, $stateParam, $minRating, $states, $loggedIn, $joinUrl, $directoryUrl',
						'template_content'  => $body,
						'template_updated'  => time(),
						'template_version'  => '1.0.313',
					] );
				} catch ( \Throwable $e ) {
					try { \IPS\Log::log( 'upg_10313 reseed dealerDirectory: ' . $e->getMessage(), 'gddealer_upgrade' ); } catch ( \Throwable ) {}
				}
			}
		}

		/* 3. Inject the directory_listed checkbox into the live dashboardCustomize
		   template, right after the address_public block. Idempotent — REPLACE() is
		   a no-op when the new HTML is already present. */
		$anchor = '<div class="gdField__hint" style="margin-top:2px;margin-left:24px">When off, customers see only city &amp; state.</div>';
		$inject = '<div class="gdField__hint" style="margin-top:2px;margin-left:24px">When off, customers see only city &amp; state.</div>' . "\n" .
			'        <label class="gdCheckbox" style="margin-top:14px">' . "\n" .
			'            <input type="checkbox" name="directory_listed" value="1" {expression="$data[\'profile\'][\'directory_listed\'] ? \'checked\' : \'\'"}>' . "\n" .
			'            <span>List my dealership in the public directory</span>' . "\n" .
			'        </label>' . "\n" .
			'        <div class="gdField__hint" style="margin-top:2px;margin-left:24px">When off, your dealership is hidden from /dealers (but still visible by direct link).</div>';
		try {
			\IPS\Db::i()->preparedQuery(
				'UPDATE `' . $prefix . 'core_theme_templates`
				 SET template_content = REPLACE(template_content, ?, ?), template_updated = ?
				 WHERE template_app = ? AND template_location = ? AND template_group = ? AND template_name = ?
				   AND template_content LIKE ?
				   AND template_content NOT LIKE ?',
				[
					$anchor, $inject, time(),
					'gddealer', 'front', 'dealers', 'dashboardCustomize',
					'%' . $anchor . '%',
					'%name="directory_listed"%',
				]
			);
		} catch ( \Throwable $e ) {
			try { \IPS\Log::log( 'upg_10313 inject directory_listed checkbox: ' . $e->getMessage(), 'gddealer_upgrade' ); } catch ( \Throwable ) {}
		}

		/* 4. Seed the lang string per language. */
		foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
		{
			try {
				\IPS\Db::i()->replace( 'core_sys_lang_words', [
					'lang_id'      => (int) $langId,
					'word_app'     => 'gddealer',
					'word_key'     => 'gddealer_directory_listed',
					'word_default' => 'List my dealership in the public directory',
					'word_js'      => 0,
					'word_export'  => 1,
				] );
			} catch ( \Throwable ) {}
		}

		require_once \IPS\ROOT_PATH . '/applications/gddealer/sources/Setup/CanonicalTemplates.php';
		\IPS\gddealer\Setup\CanonicalTemplates::purgeCanonicalTemplates();

		try { \IPS\Data\Store::i()->clearAll(); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
