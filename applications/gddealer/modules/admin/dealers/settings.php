<?php
/**
 * @brief       GD Dealer Manager — ACP Settings
 * @package     IPS Community Suite
 * @subpackage  GD Dealer Manager
 * @since       15 Apr 2026
 */

namespace IPS\gddealer\modules\admin\dealers;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _settings extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'dealer_manage' );
		parent::execute();
	}

	protected function manage()
	{
		$form = new \IPS\Helpers\Form;

		$form->addHeader( 'gddealer_settings_member_groups' );

		$form->add( new \IPS\Helpers\Form\Number( 'gddealer_group_founding',
			(int) \IPS\Settings::i()->gddealer_group_founding, FALSE ) );
		$form->add( new \IPS\Helpers\Form\Number( 'gddealer_group_basic',
			(int) \IPS\Settings::i()->gddealer_group_basic, FALSE ) );
		$form->add( new \IPS\Helpers\Form\Number( 'gddealer_group_pro',
			(int) \IPS\Settings::i()->gddealer_group_pro, FALSE ) );
		$form->add( new \IPS\Helpers\Form\Number( 'gddealer_group_enterprise',
			(int) \IPS\Settings::i()->gddealer_group_enterprise, FALSE ) );
		$form->add( new \IPS\Helpers\Form\Number( 'gddealer_group_max',
			(int) \IPS\Settings::i()->gddealer_group_max, FALSE ) );

		$form->addHeader( 'gddealer_settings_announcement' );

		$form->add( new \IPS\Helpers\Form\YesNo( 'gddealer_announce_enabled',
			(bool) ( \IPS\Settings::i()->gddealer_announce_enabled ?? 0 ), FALSE ) );

		$form->add( new \IPS\Helpers\Form\Select( 'gddealer_announce_style',
			(string) ( \IPS\Settings::i()->gddealer_announce_style ?: 'info' ), FALSE, [
				'options' => [ 'info' => 'Info (blue)', 'warning' => 'Warning (amber)' ],
			] ) );

		$form->add( new \IPS\Helpers\Form\Editor( 'gddealer_announce_body',
			(string) ( \IPS\Settings::i()->gddealer_announce_body ?? '' ), FALSE, [
				'app' => 'gddealer', 'key' => 'Announcement', 'autoSaveKey' => 'gddealer-announce',
			] ) );

		$form->addHeader( 'gddealer_settings_general' );

		$form->add( new \IPS\Helpers\Form\Select( 'gddealer_default_import_schedule',
			(string) \IPS\Settings::i()->gddealer_default_import_schedule, TRUE, [
				'options' => [
					'15min' => 'Every 15 minutes',
					'30min' => 'Every 30 minutes',
					'1hr'   => 'Hourly',
					'6hr'   => 'Every 6 hours',
					'daily' => 'Daily',
				],
			] ) );

		/* v1.0.338 — per-run cap on the DealerImportFeeds task so a
		   cluster of simultaneously-due dealers doesn't take minutes
		   to work through in one 1-min tick. Task processes at most
		   this many dealers per invocation (most-overdue first);
		   subsequent ticks pick up the rest. Range 1-50 — going
		   higher than 50 would risk task-timeout on huge dealer
		   counts anyway. */
		$form->add( new \IPS\Helpers\Form\Number( 'gddealer_import_max_per_run',
			(int) ( \IPS\Settings::i()->gddealer_import_max_per_run ?: 5 ),
			TRUE, [ 'min' => 1, 'max' => 50 ] ) );

		$form->add( new \IPS\Helpers\Form\Number( 'gddealer_out_of_stock_grace_hours',
			(int) \IPS\Settings::i()->gddealer_out_of_stock_grace_hours, TRUE ) );

		$form->add( new \IPS\Helpers\Form\YesNo( 'gddealer_click_tracking_enabled',
			(bool) \IPS\Settings::i()->gddealer_click_tracking_enabled, FALSE ) );

		/* Deals author — the member shown as the byline on
		   auto-published deals. Default is the AutoDeals system
		   account created by upg_10328. Falls through to guest
		   only if the configured member has been deleted. */
		$dealsAuthorMember = null;
		try
		{
			$aid = (int) \IPS\Settings::i()->gddealer_deals_author_id;
			if ( $aid )
			{
				$m = \IPS\Member::load( $aid );
				if ( $m->member_id ) { $dealsAuthorMember = $m; }
			}
		}
		catch ( \Throwable ) {}
		$form->add( new \IPS\Helpers\Form\Member( 'gddealer_deals_author_id',
			$dealsAuthorMember, FALSE ) );

		/* ---- Directory ---- */
		$form->addHeader( 'gddealer_settings_directory' );

		$form->add( new \IPS\Helpers\Form\YesNo( 'gddealer_dir_map_enabled',
			(bool) \IPS\Settings::i()->gddealer_dir_map_enabled, FALSE ) );

		$form->add( new \IPS\Helpers\Form\Select( 'gddealer_dir_default_sort',
			(string) ( \IPS\Settings::i()->gddealer_dir_default_sort ?: 'featured' ), FALSE,
			[ 'options' => [
				'featured' => 'Featured (rotating)',
				'rating'   => 'Highest rated',
				'listings' => 'Most listings',
				'newest'   => 'Newest',
				'alpha'    => 'A–Z',
			] ] ) );

		$form->add( new \IPS\Helpers\Form\Number( 'gddealer_dir_per_page',
			(int) ( \IPS\Settings::i()->gddealer_dir_per_page ?: 24 ), FALSE, [ 'min' => 1 ] ) );

		$form->add( new \IPS\Helpers\Form\Select( 'gddealer_dir_default_view',
			(string) ( \IPS\Settings::i()->gddealer_dir_default_view ?: 'grid' ), FALSE,
			[ 'options' => [ 'grid' => 'Grid', 'list' => 'List' ] ] ) );

		$form->add( new \IPS\Helpers\Form\Text( 'gddealer_dir_hero_eyebrow',
			(string) \IPS\Settings::i()->gddealer_dir_hero_eyebrow, FALSE ) );

		$form->add( new \IPS\Helpers\Form\Text( 'gddealer_dir_hero_title',
			(string) \IPS\Settings::i()->gddealer_dir_hero_title, FALSE ) );

		$form->add( new \IPS\Helpers\Form\TextArea( 'gddealer_dir_hero_sub',
			(string) \IPS\Settings::i()->gddealer_dir_hero_sub, FALSE, [ 'rows' => 3 ] ) );

		$form->add( new \IPS\Helpers\Form\Url( 'gddealer_dir_join_url',
			(string) \IPS\Settings::i()->gddealer_dir_join_url, FALSE ) );

		$form->add( new \IPS\Helpers\Form\Text( 'gddealer_dir_join_text',
			(string) \IPS\Settings::i()->gddealer_dir_join_text, FALSE ) );

		$form->add( new \IPS\Helpers\Form\YesNo( 'gddealer_dir_show_search',
			(bool) \IPS\Settings::i()->gddealer_dir_show_search, FALSE ) );

		$form->add( new \IPS\Helpers\Form\YesNo( 'gddealer_dir_show_state_filter',
			(bool) \IPS\Settings::i()->gddealer_dir_show_state_filter, FALSE ) );

		$form->add( new \IPS\Helpers\Form\YesNo( 'gddealer_dir_show_rating_filter',
			(bool) \IPS\Settings::i()->gddealer_dir_show_rating_filter, FALSE ) );

		$form->add( new \IPS\Helpers\Form\YesNo( 'gddealer_dir_show_sort',
			(bool) \IPS\Settings::i()->gddealer_dir_show_sort, FALSE ) );

		$form->addHeader( 'gddealer_commerce_header' );

		$form->add( new \IPS\Helpers\Form\Number( 'gddealer_commerce_basic_id',
			(int) \IPS\Settings::i()->gddealer_commerce_basic_id, FALSE ) );
		$form->add( new \IPS\Helpers\Form\Number( 'gddealer_commerce_pro_id',
			(int) \IPS\Settings::i()->gddealer_commerce_pro_id, FALSE ) );
		$form->add( new \IPS\Helpers\Form\Number( 'gddealer_commerce_enterprise_id',
			(int) \IPS\Settings::i()->gddealer_commerce_enterprise_id, FALSE ) );
		$form->add( new \IPS\Helpers\Form\Number( 'gddealer_commerce_max_id',
			(int) \IPS\Settings::i()->gddealer_commerce_max_id, FALSE ) );

		$form->addHeader( 'gddealer_settings_subscription_tab' );

		$settings = \IPS\Settings::i();

		$form->add( new \IPS\Helpers\Form\TextArea( 'gddealer_subscription_billing_note',
			(string) ( $settings->gddealer_subscription_billing_note ?? '' ), FALSE, [ 'rows' => 3 ] ) );

		$subscribeUrlValue = (string) ( $settings->gddealer_subscribe_url ?? '' );
		$form->add( new \IPS\Helpers\Form\Url( 'gddealer_subscribe_url',
			$subscribeUrlValue ? \IPS\Http\Url::external( $subscribeUrlValue ) : null,
			FALSE ) );

		$form->addHeader( 'gddealer_settings_help_content' );

		$form->add( new \IPS\Helpers\Form\TextArea( 'gddealer_help_intro',
			(string) ( $settings->gddealer_help_intro ?? '' ), FALSE, [ 'rows' => 3 ] ) );
		$form->add( new \IPS\Helpers\Form\TextArea( 'gddealer_help_step1',
			(string) ( $settings->gddealer_help_step1 ?? '' ), FALSE, [ 'rows' => 4 ] ) );
		$form->add( new \IPS\Helpers\Form\TextArea( 'gddealer_help_step2',
			(string) ( $settings->gddealer_help_step2 ?? '' ), FALSE, [ 'rows' => 4 ] ) );
		$form->add( new \IPS\Helpers\Form\TextArea( 'gddealer_help_step3',
			(string) ( $settings->gddealer_help_step3 ?? '' ), FALSE, [ 'rows' => 4 ] ) );
		$form->add( new \IPS\Helpers\Form\TextArea( 'gddealer_help_step4',
			(string) ( $settings->gddealer_help_step4 ?? '' ), FALSE, [ 'rows' => 4 ] ) );
		$form->add( new \IPS\Helpers\Form\TextArea( 'gddealer_help_step5',
			(string) ( $settings->gddealer_help_step5 ?? '' ), FALSE, [ 'rows' => 4 ] ) );
		$form->add( new \IPS\Helpers\Form\TextArea( 'gddealer_help_requirements',
			(string) ( $settings->gddealer_help_requirements ?? '' ), FALSE, [ 'rows' => 8 ] ) );
		$form->add( new \IPS\Helpers\Form\Text( 'gddealer_help_contact',
			(string) ( $settings->gddealer_help_contact ?? '' ), FALSE ) );

		$form->addHeader( 'gddealer_help_step2_code_header' );

		$form->add( new \IPS\Helpers\Form\TextArea( 'gddealer_help_step2_csv',
			(string) ( $settings->gddealer_help_step2_csv ?? '' ), FALSE,
			[ 'rows' => 6 ] ) );

		$form->add( new \IPS\Helpers\Form\TextArea( 'gddealer_help_step2_json',
			(string) ( $settings->gddealer_help_step2_json ?? '' ), FALSE,
			[ 'rows' => 10 ] ) );

		$form->add( new \IPS\Helpers\Form\TextArea( 'gddealer_help_step2_xml',
			(string) ( $settings->gddealer_help_step2_xml ?? '' ), FALSE,
			[ 'rows' => 10 ] ) );

		$form->addHeader( 'gddealer_help_sync_header' );

		$form->add( new \IPS\Helpers\Form\Text( 'gddealer_help_sync_basic',
			(string) ( $settings->gddealer_help_sync_basic ?? 'Every 6 hours' ), FALSE ) );

		$form->add( new \IPS\Helpers\Form\Text( 'gddealer_help_sync_pro',
			(string) ( $settings->gddealer_help_sync_pro ?? 'Every 30 minutes' ), FALSE ) );

		$form->add( new \IPS\Helpers\Form\Text( 'gddealer_help_sync_enterprise',
			(string) ( $settings->gddealer_help_sync_enterprise ?? 'Every 15 minutes' ), FALSE ) );

		$form->addHeader( 'gddealer_settings_guidelines' );

		$form->add( new \IPS\Helpers\Form\Text( 'gddealer_guidelines_buyer_title',
			(string) ( $settings->gddealer_guidelines_buyer_title ?? '' ), FALSE ) );
		$form->add( new \IPS\Helpers\Form\TextArea( 'gddealer_guidelines_buyer_body',
			(string) ( $settings->gddealer_guidelines_buyer_body ?? '' ), FALSE, [ 'rows' => 10 ] ) );

		$form->add( new \IPS\Helpers\Form\Text( 'gddealer_guidelines_dispute_title',
			(string) ( $settings->gddealer_guidelines_dispute_title ?? '' ), FALSE ) );
		$form->add( new \IPS\Helpers\Form\TextArea( 'gddealer_guidelines_dispute_body',
			(string) ( $settings->gddealer_guidelines_dispute_body ?? '' ), FALSE, [ 'rows' => 12 ] ) );

		$form->add( new \IPS\Helpers\Form\Text( 'gddealer_guidelines_dealer_title',
			(string) ( $settings->gddealer_guidelines_dealer_title ?? '' ), FALSE ) );
		$form->add( new \IPS\Helpers\Form\TextArea( 'gddealer_guidelines_dealer_body',
			(string) ( $settings->gddealer_guidelines_dealer_body ?? '' ), FALSE, [ 'rows' => 10 ] ) );

		$form->addHeader( 'gddealer_caps_header' );
		$form->add( new \IPS\Helpers\Form\Number( 'gddealer_cap_basic',      ( \IPS\Settings::i()->gddealer_cap_basic !== '' ? (int) \IPS\Settings::i()->gddealer_cap_basic : 500 ),      FALSE, [ 'min' => 0 ] ) );
		$form->add( new \IPS\Helpers\Form\Number( 'gddealer_cap_pro',        ( \IPS\Settings::i()->gddealer_cap_pro !== '' ? (int) \IPS\Settings::i()->gddealer_cap_pro : 2500 ),       FALSE, [ 'min' => 0 ] ) );
		$form->add( new \IPS\Helpers\Form\Number( 'gddealer_cap_enterprise', ( \IPS\Settings::i()->gddealer_cap_enterprise !== '' ? (int) \IPS\Settings::i()->gddealer_cap_enterprise : 6000 ), FALSE, [ 'min' => 0 ] ) );
		$form->add( new \IPS\Helpers\Form\Number( 'gddealer_cap_max',        ( \IPS\Settings::i()->gddealer_cap_max !== '' ? (int) \IPS\Settings::i()->gddealer_cap_max : 0 ),           FALSE, [ 'min' => 0 ] ) );

		$form->addHeader( 'gddealer_settings_theme' );

		$form->add( new \IPS\Helpers\Form\Color( 'gddealer_color_primary',
			\IPS\Settings::i()->gddealer_color_primary ?: '#2563eb', FALSE,
			[], NULL, NULL, NULL, 'gddealer_color_primary' ) );

		$form->add( new \IPS\Helpers\Form\Color( 'gddealer_color_active_tab_bg',
			\IPS\Settings::i()->gddealer_color_active_tab_bg ?: '#1e3a5f', FALSE,
			[], NULL, NULL, NULL, 'gddealer_color_active_tab_bg' ) );

		$form->add( new \IPS\Helpers\Form\Color( 'gddealer_color_active_tab_text',
			\IPS\Settings::i()->gddealer_color_active_tab_text ?: '#ffffff', FALSE,
			[], NULL, NULL, NULL, 'gddealer_color_active_tab_text' ) );

		$form->add( new \IPS\Helpers\Form\Color( 'gddealer_color_inactive_tab_text',
			\IPS\Settings::i()->gddealer_color_inactive_tab_text ?: '#374151', FALSE,
			[], NULL, NULL, NULL, 'gddealer_color_inactive_tab_text' ) );

		$form->add( new \IPS\Helpers\Form\Color( 'gddealer_color_accent',
			\IPS\Settings::i()->gddealer_color_accent ?: '#16a34a', FALSE,
			[], NULL, NULL, NULL, 'gddealer_color_accent' ) );

		$form->add( new \IPS\Helpers\Form\Color( 'gddealer_color_warning',
			\IPS\Settings::i()->gddealer_color_warning ?: '#d97706', FALSE,
			[], NULL, NULL, NULL, 'gddealer_color_warning' ) );

		$form->add( new \IPS\Helpers\Form\Color( 'gddealer_color_danger',
			\IPS\Settings::i()->gddealer_color_danger ?: '#dc2626', FALSE,
			[], NULL, NULL, NULL, 'gddealer_color_danger' ) );

		$form->add( new \IPS\Helpers\Form\Color( 'gddealer_color_header_bg',
			\IPS\Settings::i()->gddealer_color_header_bg ?: '#1e3a5f', FALSE,
			[], NULL, NULL, NULL, 'gddealer_color_header_bg' ) );

		$form->add( new \IPS\Helpers\Form\Color( 'gddealer_color_card_bg',
			\IPS\Settings::i()->gddealer_color_card_bg ?: '#ffffff', FALSE,
			[], NULL, NULL, NULL, 'gddealer_color_card_bg' ) );

		$form->addHeader( 'gddealer_settings_tier_colors' );

		$form->add( new \IPS\Helpers\Form\Color( 'gddealer_founding_badge_color',
			\IPS\Settings::i()->gddealer_founding_badge_color ?: '#b45309', FALSE,
			[], NULL, NULL, NULL, 'gddealer_founding_badge_color' ) );

		$form->add( new \IPS\Helpers\Form\Color( 'gddealer_basic_badge_color',
			\IPS\Settings::i()->gddealer_basic_badge_color ?: '#6b7280', FALSE,
			[], NULL, NULL, NULL, 'gddealer_basic_badge_color' ) );

		$form->add( new \IPS\Helpers\Form\Color( 'gddealer_pro_badge_color',
			\IPS\Settings::i()->gddealer_pro_badge_color ?: '#2563eb', FALSE,
			[], NULL, NULL, NULL, 'gddealer_pro_badge_color' ) );

		$form->add( new \IPS\Helpers\Form\Color( 'gddealer_enterprise_badge_color',
			\IPS\Settings::i()->gddealer_enterprise_badge_color ?: '#7c3aed', FALSE,
			[], NULL, NULL, NULL, 'gddealer_enterprise_badge_color' ) );

		$form->addHeader( 'gddealer_settings_quicklinks' );

		$currentLinks = json_decode( (string) ( \IPS\Settings::i()->gddealer_quicklinks ?: '[]' ), true );
		if ( !is_array( $currentLinks ) || empty( $currentLinks ) )
		{
			$currentLinks = [
				[ 'icon' => 'fa-solid fa-user',           'label' => 'View Public Profile',  'url_type' => 'profile',       'custom_url' => '' ],
				[ 'icon' => 'fa-solid fa-rss',            'label' => 'Feed Settings',         'url_type' => 'feed_settings', 'custom_url' => '' ],
				[ 'icon' => 'fa-solid fa-circle-question','label' => 'Help & Setup Guide',    'url_type' => 'help',          'custom_url' => '' ],
				[ 'icon' => 'fa-solid fa-sliders',        'label' => 'Customize Dashboard',   'url_type' => 'customize',     'custom_url' => '' ],
			];
		}

		$form->add( new \IPS\Helpers\Form\TextArea( 'gddealer_quicklinks_json',
			json_encode( $currentLinks, JSON_PRETTY_PRINT ), FALSE,
			[ 'rows' => 10 ],
			NULL, NULL,
			'<p style="margin-top:4px;font-size:0.85em;color:#666">JSON array. Each item: <code>{"icon":"fa-solid fa-user","label":"My Label","url_type":"profile","custom_url":""}</code><br>url_type options: profile, feed_settings, listings, unmatched, analytics, reviews, help, subscription, customize, custom</p>',
			'gddealer_quicklinks_json'
		) );

		$form->addHeader( 'gddealer_settings_emails' );
		$form->addMessage( 'gddealer_settings_emails_help' );

		/* IPS 5 email storage — investigated and verified against the
		   real core_email_templates schema, DealerEmails.php extension,
		   emails.xml, and every buildFromTemplate() call site.
		   Real body column is template_content_html; there is NO
		   per-row subject column. IPS resolves the subject at send
		   time from language key {templateName}_email_subject
		   (confirmed by the working FFL flow: template name
		   gddealer_ffl_verified pairs with lang key
		   gddealer_ffl_verified_email_subject, and the send code
		   never passes an explicit subject arg — IPS auto-resolves).
		   All three admin-editable dealer emails send via
		   \IPS\Email::buildFromTemplate() with no subject arg, so
		   writing the correct lang key here IS what changes what
		   dealers actually receive. */
		$getEmailSubject = function( string $tpl ): string {
			try {
				$defaultLangId = (int) \IPS\Db::i()->select( 'lang_id', 'core_sys_lang', [ 'lang_default=?', 1 ] )->first();
			}
			catch ( \Throwable ) { $defaultLangId = 0; }
			try {
				return (string) \IPS\Db::i()->select( 'word_default', 'core_sys_lang_words',
					[ 'word_key=? AND lang_id=?', $tpl . '_email_subject', $defaultLangId ]
				)->first();
			}
			catch ( \Throwable ) { return ''; }
		};
		$getEmailBody = function( string $tpl ): string {
			try {
				return (string) \IPS\Db::i()->select( 'template_content_html', 'core_email_templates',
					[ 'template_app=? AND template_name=?', 'gddealer', $tpl ]
				)->first();
			}
			catch ( \Throwable ) { return ''; }
		};

		$form->add( new \IPS\Helpers\Form\Text( 'gddealer_email_welcome_subject',
			$getEmailSubject( 'dealerWelcome' ), FALSE, [],
			NULL, NULL, NULL, 'gddealer_email_welcome_subject' ) );
		$form->add( new \IPS\Helpers\Form\TextArea( 'gddealer_email_welcome_body',
			$getEmailBody( 'dealerWelcome' ), FALSE, [ 'rows' => 8 ],
			NULL, NULL, NULL, 'gddealer_email_welcome_body' ) );

		$form->add( new \IPS\Helpers\Form\Text( 'gddealer_email_expiring_subject',
			$getEmailSubject( 'trialExpiringSoon' ), FALSE, [],
			NULL, NULL, NULL, 'gddealer_email_expiring_subject' ) );
		$form->add( new \IPS\Helpers\Form\TextArea( 'gddealer_email_expiring_body',
			$getEmailBody( 'trialExpiringSoon' ), FALSE, [ 'rows' => 8 ],
			NULL, NULL, NULL, 'gddealer_email_expiring_body' ) );

		$form->add( new \IPS\Helpers\Form\Text( 'gddealer_email_expired_subject',
			$getEmailSubject( 'trialExpired' ), FALSE, [],
			NULL, NULL, NULL, 'gddealer_email_expired_subject' ) );
		$form->add( new \IPS\Helpers\Form\TextArea( 'gddealer_email_expired_body',
			$getEmailBody( 'trialExpired' ), FALSE, [ 'rows' => 8 ],
			NULL, NULL, NULL, 'gddealer_email_expired_body' ) );

		if ( $values = $form->values() )
		{
			/* Form\Member returns an \IPS\Member instance (or NULL
			   when the field is empty). Persist just the ID so the
			   setting stays cheap to load. */
			$dealsAuthorId = 0;
			if ( isset( $values['gddealer_deals_author_id'] ) && $values['gddealer_deals_author_id'] instanceof \IPS\Member )
			{
				$dealsAuthorId = (int) $values['gddealer_deals_author_id']->member_id;
			}

			$form->saveAsSettings( [
				'gddealer_group_founding'             => (int) $values['gddealer_group_founding'],
				'gddealer_group_basic'                => (int) $values['gddealer_group_basic'],
				'gddealer_group_pro'                  => (int) $values['gddealer_group_pro'],
				'gddealer_group_enterprise'           => (int) $values['gddealer_group_enterprise'],
				'gddealer_group_max'                  => (int) $values['gddealer_group_max'],
				'gddealer_announce_enabled'           => (int) $values['gddealer_announce_enabled'],
				'gddealer_announce_style'             => (string) $values['gddealer_announce_style'],
				'gddealer_announce_body'              => (string) $values['gddealer_announce_body'],
				'gddealer_default_import_schedule'    => (string) $values['gddealer_default_import_schedule'],
				'gddealer_deals_author_id'            => $dealsAuthorId,
				'gddealer_out_of_stock_grace_hours'   => (int) $values['gddealer_out_of_stock_grace_hours'],
				'gddealer_click_tracking_enabled'     => (int) $values['gddealer_click_tracking_enabled'],
				'gddealer_commerce_basic_id'          => (int) $values['gddealer_commerce_basic_id'],
				'gddealer_commerce_pro_id'            => (int) $values['gddealer_commerce_pro_id'],
				'gddealer_commerce_enterprise_id'     => (int) $values['gddealer_commerce_enterprise_id'],
				'gddealer_commerce_max_id'            => (int) $values['gddealer_commerce_max_id'],
				'gddealer_subscription_billing_note'  => (string) $values['gddealer_subscription_billing_note'],
				'gddealer_subscribe_url'              => (string) $values['gddealer_subscribe_url'],
				'gddealer_help_intro'                 => (string) $values['gddealer_help_intro'],
				'gddealer_help_step1'                 => (string) $values['gddealer_help_step1'],
				'gddealer_help_step2'                 => (string) $values['gddealer_help_step2'],
				'gddealer_help_step3'                 => (string) $values['gddealer_help_step3'],
				'gddealer_help_step4'                 => (string) $values['gddealer_help_step4'],
				'gddealer_help_step5'                 => (string) $values['gddealer_help_step5'],
				'gddealer_help_requirements'          => (string) $values['gddealer_help_requirements'],
				'gddealer_help_contact'               => (string) $values['gddealer_help_contact'],
				'gddealer_help_step2_csv'             => (string) $values['gddealer_help_step2_csv'],
				'gddealer_help_step2_json'            => (string) $values['gddealer_help_step2_json'],
				'gddealer_help_step2_xml'             => (string) $values['gddealer_help_step2_xml'],
				'gddealer_help_sync_basic'            => (string) $values['gddealer_help_sync_basic'],
				'gddealer_help_sync_pro'              => (string) $values['gddealer_help_sync_pro'],
				'gddealer_help_sync_enterprise'       => (string) $values['gddealer_help_sync_enterprise'],
				'gddealer_guidelines_buyer_title'     => (string) $values['gddealer_guidelines_buyer_title'],
				'gddealer_guidelines_buyer_body'      => (string) $values['gddealer_guidelines_buyer_body'],
				'gddealer_guidelines_dispute_title'   => (string) $values['gddealer_guidelines_dispute_title'],
				'gddealer_guidelines_dispute_body'    => (string) $values['gddealer_guidelines_dispute_body'],
				'gddealer_guidelines_dealer_title'    => (string) $values['gddealer_guidelines_dealer_title'],
				'gddealer_guidelines_dealer_body'     => (string) $values['gddealer_guidelines_dealer_body'],
				'gddealer_color_primary'              => (string) $values['gddealer_color_primary'],
				'gddealer_color_active_tab_bg'        => (string) $values['gddealer_color_active_tab_bg'],
				'gddealer_color_active_tab_text'      => (string) $values['gddealer_color_active_tab_text'],
				'gddealer_color_inactive_tab_text'    => (string) $values['gddealer_color_inactive_tab_text'],
				'gddealer_color_accent'               => (string) $values['gddealer_color_accent'],
				'gddealer_color_warning'              => (string) $values['gddealer_color_warning'],
				'gddealer_color_danger'               => (string) $values['gddealer_color_danger'],
				'gddealer_color_header_bg'            => (string) $values['gddealer_color_header_bg'],
				'gddealer_color_card_bg'              => (string) $values['gddealer_color_card_bg'],
				'gddealer_founding_badge_color'       => (string) $values['gddealer_founding_badge_color'],
				'gddealer_basic_badge_color'          => (string) $values['gddealer_basic_badge_color'],
				'gddealer_pro_badge_color'            => (string) $values['gddealer_pro_badge_color'],
				'gddealer_enterprise_badge_color'     => (string) $values['gddealer_enterprise_badge_color'],
				'gddealer_cap_basic'                 => (int) $values['gddealer_cap_basic'],
				'gddealer_cap_pro'                   => (int) $values['gddealer_cap_pro'],
				'gddealer_cap_enterprise'            => (int) $values['gddealer_cap_enterprise'],
				'gddealer_cap_max'                   => (int) $values['gddealer_cap_max'],
				'gddealer_dir_map_enabled'           => (int) $values['gddealer_dir_map_enabled'],
				'gddealer_dir_default_sort'          => (string) $values['gddealer_dir_default_sort'],
				'gddealer_dir_per_page'              => (int) $values['gddealer_dir_per_page'],
				'gddealer_dir_default_view'          => (string) $values['gddealer_dir_default_view'],
				'gddealer_dir_hero_eyebrow'          => (string) $values['gddealer_dir_hero_eyebrow'],
				'gddealer_dir_hero_title'            => (string) $values['gddealer_dir_hero_title'],
				'gddealer_dir_hero_sub'              => (string) $values['gddealer_dir_hero_sub'],
				'gddealer_dir_join_url'              => (string) $values['gddealer_dir_join_url'],
				'gddealer_dir_join_text'             => (string) $values['gddealer_dir_join_text'],
				'gddealer_dir_show_search'           => (int) $values['gddealer_dir_show_search'],
				'gddealer_dir_show_state_filter'     => (int) $values['gddealer_dir_show_state_filter'],
				'gddealer_dir_show_rating_filter'    => (int) $values['gddealer_dir_show_rating_filter'],
				'gddealer_dir_show_sort'             => (int) $values['gddealer_dir_show_sort'],
			]);

			if ( isset( $values['gddealer_quicklinks_json'] ) )
			{
				$decoded = json_decode( trim( (string) $values['gddealer_quicklinks_json'] ), true );
				if ( is_array( $decoded ) )
				{
					\IPS\Settings::i()->changeValues( [ 'gddealer_quicklinks' => json_encode( $decoded ) ] );
				}
			}

			/* Writes to the REAL IPS 5 storage locations investigated
			   above. Subject → core_sys_lang_words for every lang_id
			   under key {tpl}_email_subject. Body → core_email_templates
			   .template_content_html (+ mirrored plaintext with tags
			   stripped so plaintext MIME parts stay usable). Template
			   row is upserted so a missing row (emails.xml parse-fail
			   installs, rule #18) doesn't silently no-op the update.
			   Silent catch(\Exception){} REMOVED — every write path
			   logs on failure so ACP save issues surface in
			   core_error_logs going forward. */
			$updateEmail = function( string $tpl, string $subject, string $body ): void {
				$subject = (string) $subject;
				$body    = (string) $body;

				/* Body — core_email_templates */
				try
				{
					$exists = FALSE;
					try
					{
						$exists = (bool) \IPS\Db::i()->select( 'template_id', 'core_email_templates',
							[ 'template_app=? AND template_name=?', 'gddealer', $tpl ]
						)->first();
					}
					catch ( \UnderflowException ) { $exists = FALSE; }

					$plaintext = trim( html_entity_decode( strip_tags( str_replace( [ '<br>', '<br/>', '<br />', '</p>' ], "\n", $body ) ), ENT_QUOTES | ENT_HTML5 ) );

					if ( $exists )
					{
						\IPS\Db::i()->update( 'core_email_templates',
							[
								'template_content_html'      => $body,
								'template_content_plaintext' => $plaintext,
								'template_edited'            => 1,
							],
							[ 'template_app=? AND template_name=?', 'gddealer', $tpl ]
						);
					}
					else
					{
						\IPS\Db::i()->insert( 'core_email_templates', [
							'template_app'               => 'gddealer',
							'template_name'              => $tpl,
							'template_content_html'      => $body,
							'template_content_plaintext' => $plaintext,
							'template_data'              => '',
							'template_edited'            => 1,
						] );
					}
				}
				catch ( \Throwable $e )
				{
					try { \IPS\Log::log( 'gddealer email body save (' . $tpl . '): ' . $e->getMessage(), 'gddealer' ); } catch ( \Throwable ) {}
				}

				/* Subject — {tpl}_email_subject for every lang_id
				   (Rule #43/#44 — 6-col core_sys_lang_words replace,
				   per-row try/catch so one bad row doesn't abort). */
				try
				{
					foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
					{
						try
						{
							\IPS\Db::i()->replace( 'core_sys_lang_words', [
								'lang_id'      => (int) $langId,
								'word_app'     => 'gddealer',
								'word_key'     => $tpl . '_email_subject',
								'word_default' => $subject,
								'word_js'      => 0,
								'word_export'  => 1,
							] );
						}
						catch ( \Throwable $e )
						{
							try { \IPS\Log::log( 'gddealer email subject save (' . $tpl . ' lang ' . (int) $langId . '): ' . $e->getMessage(), 'gddealer' ); } catch ( \Throwable ) {}
						}
					}
				}
				catch ( \Throwable $e )
				{
					try { \IPS\Log::log( 'gddealer email subject save (' . $tpl . ' lang loop): ' . $e->getMessage(), 'gddealer' ); } catch ( \Throwable ) {}
				}
			};

			$updateEmail( 'dealerWelcome',     (string) $values['gddealer_email_welcome_subject'],  (string) $values['gddealer_email_welcome_body'] );
			$updateEmail( 'trialExpiringSoon', (string) $values['gddealer_email_expiring_subject'], (string) $values['gddealer_email_expiring_body'] );
			$updateEmail( 'trialExpired',      (string) $values['gddealer_email_expired_subject'],  (string) $values['gddealer_email_expired_body'] );

			/* Language cache MUST be invalidated so the freshly-saved
			   subject lang key is used on the next email send instead
			   of a stale cached value. Applies to buildFromTemplate()
			   subject lookup and any UI display of the same key. */
			try { \IPS\Data\Store::i()->clearAll(); } catch ( \Throwable ) {}
			try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}

			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=settings' ),
				'saved'
			);
		}

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gddealer_settings_title' );
		\IPS\Output::i()->output = (string) $form;
	}
}

class settings extends _settings {}
