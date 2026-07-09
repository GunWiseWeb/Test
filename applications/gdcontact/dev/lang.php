<?php
$lang = [
	'__app_gdcontact'           => 'Gun Rack Contact',
	'menutab__gdcontact'        => 'Contact',
	'menutab__gdcontact_icon'   => 'envelope',

	'module__admin_manage'      => 'Contact',
	'menu__gdcontact_manage_settings' => 'Settings',
	'menu__gdcontact_manage_fields'   => 'Fields',
	'r__contact_manage'         => 'Manage Contact',

	/* Settings labels + help */
	'gdcontact_recipient'                 => 'Default recipient email',
	'gdcontact_recipient_desc'            => 'Every submission is emailed here unless a routing rule overrides.',
	'gdcontact_from_email'                => 'From email',
	'gdcontact_from_email_desc'           => 'Optional — the email address the outgoing message appears to come from. Leave blank to use IPS\'s default outgoing address.',
	'gdcontact_from_name'                 => 'From name',
	'gdcontact_from_name_desc'            => 'Optional — the display name on the outgoing message. Leave blank to use IPS\'s default.',
	'gdcontact_subject_prefix'            => 'Email subject prefix',
	'gdcontact_subject_prefix_desc'       => 'Prepended to the outgoing email subject.',
	'gdcontact_page_title'                => 'Public page title',
	'gdcontact_intro'                     => 'Public page intro text',
	'gdcontact_success_message'           => 'Success message',
	'gdcontact_success_message_desc'      => 'Shown to the visitor after a successful submission.',
	'gdcontact_captcha_enabled'           => 'CAPTCHA on the public form',
	'gdcontact_captcha_enabled_desc'      => 'Uses whatever CAPTCHA the site has configured (Turnstile, reCAPTCHA, etc.).',
	'gdcontact_routes_json'               => 'Routing rules (JSON)',
	'gdcontact_routes_json_desc'          => 'Optional per-field-value routing. Array of {"field_key":"…","value":"…","recipient":"…"} entries. Matching entries route the email to the listed recipient INSTEAD of the default.',
	'gdcontact_honeypot_enabled'          => 'Honeypot anti-spam',
	'gdcontact_honeypot_enabled_desc'     => 'Adds an invisible field that bots fill in and humans don\'t. Highly recommended.',

	/* Fields builder — field labels + help */
	'gdcontact_field_label'          => 'Label',
	'gdcontact_field_label_desc'     => 'What the visitor sees above the input.',
	'gdcontact_field_key'            => 'Field key',
	'gdcontact_field_key_desc'       => 'Machine-safe slug (letters, digits, underscores). Auto-derived from the label — override only if you need to.',
	'gdcontact_field_type'           => 'Field type',
	'gdcontact_field_required'       => 'Required',
	'gdcontact_field_position'       => 'Position',
	'gdcontact_field_position_desc'  => 'Lower = higher on the form.',
	'gdcontact_field_options'        => 'Options (for Select)',
	'gdcontact_field_options_desc'   => 'One option per line. Ignored for other field types.',
	'gdcontact_field_placeholder'    => 'Placeholder',
	'gdcontact_field_help_text'      => 'Help text',
	'gdcontact_field_enabled'        => 'Enabled',

	/* Fields builder — table headers + actions */
	'gdcontact_fields_title'      => 'Contact fields',
	'gdcontact_fields_intro'      => 'Define what the visitor sees on /contact/. Drag by position or edit each row to reorder.',
	'gdcontact_fields_add'        => 'Add field',
	'gdcontact_field_edit_title'  => 'Edit field',
	'gdcontact_field_add_title'   => 'Add field',
	'gdcontact_field_saved'       => 'Field saved.',
	'gdcontact_field_deleted'     => 'Field deleted.',
	'gdcontact_field_delete_confirm' => 'Delete this field?',

	/* Settings page title */
	'gdcontact_settings_title'    => 'Contact form settings',
	'gdcontact_settings_saved'    => 'Settings saved.',

	/* Field types */
	'gdcontact_ftype_text'     => 'Single-line text',
	'gdcontact_ftype_email'    => 'Email address',
	'gdcontact_ftype_phone'    => 'Phone number',
	'gdcontact_ftype_textarea' => 'Multi-line text',
	'gdcontact_ftype_select'   => 'Dropdown (Select)',
	'gdcontact_ftype_checkbox' => 'Checkbox',
	'gdcontact_ftype_number'   => 'Number',

	/* Public page */
	'gdcontact_submit'                     => 'Send message',
	'gdcontact_field_required_star'        => '*',
	'gdcontact_err_captcha'                => 'Please complete the CAPTCHA.',
	'gdcontact_err_required'               => 'Please complete every required field.',
	'gdcontact_err_email'                  => 'Please enter a valid email address.',
	'gdcontact_err_number'                 => 'Please enter a numeric value.',
	'gdcontact_err_send'                   => 'Sorry — we couldn\'t send your message. Please try again in a moment or email us directly.',

	/* Default seed field labels — used by install.php */
	'gdcontact_default_field_name'    => 'Your name',
	'gdcontact_default_field_email'   => 'Email address',
	'gdcontact_default_field_phone'   => 'Phone (optional)',
	'gdcontact_default_field_message' => 'Message',

	/* Honeypot label — rendered inside the off-screen container
	   so real users never see it. Present so the raw key doesn't
	   leak into DOM inspectors either. */
	'gdcontact_hp_website' => 'Website',
];
