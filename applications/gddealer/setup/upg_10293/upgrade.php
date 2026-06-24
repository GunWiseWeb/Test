<?php

namespace IPS\gddealer\setup\upg_10293;

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
		/* ---- Create gd_dealer_announce_dismiss table if missing ---- */
		if ( !\IPS\Db::i()->checkForTable( 'gd_dealer_announce_dismiss' ) )
		{
			\IPS\Db::i()->createTable( [
				'name'    => 'gd_dealer_announce_dismiss',
				'columns' => [
					[
						'name'           => 'member_id',
						'type'           => 'INT',
						'length'         => 10,
						'unsigned'       => true,
						'allow_null'     => false,
						'auto_increment' => false,
					],
					[
						'name'           => 'version',
						'type'           => 'VARCHAR',
						'length'         => 12,
						'allow_null'     => false,
						'default'        => '',
						'auto_increment' => false,
					],
					[
						'name'           => 'dismissed_at',
						'type'           => 'INT',
						'length'         => 10,
						'unsigned'       => true,
						'allow_null'     => false,
						'default'        => '0',
						'auto_increment' => false,
					],
				],
				'indexes' => [
					[
						'type'    => 'primary',
						'name'    => 'PRIMARY',
						'columns' => [ 'member_id', 'version' ],
						'length'  => [ null, null ],
					],
				],
			] );
		}

		/* ---- Seed new settings if missing ---- */
		$newSettings = [
			'gddealer_announce_enabled' => '0',
			'gddealer_announce_style'   => 'info',
			'gddealer_announce_body'    => '',
		];
		foreach ( $newSettings as $key => $default )
		{
			try
			{
				$exists = (int) \IPS\Db::i()->select( 'COUNT(*)', 'core_sys_conf_settings',
					[ 'conf_key=?', $key ] )->first();
				if ( !$exists )
				{
					\IPS\Db::i()->insert( 'core_sys_conf_settings', [
						'conf_key'     => $key,
						'conf_value'   => $default,
						'conf_default' => $default,
						'conf_app'     => 'gddealer',
					] );
				}
			}
			catch ( \Throwable ) {}
		}

		/* ---- Seed new lang strings ---- */
		$newStrings = [
			'gddealer_front_unmatched_60day' => 'Unmatched UPCs are reviewed manually and may take up to 60 days to be added to our catalog.',
			'gddealer_settings_announcement' => 'Dealer Announcement',
			'gddealer_announce_enabled'      => 'Show announcement banner',
			'gddealer_announce_style'        => 'Banner style',
			'gddealer_announce_body'         => 'Announcement message',
		];
		foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
		{
			foreach ( $newStrings as $key => $val )
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

		/* ---- Re-seed overview + unmatched templates ---- */
		$overviewBody = <<<'TEMPLATE_EOT'
{{if isset($data['announcement']) && $data['announcement']['show']}}
<div class="ipsMessage ipsMessage--{$data['announcement']['style']} gdDealerAnnounce" data-gd-announce data-version="{$data['announcement']['version']}" data-dismissurl="{$data['announce_dismiss_url']}" data-csrfkey="{$data['csrfKey']}">
	<div class="gdDealerAnnounce__body">{$data['announcement']['body']|raw}</div>
	<button type="button" class="gdDealerAnnounce__close" data-gd-announce-close aria-label="Dismiss">&times;</button>
</div>
{{endif}}
TEMPLATE_EOT;

		try
		{
			$existing = \IPS\Db::i()->select( 'template_content', 'core_theme_templates', [
				'template_app=? AND template_name=? AND template_location=?',
				'gddealer', 'overview', 'front',
			] )->first();

			if ( strpos( $existing, 'data-gd-announce' ) === false )
			{
				\IPS\Db::i()->update( 'core_theme_templates', [
					'template_content' => $overviewBody . "\n" . $existing,
					'template_updated' => time(),
					'template_version' => '1.0.293',
				], [
					'template_app=? AND template_name=? AND template_location=?',
					'gddealer', 'overview', 'front',
				] );
			}
		}
		catch ( \Throwable ) {}

		try
		{
			$unmatchedBody = \IPS\Db::i()->select( 'template_content', 'core_theme_templates', [
				'template_app=? AND template_name=? AND template_location=?',
				'gddealer', 'unmatched', 'front',
			] )->first();

			if ( strpos( $unmatchedBody, 'gddealer_front_unmatched_60day' ) === false )
			{
				$unmatchedBody = str_replace(
					'{lang="gddealer_front_unmatched_intro"}</p>',
					'{lang="gddealer_front_unmatched_intro"}</p>' . "\n\n" . '<div class="ipsMessage ipsMessage--info" style="margin:0 0 16px 0">' . "\n\t" . '{lang="gddealer_front_unmatched_60day"}' . "\n" . '</div>',
					$unmatchedBody
				);
				\IPS\Db::i()->update( 'core_theme_templates', [
					'template_content' => $unmatchedBody,
					'template_updated' => time(),
					'template_version' => '1.0.293',
				], [
					'template_app=? AND template_name=? AND template_location=?',
					'gddealer', 'unmatched', 'front',
				] );
			}
		}
		catch ( \Throwable ) {}

		/* ---- Heal extensions.json (rule #16) ---- */
		try
		{
			$extFile = \IPS\ROOT_PATH . '/applications/gddealer/data/extensions.json';
			if ( file_exists( $extFile ) )
			{
				$ext = json_decode( file_get_contents( $extFile ), true );
				$dirty = false;
				if ( !isset( $ext['core']['EditorLocations']['Announcement'] ) )
				{
					$ext['core']['EditorLocations']['Announcement'] = 'IPS\\gddealer\\extensions\\core\\EditorLocations\\Announcement';
					$dirty = true;
				}
				if ( $dirty )
				{
					file_put_contents( $extFile, json_encode( $ext, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				}
			}
			unset( \IPS\Data\Store::i()->extensions );
		}
		catch ( \Throwable ) {}

		/* ---- Canonical template ensure + cache clear ---- */
		try
		{
			$canonical = \IPS\ROOT_PATH . '/applications/gddealer/sources/Setup/CanonicalTemplates.php';
			if ( file_exists( $canonical ) )
			{
				require_once $canonical;
				\IPS\gddealer\Setup\CanonicalTemplates::ensure();
				\IPS\gddealer\Setup\CanonicalTemplates::clearCaches();
			}
		}
		catch ( \Throwable ) {}

		try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }     catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }             catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }             catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
