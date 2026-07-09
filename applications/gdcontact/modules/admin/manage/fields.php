<?php
/**
 * @brief  GD Contact — ACP: fields builder.
 *
 * List + add + edit + delete + enable/disable + reorder.
 * ACP form goes through \IPS\Helpers\Form (framework-managed
 * URL + CSRF + form key — avoids the 301-on-POST trap). Every
 * URL in this controller uses the 'admin' base as the 2nd arg
 * to Url::internal (CLAUDE.md rule confirmed via
 * gddealer/stockactions.php).
 */

namespace IPS\gdcontact\modules\admin\manage;

use IPS\gdcontact\Field\Field;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _fields extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'contact_manage' );
		parent::execute();
	}

	/* ------------------------------------------------------------------
	 * List page — the default landing.
	 * ------------------------------------------------------------------ */
	protected function manage(): void
	{
		$lang = \IPS\Member::loggedIn()->language();
		$esc  = fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
		$L    = fn( string $k ): string => $esc( (string) $lang->addToStack( $k ) );

		$typeLabels = Field::typeLabels();
		$addUrl     = (string) \IPS\Http\Url::internal( 'app=gdcontact&module=manage&controller=fields&do=add', 'admin' );

		$html  = '<div class="ipsBox" style="margin-bottom:16px"><div class="ipsBox_body ipsPad">';
		$html .= '<h2 class="ipsType_sectionHead" style="margin:0 0 8px">' . $L( 'gdcontact_fields_title' ) . '</h2>';
		$html .= '<p style="margin:0 0 12px;color:#475569">' . $L( 'gdcontact_fields_intro' ) . '</p>';
		$html .= '<a class="ipsButton ipsButton--primary" href="' . $esc( $addUrl ) . '">' . $L( 'gdcontact_fields_add' ) . '</a>';
		$html .= '</div></div>';

		$html .= '<table class="ipsTable ipsTable--striped" style="width:100%">';
		$html .= '<thead><tr>'
			. '<th style="width:60px">' . $L( 'gdcontact_field_position' ) . '</th>'
			. '<th>' . $L( 'gdcontact_field_label' ) . '</th>'
			. '<th>' . $L( 'gdcontact_field_key' ) . '</th>'
			. '<th>' . $L( 'gdcontact_field_type' ) . '</th>'
			. '<th style="width:80px">' . $L( 'gdcontact_field_required' ) . '</th>'
			. '<th style="width:80px">' . $L( 'gdcontact_field_enabled' ) . '</th>'
			. '<th style="width:180px"></th>'
			. '</tr></thead><tbody>';

		try
		{
			foreach ( \IPS\Db::i()->select( '*', 'gd_contact_fields', null, 'position ASC, id ASC' ) as $row )
			{
				$id       = (int) $row['id'];
				$type     = (string) ( $row['type'] ?? 'text' );
				$typeLbl  = $lang->addToStack( $typeLabels[ $type ] ?? 'gdcontact_ftype_text' );

				$editUrl   = (string) \IPS\Http\Url::internal( 'app=gdcontact&module=manage&controller=fields&do=edit&id=' . $id,   'admin' );
				$toggleUrl = (string) \IPS\Http\Url::internal( 'app=gdcontact&module=manage&controller=fields&do=toggle&id=' . $id, 'admin' )->csrf();
				$delUrl    = (string) \IPS\Http\Url::internal( 'app=gdcontact&module=manage&controller=fields&do=delete&id=' . $id, 'admin' )->csrf();

				$html .= '<tr>';
				$html .= '<td>' . (int) $row['position'] . '</td>';
				$html .= '<td>' . $esc( (string) $row['label'] ) . '</td>';
				$html .= '<td><code>' . $esc( (string) $row['field_key'] ) . '</code></td>';
				$html .= '<td>' . $esc( (string) $typeLbl ) . '</td>';
				$html .= '<td>' . ( (int) $row['required'] ? '✓' : '' ) . '</td>';
				$html .= '<td>' . ( (int) $row['enabled'] ? '✓' : '<span style="color:#94a3b8">—</span>' ) . '</td>';
				$html .= '<td style="text-align:right;white-space:nowrap">';
				$html .= '<a class="ipsButton ipsButton--small" href="' . $esc( $editUrl ) . '">Edit</a> ';
				$html .= '<a class="ipsButton ipsButton--small ipsButton--soft" href="' . $esc( $toggleUrl ) . '">' . ( (int) $row['enabled'] ? 'Disable' : 'Enable' ) . '</a> ';
				$html .= '<a class="ipsButton ipsButton--small ipsButton--negative" href="' . $esc( $delUrl ) . '" data-confirm="' . $L( 'gdcontact_field_delete_confirm' ) . '">Delete</a>';
				$html .= '</td>';
				$html .= '</tr>';
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcontact fields list: ' . $e->getMessage(), 'gdcontact' ); } catch ( \Throwable ) {}
		}

		$html .= '</tbody></table>';

		\IPS\Output::i()->title  = $lang->addToStack( 'gdcontact_fields_title' );
		\IPS\Output::i()->output = $html;
	}

	/* ------------------------------------------------------------------
	 * Add / Edit form. do=add or do=edit&id=N.
	 * ------------------------------------------------------------------ */
	protected function add(): void  { $this->form( null ); }
	protected function edit(): void { $this->form( (int) \IPS\Request::i()->id ); }

	protected function form( ?int $id ): void
	{
		$lang = \IPS\Member::loggedIn()->language();

		$existing = null;
		if ( $id )
		{
			try { $existing = Field::load( $id ); } catch ( \Throwable ) { $existing = null; }
		}

		$typeOpts = [];
		foreach ( Field::typeLabels() as $k => $langKey )
		{
			$typeOpts[ $k ] = (string) $lang->addToStack( $langKey );
		}

		$form = new \IPS\Helpers\Form;

		$form->add( new \IPS\Helpers\Form\Text( 'gdcontact_field_label',
			$existing ? (string) $existing->label : '', TRUE, [ 'maxLength' => 200 ] ) );

		$form->add( new \IPS\Helpers\Form\Text( 'gdcontact_field_key',
			$existing ? (string) $existing->field_key : '', FALSE, [ 'maxLength' => 60 ] ) );

		$form->add( new \IPS\Helpers\Form\Select( 'gdcontact_field_type',
			$existing ? (string) $existing->type : 'text', TRUE, [ 'options' => $typeOpts ] ) );

		$form->add( new \IPS\Helpers\Form\YesNo( 'gdcontact_field_required',
			$existing ? (bool) $existing->required : FALSE, FALSE ) );

		$form->add( new \IPS\Helpers\Form\Number( 'gdcontact_field_position',
			$existing ? (int) $existing->position : $this->nextPosition(), FALSE ) );

		$form->add( new \IPS\Helpers\Form\TextArea( 'gdcontact_field_options',
			$existing ? (string) ( $existing->options ?? '' ) : '', FALSE, [ 'rows' => 5 ] ) );

		$form->add( new \IPS\Helpers\Form\Text( 'gdcontact_field_placeholder',
			$existing ? (string) ( $existing->placeholder ?? '' ) : '', FALSE, [ 'maxLength' => 200 ] ) );

		$form->add( new \IPS\Helpers\Form\Text( 'gdcontact_field_help_text',
			$existing ? (string) ( $existing->help_text ?? '' ) : '', FALSE, [ 'maxLength' => 500 ] ) );

		$form->add( new \IPS\Helpers\Form\YesNo( 'gdcontact_field_enabled',
			$existing ? (bool) $existing->enabled : TRUE, FALSE ) );

		if ( $values = $form->values() )
		{
			$label = trim( (string) $values['gdcontact_field_label'] );
			$key   = trim( (string) $values['gdcontact_field_key'] );
			if ( $key === '' ) { $key = Field::slugify( $label, $existing ? (int) $existing->id : 0 ); }

			/* Ensure the field_key stays unique. */
			$conflict = 0;
			try
			{
				$w = $existing
					? [ 'field_key=? AND id<>?', $key, (int) $existing->id ]
					: [ 'field_key=?', $key ];
				$conflict = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_contact_fields', $w )->first();
			}
			catch ( \Throwable ) {}
			if ( $conflict > 0 )
			{
				/* Append a random suffix rather than fail — small
				   admin form, don't blow up on a name clash. */
				$key = substr( $key, 0, 52 ) . '_' . substr( bin2hex( random_bytes( 3 ) ), 0, 6 );
			}

			$data = [
				'field_key'   => $key,
				'label'       => $label !== '' ? $label : $key,
				'type'        => (string) $values['gdcontact_field_type'],
				'required'    => (int) (bool) $values['gdcontact_field_required'],
				'position'    => (int) $values['gdcontact_field_position'],
				'options'     => ( trim( (string) $values['gdcontact_field_options'] ) !== '' ) ? (string) $values['gdcontact_field_options'] : null,
				'placeholder' => ( trim( (string) $values['gdcontact_field_placeholder'] ) !== '' ) ? (string) $values['gdcontact_field_placeholder'] : null,
				'help_text'   => ( trim( (string) $values['gdcontact_field_help_text'] ) !== '' ) ? (string) $values['gdcontact_field_help_text'] : null,
				'enabled'     => (int) (bool) $values['gdcontact_field_enabled'],
			];

			try
			{
				if ( $existing )
				{
					\IPS\Db::i()->update( 'gd_contact_fields', $data, [ 'id=?', (int) $existing->id ] );
				}
				else
				{
					\IPS\Db::i()->insert( 'gd_contact_fields', $data );
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'gdcontact field save: ' . $e->getMessage(), 'gdcontact' ); } catch ( \Throwable ) {}
			}

			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gdcontact&module=manage&controller=fields', 'admin' ),
				'gdcontact_field_saved'
			);
			return;
		}

		\IPS\Output::i()->title  = $lang->addToStack( $existing ? 'gdcontact_field_edit_title' : 'gdcontact_field_add_title' );
		\IPS\Output::i()->output = (string) $form;
	}

	/* ------------------------------------------------------------------
	 * Enable/disable toggle. GET-with-csrf (Url::internal(...)->csrf())
	 * is fine here — this action performs work and redirects; it does
	 * NOT render a 2xx HTML page (rule about redirect-vs-render).
	 * ------------------------------------------------------------------ */
	protected function toggle(): void
	{
		\IPS\Session::i()->csrfCheck();
		$id = (int) \IPS\Request::i()->id;
		try
		{
			$row = \IPS\Db::i()->select( 'enabled', 'gd_contact_fields', [ 'id=?', $id ] )->first();
			\IPS\Db::i()->update( 'gd_contact_fields', [ 'enabled' => (int) $row ? 0 : 1 ], [ 'id=?', $id ] );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcontact toggle: ' . $e->getMessage(), 'gdcontact' ); } catch ( \Throwable ) {}
		}
		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdcontact&module=manage&controller=fields', 'admin' )
		);
	}

	protected function delete(): void
	{
		\IPS\Session::i()->csrfCheck();
		$id = (int) \IPS\Request::i()->id;
		try { \IPS\Db::i()->delete( 'gd_contact_fields', [ 'id=?', $id ] ); } catch ( \Throwable ) {}
		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdcontact&module=manage&controller=fields', 'admin' ),
			'gdcontact_field_deleted'
		);
	}

	protected function nextPosition(): int
	{
		try
		{
			$max = (int) \IPS\Db::i()->select( 'MAX(position)', 'gd_contact_fields' )->first();
			return $max + 10;
		}
		catch ( \Throwable )
		{
			return 100;
		}
	}
}

class fields extends _fields {}
