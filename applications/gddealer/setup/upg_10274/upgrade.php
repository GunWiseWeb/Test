<?php

namespace IPS\gddealer\setup\upg_10274;

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
		$newTemplates = [
			'trialReminder30' => [
				'html'  => '<p>Hi {$params[\'name\']},</p><p>Just a heads-up — your founding trial ends on <strong>{$params[\'expiry_date\']}</strong> (about a month from now).</p><p>After that date you\'ll need a paid plan to keep your listings live on GunRack Deals. Your Founder badge stays with you permanently.</p><p><a href="{$params[\'subscribe_url\']}">Browse plans</a></p><p>Questions? Reply to this email or contact <a href="mailto:{$params[\'contact_email\']}">{$params[\'contact_email\']}</a>.</p>',
				'plain' => 'Hi {$params[\'name\']}, your founding trial ends on {$params[\'expiry_date\']} (about a month from now). After that you\'ll need a paid plan to keep your listings live. Your Founder badge stays permanently. Browse plans: {$params[\'subscribe_url\']} Questions? Contact {$params[\'contact_email\']}',
			],
			'trialReminder7' => [
				'html'  => '<p>Hi {$params[\'name\']},</p><p>Your founding trial ends in <strong>one week</strong> — on <strong>{$params[\'expiry_date\']}</strong>.</p><p>Once it expires, your listings will be hidden until you pick a paid plan. Your Founder badge is permanent and won\'t be affected.</p><p><a href="{$params[\'subscribe_url\']}">Choose a plan now</a></p><p>Need help? Contact <a href="mailto:{$params[\'contact_email\']}">{$params[\'contact_email\']}</a>.</p>',
				'plain' => 'Hi {$params[\'name\']}, your founding trial ends in one week — on {$params[\'expiry_date\']}. Your listings will be hidden until you pick a paid plan. Founder badge is permanent. Choose a plan: {$params[\'subscribe_url\']} Need help? {$params[\'contact_email\']}',
			],
			'trialReminder1' => [
				'html'  => '<p>Hi {$params[\'name\']},</p><p><strong>Your founding trial expires tomorrow</strong> — on <strong>{$params[\'expiry_date\']}</strong>.</p><p>Choose a plan today to keep your listings live without interruption. Your Founder badge is yours to keep regardless.</p><p><a href="{$params[\'subscribe_url\']}">Choose a plan now</a></p><p>Questions? <a href="mailto:{$params[\'contact_email\']}">{$params[\'contact_email\']}</a>.</p>',
				'plain' => 'Hi {$params[\'name\']}, your founding trial expires TOMORROW ({$params[\'expiry_date\']}). Choose a plan today to keep your listings live. Founder badge stays. Choose a plan: {$params[\'subscribe_url\']} Questions? {$params[\'contact_email\']}',
			],
			'foundingTrialEnded' => [
				'html'  => '<p>Hi {$params[\'name\']},</p><p>Your founding trial has ended. Your listings are now hidden and your feed has been paused.</p><p>To bring your listings back, choose a paid plan — your Founder badge is still yours and won\'t be affected.</p><p><a href="{$params[\'subscribe_url\']}">Choose a plan</a></p><p>Need help? Contact <a href="mailto:{$params[\'contact_email\']}">{$params[\'contact_email\']}</a>.</p>',
				'plain' => 'Hi {$params[\'name\']}, your founding trial has ended. Your listings are now hidden and your feed is paused. Choose a paid plan to bring them back — your Founder badge is still yours. Choose a plan: {$params[\'subscribe_url\']} Need help? {$params[\'contact_email\']}',
			],
		];

		foreach ( $newTemplates as $name => $content )
		{
			try
			{
				$exists = (int) \IPS\Db::i()->select( 'COUNT(*)', 'core_email_templates',
					[ 'template_app=? AND template_name=?', 'gddealer', $name ]
				)->first();

				if ( $exists > 0 ) { continue; }

				\IPS\Db::i()->insert( 'core_email_templates', [
					'template_app'               => 'gddealer',
					'template_name'              => $name,
					'template_data'              => '',
					'template_content_html'      => $content['html'],
					'template_content_plaintext' => $content['plain'],
					'template_key'               => md5( 'gddealer;' . $name ),
					'template_parent'            => 0,
					'template_edited'            => 0,
					'template_pinned'            => 0,
				] );
			}
			catch ( \Throwable )
			{
				try
				{
					\IPS\Db::i()->insert( 'core_email_templates', [
						'template_app'               => 'gddealer',
						'template_name'              => $name,
						'template_content_html'      => $content['html'],
						'template_content_plaintext' => $content['plain'],
					] );
				}
				catch ( \Throwable ) {}
			}
		}

		require_once \IPS\ROOT_PATH . '/applications/gddealer/setup/templates_10087.php';

		try { \IPS\Theme::deleteCompiledTemplate('gddealer'); } catch ( \Throwable ) {}
		try { \IPS\Theme::deleteCompiledCss(); } catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}

class upgrade extends _upgrade {}
