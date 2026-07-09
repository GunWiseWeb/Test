<?php
/**
 * @brief  GD Contact — public /contact-us/ page.
 *
 * v1.0.4 stops using \IPS\Helpers\Form for the visible fields
 * because IPS's form markup nests its own ipsForm / ipsFieldRow
 * / ipsSubmit chrome inside our .gdcontact-card, which produced
 * the box-in-a-box look no amount of CSS could tidy up. The
 * fields are now rendered as CUSTOM HTML directly from the
 * gd_contact_fields definitions, with proper .gdcontact-*
 * classes matching the approved mockup.
 *
 * Preserved verbatim:
 *   * CSRF — hidden csrfKey input + \IPS\Session::i()->csrfCheck()
 *     on POST.
 *   * CAPTCHA — \IPS\Helpers\Form\Captcha instantiated standalone
 *     for both render and validate. Uses whatever CAPTCHA the
 *     site has configured (Turnstile / reCAPTCHA / IPS default).
 *   * Honeypot — off-screen div injected into the form, silent-
 *     reject on hit.
 *   * sendEmail() — same routing + email path as v1.0.0.
 *
 * gd_ffl / gd_zip_geo etc. are not touched; this app writes
 * zero database rows on the front page.
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
	 * GET  /contact-us/  — render form.
	 * POST /contact-us/  — validate + send.
	 * ------------------------------------------------------------------ */
	protected function manage(): void
	{
		$lang = \IPS\Member::loggedIn()->language();
		$L    = fn( string $k ): string => (string) $lang->addToStack( $k );
		$esc  = fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );

		/* Enqueue our stylesheet — MUST use \IPS\Theme::i()->css.
		   The css helper on \IPS\Output does not exist (v1.0.11
		   bug on gdffl — don't reintroduce). */
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

		$fields          = Field::enabledOrdered();
		$isPost          = ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'POST';
		$honeypotOn      = (bool) \IPS\Settings::i()->gdcontact_honeypot_enabled;
		$captchaOn       = (bool) \IPS\Settings::i()->gdcontact_captcha_enabled;
		$flashKey        = 'gdcontact_flash';
		$successFlag     = isset( $_SESSION[ $flashKey ] ) && $_SESSION[ $flashKey ] === 'ok';
		if ( $successFlag ) { unset( $_SESSION[ $flashKey ] ); }

		/* Silent-reject honeypot before ANY validation runs — bots
		   never learn they've been caught. */
		if ( $isPost && $honeypotOn )
		{
			$hp = '';
			try { $hp = trim( (string) ( \IPS\Request::i()->gdcontact_hp_website ?? '' ) ); }
			catch ( \Throwable ) {}
			if ( $hp !== '' )
			{
				$_SESSION[ $flashKey ] = 'ok';
				\IPS\Output::i()->redirect(
					\IPS\Http\Url::internal( 'app=gdcontact&module=contact&controller=contact', 'front' )
				);
				return;
			}
		}

		/* Standalone captcha — instantiated once so both render()
		   and validate() see the same instance. IPS 5's Form\Captcha
		   works stand-alone (no parent Form required) via html() +
		   getValue() + validate(). */
		$captcha = null;
		if ( $captchaOn )
		{
			try { $captcha = new \IPS\Helpers\Form\Captcha; } catch ( \Throwable ) { $captcha = null; }
		}

		/* Pull every submitted value up-front. Whether we ultimately
		   succeed or re-render with errors, these drive the form
		   re-fill so users don't retype on validation failure. */
		$submittedValues = [];
		if ( $isPost )
		{
			foreach ( $fields as $f )
			{
				$key = (string) $f->field_key;
				try
				{
					$raw = \IPS\Request::i()->$key ?? '';
				}
				catch ( \Throwable ) { $raw = ''; }
				if ( is_array( $raw ) ) { $raw = implode( ', ', $raw ); }
				$submittedValues[ $key ] = (string) $raw;
			}
		}
		else
		{
			/* Prefill name/email for logged-in members. */
			$me = \IPS\Member::loggedIn();
			if ( $me->member_id )
			{
				foreach ( $fields as $f )
				{
					if ( (string) $f->field_key === 'name'  ) { $submittedValues[ 'name'  ] = (string) $me->name;  }
					if ( (string) $f->field_key === 'email' ) { $submittedValues[ 'email' ] = (string) $me->email; }
				}
			}
		}

		/* ------------------------ VALIDATION ------------------------ */
		$errors = [];
		if ( $isPost )
		{
			try { \IPS\Session::i()->csrfCheck(); }
			catch ( \Throwable )
			{
				$errors[] = 'Session expired — please refresh and try again.';
			}

			if ( !$errors && $captcha )
			{
				try
				{
					$captcha->getValue();
					$captcha->validate();
				}
				catch ( \Throwable )
				{
					$errors[] = $L( 'gdcontact_err_captcha' );
				}
			}

			if ( !$errors )
			{
				foreach ( $fields as $f )
				{
					$key = (string) $f->field_key;
					$val = trim( (string) ( $submittedValues[ $key ] ?? '' ) );
					if ( (bool) $f->required && $val === '' )
					{
						$errors[] = $L( 'gdcontact_err_required' );
						break;
					}
					if ( (string) $f->type === 'email' && $val !== '' && !filter_var( $val, FILTER_VALIDATE_EMAIL ) )
					{
						$errors[] = $L( 'gdcontact_err_email' );
						break;
					}
					if ( (string) $f->type === 'number' && $val !== '' && !is_numeric( $val ) )
					{
						$errors[] = $L( 'gdcontact_err_number' );
						break;
					}
				}
			}

			if ( !$errors )
			{
				/* Assemble the email payload — same shape as v1.0.0
				   so sendEmail()'s routing + Reply-To logic keeps
				   working unchanged. */
				$submitted = [];
				foreach ( $fields as $f )
				{
					$key = (string) $f->field_key;
					$submitted[] = [
						'label' => (string) $f->label,
						'key'   => $key,
						'type'  => (string) $f->type,
						'value' => (string) ( $submittedValues[ $key ] ?? '' ),
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
				$errors[] = $L( 'gdcontact_err_send' );
			}
		}

		/* ------------------------ RENDER ------------------------ */
		$title  = (string) \IPS\Settings::i()->gdcontact_page_title ?: $L( '__app_gdcontact' );
		$intro  = (string) \IPS\Settings::i()->gdcontact_intro;
		$formUrl = (string) \IPS\Http\Url::internal(
			'app=gdcontact&module=contact&controller=contact',
			'front'
		);

		$iconMail =
			'<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
			. '<rect width="20" height="16" x="2" y="4" rx="2"/>'
			. '<path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>'
			. '</svg>';
		$iconSend =
			'<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
			. '<line x1="22" y1="2" x2="11" y2="13"/>'
			. '<polygon points="22 2 15 22 11 13 2 9 22 2"/>'
			. '</svg>';

		$html  = '<div class="gr5 gdcontact-wrap">';

		if ( $successFlag )
		{
			$html .= '<div class="gdcontact-success" role="status">'
				. $esc( (string) \IPS\Settings::i()->gdcontact_success_message )
				. '</div>';
		}

		if ( !empty( $errors ) )
		{
			$html .= '<div class="gdcontact-error" role="alert">';
			foreach ( $errors as $err )
			{
				$html .= '<div>' . $esc( (string) $err ) . '</div>';
			}
			$html .= '</div>';
		}

		$html .= '<div class="gdcontact-card">';

		/* Header — navy band, teal mail icon, title + sub-line. */
		$html .= '<div class="gdcontact-header">';
		$html .= '<span class="gdcontact-header__icon">' . $iconMail . '</span>';
		$html .= '<div class="gdcontact-header__text">';
		$html .= '<h1 class="gdcontact-header__title">' . $esc( $title ) . '</h1>';
		if ( $intro !== '' )
		{
			$html .= '<p class="gdcontact-header__sub">' . $esc( $intro ) . '</p>';
		}
		$html .= '</div>';
		$html .= '</div>';

		/* Body — the custom form. */
		$html .= '<div class="gdcontact-body">';
		$html .= '<form class="gdcontact-form" method="post" action="' . $esc( $formUrl ) . '" novalidate>';
		$html .= '<input type="hidden" name="csrfKey" value="' . $esc( (string) \IPS\Session::i()->csrfKey ) . '">';

		foreach ( $fields as $f )
		{
			$key   = (string) $f->field_key;
			$value = (string) ( $submittedValues[ $key ] ?? '' );
			$html .= $this->renderFieldHtml( $f, $value, $esc );
		}

		/* Honeypot — the anti-bot trap. Off-screen inline styles as
		   belt-and-suspenders in case .gdcontact-hp CSS didn't load. */
		if ( $honeypotOn )
		{
			$html .= '<div class="gdcontact-hp" aria-hidden="true" tabindex="-1"'
				. ' style="position:absolute;left:-9999px;top:-9999px;height:0;width:0;overflow:hidden">'
				. '<label for="gdcontact-hp-website">' . $esc( (string) $lang->addToStack( 'gdcontact_hp_website' ) ) . '</label>'
				. '<input type="text" id="gdcontact-hp-website" name="gdcontact_hp_website" value="" autocomplete="off" tabindex="-1">'
				. '</div>';
		}

		if ( $captcha )
		{
			try
			{
				$html .= '<div class="gdcontact-captcha">' . (string) $captcha->html() . '</div>';
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'gdcontact captcha render: ' . $e->getMessage(), 'gdcontact' ); } catch ( \Throwable ) {}
			}
		}

		$html .= '<button type="submit" class="gdcontact-submit">'
			. '<span aria-hidden="true">' . $iconSend . '</span>'
			. '<span>' . $esc( $L( 'gdcontact_submit' ) ) . '</span>'
			. '</button>';

		$html .= '</form>';
		$html .= '</div>';   /* /.gdcontact-body */
		$html .= '</div>';   /* /.gdcontact-card */
		$html .= '</div>';   /* /.gdcontact-wrap */

		\IPS\Output::i()->title  = $title;
		\IPS\Output::i()->output = $html;
	}

	/* ------------------------------------------------------------------
	 * INTERNAL — render one field row as custom HTML.
	 * ------------------------------------------------------------------ */
	protected function renderFieldHtml( Field $f, string $value, \Closure $esc ): string
	{
		$key    = (string) $f->field_key;
		$label  = (string) $f->label;
		$type   = (string) $f->type;
		$req    = (bool) $f->required;
		$ph     = (string) ( $f->placeholder ?? '' );
		$help   = (string) ( $f->help_text ?? '' );
		$reqAtt = $req ? ' required' : '';
		$phAtt  = $ph !== '' ? ' placeholder="' . $esc( $ph ) . '"' : '';
		$idAtt  = ' id="f_' . $esc( $key ) . '"';
		$nmAtt  = ' name="' . $esc( $key ) . '"';

		$labelHtml = '<label class="gdcontact-label" for="f_' . $esc( $key ) . '">'
			. $esc( $label )
			. ( $req ? ' <span class="gdcontact-req" aria-hidden="true">*</span>' : '' )
			. '</label>';
		$helpHtml  = $help !== '' ? '<small class="gdcontact-help">' . $esc( $help ) . '</small>' : '';

		switch ( $type )
		{
			case 'email':
				$input = '<input class="gdcontact-input" type="email"' . $idAtt . $nmAtt . $phAtt . $reqAtt . ' autocomplete="email" value="' . $esc( $value ) . '">';
				break;

			case 'phone':
				$input = '<input class="gdcontact-input" type="tel"' . $idAtt . $nmAtt . $phAtt . $reqAtt . ' autocomplete="tel" value="' . $esc( $value ) . '">';
				break;

			case 'number':
				$input = '<input class="gdcontact-input" type="number"' . $idAtt . $nmAtt . $phAtt . $reqAtt . ' value="' . $esc( $value ) . '">';
				break;

			case 'textarea':
				$input = '<textarea class="gdcontact-input gdcontact-textarea"' . $idAtt . $nmAtt . $phAtt . $reqAtt . ' rows="6">' . $esc( $value ) . '</textarea>';
				break;

			case 'select':
				$opts    = $f->optionsArray();
				$selHtml = '<option value="">— Select —</option>';
				foreach ( $opts as $o )
				{
					$sel     = ( $value === $o ) ? ' selected' : '';
					$selHtml .= '<option value="' . $esc( $o ) . '"' . $sel . '>' . $esc( $o ) . '</option>';
				}
				$input = '<select class="gdcontact-input"' . $idAtt . $nmAtt . $reqAtt . '>' . $selHtml . '</select>';
				break;

			case 'checkbox':
				/* Checkbox self-labels — no separate label block above. */
				$chk = ( $value === '1' || $value === 'on' || $value === 'true' ) ? ' checked' : '';
				return '<div class="gdcontact-field gdcontact-field--checkbox">'
					. '<label class="gdcontact-check">'
					. '<input type="checkbox"' . $idAtt . $nmAtt . ' value="1"' . $chk . $reqAtt . '>'
					. '<span>' . $esc( $label ) . ( $req ? ' <span class="gdcontact-req">*</span>' : '' ) . '</span>'
					. '</label>'
					. $helpHtml
					. '</div>';

			case 'text':
			default:
				$input = '<input class="gdcontact-input" type="text"' . $idAtt . $nmAtt . $phAtt . $reqAtt . ' value="' . $esc( $value ) . '">';
				break;
		}

		return '<div class="gdcontact-field">'
			. $labelHtml
			. $input
			. $helpHtml
			. '</div>';
	}

	/* ------------------------------------------------------------------
	 * INTERNAL — assemble body + resolve recipient(s) + send.
	 * Unchanged from v1.0.0 — receives the same {label,key,type,value}
	 * shape.
	 * ------------------------------------------------------------------ */
	protected function sendEmail( array $submitted ): bool
	{
		$prefix    = trim( (string) \IPS\Settings::i()->gdcontact_subject_prefix );
		$fromEmail = trim( (string) \IPS\Settings::i()->gdcontact_from_email );
		$fromName  = trim( (string) \IPS\Settings::i()->gdcontact_from_name );
		$default   = trim( (string) \IPS\Settings::i()->gdcontact_recipient );

		/* Reply-to = submitter's email if we can find one. */
		$replyEmail = '';
		foreach ( $submitted as $row )
		{
			if ( $row['type'] === 'email' )
			{
				$val = trim( (string) $row['value'] );
				if ( filter_var( $val, FILTER_VALIDATE_EMAIL ) ) { $replyEmail = $val; break; }
			}
		}

		/* Recipient routing — same shape as v1.0.0. */
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

		$subjectSuffix = '';
		foreach ( $submitted as $s )
		{
			if ( in_array( $s['key'], [ 'subject', 'reason', 'topic' ], TRUE ) && trim( (string) $s['value'] ) !== '' )
			{
				$subjectSuffix = ' — ' . trim( (string) $s['value'] );
				break;
			}
		}
		$subject = ( $prefix !== '' ? $prefix . ' ' : '' ) . 'Contact form' . $subjectSuffix;

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
