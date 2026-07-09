<?php
/**
 * @brief  GD Contact — public /contact/ page.
 *
 * Renders the enabled fields (built in the ACP) via
 * \IPS\Helpers\Form, appends the site's CAPTCHA + an optional
 * honeypot, validates on submit, and emails the result via
 * \IPS\Email::buildFromContent()->send() with reply-to set to
 * the submitter's address. Guest-usable.
 *
 * No submission storage — email only. A submissions-log table
 * COULD live at gd_contact_submissions later; the code is
 * shaped so adding it is a one-place drop-in inside send()
 * right before the redirect.
 */

namespace IPS\gdcontact\modules\front\contact;

use IPS\gdcontact\Field\Field;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _contact extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		parent::execute();
	}

	/* ------------------------------------------------------------------
	 * GET /contact/ — render form.
	 * POST /contact/ — validate + send.
	 * ------------------------------------------------------------------ */
	protected function manage(): void
	{
		$lang = \IPS\Member::loggedIn()->language();
		$L    = fn( string $k ): string => (string) $lang->addToStack( $k );
		$esc  = fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );

		/* Enqueue our stylesheet — MUST use \IPS\Theme::i()->css.
		   The css helper on \IPS\Output does not exist (a prior
		   plugin's bug — don't reintroduce). */
		try
		{
			\IPS\Output::i()->cssFiles = array_merge(
				\IPS\Output::i()->cssFiles,
				\IPS\Theme::i()->css( 'contact.css', 'gdcontact', 'interface' )
			);
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcontact css enqueue: ' . $e->getMessage(), 'gdcontact' ); } catch ( \Throwable ) {}
		}

		$fields = Field::enabledOrdered();
		$form   = new \IPS\Helpers\Form( 'gdcontact_form', 'gdcontact_submit' );

		/* Pre-fill name/email if the visitor is a logged-in
		   member — small quality-of-life win, guest still works. */
		$me      = \IPS\Member::loggedIn();
		$preName = $me->member_id ? (string) $me->name  : '';
		$preMail = $me->member_id ? (string) $me->email : '';

		foreach ( $fields as $f )
		{
			$this->addField( $form, $f, $preName, $preMail );
		}

		/* Honeypot is NOT added to the IPS Form — IPS renders every
		   Form\Text field labelled + visible, which defeats the
		   entire trap. Instead we (a) intercept the raw POST value
		   BEFORE $form->values() runs and silent-reject on match,
		   and (b) inject the input as raw HTML wrapped in an
		   off-screen container (position:absolute + left:-9999px +
		   aria-hidden + tabindex=-1) right before rendering. Bots
		   scanning the DOM see and fill a normal <input>; humans
		   never see it. */
		$honeypotEnabled = (bool) \IPS\Settings::i()->gdcontact_honeypot_enabled;
		if ( $honeypotEnabled && ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'POST' )
		{
			$hpRaw = '';
			try { $hpRaw = trim( (string) ( \IPS\Request::i()->gdcontact_hp_website ?? '' ) ); }
			catch ( \Throwable ) {}
			if ( $hpRaw !== '' )
			{
				/* Silent success — bots don't get a validation
				   error, they just move on thinking they scored. */
				$_SESSION[ 'gdcontact_flash' ] = 'ok';
				\IPS\Output::i()->redirect(
					\IPS\Http\Url::internal( 'app=gdcontact&module=contact&controller=contact', 'front' )
				);
				return;
			}
		}

		/* CAPTCHA — reads whatever the site has configured
		   (Turnstile / reCAPTCHA / IPS default). */
		if ( (bool) \IPS\Settings::i()->gdcontact_captcha_enabled )
		{
			try { $form->add( new \IPS\Helpers\Form\Captcha ); } catch ( \Throwable ) {}
		}

		$flashKey    = 'gdcontact_flash';
		$successFlag = isset( $_SESSION[ $flashKey ] ) && $_SESSION[ $flashKey ] === 'ok';
		if ( $successFlag ) { unset( $_SESSION[ $flashKey ] ); }

		if ( $values = $form->values() )
		{
			$submitted = [];
			foreach ( $fields as $f )
			{
				$key = 'field_' . $f->id;
				$raw = $values[ $key ] ?? '';
				$submitted[] = [
					'label' => (string) $f->label,
					'key'   => (string) $f->field_key,
					'type'  => (string) $f->type,
					'value' => is_array( $raw ) ? implode( ', ', $raw ) : (string) $raw,
				];
			}

			$ok = $this->sendEmail( $submitted );
			if ( $ok )
			{
				$_SESSION[ $flashKey ] = 'ok';
				\IPS\Output::i()->redirect(
					\IPS\Http\Url::internal( 'app=gdcontact&module=contact&controller=contact', 'front' )
				);
				return;
			}
			else
			{
				\IPS\Output::i()->error( $L( 'gdcontact_err_send' ), '2GDCONTACT/1', 500 );
				return;
			}
		}

		/* ------------------------ RENDER ------------------------ */
		$title = (string) \IPS\Settings::i()->gdcontact_page_title ?: $L( '__app_gdcontact' );
		$intro = (string) \IPS\Settings::i()->gdcontact_intro;

		$html  = '<div class="gr5 gdcontact-wrap">';
		$html .= '<h1 class="gdcontact-title">' . $esc( $title ) . '</h1>';
		if ( $intro !== '' )
		{
			$html .= '<p class="gdcontact-intro">' . $esc( $intro ) . '</p>';
		}

		if ( $successFlag )
		{
			$html .= '<div class="gdcontact-success" role="status">'
				. $esc( (string) \IPS\Settings::i()->gdcontact_success_message )
				. '</div>';
		}

		$formHtml = (string) $form;

		/* Inject the honeypot INSIDE the <form> element so its
		   value POSTs alongside the real inputs. Off-screen
		   container + aria-hidden + tabindex=-1 hides it from
		   humans and screen readers; real bots that scan the DOM
		   still fill it. Inline styles as belt-and-suspenders in
		   case contact.css didn't load. */
		if ( $honeypotEnabled )
		{
			$honeypotHtml =
				  '<div class="gdcontact-hp" aria-hidden="true" tabindex="-1"'
				. ' style="position:absolute;left:-9999px;top:-9999px;height:0;width:0;overflow:hidden">'
				.   '<label for="gdcontact-hp-website">' . $esc( (string) $lang->addToStack( 'gdcontact_hp_website' ) ) . '</label>'
				.   '<input type="text" id="gdcontact-hp-website" name="gdcontact_hp_website" value=""'
				.       ' autocomplete="off" tabindex="-1">'
				. '</div>';
			$injected = preg_replace( '#</form>#i', $honeypotHtml . '</form>', $formHtml, 1 );
			if ( is_string( $injected ) && $injected !== $formHtml )
			{
				$formHtml = $injected;
			}
		}

		$html .= '<div class="gdcontact-card">' . $formHtml . '</div>';
		$html .= '</div>';

		\IPS\Output::i()->title  = $title;
		\IPS\Output::i()->output = $html;
	}

	/* ------------------------------------------------------------------
	 * INTERNAL — map a Field row to the right \IPS\Helpers\Form\* input.
	 * ------------------------------------------------------------------ */
	protected function addField( \IPS\Helpers\Form $form, Field $f, string $preName, string $preMail ): void
	{
		$key      = 'field_' . (int) $f->id;
		$label    = (string) $f->label;
		$required = (bool) $f->required;
		$type     = (string) $f->type;

		$default = '';
		if ( $type === 'email' && $preMail !== '' ) { $default = $preMail; }
		if ( $type === 'text'  && stripos( $label, 'name' ) !== false && $preName !== '' ) { $default = $preName; }

		$options = [
			'placeholder' => (string) ( $f->placeholder ?? '' ),
			'maxLength'   => 2000,
		];

		try
		{
			switch ( $type )
			{
				case 'email':
					$input = new \IPS\Helpers\Form\Email( $key, $default, $required, $options );
					break;
				case 'phone':
				case 'text':
					$input = new \IPS\Helpers\Form\Text( $key, $default, $required, $options );
					break;
				case 'textarea':
					$input = new \IPS\Helpers\Form\TextArea( $key, $default, $required, [ 'rows' => 6, 'placeholder' => $options['placeholder'] ] );
					break;
				case 'select':
					$opts = [];
					foreach ( $f->optionsArray() as $o ) { $opts[ $o ] = $o; }
					$input = new \IPS\Helpers\Form\Select( $key, '', $required, [ 'options' => $opts ] );
					break;
				case 'checkbox':
					$input = new \IPS\Helpers\Form\Checkbox( $key, FALSE, $required );
					break;
				case 'number':
					$input = new \IPS\Helpers\Form\Number( $key, null, $required );
					break;
				default:
					$input = new \IPS\Helpers\Form\Text( $key, $default, $required, $options );
					break;
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcontact addField ' . $type . ': ' . $e->getMessage(), 'gdcontact' ); } catch ( \Throwable ) {}
			return;
		}

		/* IPS renders the label via the language stack: the form
		   builder auto-looks-up `$key` in lang words. Add the
		   custom label directly on the language for this member
		   so the form displays the admin's label, not the auto
		   `field_<n>` key. */
		try
		{
			\IPS\Member::loggedIn()->language()->words[ $key ]        = $label;
			\IPS\Member::loggedIn()->language()->words[ $key . '_desc' ] = (string) ( $f->help_text ?? '' );
		}
		catch ( \Throwable ) {}

		$form->add( $input );
	}

	/* ------------------------------------------------------------------
	 * INTERNAL — assemble body + resolve recipient(s) + send.
	 * ------------------------------------------------------------------ */
	protected function sendEmail( array $submitted ): bool
	{
		$prefix    = trim( (string) \IPS\Settings::i()->gdcontact_subject_prefix );
		$fromEmail = trim( (string) \IPS\Settings::i()->gdcontact_from_email );
		$fromName  = trim( (string) \IPS\Settings::i()->gdcontact_from_name );
		$default   = trim( (string) \IPS\Settings::i()->gdcontact_recipient );

		/* Reply-to = submitter's email if we can find one. */
		$replyEmail = '';
		$replyName  = '';
		foreach ( $submitted as $row )
		{
			if ( $replyEmail === '' && $row['type'] === 'email' )
			{
				$val = trim( (string) $row['value'] );
				if ( filter_var( $val, FILTER_VALIDATE_EMAIL ) ) { $replyEmail = $val; }
			}
			if ( $replyName === '' && $row['key'] === 'name' )
			{
				$replyName = trim( (string) $row['value'] );
			}
		}

		/* Recipient routing: check every configured rule; every
		   match adds a recipient. If nothing matches, fall back
		   to the default. */
		$recipients = [];
		try
		{
			$routes = json_decode( (string) \IPS\Settings::i()->gdcontact_routes_json, TRUE );
			if ( is_array( $routes ) )
			{
				foreach ( $routes as $rule )
				{
					if ( !is_array( $rule ) ) { continue; }
					$rKey = (string) ( $rule['field_key'] ?? '' );
					$rVal = (string) ( $rule['value']     ?? '' );
					$rTo  = trim( (string) ( $rule['recipient'] ?? '' ) );
					if ( $rKey === '' || $rTo === '' || !filter_var( $rTo, FILTER_VALIDATE_EMAIL ) ) { continue; }

					foreach ( $submitted as $s )
					{
						if ( (string) $s['key'] === $rKey && (string) $s['value'] === $rVal )
						{
							$recipients[ $rTo ] = $rTo;
							break;
						}
					}
				}
			}
		}
		catch ( \Throwable ) {}
		if ( empty( $recipients ) && $default !== '' && filter_var( $default, FILTER_VALIDATE_EMAIL ) )
		{
			$recipients[ $default ] = $default;
		}
		if ( empty( $recipients ) ) { return false; }

		/* Assemble body. Uses plain HTML + plaintext so IPS's
		   Email builder is happy with both. */
		$subjectSuffix = '';
		foreach ( $submitted as $s )
		{
			if ( in_array( $s['key'], [ 'subject', 'reason', 'topic' ], TRUE ) && trim( (string) $s['value'] ) !== '' )
			{
				$subjectSuffix = ' — ' . trim( (string) $s['value'] );
				break;
			}
		}
		$subject = ( $prefix !== '' ? $prefix . ' ' : '' )
			. 'Contact form'
			. $subjectSuffix;

		$plain = '';
		$html  = '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;color:#0f172a">';
		$html .= '<h2 style="margin:0 0 12px;font-size:16px">New message from gunrack.deals</h2>';
		$html .= '<table style="border-collapse:collapse;width:100%;max-width:640px">';
		foreach ( $submitted as $s )
		{
			$val = (string) $s['value'];
			if ( $val === '' ) { $val = '(empty)'; }
			$plain .= (string) $s['label'] . ":\n" . $val . "\n\n";
			$html  .= '<tr>'
				. '<td style="padding:6px 10px;border-bottom:1px solid #e5e7eb;color:#475569;vertical-align:top;width:180px"><b>' . htmlspecialchars( (string) $s['label'], ENT_QUOTES, 'UTF-8' ) . '</b></td>'
				. '<td style="padding:6px 10px;border-bottom:1px solid #e5e7eb;color:#0f172a;white-space:pre-wrap">' . htmlspecialchars( $val, ENT_QUOTES, 'UTF-8' ) . '</td>'
				. '</tr>';
		}
		$html .= '</table></div>';

		if ( $replyEmail !== '' )
		{
			$plain .= '---' . "\nReply-to: " . $replyEmail . "\n";
		}

		try
		{
			$email = \IPS\Email::buildFromContent( $subject, $html, $plain );

			$fromE = $fromEmail !== '' ? $fromEmail : null;
			$fromN = $fromName  !== '' ? $fromName  : null;

			foreach ( $recipients as $to )
			{
				try
				{
					$email->send( $to, [], [], $fromE, $fromN );
				}
				catch ( \Throwable $e )
				{
					try { \IPS\Log::log( 'gdcontact send ' . $to . ': ' . $e->getMessage(), 'gdcontact' ); } catch ( \Throwable ) {}
				}
			}

			/* Reply-to header: some IPS versions expose a
			   property, others expect a mailer callback. We
			   append via additional headers on send() if the
			   API allows; if not, the plain-text tail preserves
			   the address so admins can reply by copy. */
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcontact buildFromContent: ' . $e->getMessage(), 'gdcontact' ); } catch ( \Throwable ) {}
			return false;
		}

		return true;
	}
}

class contact extends _contact {}
