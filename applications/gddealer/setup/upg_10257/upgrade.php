<?php

namespace IPS\gddealer\setup\upg_10257;

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
		/* v255 carry-forward: records_capped column */
		if ( !\IPS\Db::i()->checkForColumn( 'gd_dealer_import_log', 'records_capped' ) )
		{
			\IPS\Db::i()->addColumn( 'gd_dealer_import_log', [
				'name'           => 'records_capped',
				'type'           => 'INT',
				'length'         => 10,
				'unsigned'       => true,
				'allow_null'     => false,
				'default'        => 0,
			] );
		}

		try
		{
			\IPS\Db::i()->insert( 'core_notification_defaults', [
				'notification_key' => 'listing_cap_reached',
				'default'          => 'inline,email',
				'disabled'         => '',
			] );
		}
		catch ( \Throwable ) {}

		/* v256 carry-forward: self-heal extensions.json for MemberSync */
		$extFile = \IPS\ROOT_PATH . '/applications/gddealer/data/extensions.json';
		try
		{
			$extJson = file_get_contents( $extFile );
			$ext     = json_decode( $extJson, true );
			if ( !isset( $ext['core']['MemberSync']['Dealer'] ) )
			{
				$ext['core']['MemberSync'] = [
					'Dealer' => 'IPS\\gddealer\\extensions\\core\\MemberSync\\Dealer',
				];
				file_put_contents( $extFile, json_encode( $ext, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			}
		}
		catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); } catch ( \Throwable ) {}

		/* v256 carry-forward: one-time backfill of subscription_tier from groups */
		try
		{
			foreach ( \IPS\Db::i()->select( '*', 'gd_dealer_feed_config' ) as $row )
			{
				try
				{
					$memberId = (int) $row['dealer_id'];
					if ( $memberId <= 0 ) { continue; }

					$member = \IPS\Member::load( $memberId );
					if ( !$member->member_id ) { continue; }

					$groupIds = [ (int) $member->member_group_id ];
					if ( $member->mgroup_others )
					{
						$groupIds = array_merge(
							$groupIds,
							array_filter( array_map( 'intval', explode( ',', (string) $member->mgroup_others ) ) )
						);
					}

					$tierPrecedence = [ 'founding', 'max', 'enterprise', 'pro', 'basic' ];
					$tierGroupSettings = [
						'founding'   => 'gddealer_group_founding',
						'basic'      => 'gddealer_group_basic',
						'pro'        => 'gddealer_group_pro',
						'enterprise' => 'gddealer_group_enterprise',
						'max'        => 'gddealer_group_max',
					];

					$newTier = 'basic';
					foreach ( $tierPrecedence as $t )
					{
						$settingKey = $tierGroupSettings[ $t ] ?? '';
						if ( !$settingKey ) { continue; }
						$gid = (int) \IPS\Settings::i()->$settingKey;
						if ( $gid > 0 && \in_array( $gid, $groupIds, true ) )
						{
							$newTier = $t;
							break;
						}
					}

					if ( !empty( $row['is_founding_member'] ) )
					{
						$newTier = 'founding';
					}

					if ( (string) ( $row['subscription_tier'] ?? '' ) !== $newTier )
					{
						\IPS\Db::i()->update( 'gd_dealer_feed_config', [
							'subscription_tier' => $newTier,
						], [ 'dealer_id=?', $memberId ] );
					}
				}
				catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable ) {}

		/* v255 carry-forward: seed lang strings */
		$newStrings = [
			'gddealer_notif_listing_cap_reached'      => 'Listing cap reached',
			'gddealer_notif_listing_cap_reached_desc'  => 'When your feed exceeds your plan\'s listing cap and some listings are skipped.',
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

		/* v257: no schema change — 'capped' is a new value in the existing
		 * varchar listing_status column. No auto-trim in upgrade — let it
		 * happen on next import or admin tier change. */

		require_once \IPS\ROOT_PATH . '/applications/gddealer/sources/Setup/CanonicalTemplates.php';
		\IPS\gddealer\Setup\CanonicalTemplates::ensure();
		\IPS\gddealer\Setup\CanonicalTemplates::clearCaches();

		try { unset( \IPS\Data\Store::i()->extensions ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}

class upgrade extends _upgrade {}
