<?php
/**
 * @brief  GD Contact — field definition ActiveRecord.
 *
 * One row in gd_contact_fields per configured input on the
 * public contact form. Rule #7 verbatim on the three static
 * property declarations.
 */

namespace IPS\gdcontact\Field;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Field extends \IPS\Patterns\ActiveRecord
{
	public static ?string $databaseTable    = 'gd_contact_fields';
	public static string  $databaseColumnId = 'id';
	public static string  $databasePrefix   = '';

	/**
	 * Every supported field type → its lang-key label. Keeping
	 * this on the model means every consumer sees the same set.
	 */
	public static function typeLabels(): array
	{
		return [
			'text'     => 'gdcontact_ftype_text',
			'email'    => 'gdcontact_ftype_email',
			'phone'    => 'gdcontact_ftype_phone',
			'textarea' => 'gdcontact_ftype_textarea',
			'select'   => 'gdcontact_ftype_select',
			'checkbox' => 'gdcontact_ftype_checkbox',
			'number'   => 'gdcontact_ftype_number',
		];
	}

	/**
	 * Turn a user-visible label into a machine-safe slug. Only
	 * letters, digits, and underscores; leading digits get a
	 * `f_` prefix; empty falls back to `field_<n>`.
	 */
	public static function slugify( string $label, int $fallbackId = 0 ): string
	{
		$slug = strtolower( $label );
		$slug = preg_replace( '/[^a-z0-9]+/', '_', $slug );
		$slug = trim( (string) $slug, '_' );
		if ( $slug === '' ) { return $fallbackId > 0 ? ( 'field_' . $fallbackId ) : 'field'; }
		if ( preg_match( '/^[0-9]/', $slug ) ) { $slug = 'f_' . $slug; }
		return substr( $slug, 0, 60 );
	}

	/**
	 * Split a `options` blob (one option per line) into a clean
	 * array. Empty/blank lines are dropped.
	 */
	public function optionsArray(): array
	{
		$raw = (string) ( $this->options ?? '' );
		if ( $raw === '' ) { return []; }
		$lines = preg_split( '/\r\n|\r|\n/', $raw ) ?: [];
		$out   = [];
		foreach ( $lines as $line )
		{
			$line = trim( $line );
			if ( $line !== '' ) { $out[] = $line; }
		}
		return $out;
	}

	/**
	 * Ordered list of enabled fields — what the public form
	 * renders and what the submit endpoint iterates. Order is
	 * (position ASC, id ASC).
	 */
	public static function enabledOrdered(): array
	{
		$rows = [];
		try
		{
			foreach ( \IPS\Db::i()->select( '*', 'gd_contact_fields', [ 'enabled=?', 1 ], 'position ASC, id ASC' ) as $r )
			{
				$rows[] = static::constructFromData( $r );
			}
		}
		catch ( \Throwable ) {}
		return $rows;
	}
}

class Field extends _Field {}
